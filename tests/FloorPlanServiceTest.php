<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\DomainException;
use DineReserve\FloorPlanService;

/**
 * URS REQ-1.6 — floor plan and table-status management (W10).
 */
final class FloorPlanServiceTest extends TestCase
{
    private BookingService $bookings;

    private AvailabilityService $availability;

    private FloorPlanService $floorplan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new AvailabilityService($this->pdo, $this->clock);
        $this->bookings = new BookingService($this->pdo, $this->availability, $this->clock);
        $this->floorplan = new FloorPlanService($this->pdo, $this->availability, $this->clock, $this->bookings);
    }

    private function book(string $tableCode = 'T03', string $date = '2026-11-10', string $time = '19:00', int $party = 4): array
    {
        return $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode($tableCode),
            'date' => $date,
            'time' => $time,
            'party_size' => $party,
            'name' => 'Floor Guest',
            'email' => 'floor@example.com',
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

    /** @return array<string,mixed> */
    private function tableSummary(string $code): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND code = ?');
        $statement->execute([$this->restaurantId, $code]);
        $row = $statement->fetch();
        self::assertIsArray($row);

        return $row;
    }

    private function availability(): AvailabilityService
    {
        return new AvailabilityService($this->pdo, $this->clock);
    }

    // ---------------------------------------------------------------- snapshot

    public function testSnapshotListsTablesAndMarksTheCurrentBooking(): void
    {
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);

        $snapshot = $this->floorplan->snapshot($this->restaurantId, '2026-11-10', '19:30');

        self::assertSame(5, $snapshot['poll_after_seconds']);
        self::assertSame('2026-11-10', $snapshot['date']);
        self::assertCount(60, $snapshot['tables']);

        $table = null;
        foreach ($snapshot['tables'] as $candidate) {
            if ($candidate['code'] === 'T03') {
                $table = $candidate;
                break;
            }
        }
        self::assertNotNull($table);
        self::assertSame('FREE', $table['status']);
        self::assertSame($booking['booking']['reference'], $table['current_booking']['reference']);
        self::assertSame('19:00', $table['current_booking']['start']);
        self::assertSame(4, $table['current_booking']['party_size']);
    }

    public function testSnapshotShowsTheUpcomingBookingBeforeTheSlot(): void
    {
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);

        $snapshot = $this->floorplan->snapshot($this->restaurantId, '2026-11-10', '10:00');
        $table = null;
        foreach ($snapshot['tables'] as $candidate) {
            if ($candidate['code'] === 'T03') {
                $table = $candidate;
                break;
            }
        }

        self::assertNull($table['current_booking']);
        self::assertSame($booking['booking']['reference'], $table['upcoming_booking']['reference']);
        self::assertSame(1, $snapshot['summary']['booked_today']);
    }

    public function testSnapshotValidatesTheRestaurant(): void
    {
        $this->assertDomainCode('NOT_FOUND', fn () => $this->floorplan->snapshot(999, '2026-11-10'));
    }

    // -------------------------------------------------------------------- seat

    public function testSeatMovesTheBookingToSeatedAndOccupiesTheTable(): void
    {
        $booking = $this->book();
        $result = $this->floorplan->seat((string) $booking['booking']['reference']);

        self::assertSame('SEATED', $result['booking']['status']);
        self::assertSame('OCCUPIED', $result['table']['status']);

        $row = $this->tableSummary('T03');
        self::assertSame('OCCUPIED', $row['status']);

        $events = $this->pdo->query(
            'SELECT event_type, actor FROM booking_events ORDER BY id DESC LIMIT 1'
        )->fetch();
        self::assertSame('seated', $events['event_type']);
        self::assertSame('host', $events['actor']);
    }

    public function testSeatingTheSameBookingTwiceIsRejected(): void
    {
        $booking = $this->book();
        $this->floorplan->seat((string) $booking['booking']['reference']);

        $this->assertDomainCode('ALREADY_SEATED', fn () => $this->floorplan->seat((string) $booking['booking']['reference']));
    }

    public function testSeatingACancelledBookingIsRejected(): void
    {
        $booking = $this->book();
        $this->bookings->cancel([
            'reference' => (string) $booking['booking']['reference'],
            'email' => 'floor@example.com',
        ]);

        $this->assertDomainCode('BOOKING_NOT_SEATABLE', fn () => $this->floorplan->seat((string) $booking['booking']['reference']));
    }

    public function testSeatingAnUnknownReferenceIsNotFound(): void
    {
        $this->assertDomainCode('NOT_FOUND', fn () => $this->floorplan->seat('ZZZZZZZZ'));
    }

    // ---------------------------------------------------------------- walk-in

    public function testWalkInCreatesASeatedWalkInBooking(): void
    {
        $this->clock->advance('+2 hours');   // 12:00, inside lunch service

        $result = $this->floorplan->walkIn([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T01'),
            'party_size' => 2,
            'name' => 'Walk In',
        ]);

        self::assertSame('walk_in', $result['booking']['channel']);
        self::assertSame('SEATED', $result['booking']['status']);
        self::assertSame('OCCUPIED', $result['table']['status']);
        self::assertSame('2026-11-02', $result['booking']['date']);
    }

    public function testWalkInValidatesItsInput(): void
    {
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->floorplan->walkIn([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T01'),
            'party_size' => 2,
        ]));
    }

    public function testWalkInRejectsAPartyTooBigForTheTable(): void
    {
        $this->clock->advance('+2 hours');

        $this->assertDomainCode('PARTY_TOO_LARGE', fn () => $this->floorplan->walkIn([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T01'),
            'party_size' => 4,
            'name' => 'Too Big',
        ]));
    }

    // ---------------------------------------------------------------- no-show

    public function testNoShowMarksTheBookingAndSendsTheTableToCleaning(): void
    {
        $booking = $this->book();
        $result = $this->floorplan->noShow((string) $booking['booking']['reference']);

        self::assertSame('NO_SHOW', $result['booking']['status']);
        self::assertSame('CLEANING', $result['table']['status']);
        self::assertSame('CLEANING', $this->tableSummary('T03')['status']);
    }

    public function testNoShowRejectsASeatedBooking(): void
    {
        $booking = $this->book();
        $this->floorplan->seat((string) $booking['booking']['reference']);

        $this->assertDomainCode('BOOKING_NOT_NO_SHOW', fn () => $this->floorplan->noShow((string) $booking['booking']['reference']));
    }

    // ---------------------------------------------------------------- release

    public function testReleaseFreesTheTableAndCompletesTheSeatedBooking(): void
    {
        $booking = $this->book();
        $this->floorplan->seat((string) $booking['booking']['reference']);

        $result = $this->floorplan->release($this->tableIdByCode('T03'), '2026-11-10');

        self::assertSame('FREE', $result['table']['status']);
        self::assertSame('COMPLETED', $result['completed_booking']['status']);
        self::assertSame('FREE', $this->tableSummary('T03')['status']);

        $events = $this->pdo->query(
            'SELECT event_type FROM booking_events ORDER BY id DESC LIMIT 1'
        )->fetchColumn();
        self::assertSame('released', $events);
    }

    public function testReleasingABlockedTableIsRejected(): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM tables WHERE restaurant_id = ? AND status = ? LIMIT 1');
        $statement->execute([$this->restaurantId, 'BLOCKED']);
        $blocked = $statement->fetch();
        self::assertIsArray($blocked);

        $this->assertDomainCode('TABLE_NOT_RELEASABLE', fn () => $this->floorplan->release((int) $blocked['id']));
    }

    // ------------------------------------------------------------- availability

    public function testOccupiedTableIsNotOfferedByAvailability(): void
    {
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->floorplan->seat((string) $booking['booking']['reference']);

        $result = $this->availability()->search($this->restaurantId, '2026-11-10', '19:00', 4);
        foreach ($result['tables'] as $table) {
            self::assertNotSame('T03', $table['code']);
        }
    }

    public function testCleaningTableBecomesBookableAgainAfterRelease(): void
    {
        $booking = $this->book('T03', '2026-11-10', '19:00', 4);
        $this->floorplan->noShow((string) $booking['booking']['reference']);

        $during = $this->availability()->search($this->restaurantId, '2026-11-10', '19:00', 4);
        $codes = array_column($during['tables'], 'code');
        self::assertNotContains('T03', $codes);

        $this->floorplan->release($this->tableIdByCode('T03'), '2026-11-10');

        $after = $this->availability()->search($this->restaurantId, '2026-11-10', '19:00', 4);
        self::assertContains('T03', array_column($after['tables'], 'code'));
    }

    public function testEveryTransitionIsAudited(): void
    {
        $booking = $this->book();
        $this->floorplan->seat((string) $booking['booking']['reference']);
        $this->floorplan->release($this->tableIdByCode('T03'), '2026-11-10');

        $actions = $this->pdo->query('SELECT action FROM audit_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('floorplan.seat', $actions);
        self::assertContains('floorplan.release', $actions);
    }
}
