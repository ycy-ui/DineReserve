# Changelog

All notable changes to DineReserve are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
weeks refer to the 14-week plan in [`docs/02-architecture.md`](docs/02-architecture.md) and the Task 1 proposal.

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

[0.1.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.1.0
