# Archive

Frozen copies of deliverables that were published earlier and have since been superseded in place.

| File | Original deliverable | Published | Source commit |
|---|---|---|---|
| [`project-site-2026-10-06.html`](project-site-2026-10-06.html) | Task 1 project website (proposal site), published on GitHub Pages | 2026-10-06 | `5035341` — *Set Group No. to 6 on project website* |

## Why it is archived

The live project site is [`index.html`](../../index.html) at the repository root, which is published to
<https://ycy-ui.github.io/DineReserve/>. That file started life as the October version archived here and was
later extended in W6 with a **“Try the live demo”** section (29 added lines, 2 changed) linking to
[`demo.html`](../../demo.html). The original October page is kept verbatim so the Task 1 deliverable can be
reviewed without walking the commit history.

## How this copy was produced

```bash
# from the repository root
git show 5035341:index.html > docs/archive/project-site-2026-10-06.html
```

The file is byte-for-byte the page that was on GitHub Pages on 2026-10-06; nothing was reformatted or
fixed up. The October commits themselves are also present in the history (`5a630d0`, `256909e`,
`5035341`), both in `ycy-ui/DineReserve` and in `MUST-Workspace/D2-team6-project`.
