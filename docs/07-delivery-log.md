# Delivery log and plan-to-artefact mapping

> **Why this file exists.** The W1–W6 deliverables in this repository were produced in **one
> concentrated iteration on 2026-11-02**, not week by week. Every commit therefore carries the real
> date **2026-11-02** and no commit date was altered, backdated or rewritten. This page states that
> plainly and maps each *planned* week to the artefact that satisfies it, so that a reader can still
> see which deliverable belongs to which part of the plan.

## 1. Facts

| Item | Value |
|---|---|
| Course project | DineReserve — online restaurant booking and table management system, Group 6 |
| Task 1 (project proposal) | submitted 2026-10-06 |
| Task 2 (user requirements specification) | submitted 2026-10-20 |
| W1–W6 implementation window | **2026-11-02, ~18:00–19:10 (Asia/Macau)** |
| Commits on `main` | 15 (W1–W6 plus the CI, changelog and group-number follow-ups) |
| Pull requests merged | 8 |
| Releases | `v0.1.0` (2026-11-02 18:27), `v0.1.1` (2026-11-02 18:49) |
| Test suite | 64 tests / 656 assertions, green |
| CI | 3 jobs, green on `main` and on every pull request |

**What is real:** the code, the tests, the CI pipeline, the pull requests and the releases all exist
and are verifiable now. The engine really implements REQ-1.1 and REQ-1.2 and the demo really runs.

**What is not real:** a six-week development cadence. The 14-week plan promised work in weeks 1–6; that
work was delivered after the fact, in a single iteration. Nothing was backdated to look otherwise.

## 2. Planned week → delivered artefact

| Planned week | Planned deliverable (Task 1 §3.2) | Delivered as | Commit | PR | Completed |
|---|---|---|---|---|---|
| W1 | Domain study and setup: fork/install the open-source base, project site | Engine skeleton, Composer/PHPUnit tooling, TastyIgniter extension stub, licence | [`2a06316`](https://github.com/ycy-ui/DineReserve/commit/2a06316) | [#15](https://github.com/ycy-ui/DineReserve/pull/15) | 2026-11-02 18:24 |
| W2 | — (engineering baseline, not in the original plan) | Three-job CI pipeline, PR template, milestones/issues/labels | [`faf43ab`](https://github.com/ycy-ui/DineReserve/commit/faf43ab) | [#20](https://github.com/ycy-ui/DineReserve/pull/20) | 2026-11-02 18:47 |
| W3 | P2 core booking (part 1): `availabilityModel`, `bookingEngine` groundwork | Dual-dialect schema (10 tables), demo seed (CAP-1.1), `Slot`/`Clock`/`DomainException`, ER diagram | [`1d60f58`](https://github.com/ycy-ui/DineReserve/commit/1d60f58) | [#16](https://github.com/ycy-ui/DineReserve/pull/16) | 2026-11-02 18:26 |
| W4 | P2 core booking (part 2) | **REQ-1.1** availability engine, 30 tests / 402 assertions | [`76599ae`](https://github.com/ycy-ui/DineReserve/commit/76599ae) | [#17](https://github.com/ycy-ui/DineReserve/pull/17) | 2026-11-02 18:26 |
| W5 | P2 core booking (part 3) | **REQ-1.2** booking engine, 22 tests incl. real-process concurrency | [`817c59f`](https://github.com/ycy-ui/DineReserve/commit/817c59f) | [#18](https://github.com/ycy-ui/DineReserve/pull/18) | 2026-11-02 18:27 |
| W6 | P3 automation starts (waitlist, notifications) — *pulled forward*: the diner flow had to exist first | Diner demo, HTTP API, context/ER/flow diagrams, domain study, API reference, traceability, test report | [`ca6e034`](https://github.com/ycy-ui/DineReserve/commit/ca6e034) | [#19](https://github.com/ycy-ui/DineReserve/pull/19) | 2026-11-02 18:27 |
| — | Release checkpoint | `v0.1.0` — weeks 1–6 content | tag `979c79b` | — | 2026-11-02 18:28 |
| — | Follow-up found by CI | MySQL `DATETIME` fix + pipeline | [`faf43ab`](https://github.com/ycy-ui/DineReserve/commit/faf43ab) | [#20](https://github.com/ycy-ui/DineReserve/pull/20) | 2026-11-02 18:47 |
| — | Release checkpoint | `v0.1.1` — CI + MySQL fix | tag `e35cb5c` | — | 2026-11-02 18:49 |

Per-deliverable detail is in [`CHANGELOG.md`](../CHANGELOG.md); the requirement-to-code-to-test matrix
is in [`05-traceability.md`](05-traceability.md).

## 3. What each week actually produced (evidence)

| Deliverable | Files | Evidence |
|---|---|---|
| W1 scaffold | `src/`, `bootstrap/`, `composer.json`, `phpunit.xml`, `extensions/dinereserve/booking/` | `php -l` clean; autoloading works without Composer |
| W2 CI | `.github/workflows/ci.yml`, `.github/PULL_REQUEST_TEMPLATE.md` | 3 green jobs on `main`; run 41431642140 |
| W3 data model | `database/schema.sql`, `database/schema-mysql.sql`, `src/Database.php`, `bin/seed.php` | `php bin/seed.php` → 1 restaurant / 60 tables / 3 periods; 12 infrastructure tests |
| W4 REQ-1.1 | `src/AvailabilityService.php` | 30 tests; the same rule set mirrored in the browser demo |
| W5 REQ-1.2 | `src/BookingService.php` | 22 tests, one of them forking two processes to prove a double booking is impossible |
| W6 diner flow | `demo.html`, `assets/demo/`, `public/index.php`, `docs/diagrams/*.svg`, `docs/01`, `03`, `04`, `05` | headless-Chrome walkthrough 40/40 steps, API smoke test in CI |

## 4. Schedule impact and the revised plan

The Task 1 proposal commits to 14 weeks (P1 W1–2, P2 W3–5, P3 W6–9, P4 W10–12, P5 W13–14). Because
weeks 1–6 were compressed into one iteration, the remaining work now runs from **W7** with roughly nine
weeks left in the original window (assuming W1 started 2026-09-29, W14 would end 2027-01-04).

| Week | Dates | Scope | Tracking |
|---|---|---|---|
| W7 | 2026-11-03 → 11-09 | **REQ-1.3** change / cancel a booking (signed link, atomic move, audit trail) — **done 2026-11-04**, 25 tests | [#7](https://github.com/ycy-ui/DineReserve/issues/7) |
| W8 | 2026-11-10 → 11-16 | **REQ-1.4** confirmation, 24 h / 2 h reminders, retries, notification log | [#8](https://github.com/ycy-ui/DineReserve/issues/8) |
| W9 | 2026-11-17 → 11-23 | **REQ-1.5** waitlist with 5-minute automatic offers | [#9](https://github.com/ycy-ui/DineReserve/issues/9) |
| W10 | 2026-11-24 → 11-30 | **REQ-1.6** floor plan and table-status management | [#10](https://github.com/ycy-ui/DineReserve/issues/10) |
| W11 | 2026-12-01 → 12-07 | **REQ-1.7 / REQ-1.8** occupancy reporting and no-show risk score | [#11](https://github.com/ycy-ui/DineReserve/issues/11) |
| W12 | 2026-12-08 → 12-14 | **REQ-1.9** administration + **NFR verification** (PERF/SEC/AVA/CAP) | [#12](https://github.com/ycy-ui/DineReserve/issues/12), [#13](https://github.com/ycy-ui/DineReserve/issues/13) |
| W13 | 2026-12-15 → 12-21 | Pilot service with the partner restaurant, code freeze | [#14](https://github.com/ycy-ui/DineReserve/issues/14) |
| W14 | 2026-12-22 → 12-28 | Documentation, demo, delivery | [#14](https://github.com/ycy-ui/DineReserve/issues/14) |

From W7 onward the cadence is real: one issue, one branch, one pull request per week, merged only when
the three CI jobs are green.

## 5. How to verify this log

```bash
# every commit carries the real date 2026-11-02 (no backdating)
git log --format='%h  %ad  %s' --date=iso

# the pull requests that landed it, with GitHub-side merge timestamps
gh pr list --state merged --json number,title,mergedAt

# the pipeline runs (GitHub-side timestamps, not editable from a local clone)
gh run list --workflow=ci.yml --limit 20
```

If a reviewer needs the work to have happened weekly, the honest answer is in this file: it did not.
What the team can show instead is a complete, tested, CI-verified implementation of the first two
requirements plus a plan for the remaining eight weeks.
