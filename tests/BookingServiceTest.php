<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\DomainException;
use DineReserve\Slot;

/**
 * Unit coverage for REQ-1.2 "create a booking": the contract shape, the
 * all-at-once validation, every rejection path and the idempotent replay.
 */
final class BookingServiceTest extends TestCase
{
    private function service(): BookingService
    {
        return new BookingService($this->pdo, new AvailabilityService($this->pdo), $this->clock);
    }

    /**
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'restaurant_id' => $this->restaurantId,
            // T03: 6 seats, main area, FREE.
            'table_id' => $this->tableIdByCode('T03'),
            'date' => '2026-11-10',
            'time' => '19:00',
            'party_size' => 6,
            'name' => 'Chan Tai Man',
            'email' => 'chan@example.com',
        ], $overrides);
    }

    private function countRows(string $table, string $where = '1 = 1', array $params = []): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param callable():mixed $callback
     */
    private function assertDomainError(string $code, int $status, callable $callback): DomainException
    {
        try {
            $callback();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode());
            self::assertSame($status, $exception->status());

            return $exception;
        }

        self::fail(sprintf('Expected a DomainException with code %s, but no exception was thrown.', $code));
    }

    /**
     * Run a test body with RESTAURANT_TELEPHONE set to the given value (null
     * unsets it), restoring the previous process environment afterwards.
     *
     * @param callable():mixed $callback
     */
    private function withTelephoneEnvironment(?string $value, callable $callback): void
    {
        $original = getenv('RESTAURANT_TELEPHONE');

        if ($value === null) {
            putenv('RESTAURANT_TELEPHONE');
        } else {
            putenv('RESTAURANT_TELEPHONE=' . $value);
        }

        try {
            $callback();
        } finally {
            if ($original === false) {
                putenv('RESTAURANT_TELEPHONE');
            } else {
                putenv('RESTAURANT_TELEPHONE=' . $original);
            }
        }
    }

    public function testCreatesBookingAndReturnsTheContractShape(): void
    {
        $result = $this->service()->create($this->validInput([
            'mobile' => '+853 0000 0000',
            'special_requests' => 'Window seat please',
        ]));

        self::assertTrue($result['created']);

        $booking = $result['booking'];
        self::assertSame([
            'id', 'reference', 'status', 'date', 'start', 'end', 'party_size', 'table',
            'guest', 'special_requests', 'cutoff_at', 'reminders', 'channel', 'created_at',
        ], array_keys($booking));

        self::assertIsInt($booking['id']);
        self::assertSame('CONFIRMED', $booking['status']);
        self::assertSame('2026-11-10', $booking['date']);
        self::assertSame('19:00', $booking['start']);
        self::assertSame('21:15', $booking['end']);
        self::assertSame(6, $booking['party_size']);
        self::assertSame([
            'id' => $this->tableIdByCode('T03'),
            'code' => 'T03',
            'seats' => 6,
            'area' => 'main',
        ], $booking['table']);
        self::assertSame([
            'name' => 'Chan Tai Man',
            'email' => 'chan@example.com',
            'mobile' => '+853 0000 0000',
        ], $booking['guest']);
        self::assertSame('Window seat please', $booking['special_requests']);
        self::assertSame('online', $booking['channel']);
        self::assertSame('2026-11-02T10:00:00+08:00', $booking['created_at']);

        // Cut-off is start - 120 minutes, ISO 8601 with the restaurant offset.
        self::assertSame('2026-11-10T17:00:00+08:00', $booking['cutoff_at']);

        // Reminders are 24 h and 2 h before the start.
        self::assertSame(
            ['2026-11-09T19:00:00+08:00', '2026-11-10T17:00:00+08:00'],
            $booking['reminders']
        );

        // Reference: 8 chars, unambiguous alphabet only (no O/0/I/1).
        self::assertSame(BookingService::REFERENCE_LENGTH, strlen($booking['reference']));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $booking['reference']);

        // Persisted exactly once, with the contract columns.
        $row = $this->pdo
            ->query('SELECT * FROM bookings')
            ->fetchAll();
        self::assertCount(1, $row);
        $row = $row[0];
        self::assertSame($booking['reference'], $row['reference']);
        self::assertSame('CONFIRMED', $row['status']);
        self::assertSame('online', $row['channel']);
        self::assertSame('2026-11-10T17:00:00+08:00', $row['cutoff_at']);
        self::assertSame('2026-11-02T10:00:00+08:00', $row['created_at']);
        self::assertNull($row['idempotency_token']);
        self::assertSame('chan@example.com', $row['guest_email']);
        self::assertSame('+853 0000 0000', $row['guest_mobile']);
        self::assertSame('Window seat please', $row['special_requests']);

        // Audit event.
        $event = $this->pdo
            ->query('SELECT * FROM booking_events')
            ->fetchAll();
        self::assertCount(1, $event);
        self::assertSame('created', $event[0]['event_type']);
        self::assertSame('diner', $event[0]['actor']);
        self::assertSame((int) $booking['id'], (int) $event[0]['booking_id']);
        $payload = json_decode((string) $event[0]['payload'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($booking['reference'], $payload['reference']);
        self::assertSame('2026-11-02T10:00:00+08:00', $event[0]['occurred_at']);

        // Simulated confirmation e-mail (W8 queue placeholder).
        $notification = $this->pdo
            ->query('SELECT * FROM notification_log')
            ->fetchAll();
        self::assertCount(1, $notification);
        self::assertSame((int) $booking['id'], (int) $notification[0]['booking_id']);
        self::assertSame('email', $notification[0]['channel']);
        self::assertSame('booking_confirmation', $notification[0]['template']);
        self::assertSame('QUEUED', $notification[0]['status']);
        self::assertSame('2026-11-02T10:00:00+08:00', $notification[0]['scheduled_at']);

        // No idempotency token was supplied, so no key row exists.
        self::assertSame(0, $this->countRows('idempotency_keys'));
    }

    public function testValidationCollectsEveryFieldErrorAtOnce(): void
    {
        $exception = $this->assertDomainError('VALIDATION_ERROR', 422, function (): void {
            $this->service()->create([
                'restaurant_id' => '',
                'table_id' => null,
                'date' => '10/11/2026',
                'time' => '7pm',
                'party_size' => 0,
                'name' => '',
                'email' => 'not-an-email',
                'mobile' => 123,
                'special_requests' => str_repeat('x', 201),
                'idempotency_token' => 'short',
            ]);
        });

        $details = $exception->details();
        self::assertArrayHasKey('errors', $details);

        $fields = array_keys($details['errors']);
        sort($fields);
        self::assertSame([
            'date', 'email', 'idempotency_token', 'mobile', 'name',
            'party_size', 'restaurant_id', 'special_requests', 'table_id', 'time',
        ], $fields);

        // An invalid request never writes anything.
        self::assertSame(0, $this->countRows('bookings'));
        self::assertSame(0, $this->countRows('booking_events'));
        self::assertSame(0, $this->countRows('notification_log'));
    }

    public function testValidationAcceptsOnlyMissingEmailAsSingleError(): void
    {
        $exception = $this->assertDomainError('VALIDATION_ERROR', 422, function (): void {
            $this->service()->create($this->validInput(['email' => '']));
        });

        self::assertSame(['email' => 'An e-mail address is required.'], $exception->details()['errors']);
    }

    public function testUnknownTableReturnsNotFound(): void
    {
        $this->assertDomainError('NOT_FOUND', 404, function (): void {
            $this->service()->create($this->validInput(['table_id' => 999999]));
        });

        self::assertSame(0, $this->countRows('bookings'));
    }

    public function testTableOfAnotherRestaurantReturnsNotFound(): void
    {
        $now = '2026-11-02T10:00:00+08:00';
        $this->pdo->prepare(
            'INSERT INTO restaurants (name, address, timezone, currency, languages, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(['Other Restaurant', 'Elsewhere', 'Asia/Macau', 'MOP', 'en', $now]);
        $otherRestaurantId = (int) $this->pdo->lastInsertId();

        $this->assertDomainError('NOT_FOUND', 404, function () use ($otherRestaurantId): void {
            $this->service()->create($this->validInput(['restaurant_id' => $otherRestaurantId]));
        });

        // An entirely unknown restaurant is NOT_FOUND as well.
        $this->assertDomainError('NOT_FOUND', 404, function (): void {
            $this->service()->create($this->validInput(['restaurant_id' => 999999]));
        });
    }

    public function testBlockedAndMaintenanceTablesAreUnavailable(): void
    {
        foreach (['T17', 'T23'] as $code) {
            $exception = $this->assertDomainError('TABLE_UNAVAILABLE', 409, function () use ($code): void {
                $this->service()->create($this->validInput([
                    'table_id' => $this->tableIdByCode($code),
                    'party_size' => 2,
                ]));
            });

            self::assertSame($this->tableIdByCode($code), $exception->details()['table_id']);
        }

        self::assertSame(0, $this->countRows('bookings'));
    }

    public function testPartyLargerThanTheTableIsRejected(): void
    {
        // T01 seats 2.
        $exception = $this->assertDomainError('PARTY_TOO_LARGE', 422, function (): void {
            $this->service()->create($this->validInput([
                'table_id' => $this->tableIdByCode('T01'),
                'party_size' => 6,
            ]));
        });

        self::assertSame(2, $exception->details()['max_party_size']);
        self::assertSame(0, $this->countRows('bookings'));
    }

    public function testPartyOverTheOnlineMaximumIsRejected(): void
    {
        $this->withTelephoneEnvironment(null, function (): void {
            $exception = $this->assertDomainError('PARTY_TOO_LARGE', 422, function (): void {
                $this->service()->create($this->validInput(['party_size' => 21]));
            });

            self::assertSame(BookingService::MAX_PARTY_SIZE, $exception->details()['max_party_size']);
            // No phone number is hard-coded: without configuration the hint is
            // plain wording and the details carry no telephone key.
            self::assertArrayNotHasKey('telephone', $exception->details());
            self::assertStringNotContainsString('Telephone:', $exception->getMessage());
            self::assertStringContainsString(
                'Please call the restaurant to book a table for more than 20 guests.',
                $exception->getMessage()
            );
        });
    }

    public function testTelephoneHintIsSuppliedByTheDeploymentEnvironment(): void
    {
        $this->withTelephoneEnvironment('+853 0000 1111', function (): void {
            $exception = $this->assertDomainError('PARTY_TOO_LARGE', 422, function (): void {
                $this->service()->create($this->validInput(['party_size' => 21]));
            });

            self::assertSame('+853 0000 1111', $exception->details()['telephone']);
            self::assertStringEndsWith(
                'Please call the restaurant to book a table for more than 20 guests. Telephone: +853 0000 1111',
                $exception->getMessage()
            );
        });
    }

    public function testSlotMustEndBeforeTheServicePeriodCloses(): void
    {
        // Dinner closes at 22:00; a 135-minute sitting at 20:30 would overrun it.
        $exception = $this->assertDomainError('NO_SERVICE_PERIOD', 422, function (): void {
            $this->service()->create($this->validInput(['time' => '20:30']));
        });

        self::assertSame('20:30', $exception->details()['requested_time']);
        self::assertSame('19:45', $exception->details()['latest_start']);
        self::assertSame(0, $this->countRows('bookings'));

        // The last start that still fits: 19:45 + 135 minutes = 22:00.
        $result = $this->service()->create($this->validInput(['time' => '19:45']));

        self::assertTrue($result['created']);
        self::assertSame('19:45', $result['booking']['start']);
        self::assertSame('22:00', $result['booking']['end']);
    }

    public function testSlotInThePastIsRejected(): void
    {
        // Move the clock to 19:00 on the same evening.
        $this->clock->advance('+9 hours');

        $exception = $this->assertDomainError('SLOT_IN_PAST', 422, function (): void {
            $this->service()->create($this->validInput([
                'date' => '2026-11-02',
                'time' => '17:30',
            ]));
        });

        self::assertSame('2026-11-02', $exception->details()['date']);
        self::assertSame(0, $this->countRows('bookings'));
    }

    public function testDateOutsideTheBookableWindowIsRejected(): void
    {
        foreach (['2026-11-01', '2027-02-15'] as $date) {
            $exception = $this->assertDomainError('DATE_OUT_OF_RANGE', 422, function () use ($date): void {
                $this->service()->create($this->validInput(['date' => $date]));
            });

            self::assertSame('2026-11-02', $exception->details()['min']);
            self::assertSame('2027-01-31', $exception->details()['max']);
        }
    }

    public function testTimeOutsideEveryServicePeriodIsRejected(): void
    {
        $exception = $this->assertDomainError('NO_SERVICE_PERIOD', 422, function (): void {
            $this->service()->create($this->validInput(['time' => '14:45']));
        });

        self::assertCount(3, $exception->details()['periods']);
    }

    public function testDoubleBookingReturnsTableTakenWithAlternatives(): void
    {
        $service = $this->service();
        $first = $service->create($this->validInput());
        self::assertTrue($first['created']);

        $exception = $this->assertDomainError('TABLE_TAKEN', 409, function () use ($service): void {
            $service->create($this->validInput());
        });

        $details = $exception->details();
        self::assertSame($this->tableIdByCode('T03'), $details['table_id']);
        self::assertNotEmpty($details['alternatives']);
        self::assertLessThanOrEqual(3, count($details['alternatives']));

        foreach ($details['alternatives'] as $alternative) {
            self::assertArrayHasKey('id', $alternative);
            self::assertArrayHasKey('code', $alternative);
            self::assertArrayHasKey('seats', $alternative);
            self::assertArrayHasKey('area', $alternative);
            self::assertGreaterThanOrEqual(6, $alternative['seats']);
            self::assertNotSame($this->tableIdByCode('T03'), $alternative['id']);
        }

        // The failed attempt rolled back: exactly one booking and its side effects.
        self::assertSame(1, $this->countRows('bookings'));
        self::assertSame(1, $this->countRows('booking_events'));
        self::assertSame(1, $this->countRows('notification_log'));
    }

    public function testBackToBackBookingIsAllowed(): void
    {
        $tableId = $this->tableIdByCode('T03');
        // Existing dinner sitting finishes exactly when the new one starts.
        $this->insertBooking([
            'table_id' => $tableId,
            'booking_date' => '2026-11-10',
            'start_time' => '17:30',
            'end_time' => '19:00',
            'party_size' => 2,
        ]);

        $result = $this->service()->create($this->validInput([
            'table_id' => $tableId,
            'time' => '19:00',
        ]));

        self::assertTrue($result['created']);
        self::assertSame(2, $this->countRows('bookings'));
    }

    public function testIdempotentReplayReturnsTheOriginalBooking(): void
    {
        $input = $this->validInput(['idempotency_token' => 'idem-token-0001']);
        $service = $this->service();

        $first = $service->create($input);
        self::assertTrue($first['created']);

        $replay = $service->create($input);
        self::assertFalse($replay['created']);
        self::assertSame($first['booking'], $replay['booking']);

        self::assertSame(1, $this->countRows('bookings'));
        self::assertSame(1, $this->countRows('booking_events'));
        self::assertSame(1, $this->countRows('notification_log'));
        self::assertSame(1, $this->countRows('idempotency_keys'));
        self::assertSame(
            (int) $first['booking']['id'],
            (int) $this->pdo->query('SELECT booking_id FROM idempotency_keys')->fetchColumn()
        );
    }

    public function testEmailIsStoredLowerCased(): void
    {
        $result = $this->service()->create($this->validInput([
            'email' => 'Chan.Tai.Man@Example.COM',
        ]));

        self::assertSame('chan.tai.man@example.com', $result['booking']['guest']['email']);
        self::assertSame(
            'chan.tai.man@example.com',
            $this->pdo->query('SELECT guest_email FROM bookings')->fetchColumn()
        );
    }

    public function testSpecialRequestsLongerThan200CharactersAreRejected(): void
    {
        $exception = $this->assertDomainError('VALIDATION_ERROR', 422, function (): void {
            $this->service()->create($this->validInput(['special_requests' => str_repeat('a', 201)]));
        });

        self::assertArrayHasKey('special_requests', $exception->details()['errors']);
        self::assertSame(0, $this->countRows('bookings'));
    }

    public function testEveryBookingGetsAUniqueUnambiguousReference(): void
    {
        $service = $this->service();
        $references = [];

        foreach (['T03', 'T07', 'T11', 'T15', 'T19'] as $code) {
            $result = $service->create($this->validInput(['table_id' => $this->tableIdByCode($code)]));
            $reference = $result['booking']['reference'];

            self::assertSame(8, strlen($reference));
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $reference);
            $references[] = $reference;
        }

        self::assertCount(count($references), array_unique($references));
        self::assertSame(5, $this->countRows('bookings'));
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $result = $this->service()->create($this->validInput());

        self::assertNull($result['booking']['guest']['mobile']);
        self::assertNull($result['booking']['special_requests']);

        $booking = Slot::iso8601($this->clock->now());
        self::assertSame('2026-11-02T10:00:00+08:00', $booking);
    }
}
