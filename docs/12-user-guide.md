# User guide — running a service with DineReserve

This is the short manual for the host desk and the duty manager. It assumes the
system is deployed and the floor plan has been loaded.

## Before service

| Task | How |
|---|---|
| Check the day is loaded | Open the floor plan; the date at the top must be today |
| Confirm the backup exists | Ask the on-site engineer for the latest `pilot-backup.sqlite` / MySQL dump |
| Check the settings | Manager only: `GET /api/v1/admin/settings` (or the manager screen) — slot, window, cut-off, buffer, offer TTL, max party |
| Create a host account | `php bin/admin.php create --name="Chen" --email=chen@restaurant.mo --password=… --role=host` |
| Open the diner URL | `https://<deployment>/demo.html` for the guest-facing flow |

## Taking a booking (host)

1. Ask for date, time, party size, name, phone/e-mail.
2. Search availability: `GET /api/v1/availability?date=…&time=…&party_size=…`.
3. Choose a table from the returned list and create the booking:
   `POST /api/v1/bookings` with `reference` returned to the guest.
4. The guest receives a confirmation and the 24 h / 2 h reminders.
5. Tell the guest the cut-off time (default 120 minutes before the sitting).

## Seating and the floor

| Situation | Action |
|---|---|
| Guest arrives with a booking | Open the floor plan, find the table, **Seat** (`POST /api/v1/floorplan/seat`) |
| Guest arrives without a booking | **Walk-in** (`POST /api/v1/floorplan/walk-in`) — the booking is created and seated in one step |
| Guest does not arrive | **No-show** (`POST /api/v1/floorplan/no-show`) — the booking is marked and the table goes to cleaning |
| Table cleaned and ready | **Release** (`POST /api/v1/floorplan/release`) — the table returns to FREE and the booking is completed |
| Table out of service | Ask the manager to set it to BLOCKED / MAINTENANCE; it will not be offered |

The screen refreshes every 5 seconds (`poll_after_seconds`), so no manual reload
is needed.

## Changing or cancelling

- **Guest self-service**: the confirmation e-mail contains a signed link valid for
  72 hours. The guest can move the booking to another slot or cancel it.
- **Host**: search the booking, then use *change* or *cancel* on the record. A
  cancelled slot is automatically offered to the first matching waiter.
- Cut-off: after the cut-off time the guest cannot self-service; the host can
  still change or cancel.

## Waitlist

1. When a guest asks for a full slot, add them to the waitlist: `POST /api/v1/waitlist`.
2. When a booking is cancelled, the first matching waiter receives an offer by
   e-mail, valid for 5 minutes.
3. The guest accepts through the link; the system creates the booking atomically
   (channel `waitlist`). If they decline or the offer expires, the next waiter is
   offered the table.

## End of service

| Task | How |
|---|---|
| Mark remaining no-shows | Floor plan → No-show for each unused confirmed booking |
| Pull the day report | `GET /api/v1/reports/occupancy?from=<today>&to=<today>` |
| Export for the manager | `POST /api/v1/reports/export` with `format=xlsx` or `pdf` |
| Check the risk list | `GET /api/v1/reports/risk?from=<today>&to=<today>` — HIGH ≥ 70 |
| Close the day | Take a backup, then `php bin/waitlist.php --expire` to clean stale offers |

## When something goes wrong

1. If the screen stops updating: refresh; if the problem persists, switch to paper.
2. If a booking looks wrong: note the reference and tell the on-site engineer.
3. If data is corrupted: stop the app, restore the pre-service backup, restart and
   verify with a known reference.
4. Never delete rows to "fix" a problem — the audit trail is what lets us find it.

## Manager tasks

| Task | Command / endpoint |
|---|---|
| Add or deactivate a user | `php bin/admin.php create` / `php bin/admin.php set-role`; `PATCH /api/v1/admin/users/{id}` |
| Change a policy | `PATCH /api/v1/admin/settings` (`slot_minutes`, `max_days_ahead`, `cutoff_minutes`, `turnover_buffer_minutes`, `waitlist_offer_minutes`, `max_party_size`) |
| See the risk score of a booking | included in the booking payload and the risk report |
| Re-score a period after a rule change | `php bin/report.php --rescore --from=… --to=…` |
