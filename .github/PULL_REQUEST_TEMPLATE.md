## What this PR does

<!-- One sentence per bullet, keep it factual. -->

-

## Requirement traceability

<!-- Every change must point at a URS requirement or a week deliverable. -->

- Closes #<!-- issue number -->
- Requirement(s): REQ-1.<n> / PERF-1.<n> / SEC-1.<n>
- Week: W<n> (see the plan in `docs/`)

## How it was verified

```bash
composer install
php bin/seed.php
vendor/bin/phpunit --do-not-cache-result
php -S 127.0.0.1:8080 -t public   # then curl the endpoint below
curl -s "http://127.0.0.1:8080/api/v1/availability?restaurant_id=1&date=2026-11-10&time=19:00&party_size=6"
```

## Checklist

- [ ] `vendor/bin/phpunit` is green and no existing test was weakened
- [ ] `php -l` passes on every touched PHP file
- [ ] No new Composer dependency (URS §3.1 licence constraint)
- [ ] Input validation errors are aggregated into one `VALIDATION_ERROR`
- [ ] Error paths return a `DomainException` code, never a raw exception message
- [ ] `docs/` or `README.md` updated when behaviour/scope changed
- [ ] Demo (`demo.html`) still works without external resources

## Screenshots / curl output

<!-- Paste the JSON response or a screenshot of the UI state you changed. -->
