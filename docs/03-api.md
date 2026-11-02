# DineReserve — HTTP API v1

**Document:** `docs/03-api.md` · **Owner:** Stream D · **Group:** 6
**Contract status (2026-11-02, W6):** implemented and verified against `public/index.php` plus the
two services: `src/AvailabilityService.php` (REQ-1.1) and `src/BookingService.php` (REQ-1.2), with
the shared `src/Database.php`, `src/Slot.php`, `src/Clock.php` and `src/DomainException.php`.
Where this document and the code disagree, the code wins and this document must be updated in the
same pull request.

**Related:** [architecture](02-architecture.md) · [traceability](05-traceability.md) ·
[context diagram](diagrams/context.svg) · [booking flow](diagrams/booking-flow.svg) · URS §6–§16.

---

## 1. Conventions

| Topic | Rule |
|---|---|
| Base URL (local) | `http://127.0.0.1:8080` |
| Versioning | Path version `/api/v1`; additions are backward compatible, breaking changes need `/api/v2`. |
| Content type | Requests and responses are `application/json; charset=utf-8`; request bodies **must** be valid JSON. |
| Dates | `YYYY-MM-DD` (ISO 8601), interpreted in the restaurant's local date (`Asia/Macau`). |
| Times | `HH:MM`, 24-hour clock, restaurant local time. No seconds, no time-zone suffix. |
| Timestamps | ISO 8601 with offset, e.g. `2026-11-10T17:00:00+08:00` (`Slot::iso8601`). |
| Integers | JSON numbers (not strings): `restaurant_id`, `table_id`, `party_size`. |
| Money | Not accepted or returned in v1 (online payment/deposit are out of scope, URS §1.3). |
| CORS | `Access-Control-Allow-Origin: *`; `OPTIONS` returns `204 No Content` with the allow headers. |
| Authentication | **None in W6.** Public read endpoints are anonymous; booking is anonymous by design (URS REQ-1.2 7.7). Admin endpoints arrive with REQ-1.9 (W11). |
| Transport | HTTPS/TLS 1.2+ in any deployed environment (SEC-1.1). The local `php -S` URL is HTTP only for development. |
| PII | Availability responses never contain diner data. Booking responses contain only the guest details the caller supplied. |
| Rate limiting | Not implemented in W6; the pilot relies on CAP-2.1 concurrency targets (20 diner searches, 5 bookings/minute peak). |

### 1.1 Error envelope

Every non-2xx response uses one envelope, produced by `DomainException::toArray()`:

```json
{
  "error": {
    "code": "TABLE_TAKEN",
    "message": "The selected table has just been booked by someone else. Please choose another slot.",
    "details": {"table_id": 3, "alternatives": [{"id": 4, "code": "T04", "seats": 8, "area": "main"}]}
  }
}
```

`code` is a stable machine-readable string (clients branch on it); `message` is English for logs and
fallback display; `details` is endpoint-specific and may be `{}`. Field-level validation errors are
aggregated — a request with three bad fields returns all three in one response (no partial write).

### 1.2 Status and error-code reference

| HTTP | `error.code` | When it is returned | `details` (example) |
|---|---|---|---|
| 400 | `MALFORMED_JSON` | `POST /api/v1/bookings` body is not a JSON object. | `{}` |
| 404 | `NOT_FOUND` | Restaurant, table or booking does not exist; booking reference + e-mail do not match (same generic message, no existence leak — URS REQ-1.3 8.9). | `{}` |
| 404 | `ROUTE_NOT_FOUND` | Unknown path, or a method the path does not serve (the W6 router has no separate 405). | `{}` |
| 409 | `TABLE_TAKEN` | Re-check under the lock found an overlapping `CONFIRMED`/`SEATED` booking on the chosen table. | `{"table_id": 3, "alternatives": [ ... up to 3 tables ... ]}` |
| 409 | `TABLE_UNAVAILABLE` | Table exists but its status is `BLOCKED` or `MAINTENANCE` (or it belongs to another restaurant). | `{"table_id": 17, "status": "BLOCKED"}` |
| 409 | `CONFLICT` | Generic optimistic/concurrency conflict (e.g. idempotency token reused with a different payload). | `{"token": "..."}` |
| 422 | `VALIDATION_ERROR` | One or more fields are missing, malformed or out of range. No query/transaction is run. | `{"errors": {"email": "Invalid e-mail address."}}` |
| 422 | `PARTY_TOO_LARGE` | Search: `party_size > 20` (online limit; redirect to telephone, URS REQ-1.1 6.8). Booking: `party_size > table.seats` (URS REQ-1.2 7.7). | Search: `{"max_party_size": 20, "telephone": "<restaurant telephone from settings>"}`; booking: `{"table_id": 3, "party_size": 8, "max_party_size": 6, "telephone": "..."}` |
| 422 | `DATE_OUT_OF_RANGE` | Date is before "today" or more than 90 days ahead (`Slot::MAX_DAYS_AHEAD`). | `{"min": "2026-11-02", "max": "2027-01-31"}` |
| 422 | `NO_SERVICE_PERIOD` | Time is not inside any service period (or the sitting would overrun the period end). | `{"requested_time": "09:00", "latest_start": null, "service_periods": [{"id": 1, "name": "Lunch", "start": "11:30", "end": "14:30"}]}`; overrun: `{"requested_time": "21:00", "latest_start": "19:45"}` |
| 422 | `SLOT_IN_PAST` | Booking was attempted for a slot whose start is not in the future. | `{"date": "2026-11-10", "time": "19:00", "now": "2026-11-10T20:00:00+08:00"}` |
| 500 | `INTERNAL_ERROR` | Unexpected non-database failure. Logged; the client receives a generic message. | `{}` |
| 503 | `DATABASE_UNAVAILABLE` | Database connection/migration failure at startup, or a PDO error during the request. The client shows a retry page; no fabricated availability (URS REQ-1.1 6.9). | `{}` |

Rule of thumb: **4xx = the caller can fix it; 5xx = the server failed.** `DomainException` never
calls `exit`/`die`; the router renders `toArray()` with `status()`.

---

## 2. Endpoint summary

| # | Method | Path | Purpose | REQ | Success |
|---|---|---|---|---|---|
| 1 | GET | `/api/v1/health` | Liveness probe for the router, PHP version and clock. | — | 200 |
| 2 | GET | `/api/v1/restaurant` | Restaurant profile, service periods and table count. | REQ-1.1 (discovery) | 200 |
| 3 | GET | `/api/v1/availability` | Conflict-free table/slot search for a date, time and party size. | REQ-1.1 | 200 |
| 4 | POST | `/api/v1/bookings` | Create a booking atomically (lock + idempotency). | REQ-1.2 | 201 / 200 |
| 5 | GET | `/api/v1/bookings/{reference}` | Read one booking using reference + e-mail. | REQ-1.2 / REQ-1.3 foundation | 200 |

`GET /` (or `/api`) returns a small machine-readable index of these endpoints and points at this
document; it is intended for the W6 smoke test, not for clients.

---

## 3. GET `/api/v1/health`

Liveness probe. It does not touch the database; the demo and the W6 deployment check use it to
prove the router is up.

```bash
curl -s http://127.0.0.1:8080/api/v1/health
```

**Parameters:** none.

**200 OK**

```json
{
  "status": "ok",
  "php": "8.3.0",
  "database": "sqlite",
  "time": "2026-11-02T18:19:42+08:00"
}
```

| Field | Type | Meaning |
|---|---|---|
| `status` | string | `"ok"` when the router answered. |
| `php` | string | `PHP_VERSION` of the running server; used in the W6 smoke test. |
| `database` | string | PDO driver in use: `sqlite` (local demo/tests) or `mysql` (deployment). |
| `time` | string | Current time from the application clock, restaurant offset (`Asia/Macau`). |

**Errors:** none in normal operation. A database connection/migration failure is answered by the
startup handler with `503 DATABASE_UNAVAILABLE` before the route dispatch, so the process is
observably up even when the data layer is not.

---

## 4. GET `/api/v1/restaurant`

Returns the restaurant profile and its bookable service periods. The diner UI uses it to build the
date/time selector; it is the "Discovery" input of REQ-1.1 (URS §2.1).

```bash
curl -s http://127.0.0.1:8080/api/v1/restaurant
```

**Parameters:** none. v1 serves the single seeded restaurant (CAP-1.1: one restaurant in year one);
`restaurant_id` is accepted later when multi-tenant routing lands (CAP-1.2, W11+).

**200 OK**

```json
{
  "restaurant": {
    "id": 1,
    "name": "DineReserve Demo Restaurant",
    "address": "Rua de Nam Keng, Taipa, Macau",
    "timezone": "Asia/Macau",
    "currency": "MOP",
    "languages": "en,zh"
  },
  "service_periods": [
    {"id": 1, "name": "Lunch", "start": "11:30", "end": "14:30", "avg_dining_minutes": 90},
    {"id": 2, "name": "Afternoon Tea", "start": "15:00", "end": "17:00", "avg_dining_minutes": 60},
    {"id": 3, "name": "Dinner", "start": "17:30", "end": "22:00", "avg_dining_minutes": 120}
  ],
  "tables": 60
}
```

| Field | Type | Meaning |
|---|---|---|
| `restaurant.id` | integer | `restaurants.id`. |
| `restaurant.timezone` | string | IANA zone; all dates/times in the API are in this zone. |
| `restaurant.languages` | string | Comma-separated UI label languages (`"en,zh"`), as stored; all stored text is UTF-8. |
| `service_periods[]` | array | `service_periods` rows; `start`/`end` map from `start_time`/`end_time`; `end` is exclusive for bookable starts. `days_of_week` is deliberately not exposed in v1 (all demo periods are 7 days). |
| `tables` | integer | Total tables of the restaurant, including `BLOCKED`/`MAINTENANCE` (60 in the demo seed). |

**Errors:** `404 NOT_FOUND` if no restaurant is configured; `503 DATABASE_UNAVAILABLE` on a PDO
failure; `500 INTERNAL_ERROR` on an unexpected failure.

---

## 5. GET `/api/v1/availability`

The core of REQ-1.1: for a date, time and party size, return the bookable slots and the tables that
can be booked **at the requested time**, computed from live bookings (not a cached daily snapshot).
Search never locks a table; only REQ-1.2 holds a lock.

```bash
curl -s "http://127.0.0.1:8080/api/v1/availability?restaurant_id=1&date=2026-11-10&time=19:00&party_size=6"
```

### 5.1 Query parameters

| Name | Required | Type / allowed values | Rules |
|---|---|---|---|
| `restaurant_id` | yes | integer ≥ 1 | Must exist, else `NOT_FOUND`. The W6 router defaults to `1` when the parameter is omitted (single-tenant demo); clients should always send it. |
| `date` | yes | `YYYY-MM-DD` | Strict format (`Slot::isValidDate`); must satisfy `today ≤ date ≤ today + 90` in the restaurant time zone, else `DATE_OUT_OF_RANGE` (details `min`, `max`). |
| `time` | yes | `HH:MM` | Must fall inside a service period (`start ≤ time < end`), else `NO_SERVICE_PERIOD`. |
| `party_size` | yes | integer 1–20 | Non-integer/out of range → `VALIDATION_ERROR`; `> 20` → `PARTY_TOO_LARGE` with the telephone hint. |
| `area` | no | `main` \| `window` \| `terrace` | Also accepts the empty string as "no filter"; unknown value → `VALIDATION_ERROR`. |
| `service_period` | no | service-period name, e.g. `Dinner` | Narrows the search to one period; unknown name → `NO_SERVICE_PERIOD`. |

Slot rule: a table is bookable when it is `FREE` (never `BLOCKED`/`MAINTENANCE`), `seats ≥ party_size`,
and no `CONFIRMED`/`SEATED` booking on that table overlaps `[time, time + avg_dining_minutes + 15)`
(`Slot::TURNOVER_BUFFER_MINUTES`; back-to-back is not an overlap). Only start times aligned to
`Slot::STEP_MINUTES = 15` are offered; the last start is `period.end − slot_length`.

### 5.2 Example success (2026-11-10 19:00, 6 people, Dinner)

> Captured on 2026-11-02 from a freshly seeded database (`php bin/seed.php`) for a 6-person party
> at 19:00 on Tuesday 2026-11-10. The `tables` array is an excerpt (28 entries in total); counts
> change with existing bookings, blocked tables and filters. The response timestamps are the ones
> returned by that smoke run.

**200 OK**

```json
{
  "restaurant": {
    "id": 1,
    "name": "DineReserve Demo Restaurant",
    "timezone": "Asia/Macau",
    "address": "Rua de Nam Keng, Taipa, Macau"
  },
  "query": {
    "date": "2026-11-10",
    "time": "19:00",
    "party_size": 6,
    "area": null,
    "service_period": "Dinner"
  },
  "service_period": {
    "id": 3,
    "name": "Dinner",
    "start": "17:30",
    "end": "22:00",
    "avg_dining_minutes": 120,
    "slot_minutes": 135
  },
  "slots": [
    {"start": "17:30", "end": "19:45", "available_tables": 28, "largest_party_size": 8},
    {"start": "17:45", "end": "20:00", "available_tables": 28, "largest_party_size": 8},
    {"start": "18:00", "end": "20:15", "available_tables": 28, "largest_party_size": 8},
    {"start": "18:15", "end": "20:30", "available_tables": 28, "largest_party_size": 8},
    {"start": "18:30", "end": "20:45", "available_tables": 28, "largest_party_size": 8},
    {"start": "18:45", "end": "21:00", "available_tables": 28, "largest_party_size": 8},
    {"start": "19:00", "end": "21:15", "available_tables": 28, "largest_party_size": 8},
    {"start": "19:15", "end": "21:30", "available_tables": 28, "largest_party_size": 8},
    {"start": "19:30", "end": "21:45", "available_tables": 28, "largest_party_size": 8},
    {"start": "19:45", "end": "22:00", "available_tables": 28, "largest_party_size": 8}
  ],
  "tables": [
    {"id": 3, "code": "T03", "seats": 6, "area": "main"},
    {"id": 4, "code": "T04", "seats": 8, "area": "main"},
    {"id": 7, "code": "T07", "seats": 6, "area": "window"},
    {"id": 8, "code": "T08", "seats": 8, "area": "window"},
    {"id": 11, "code": "T11", "seats": 6, "area": "terrace"},
    {"id": 12, "code": "T12", "seats": 8, "area": "terrace"}
  ],
  "summary": {
    "available_tables": 28,
    "largest_party_size": 8,
    "is_available": true
  },
  "meta": {
    "generated_at": "2026-11-02T18:19:42+08:00",
    "cached": false,
    "cache_ttl_seconds": 30
  }
}
```

| Field | Type | Meaning |
|---|---|---|
| `query` | object | Echo of the normalised search criteria (`area` may be `null`; `service_period` is the **resolved period name**, always a string). |
| `service_period` | object | The period that contains `time`; `slot_minutes` = `avg_dining_minutes + 15`. |
| `slots[]` | array | Every aligned start in the period, ordered by time: `start`, `end`, `available_tables`, `largest_party_size`. Drives the "quieter alternatives" list. |
| `tables[]` | array | Tables bookable **at the requested time**, ordered by table number: `id`, `code`, `seats`, `area`. This is what the booking screen lists. |
| `summary.available_tables` | integer | Number of bookable tables at the requested time. |
| `summary.is_available` | boolean | `false` when no table fits; the UI must show the waitlist call to action (REQ-1.5), not an empty list. |
| `meta.cached` | boolean | `true` on a cache hit; the cache TTL is 30 s (URS REQ-1.1 6.4) and any booking invalidates it. |
| `meta.generated_at` | string | Response timestamp so the client can detect a stale result (URS REQ-1.1 6.8). |

### 5.3 Errors for availability

| HTTP | Code | Trigger |
|---|---|---|
| 404 | `NOT_FOUND` | `restaurant_id` does not exist. |
| 422 | `VALIDATION_ERROR` | Bad date format, bad time format, `party_size` not an integer in 1–20, unknown `area`. |
| 422 | `DATE_OUT_OF_RANGE` | Date in the past or more than 90 days ahead. |
| 422 | `NO_SERVICE_PERIOD` | Time outside all periods, unknown `service_period` filter, or the slot would overrun the period end. |
| 422 | `PARTY_TOO_LARGE` | `party_size > 20`; response carries `max_party_size` and the telephone hint. |
| 500 | `INTERNAL_ERROR` | Unexpected failure, no fabricated availability (URS REQ-1.1 6.9). |
| 503 | `DATABASE_UNAVAILABLE` | PDO failure; the UI shows a retry page, never a fabricated list. |

---

## 6. POST `/api/v1/bookings`

Creates one booking for a table that was shown as available. Validation runs first, then a write
transaction with a short-lived lock (`BEGIN IMMEDIATE` on SQLite, `SELECT ... FOR UPDATE` on MySQL)
re-checks availability; two concurrent diners can never both take the same table.

```bash
curl -s -X POST http://127.0.0.1:8080/api/v1/bookings \
  -H 'Content-Type: application/json' \
  -d '{
    "restaurant_id": 1,
    "table_id": 3,
    "date": "2026-11-10",
    "time": "19:00",
    "party_size": 6,
    "name": "Chan Tai Man",
    "email": "chan@example.com",
    "mobile": null,
    "special_requests": "Window seat if possible",
    "idempotency_token": "6f1c2a9b7d3e"
  }'
```

### 6.1 Request body

| Name | Required | Type / rules | Notes |
|---|---|---|---|
| `restaurant_id` | yes | integer ≥ 1 | Must exist. |
| `table_id` | yes | integer ≥ 1 | Must belong to `restaurant_id`. |
| `date` | yes | `YYYY-MM-DD` | Within today … today + 90. |
| `time` | yes | `HH:MM` | Inside a service period, slot start in the future. |
| `party_size` | yes | integer 1–20 | Must not exceed the table's `seats` (`PARTY_TOO_LARGE`). |
| `name` | yes | string, 1–80 characters, Unicode | Trimmed; HTML-escaped before display (URS REQ-1.2 7.8). |
| `email` | yes | valid address, ≤ 254 chars | Stored lower-cased; used for the confirmation and for `GET /bookings/{reference}`. |
| `mobile` | no | string or `null` | E.164 or local format with default country code; SMS is an optional channel. |
| `special_requests` | no | string ≤ 200 chars or `null` | Stored as typed; escaped for display. |
| `idempotency_token` | no | `^[A-Za-z0-9_-]{8,64}$` | Double-submit protection (URS REQ-1.2 7.9). Same token → same booking, no second row, no second notification. |

The diner's acceptance of the cancellation policy (URS REQ-1.2 7.2) is a UI-level consent in W6; the
API does not persist a separate consent field.

### 6.2 Success — new booking

**201 Created**

```json
{
  "booking": {
    "id": 1,
    "reference": "ZUYPMHXY",
    "status": "CONFIRMED",
    "date": "2026-11-10",
    "start": "19:00",
    "end": "21:15",
    "party_size": 6,
    "table": {"id": 3, "code": "T03", "seats": 6, "area": "main"},
    "guest": {"name": "Chan Tai Man", "email": "chan@example.com", "mobile": null},
    "special_requests": "Window seat if possible",
    "cutoff_at": "2026-11-10T17:00:00+08:00",
    "reminders": ["2026-11-09T19:00:00+08:00", "2026-11-10T17:00:00+08:00"],
    "channel": "online",
    "created_at": "2026-11-02T18:19:55+08:00"
  },
  "created": true
}
```

| Field | Type | Meaning |
|---|---|---|
| `reference` | string | 8 chars from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789` (no O/0/I/1, URS REQ-1.2 7.8), cryptographic, unique. |
| `status` | string | `CONFIRMED` on creation. |
| `end` | string | Start + `avg_dining_minutes + 15` (turnover buffer). |
| `table` | object | Assigned table snapshot: `id`, `code`, `seats`, `area`. |
| `cutoff_at` | string | Booking start − 120 min, ISO 8601 with restaurant offset (URS REQ-1.2 7.4; default `Slot::DEFAULT_CUTOFF_MINUTES`). |
| `reminders` | array | Scheduled 24 h and 2 h reminder timestamps; W8 turns these into queued notifications (REQ-1.4). |
| `channel` | string | `online` for this endpoint (`phone`, `walk_in`, `waitlist` are staff-created). |

### 6.3 Success — idempotent replay

**200 OK** with the **stored** booking and `"created": false`:

```json
{
  "booking": {"id": 1, "reference": "ZUYPMHXY", "status": "CONFIRMED", "...": "same shape as 6.2"},
  "created": false
}
```

No second row, no second confirmation notification. The body's `created` flag is authoritative even
if a proxy or future controller always returns 201.

### 6.4 Errors for booking creation

| HTTP | Code | Trigger |
|---|---|---|
| 400 | `MALFORMED_JSON` | Body is not a JSON object. |
| 404 | `NOT_FOUND` | Unknown restaurant, or table not belonging to the restaurant (re-checked under the lock). |
| 409 | `TABLE_TAKEN` | Overlapping `CONFIRMED`/`SEATED` booking found after the lock; `details.alternatives` holds up to 3 alternatives from `AvailabilityService::search()` at the same date/time. The diner's form data is preserved by the caller. |
| 409 | `TABLE_UNAVAILABLE` | Table status is `BLOCKED` or `MAINTENANCE`. |
| 409 | `CONFLICT` | Idempotency token reused with a different booking payload, or another concurrency conflict. |
| 422 | `VALIDATION_ERROR` | All invalid fields aggregated in `details.errors`; no partial booking. |
| 422 | `PARTY_TOO_LARGE` | `party_size > table.seats`. |
| 422 | `DATE_OUT_OF_RANGE` | Date outside the 90-day window. |
| 422 | `NO_SERVICE_PERIOD` | Time outside the restaurant's periods. |
| 422 | `SLOT_IN_PAST` | Slot start is not in the future at commit time. |
| 500 | `INTERNAL_ERROR` | Unexpected failure; transaction rolled back, lock released, no booking created. |
| 503 | `DATABASE_UNAVAILABLE` | PDO/connection failure; no booking created. |

---

## 7. GET `/api/v1/bookings/{reference}`

Reads one booking. Access control is deliberately minimal but privacy-safe: the caller must present
both the booking reference and the e-mail used at booking time (SEC-1.6 / URS REQ-1.3 8.4). This is
the data source the W7 change/cancel flow starts from.

```bash
curl -s "http://127.0.0.1:8080/api/v1/bookings/ZUYPMHXY?email=chan@example.com"
```

### 7.1 Path / query parameters

| Name | In | Required | Rules |
|---|---|---|---|
| `reference` | path | yes | Exactly 8 characters from the unambiguous alphabet, **upper-case** (`[A-Z2-9]{8}`); a lower-case reference is not routed. |
| `email` | query | yes | Must equal (case-insensitive) the booking's `guest_email`; a missing e-mail follows the generic `NOT_FOUND` path (no validation leak). |

### 7.2 Success

**200 OK** — same booking object as §6.2:

```json
{
  "booking": {
    "id": 1,
    "reference": "ZUYPMHXY",
    "status": "CONFIRMED",
    "date": "2026-11-10",
    "start": "19:00",
    "end": "21:15",
    "party_size": 6,
    "table": {"id": 3, "code": "T03", "seats": 6, "area": "main"},
    "guest": {"name": "Chan Tai Man", "email": "chan@example.com", "mobile": null},
    "special_requests": "Window seat if possible",
    "cutoff_at": "2026-11-10T17:00:00+08:00",
    "reminders": ["2026-11-09T19:00:00+08:00", "2026-11-10T17:00:00+08:00"],
    "channel": "online",
    "created_at": "2026-11-02T18:19:55+08:00"
  }
}
```

`status` may be `CONFIRMED`, `CANCELLED`, `SEATED`, `NO_SHOW` or `COMPLETED`; W6 only creates
`CONFIRMED`, the others arrive with REQ-1.3/REQ-1.6.

### 7.3 Errors

| HTTP | Code | Trigger |
|---|---|---|
| 404 | `NOT_FOUND` | Unknown reference, missing e-mail **or** e-mail mismatch — the message is identical in every case so a reference cannot be probed (URS REQ-1.3 8.9). |
| 404 | `ROUTE_NOT_FOUND` | Reference does not match the 8-character route pattern (e.g. lower-case input). |
| 500 | `INTERNAL_ERROR` | Unexpected failure. |
| 503 | `DATABASE_UNAVAILABLE` | PDO failure. |

---

## 8. Run it locally (three commands)

Prerequisites: PHP 8.1+ with `pdo_sqlite` (the demo/tests) or `pdo_mysql` (TastyIgniter deployment).
`composer install` is optional: `bootstrap/autoload.php` falls back to a PSR-4 autoloader so the
three commands work without Composer on a classroom machine.

```bash
php bin/seed.php
php -S 127.0.0.1:8080 -t public
curl -s "http://127.0.0.1:8080/api/v1/availability?restaurant_id=1&date=2026-11-10&time=19:00&party_size=6"
```

- `php bin/seed.php` recreates `database/local.sqlite` with one restaurant, 60 tables (T17 `BLOCKED`,
  T23 `MAINTENANCE`, T34/T46/T51 likewise out of service) and the three service periods.
- The built-in server is single-threaded; use the PHPUnit concurrency test (two processes against one
  SQLite file) rather than curl to prove the REQ-1.2 double-booking guarantee.
- Add `-H 'Content-Type: application/json'` for the POST endpoints (see §6).

**Smoke test sequence:** `health` → `restaurant` → `availability` → `bookings` → `bookings/{ref}`.

---

## 9. Implementation status (keep this table honest)

| Endpoint | Contract | Implementation | Test evidence |
|---|---|---|---|
| `GET /health` | final | `public/index.php` (implemented) | smoke test / curl |
| `GET /restaurant` | final | `public/index.php` (implemented) | smoke test / curl |
| `GET /availability` | final | `AvailabilityService::search()` (implemented) | `tests/AvailabilityServiceTest.php` |
| `POST /bookings` | final | `BookingService::create()` (implemented) | `tests/BookingServiceTest.php`, `tests/BookingConcurrencyTest.php` |
| `GET /bookings/{reference}` | final | `public/index.php` + `bookingPayload()` (implemented) | used by W7 change/cancel; dedicated test added with REQ-1.3 |

Smoke-tested on 2026-11-02 against a temporary seeded SQLite database with the PHP built-in server:
health, restaurant, availability, booking creation, booking lookup and idempotent replay all returned
the shapes documented above (a fresh seed reports 28 available 6-seat-or-larger tables at 19:00,
10 Dinner slots, and `GET /bookings/{reference}` returns the same object as creation).

---

## 10. Compatibility notes

- Field names are **snake_case** and stable; clients must ignore unknown fields (forward compatible).
- New optional response fields may be added in v1; removing or renaming a field is a v2 change.
- Error `details` keys are documented per code; clients must not branch on the free-text `message`.
- The API deliberately exposes no admin/role endpoints in v1: REQ-1.9 administration is added in
  W11 behind authentication (SEC-1.4, 30-minute session).
