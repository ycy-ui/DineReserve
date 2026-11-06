<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\Mailer;
use DineReserve\NotificationService;
use RuntimeException;

/**
 * URS REQ-1.4 — confirmation, 24 h / 2 h reminders, retries, notification log (W8).
 */
final class NotificationServiceTest extends TestCase
{
    private BookingService $bookings;

    private RecordingMailer $mailer;

    private NotificationService $notifications;

    private string $email = 'guest@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = new RecordingMailer();
        $this->bookings = new BookingService(
            $this->pdo,
            new AvailabilityService($this->pdo, $this->clock),
            $this->clock
        );
        $this->notifications = new NotificationService(
            $this->pdo,
            $this->mailer,
            $this->clock,
            fn (string $reference): string => $this->bookings->issueManageToken($reference)
        );
        $this->bookings->setNotifications($this->notifications);
    }

    private function book(string $date = '2026-11-10', string $time = '19:00', int $party = 4): array
    {
        return $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T03'),
            'date' => $date,
            'time' => $time,
            'party_size' => $party,
            'name' => 'Notify Test',
            'email' => $this->email,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $bookingId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM notification_log WHERE booking_id = ? ORDER BY id');
        $statement->execute([$bookingId]);

        return $statement->fetchAll();
    }

    /** @return list<string> */
    private function templates(int $bookingId): array
    {
        return array_map(static fn (array $row): string => (string) $row['template'], $this->rows($bookingId));
    }

    // ------------------------------------------------------------- scheduling

    public function testCreateQueuesConfirmationAndBothReminders(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        self::assertSame(
            ['booking_confirmation', 'booking_reminder_24h', 'booking_reminder_2h'],
            $this->templates($bookingId)
        );

        $rows = $this->rows($bookingId);
        self::assertSame('2026-11-09T19:00:00+08:00', $rows[1]['scheduled_at']);
        self::assertSame('2026-11-10T17:00:00+08:00', $rows[2]['scheduled_at']);
        self::assertSame('QUEUED', $rows[0]['status']);
    }

    public function testRemindersWhoseMomentHasPassedAreSkipped(): void
    {
        // Booked for this evening: the 24 h reminder is already in the past.
        $result = $this->book('2026-11-02', '19:00');
        $bookingId = (int) $result['booking']['id'];

        self::assertSame(
            ['booking_confirmation', 'booking_reminder_2h'],
            $this->templates($bookingId)
        );
    }

    public function testReminderSchedulingIsIdempotent(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        self::assertSame(0, $this->notifications->scheduleRemindersForBooking($bookingId));
        self::assertCount(3, $this->rows($bookingId));
    }

    public function testDueListsOnlyNotificationsThatAreAlreadyDue(): void
    {
        $this->book();

        $due = $this->notifications->due();
        self::assertCount(1, $due);
        self::assertSame('booking_confirmation', $due[0]['template']);
    }

    // --------------------------------------------------------------- delivery

    public function testDispatchSendsTheDueConfirmationOnce(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $summary = $this->notifications->dispatch();
        self::assertSame(1, $summary['sent']);

        self::assertCount(1, $this->mailer->messages);
        self::assertSame($this->email, $this->mailer->messages[0]['to']);
        self::assertStringContainsString((string) $result['booking']['reference'], $this->mailer->messages[0]['subject']);

        $row = $this->rows($bookingId)[0];
        self::assertSame('SENT', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertNotNull($row['sent_at']);

        // A second dispatch must not send the same message again.
        $this->notifications->dispatch();
        self::assertCount(1, $this->mailer->messages);
    }

    public function testFutureRemindersAreNotSentEarly(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->notifications->dispatch();
        $rows = $this->rows($bookingId);
        self::assertSame('QUEUED', $rows[1]['status']);
        self::assertSame('QUEUED', $rows[2]['status']);
    }

    public function testReminderIsDeliveredWhenItsMomentArrives(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->notifications->dispatch();                       // confirmation
        $this->clock->advance('+7 days 9 hours');                // 2026-11-09 19:00

        $summary = $this->notifications->dispatch();
        self::assertSame(1, $summary['sent']);
        self::assertCount(2, $this->mailer->messages);
        self::assertStringContainsString('tomorrow', strtolower($this->mailer->messages[1]['body']));
        self::assertSame('SENT', $this->rows($bookingId)[1]['status']);
    }

    public function testDispatchRespectsTheLimit(): void
    {
        $this->book();
        // A second table, same slot.
        $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T07'),
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 4,
            'name' => 'Second Guest',
            'email' => 'second@example.com',
        ]);

        $summary = $this->notifications->dispatch(1);
        self::assertSame(1, $summary['sent']);
        self::assertCount(1, $this->mailer->messages);
    }

    // ---------------------------------------------------------------- retries

    public function testFailedDeliveryIsRetriedAfterOneMinute(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->mailer->fail = true;
        $summary = $this->notifications->dispatch();

        self::assertSame(1, $summary['retried']);
        self::assertSame(0, $summary['sent']);

        $row = $this->rows($bookingId)[0];
        self::assertSame('QUEUED', $row['status']);
        self::assertSame(1, (int) $row['attempts']);
        self::assertSame('2026-11-02T10:01:00+08:00', $row['scheduled_at']);
        self::assertStringContainsString('SMTP down', (string) $row['last_error']);
    }

    public function testRetryBackoffWalksOneFiveAndFifteenMinutes(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->mailer->fail = true;

        $this->notifications->dispatch();                       // attempt 1 fails → +1 min
        $this->clock->advance('+2 minutes');
        $this->notifications->dispatch();                       // attempt 2 fails → +5 min
        self::assertSame(2, (int) $this->rows($bookingId)[0]['attempts']);
        self::assertSame('2026-11-02T10:07:00+08:00', $this->rows($bookingId)[0]['scheduled_at']);

        $this->clock->advance('+6 minutes');
        $this->notifications->dispatch();                       // attempt 3 fails → +15 min
        self::assertSame(3, (int) $this->rows($bookingId)[0]['attempts']);
        self::assertSame('2026-11-02T10:23:00+08:00', $this->rows($bookingId)[0]['scheduled_at']);

        $this->clock->advance('+16 minutes');
        $summary = $this->notifications->dispatch();             // attempt 4 → FAILED
        self::assertSame(1, $summary['failed']);
        self::assertSame('FAILED', $this->rows($bookingId)[0]['status']);
        self::assertSame('SMTP down', $this->rows($bookingId)[0]['last_error']);
    }

    public function testARecoveredMailerDeliversTheRetriedRow(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->mailer->fail = true;
        $this->notifications->dispatch();
        $this->mailer->fail = false;

        $this->clock->advance('+2 minutes');
        $summary = $this->notifications->dispatch();

        self::assertSame(1, $summary['sent']);
        self::assertSame('SENT', $this->rows($bookingId)[0]['status']);
        self::assertSame(2, (int) $this->rows($bookingId)[0]['attempts']);
    }

    // ------------------------------------------------------- change / cancel

    public function testChangeCancelsOldRemindersAndQueuesNewOnes(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->bookings->change([
            'reference' => (string) $result['booking']['reference'],
            'email' => $this->email,
            'date' => '2026-11-12',
            'time' => '19:00',
        ]);

        $rows = $this->rows($bookingId);
        $byTemplate = [];
        foreach ($rows as $row) {
            $byTemplate[$row['template']][] = $row;
        }

        self::assertSame('CANCELLED', $byTemplate['booking_reminder_24h'][0]['status']);
        self::assertSame('CANCELLED', $byTemplate['booking_reminder_2h'][0]['status']);
        self::assertCount(2, $byTemplate['booking_reminder_24h']);
        self::assertSame('QUEUED', $byTemplate['booking_reminder_24h'][1]['status']);
        self::assertSame('2026-11-11T19:00:00+08:00', $byTemplate['booking_reminder_24h'][1]['scheduled_at']);
        self::assertSame('2026-11-12T17:00:00+08:00', $byTemplate['booking_reminder_2h'][1]['scheduled_at']);
        self::assertArrayHasKey('booking_changed', $byTemplate);
    }

    public function testCancelCancelsPendingRemindersAndSendsTheNotice(): void
    {
        $result = $this->book();
        $bookingId = (int) $result['booking']['id'];

        $this->bookings->cancel([
            'reference' => (string) $result['booking']['reference'],
            'email' => $this->email,
        ]);

        foreach ($this->rows($bookingId) as $row) {
            if (str_starts_with((string) $row['template'], 'booking_reminder')) {
                self::assertSame('CANCELLED', $row['status']);
            }
        }

        $summary = $this->notifications->dispatch();
        self::assertSame(2, $summary['sent']);   // the confirmation and the cancellation notice
        $subjects = array_column($this->mailer->messages, 'subject');
        self::assertTrue((bool) array_filter($subjects, static fn (string $s): bool => str_contains($s, 'cancelled')));
    }

    public function testCancelledBookingNeverReceivesItsReminders(): void
    {
        $result = $this->book();
        $this->bookings->cancel([
            'reference' => (string) $result['booking']['reference'],
            'email' => $this->email,
        ]);
        $this->notifications->dispatch();                       // confirmation + cancellation

        $this->clock->advance('+9 days');
        $summary = $this->notifications->dispatch();

        self::assertSame(0, $summary['sent']);
        $bodies = implode("\n", array_column($this->mailer->messages, 'subject'));
        self::assertStringNotContainsString('your table is tomorrow', strtolower($bodies));
    }

    // ---------------------------------------------------------------- render

    public function testConfirmationRenderCarriesReferenceAndManageLink(): void
    {
        $result = $this->book();
        $booking = $this->pdo
            ->query('SELECT * FROM bookings WHERE id = ' . (int) $result['booking']['id'])
            ->fetch();
        $restaurant = $this->pdo->query('SELECT * FROM restaurants LIMIT 1')->fetch();
        $table = $this->pdo->query('SELECT * FROM tables WHERE id = ' . (int) $booking['table_id'])->fetch();

        $message = $this->notifications->render($booking, $restaurant, $table, 'booking_confirmation');

        self::assertStringContainsString((string) $booking['reference'], $message['subject']);
        self::assertStringContainsString((string) $booking['reference'], $message['body']);
        self::assertStringContainsString('/api/v1/bookings/' . $booking['reference'] . '/manage?token=', $message['body']);
        self::assertStringContainsString('Guests    : 4', $message['body']);
    }

    public function testReminderRenderUsesTheRightWording(): void
    {
        $result = $this->book();
        $booking = $this->pdo
            ->query('SELECT * FROM bookings WHERE id = ' . (int) $result['booking']['id'])
            ->fetch();
        $restaurant = $this->pdo->query('SELECT * FROM restaurants LIMIT 1')->fetch();
        $table = $this->pdo->query('SELECT * FROM tables WHERE id = ' . (int) $booking['table_id'])->fetch();

        $tomorrow = $this->notifications->render($booking, $restaurant, $table, 'booking_reminder_24h');
        $soon = $this->notifications->render($booking, $restaurant, $table, 'booking_reminder_2h');

        self::assertStringContainsString('tomorrow', strtolower($tomorrow['subject'] . $tomorrow['body']));
        self::assertStringContainsString('two hours', strtolower($soon['subject'] . $soon['body']));
    }
}

/**
 * In-memory Mailer double for the notification tests.
 */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $messages = [];

    public bool $fail = false;

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fail) {
            throw new RuntimeException('SMTP down');
        }

        $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
