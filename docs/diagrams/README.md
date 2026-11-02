# DineReserve — diagrams

All figures are **hand-authored SVG** (no PNG export, no build step). Open any file directly in a
browser or paste it into a Markdown/HTML page; they render from `file://` with no external
dependency. They are the response to the four missing/undefined figures in the Task 2 URS (see
[traceability §5](../05-traceability.md#5-known-gaps-in-the-task-2-urs)).

| File | What it shows | Canvas | Requirements |
|---|---|---|---|
| [`context.svg`](context.svg) | C4 level-1 system context: one system boundary, four user roles (Diner, Host, Manager, Administrator), four external systems (SMTP e-mail gateway, optional SMS gateway, MySQL database, GitHub Pages project site) and the protocols on each flow. | `0 0 1200 720` | URS §1.4 / §2.1 (missing Figure 1); REQ-1.1–1.9 actors |
| [`er.svg`](er.svg) | Entity-relationship model of the 10 tables in `database/schema.sql`, key columns only, with PK/FK tags and 1:N / 1:1 / N:M relationships. | `0 0 1400 1000` | REQ-1.1/1.2 (bookings, tables), REQ-1.5 (waitlist), REQ-1.9 (users/roles) |
| [`booking-flow.svg`](booking-flow.svg) | Activity flow: search → results → choose table → fill form → submit → TABLE_TAKEN branch → confirmed → confirmation/reminders → check-in, plus the no-show/late-cancel → release table → waitlist branch. Solid = W6 scope, dashed = planned W7–W11. | `0 0 1200 900` | REQ-1.1, 1.2, 1.3, 1.4, 1.5, 1.6 (URS §6–§11) |

## Legend

- **Rounded rectangle** — start/end state (flow) or the system (context).
- **Rectangle** — an activity step or an external system/actor box.
- **Diamond** — a decision with labelled outgoing branches (`Yes` / `No`).
- **Solid arrow** — runtime data flow / implemented W6 path.
- **Dashed arrow or border** — optional, deferred or planned (W7–W11).
- **PK / FK** — primary key / foreign key; `PK, FK` marks a composite key that is also a foreign key.
- **1:N / 1:1 / N:M** — cardinality; N:M is resolved through the associative table `user_roles`.
- Colours: blue = core restaurant/booking entities and the W6 path, teal = authentication cluster
  and user roles, orange = booking/events cluster and planned features, green = confirmed/success.

## How to update

1. **Edit the SVG text directly.** Coordinates are explicit (no transforms, no `foreignObject`);
   keep the existing grid and move whole `<g>` blocks when possible.
2. **Keep it self-contained.** No external font files, images, CDN or `@import`; use
   `font-family="Helvetica, Arial, sans-serif"` and `font-size` 13 or larger. Minimum 13 px text is a
   review requirement, not a style preference.
3. **Keep the canvases.** `viewBox` values are part of the contract with the docs that embed the
   figures; if the drawing needs more room, update this table too.
4. **Re-check every time:**
   ```bash
   for f in docs/diagrams/*.svg; do python3 -c "import xml.dom.minidom,sys; xml.dom.minidom.parse('$f'); print('$f OK')"; done
   grep -c '<text' docs/diagrams/*.svg
   ```
   Every file must parse as XML, contain more than 10 `<text>` elements, and contain no external
   link (`href=`, `<image>`, `<use href=`, `url(http`). The `xmlns="http://www.w3.org/2000/svg"`
   namespace is not an external link.
5. **Keep the docs in sync.** If a REQ changes, update the matching element here and the row in
   [`docs/05-traceability.md`](../05-traceability.md); the URS figure numbers are referenced there.

## Source of truth

- Tables/columns: `database/schema.sql` (SQLite) and `database/schema-mysql.sql` (MySQL 8).
- API shapes and status codes: [`docs/03-api.md`](../03-api.md) and `public/index.php`.
- Requirements and case-of-use wording: Task 2 URS §5–§16; traceability mapping in
  [`docs/05-traceability.md`](../05-traceability.md).
- Domain-study assumptions behind the actors/flows: [`docs/01-domain-study.md`](../01-domain-study.md).
