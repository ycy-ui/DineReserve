# Test report — weeks 1–6

Scope of this report: the two requirements implemented so far, **REQ-1.1** (availability search) and
**REQ-1.2** (booking creation), plus the shared infrastructure they depend on. The remaining
requirements and the non-functional verification pass are scheduled for W7–W12 and are listed in
§6 of this report.

## 1. Environment

| Item | Value |
|---|---|
| PHP (CI) | 8.2, extensions `pdo_sqlite`, `sqlite3`, `mbstring`, `dom`, `xml`, `xmlwriter`, `tokenizer` |
| PHP (local authoring) | 8.3.32, static CLI build |
| Database | SQLite in-memory for the suite; MySQL 8.0 for the deployment schema (separate CI job) |
| Test framework | PHPUnit 10.5 — no runtime dependency is added to the engine |
| Time | frozen per test: `FrozenClock('2026-11-02 10:00:00', 'Asia/Macau')` |
| Seed | `Database::seedDemo()`: 1 restaurant, 60 tables, 3 service periods (URS CAP-1.1) |

Determinism measures: every test gets a fresh in-memory database, the clock is injectable, the demo
seed is deterministic, the booking reference is asserted by alphabet/length and not by value, and the
concurrency test synchronises two forked processes on a barrier file.

## 2. How to run

```bash
composer install
vendor/bin/phpunit --do-not-cache-result                      # all 64 tests
vendor/bin/phpunit --filter Availability                      # REQ-1.1 rules
vendor/bin/phpunit --filter Booking                           # REQ-1.2 rules
vendor/bin/phpunit tests/BookingConcurrencyTest.php           # fork-based concurrency
php bin/seed.php && php -S 127.0.0.1:8080 -t public           # manual API verification
```

The same suite runs on every push and pull request in `.github/workflows/ci.yml`
(job “PHP 8.2 · PHPUnit · SQLite”), followed by a live HTTP smoke test.

## 3. Result summary

| Suite | Tests | Assertions | Time | Purpose |
|---|---:|---:|---:|---|
| `tests/InfrastructureTest.php` | 12 | 28 | 0.57 s | schema (both dialects' shape), seed, time arithmetic, clock |
| `tests/AvailabilityServiceTest.php` | 30 | 402 | 1.44 s | **REQ-1.1** rules |
| `tests/BookingServiceTest.php` | 20 | 208 | 0.91 s | **REQ-1.2** rules |
| `tests/BookingConcurrencyTest.php` | 2 | 18 | 0.23 s | **REQ-1.2** race conditions, real processes |
| **Total** | **64** | **656** | **3.07 s** | |

Status: **all green**, no warnings, notices or deprecations (`failOnWarning`/`failOnNotice` enabled in
`phpunit.xml`), so a silent PHP 8.2/8.3 deprecation cannot slip into CI.

## 4. REQ-1.1 — availability search: rule → test

| URS rule (REQ-1.1 §6) | Test(s) |
|---|---|
| Result computed from live data, documented response shape | `testSearchReturnsTheDocumentedContractShape` |
| Slot = average dining duration + 15 min turnover; start times 15 min apart | `testDinnerSlotsCoverTheWholeServicePeriod`, `testSlotsReportAvailabilityPerStartTime` |
| Party size must fit the table capacity | `testCapacityFilterOnlyOffersTablesThatSeatTheParty` |
| Tables out of service or in maintenance are never offered | `testBlockedAndMaintenanceTablesAreNeverOffered` |
| A table already booked for an overlapping time is not offered | `testOverlappingConfirmedBookingRemovesTheTable` |
| Only CONFIRMED / SEATED occupy a table | `testOnlyConfirmedAndSeatedBookingsOccupyATable` |
| Back-to-back bookings are allowed (half-open intervals) | `testBackToBackBookingsDoNotConflict` |
| Date not in the past, not more than 90 days ahead | `testRejectsPastDate`, `testRejectsDateBeyondTheHorizon`, `testAcceptsBoundaryDates` |
| Malformed dates rejected | `testRejectsMalformedDates` |
| Party size 1–20; larger parties go to the telephone | `testRejectsNonPositivePartySize`, `testRejectsPartyLargerThanTwenty`, `testPartyOfTwentyIsAValidQueryWithoutTables` |
| Requested time must be inside a service period | `testRejectsTimeOutsideServicePeriods` |
| The whole slot must end before the service closes (last dinner seating 19:45) | `testLastBookableDinnerStartIsAccepted`, `testRejectsDinnerStartWhoseSlotWouldOverrunClosing`, `testRejectsLunchStartWhoseSlotWouldOverrunClosing` |
| Service-period and area filters | `testServicePeriodFilterNarrowsTheSearch`, `testAreaFilterOnlyOffersTablesInThatArea`, `testUnknownAreaYieldsNoAvailability` |
| 30-second cache, invalidated by a booking | `testCacheHitIsMarkedAndInvalidateForcesARecalculation`, `testInvalidateMakesANewBookingVisible`, `testCacheExpiresAfterTtl`, `testZeroTtlDisablesTheCache` |
| `isTableFree()` used by the booking transaction | `testIsTableFreeWithoutBookings`, `testIsTableFreeDetectsOverlapsAndHonoursBackToBack`, `testIsTableFreeIgnoresCancelledAndNoShowBookings` |
| Unknown restaurant → 404 | `testUnknownRestaurantIsNotFound` |

## 5. REQ-1.2 — booking creation: rule → test

| URS rule (REQ-1.2 §7) | Test(s) |
|---|---|
| Confirmation with unique reference, contract response shape | `testCreatesBookingAndReturnsTheContractShape` |
| All field errors reported at once, no partial booking | `testValidationCollectsEveryFieldErrorAtOnce`, `testValidationAcceptsOnlyMissingEmailAsSingleError` |
| Reference 8 chars, unambiguous alphabet, unique | `testEveryBookingGetsAUniqueUnambiguousReference` |
| Table / restaurant existence, ownership | `testUnknownTableReturnsNotFound`, `testTableOfAnotherRestaurantReturnsNotFound` |
| Table out of service | `testBlockedAndMaintenanceTablesAreUnavailable` |
| Party size vs seats, and vs the online maximum of 20 | `testPartyLargerThanTheTableIsRejected`, `testPartyOverTheOnlineMaximumIsRejected` |
| Deployment supplies the telephone number, nothing fabricated | `testTelephoneHintIsSuppliedByTheDeploymentEnvironment` |
| Whole slot must fit before closing (no booking past 22:00) | `testSlotMustEndBeforeTheServicePeriodCloses` |
| No booking in the past | `testSlotInThePastIsRejected` |
| Date window and service period re-validated under the lock | `testDateOutsideTheBookableWindowIsRejected`, `testTimeOutsideEveryServicePeriodIsRejected` |
| Double booking → 409 with alternatives | `testDoubleBookingReturnsTableTakenWithAlternatives`, `testBackToBackBookingIsAllowed` |
| Idempotent replay: one booking, one event, one notification | `testIdempotentReplayReturnsTheOriginalBooking` |
| E-mail normalised, special requests ≤ 200 chars, optional fields null | `testEmailIsStoredLowerCased`, `testSpecialRequestsLongerThan200CharactersAreRejected`, `testOptionalFieldsDefaultToNull` |
| Concurrent diners cannot take the same table | `testTwoConcurrentDinersCannotBookTheSameTable` |
| Same idempotency token submitted twice concurrently books once | `testConcurrentDoubleSubmitWithTheSameIdempotencyTokenBooksOnce` |

### Concurrency method (why this is a real test)

`:memory:` databases are private to each process, so the test uses a `tempnam()` **file** database:
the parent migrates and seeds it, closes its own connection, then `pcntl_fork()`s two children, each
opening its own PDO and calling the real `BookingService::create()`. A barrier file is written by the
parent so both children enter the transaction at the same instant; children report the outcome through
exit codes (`0` success, `3` TABLE_TAKEN, `4` other `DomainException`, `5` fatal, `9` barrier timeout).
The parent `pcntl_waitpid()`s and asserts the sorted exit codes are exactly `[0, 3]`, then opens a new
connection and asserts the table/date holds exactly one booking and that `bookings`, `booking_events`,
`notification_log` and `idempotency_keys` each hold exactly one row. The test is skipped only when
`pcntl` is unavailable.

## 6. Not covered yet (honest list)

| Item | Status | Planned |
|---|---|---|
| REQ-1.3 change/cancel, REQ-1.4 notifications, REQ-1.5 waitlist, REQ-1.6 floor plan, REQ-1.7 reporting, REQ-1.8 risk score, REQ-1.9 administration | not implemented | W7–W11 |
| **PERF-1.1 … PERF-1.8** response-time measurements | only the availability/booking path is unit-tested, no load test yet | W12 (k6, 95th percentile) |
| **SEC-1.1 … SEC-1.7** (TLS, hashing, session expiry, OWASP Top 10) | password hashing is used for the demo accounts; no formal review yet | W12 |
| **AVA-1.1 … AVA-1.5** (99.5 % availability, backup + restore drill) | not applicable to a unit suite; needs the pilot deployment | W12–W13 |
| **CAP-1.2 / CAP-1.3** growth volumes | year-one volume exists as seed data (CAP-1.1) | W12 |
| MySQL behaviour of the booking transaction (`SELECT … FOR UPDATE`) | the schema is applied and seeded in CI, but the concurrency test runs on SQLite only | W12 (pilot on MySQL) |
| Browser end-to-end automation (Playwright/Selenium) | the demo is verified manually with headless Chrome screenshots; rules are cross-checked against the engine through a Node smoke script | optional, W12 |
| Accessibility audit | basics implemented (labels, focus styles), no automated audit | optional |

## 7. What the CI proves on GitHub

`.github/workflows/ci.yml` runs three jobs for every push and pull request:

1. **PHP 8.2 · PHPUnit · SQLite** — dependency install, `php -l` on every file, the 64 tests above, then
   an HTTP smoke test against the running API (`health` → `availability` → `POST booking` → read it back).
2. **MySQL 8.0 · schema + seed** — the MySQL dialect DDL is applied and the seed is written through the
   same application code, asserting 60 tables and 3 service periods.
3. **Static demo** — `node --check` on every demo script, HTML/SVG parsing, and a guard that the demo
   loads no external resource (so the demo keeps working offline and on GitHub Pages).

Evidence: the Actions history for `main` and for the six week-pull-requests, `v0.1.0` release notes and
the `CHANGELOG.md` entries.
