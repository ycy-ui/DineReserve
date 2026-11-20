# Changelog

All notable changes to DineReserve are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
weeks refer to the 14-week plan in [`docs/02-architecture.md`](docs/02-architecture.md) and the Task 1 proposal.

## [1.0.0] - 2026-12-28 — v1.0 release

### Added (W14)
- `docs/11-release-notes-v1.0.md`: release notes, requirement coverage, installation and
  upgrade notes, known limitations, pilot-findings triage and the post-1.0 roadmap.
- `docs/12-user-guide.md`: the short manual for the host desk and the duty manager.
- Code-freeze checklist completed and signed; the freeze exception log records the two
  v1.0 items (benchmark table selection, release packaging).

### Fixed
- `bin/bench.php` now benchmarks only bookable tables; previously a 50-iteration run
  could hit a BLOCKED/MAINTENANCE table and abort (found during the v1.0 verification).

### Verification
- 199 tests / 1,311 assertions green locally and in CI; pilot rehearsal 9/9 invariants;
  security baseline 15/15; latency budgets met (p95: availability 0.02 ms, booking
  0.06 ms, report 0.20 ms, floor plan 0.08 ms on the in-memory demo).

## [0.8.0] - 2026-11-20 — Week 13: pilot rehearsal and code freeze

### Added (W13)
- `PilotService`: replays one dinner service on a disposable copy of the demo restaurant —
  12 table bookings across online/phone, waitlist entries, cancellations that release offers,
  offer acceptance and expiry, seating, a walk-in, a no-show, a release, notification dispatch,
  the daily report and a backup/restore check.
- Eight operational invariants are asserted (no double booking, known statuses, report = bookings,
  utilisation bounds, floor plan vs seated bookings, every booking created once, notifications
  accounted for, events recorded) plus the backup restore; a failed check exits non-zero.
- `bin/pilot.php` CLI (`--db`, `--date`, `--backup`, `--report`) generating the pilot record.
- `docs/09-pilot-runbook.md` (scope, roles, checklists, timeline, monitoring, rollback, rehearsal
  record, post-pilot review) and `docs/10-code-freeze.md` (freeze scope, exceptions, v1.0-rc
  checklist, sign-off).
- `tests/PilotTest.php`: 8 tests (199 total).

## [0.7.0] - 2026-11-19 — Week 12: administration and NFR validation (REQ-1.9)

### Added (W12 · REQ-1.9)
- `AdminService`: user creation, role assignment, activation, bcrypt password hashing,
  constant-time admin token, role → capability matrix (administrator / manager / host / viewer).
- Per-restaurant policy settings in the new `settings` table — slot minutes, booking window,
  cut-off, turnover buffer, waitlist offer TTL and maximum party size — with range validation
  and an audit row per change.
- HTTP API: `GET/POST /api/v1/admin/users`, `PATCH /api/v1/admin/users/{id}`,
  `POST /api/v1/admin/login`, `GET/PATCH /api/v1/admin/settings` behind `X-Admin-Token`
  and `X-Actor-Role`.
- CLI: `bin/admin.php` (`list`, `create`, `set-role`, `settings`).

### Added (W12 · non-functional validation)
- `bin/bench.php`: p50/p95/mean/max latency for availability, booking, report and floor plan,
  with budgets that fail the build on regression.
- `bin/security-check.php`: 15 runtime checks — credential storage, authentication,
  authorisation, validation, prepared statements and the audit trail.
- `docs/08-nfr-validation.md`: the 27 NFRs with target, method and evidence.
- 25 new tests (191 total); CI gains an admin/NFR smoke step.

## [0.6.0] - 2026-11-15 — Week 11: reporting and no-show risk (REQ-1.7, REQ-1.8)

### Added (W11 · REQ-1.7 / REQ-1.8)
- `ReportService`: daily occupancy and utilisation (capacity = bookable seats × sittings the service periods
  allow), covers, no-show rate, channel mix (online / phone / walk-in / waitlist) and range totals, all derived
  from the booking rows and events.
- `ReportExporter`: CSV, XLSX and PDF exports written without any runtime package — a minimal OOXML workbook
  (uncompressed ZIP) and a small PDF 1.4 text document, so the deliverable stays dependency-free like the rest
  of the engine.
- `RiskScorer`: an explainable, additive 0–100 no-show score (lead time, weekday, late sitting, party size,
  channel, guest no-show history, special requests), bands HIGH ≥ 70 / MEDIUM ≥ 40, and the rule version
  `risk-v1` stored on every scored booking (`bookings.risk_rule_version`).
- Every new booking is scored by `BookingService::setRiskScorer()`; `bin/report.php --rescore` re-scores a
  range after a rule change, and each score is audited.
- HTTP API: `GET /api/v1/reports/occupancy`, `GET /api/v1/reports/risk`, `POST /api/v1/reports/export`.
- CLI: `bin/report.php` (text table, `--format=xlsx|pdf|csv`, `--out`, `--rescore`).
- 26 new tests (166 total); CI gains a reporting smoke step.

## [0.5.0] - 2026-11-09 — Week 10: floor plan and table statuses (REQ-1.6)

### Added (W10 · REQ-1.6)
- `FloorPlanService`: a live floor-plan snapshot (tables, areas, positions, current and upcoming bookings)
  with `poll_after_seconds = 5` for the host screen.
- Check-in: `CONFIRMED → SEATED` and the table becomes `OCCUPIED` (duplicate/cancelled check-ins rejected).
- Walk-in: a booking is created with `channel = walk_in` and immediately seated.
- No-show: `CONFIRMED → NO_SHOW`, the table goes to `CLEANING` and disappears from availability.
- Release: `OCCUPIED|CLEANING → FREE` and a still-seated booking becomes `COMPLETED`; blocked/maintenance
  tables refuse release.
- Every transition appends a `booking_events` row (`seated`, `no_show`, `released`) and an `audit_log` row,
  and invalidates the availability cache for that date.
- Table statuses documented as `FREE | OCCUPIED | CLEANING | BLOCKED | MAINTENANCE`; availability still
  only offers `FREE` tables.
- HTTP API: `GET /api/v1/floorplan`, `POST …/seat`, `POST …/walk-in`, `POST …/no-show`, `POST …/release`.
- 17 new tests (140 total / 1002 assertions).

## [0.4.0] - 2026-11-08 — Week 9: waitlist and 5-minute offers (REQ-1.5)

### Added (W9 · REQ-1.5)
- `WaitlistService`: join a per-restaurant/per-day queue with strict arrival order and party-size matching,
  and expose the queue position.
- Automatic release: when a confirmed booking is cancelled, `BookingService::cancel()` offers the freed
  table to the first waiter whose party fits — the offer holds the table for **five minutes**.
- Accepting converts the offer into a real booking (`channel = waitlist`) through the normal booking rules,
  so availability is re-checked and the reference/cut-off/e-mails still apply.
- Declining or letting the offer expire passes the slot to the next waiter; `bin/waitlist.php --expire`
  (cron) performs the sweep, and `POST /api/v1/waitlist/expire` exposes it over HTTP.
- Offer notices go out through the W8 `Mailer` port with the deadline and the accept URL;
  every transition appends an `audit_log` row.
- Added `offer_table_id` / `offer_time` to `waitlist_entries` (both dialects) and a `channel` field to
  booking creation (`online | phone | walk_in | waitlist`).
- HTTP API: `POST /api/v1/waitlist`, `GET /api/v1/waitlist/{id}`, `POST …/accept`, `POST …/decline`,
  `POST /api/v1/waitlist/expire`.
- 18 new tests (123 total / 902 assertions).

## [0.3.0] - 2026-11-06 — Week 8: notifications (REQ-1.4)

### Added (W8 · REQ-1.4)
- `NotificationService`: queues the confirmation, the 24 h and the 2 h reminders for a booking, renders
  the five templates (`booking_confirmation`, `booking_reminder_24h`, `booking_reminder_2h`,
  `booking_changed`, `booking_cancelled`) and delivers everything that is due.
- Delivery port `Mailer` with a dependency-free `FileMailer` (writes `storage/mail.log`); the test suite
  binds an in-memory double, so the whole pipeline runs in CI without SMTP credentials.
- Retry policy: a failed delivery is re-scheduled after **1 / 5 / 15 minutes** and becomes `FAILED`
  after the fourth attempt, with `last_error` recorded for triage.
- Reminders follow the booking: `change()` cancels the old reminders and queues new ones,
  `cancel()` cancels the pending reminders, and a cancelled booking never receives a reminder.
- `bin/notify.php` — cron entry point with `--limit` and `--dry-run`; `POST /api/v1/notifications/dispatch`
  and `GET /api/v1/notifications/due` expose the same queue over HTTP.
- 16 new tests (105 total / 844 assertions).

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

[1.0.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v1.0.0
[0.8.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.8.0
[0.7.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.7.0
[0.6.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.6.0
[0.5.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.5.0
[0.4.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.4.0
[0.3.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.3.0
[0.2.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.2.0
[0.1.1]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.1.1
[0.1.0]: https://github.com/ycy-ui/DineReserve/releases/tag/v0.1.0
