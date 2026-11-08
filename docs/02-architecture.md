# DineReserve — Architecture & Interface Contract (W1–W6)

> This document is the contract between the parallel work streams. Code that
> does not match it will be rejected in review. Requirements are referenced by
> their URS identifiers (REQ-1.x, PERF-1.x, SEC-1.x).

## 1. Layering

```
browser (demo, GitHub Pages)      HTTP JSON API (public/index.php)     engine (src/)
        │                                    │                            │
        │  static, mirrors the rules         │  thin: parse → service     │  domain rules
        └────────────────────────────────────┴────────────────────────────┘
                                                                          │
                                                              PDO (SQLite / MySQL 8)
```

- `src/` is framework agnostic: plain PHP 8.1+, PDO only, no TastyIgniter or Laravel dependency.
  The TastyIgniter extension in `extensions/dinereserve/booking/` is a thin adapter that will
  call these classes in W7+ (the URS requires the deployment to stay on the TastyIgniter base).
- `public/index.php` is a router/controller layer only.
- The browser demo has **no build step** and no external CDN: it must work from `file://` and
  from GitHub Pages, therefore plain `<script>` files (no ES modules).

## 2. Files and ownership

| Path | Owner | Purpose |
|---|---|---|
| `database/schema.sql`, `database/schema-mysql.sql` | Lead | DDL for both dialects (10 tables) |
| `src/Database.php`, `src/Clock.php`, `src/Slot.php`, `src/DomainException.php` | Lead | infrastructure + time math + error type |
| `src/AvailabilityService.php` | stream A | REQ-1.1 availability search |
| `src/BookingService.php` | stream B | REQ-1.2 booking creation |
| `public/index.php` | Lead | HTTP API |
| `assets/demo/*`, `demo.html`, `index.html` | stream C | diner flow demo (REQ-1.1/1.2 UI) |
| `docs/01-domain-study.md`, `docs/03-api.md`, `docs/05-traceability.md`, `docs/diagrams/*.svg` | stream D | documentation + diagrams |
| `tests/AvailabilityServiceTest.php` | stream A | unit tests |
| `tests/BookingServiceTest.php`, `tests/BookingConcurrencyTest.php` | stream B | unit + concurrency tests |

Nobody but the Lead runs `git` commands, and nobody touches a file owned by another stream.

## 3. Data model (see `database/schema.sql`)

| Table | Key columns |
|---|---|
| `restaurants` | id, name, address, timezone (`Asia/Macau`), currency, languages |
| `service_periods` | restaurant_id, name, start_time, end_time, avg_dining_minutes, days_of_week |
| `tables` | restaurant_id, code (`T01`), seats, area (`main`/`window`/`terrace`), pos_x, pos_y, status (`FREE`/`BLOCKED`/`MAINTENANCE`) |
| `bookings` | reference (8 chars unique), restaurant_id, table_id, booking_date, start_time, end_time, party_size, status, guest_*, cutoff_at, channel, idempotency_token, risk_score |
| `booking_events` | booking_id, event_type (`created`/`changed`/`cancelled`/`seated`/`no_show`/`released`), actor, payload(JSON), occurred_at |
| `waitlist_entries` | restaurant_id, service_period_id, entry_date, party_size, status, offer_expires_at |
| `users` / `roles` / `user_roles` | role based access (W10), seeded with demo accounts |
| `idempotency_keys` | token → booking_id (double submit protection, REQ-1.2 7.9) |
| `notification_log`, `audit_log` | reserved for W8 / W10, tables exist already |

Seed data (`Database::seedDemo`, 60 tables — CAP-1.1): T17 is `BLOCKED`, T23 is `MAINTENANCE`,
capacities cycle 2/4/6/8, areas cycle main/window/terrace, three periods:
Lunch 11:30–14:30 (90 min), Afternoon Tea 15:00–17:00 (60 min), Dinner 17:30–22:00 (120 min).

## 4. Shared building blocks (already implemented — do not modify)

```php
// src/Slot.php
Slot::minutes('19:00'): int                 // 1140
Slot::format(1140): string                  // '19:00'
Slot::isValidDate('2026-11-10'): bool       // strict YYYY-MM-DD + checkdate
Slot::isValidTime('19:00'): bool
Slot::at($date, $time, DateTimeZone): DateTimeImmutable
Slot::addMinutes('19:00', 135): string      // '21:15'
Slot::overlaps($aStart, $aEnd, $bStart, $bEnd): bool   // half-open, back-to-back = no overlap
Slot::contains($periodStart, $periodEnd, $time): bool
Slot::iso8601($moment): string              // 2026-11-10T19:00:00+08:00
Slot::TURNOVER_BUFFER_MINUTES = 15
Slot::STEP_MINUTES = 15
Slot::MAX_DAYS_AHEAD = 90
Slot::DEFAULT_CUTOFF_MINUTES = 120

// src/Clock.php — injectable, tests use FrozenClock('2026-11-02 10:00:00')
interface Clock { now(): DateTimeImmutable; timezone(): DateTimeZone; }
SystemClock(string $timezone = 'Asia/Macau')
FrozenClock(string $iso, string $timezone = 'Asia/Macau'); advance(string $modifier): void

// src/DomainException.php — thrown by services, rendered by the API as JSON
new DomainException(string $code, string $message, array $details = [], int $status = 422)
DomainException::validation(['email' => 'Invalid e-mail address.'])   // 422 VALIDATION_ERROR
DomainException::notFound('...')                                      // 404 NOT_FOUND
DomainException::tableTaken($tableId, $alternatives)                  // 409 TABLE_TAKEN
DomainException::conflict('...', $details)                            // 409 CONFLICT
->errorCode(): string   ->status(): int   ->details(): array   ->toArray(): array

// src/Database.php
Database::sqlite(':memory:'): PDO
Database::mysql($host, $db, $user, $pass, $port): PDO
Database::migrate(PDO, ?string $schemaFile = null): void
Database::seedDemo(PDO, int $tableCount = 60): array{restaurant_id:int, tables:list<int>, periods:array<string,int>}
```

## 5. Stream A — `App\DineReserve\AvailabilityService` (REQ-1.1)

```php
final class AvailabilityService
{
    public function __construct(PDO $pdo, ?Clock $clock = null, int $cacheTtlSeconds = 30);

    /**
     * @param array{area?:string, service_period?:string} $filters
     * @throws DomainException
     */
    public function search(int $restaurantId, string $date, string $time, int $partySize, array $filters = []): array;

    /** Drop the cached availability of a restaurant/day (called after a booking is created). */
    public function invalidate(int $restaurantId, string $date): void;

    /** Is one table free for [start,end) on that date? Used by BookingService under its lock. */
    public function isTableFree(int $tableId, string $date, string $startTime, string $endTime, ?int $ignoreBookingId = null): bool;
}
```

### Rules

1. Restaurant must exist → `DomainException::notFound` (404).
2. `date`: strict `YYYY-MM-DD`; must satisfy `today <= date <= today + 90` in the **restaurant time zone**
   (today from the injected clock) → `DATE_OUT_OF_RANGE` (422, details: `min`, `max`).
3. `time`: `HH:MM`; must fall inside a service period (`start <= time < end`) → `NO_SERVICE_PERIOD`
   (422, details: list of periods with name/start/end). Optional `service_period` filter narrows the choice.
4. `party_size`: integer 1..20 → validation error; `> 20` → `PARTY_TOO_LARGE` (422,
   details: `max_party_size` and a `telephone` hint — URS REQ-1.1 6.8).
5. Slot = `[time, time + avg_dining_minutes + 15)`. Only start times aligned to `STEP_MINUTES`
   from the period start are offered; the last offered start is `period.end - slot_length`.
6. A table is offered only when it is `FREE` (never `BLOCKED`/`MAINTENANCE`) and `seats >= party_size`
   and no booking with status `CONFIRMED` or `SEATED` on the same table/date overlaps the slot
   (use `Slot::overlaps`). Optional `area` filter.
7. Cache the computed result for `cacheTtlSeconds` (default 30) keyed by
   restaurant/date/time/party/filters; a cache hit sets `meta.cached = true`. Bookings call `invalidate()`.
8. Never fabricate availability when the database is unavailable (let PDOException bubble up).

### Return shape (exact keys — the API and the demo rely on them)

```json
{
  "restaurant": {"id": 1, "name": "DineReserve Demo Restaurant", "timezone": "Asia/Macau", "address": "..."},
  "query": {"date": "2026-11-10", "time": "19:00", "party_size": 6, "area": null, "service_period": "Dinner"},
  "service_period": {"id": 3, "name": "Dinner", "start": "17:30", "end": "22:00",
                     "avg_dining_minutes": 120, "slot_minutes": 135},
  "slots": [
    {"start": "17:30", "end": "19:45", "available_tables": 12, "largest_party_size": 8},
    {"start": "17:45", "end": "20:00", "available_tables": 11, "largest_party_size": 8}
  ],
  "tables": [{"id": 5, "code": "T05", "seats": 4, "area": "main"},
             {"id": 6, "code": "T06", "seats": 6, "area": "main"}],
  "summary": {"available_tables": 12, "largest_party_size": 8, "is_available": true},
  "meta": {"generated_at": "2026-11-02T10:00:00+08:00", "cached": false, "cache_ttl_seconds": 30}
}
```

`tables` is the list of tables bookable **at the requested time** (this is what the UI lists).
`slots` covers the whole service period so the UI can show quieter alternatives.

## 6. Stream B — `DineReserve\BookingService` (REQ-1.2)

```php
final class BookingService
{
    public function __construct(PDO $pdo, ?AvailabilityService $availability = null, ?Clock $clock = null);

    /** @throws DomainException */
    public function create(array $input): array;   // ['booking' => [...], 'created' => bool]
}
```

### Accepted input keys

`restaurant_id` (int), `table_id` (int), `date` (`YYYY-MM-DD`), `time` (`HH:MM`), `party_size` (int),
`name` (1..80), `email` (valid, ≤254, stored lower-cased), `mobile?`, `special_requests?` (≤200),
`idempotency_token?` (`[A-Za-z0-9_-]{8,64}`).

### Rules

1. Validate every field first; collect **all** errors into one
   `DomainException::validation(['field' => 'message', ...])` (422). No partial booking is created.
2. Idempotency: if the token already exists in `idempotency_keys`, return the stored booking with
   `created: false` — no second row, no second notification.
3. Open a write transaction. SQLite: `BEGIN IMMEDIATE`. MySQL: `SELECT ... FOR UPDATE` on the table row
   (use `Database::driver($pdo)`), so two concurrent diners cannot take the same table.
4. Under the lock re-validate: table belongs to the restaurant (`NOT_FOUND`), status `FREE`
   (`TABLE_UNAVAILABLE` 409), `party_size <= seats` (`PARTY_TOO_LARGE` 422), date/time in range,
   service period exists, slot in the future (`SLOT_IN_PAST` 422), and `AvailabilityService::isTableFree`
   (`TABLE_TAKEN` 409 with up to 3 `alternatives` taken from `search()` at the same date/time).
5. Reference: 8 chars from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no O/0/I/1, URS REQ-1.2 7.8),
   cryptographically random; retry up to 5 times on a unique-constraint violation.
6. `cutoff_at` = booking start − 120 minutes, stored as ISO 8601 with the restaurant offset.
7. Write the booking (`status = CONFIRMED`, `channel = online`), a `booking_events` row
   (`event_type = created`, `actor = diner`, JSON payload), and the `idempotency_keys` row inside the
   same transaction. On any error roll back and release the lock.
8. After commit call `AvailabilityService::invalidate($restaurantId, $date)`.
9. Simulated e-mail: append to `notification_log` (`channel = email`, `template = booking_confirmation`,
   `status = QUEUED`, `scheduled_at` = now) inside the same transaction — W8 replaces this with the real queue.

### Return shape

```json
{
  "booking": {
    "id": 12, "reference": "K7MQ4P2X", "status": "CONFIRMED",
    "date": "2026-11-10", "start": "19:00", "end": "21:15", "party_size": 6,
    "table": {"id": 5, "code": "T05", "seats": 6, "area": "main"},
    "guest": {"name": "Chan Tai Man", "email": "chan@example.com", "mobile": null},
    "special_requests": null,
    "cutoff_at": "2026-11-10T17:00:00+08:00",
    "reminders": ["2026-11-09T19:00:00+08:00", "2026-11-10T17:00:00+08:00"],
    "channel": "online", "created_at": "2026-11-02T10:00:00+08:00"
  },
  "created": true
}
```

## 7. HTTP API (`public/index.php`, run with `php -S 127.0.0.1:8080 -t public`)

| Method | Path | Body / query | Success |
|---|---|---|---|
| GET | `/api/v1/health` | — | `{"status":"ok","time":"...","php":"8.3"}` |
| GET | `/api/v1/restaurant` | — | restaurant profile + service periods + table count |
| GET | `/api/v1/availability` | `restaurant_id,date,time,party_size,area?` | AvailabilityService output |
| POST | `/api/v1/bookings` | JSON body (see 6.1) | 201 + BookingService output |
| GET | `/api/v1/bookings/{reference}` | `email` | booking (used by W7 change/cancel) |

Error envelope, any status: `{"error": {"code": "TABLE_TAKEN", "message": "...", "details": {...}}}`.
CORS: `Access-Control-Allow-Origin: *`, `OPTIONS` → 204.

## 8. Testing

```bash
/tmp/phpcli/php vendor/bin/phpunit            # entire suite
/tmp/phpcli/php vendor/bin/phpunit --filter Availability
/tmp/phpcli/php vendor/bin/phpunit tests/BookingServiceTest.php
```

`tests/TestCase.php` gives every test: `$this->pdo` (fresh SQLite DB, schema migrated, demo seeded),
`$this->restaurantId`, `$this->tableIds`, `$this->clock` (frozen at 2026-11-02 10:00), helpers
`tableIdByCode('T05')`, `table($id)`, `insertBooking([...])`.

Coverage expected for W4/W5:

- Availability: happy path counts, capacity filtering, blocked/maintenance excluded, overlapping
  booking excluded, back-to-back allowed, date in the past / beyond 90 days rejected, party > 20,
  time outside service periods, area filter, 30 s cache hit + invalidation.
- Booking: happy path shape (reference alphabet/length, cutoff, reminders, event row, notification row),
  validation aggregation, capacity exceeded, blocked table, unknown table/restaurant, slot in the past,
  double booking → TABLE_TAKEN with alternatives, idempotency replay (`created: false`, one row),
  concurrent attempts (fork two processes against one SQLite file, or two connections with
  `BEGIN IMMEDIATE`) → exactly one booking.

## 9. Conventions

- `declare(strict_types=1);` in every PHP file, PSR-12, 4 spaces, final classes where possible.
- No new Composer dependencies in W1–W6 (keeps CI fast and the licence constraint of URS 3.1 satisfied).
- All money/dates: `YYYY-MM-DD`, `HH:MM`, ISO 8601 timestamps with offset; text UTF-8.
- Every public method that can reject input throws `DomainException` — never `exit`/`die`.

## 10. REQ-1.3 — change or cancel a booking (W7, implemented)

```php
// src/BookingService.php
public function change(array $input): array;   // ['booking' => [...], 'changed' => bool, 'changes' => ['date','time',...]]
public function cancel(array $input): array;   // ['booking' => [...], 'cancelled' => bool]
public function issueManageToken(string $reference, int $ttlHours = 72): string;
public function verifyManageToken(string $token): array; // ['reference' => 'K7MQ4P2X', 'expires_at' => '...']
```

**Identity** — every call carries `reference` plus either `manage_token` (signed link) or `email`
(the address used at booking time). A missing identity is `AUTHORIZATION_REQUIRED` (403); a wrong
e-mail or an unknown reference both answer with the same `NOT_FOUND` (404) so the reference cannot be
probed (URS SEC-1.6).

**Rules** — the booking must be `CONFIRMED` (`BOOKING_CANCELLED` / `BOOKING_NOT_EDITABLE`, 409) and
`now <= cutoff_at` (`CUTOFF_PASSED`, 422, details include the cut-off). `change()` locks both the old
and the new table (ascending id order), re-runs every slot rule of REQ-1.2 under the lock and performs a
single `UPDATE` that moves the booking; the old table is released by that update, so a failed change
leaves the original booking untouched. `cancel()` sets `CANCELLED`, releases the table and is idempotent.
Every transition appends a `booking_events` row (`changed` with before/after, `cancelled` with the
released slot) and queues a `notification_log` row for W8.

**Manage token** — `base64url("REF.<expires_at>.<hmac_sha256(REF.expires_at, secret)>")`, secret from
`BOOKING_LINK_SECRET` (documented development fallback), TTL 72 h, validated with `hash_equals`.
`TOKEN_INVALID` (403) for a malformed/tampered token, `TOKEN_EXPIRED` (403) after expiry.

**HTTP** — `POST /api/v1/bookings/{reference}/change`, `POST …/cancel`, `POST …/manage-token`,
`GET …/manage?token=…` (returns the booking plus `manage.can_change` / `manage.can_cancel`).
Booking payloads now include `manage_token` and `manage_url`.

## 11. REQ-1.4 — notifications (W8, implemented)

```php
// src/NotificationService.php
public function scheduleRemindersForBooking(int $bookingId): int;   // 24 h + 2 h rows
public function rescheduleRemindersForBooking(int $bookingId): int; // change: cancel old, queue new
public function cancelPendingReminders(int $bookingId): int;        // cancel: drop queued reminders
public function dispatch(int $limit = 50): array;                   // ['sent','failed','retried','cancelled','due']
public function render(array $booking, array $restaurant, array $table, string $template): array;
```

**Queue** — `notification_log` is the outbox: `status` ∈ `QUEUED | SENT | FAILED | CANCELLED`,
`scheduled_at` is the next attempt, `attempts` and `last_error` support triage. `BookingService::create()`
still writes the confirmation row inside the booking transaction; `NotificationService` adds the two
reminders after it (a reminder whose moment has already passed is skipped, e.g. a same-evening booking).

**Templates** — `booking_confirmation`, `booking_reminder_24h`, `booking_reminder_2h`,
`booking_changed`, `booking_cancelled`. Each carries reference, date, time window, party size and table;
everything except the cancellation notice also carries the signed manage link from REQ-1.3.

**Delivery** — a `Mailer` port. Production binds an SMTP adapter; the repository ships `FileMailer`
(`storage/mail.log`) so the pipeline is runnable without credentials. Failures are retried after
**1 / 5 / 15 minutes** and become `FAILED` after the fourth attempt; each attempt is isolated so one bad
row never blocks the batch.

**Triggers** — `bin/notify.php` (cron: `* * * * * cd /srv/dinereserve && php bin/notify.php --limit=200`),
`POST /api/v1/notifications/dispatch` and `GET /api/v1/notifications/due`. `BookingService::setNotifications()`
keeps the queue in sync when a booking is created, changed or cancelled.

## 12. REQ-1.5 — waitlist and 5-minute offers (W9, implemented)

```php
// src/WaitlistService.php
public function join(array $input): array;                           // ['entry','position','created']
public function position(int $entryId): int;                          // 1 = next in line
public function offerNextForReleasedSlot(int $restaurantId, string $date, string $time, int $tableId): ?array;
public function accept(int $entryId, array $input): array;            // ['entry','booking']
public function decline(int $entryId, array $input): array;           // ['entry','offered']
public function expireOffers(): array;                                // ['expired','offered']
```

**Queue** — `waitlist_entries` is per restaurant and per day, `created_at, id` gives the strict arrival
order required by the URS. `position()` counts only `WAITING` rows, so an offered diner no longer holds a
place in the queue.

**Offer lifecycle** — `WAITING → OFFERED → CONVERTED | DECLINED | EXPIRED`. When a booking is cancelled,
`BookingService::cancel()` hands the freed slot to `offerNextForReleasedSlot()`: the first waiter whose
party fits the table and whose service period matches gets `offer_expires_at = now + 5 min`,
`offer_table_id` / `offer_time`, an e-mail notice through the W8 `Mailer` port, and an `audit_log` row.

**Conversion** — `accept()` first claims the offer with a conditional `UPDATE … WHERE status='OFFERED'`
(so two taps cannot create two bookings), then calls `BookingService::create()` with `channel = waitlist`;
the normal slot rules are re-checked, so a table taken meanwhile yields `TABLE_TAKEN` and the offer is
handed back. `decline()` and `expireOffers()` (cron: `bin/waitlist.php --expire`) pass the slot to the next
waiter when the table is still free.

**Schema** — `waitlist_entries` gained `offer_table_id` and `offer_time`; bookings gained the `channel`
field (`online | phone | walk_in | waitlist`), which the reporting in W11 will use.
