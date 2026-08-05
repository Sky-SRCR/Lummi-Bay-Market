# Roadmap — Multi-Display Support

Status: **agreed, not started.** No application code has changed.

## Why

The app drives exactly one sign, and that assumption is baked in at every layer:
`canvas_settings` is a single row read as `WHERE id = 1`; `canvas_elements` has no
Display column; `api.php?action=publish` runs an unscoped `DELETE FROM
canvas_elements` and re-inserts the browser's whole layout; `1920 × 1080` is
hardcoded in ~10 places in `builder.php` and 6 in `viewer.php`; and permissions are
role-only, with no per-sign concept.

The goal is for one installation to drive any number of signs of any shape, each with
its own layout and its own list of people allowed to edit it, while the drive-thru
sign keeps running throughout.

Vocabulary is in [`CONTEXT.md`](../CONTEXT.md) — **Display**, **Viewer**, **Screen**,
**screen name tag**, **canvas**, **grant**, **edit lock**. Use those words.

## Decisions

Recorded in full, with rejected alternatives, in:

- [ADR-0003](adr/0003-every-viewer-names-its-display.md) — every Viewer URL names its
  Display; no default or fallback.
- [ADR-0004](adr/0004-canvas-size-fixed-at-creation.md) — canvas size is fixed at
  creation; the Viewer's scaling already covers same-shape resolution changes.
- [ADR-0005](adr/0005-per-display-grants-role-decides-power.md) — grants say *which*
  Displays, role still says *how much* power.
- [ADR-0006](adr/0006-publish-staleness-check-not-version-history.md) — publish
  refuses stale writes; no version history.
- [ADR-0007](adr/0007-one-editor-per-display-idle-lock.md) — one editor per Display,
  lock held by activity, 15-minute idle release.

Everything else settled during the design interview:

| Area | Decision |
|------|----------|
| Tag format | lowercase letters, digits, hyphens; 2–32 chars; suggested from the title; duplicates rejected by name |
| Tag renaming | admin may rename, behind a confirm showing the new URL to enter on the Screen |
| Retiring | deactivate keeps the layout and shows *"This display is turned off"*; delete destroys it and needs the tag typed to confirm; no "last Display" rule |
| New Display | dimensions first, then blank or duplicate — duplication offered only from Displays of identical dimensions |
| Initial state | active immediately on creation |
| Shape mismatch | letterbox, as today — nothing distorted |
| Per-Display | background (absorbing and retiring `canvas_settings`) |
| Shared | asset library, Brand Standards typography, `uploads/` |
| Builder entry | one Display → straight in for everyone; more than one → admins pick every time, basic users resume their last |
| Builder zoom | opens at zoom-to-fit, with Fit / 100% / − / + |
| Public content | Viewers stay login-free; the admin screen states plainly that anyone with the URL can view a Display |
| Preview | none — Publish is the only path to a Screen |
| Delivery | one PR per phase, on `claude/app-update-planning-1pjqfr`, restarted from `main` after each merge |

Duplication copies element positions, hidden and locked flags, and section
backgrounds, and points at the **same** library assets rather than copying them.

## Phases

Each phase is independently deployable and leaves the app coherent, so work can stop
after any of them. There is no CI or deploy pipeline — every merge reaches the sign by
hand — so phase order is also deployment order.

### Phase 1 — Display-scoped data model and API · size M · risk **High**

Add `displays` (tag, title, location, width, height, background, active, last
published at/by, lock holder/activity). Add `display_id` to `canvas_elements`,
indexed and FK'd, backfilled to the migrated drive-thru row. Thread a Display through
every endpoint in `api.php` — `get_layout`, `publish`, `get_canvas_elements`,
`set_element_hidden`, `delete_canvas_element` — and retire `canvas_settings` along
with the last `WHERE id = 1`. Implement the publish staleness check while the publish
path is open.

Schema changes follow the idempotent `ALTER TABLE` pattern already in `api.php` /
`auth.php`; there is no migration tool and the live database is edited in place.

No UI change beyond the Builder sending its layout stamp. **Done when** the sign
behaves exactly as today and every query and delete in the publish transaction is
Display-scoped.

Risk lives here. Publish currently deletes every element unscoped — a scoping miss
does not degrade one sign, it empties all of them. Worth a line-by-line review and a
rehearsal against a copy of live data before it reaches the server. A `php -l` GitHub
Action is cheap insurance and can ride along.

### Phase 2 — Canvas dimensions from the Display record · size M · risk Medium

`viewer.php` resolves `?display=<tag>` and renders at that Display's dimensions,
showing *"No display specified"* for a missing or unknown tag and *"This display is
turned off"* for a deactivated one — never another Display's layout. `builder.php`
takes its canvas CSS, inspector bounds, default marquee width, root-level clamps and
align-to-canvas maths from the record, and gains zoom-to-fit so a portrait canvas
fits the editor frame.

**Done when** `viewer.php?display=drive-thru` is pixel-identical to today's sign, a
hand-inserted Display of another shape renders correctly in both Builder and Viewer,
and the negative cases show notices.

**Cutover, at the end of this phase:** re-point the drive-thru Screen and the
SmartSign2Go widget from `…/viewer.php` to `…/viewer.php?display=drive-thru`. Deploy
and re-point in the same visit — in between, that Screen shows the notice.

### Phase 3 — Admin Displays screen · size M · risk Low

A **Displays** tab in `admin_panel.php`: create (dimensions first, then blank or
duplicate-from-same-size), edit title / tag / location / background, deactivate, and
delete behind typing the tag. Size is shown read-only. Admin-only, CSRF-protected
like the rest of the panel.

The screen name tag is visible wherever a Display appears: its own column beside the
title, the full Viewer URL copy-ready for configuring a device, last-published-by, and
the note that anyone with the URL can view the content. The Builder's top bar shows
the active Display's title, tag and dimensions, and publish names the Display it went
to. The Work Area tab becomes Display-scoped.

**Done when** an admin can create a second Display, build it, publish it, show it on
another Screen, and delete it without touching the first.

### Phase 4 — Grants and the Display picker · size M · risk Medium

`display_permissions` plus a grant UI (a Display↔account matrix), enforced
**server-side** on every write and on `get_canvas_elements` — not merely absent from
the picker. The picker filters to what the signed-in account may edit; a `basic`
account with no grants sees *"No displays have been assigned to you yet."*

**Done when** a `basic` account granted one Display can edit only that Display, and a
forged publish naming another is rejected by the API.

### Phase 5 — Edit lock and read-only Builder · size M · risk Medium

Take the lock on opening a Display; heartbeat the last real interaction; lapse after
15 minutes idle with a warning at 13 and one click to keep it; release on leaving;
re-take silently on return if unclaimed; refuse publish when the lock moved on. A
second account opens the Display read-only with a banner naming the holder. Admins can
force-unlock behind a confirm.

The read-only mode is the substantial part: every editing control in `builder.php`
must respect it, in a file that is largely inline JavaScript.

**Done when** two accounts cannot edit one Display at once, an idle tab frees the
Display after 15 minutes, an active editor is never interrupted, and a force-unlock
works with the holder warned.

### Phase 6 — Docs and schema · size S · risk Low

`schema.sql` updated to the real structure. `README.md`, `help.php` and `HANDOFF.md`
corrected — all three present 1920 × 1080 as a fixed property of the system, and
`HANDOFF.md` states a viewer-only resolution change is impossible.

## Risks and watch-items

- **Unscoped delete on publish (Phase 1)** — the one change that can lose every
  Display's layout at once. Highest-value review target of the project.
- **No undo, ever** — publish overwrites and nothing is versioned. The lock and the
  staleness check prevent collisions; neither recovers a layout after the fact.
- **Live schema drift** — the live database is behind the repo (it still lacks the
  lockout columns `auth.php` adds at runtime). Phase 1 must assume nothing about what
  is already applied.
- **Kiosk framing** — the `<Files "viewer.php">` block in `.htaccess` drops
  `X-Frame-Options` so signage widgets can embed the sign, and the Viewer is
  scroll-locked for kiosks. Keeping one filename means every Display inherits both;
  anything that renames or splits `viewer.php` breaks them.
- **`builder.php` is ~2450 lines, mostly inline JavaScript** — `php -l` cannot see
  those errors. Phases 2 and 5 touch it heavily and need reading, not just linting.
- **No tests** — verification is manual against the live site.

## Verification

- `php -l` every touched PHP file before committing — no PHP runtime here.
- `GET api.php?action=get_layout&display=<tag>` (public, no session) to diff layout
  JSON before and after a change, plus the negative cases: no tag, unknown tag,
  deactivated Display.
- Real browser pass on the live site: sign in → edit → publish → the Screen updates
  within 30s; a stale or forged POST still returns "Security token mismatch."
- Phases 4–5 need two accounts (an admin, and a `basic` account with one grant), and
  Phase 5 needs two browsers to exercise the lock.
- Phase 2 needs a genuinely different-shaped Display, including a portrait one, to
  prove dimensions are data-driven rather than re-parameterised 1920 × 1080.

## Out of scope

Drafts, version history and rollback (the natural follow-on, and it would build on the
publish stamps). Per-Display typography. Content scheduling or dayparting. Screen
heartbeat and monitoring. Bulk price editing. A per-Display access key, if a
staff-only Display is ever wanted. Copying a layout between different dimensions —
considered and dropped, since the Viewer's scaling covers resolution changes and a
shape change needs redesign regardless.
