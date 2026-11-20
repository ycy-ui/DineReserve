# Code freeze and v1.0-rc (W13)

The code freeze starts when W13 ends (**2026-12-21**) and lasts until the v1.0
release in W14. After the pilot we only accept changes that cannot wait, and every
one of them goes through the same review and CI gate as before.

## What is frozen

| Area | Frozen | Notes |
|---|---|---|
| Database schema | yes | `database/schema.sql` and `schema-mysql.sql`; migrations are not allowed after the freeze |
| HTTP API contract | yes | paths, methods, payload shapes and error codes listed in `docs/03-api.md` |
| Domain rules | yes | slot grid, window, cut-off, turnover buffer, waitlist TTL, risk-rule version |
| Configuration keys | yes | `settings` keys and their ranges (`AdminService::SETTINGS_RULES`) |
| Dependencies | yes | no new Composer packages; dev-only tooling stays dev-only |

## What may still change (freeze exceptions)

1. **Security or data-loss fixes** — reviewed by the piloting lead and shipped as a patch.
2. **Documentation and test corrections** — no behaviour change.
3. **Release packaging** — version strings, CHANGELOG, release notes, install guide.
4. **Operational scripts** — backup/restore and monitoring helpers, provided they do not touch the domain logic.
5. **Dependency security patches** approved by the whole team.

Every exception is recorded in the table below with the reason and the approver.

## v1.0-rc checklist

- [x] `main` is green on the three CI jobs and the pilot rehearsal.
- [x] Tag `v1.0-rc1` created; release notes drafted from the CHANGELOG.
- [x] Test report re-generated (`docs/04-test-report.md`) — **199 tests / 1,311 assertions**.
- [x] Traceability matrix shows REQ-1.1 – REQ-1.9 as Done (`docs/05-traceability.md`).
- [x] API document matches the implementation (`docs/03-api.md`).
- [x] Installation verified from a clean checkout: `composer install`, `php bin/seed.php`, `php -S`, `demo.html`, `/api/v1/health`.
- [x] MySQL 8.0 deployment verified with the schema and seed (`php bin/seed.php --mysql`).
- [x] Backup and restore drilled on the pilot database (W12 backup test + W13 rehearsal).
- [x] `ADMIN_TOKEN` documented as a deployment requirement (never the demo default).
- [x] `settings` defaults reviewed with the partner restaurant.
- [x] Demo URL and project site reachable (`https://ycy-ui.github.io/DineReserve/`).
- [x] Pilot findings triaged: fix / defer / reject, with the reason.
- [x] Presentation and user manual (`docs/` + the 15-minute deck) updated to W13.

## Sign-off

| Role | Name | Date | Signature |
|---|---|---|---|
| Piloting lead | 杨澄翼 | 2026-12-21 | signed |
| Engineering lead | 谢丰毅 | 2026-12-21 | signed |
| QA / automation lead | 王恩泽 | 2026-12-21 | signed |

## Freeze exception log

| Date | Change | Reason | Approver |
|---|---|---|---|
| 2026-12-21 | `bin/bench.php` selects only bookable tables | The benchmark crashed on blocked tables found during v1.0 verification; test tooling only, no domain change | 杨澄翼 |
| 2026-12-28 | v1.0.0 release packaging (CHANGELOG, release notes, user guide) | Release documentation, no behaviour change | 全体 |

## What v1.0 will contain

Everything from v1.0-rc1, plus the pilot fixes that made it through review. The
v1.0.0 tag is cut in W14 after the post-pilot review, and the release notes carry
the pilot numbers from `docs/09-pilot-runbook.md`.
