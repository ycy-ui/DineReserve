<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\DomainException;
use DineReserve\RiskScorer;

/**
 * URS REQ-1.8 — explainable no-show risk score (W11).
 */
final class RiskScorerTest extends TestCase
{
    private BookingService $bookings;

    private RiskScorer $scorer;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();

        $this->availability = new AvailabilityService($this->pdo, $this->clock);
        $this->bookings = new BookingService($this->pdo, $this->availability, $this->clock);
        $this->scorer = new RiskScorer($this->pdo, $this->clock);
        $this->bookings->setRiskScorer($this->scorer);
    }

    /** @param array<string,mixed> $overrides */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'restaurant_id' => $this->restaurantId,
            'date' => '2026-11-20',
            'time' => '19:00',
            'party_size' => 2,
            'channel' => 'online',
            'email' => 'risk@example.com',
            'special_requests' => null,
        ], $overrides);
    }

    private function reasonCodes(array $score): array
    {
        return array_column($score['reasons'], 'code');
    }

    public function testScoresAStandardBookingWithAnExplainableBreakdown(): void
    {
        $score = $this->scorer->score($this->context());

        self::assertGreaterThan(0, $score['score']);
        self::assertLessThanOrEqual(100, $score['score']);
        self::assertContains($score['band'], ['LOW', 'MEDIUM', 'HIGH']);
        self::assertSame(RiskScorer::RULE_VERSION, $score['rule_version']);
        self::assertNotEmpty($score['reasons']);
        self::assertSame('baseline', $score['reasons'][0]['code']);
        foreach ($score['reasons'] as $reason) {
            self::assertArrayHasKey('code', $reason);
            self::assertArrayHasKey('points', $reason);
            self::assertArrayHasKey('label', $reason);
        }
        self::assertSame(array_sum(array_column($score['reasons'], 'points')), $score['score']);
    }

    public function testShortLeadTimeRaisesTheScore(): void
    {
        // Same weekday class (Monday/Tuesday), only the lead time differs.
        $far = $this->scorer->score($this->context(['date' => '2026-11-24']));
        $tomorrow = $this->scorer->score($this->context(['date' => '2026-11-02']));

        self::assertGreaterThan($far['score'], $tomorrow['score']);
        self::assertContains('short_lead', $this->reasonCodes($tomorrow));
    }

    public function testLargePartiesScoreHigher(): void
    {
        $small = $this->scorer->score($this->context(['party_size' => 2]));
        $group = $this->scorer->score($this->context(['party_size' => 6]));
        $large = $this->scorer->score($this->context(['party_size' => 8]));

        self::assertGreaterThan($small['score'], $group['score']);
        self::assertGreaterThan($group['score'], $large['score']);
        self::assertContains('large_party', $this->reasonCodes($large));
    }

    public function testWeekendSittingsScoreHigherThanMidweek(): void
    {
        $friday = (new \DateTimeImmutable('2026-11-02'))->modify('next friday')->format('Y-m-d');
        $tuesday = (new \DateTimeImmutable('2026-11-02'))->modify('next tuesday')->format('Y-m-d');

        $weekend = $this->scorer->score($this->context(['date' => $friday]));
        $midweek = $this->scorer->score($this->context(['date' => $tuesday]));

        self::assertGreaterThan($midweek['score'], $weekend['score']);
        self::assertContains('peak_weekday', $this->reasonCodes($weekend));
    }

    public function testLateSittingsScoreHigher(): void
    {
        $early = $this->scorer->score($this->context(['time' => '18:00']));
        $late = $this->scorer->score($this->context(['time' => '20:30']));

        self::assertGreaterThan($early['score'], $late['score']);
        self::assertContains('late_sitting', $this->reasonCodes($late));
    }

    public function testChannelChangesTheScore(): void
    {
        $online = $this->scorer->score($this->context(['channel' => 'online']));
        $phone = $this->scorer->score($this->context(['channel' => 'phone']));
        $walkIn = $this->scorer->score($this->context(['channel' => 'walk_in']));

        self::assertGreaterThan($phone['score'], $online['score']);
        self::assertGreaterThan($walkIn['score'], $phone['score']);
        self::assertContains('channel_walk_in', $this->reasonCodes($walkIn));
    }

    public function testPastNoShowsRaiseTheScore(): void
    {
        $booking = $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T03'),
            'date' => '2026-11-05',
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'No Show',
            'email' => 'risk@example.com',
        ]);
        $this->pdo->exec("UPDATE bookings SET status = 'NO_SHOW' WHERE id = " . (int) $booking['booking']['id']);

        $score = $this->scorer->score($this->context());

        self::assertContains('past_no_shows', $this->reasonCodes($score));
        self::assertSame(15, $score['reasons'][array_search('past_no_shows', $this->reasonCodes($score), true)]['points']);
    }

    public function testSpecialRequestsLowerTheScore(): void
    {
        $plain = $this->scorer->score($this->context());
        $withRequest = $this->scorer->score($this->context(['special_requests' => 'Window table for a birthday']));

        self::assertLessThan($plain['score'], $withRequest['score']);
        self::assertContains('special_request', $this->reasonCodes($withRequest));
    }

    public function testScoreIsClampedAndBanded(): void
    {
        $score = $this->scorer->score($this->context([
            'date' => '2026-11-03',
            'time' => '20:30',
            'party_size' => 8,
        ]));

        self::assertLessThanOrEqual(100, $score['score']);
        self::assertGreaterThanOrEqual(0, $score['score']);
        self::assertSame(
            $score['score'] >= RiskScorer::HIGH_THRESHOLD ? 'HIGH' : ($score['score'] >= RiskScorer::MEDIUM_THRESHOLD ? 'MEDIUM' : 'LOW'),
            $score['band']
        );
    }

    public function testApplyToBookingStoresTheScoreAndRuleVersion(): void
    {
        $booking = $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T03'),
            'date' => '2026-11-20',
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Stored Score',
            'email' => 'stored@example.com',
        ]);

        $score = $this->scorer->applyToBooking((int) $booking['booking']['id']);

        $row = $this->pdo->query(
            'SELECT risk_score, risk_rule_version FROM bookings WHERE id = ' . (int) $booking['booking']['id']
        )->fetch();
        self::assertSame($score['score'], (int) $row['risk_score']);
        self::assertSame(RiskScorer::RULE_VERSION, $row['risk_rule_version']);
        self::assertSame(RiskScorer::RULE_VERSION, $score['rule_version']);
        self::assertContains($score['band'], ['LOW', 'MEDIUM', 'HIGH']);

        $audit = $this->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'booking.risk_scored'")->fetchColumn();
        self::assertGreaterThanOrEqual(1, (int) $audit);
    }

    public function testNewBookingsAreScoredOnCreation(): void
    {
        $booking = $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T03'),
            'date' => '2026-11-20',
            'time' => '19:00',
            'party_size' => 4,
            'name' => 'Auto Score',
            'email' => 'auto@example.com',
        ]);

        $row = $this->pdo->query(
            'SELECT risk_score, risk_rule_version FROM bookings WHERE id = ' . (int) $booking['booking']['id']
        )->fetch();

        self::assertNotNull($row['risk_score']);
        self::assertGreaterThanOrEqual(0, (int) $row['risk_score']);
        self::assertSame(RiskScorer::RULE_VERSION, $row['risk_rule_version']);
    }

    public function testRescoreRangeUpdatesEveryBooking(): void
    {
        $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T03'),
            'date' => '2026-11-20',
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'One',
            'email' => 'one@example.com',
        ]);
        $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode('T07'),
            'date' => '2026-11-21',
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Two',
            'email' => 'two@example.com',
        ]);

        $count = $this->scorer->rescoreRange('2026-11-20', '2026-11-21', $this->restaurantId);

        self::assertSame(2, $count);
    }

    public function testScoringAnUnknownBookingIsRejected(): void
    {
        $this->assertDomainCode('NOT_FOUND', fn () => $this->scorer->applyToBooking(999999));
    }

    private function assertDomainCode(string $code, callable $call): void
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame($code, $exception->errorCode());

            return;
        }

        self::fail('expected DomainException with code ' . $code);
    }
}
