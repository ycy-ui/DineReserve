# DineReserve

**An online restaurant booking and table management system.**
Course project · Macau University of Science and Technology, School of Computer Science and Engineering · Group 6.

[![CI](https://github.com/ycy-ui/DineReserve/actions/workflows/ci.yml/badge.svg)](https://github.com/ycy-ui/DineReserve/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb3)
![Tests](https://img.shields.io/badge/tests-PHPUnit%2010-3c9)
![Licence](https://img.shields.io/badge/licence-MIT-green)

**Project site:** https://ycy-ui.github.io/DineReserve/
**Live demo (no install, runs in the browser):** https://ycy-ui.github.io/DineReserve/demo.html

---

## What DineReserve is

Small and medium-sized restaurants still take reservations by telephone and record them in a paper
book. During service the host is also the person answering the phone, so bookings are lost; tables are
allocated from memory and occasionally double-booked; diners who never arrive leave a table empty for
the whole evening; a late cancellation cannot be refilled; and managers have no data to plan staffing.
Commercial booking platforms solve some of this, but charge commission per cover and keep the customer
relationship.

DineReserve is a **self-hosted** alternative for a single restaurant: diners see real-time
availability, book in under a minute, join a waitlist when a service is full and receive automatic
reminders; staff see the floor plan, check parties in, mark no-shows and read occupancy reports.

## Problem → solution at a glance

| Problem (Task 1 §1.2) | DineReserve response | Requirement |
|---|---|---|
| Phone overload during service | Online self-service booking | REQ-1.1, REQ-1.2 |
| Manual table allocation errors | Availability engine with conflict detection + table lock | REQ-1.1, REQ-1.2 |
| No-shows, table empty all evening | Automatic reminders, 15-minute grace period, no-show risk score | REQ-1.4, REQ-1.8 |
| Late cancellations cannot be refilled | Waitlist with a 5-minute automatic offer | REQ-1.5 |
| No operational data | Occupancy / utilisation / no-show dashboard | REQ-1.7 |
| Platform commission and lock-in | Open-source, self-hosted on TastyIgniter | URS §3.1 |

### Success metrics (proposed targets, Task 1 Table 1)

| Metric | Current practice | Target |
|---|---|---|
| No-show rate | 10–20% | **< 5%** after reminders and waitlist |
| Online booking share | none / ad hoc | **≥ 70%** of all bookings |
| Time to complete a booking | 3–5 min by phone | **< 60 s** online |
| Covers recovered from cancelled slots | 0 | **+10%** on peak slots |
| Staff time on booking calls | several hours a day | **less than half** |
| Booking page response | not measurable | **< 2 s** |

## Quick start (local)

```bash
# 1. dependencies (PHPUnit only; the engine itself has no runtime dependency)
composer install

# 2. create the SQLite database and seed the demo restaurant (60 tables, 3 service periods)
php bin/seed.php

# 3. run the HTTP API
php -S 127.0.0.1:8080 -t public

# 4. check it works
curl -s http://127.0.0.1:8080/api/v1/health
curl -s "http://127.0.0.1:8080/api/v1/availability?restaurant_id=1&date=2026-11-10&time=19:00&party_size=6"
```

The diner demo is plain HTML/JS — just open `demo.html` in a browser, no server needed
(it also runs on GitHub Pages). The HTTP API is optional for the demo.

## What is implemented today (weeks 1–6)

| Week | Deliverable | Where | Status |
|---|---|---|---|
| W1 | Repository scaffold, PHP 8.1 engine layout, TastyIgniter extension stub | `src/`, `extensions/` | ✅ |
| W2 | CI (3 jobs), PR template, requirement-linked issues | `.github/` | ✅ |
| W3 | Dual-dialect schema, 10 tables, demo seed, ER diagram | `database/`, `docs/diagrams/er.svg` | ✅ |
| W4 | **REQ-1.1** availability search + 30 s cache + unit tests | `src/AvailabilityService.php` | ✅ |
| W5 | **REQ-1.2** booking creation: lock, idempotency, reference, cut-off, concurrency test | `src/BookingService.php` | ✅ |
| W6 | Diner flow demo, HTTP API, context + flow diagrams, API/traceability docs | `demo.html`, `public/`, `docs/` | ✅ |

Weeks 7–14 are planned: change/cancel (REQ-1.3), notifications (REQ-1.4), waitlist (REQ-1.5),
floor plan (REQ-1.6), reporting (REQ-1.7), risk scoring (REQ-1.8), administration (REQ-1.9),
then the non-functional test pass, the pilot service and delivery. See `CHANGELOG.md` and
`docs/05-traceability.md`.

## API

| Method | Endpoint | Purpose | Requirement |
|---|---|---|---|
| GET | `/api/v1/health` | liveness + database driver + server time | — |
| GET | `/api/v1/restaurant` | restaurant profile, service periods, table count | REQ-1.1 |
| GET | `/api/v1/availability` | `?restaurant_id=1&date=YYYY-MM-DD&time=HH:MM&party_size=N[&area=main]` | REQ-1.1 |
| POST | `/api/v1/bookings` | create a booking (JSON body) | REQ-1.2 |
| GET | `/api/v1/bookings/{reference}?email=…` | read a booking (reference + e-mail, URS SEC-1.6) | REQ-1.3 groundwork |

Full request/response examples and the error-code table: [`docs/03-api.md`](docs/03-api.md).
The JSON error envelope is always `{"error": {"code", "message", "details"}}`.

## How it is built

```
public/index.php        thin HTTP layer: parse → service → JSON
src/                    framework-agnostic engine (PHP 8.1+, PDO only)
  AvailabilityService   REQ-1.1  period mapping, capacity/overlap rules, 30 s cache
  BookingService        REQ-1.2  validation, lock, idempotency, reference, cut-off
  Slot                  time arithmetic (half-open intervals, turnover buffer)
  Database              connection factory, dual-dialect migration, demo seed
  Clock                 injectable clock (tests freeze time)
database/               schema.sql (SQLite, tests) + schema-mysql.sql (MySQL 8.0, deployment)
demo.html + assets/     dependency-free static diner demo (GitHub Pages)
docs/                   architecture contract, domain study, API, traceability, SVG diagrams
tests/                  PHPUnit suite (infrastructure, availability, booking, concurrency)
extensions/             TastyIgniter adapter stub (platform integration in W7)
```

Design rules:

- The engine never echoes, never calls `exit`, and expresses every rejection as a `DomainException`
  carrying a stable code and an HTTP status — the API layer stays thin.
- Same SQL for every dialect at the query level; only the DDL differs, and CI applies **both** schemas.
- No runtime Composer dependency, so the URS §3.1 licence constraint (MIT/Apache/BSD/GPL-compatible
  only) is satisfied trivially.

## Tests

```bash
vendor/bin/phpunit --do-not-cache-result        # whole suite
vendor/bin/phpunit --filter Availability        # REQ-1.1 rules
vendor/bin/phpunit --filter Booking             # REQ-1.2 rules
```

CI runs three jobs on every push and pull request:

1. **PHP 8.2 · PHPUnit · SQLite** — `php -l` on every file, full suite, then a live HTTP smoke test
   (health → availability → create booking → read it back).
2. **MySQL 8.0** — applies the MySQL schema and seeds through the application code.
3. **Static demo** — `node --check` on every demo script, HTML/SVG parse, and a guard that the demo
   loads no external resource.

## Team and ownership

| Member | Role | Owns |
|---|---|---|
| 杨澄翼 (1240022943) | Team leader | Booking engine, availability model, table matching (REQ-1.1, REQ-1.2, REQ-1.3) |
| 王恩泽 | Team member | Waitlist escalation, scheduled notifications, occupancy statistics (REQ-1.4, REQ-1.5, REQ-1.7) |
| 谢丰毅 | Team member | Customer booking portal, merchant dashboard, test plan and documentation (REQ-1.6, UI, NFR tests) |

## References

- Task 1 Project Proposal — DineReserve, 2026-10-06.
- Task 2 User Requirements Specification — DineReserve, Group 6, 2026-10-20 (REQ-1.1 … REQ-1.9, 27 NFRs).
- TastyIgniter, open-source restaurant management software — https://github.com/tastyigniter/TastyIgniter
- G. J. Myers, *The Art of Software Testing*, John Wiley & Sons, 2004.
- Predictive Analytics for Appointment Bookings, arXiv:2204.08475, 2022.

## Licence

MIT — see [LICENSE](LICENSE).
