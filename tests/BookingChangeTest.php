<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\DomainException;
use DineReserve\Slot;

/**
 * URS REQ-1.3 — change or cancel a booking (W7).
 */
final class BookingChangeTest extends TestCase
{
    private BookingService $bookings;

    private AvailabilityService $availability;

    private string $email = 'guest@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new AvailabilityService($this->pdo, $this->clock);
        $this->bookings = new BookingService($this->pdo, $this->availability, $this->clock);
    }

    /** Create a confirmed booking and return its reference. */
    private function book(string $tableCode, string $date, string $time, int $partySize = 2): string
    {
        $result = $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode($tableCode),
            'date' => $date,
            'time' => $time,
            'party_size' => $partySize,
            'name' => 'Change Test',
            'email' => $this->email,
        ]);

        return (string) $result['booking']['reference'];
    }

    /** @return array<string,mixed> */
    private function bookingRow(string $reference): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bookings WHERE reference = ?');
        $statement->execute([$reference]);
        $row = $statement->fetch();

        self::assertIsArray($row);

        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function events(string $reference): array
    {
        $statement = $this->pdo->prepare(
            'SELECT e.* FROM booking_events e
               JOIN bookings b ON b.id = e.booking_id
              WHERE b.reference = ?
              ORDER BY e.id'
        );
        $statement->execute([$reference]);

        return $statement->fetchAll();
    }

    private function assertDomainCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode(), 'unexpected error code: ' . $exception->getMessage());

            return;
        }

        self::fail('expected DomainException with code ' . $code);
    }

    // ---------------------------------------------------------------- change

    public function testChangeMovesTheBookingToANewDateAndTime(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $result = $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'date' => '2026-11-11',
            'time' => '18:00',
        ]);

        self::assertTrue($result['changed']);
        self::assertSame(['date', 'time'], $result['changes']);

        $booking = $result['booking'];
        self::assertSame('2026-11-11', $booking['date']);
        self::assertSame('18:00', $booking['start']);
        self::assertSame('20:15', $booking['end']);           // 18:00 + 135 min (dinner 120 + 15)
        self::assertSame('2026-11-11T16:00:00+08:00', $booking['cutoff_at']);
        self::assertSame('CONFIRMED', $booking['status']);

        $row = $this->bookingRow($reference);
        self::assertSame('2026-11-11', $row['booking_date']);
        self::assertSame('18:00', $row['start_time']);
    }

    public function testChangeWritesAnAuditEventWithBeforeAndAfter(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'time' => '19:30',
        ]);

        $events = $this->events($reference);
        self::assertCount(2, $events);
        self::assertSame('created', $events[0]['event_type']);
        self::assertSame('changed', $events[1]['event_type']);
        self::assertSame('diner', $events[1]['actor']);

        $payload = json_decode((string) $events[1]['payload'], true);
        self::assertIsArray($payload);
        self::assertSame(['time'], $payload['changed_fields']);
        self::assertSame('19:00', $payload['before']['start']);
        self::assertSame('19:30', $payload['after']['start']);
    }

    public function testChangeToAnotherTableReleasesTheOldOne(): void
    {
        $oldTable = $this->tableIdByCode('T03');
        $newTable = $this->tableIdByCode('T07');
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $result = $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'table_id' => $newTable,
        ]);

        self::assertTrue($result['changed']);
        self::assertSame(['table_id'], $result['changes']);
        self::assertSame($newTable, $result['booking']['table']['id']);

        // The old table is bookable again, the new one is not.
        self::assertTrue($this->availability->isTableFree($oldTable, '2026-11-10', '19:00', '21:15'));
        self::assertFalse($this->availability->isTableFree($newTable, '2026-11-10', '19:00', '21:15'));
    }

    public function testChangeWithOnlyPartySizeKeepsTheSlot(): void
    {
        $reference = $this->book('T06', '2026-11-10', '19:00', 2);

        $result = $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'party_size' => 4,
        ]);

        self::assertSame(['party_size'], $result['changes']);
        self::assertSame(4, $result['booking']['party_size']);
        self::assertSame('19:00', $result['booking']['start']);
    }

    public function testUnchangedRequestIsANoOp(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $before = $this->bookingRow($reference);
        $eventsBefore = count($this->events($reference));

        $result = $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 4,
        ]);

        self::assertFalse($result['changed']);
        self::assertSame([], $result['changes']);
        self::assertSame($before['updated_at'], $this->bookingRow($reference)['updated_at']);
        self::assertCount($eventsBefore, $this->events($reference));
    }

    public function testChangeAfterCutoffIsRejectedAndLeavesTheBookingIntact(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $before = $this->bookingRow($reference);

        // Cut-off is 17:00 on 2026-11-10; move the frozen clock past it.
        $this->clock->advance('+8 days 7 hours 30 minutes');   // 2026-11-10 17:30

        try {
            $this->bookings->change([
                'reference' => $reference,
                'email' => $this->email,
                'time' => '20:00',
            ]);
            self::fail('expected CUTOFF_PASSED');
        } catch (DomainException $exception) {
            self::assertSame('CUTOFF_PASSED', $exception->errorCode());
            self::assertSame(422, $exception->status());
            self::assertArrayHasKey('cutoff_at', $exception->details());
        }

        self::assertSame($before['start_time'], $this->bookingRow($reference)['start_time']);
    }

    public function testChangeOfACancelledBookingIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->bookings->cancel(['reference' => $reference, 'email' => $this->email]);

        $this->assertDomainCode('BOOKING_CANCELLED', fn () => $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'time' => '20:00',
        ]));
    }

    public function testChangeRequiresAnIdentity(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $this->assertDomainCode('AUTHORIZATION_REQUIRED', fn () => $this->bookings->change([
            'reference' => $reference,
            'time' => '20:00',
        ]));
    }

    public function testChangeWithWrongEmailLooksLikeANotFoundBooking(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $this->assertDomainCode('NOT_FOUND', fn () => $this->bookings->change([
            'reference' => $reference,
            'email' => 'someone-else@example.com',
            'time' => '20:00',
        ]));
    }

    public function testChangeWithASignedManageLinkWorks(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $token = $this->bookings->issueManageToken($reference);

        $result = $this->bookings->change([
            'reference' => $reference,
            'manage_token' => $token,
            'time' => '19:30',
        ]);

        self::assertTrue($result['changed']);
        self::assertSame('19:30', $result['booking']['start']);
    }

    public function testTamperedManageTokenIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $token = $this->bookings->issueManageToken($reference);
        $tampered = substr($token, 0, -2) . (str_ends_with($token, 'AA') ? 'BB' : 'AA');

        $this->assertDomainCode('TOKEN_INVALID', fn () => $this->bookings->change([
            'reference' => $reference,
            'manage_token' => $tampered,
            'time' => '20:00',
        ]));
    }

    public function testExpiredManageTokenIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $token = $this->bookings->issueManageToken($reference);
        $this->clock->advance('+4 days');   // default TTL is 72 h

        $this->assertDomainCode('TOKEN_EXPIRED', fn () => $this->bookings->change([
            'reference' => $reference,
            'manage_token' => $token,
            'time' => '20:00',
        ]));
    }

    public function testManageTokenForAnotherBookingIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $other = $this->book('T07', '2026-11-10', '19:00', 4);
        $otherToken = $this->bookings->issueManageToken($other);

        $this->assertDomainCode('NOT_FOUND', fn () => $this->bookings->change([
            'reference' => $reference,
            'manage_token' => $otherToken,
            'time' => '20:00',
        ]));
    }

    public function testChangeToATakenSlotReturnsAlternatives(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->book('T07', '2026-11-10', '19:30', 4);

        try {
            $this->bookings->change([
                'reference' => $reference,
                'email' => $this->email,
                'table_id' => $this->tableIdByCode('T07'),
                'time' => '19:30',
            ]);
            self::fail('expected TABLE_TAKEN');
        } catch (DomainException $exception) {
            self::assertSame('TABLE_TAKEN', $exception->errorCode());
            self::assertSame(409, $exception->status());
            self::assertArrayHasKey('alternatives', $exception->details());
            // The original booking was not moved.
            self::assertSame('19:00', $this->bookingRow($reference)['start_time']);
        }
    }

    public function testChangeToABlockedTableIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $this->assertDomainCode('TABLE_UNAVAILABLE', fn () => $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'table_id' => $this->tableIdByCode('T17'),
        ]));
    }

    public function testChangeToAPastSlotIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->clock->advance('+6 hours');   // 2026-11-02 16:00, inside Afternoon Tea

        $this->assertDomainCode('SLOT_IN_PAST', fn () => $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'date' => '2026-11-02',
            'time' => '15:30',
        ]));
    }

    public function testChangeOutsideTheBookingWindowIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $far = $this->clock->now()->modify('+' . (Slot::MAX_DAYS_AHEAD + 1) . ' days')->format('Y-m-d');

        $this->assertDomainCode('DATE_OUT_OF_RANGE', fn () => $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'date' => $far,
        ]));
    }

    public function testChangeToAPartyTooLargeForTheTableIsRejected(): void
    {
        $reference = $this->book('T01', '2026-11-10', '19:00', 2);

        $this->assertDomainCode('PARTY_TOO_LARGE', fn () => $this->bookings->change([
            'reference' => $reference,
            'email' => $this->email,
            'party_size' => 6,
        ]));
    }

    public function testChangeValidatesEveryField(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        try {
            $this->bookings->change([
                'reference' => $reference,
                'email' => $this->email,
                'date' => '2026-13-40',
                'time' => '25:99',
                'party_size' => 0,
            ]);
            self::fail('expected VALIDATION_ERROR');
        } catch (DomainException $exception) {
            self::assertSame('VALIDATION_ERROR', $exception->errorCode());
            self::assertSame(
                ['date', 'time', 'party_size'],
                array_keys($exception->details()['errors'])
            );
        }
    }

    // ---------------------------------------------------------------- cancel

    public function testCancelMarksTheBookingCancelledAndReleasesTheTable(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $tableId = $this->tableIdByCode('T03');

        $result = $this->bookings->cancel([
            'reference' => $reference,
            'email' => $this->email,
            'reason' => 'Plans changed',
        ]);

        self::assertTrue($result['cancelled']);
        self::assertSame('CANCELLED', $result['booking']['status']);
        self::assertSame('CANCELLED', $this->bookingRow($reference)['status']);
        self::assertTrue($this->availability->isTableFree($tableId, '2026-11-10', '19:00', '21:15'));

        $events = $this->events($reference);
        self::assertCount(2, $events);
        self::assertSame('cancelled', $events[1]['event_type']);
        $payload = json_decode((string) $events[1]['payload'], true);
        self::assertSame('Plans changed', $payload['reason']);
        self::assertSame($tableId, $payload['released_table_id']);

        $notified = $this->pdo->prepare('SELECT template FROM notification_log WHERE booking_id = ? ORDER BY id');
        $notified->execute([(int) $this->bookingRow($reference)['id']]);
        self::assertSame(['booking_confirmation', 'booking_cancelled'], $notified->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testCancelIsIdempotent(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $first = $this->bookings->cancel(['reference' => $reference, 'email' => $this->email]);
        $second = $this->bookings->cancel(['reference' => $reference, 'email' => $this->email]);

        self::assertTrue($first['cancelled']);
        self::assertFalse($second['cancelled']);
        self::assertSame('CANCELLED', $second['booking']['status']);

        $cancelledEvents = array_filter($this->events($reference), static fn (array $event): bool => $event['event_type'] === 'cancelled');
        self::assertCount(1, $cancelledEvents);
    }

    public function testCancelAfterCutoffIsRejected(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->clock->advance('+8 days 7 hours 30 minutes');

        $this->assertDomainCode('CUTOFF_PASSED', fn () => $this->bookings->cancel([
            'reference' => $reference,
            'email' => $this->email,
        ]));
        self::assertSame('CONFIRMED', $this->bookingRow($reference)['status']);
    }

    public function testCancelRequiresAnIdentity(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);

        $this->assertDomainCode('AUTHORIZATION_REQUIRED', fn () => $this->bookings->cancel([
            'reference' => $reference,
        ]));
    }

    public function testCancelWithASignedManageLinkWorks(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $token = $this->bookings->issueManageToken($reference);

        $result = $this->bookings->cancel([
            'reference' => $reference,
            'manage_token' => $token,
        ]);

        self::assertTrue($result['cancelled']);
    }

    public function testManageTokenRoundTripReportsTheExpiry(): void
    {
        $reference = $this->book('T03', '2026-11-10', '19:00', 4);
        $token = $this->bookings->issueManageToken($reference, 24);

        $claims = $this->bookings->verifyManageToken($token);
        self::assertSame($reference, $claims['reference']);
        self::assertSame('2026-11-03T02:00:00Z', $claims['expires_at']);
    }
}
