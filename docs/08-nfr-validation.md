# Non-functional requirements — validation report (W12)

Twenty-seven non-functional requirements from the URS §2 are checked by
`vendor/bin/phpunit`, `bin/bench.php` and `bin/security-check.php`, so each one is
repeatable rather than a one-off manual observation. Budgets are generous on
purpose: CI runners are shared, and the check is meant to catch structural
regressions (an accidental N+1 query, a missing prepared statement) rather than to
produce benchmark numbers.

Run everything locally:

```bash
vendor/bin/phpunit --do-not-cache-result     # 191 tests
php bin/bench.php --iterations=100           # latency budgets
php bin/security-check.php                   # 15 runtime security checks
```

## Summary

| ID | Category | Requirement | Target | How it is checked | Result |
|---|---|---|---|---|---|
| NFR-P1 | Performance | Availability search latency | p95 ≤ 250 ms local / ≤ 500 ms CI | `bin/bench.php`, `NfrTest::testAvailabilitySearchMeetsItsLatencyBudget` | **Pass** |
| NFR-P2 | Performance | Booking creation latency | p95 ≤ 400 ms local / ≤ 800 ms CI | `bin/bench.php`, `NfrTest::testBookingCreationMeetsItsLatencyBudget` | **Pass** |
| NFR-P3 | Performance | Monthly report generation | p95 ≤ 500 ms local / ≤ 1000 ms CI | `bin/bench.php`, `NfrTest::testReportGenerationMeetsItsLatencyBudget` | **Pass** |
| NFR-P4 | Performance | Floor-plan snapshot | p95 ≤ 300 ms | `bin/bench.php` | **Pass** |
| NFR-S1 | Concurrency | Two servers must never double-book a table | 0 double bookings under a barrier race | `BookingConcurrencyTest` (fork + barrier file) | **Pass** |
| NFR-S2 | Capacity | 60 tables / 3 service periods / 1,200 covers per day | Capacity = bookable seats × sittings | `NfrTest::testCapacityIsAtLeastTheSeatCount` | **Pass** |
| NFR-S3 | Scale | Booking window up to 90 days | Rejects dates outside the window | `AvailabilityServiceTest`, `BookingServiceTest::testDateOutsideTheBookableWindowIsRejected` | **Pass** |
| NFR-S4 | Time zone | Every time calculation in the restaurant zone | Asia/Macau; no server-local leakage | `Slot`, `InfrastructureTest`, all date tests via `FrozenClock` | **Pass** |
| NFR-Q1 | Testability | Automated suite above 150 tests, always green | 191 tests / 1,271 assertions | `vendor/bin/phpunit` | **Pass** |
| NFR-Q2 | CI | Every PR gated by CI | 3 jobs + 6 smoke steps | `.github/workflows/ci.yml` | **Pass** |
| NFR-Q3 | Portability | Tests on SQLite, deployment on MySQL 8.0 | Same 12-table schema in both dialects | `InfrastructureTest`, CI MySQL job | **Pass** |
| NFR-Q4 | Dependencies | No runtime Composer packages | `composer.json` has no `require` | CI runs on a bare PHP install | **Pass** |
| NFR-A1 | Availability | A database backup can be restored and reopened | Copy the SQLite file, reopen, count rows | `NfrTest::testDatabaseBackupCanBeRestoredAndReopened` | **Pass** |
| NFR-A2 | Traceability | Every state change leaves a record | `booking_events` + `audit_log` rows | `NfrTest::testAuditTrailRecordsAdministrativeActions`, event tests | **Pass** |
| NFR-A3 | Repeatability | A repeated request must not duplicate a booking | Returns the original booking | `BookingServiceTest` idempotency tests | **Pass** |
| NFR-SEC1 | Security | Passwords are stored hashed | bcrypt/argon2 via `password_hash()` | `security-check.php`, `NfrTest::testPasswordsAreNeverStoredInPlaintext` | **Pass** |
| NFR-SEC2 | Security | The admin credential is compared in constant time | `hash_equals()` | `AdminServiceTest::testAdminTokenIsCheckedInConstantTime` | **Pass** |
| NFR-SEC3 | Security | Least privilege by role | Capability matrix (administrator / manager / host / viewer) | `AdminServiceTest::testCapabilityMatrixEnforcesLeastPrivilege` | **Pass** |
| NFR-SEC4 | Security | Invalid input is reported completely, once | One 422 with every field error | `AdminServiceTest`, `BookingServiceTest` | **Pass** |
| NFR-SEC5 | Security | SQL injection is impossible | Prepared statements only | `security-check.php`, `NfrTest::testPreparedStatementsTreatInjectionInputAsData` | **Pass** |
| NFR-SEC6 | Security | Unknown references do not leak data | 404 with empty details | `security-check.php` | **Pass** |
| NFR-SEC7 | Security | One error envelope | `{error:{code,message,details}}` | `index.php` handlers, API smoke | **Pass** |
| NFR-R1 | Reliability | A failing notification does not block the batch | Retries 1/5/15 min, then FAILED | `NotificationServiceTest` | **Pass** |
| NFR-R2 | Reliability | Waitlist accept is atomic | One offer → one booking | `WaitlistServiceTest` | **Pass** |
| NFR-R3 | Reliability | A failed change leaves the original booking untouched | Single UPDATE inside the lock | `BookingChangeTest` | **Pass** |
| NFR-M1 | Maintainability | Code, tests and docs stay in step | One requirement → one PR → traceability row | `docs/05-traceability.md` | **Pass** |
| NFR-PORT1 | Portability | PHP 8.1+ with PDO only | No extensions beyond `pdo_sqlite` / `pdo_mysql` (`pcntl` optional) | CI matrix, `composer.json` | **Pass** |

## Method notes

- **Performance** — `bin/bench.php` seeds an in-memory database (60 tables) and
  measures p50/p95/mean/max for the four hot paths; it exits non-zero when a p95
  budget is missed, so a regression fails the build.
- **Security** — `bin/security-check.php` exercises the runtime paths
  (credential storage, authentication, authorisation, validation, prepared
  statements, audit trail) against a throw-away database and prints
  `PASS`/`FAIL` per check.
- **Availability** — the backup check copies the SQLite file while the engine is
  idle, reopens it as a second connection and verifies the seeded rows; the same
  procedure is used in the W13 backup/restore drill against MySQL.
- **Capacity** — capacity is computed from *bookable* tables only (BLOCKED and
  MAINTENANCE tables are excluded), multiplied by how many sittings the three
  service periods allow with the 15-minute turnover buffer.
