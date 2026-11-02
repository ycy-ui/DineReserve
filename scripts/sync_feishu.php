<?php

declare(strict_types=1);

/**
 * Sync docs/progress.json into a Feishu (Lark) Bitable.
 *
 * The Bitable is the team-facing view; docs/progress.json is the source of
 * truth in the repository. Run by .github/workflows/feishu-sync.yml after a
 * push to main, or manually:
 *
 *   FEISHU_APP_ID=cli_xxx \
 *   FEISHU_APP_SECRET=xxx \
 *   FEISHU_BITABLE_APP_TOKEN=bascnxxxx \
 *   FEISHU_BITABLE_TABLE_ID=tblxxxx \
 *   php scripts/sync_feishu.php --ensure-schema
 *
 * Flags:
 *   --dry-run        print the planned API calls, send nothing
 *   --ensure-schema  create the table fields if they are missing
 *
 * Exit codes: 0 success / 1 failure / 2 configuration problem.
 */

const FEISHU_BASE = 'https://open.feishu.cn/open-apis';

/** Field name => [Feishu field type, extra property]. Type codes: 1 text, 2 number, 3 single select, 5 date. */
const FIELD_SCHEMA = [
    '周次' => [1, null],
    '阶段' => [1, null],
    '任务' => [1, null],
    '对应需求' => [1, null],
    '负责人' => [1, null],
    '状态' => [3, ['options' => [
        ['name' => '未开始'], ['name' => '进行中'], ['name' => '已完成'], ['name' => '阻塞'],
    ]]],
    '计划开始' => [5, null],
    '计划结束' => [5, null],
    '实际完成' => [5, null],
    '进度' => [2, null],
    '交付物' => [1, null],
    'GitHub 链接' => [1, null],
    '备注' => [1, null],
];

$options = getopt('', ['dry-run', 'ensure-schema', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/sync_feishu.php [--dry-run] [--ensure-schema]\n");
    exit(0);
}
$dryRun = isset($options['dry-run']);
$ensureSchema = isset($options['ensure-schema']);

$root = dirname(__DIR__);
$progressFile = $root . '/docs/progress.json';

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit($code);
}

function note(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

if (!is_file($progressFile)) {
    fail("progress file not found: {$progressFile}", 2);
}

$progress = json_decode((string) file_get_contents($progressFile), true);
if (!is_array($progress) || !isset($progress['rows']) || !is_array($progress['rows'])) {
    fail('docs/progress.json is not valid or has no "rows" array');
}

$env = static function (string $name): string {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        fail("missing required environment variable {$name}", 2);
    }

    return trim($value);
};

/**
 * @param array<string,mixed>|null $body
 * @return array<string,mixed>
 */
function feishu(string $method, string $path, ?array $body, string $token, bool $dryRun, array $query = []): array
{
    $url = FEISHU_BASE . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    if ($dryRun) {
        note(sprintf('[dry-run] %s %s%s', $method, $path, $body === null ? '' : ' ' . json_encode($body, JSON_UNESCAPED_UNICODE)));
        return ['code' => 0, 'data' => []];
    }

    $handle = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'];
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($raw === false) {
        fail("network error calling {$path}: {$error}");
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        fail("non-JSON response from {$path} (HTTP {$status}): " . substr((string) $raw, 0, 300));
    }
    if (($decoded['code'] ?? -1) !== 0) {
        fail(sprintf('Feishu API error on %s (HTTP %d): code=%s msg=%s', $path, $status, $decoded['code'] ?? '?', $decoded['msg'] ?? '?'));
    }

    return $decoded;
}

function tenantToken(string $appId, string $appSecret, bool $dryRun): string
{
    if ($dryRun) {
        note('[dry-run] POST /auth/v3/tenant_access_token/internal');
        return 'dry-run-token';
    }
    $handle = curl_init(FEISHU_BASE . '/auth/v3/tenant_access_token/internal');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'],
        CURLOPT_POSTFIELDS => json_encode(['app_id' => $appId, 'app_secret' => $appSecret]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($handle);
    curl_close($handle);
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded) || ($decoded['code'] ?? -1) !== 0 || empty($decoded['tenant_access_token'])) {
        fail('unable to obtain tenant_access_token: ' . substr((string) $raw, 0, 300));
    }

    return (string) $decoded['tenant_access_token'];
}

/**
 * Normalise a Feishu field value to a scalar for comparison/printing.
 * Text fields come back as [{type:text,text:...}], selects as strings, dates as ms.
 */
function scalarValue(mixed $value): mixed
{
    if (is_array($value)) {
        if (isset($value['text'])) {
            return (string) $value['text'];
        }
        if (array_is_list($value)) {
            $parts = [];
            foreach ($value as $item) {
                if (is_array($item) && isset($item['text'])) {
                    $parts[] = (string) $item['text'];
                } elseif (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            }
            if ($parts !== []) {
                return implode('', $parts);
            }
        }
    }

    return $value;
}

function dateToMillis(?string $date): ?int
{
    if ($date === null || $date === '') {
        return null;
    }
    $moment = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
    if ($moment === false) {
        fail("invalid date in progress.json: {$date}");
    }

    return $moment->getTimestamp() * 1000;
}

/** Build the Bitable fields payload for one progress row, coerced by field type. */
function buildFields(array $row, array $types): array
{
    $map = [
        '周次' => $row['week'] ?? '',
        '阶段' => $row['phase'] ?? '',
        '任务' => $row['task'] ?? '',
        '对应需求' => $row['requirement'] ?? '',
        '负责人' => $row['owner'] ?? '',
        '状态' => $row['status'] ?? '',
        '计划开始' => dateToMillis($row['planned_start'] ?? null),
        '计划结束' => dateToMillis($row['planned_end'] ?? null),
        '实际完成' => dateToMillis($row['actual_done'] ?? null),
        '进度' => isset($row['progress']) ? (int) round(((float) $row['progress']) * 100) : 0,
        '交付物' => $row['artifact'] ?? '',
        'GitHub 链接' => $row['link'] ?? '',
        '备注' => $row['note'] ?? '',
    ];

    $payload = [];
    foreach ($map as $name => $value) {
        $type = $types[$name] ?? 1;
        if ($value === null || $value === '') {
            continue;
        }
        // Date fields take epoch milliseconds, numbers take ints, text/select take strings.
        $payload[$name] = match ($type) {
            5 => (int) $value,
            2 => (int) $value,
            default => (string) $value,
        };
    }

    return $payload;
}

$appId = $env('FEISHU_APP_ID');
$appSecret = $env('FEISHU_APP_SECRET');
$appToken = $env('FEISHU_BITABLE_APP_TOKEN');
$tableId = $env('FEISHU_BITABLE_TABLE_ID');

note(sprintf('Feishu sync: %d progress rows, app %s, table %s%s', count($progress['rows']), $appId, $tableId, $dryRun ? ' (dry-run)' : ''));
$token = tenantToken($appId, $appSecret, $dryRun);
$basePath = "/bitable/v1/apps/{$appToken}/tables/{$tableId}";

// 1. Ensure the schema (optional) and learn the field types.
$fields = [];
if (!$dryRun) {
    $response = feishu('GET', $basePath . '/fields', null, $token, false, ['page_size' => 200]);
    foreach ($response['data']['items'] ?? [] as $field) {
        $fields[$field['field_name']] = (int) ($field['type'] ?? 1);
    }
}

$missing = array_diff(array_keys(FIELD_SCHEMA), array_keys($fields));
if ($missing !== [] && !$ensureSchema && !$dryRun) {
    fail('missing fields in the Bitable: ' . implode(', ', $missing) . ' — rerun with --ensure-schema');
}

if ($ensureSchema) {
    foreach (FIELD_SCHEMA as $name => [$type, $property]) {
        if ($dryRun) {
            if (!isset($fields[$name])) {
                note("[dry-run] POST {$basePath}/fields ({$name}, type {$type})");
            }
            continue;
        }
        if (isset($fields[$name])) {
            continue;
        }
        $body = ['field_name' => $name, 'type' => $type];
        if ($property !== null) {
            $body['property'] = $property;
        }
        feishu('POST', $basePath . '/fields', $body, $token, false);
        $fields[$name] = $type;
        note("created field: {$name} (type {$type})");
    }
    if ($dryRun) {
        // Give the dry-run a usable type map so the payload preview is meaningful.
        foreach (FIELD_SCHEMA as $name => [$type]) {
            $fields[$name] ??= $type;
        }
    }
}

// 2. Index the existing records by 周次.
$existing = [];
if (!$dryRun) {
    $pageToken = '';
    do {
        $query = ['page_size' => 500];
        if ($pageToken !== '') {
            $query['page_token'] = $pageToken;
        }
        $response = feishu('GET', $basePath . '/records', null, $token, false, $query);
        foreach ($response['data']['items'] ?? [] as $record) {
            $week = scalarValue($record['fields']['周次'] ?? null);
            if (is_string($week) && $week !== '') {
                $existing[$week] = $record['record_id'];
            }
        }
        $pageToken = (string) ($response['data']['page_token'] ?? '');
        $hasMore = (bool) ($response['data']['has_more'] ?? false);
    } while ($hasMore && $pageToken !== '');
}

// 3. Diff and push.
$toCreate = [];
$toUpdate = [];
foreach ($progress['rows'] as $row) {
    $week = (string) ($row['week'] ?? '');
    if ($week === '') {
        continue;
    }
    $payload = ['fields' => buildFields($row, $fields)];
    if (isset($existing[$week])) {
        $payload['record_id'] = $existing[$week];
        $toUpdate[] = $payload;
    } else {
        $toCreate[] = $payload;
    }
}

note(sprintf('records: %d to create, %d to update, %d already matching', count($toCreate), count($toUpdate), count($existing) - count($toUpdate)));

foreach (array_chunk($toCreate, 100) as $chunk) {
    if (!$dryRun) {
        feishu('POST', $basePath . '/records/batch_create', ['records' => $chunk], $token, false);
    } else {
        note('[dry-run] batch_create ' . count($chunk) . ' records');
        foreach ($chunk as $record) {
            note('  + ' . json_encode($record['fields'], JSON_UNESCAPED_UNICODE));
        }
    }
}

foreach (array_chunk($toUpdate, 100) as $chunk) {
    if (!$dryRun) {
        feishu('POST', $basePath . '/records/batch_update', ['records' => $chunk], $token, false);
    } else {
        note('[dry-run] batch_update ' . count($chunk) . ' records');
        foreach ($chunk as $record) {
            note('  ~ ' . $record['record_id'] . ' ' . json_encode($record['fields'], JSON_UNESCAPED_UNICODE));
        }
    }
}

note($dryRun ? 'dry-run complete: nothing was written' : 'sync complete');
exit(0);
