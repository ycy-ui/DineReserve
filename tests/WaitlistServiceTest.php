<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\DomainException;
use DineReserve\Mailer;
use DineReserve\WaitlistService;
use RuntimeException;

/**
 * URS REQ-1.5 — waitlist, 5-minute offers, automatic release (W9).
 */
final class WaitlistServiceTest extends TestCase
{
    private BookingService $bookings;

    private WaitlistService $waitlist;

    private WaitlistMailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $availability = new AvailabilityService($this->pdo, $this->clock);
        $this->bookings = new BookingService($this->pdo, $availability, $this->clock);
        $this->mailer = new WaitlistMailer();
        $this->waitlist = new WaitlistService($this->pdo, $availability, $this->clock, $this->mailer, $this->bookings);
        $this->bookings->setWaitlist($this->waitlist);
    }

    /** Join the queue and return the entry payload. */
    private function join(string $date, string $time, int $party, string $tableCode = 'T03', string $email = 'waiter@example.com'): array
    {
        $result = $this->waitlist->join([
            'restaurant_id' => $this->restaurantId,
            'date' => $date,
            'time' => $time,
            'party_size' => $party,
            'name' => 'Wait ' . $party,
            'email' => $email,
            'table_hint' => $tableCode,
        ]);

        return $result['entry'];
    }

    private function book(string $tableCode, string $date, string $time, int $party = 2, string $email = 'guest@example.com'): array
    {
        return $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode($tableCode),
            'date' => $date,
            'time' => $time,
            'party_size' => $party,
            'name' => 'Guest',
            'email' => $email,
        ]);
    }

    private function assertDomainCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode(), 'unexpected: ' . $exception->getMessage());

            return;
        }

        self::fail('expected DomainException with code ' . $code);
    }

    // ------------------------------------------------------------------ join

    public function testJoinCreatesAWaitingEntryWithAPosition(): void
    {
        $entry = $this->join('2026-11-10', '19:00', 4);

        self::assertSame('WAITING', $entry['status']);
        self::assertSame(1, $entry['position']);
        self::assertSame('2026-11-10', $entry['date']);
        self::assertSame(4, $entry['party_size']);
    }

    public function testQueuePositionsFollowArrivalOrder(): void
    {
        $first = $this->join('2026-11-10', '19:00', 2, 'T03');
        $second = $this->join('2026-11-10', '19:00', 2, 'T03', 'second@example.com');

        self::assertSame(1, $first['position']);
        self::assertSame(2, $second['position']);
        self::assertSame(2, $this->waitlist->position((int) $second['id']));
    }

    public function testDuplicateJoinReturnsTheExistingEntry(): void
    {
        $first = $this->join('2026-11-10', '19:00', 4);
        $result = $this->waitlist->join([
            'restaurant_id' => $this->restaurantId,
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 4,
            'name' => 'Wait 4',
            'email' => 'waiter@example.com',
        ]);

        self::assertFalse($result['created']);
        self::assertSame($first['id'], $result['entry']['id']);
    }

    public function testJoinValidatesEveryField(): void
    {
        try {
            $this->waitlist->join([
                'restaurant_id' => $this->restaurantId,
                'date' => '2026-13-40',
                'time' => '25:99',
                'party_size' => 0,
                'name' => '',
                'email' => 'not-an-email',
            ]);
            self::fail('expected VALIDATION_ERROR');
        } catch (DomainException $exception) {
            self::assertSame('VALIDATION_ERROR', $exception->errorCode());
            self::assertSame(
                ['date', 'time', 'party_size', 'name', 'email'],
                array_keys($exception->details()['errors'])
            );
        }
    }

    public function testJoinRejectsAPastDate(): void
    {
        $this->assertDomainCode('DATE_OUT_OF_RANGE', fn () => $this->waitlist->join([
            'restaurant_id' => $this->restaurantId,
            'date' => '2026-11-01',
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Past',
            'email' => 'past@example.com',
        ]));
    }

    public function testJoinRejectsATimeOutsideTheServicePeriods(): void
    {
        $this->assertDomainCode('NO_SERVICE_PERIOD', fn () => $this->waitlist->join([
            'restaurant_id' => $this->restaurantId,
            'date' => '2026-11-10',
            'time' => '09:00',
            'party_size' => 2,
            'name' => 'Early',
            'email' => 'early@example.com',
        ]));
    }

    public function testJoinRejectsAnUnrealisticPartySize(): void
    {
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->waitlist->join([
            'restaurant_id' => $this->restaurantId,
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 25,
            'name' => 'Big party',
            'email' => 'big@example.com',
        ]));
    }

    // ---------------------------------------------------------------- offers

    public function testCancellingABookingOffersTheSlotToTheFirstWaiter(): void
    {
        $this->join('2026-11-10', '19:00', 4);
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);

        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $entry = $this->waitlist->getEntry(1);
        self::assertSame('OFFERED', $entry['status']);
        self::assertSame('19:00', $entry['offer_time']);
        self::assertSame('2026-11-02T10:05:00+08:00', $entry['offer_expires_at']);
        self::assertSame($this->tableIdByCode('T03'), $entry['table']['id']);
        self::assertCount(1, $this->mailer->messages);
    }

    public function testTheWaiterWhoFitsTheReleasedTableGetsTheOffer(): void
    {
        // Six guests join first, then a party of two.
        $this->join('2026-11-10', '19:00', 6, 'T03', 'six@example.com');
        $this->join('2026-11-10', '19:00', 2, 'T03', 'two@example.com');

        // A four-seat table is released: the party of six does not fit.
        $booking = $this->book('T02', '2026-11-10', '19:00', 2);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $entries = $this->pdo->query('SELECT * FROM waitlist_entries ORDER BY id')->fetchAll();
        self::assertSame('WAITING', $entries[0]['status']);
        self::assertSame('OFFERED', $entries[1]['status']);
    }

    public function testTheOfferNoticeCarriesTheDeadlineAndAcceptLink(): void
    {
        $this->join('2026-11-10', '19:00', 4);
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $message = $this->mailer->messages[0];
        self::assertStringContainsString('5 minutes', $message['body']);
        self::assertStringContainsString('/api/v1/waitlist/1/accept', $message['body']);
        self::assertStringContainsString('19:00', $message['body']);
    }

    // ------------------------------------------------------------- accepting

    public function testAcceptingAnOfferCreatesAWaitlistBooking(): void
    {
        $this->join('2026-11-10', '19:00', 4);
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $result = $this->waitlist->accept(1, ['email' => 'waiter@example.com']);

        self::assertSame('CONVERTED', $result['entry']['status']);
        self::assertSame('waitlist', $result['booking']['channel']);
        self::assertSame('2026-11-10', $result['booking']['date']);
        self::assertSame('19:00', $result['booking']['start']);
        self::assertFalse(
            $this->pdo->query('SELECT COUNT(*) FROM waitlist_entries WHERE status = \'WAITING\'')->fetchColumn() > 0
        );
    }

    public function testAcceptingAfterTheFiveMinutesIsRejected(): void
    {
        $this->join('2026-11-10', '19:00', 4);
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $this->clock->advance('+6 minutes');
        $this->assertDomainCode('OFFER_EXPIRED', fn () => $this->waitlist->accept(1, ['email' => 'waiter@example.com']));

        self::assertSame('EXPIRED', $this->waitlist->getEntry(1)['status']);
    }

    public function testAcceptingWithTheWrongEmailLooksLikeANotFoundEntry(): void
    {
        $this->join('2026-11-10', '19:00', 4);
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $this->assertDomainCode('NOT_FOUND', fn () => $this->waitlist->accept(1, ['email' => 'someone@example.com']));
    }

    public function testAcceptingWithoutAnOfferIsRejected(): void
    {
        $this->join('2026-11-10', '19:00', 4);

        $this->assertDomainCode('WAITLIST_NOT_OFFERED', fn () => $this->waitlist->accept(1, ['email' => 'waiter@example.com']));
    }

    public function testAcceptingWhenTheTableWasTakenMeanwhileKeepsTheOffer(): void
    {
        $this->join('2026-11-10', '19:00', 4);
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        // Someone else books the freed table before the waiter confirms.
        $this->book('T03', '2026-11-10', '19:00', 4, 'rival@example.com');

        $this->assertDomainCode('TABLE_TAKEN', fn () => $this->waitlist->accept(1, ['email' => 'waiter@example.com']));
        self::assertSame('OFFERED', $this->waitlist->getEntry(1)['status']);
    }

    // ------------------------------------------------------- decline / expiry

    public function testDecliningPassesTheSlotToTheNextWaiter(): void
    {
        $this->join('2026-11-10', '19:00', 4, 'T03', 'first@example.com');
        $this->join('2026-11-10', '19:00', 4, 'T03', 'second@example.com');
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $result = $this->waitlist->decline(1, ['email' => 'first@example.com']);

        self::assertSame('DECLINED', $result['entry']['status']);
        self::assertNotNull($result['offered']);
        self::assertSame(2, $result['offered']['id']);
        self::assertSame('OFFERED', $this->waitlist->getEntry(2)['status']);
    }

    public function testExpiredOffersArePassedToTheNextWaiter(): void
    {
        $this->join('2026-11-10', '19:00', 4, 'T03', 'first@example.com');
        $this->join('2026-11-10', '19:00', 4, 'T03', 'second@example.com');
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        $this->clock->advance('+6 minutes');
        $summary = $this->waitlist->expireOffers();

        self::assertSame(1, $summary['expired']);
        self::assertSame(1, $summary['offered']);
        self::assertSame('EXPIRED', $this->waitlist->getEntry(1)['status']);
        self::assertSame('OFFERED', $this->waitlist->getEntry(2)['status']);
        self::assertCount(2, $this->mailer->messages);
    }

    public function testPositionCountsOnlyWaitingEntries(): void
    {
        $first = $this->join('2026-11-10', '19:00', 4, 'T03', 'first@example.com');
        $second = $this->join('2026-11-10', '19:00', 4, 'T03', 'second@example.com');
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'guest@example.com',
        ]);

        // The first waiter is now OFFERED, so the second becomes position 1.
        self::assertSame(1, $this->waitlist->position((int) $second['id']));
        self::assertSame('OFFERED', $this->waitlist->getEntry((int) $first['id'])['status']);
    }
}

/**
 * In-memory Mailer double for the waitlist tests.
 */
final class WaitlistMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,body:string}> */
    public array $messages = [];

    public function send(string $to, string $subject, string $body): void
    {
        if ($to === '' || $subject === '') {
            throw new RuntimeException('incomplete message');
        }

        $this->messages[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
}
