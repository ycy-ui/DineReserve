<?php

declare(strict_types=1);

namespace DineReserve\Tests;

use DineReserve\AvailabilityService;
use DineReserve\BookingService;
use DineReserve\DomainException;
use DineReserve\ReportExporter;
use DineReserve\ReportService;
use DineReserve\RiskScorer;

/**
 * URS REQ-1.7 — occupancy, utilisation, no-show and channel reporting (W11),
 * including the CSV / XLSX / PDF exports.
 */
final class ReportingTest extends TestCase
{
    private BookingService $bookings;

    private ReportService $reports;

    private ReportExporter $exporter;

    private array $references = [];

    protected function setUp(): void
    {
        parent::setUp();

        $availability = new AvailabilityService($this->pdo, $this->clock);
        $this->bookings = new BookingService($this->pdo, $availability, $this->clock);
        $this->reports = new ReportService($this->pdo, $this->clock);
        $this->exporter = new ReportExporter();
        $this->bookings->setRiskScorer(new RiskScorer($this->pdo, $this->clock));

        // 3 Nov: two served bookings (2 + 4 covers).
        $this->references['a'] = $this->book('T03', '2026-11-03', '19:00', 2, 'a@example.com');
        $this->references['b'] = $this->book('T07', '2026-11-03', '19:00', 4, 'b@example.com');
        // 4 Nov: one cancelled, one no-show.
        $this->references['c'] = $this->book('T03', '2026-11-04', '19:00', 2, 'c@example.com');
        $this->bookings->cancel(['reference' => $this->references['c'], 'email' => 'c@example.com']);
        $this->references['d'] = $this->book('T07', '2026-11-04', '19:00', 2, 'd@example.com');
        $this->pdo->exec("UPDATE bookings SET status = 'NO_SHOW' WHERE reference = '" . $this->references['d'] . "'");
        // 5 Nov: a walk-in.
        $this->references['e'] = $this->book('T03', '2026-11-05', '19:00', 2, 'e@example.com', 'walk_in');
    }

    private function book(string $tableCode, string $date, string $time, int $party, string $email, string $channel = 'online'): string
    {
        $result = $this->bookings->create([
            'restaurant_id' => $this->restaurantId,
            'table_id' => $this->tableIdByCode($tableCode),
            'date' => $date,
            'time' => $time,
            'party_size' => $party,
            'name' => 'Report Guest',
            'email' => $email,
            'channel' => $channel,
        ]);

        return (string) $result['booking']['reference'];
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

    // ------------------------------------------------------------- occupancy

    public function testOccupancyCountsCoversAndCapacityPerDay(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');

        self::assertSame('occupancy', $report['kind']);
        self::assertGreaterThan(0, $report['capacity_per_day']);
        self::assertCount(3, $report['days']);

        $first = $report['days'][0];
        self::assertSame('2026-11-03', $first['date']);
        self::assertSame(6, $first['covers']);
        self::assertSame(2, $first['bookings']);
        self::assertSame(0, $first['no_shows']);
        self::assertSame($report['capacity_per_day'], $first['capacity']);
        self::assertSame(round(6 / $report['capacity_per_day'] * 100, 1), $first['utilisation_pct']);
    }

    public function testCancelledAndNoShowBookingsAreNotCountedAsCovers(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-04', '2026-11-04');
        $day = $report['days'][0];

        self::assertSame(0, $day['covers']);
        self::assertSame(2, $day['bookings']);
        self::assertSame(1, $day['cancellations']);
        self::assertSame(1, $day['no_shows']);
        self::assertSame(100.0, $day['no_show_rate_pct']);
    }

    public function testTotalsAggregateTheWholeRange(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');
        $totals = $report['totals'];

        self::assertSame(8, $totals['covers']);
        self::assertSame(5, $totals['bookings']);
        self::assertSame(1, $totals['no_shows']);
        self::assertSame(1, $totals['cancellations']);
        self::assertSame(25.0, $totals['no_show_rate_pct']);
    }

    public function testChannelMixIsReported(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');
        $channels = $report['totals']['channels'];

        self::assertSame(4, $channels['online']);
        self::assertSame(1, $channels['walk_in']);
        self::assertSame(0, $channels['phone']);
        self::assertSame(0, $channels['waitlist']);
    }

    public function testEmptyRangeReturnsZeroTotals(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-12-01', '2026-12-03');

        self::assertCount(3, $report['days']);
        self::assertSame(0, $report['totals']['covers']);
        self::assertSame(0.0, $report['totals']['utilisation_pct']);
        self::assertSame(0.0, $report['totals']['no_show_rate_pct']);
    }

    public function testRangeValidation(): void
    {
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->reports->occupancy($this->restaurantId, '2026-11-05', '2026-11-01'));
        $this->assertDomainCode('VALIDATION_ERROR', fn () => $this->reports->occupancy($this->restaurantId, '2026-13-01', '2026-11-01'));
        $this->assertDomainCode('RANGE_TOO_LARGE', fn () => $this->reports->occupancy($this->restaurantId, '2026-01-01', '2026-12-31'));
        $this->assertDomainCode('NOT_FOUND', fn () => $this->reports->occupancy(999, '2026-11-01', '2026-11-02'));
    }

    // ------------------------------------------------------------------ risk

    public function testRiskReportSummarisesScores(): void
    {
        $scorer = new RiskScorer($this->pdo, $this->clock);
        $scorer->rescoreRange('2026-11-03', '2026-11-05', $this->restaurantId);

        $report = $this->reports->risk($this->restaurantId, '2026-11-03', '2026-11-05');

        self::assertSame('risk', $report['kind']);
        self::assertSame(5, $report['scored_bookings']);
        self::assertSame(['risk-v1'], $report['rule_versions']);
        self::assertSame(5, array_sum($report['distribution']));
        self::assertGreaterThan(0, $report['average_score']);
    }

    public function testRiskReportFlagsHighRiskBookings(): void
    {
        $this->pdo->exec("UPDATE bookings SET risk_score = 95, risk_rule_version = 'risk-v1' WHERE reference = '" . $this->references['a'] . "'");
        $this->pdo->exec("UPDATE bookings SET risk_score = 50, risk_rule_version = 'risk-v1' WHERE reference = '" . $this->references['b'] . "'");

        $report = $this->reports->risk($this->restaurantId, '2026-11-03', '2026-11-05');

        self::assertSame(1, $report['distribution']['HIGH']);
        self::assertSame(1, $report['distribution']['MEDIUM']);
        self::assertCount(1, $report['flagged']);
        self::assertSame($this->references['a'], $report['flagged'][0]['reference']);
        self::assertSame(95, $report['flagged'][0]['score']);
    }

    // ---------------------------------------------------------------- export

    public function testCsvExportContainsHeaderAndRows(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');
        $path = tempnam(sys_get_temp_dir(), 'dr-csv-') . '.csv';
        $this->exporter->export($report, 'csv', $path);

        $content = (string) file_get_contents($path);
        self::assertStringContainsString('occupancy and utilisation report', $content);
        self::assertStringContainsString('2026-11-03', $content);
        self::assertStringContainsString('Utilisation %', $content);
        self::assertStringContainsString('TOTAL', $content);
        unlink($path);
    }

    public function testXlsxExportIsAZipWorkbook(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');
        $path = tempnam(sys_get_temp_dir(), 'dr-xlsx-') . '.xlsx';
        $this->exporter->export($report, 'xlsx', $path);

        $content = (string) file_get_contents($path);
        self::assertStringStartsWith("PK\x03\x04", $content);
        self::assertStringContainsString('[Content_Types].xml', $content);
        self::assertStringContainsString('xl/worksheets/sheet1.xml', $content);
        self::assertStringContainsString('DineReserve', $content);
        self::assertStringContainsString('2026-11-03', $content);
        unlink($path);
    }

    public function testPdfExportStartsWithThePdfSignature(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');
        $path = tempnam(sys_get_temp_dir(), 'dr-pdf-') . '.pdf';
        $this->exporter->export($report, 'pdf', $path);

        $content = (string) file_get_contents($path);
        self::assertStringStartsWith('%PDF-1.4', $content);
        self::assertStringContainsString('DineReserve', $content);
        self::assertStringContainsString('%%EOF', $content);
        unlink($path);
    }

    public function testRiskReportExportsAsXlsx(): void
    {
        $report = $this->reports->risk($this->restaurantId, '2026-11-03', '2026-11-05');
        $path = tempnam(sys_get_temp_dir(), 'dr-risk-') . '.xlsx';
        $this->exporter->export($report, 'xlsx', $path);

        $content = (string) file_get_contents($path);
        self::assertStringStartsWith("PK\x03\x04", $content);
        self::assertStringContainsString('no-show risk report', $content);
        unlink($path);
    }

    public function testUnsupportedFormatIsRejected(): void
    {
        $report = $this->reports->occupancy($this->restaurantId, '2026-11-03', '2026-11-05');
        $path = tempnam(sys_get_temp_dir(), 'dr-bad-') . '.txt';

        $this->assertDomainCode('REPORT_FORMAT_UNSUPPORTED', fn () => $this->exporter->export($report, 'docx', $path));
        unlink($path);
    }
}
