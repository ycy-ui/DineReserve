# DineReserve v1.0.0 — release notes

**Release date:** 2026-12-28 (project week 14) · **Tag:** `v1.0.0` · **Previous:** `v1.0-rc1` (2026-12-21), `v0.8.0`

DineReserve is a self-hosted online booking and table-management system for small
restaurants, built as a TastyIgniter extension on a standalone PHP engine. This is
the first production release: all nine functional requirements from the URS are
implemented, the 27 non-functional requirements are validated, and one dinner
service has been rehearsed end-to-end.

## Highlights

| Area | What shipped |
|---|---|
| Booking core | Real-time availability (15-minute grid, 90-day window, turnover buffer), booking creation with idempotency, change and cancel with signed links and audit |
| Automation | Confirmation + 24 h / 2 h reminders with 1/5/15-minute retries, waitlist with 5-minute offers, floor plan with FREE/OCCUPIED/CLEANING/BLOCKED/MAINTENANCE |
| Insight | Occupancy / utilisation / no-show / channel reports with CSV, XLSX and PDF export; explainable 0–100 no-show risk score with rule version |
| Administration | Users, roles and a capability matrix; per-restaurant policy settings (slot, window, cut-off, buffer, offer TTL, max party) with audit |
| Quality | 199 tests / 1,311 assertions, three CI jobs, a pilot rehearsal with nine invariants, latency budgets and a 15-check security baseline |

## Requirement coverage

| Requirement | Status | Evidence |
|---|---|---|
| REQ-1.1 availability search | Done | `AvailabilityService`, 30 tests |
| REQ-1.2 create booking | Done | `BookingService`, 22 tests incl. concurrency |
| REQ-1.3 change / cancel | Done | signed manage links, atomic swap, 25 tests |
| REQ-1.4 confirmation / reminders | Done | `NotificationService`, 16 tests |
| REQ-1.5 waitlist and auto-release | Done | `WaitlistService`, 18 tests |
| REQ-1.6 floor plan and table status | Done | `FloorPlanService`, 17 tests |
| REQ-1.7 occupancy / utilisation reporting + PDF/Excel | Done | `ReportService` + `ReportExporter`, 13 tests |
| REQ-1.8 no-show risk score | Done | `RiskScorer`, 13 tests |
| REQ-1.9 users / roles / policies | Done | `AdminService` + `settings`, 13 tests |

Full mapping: `docs/05-traceability.md`.

## Installation

Requirements: PHP 8.1+ with `pdo_sqlite` (dev) or `pdo_mysql` (deployment); MySQL 8.0
for production; no Composer packages at runtime.

```bash
git clone https://github.com/ycy-ui/DineReserve.git
cd DineReserve
composer install --no-dev          # dev tooling only (PHPUnit); runtime needs nothing
php bin/seed.php                   # creates database/local.sqlite with the demo restaurant
php -S 127.0.0.1:8080 -t . /path/to/router.php   # the router maps /api/* to public/index.php
open http://127.0.0.1:8080/demo.html
```

MySQL deployment:

```bash
export DB_HOST=127.0.0.1 DB_DATABASE=dinereserve DB_USERNAME=dinereserve DB_PASSWORD=***
php bin/seed.php --mysql
```

Set `ADMIN_TOKEN` to a long random value in the deployment environment — the API
rejects admin calls when the header is missing or wrong, and the demo default
(`dev-admin-token`) must never be used in production.

## Upgrade from v0.8.0

- No schema changes since v1.0-rc1; if you came from an earlier release, recreate
  the database with `php bin/seed.php` (the demo data) or apply `database/schema.sql`.
- `settings` defaults are unchanged; existing rows keep their values.
- The benchmark now selects only bookable tables, so `php bin/bench.php` no longer
  fails on the blocked/maintenance tables of the demo floor plan.

## Verification at release

```
vendor/bin/phpunit                    199 tests, 1,311 assertions   OK
php bin/pilot.php                     9/9 invariants, backup restore OK
php bin/bench.php --iterations=50     p95: availability 0.02 ms, booking 0.06 ms, report 0.20 ms, floor 0.08 ms
php bin/security-check.php            15/15 checks
CI                                    3 jobs green + 6 smoke steps
```

## Known limitations

1. **One service rehearsed, no live restaurant yet.** The pilot is a faithful
   simulation (same engine, real floor plan); the live service is the next step
   and its findings go into a 1.0.x patch, not into this tag.
2. **Policy settings are stored and validated but not yet consumed by the engine.**
   The engine uses the compile-time constants so the behaviour stays deterministic;
   wiring `settings` into the services is the first 1.1 item.
3. **The diner demo is a static prototype** (browser storage) that mirrors the
   engine rules; the API is the system of record.
4. **PHP's built-in server is for development only.** Production needs a real web
   server (TastyIgniter runs on Apache/Nginx with PHP-FPM).
5. **Email delivery uses a file mailer in development**; production swaps in an
   SMTP adapter through the `Mailer` port.
6. **The admin screen ships with TastyIgniter** — this release provides the API
   and CLI; the UI arrives with the platform integration.

## Pilot findings and triage (from the W13 rehearsal)

| Finding | Decision | Where |
|---|---|---|
| Benchmark booked blocked/maintenance tables when iterating over all 60 tables | **Fixed** for v1.0.0 | `bin/bench.php` |
| Daily utilisation looks low for a single dinner service (4.3 %) because capacity covers three service periods | **Documented** — the rehearsal targets correctness, not a full house | `docs/09-pilot-runbook.md` |
| Cancelled bookings leave their queued reminders cancelled (12 in the rehearsal) | **Expected** — reminders are cancelled so guests are not reminded about a cancelled booking | `NotificationService` |
| Waitlist offers expire after 5 minutes; a late guest must re-join | **By design** — prevents a table being held indefinitely | `WaitlistService` |
| `settings` not yet consumed by the engine | **Deferred to 1.1** — needs a migration of the constants to a settings reader | roadmap |
| Real pilot not yet run | **Next step** — runbook is ready, feedback becomes a 1.0.x patch | W14+ |

## What's next (post-1.0)

1. Wire `settings` into the services (cut-off, buffer, window, offer TTL, max party).
2. Run the live pilot and fold the findings into `1.0.x`.
3. Ship the TastyIgniter admin screens on top of the existing API.
4. Add the remaining UI polish for the host (table map drag-and-drop) and the diner.

## Support

- Issues: https://github.com/ycy-ui/DineReserve/issues
- Project site: https://ycy-ui.github.io/DineReserve/
- Online demo: https://ycy-ui.github.io/DineReserve/demo.html
