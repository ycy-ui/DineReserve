# DineReserve — Requirements Traceability Matrix

**Document:** `docs/05-traceability.md` · **Owner:** Stream D · **Group:** 6
**Snapshot:** 2026-11-02 (end of W5 / start of W6) · **Scope:** URS REQ-1.1 – REQ-1.9 and a
five-item NFR sample.
**Related:** [domain study](01-domain-study.md) · [architecture](02-architecture.md) ·
[API](03-api.md) · [context](diagrams/context.svg) · [ER](diagrams/er.svg) ·
[booking flow](diagrams/booking-flow.svg) · Task 2 URS (28 pp.).

> **Honesty rule for this matrix.** A row may say **Done** only when it names (a) a real source
> location (file + method) and (b) a real test method file that exercises it. A design that exists
> only as a table or a paragraph is **Planned** or **Partial**, never Done. No test method name is
> invented: every method named below exists in `tests/` as of the snapshot. The URS is not edited by
> this stream; every mismatch is recorded in §5 instead of being silently repaired.

---

## 1. Status legend and snapshot

| Status | Meaning | Evidence required |
|---|---|---|
| **Done** | Implemented in `src/` or `public/` and covered by the named test file in the snapshot. | code location + test file + method names |
| **Partial** | Only the data model / seed groundwork exists; no service, API or UI. | schema/seed location + infrastructure test |
| **Planned** | Not implemented; scheduled in the W7–W13 plan. | planned file + release week |
| **Deferred** | Explicitly out of the W6 pilot scope. | URS / proposal reference |

**W1–W6 snapshot in one line:** only **REQ-1.1** and **REQ-1.2** have implementation *and* tests.
Every other functional requirement is **Planned** for W7–W11; some of them only have W3 database
groundwork (a table/column exists, no service, API, UI or acceptance test).

**How to read a trace row:** URS REQ → implementation (`file::method`) → test method(s) →
status → release week. The URS section/page is given so a reviewer can check the requirement text
without guessing.

---

## 2. Functional requirement matrix (REQ-1.1 – REQ-1.9)

| REQ | URS § / p. | Requirement (short) | Implementation (`file::method`) | Unit tests (actual method names) | Status | Release |
|---|---|---|---|---|---|---|
| **REQ-1.1** | §6 / p.11 | Search restaurant and table availability | `src/AvailabilityService.php::search()`, `::invalidate()`, `::isTableFree()`; `public/index.php` `GET /api/v1/availability`; time rules in `src/Slot.php`; seed in `src/Database.php::seedDemo()` | `tests/AvailabilityServiceTest.php` — 30 methods; representative: `testSearchReturnsTheDocumentedContractShape`, `testCapacityFilterOnlyOffersTablesThatSeatTheParty`, `testBlockedAndMaintenanceTablesAreNeverOffered`, `testOverlappingConfirmedBookingRemovesTheTable`, `testBackToBackBookingsDoNotConflict`, `testRejectsPastDate`, `testRejectsTimeOutsideServicePeriods`, `testAreaFilterOnlyOffersTablesInThatArea`, `testCacheHitIsMarkedAndInvalidateForcesARecalculation`, `testIsTableFreeDetectsOverlapsAndHonoursBackToBack` | **Done** (W4; verified 2026-11-02) | W6 |
| **REQ-1.2** | §7 / p.13 | Create a booking (< 60 s, unique reference, no double booking) | `src/BookingService.php::create()` (validation, lock, idempotency, reference, cut-off, event, notification log); `public/index.php` `POST /api/v1/bookings` | `tests/BookingServiceTest.php` — 20 methods; `tests/BookingConcurrencyTest.php` — 2 methods. Representative: `testCreatesBookingAndReturnsTheContractShape`, `testValidationCollectsEveryFieldErrorAtOnce`, `testDoubleBookingReturnsTableTakenWithAlternatives`, `testIdempotentReplayReturnsTheOriginalBooking`, `testEveryBookingGetsAUniqueUnambiguousReference`, `testTwoConcurrentDinersCannotBookTheSameTable`, `testConcurrentDoubleSubmitWithTheSameIdempotencyTokenBooksOnce` | **Done** (W5; verified 2026-11-02) | W6 |
| **REQ-1.3** | §8 / p.14 | Change or cancel a booking before the cut-off | `src/BookingService.php::change()`, `::cancel()`, `::issueManageToken()`, `::verifyManageToken()` (HMAC-signed link, atomic table move, cut-off enforcement, audit rows `changed` / `cancelled`); `public/index.php` `POST …/change`, `POST …/cancel`, `POST …/manage-token`, `GET …/manage` | `tests/BookingChangeTest.php` — 25 methods; representative: `testChangeMovesTheBookingToANewDateAndTime`, `testChangeToAnotherTableReleasesTheOldOne`, `testChangeAfterCutoffIsRejectedAndLeavesTheBookingIntact`, `testChangeToATakenSlotReturnsAlternatives`, `testTamperedManageTokenIsRejected`, `testExpiredManageTokenIsRejected`, `testCancelMarksTheBookingCancelledAndReleasesTheTable`, `testCancelIsIdempotent` | **Done** (W7; verified 2026-11-04) | W7 |
| **REQ-1.4** | §9 / p.16 | Automatic confirmation, reminder (24 h / 2 h) and cancellation notices | **Planned:** `extensions/dinereserve/notification/` (templates, queue, scheduler, retry 1/5/15 min). Groundwork only: `notification_log` table + a `QUEUED` row written inside `BookingService::create()` | **Planned:** `tests/NotificationTest.php` | **Planned** (W8; `notification_log` table + queued row exist, delivery/scheduling not implemented) | W8 |
| **REQ-1.5** | §10 / p.17 | Waitlist and automatic table release / 5-minute offer | **Planned:** `src/WaitlistService.php` (strict arrival order, party-size match, offer expiry, conversion to booking). Groundwork only: `waitlist_entries` table | **Planned:** `tests/WaitlistServiceTest.php` | **Planned** (W9; `waitlist_entries` table exists) | W9 |
| **REQ-1.6** | §11 / p.19 | Floor plan and table-status management (check-in, walk-in, no-show, release) | **Planned:** `extensions/dinereserve/floorplan/`. Groundwork only: `tables.status`, `booking_events.event_type` (`seated` / `no_show` / `released`) | **Planned:** `tests/FloorplanTest.php` | **Planned** (W10; `tables.status` + `booking_events` columns exist) | W10 |
| **REQ-1.7** | §12 / p.20 | Occupancy / utilisation / no-show / channel reporting with PDF + Excel export | **Planned:** `ReportService` (aggregation from booking/dining events; exports). Groundwork only: `booking_events`, `tables`, `notification_log` | **Planned:** `tests/ReportServiceTest.php` | **Planned** | W11 |
| **REQ-1.8** | §13 / p.22 | 0–100 no-show risk score, HIGH flag, rule version stored | **Planned:** `RiskScorer` (rule-based, explainable, rule version persisted). Groundwork only: `bookings.risk_score` column (written as `NULL` by `BookingService`) | **Planned:** `tests/RiskScorerTest.php` | **Planned** (column exists) | W11 |
| **REQ-1.9** | §14 / p.23 | Restaurant, user, role and policy administration | **Planned:** admin UI + role-based access. Groundwork: `database/schema.sql` (`users`, `roles`, `user_roles`, `restaurants`, `service_periods`, `tables`, `audit_log`) and `src/Database.php::seedDemo()` (roles diner/host/manager/administrator, three demo users) | Groundwork tests: `tests/InfrastructureTest.php::testSchemaCreatesEveryTable`, `::testDemoSeedMatchesYearOneCapacity` | **Planned** (W11 feature; W3 schema/seed groundwork only, covered by `InfrastructureTest`) | W3 groundwork, W11 feature |

**Planned requirements (explicit list):** **REQ-1.4 (W8), REQ-1.5 (W9), REQ-1.6 (W10),
REQ-1.7 (W11), REQ-1.8 (W11), REQ-1.9 (W11 feature; W3 schema/seed groundwork).**
**REQ-1.1**, **REQ-1.2** and **REQ-1.3** are Done as of W7 (2026-11-04).

---

## 3. Test evidence for the two implemented requirements

The complete test inventory at the snapshot: `AvailabilityServiceTest.php` 30 test methods,
`BookingServiceTest.php` 20, `BookingConcurrencyTest.php` 2, `InfrastructureTest.php` 9 (one method
is a data provider with four rows). The list below maps the rule groups in
[architecture §8](02-architecture.md#8-testing) to real methods.

### 3.1 REQ-1.1 — `AvailabilityServiceTest.php` (30 methods)

| Rule group | Test methods |
|---|---|
| Contract / shape | `testSearchReturnsTheDocumentedContractShape` |
| Slots cover the service period | `testDinnerSlotsCoverTheWholeServicePeriod`, `testSlotsReportAvailabilityPerStartTime`, `testLastBookableDinnerStartIsAccepted`, `testRejectsDinnerStartWhoseSlotWouldOverrunClosing`, `testRejectsLunchStartWhoseSlotWouldOverrunClosing` |
| Capacity / blocked / maintenance | `testCapacityFilterOnlyOffersTablesThatSeatTheParty`, `testBlockedAndMaintenanceTablesAreNeverOffered`, `testOnlyConfirmedAndSeatedBookingsOccupyATable` |
| Conflict / back-to-back | `testOverlappingConfirmedBookingRemovesTheTable`, `testBackToBackBookingsDoNotConflict` |
| Date / time / party validation | `testRejectsPastDate`, `testRejectsDateBeyondTheHorizon`, `testAcceptsBoundaryDates`, `testRejectsMalformedDates`, `testRejectsNonPositivePartySize`, `testRejectsPartyLargerThanTwenty`, `testPartyOfTwentyIsAValidQueryWithoutTables`, `testRejectsTimeOutsideServicePeriods`, `testUnknownRestaurantIsNotFound` |
| Filters | `testServicePeriodFilterNarrowsTheSearch`, `testAreaFilterOnlyOffersTablesInThatArea`, `testUnknownAreaYieldsNoAvailability` |
| 30 s cache + invalidation | `testCacheHitIsMarkedAndInvalidateForcesARecalculation`, `testInvalidateMakesANewBookingVisible`, `testCacheExpiresAfterTtl`, `testZeroTtlDisablesTheCache` |
| `isTableFree()` (used under the booking lock) | `testIsTableFreeWithoutBookings`, `testIsTableFreeDetectsOverlapsAndHonoursBackToBack`, `testIsTableFreeIgnoresCancelledAndNoShowBookings` |

### 3.2 REQ-1.2 — `BookingServiceTest.php` (20) + `BookingConcurrencyTest.php` (2)

| Rule group | Test methods |
|---|---|
| Happy path / contract | `testCreatesBookingAndReturnsTheContractShape`, `testEmailIsStoredLowerCased`, `testOptionalFieldsDefaultToNull` |
| Validation aggregation | `testValidationCollectsEveryFieldErrorAtOnce`, `testValidationAcceptsOnlyMissingEmailAsSingleError`, `testSpecialRequestsLongerThan200CharactersAreRejected` |
| Table existence / status / capacity | `testUnknownTableReturnsNotFound`, `testTableOfAnotherRestaurantReturnsNotFound`, `testBlockedAndMaintenanceTablesAreUnavailable`, `testPartyLargerThanTheTableIsRejected`, `testPartyOverTheOnlineMaximumIsRejected`, `testTelephoneHintIsSuppliedByTheDeploymentEnvironment` |
| Date / time / cut-off window | `testSlotMustEndBeforeTheServicePeriodCloses`, `testSlotInThePastIsRejected`, `testDateOutsideTheBookableWindowIsRejected`, `testTimeOutsideEveryServicePeriodIsRejected` |
| Conflict (409) | `testDoubleBookingReturnsTableTakenWithAlternatives`, `testBackToBackBookingIsAllowed` |
| Idempotency / reference | `testIdempotentReplayReturnsTheOriginalBooking`, `testEveryBookingGetsAUniqueUnambiguousReference` |
| Concurrency (two processes / two connections) | `testTwoConcurrentDinersCannotBookTheSameTable`, `testConcurrentDoubleSubmitWithTheSameIdempotencyTokenBooksOnce` |

**Test execution note.** The suite and its W6 execution summary (64 test cases, PHPUnit 10.5) are
recorded in [`docs/04-test-report.md`](04-test-report.md); this matrix references the method names
that report executes. The repository does not commit `vendor/` (normal PHP project layout), so to
re-run locally:

```bash
composer install
vendor/bin/phpunit
# focused:
vendor/bin/phpunit --filter Availability
vendor/bin/phpunit tests/BookingConcurrencyTest.php
```

W6 status is therefore "implemented + tests written and recorded"; formal acceptance sign-off and
the remaining NFR evidence belong to W12.

---

## 4. NFR sample (five requirements)

The URS contains 27 NFRs. These five are sampled because they are the ones the pilot acceptance is
most likely to challenge first; the remaining NFRs are tracked in the W12 test report.

| NFR | URS target (abridged, verbatim key phrase) | Verification method | Evidence / planned evidence | Status |
|---|---|---|---|---|
| **PERF-1.3** | "Time to return an availability search result for a date, time and party size" — **2 seconds at the 95th percentile** (URS §16.1, p.26) | Load test `GET /api/v1/availability` with the CAP-2.1 year-one load (20 concurrent diner searches); record p50/p95/p99 and error rate over ≥ 10 minutes; report cache hit/miss separately. | Planned k6 script `tests/perf/availability.js` + result table in the W12 extension of `docs/04-test-report.md`. | **Planned** (W12) |
| **SEC-1.1** | No credential/personal data in plain text; browser–server uses **HTTPS with TLS 1.2 or later**; DB connection TLS where the host supports it (URS §16.2, p.26–27) | Deployment check: `curl` forced to TLS 1.2+ and TLS 1.0/1.1 rejected; scan certificate/protocols (testssl.sh or equivalent); confirm HTTPS-only redirect; verify MySQL `ssl`/`require_secure_transport` where supported. Record result in the SEC checklist. | Planned W12 SEC self-check table + deployment notes; router currently sets CORS only (TLS terminates at the web server). | **Planned** (W12) |
| **SEC-1.2** | Passwords stored only as **salted hashes** from a current password-hashing algorithm; no reversible storage (URS §16.2, p.26) | Code review every write path (`grep -R password_hash`); inspect the database for plaintext; confirm the demo accounts use `password_hash(..., PASSWORD_DEFAULT)`; add a regression test that a seeded hash verifies and is not equal to the password. | Current evidence: `src/Database.php::seedDemo()` hashes `DemoPass123!` before insert. Authentication/roles arrive with REQ-1.9 (W11); the hash regression test is planned W12. | **Partial** (seed path only) |
| **AVA-1.5** | **Daily full database backup, kept 30 days; restore demonstrated before the pilot** (URS §16.3, p.27) | Scheduled backup job (`mysqldump`/SQLite copy) + retention check; perform a real restore into a staging database before the pilot and record wall-clock restore time and row-count comparison. | Planned W12 backup + restore drill record; no backup job exists in the repository snapshot. | **Planned** (W12) |
| **CAP-1.1** | Year one: **1 restaurant, 60 tables, 500 bookings/month, 3 service periods/day, 18 months retained history** (URS §16.4, p.28) | (a) seed/DDL assertions for 1 restaurant, 60 tables, 3 periods; (b) volume test: generate 18 months × 500 bookings/month into SQLite/MySQL, run availability + booking P95 and index checks; (c) retention check for history queries. | (a) already covered by `InfrastructureTest::testDemoSeedMatchesYearOneCapacity` and `::testTableCapacitiesCycleAndAreUnique` (Done). (b) planned W12. | **Partial** (data model/seed Done; volume test **Planned** W12) |

> The full URS NFR list is 27 entries (PERF-1.1–1.8, SEC-1.1–1.7, AVA-1.1–1.5, CAP-1.1–1.3,
> CAP-2.1–2.3, CAP-3.1). This file deliberately samples five, per the review request; the W12 test
> report must cover the rest.

---

## 5. Known gaps in the Task 2 URS

These are recorded as found; **they are not silently fixed here** because the URS is a submitted
document outside this documentation stream. Each gap names the response taken in this repository.

| # | Gap | Evidence in the URS | Response in this repository | Status |
|---|---|---|---|---|
| **G1** | The context diagram is a caption with no figure. | URS §1.4 and §2.1 both introduce "Figure 1: Context diagram of DineReserve…", but the page carries only the caption; the 28-page document contains no diagram other than the page logo. | [`docs/diagrams/context.svg`](diagrams/context.svg) — real SVG with the system boundary, four roles, four external systems and the protocols. | **Closed in repo**; URS text still needs the figure embedded in its next revision. |
| **G2** | "Figure 2" is referenced but never exists. | URS §1.2: "Data flow diagrams and use case diagrams are drawn using UML notation and are referenced from Figure 1 and Figure 2." No Figure 2 caption or drawing appears anywhere. | Two supporting figures created: [`docs/diagrams/er.svg`](diagrams/er.svg) (data model, 10 tables) and [`docs/diagrams/booking-flow.svg`](diagrams/booking-flow.svg) (booking / no-show / waitlist activity flow). | **Closed in repo**; the URS cross-reference must be corrected in its next revision (no "Figure 2" exists to point at). |
| **G3** | The Trace column references undefined IDs B1–B5. | URS §5 "Trace" column: REQ-1.1–REQ-1.9 point to **B1–B5**; the Task 1 proposal defines its business objectives as **(a)–(e)**. Neither document defines B1–B5. | No B1–B5 mapping is invented. The pain-point → REQ mapping in [domain study §5](01-domain-study.md#5-pain-points--requirement-map) is used instead; the open item is tracked in [domain study §9](01-domain-study.md#9-open-items-team-fill-in-checklist). | **Open** — requires the URS author / customer team to publish the B1–B5 definitions or replace the column with (a)–(e). |
| **G4** | Acceptance testing is promised but absent. | URS §1.1: "A requirement is considered satisfied only if the corresponding acceptance test in Section 6 passes." Section 6 is the REQ-1.1 specification; the document contains no acceptance tests. | Executable evidence lives in `tests/AvailabilityServiceTest.php` and `tests/BookingServiceTest.php` (REQ-1.1/1.2) and is traced in §3; acceptance tests for REQ-1.3–1.9 are planned in W7–W12. | **Partially addressed** (W6 requirements have executable tests); the URS sentence is still wrong and must be corrected. |
| **G5** | Reference/citation anchors are loose. | URS §1.6 lists references [1]–[9], but the body text (extracted text) carries no in-line citation anchors tying a claim to [4]–[9]; the Task 1 README lists a shorter, partially different reference list. | This matrix does not invent citation mappings. Action: add in-text anchors in the URS revision and align [4]–[9] with the README reference list. | **Open** — documentation action in the next URS revision. |

> Because G1 and G2 are only "closed in repo", the URS itself remains non-conforming until its next
> revision. That is the honest status a reviewer should see.

---

## 6. Diagram → requirement traceability

| Requirement | Diagram element that makes it reviewable |
|---|---|
| REQ-1.1 | `booking-flow.svg`: "Search availability" → "Results" → "Table + slot chosen?"; `context.svg`: Diner actor; `er.svg`: `bookings`, `tables` |
| REQ-1.2 | `booking-flow.svg`: "Fill guest details" → "Submit booking" → "TABLE_TAKEN (409)?" → "Booking CONFIRMED"; `er.svg`: `bookings`, `idempotency_keys` |
| REQ-1.3 | `booking-flow.svg`: "Cancel / change (REQ-1.3)" → "Release table" |
| REQ-1.4 | `booking-flow.svg`: "Confirmation e-mail" → "24 h + 2 h reminders"; `context.svg`: SMTP e-mail gateway; `er.svg`: `bookings` (event source; `notification_log` exists in the schema but is not drawn) |
| REQ-1.5 | `booking-flow.svg`: "Release table" → "Promote waitlist (REQ-1.5)" and the "Guest arrives?" no-show branch; `er.svg`: `waitlist_entries` |
| REQ-1.6 | `booking-flow.svg`: "Host check-in → SEATED" → "Service complete"; `context.svg`: Host actor; `er.svg`: `booking_events` |
| REQ-1.7 | `context.svg`: Manager actor; `booking-flow.svg`: "Service complete"; `er.svg`: `booking_events` |
| REQ-1.8 | `context.svg`: Manager/Host actors; `er.svg`: `bookings.risk_score` (column not drawn as a row, noted in schema) |
| REQ-1.9 | `context.svg`: Administrator actor; `er.svg`: `users`, `roles`, `user_roles` (N:M via associative table) |

> ER note: `er.svg` shows only key columns to stay readable. `bookings.risk_score`,
> `notification_log` and `audit_log` are omitted from the boxes but exist in
> `database/schema.sql`; they are listed here so the omission is intentional, not a gap.

---

## 7. Maintenance rules

1. **Status only moves forward with evidence.** Add the code location and the test method in the
   same pull request that changes the row; do not pre-mark a row Done.
2. **Test names are identifiers.** If a test is renamed or split, update this matrix in the same
   commit; `docs/05-traceability.md` is the index reviewers use.
3. **Planned weeks are commitments, not history.** When a planned row slips, keep the old week and
   add the new one (`W9 → W10`) rather than overwriting; the review can then see the slip.
4. **NFR evidence lives in artefacts.** Each NFR row must point at a command/script and a result
   table (W12 report), not at a claim.
5. **Known gaps stay visible until the URS is revised.** Do not delete a G-item because a diagram
   was created; mark "closed in repo, open in URS" as above.
6. **Trace links:** URS REQ ID ↔ this matrix ↔ implementation ↔ test ↔ release tag (`v0.1`…`v1.0`).
   The domain study owns the requirement elicitation side; this file owns the implementation side.

### Verification commands used for this snapshot

```bash
# syntax of every PHP file (works without Composer)
for f in src/*.php tests/*.php public/*.php bin/*.php bootstrap/*.php; do php -l "$f"; done

# full suite (after Composer install; vendor/ is not committed)
composer install && vendor/bin/phpunit
```

At the snapshot the PHP source passes `php -l`; the PHPUnit suite is authored but requires
`composer install` (no `vendor/` is committed). The W12 release must attach the executed suite and
the five NFR results to the W12 extension of `docs/04-test-report.md`.
