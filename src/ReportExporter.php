<?php

declare(strict_types=1);

namespace DineReserve;

/**
 * Export a report array to CSV, XLSX or PDF without any runtime package.
 *
 * The XLSX writer emits a minimal OOXML workbook with uncompressed ZIP entries;
 * the PDF writer emits a small PDF 1.4 text document with the built-in Helvetica
 * font. Both formats are written to a path and the path is returned.
 */
final class ReportExporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /**
     * @param array<string,mixed> $report
     * @return string the written path
     */
    public function export(array $report, string $format, string $path): string
    {
        $format = strtolower(trim($format));
        if (!in_array($format, self::FORMATS, true)) {
            throw new DomainException(
                'REPORT_FORMAT_UNSUPPORTED',
                'Supported export formats: csv, xlsx, pdf.',
                ['format' => $format],
                422
            );
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new DomainException('REPORT_EXPORT_FAILED', 'Cannot create the export directory.', [], 500);
        }

        $payload = match ($format) {
            'csv' => $this->toCsv($report),
            'xlsx' => $this->toXlsx($report),
            'pdf' => $this->toPdf($report),
        };

        if (file_put_contents($path, $payload) === false) {
            throw new DomainException('REPORT_EXPORT_FAILED', 'Cannot write the export file.', [], 500);
        }

        return $path;
    }

    /**
     * Flatten a report into a table: title block, header row, data rows, totals.
     *
     * @param array<string,mixed> $report
     * @return array{title:string,meta:list<array{0:string,1:string}>,header:list<string>,rows:list<list<string|float>>,totals:?list<string|float>}
     */
    public function toTable(array $report): array
    {
        $kind = (string) ($report['kind'] ?? 'occupancy');
        $restaurant = (string) ($report['restaurant']['name'] ?? 'Restaurant');
        $from = (string) ($report['from'] ?? '');
        $to = (string) ($report['to'] ?? '');

        if ($kind === 'risk') {
            $distribution = (array) ($report['distribution'] ?? []);
            $rows = [];
            foreach ((array) ($report['flagged'] ?? []) as $booking) {
                $rows[] = [
                    (string) ($booking['reference'] ?? ''),
                    (string) ($booking['date'] ?? ''),
                    (string) ($booking['time'] ?? ''),
                    (int) ($booking['party_size'] ?? 0),
                    (string) ($booking['channel'] ?? ''),
                    (int) ($booking['score'] ?? 0),
                    (string) ($booking['rule_version'] ?? ''),
                ];
            }

            return [
                'title' => 'DineReserve — no-show risk report',
                'meta' => [
                    ['Restaurant', $restaurant],
                    ['Period', $from . ' to ' . $to],
                    ['Scored bookings', (string) (int) ($report['scored_bookings'] ?? 0)],
                    ['Average score', (string) ($report['average_score'] ?? 0)],
                    ['HIGH / MEDIUM / LOW', ($distribution['HIGH'] ?? 0) . ' / ' . ($distribution['MEDIUM'] ?? 0) . ' / ' . ($distribution['LOW'] ?? 0)],
                ],
                'header' => ['Reference', 'Date', 'Time', 'Party', 'Channel', 'Score', 'Rule version'],
                'rows' => $rows,
                'totals' => null,
            ];
        }

        $rows = [];
        foreach ((array) ($report['days'] ?? []) as $day) {
            $channels = (array) ($day['channels'] ?? []);
            $rows[] = [
                (string) ($day['date'] ?? ''),
                (int) ($day['covers'] ?? 0),
                (int) ($day['capacity'] ?? 0),
                (float) ($day['utilisation_pct'] ?? 0),
                (int) ($day['bookings'] ?? 0),
                (int) ($day['no_shows'] ?? 0),
                (int) ($day['cancellations'] ?? 0),
                (float) ($day['no_show_rate_pct'] ?? 0),
                (int) ($channels['online'] ?? 0),
                (int) ($channels['phone'] ?? 0),
                (int) ($channels['walk_in'] ?? 0),
                (int) ($channels['waitlist'] ?? 0),
            ];
        }
        $totals = (array) ($report['totals'] ?? []);
        $totalsChannels = (array) ($totals['channels'] ?? []);

        return [
            'title' => 'DineReserve — occupancy and utilisation report',
            'meta' => [
                ['Restaurant', $restaurant],
                ['Period', $from . ' to ' . $to],
                ['Capacity per day', (string) (int) ($report['capacity_per_day'] ?? 0) . ' covers'],
                ['Generated at', (string) ($report['generated_at'] ?? '')],
            ],
            'header' => [
                'Date', 'Covers', 'Capacity', 'Utilisation %', 'Bookings',
                'No-shows', 'Cancellations', 'No-show rate %',
                'Online', 'Phone', 'Walk-in', 'Waitlist',
            ],
            'rows' => $rows,
            'totals' => [
                'TOTAL',
                (int) ($totals['covers'] ?? 0),
                (int) ($totals['capacity'] ?? 0),
                (float) ($totals['utilisation_pct'] ?? 0),
                (int) ($totals['bookings'] ?? 0),
                (int) ($totals['no_shows'] ?? 0),
                (int) ($totals['cancellations'] ?? 0),
                (float) ($totals['no_show_rate_pct'] ?? 0),
                (int) ($totalsChannels['online'] ?? 0),
                (int) ($totalsChannels['phone'] ?? 0),
                (int) ($totalsChannels['walk_in'] ?? 0),
                (int) ($totalsChannels['waitlist'] ?? 0),
            ],
        ];
    }

    // ------------------------------------------------------------------ csv

    /** @param array<string,mixed> $report */
    private function toCsv(array $report): string
    {
        $table = $this->toTable($report);
        $lines = [];
        $lines[] = $this->csvRow([$table['title']]);
        foreach ($table['meta'] as $pair) {
            $lines[] = $this->csvRow($pair);
        }
        $lines[] = '';
        $lines[] = $this->csvRow($table['header']);
        foreach ($table['rows'] as $row) {
            $lines[] = $this->csvRow($row);
        }
        if ($table['totals'] !== null) {
            $lines[] = $this->csvRow($table['totals']);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param list<string|int|float> $row */
    private function csvRow(array $row): string
    {
        return implode(',', array_map(static function ($value): string {
            $text = (string) $value;
            if (strpbrk($text, ",\"\r\n") !== false) {
                return '"' . str_replace('"', '""', $text) . '"';
            }

            return $text;
        }, $row));
    }

    // ----------------------------------------------------------------- xlsx

    /** @param array<string,mixed> $report */
    private function toXlsx(array $report): string
    {
        $table = $this->toTable($report);
        $rows = [];

        $rows[] = [$table['title']];
        foreach ($table['meta'] as $pair) {
            $rows[] = [$pair[0], $pair[1]];
        }
        $rows[] = [];
        $rows[] = $table['header'];
        foreach ($table['rows'] as $row) {
            $rows[] = $row;
        }
        if ($table['totals'] !== null) {
            $rows[] = $table['totals'];
        }

        $sheetData = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach ($row as $columnIndex => $value) {
                $reference = $this->columnName($columnIndex) . ($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    $cells .= '<c r="' . $reference . '"><v>' . $value . '</v></c>';
                } else {
                    $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $cells .= '<c r="' . $reference . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
                }
            }
            $sheetData .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetData . '</sheetData></worksheet>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        return $this->zip([
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rootRels,
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => $workbookRels,
            'xl/worksheets/sheet1.xml' => $worksheet,
        ]);
    }

    /**
     * Minimal ZIP writer (stored entries, no compression) so XLSX export works
     * without the zip extension or any package.
     *
     * @param array<string,string> $files
     */
    private function zip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;

        foreach ($files as $name => $data) {
            $nameLength = strlen($name);
            $size = strlen($data);
            $crc = crc32($data);
            $time = 0;
            $date = 0;

            $entry = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                $time,
                $date,
                $crc,
                $size,
                $size,
                $nameLength,
                0
            ) . $name . $data;
            $local .= $entry;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                $time,
                $date,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $name;

            $offset += strlen($entry);
            $count++;
        }

        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            strlen($local),
            0
        );

        return $local . $central . $end;
    }

    private function columnName(int $index): string
    {
        $name = '';
        $index++;
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $name = chr(65 + $remainder) . $name;
            $index = intdiv($index - 1, 26);
        }

        return $name;
    }

    // ------------------------------------------------------------------ pdf

    /** @param array<string,mixed> $report */
    private function toPdf(array $report): string
    {
        $table = $this->toTable($report);
        $lines = [$table['title'], ''];
        foreach ($table['meta'] as $pair) {
            $lines[] = $pair[0] . ': ' . $pair[1];
        }
        $lines[] = '';
        $lines[] = implode(' | ', $table['header']);
        $lines[] = str_repeat('-', 95);
        foreach ($table['rows'] as $row) {
            $lines[] = implode(' | ', array_map(static fn ($value): string => (string) $value, $row));
        }
        if ($table['totals'] !== null) {
            $lines[] = str_repeat('-', 95);
            $lines[] = implode(' | ', array_map(static fn ($value): string => (string) $value, $table['totals']));
        }

        $perPage = 48;
        $pages = array_chunk($lines, $perPage);
        if ($pages === []) {
            $pages = [[]];
        }

        $objects = [];
        // 1: catalog, 2: pages
        $pageIds = [];
        $nextId = 3;
        foreach ($pages as $_) {
            $pageIds[] = $nextId;
            $nextId += 2;
        }
        $contentsIds = [];
        foreach ($pageIds as $pageId) {
            $contentsIds[] = $pageId + 1;
        }
        $fontId = $nextId;

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds));
        $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';

        foreach ($pages as $index => $pageLines) {
            $pageId = $pageIds[$index];
            $contentId = $contentsIds[$index];
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]'
                . ' /Resources << /Font << /F1 ' . $fontId . ' 0 R >> >> /Contents ' . $contentId . ' 0 R >>';

            $content = "BT\n/F1 9 Tf\n40 800 Td\n12 TL\n";
            foreach ($pageLines as $line) {
                $content .= '(' . $this->pdfEscape($line) . ") Tj\nT*\n";
            }
            $content .= "ET\n";
            $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= 'xref' . "\n0 " . ($maxId + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= isset($offsets[$id])
                ? sprintf("%010d 00000 n \n", $offsets[$id])
                : "0000000000 65535 f \n";
        }
        $pdf .= 'trailer << /Size ' . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    private function pdfEscape(string $text): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        $ascii = str_replace('\\', '\\\\', $ascii);
        $ascii = str_replace('(', '\\(', $ascii);
        $ascii = str_replace(')', '\\)', $ascii);

        return $ascii;
    }
}
