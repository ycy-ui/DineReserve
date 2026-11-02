<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\DomainException;
use PDO;

/**
 * REQ-1.1 — availability search.
 *
 * Every expectation that depends on the demo seed is derived from
 * Database::seedDemo(): 60 tables with seats cycling 2/4/6/8 (15 of each size),
 * T17/T34/T51 BLOCKED and T23/T46 MAINTENANCE, so 55 FREE tables remain, and
 * three service periods (Lunch 90 min, Afternoon Tea 60 min, Dinner 120 min).
 */
final class AvailabilityServiceTest extends TestCase
{
    /** A normal weekday well inside the booking horizon. */
    private const DATE = '2026-11-10';

    private function service(int $cacheTtlSeconds = 30): AvailabilityService
    {
        return new AvailabilityService($this->pdo, $this->clock(), $cacheTtlSeconds);
    }

    /**
     * @param array<string,mixed> $result
     * @return list<string>
     */
    private function tableCodes(array $result): array
    {
        return array_column($result['tables'], 'code');
    }

    private function assertDomainError(callable $operation, string $expectedCode, int $expectedStatus = 422): DomainException
    {
        try {
            $operation();
        } catch (DomainException $exception) {
            self::assertSame($expectedCode, $exception->errorCode());
            self::assertSame($expectedStatus, $exception->status());

            return $exception;
        }

        self::fail(sprintf('Expected DomainException "%s" but the call succeeded.', $expectedCode));
    }

    public function testSearchReturnsTheDocumentedContractShape(): void
    {
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2);

        self::assertSame(
            ['restaurant', 'query', 'service_period', 'slots', 'tables', 'summary', 'meta'],
            array_keys($result)
        );

        self::assertSame([
            'id' => $this->restaurantId,
            'name' => 'DineReserve Demo Restaurant',
            'timezone' => 'Asia/Macau',
            'address' => 'Rua de Nam Keng, Taipa, Macau',
        ], $result['restaurant']);

        self::assertSame([
            'date' => self::DATE,
            'time' => '19:00',
            'party_size' => 2,
            'area' => null,
            'service_period' => 'Dinner',
        ], $result['query']);

        self::assertIsInt($result['service_period']['id']);
        self::assertSame('Dinner', $result['service_period']['name']);
        self::assertSame('17:30', $result['service_period']['start']);
        self::assertSame('22:00', $result['service_period']['end']);
        self::assertSame(120, $result['service_period']['avg_dining_minutes']);
        self::assertSame(135, $result['service_period']['slot_minutes']);

        self::assertNotEmpty($result['tables']);
        self::assertSame(['id', 'code', 'seats', 'area'], array_keys($result['tables'][0]));
        self::assertSame(['start', 'end', 'available_tables', 'largest_party_size'], array_keys($result['slots'][0]));

        self::assertSame(
            ['available_tables', 'largest_party_size', 'is_available'],
            array_keys($result['summary'])
        );
        self::assertSame([
            'generated_at' => '2026-11-02T10:00:00+08:00',
            'cached' => false,
            'cache_ttl_seconds' => 30,
        ], $result['meta']);
    }

    public function testDinnerSlotsCoverTheWholeServicePeriod(): void
    {
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2);
        $slots = $result['slots'];

        // Dinner 17:30-22:00 with a 120 + 15 min slot: 10 aligned starts,
        // the last one being 22:00 - 135 minutes = 19:45.
        self::assertCount(10, $slots);
        self::assertSame('17:30', $slots[0]['start']);
        self::assertSame('19:45', $slots[0]['end']);
        self::assertSame('19:45', $slots[9]['start']);
        self::assertSame('22:00', $slots[9]['end']);

        foreach ($slots as $slot) {
            self::assertSame(55, $slot['available_tables'], 'All 55 FREE tables are free when nothing is booked');
            self::assertSame(8, $slot['largest_party_size']);
        }

        // No bookings: 60 seed tables minus 3 BLOCKED and 2 MAINTENANCE.
        self::assertCount(55, $result['tables']);
        self::assertSame([
            'available_tables' => 55,
            'largest_party_size' => 8,
            'is_available' => true,
        ], $result['summary']);
    }

    public function testCapacityFilterOnlyOffersTablesThatSeatTheParty(): void
    {
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 6);

        self::assertNotEmpty($result['tables']);
        foreach ($result['tables'] as $table) {
            self::assertGreaterThanOrEqual(6, $table['seats']);
            self::assertSame('FREE', $this->table($table['id'])['status']);
        }

        // 15 six-seat and 15 eight-seat tables exist; T23 and T51 (both six
        // seats) are out of service, leaving 13 + 15 = 28 bookable tables.
        self::assertCount(28, $result['tables']);
        self::assertSame(28, $result['summary']['available_tables']);
        self::assertSame(8, $result['summary']['largest_party_size']);
    }

    public function testBlockedAndMaintenanceTablesAreNeverOffered(): void
    {
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2);

        $unavailableCodes = $this->pdo
            ->query("SELECT code FROM tables WHERE status <> 'FREE' ORDER BY code")
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['T17', 'T23', 'T34', 'T46', 'T51'], $unavailableCodes);

        $freeCodes = $this->pdo
            ->query("SELECT code FROM tables WHERE status = 'FREE' ORDER BY code")
            ->fetchAll(PDO::FETCH_COLUMN);

        self::assertSame($freeCodes, $this->tableCodes($result));
        self::assertSame([], array_intersect($this->tableCodes($result), $unavailableCodes));
    }

    public function testOverlappingConfirmedBookingRemovesTheTable(): void
    {
        $tableId = $this->tableIdByCode('T05');
        $this->insertBooking([
            'table_id' => $tableId,
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
            'status' => 'CONFIRMED',
        ]);

        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2);

        self::assertNotContains('T05', $this->tableCodes($result));
        self::assertSame(54, $result['summary']['available_tables']);
    }

    public function testOnlyConfirmedAndSeatedBookingsOccupyATable(): void
    {
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T06'),
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
            'status' => 'SEATED',
        ]);
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T07'),
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
            'status' => 'CANCELLED',
        ]);
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T08'),
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
            'status' => 'NO_SHOW',
        ]);

        $codes = $this->tableCodes($this->service()->search($this->restaurantId, self::DATE, '19:00', 2));

        self::assertNotContains('T06', $codes);
        self::assertContains('T07', $codes);
        self::assertContains('T08', $codes);
    }

    public function testBackToBackBookingsDoNotConflict(): void
    {
        // T05 is booked right up to 19:00, T06 starts exactly when the 19:00
        // slot ends (19:00 + 135 min = 21:15). Half-open intervals: free.
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T05'),
            'booking_date' => self::DATE,
            'start_time' => '17:30',
            'end_time' => '19:00',
        ]);
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T06'),
            'booking_date' => self::DATE,
            'start_time' => '21:15',
            'end_time' => '23:00',
        ]);

        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2);

        self::assertContains('T05', $this->tableCodes($result));
        self::assertContains('T06', $this->tableCodes($result));
        self::assertSame(55, $result['summary']['available_tables']);
    }

    public function testSlotsReportAvailabilityPerStartTime(): void
    {
        // T06 is booked 20:00-21:45, so it is still free at the earlier starts
        // whose slot ends by 20:00, and back-to-back at 17:45.
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T06'),
            'booking_date' => self::DATE,
            'start_time' => '20:00',
            'end_time' => '21:45',
        ]);

        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2);
        $slots = [];
        foreach ($result['slots'] as $slot) {
            $slots[$slot['start']] = $slot;
        }

        self::assertSame(55, $slots['17:30']['available_tables']);
        self::assertSame(55, $slots['17:45']['available_tables'], 'Slot ends exactly when the booking starts');
        self::assertSame(54, $slots['18:00']['available_tables']);
        self::assertSame(54, $slots['19:45']['available_tables']);
        self::assertSame(54, $result['summary']['available_tables']);
    }

    public function testRejectsPastDate(): void
    {
        $exception = $this->assertDomainError(
            fn (): array => $this->service()->search($this->restaurantId, '2026-11-01', '19:00', 2),
            'DATE_OUT_OF_RANGE'
        );

        // "Today" is 2026-11-02 on the frozen clock, plus 90 days = 2027-01-31.
        self::assertSame(['min' => '2026-11-02', 'max' => '2027-01-31'], $exception->details());
    }

    public function testRejectsDateBeyondTheHorizon(): void
    {
        $exception = $this->assertDomainError(
            fn (): array => $this->service()->search($this->restaurantId, '2027-02-01', '19:00', 2),
            'DATE_OUT_OF_RANGE'
        );

        self::assertSame(['min' => '2026-11-02', 'max' => '2027-01-31'], $exception->details());
    }

    public function testAcceptsBoundaryDates(): void
    {
        $service = $this->service();

        foreach (['2026-11-02', '2027-01-31'] as $date) {
            $result = $service->search($this->restaurantId, $date, '19:00', 2);
            self::assertSame($date, $result['query']['date']);
            self::assertSame(55, $result['summary']['available_tables']);
        }
    }

    public function testRejectsMalformedDates(): void
    {
        foreach (['2026-11-1', '11/10/2026', '2026-02-30', 'not-a-date'] as $invalidDate) {
            $this->assertDomainError(
                fn (): array => $this->service()->search($this->restaurantId, $invalidDate, '19:00', 2),
                'DATE_OUT_OF_RANGE'
            );
        }
    }

    public function testRejectsNonPositivePartySize(): void
    {
        foreach ([0, -1] as $partySize) {
            $exception = $this->assertDomainError(
                fn (): array => $this->service()->search($this->restaurantId, self::DATE, '19:00', $partySize),
                'VALIDATION_ERROR'
            );
            self::assertArrayHasKey('party_size', $exception->details()['errors']);
        }
    }

    public function testRejectsPartyLargerThanTwenty(): void
    {
        $exception = $this->assertDomainError(
            fn (): array => $this->service()->search($this->restaurantId, self::DATE, '19:00', 21),
            'PARTY_TOO_LARGE'
        );

        self::assertSame(20, $exception->details()['max_party_size']);
        self::assertArrayHasKey('telephone', $exception->details());
        self::assertIsString($exception->details()['telephone']);
        self::assertNotSame('', $exception->details()['telephone']);
    }

    public function testPartyOfTwentyIsAValidQueryWithoutTables(): void
    {
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 20);

        self::assertSame(20, $result['query']['party_size']);
        self::assertSame([], $result['tables']);
        self::assertSame([
            'available_tables' => 0,
            'largest_party_size' => 0,
            'is_available' => false,
        ], $result['summary']);
        foreach ($result['slots'] as $slot) {
            self::assertSame(0, $slot['available_tables']);
        }
    }

    public function testRejectsTimeOutsideServicePeriods(): void
    {
        $service = $this->service();

        // 14:30 is the exclusive end of Lunch; 14:45 is the gap before tea;
        // 22:00 is the exclusive end of Dinner; 03:00 is closed.
        foreach (['14:30', '14:45', '22:00', '03:00'] as $time) {
            $exception = $this->assertDomainError(
                fn (): array => $service->search($this->restaurantId, self::DATE, $time, 2),
                'NO_SERVICE_PERIOD'
            );

            $details = $exception->details();
            self::assertArrayHasKey('service_periods', $details);
            self::assertSame($time, $details['requested_time']);
            self::assertArrayHasKey('latest_start', $details);
            self::assertSame(
                ['Lunch', 'Afternoon Tea', 'Dinner'],
                array_column($details['service_periods'], 'name')
            );
            foreach ($details['service_periods'] as $period) {
                self::assertSame(['id', 'name', 'start', 'end'], array_keys($period));
            }
        }

        // The first minute of a period is inside it.
        self::assertSame(
            'Lunch',
            $service->search($this->restaurantId, self::DATE, '11:30', 2)['service_period']['name']
        );
        self::assertSame(
            'Afternoon Tea',
            $service->search($this->restaurantId, self::DATE, '15:00', 2)['service_period']['name']
        );
        self::assertSame(
            'Dinner',
            $service->search($this->restaurantId, self::DATE, '17:30', 2)['service_period']['name']
        );
    }

    public function testLastBookableDinnerStartIsAccepted(): void
    {
        // 19:45 + 135 minutes = 22:00, exactly the closing time of Dinner.
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:45', 2);

        self::assertSame('19:45', $result['query']['time']);
        self::assertSame('Dinner', $result['service_period']['name']);
        self::assertCount(10, $result['slots']);
        self::assertSame('19:45', $result['slots'][9]['start']);
        self::assertSame('22:00', $result['slots'][9]['end']);
        self::assertNotEmpty($result['tables']);
        self::assertSame(55, $result['summary']['available_tables']);
    }

    public function testRejectsDinnerStartWhoseSlotWouldOverrunClosing(): void
    {
        // 20:30 + 135 minutes = 22:45, past the 22:00 closing time.
        $exception = $this->assertDomainError(
            fn (): array => $this->service()->search($this->restaurantId, self::DATE, '20:30', 2),
            'NO_SERVICE_PERIOD'
        );

        $details = $exception->details();
        self::assertSame('20:30', $details['requested_time']);
        self::assertSame('19:45', $details['latest_start']);
        self::assertSame(
            ['Lunch', 'Afternoon Tea', 'Dinner'],
            array_column($details['service_periods'], 'name')
        );
    }

    public function testRejectsLunchStartWhoseSlotWouldOverrunClosing(): void
    {
        // 13:00 + 105 minutes = 14:45, past the 14:30 end of Lunch.
        $exception = $this->assertDomainError(
            fn (): array => $this->service()->search($this->restaurantId, self::DATE, '13:00', 2),
            'NO_SERVICE_PERIOD'
        );

        $details = $exception->details();
        self::assertSame('13:00', $details['requested_time']);
        self::assertSame('12:45', $details['latest_start']);
    }

    public function testServicePeriodFilterNarrowsTheSearch(): void
    {
        $service = $this->service();
        $result = $service->search($this->restaurantId, self::DATE, '12:00', 2, ['service_period' => 'Lunch']);

        self::assertSame('Lunch', $result['query']['service_period']);
        self::assertSame('Lunch', $result['service_period']['name']);
        self::assertSame('11:30', $result['service_period']['start']);
        self::assertSame('14:30', $result['service_period']['end']);
        self::assertSame(90, $result['service_period']['avg_dining_minutes']);
        self::assertSame(105, $result['service_period']['slot_minutes']);

        // Lunch slot 11:30 + 105 min = 13:15, last start 14:30 - 105 = 12:45.
        self::assertCount(6, $result['slots']);
        self::assertSame('11:30', $result['slots'][0]['start']);
        self::assertSame('12:45', $result['slots'][5]['start']);
        self::assertSame('14:30', $result['slots'][5]['end']);

        // The same time is not inside the filtered Dinner period.
        $this->assertDomainError(
            fn (): array => $service->search(
                $this->restaurantId,
                self::DATE,
                '12:00',
                2,
                ['service_period' => 'Dinner']
            ),
            'NO_SERVICE_PERIOD'
        );

        // An unknown service period name cannot match any period either.
        $this->assertDomainError(
            fn (): array => $service->search(
                $this->restaurantId,
                self::DATE,
                '12:00',
                2,
                ['service_period' => 'Brunch']
            ),
            'NO_SERVICE_PERIOD'
        );
    }

    public function testAreaFilterOnlyOffersTablesInThatArea(): void
    {
        $service = $this->service();

        // The seed has 20 window tables, all with at least 2 seats; T17 is a
        // BLOCKED window table, so 19 remain.
        $window = $service->search($this->restaurantId, self::DATE, '19:00', 2, ['area' => 'window']);
        self::assertSame('window', $window['query']['area']);
        self::assertCount(19, $window['tables']);
        self::assertSame(19, $window['summary']['available_tables']);
        foreach ($window['tables'] as $table) {
            self::assertSame('window', $table['area']);
        }

        // Five main-area tables seat 8 guests (T04, T16, T28, T40, T52).
        $mainLarge = $service->search($this->restaurantId, self::DATE, '19:00', 8, ['area' => 'main']);
        self::assertCount(5, $mainLarge['tables']);
        foreach ($mainLarge['tables'] as $table) {
            self::assertSame('main', $table['area']);
            self::assertGreaterThanOrEqual(8, $table['seats']);
        }
        self::assertSame(8, $mainLarge['summary']['largest_party_size']);
    }

    public function testUnknownAreaYieldsNoAvailability(): void
    {
        $result = $this->service()->search($this->restaurantId, self::DATE, '19:00', 2, ['area' => 'rooftop']);

        self::assertSame('rooftop', $result['query']['area']);
        self::assertSame([], $result['tables']);
        self::assertSame([
            'available_tables' => 0,
            'largest_party_size' => 0,
            'is_available' => false,
        ], $result['summary']);
        foreach ($result['slots'] as $slot) {
            self::assertSame(0, $slot['available_tables']);
            self::assertSame(0, $slot['largest_party_size']);
        }
    }

    public function testCacheHitIsMarkedAndInvalidateForcesARecalculation(): void
    {
        $service = $this->service();

        $first = $service->search($this->restaurantId, self::DATE, '19:00', 2);
        self::assertFalse($first['meta']['cached']);

        $second = $service->search($this->restaurantId, self::DATE, '19:00', 2);
        self::assertTrue($second['meta']['cached']);
        self::assertSame($first['tables'], $second['tables']);
        self::assertSame($first['slots'], $second['slots']);
        self::assertSame($first['summary'], $second['summary']);

        // Different filters are cached under a different key.
        $filtered = $service->search($this->restaurantId, self::DATE, '19:00', 2, ['area' => 'main']);
        self::assertFalse($filtered['meta']['cached']);

        $service->invalidate($this->restaurantId, self::DATE);
        $third = $service->search($this->restaurantId, self::DATE, '19:00', 2);
        self::assertFalse($third['meta']['cached']);
        self::assertSame($first['tables'], $third['tables']);
    }

    public function testInvalidateMakesANewBookingVisible(): void
    {
        $service = $this->service();

        $before = $service->search($this->restaurantId, self::DATE, '19:00', 2);
        self::assertContains('T05', $this->tableCodes($before));

        // The first search primed the cache; book T05 behind the service's back.
        self::assertTrue($service->search($this->restaurantId, self::DATE, '19:00', 2)['meta']['cached']);
        $this->insertBooking([
            'table_id' => $this->tableIdByCode('T05'),
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
        ]);

        $stale = $service->search($this->restaurantId, self::DATE, '19:00', 2);
        self::assertTrue($stale['meta']['cached']);
        self::assertContains('T05', $this->tableCodes($stale));

        $service->invalidate($this->restaurantId, self::DATE);
        $fresh = $service->search($this->restaurantId, self::DATE, '19:00', 2);

        self::assertFalse($fresh['meta']['cached']);
        self::assertNotContains('T05', $this->tableCodes($fresh));
        self::assertSame(54, $fresh['summary']['available_tables']);
    }

    public function testCacheExpiresAfterTtl(): void
    {
        $service = $this->service(30);

        $service->search($this->restaurantId, self::DATE, '19:00', 2);
        $this->clock->advance('+31 seconds');
        $afterExpiry = $service->search($this->restaurantId, self::DATE, '19:00', 2);

        self::assertFalse($afterExpiry['meta']['cached']);
        self::assertSame(30, $afterExpiry['meta']['cache_ttl_seconds']);
    }

    public function testZeroTtlDisablesTheCache(): void
    {
        $service = $this->service(0);

        $service->search($this->restaurantId, self::DATE, '19:00', 2);
        $second = $service->search($this->restaurantId, self::DATE, '19:00', 2);

        self::assertFalse($second['meta']['cached']);
        self::assertSame(0, $second['meta']['cache_ttl_seconds']);
    }

    public function testIsTableFreeWithoutBookings(): void
    {
        $service = $this->service();

        self::assertTrue($service->isTableFree($this->tableIdByCode('T05'), self::DATE, '19:00', '21:15'));
    }

    public function testIsTableFreeDetectsOverlapsAndHonoursBackToBack(): void
    {
        $service = $this->service();
        $tableId = $this->tableIdByCode('T05');
        $bookingId = $this->insertBooking([
            'table_id' => $tableId,
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
        ]);

        self::assertFalse($service->isTableFree($tableId, self::DATE, '19:00', '21:15'));
        self::assertFalse($service->isTableFree($tableId, self::DATE, '20:00', '21:00'));
        self::assertTrue($service->isTableFree($tableId, self::DATE, '17:30', '19:00'), 'Back-to-back before');
        self::assertTrue($service->isTableFree($tableId, self::DATE, '20:45', '22:00'), 'Back-to-back after');
        self::assertTrue($service->isTableFree($tableId, self::DATE, '19:00', '21:15', $bookingId));
        self::assertTrue($service->isTableFree($tableId, '2026-11-11', '19:00', '21:15'), 'Other date');
        self::assertTrue(
            $service->isTableFree($this->tableIdByCode('T06'), self::DATE, '19:00', '21:15'),
            'Other table'
        );
    }

    public function testIsTableFreeIgnoresCancelledAndNoShowBookings(): void
    {
        $service = $this->service();
        $tableId = $this->tableIdByCode('T07');

        foreach (['CANCELLED', 'NO_SHOW'] as $status) {
            $this->insertBooking([
                'table_id' => $tableId,
                'booking_date' => self::DATE,
                'start_time' => '19:00',
                'end_time' => '20:45',
                'status' => $status,
            ]);
        }

        self::assertTrue($service->isTableFree($tableId, self::DATE, '19:00', '21:15'));

        $this->insertBooking([
            'table_id' => $tableId,
            'booking_date' => self::DATE,
            'start_time' => '19:00',
            'end_time' => '20:45',
            'status' => 'SEATED',
        ]);

        self::assertFalse($service->isTableFree($tableId, self::DATE, '19:00', '21:15'));
    }

    public function testUnknownRestaurantIsNotFound(): void
    {
        $this->assertDomainError(
            fn (): array => $this->service()->search($this->restaurantId + 1, self::DATE, '19:00', 2),
            'NOT_FOUND',
            404
        );
    }
}
