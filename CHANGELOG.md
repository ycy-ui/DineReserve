# Changelog

All notable changes to DineReserve are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
weeks refer to the 14-week plan in [`docs/02-architecture.md`](docs/02-architecture.md) and the Task 1 proposal.

## [0.2.0] - 2026-11-04 — Week 7: change or cancel a booking (REQ-1.3)

### Added (W7 · REQ-1.3)
- `BookingService::change()` — moves a confirmed booking to another date, time, party size or table in
  one transaction: the target table is locked and re-checked under the lock, the booking row is updated,
  and the old table is released by that same update, so a failed change never loses the original booking.
- `BookingService::cancel()` — cancels before the stored cut-off, releases the table and is idempotent
  (a second cancellation returns `cancelled: false` and writes no second audit row).
- Cut-off enforcement: changes/cancellations after `cutoff_at` are refused with `CUTOFF_PASSED` and the
  restaurant's contact details instead of failing silently.
- Signed self-service manage links: `issueManageToken()` / `verifyManageToken()` — HMAC-SHA256 over
  reference + expiry, 72 h TTL, tamper and expiry detection (`TOKEN_INVALID` / `TOKEN_EXPIRED`).
  Identity is either the signed link or reference + booking e-mail (URS SEC-1.6).
- Audit trail: every transition appends a `booking_events` row (`changed` with before/after values,
  `cancelled` with the released slot), and a notice row is queued in `notification_log`
  (`booking_changed` / `booking_cancelled`) for W8 to deliver.
- HTTP API: `POST /api/v1/bookings/{reference}/change`, `POST …/cancel`, `POST …/manage-token`,
  `GET …/manage?token=…`; booking responses now carry `manage_token` and `manage_url`.
- 25 new tests (89 total / 767 assertions).

## [0.1.1] - 2026-11-02 — CI pipeline and the MySQL dialect fix it found

### Added (W2 · engineering baseline)
- GitHub Actions pipeline (`.github/workflows/ci.yml`) with three jobs: **PHP 8.2 + PHPUnit + live HTTP
  smoke test**, **MySQL 8.0 schema apply + seed**, **static demo lint** (`node --check`, HTML/SVG parse,
  no-external-resource guard). Each job degrades gracefully on the earlier commits of the series and runs
  its real assertions once the corresponding code exists.
- Pull-request template that requires the URS requirement id and the verification commands.
- GitHub project hygiene: 7 milestones, 14 requirement-linked issues, labels for week/area/type.

### Fixed
- **MySQL 8.0 rejected ISO-8601 timestamps** in `DATETIME` columns with
  `SQLSTATE[22007] … Incorrect datetime value: '2026-11-02T10:44:10Z'`. `Database::dateTimeFor()` now
  serialises timestamps as UTC `Y-m-d H:i:s` for MySQL (SQLite keeps the ISO string) and reading a MySQL
  `DATETIME` is interpreted as UTC before being converted to the restaurant time zone, so the API keeps
  returning ISO-8601 with an offset. Found and fixed by the new pipeline, not in production.

## [0.1.0] - 2026-11-02 — Weeks 1–6 (foundation, availability, booking, diner flow)

### Added (W1 · project setup)
- Repository layout for a TastyIgniter-compatible build: `src/` framework-agnostic engine,
  `public/` HTTP API, `database/` dual-dialect DDL, `docs/` design documents, `assets/demo/` static demo.
- `composer.json` (PHP ≥ 8.1, PHPUnit ^10.5 dev only — no runtime dependency), `phpunit.xml`, `.gitignore`.
- TastyIgniter extension stub at `extensions/dinereserve/booking/` documenting the W7 integration point.

### Added (W2 · engineering baseline)
- GitHub Actions CI with three jobs: PHP 8.2 + PHPUnit + API smoke test (SQLite),
  MySQL 8.0 schema/seed job, and a static-demo lint job (JS syntax, HTML/SVG parsing, no external resources).
- Pull-request template requiring a requirement reference and verification steps.

### Added (W3 · data model)
- `database/schema.sql` (SQLite, used by the test suite) and `database/schema-mysql.sql` (MySQL 8.0,
  deployment dialect) with 10 tables: restaurants, service_periods, tables, bookings, booking_events,
  waitlist_entries, users, roles, user_roles, idempotency_keys (+ notification_log, audit_log).
- `Database::seedDemo()`: the year-one capacity of URS CAP-1.1 — 1 restaurant, 3 service periods,
  60 tables (capacities 2/4/6/8 cycling, areas main/window/terrace, T17 blocked, T23 maintenance).
- `bin/seed.php` CLI for local SQLite and `--mysql` seeding.

### Added (W4 · REQ-1.1 availability search)
- `AvailabilityService::search()` — maps a date/time/party size to a service period,
  generates 15-minute-aligned start times, excludes blocked/maintenance tables, honours capacity,
  detects overlapping bookings with half-open interval arithmetic, and caches results for 30 seconds.
- `AvailabilityService::isTableFree()` and `invalidate()` for the booking transaction.
- Unit tests covering capacity, blocked tables, overlap vs back-to-back, date range (past / +90 days),
  party-size limits, service-period gaps, area filter, cache hit and invalidation.

### Added (W5 · REQ-1.2 booking creation)
- `BookingService::create()` — aggregated field validation, idempotency by token, write transaction
  (`BEGIN IMMEDIATE` on SQLite, `SELECT … FOR UPDATE` on MySQL), 8-character booking reference from an
  unambiguous alphabet, cut-off and reminder timestamps, booking event + queued confirmation e-mail rows.
- `TABLE_TAKEN` (409) with alternative slots when the table is taken between search and submit.
- Concurrency test proving that two simultaneous attempts on the same table produce exactly one booking.

### Added (W6 · diner flow + documentation)
- Static, dependency-free diner demo (`demo.html` + `assets/demo/`) implementing the same rules in the
  browser, with localStorage persistence, a 60-second booking timer and a confirmation screen.
- HTTP API (`public/index.php`): health, restaurant profile, availability, booking creation, booking lookup.
- Diagrams: context, entity-relationship and booking-flow SVGs (the Task 2 URS referenced a context
  diagram and a second figure that did not exist; both are now real files).
- Documentation: domain-study plan, API reference and requirement traceability matrix.

### Known limitations
- The TastyIgniter extension is a documented stub; the engine is wired into the platform in W7.
- Notifications are recorded in `notification_log` only; the scheduler and retry policy arrive in W8.
- Change/cancel (REQ-1.3), waitlist (REQ-1.5), floor plan (REQ-1.6), reporting (REQ-1.7),
  risk scoring (REQ-1.8) and administration (REQ-1.9) remain planned for W7–W11.

[0.2.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.2.0
[0.1.1]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.1.1
[0.1.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.1.0
