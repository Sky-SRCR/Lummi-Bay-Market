# Roadmap — Multi-Display Support

Status: **All six phases built on `claude/app-update-planning-1pjqfr`, not yet
deployed** — open as [PR #3](https://github.com/Sky-SRCR/Lummi-Bay-Market/pull/3).
The build is finished; what is left is the deployment visit below. How the code is
shaped, and the invariants any later change must preserve, are in
[`BUILD-REFERENCE.md`](BUILD-REFERENCE.md) — read that alongside this file.

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

**Built.** Data access moved into four deep modules under `lib/` — schema
convergence, `Display`/`DisplayStore`, `LayoutStore` (the only place that touches
`canvas_elements`), and the request-resolution seam where Phase 4's grants will
land. `api.php` is now a thin adapter; `canvas_settings` is retired and no
`WHERE id = 1` remains. The stamp is a revision counter rather than a timestamp
(see BUILD-REFERENCE.md §4). `php tools/selftest_layout.php` runs the real publish
path against an in-memory database — 85 checks covering scoping, refusal of stale
publishes, the basic-account section rules and a forged cross-Display parent —
and `tools/rehearse_phase1.php` proves the DDL and scoping on a copy of live data.
A `php -l` + self-test GitHub Action rides along. Not yet run against MySQL or a
browser: see "Before this reaches the sign" below.

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

**Built.** No `1920`/`1080` literal remains in `viewer.php` or `builder.php`; both
are adapters over `DisplayRequest` + `Display`, and no new module was needed. The
Viewer resolves its Display server-side, so the canvas is right on first paint and
the ADR-0003 notices render without a round-trip; a Display turned off while a
Screen is running flips to the notice within one poll. The Builder derives its
canvas CSS, inspector bounds, marquee default, root clamps and align maths from the
record, and opens at zoom-to-fit with Fit / 100% / − / +. Viewing is now strict —
the transitional no-tag fallback survives for editing only, until the Phase 3
picker (BUILD-REFERENCE §3). The `settings` alias is retired. 87 self-test checks
pass. **Not yet run against MySQL or a browser**, and the cutover above has not
happened: see "Before this reaches the sign".

The zoom control is the part to re-read rather than trust: `interact.js` deltas
arrive in screen pixels and are divided by `ZOOM` in exactly three places
(BUILD-REFERENCE §4b).

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

**Built.** Creation is three gated steps — canvas size first, then the name, then
blank-or-duplicate — and the duplicate list only ever offers Displays of exactly
the size being created (ADR-0004). The size is read-only afterwards, enforced by
there being no statement anywhere that can change it. Each Display shows its tag,
shape, element count, last-published-by and a copy-ready absolute Viewer address,
above the standing note that anyone with that address can watch the sign without
signing in. Deleting needs the tag typed back and states how many elements go with
it; retiring keeps the layout and says so on the Screen within one poll.

The write surface landed as `DisplayStore` (statements, tag and size rules) plus a
new `DisplayAdmin` (the use case: validation, duplication, transactions) — not all
on `DisplayStore` as BUILD-REFERENCE had planned, because that would have put
`canvas_elements` writes in the module that owns `displays`. See BUILD-REFERENCE
§4c for that and the rest of the phase's decisions.

Two things Phase 2 deliberately left are closed: `get_editor_layout` is the
authenticated editing read, so a retired Display still opens in the Builder; and
the Builder has a picker, so the no-tag editing rule is now a decided entry
shortcut for single-sign installations rather than a transitional prop (§3). The
Builder's top bar names the Display it is editing, publish says which sign it
reached, and the Work Area tab is Display-scoped. 155 self-test checks pass.
**Not yet run against MySQL or a browser.**

The picker is shown to every account. The *Builder entry* decision below has
`basic` accounts resuming their last Display instead of picking; that arrives with
grants in Phase 4, since until grants exist every account can see every Display and
"their last" would be a memory of an unrestricted set.

### Phase 4 — Grants and the Display picker · size M · risk Medium

`display_permissions` plus a grant UI (a Display↔account matrix), enforced
**server-side** on every write and on `get_canvas_elements` — not merely absent from
the picker. The picker filters to what the signed-in account may edit; a `basic`
account with no grants sees *"No displays have been assigned to you yet."*

**Done when** a `basic` account granted one Display can edit only that Display, and a
forged publish naming another is rejected by the API.

**Built.** A grant is one row in `display_permissions` and one tick in a matrix on
the Displays tab: accounts down the side, Displays across the top, one save. Admins
are not in it — they hold every Display by role (ADR-0005) — and only `basic`
accounts can be submitted, which is also what stops a forged POST writing a grant
row for an admin. Each Display card names who it is assigned to.

Enforcement is one predicate, `Actor::mayOpen()`, called by
`DisplayRequest::forEditing()` — so `publish`, `get_editor_layout`,
`get_canvas_elements`, `set_element_hidden` and `delete_canvas_element` are all
covered without a line of their own, and an endpoint added later inherits it by
resolving its Display the same way. A forged publish naming an ungranted Display is
refused in the seam, before any layout code runs. Grants are read once per request
and only for a `basic` account; the public Viewer poll builds no actor at all.

The Builder's picker lists only what the account may open, distinguishes "no
displays have been assigned to you yet" from "the one you have is turned off", and
the entry rule generalised from *the installation's only Display* to *this
account's only Display* — so a `basic` account with one grant never sees a picker.
One with more than one returns to whatever it last opened, remembered for the
session (BUILD-REFERENCE §3). Deleting a Display or an account takes its grants
with it explicitly, for the same reason elements are deleted explicitly.

The planned `DisplayStore::editableBy()` became `Actor::openable()` instead, and
`DisplayStore::sole()` is gone; see BUILD-REFERENCE §4d for both and the rest of
the phase's decisions. 193 self-test checks pass, including the forged publish.
**Not yet run against MySQL or a browser**, and this is the first phase that
genuinely needs two accounts to verify.

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

**Built.** Opening a Display in the Builder claims its lock; a second account gets
the same page with no editing controls in it at all and a bar naming the holder and
since when. Read-only is decided server-side before the page is built, which is what
made this tractable in a file that is mostly inline JavaScript — a control added
later is either inside the `if (!$readOnly)` block or it is reachable, and that shows
up in a diff. The lock is held by work: the Builder reports the *age* of the last
real click, key or drag, so a tab forgotten on a back-office monitor keeps
heartbeating and still frees the sign exactly 15 minutes after somebody last touched
it. The holder gets a warning bar with one button at 13 minutes, and leaving the
Builder releases the lock immediately rather than making the next person wait it out.

`LayoutStore::publish()` now refuses two ways, and reports the lock first: "reload
and re-apply" is bad advice while somebody else is mid-edit. Losing the lock while a
tab is open does not disturb the canvas — ADR-0007's rule is that the unsaved edits
stay on screen and the publish is refused, which is both kinder and far simpler than
dismantling the editor underneath somebody. An admin can take a Display off its
holder from the read-only Builder, behind a confirm that says what it costs; the
takeover *transfers* the lock rather than clearing it, or the ousted tab's next
heartbeat would take it straight back.

The planned `takeLock()`/`heartbeat()` pair became one `claimLock()`, and
`forceUnlock()` became `seizeLock()`; see BUILD-REFERENCE §4e for both and the rest
of the phase's decisions, including why `builder.php` claims the lock on a GET. 316
self-test checks pass. **Not yet run against MySQL or a browser**, and this is the
phase that needs two browsers as well as two accounts — plus one 15-minute wait that
nothing can shorten.

### Phase 6 — Docs and schema · size S · risk Low

`schema.sql` updated to the real structure. `README.md`, `help.php` and `HANDOFF.md`
corrected — all three present 1920 × 1080 as a fixed property of the system, and
`HANDOFF.md` states a viewer-only resolution change is impossible.

**Built.** `schema.sql` now carries `display_permissions`, `lock_taken_at`, and a
header that says what it is: a rebuild artefact that has to agree with the two
runtime convergence functions, which are the authority on what the live database
becomes. `CONTEXT.md`, `README.md` and `HANDOFF.md` describe an installation that
drives any number of signs; `HANDOFF.md`'s claim that a viewer-only resolution
change is impossible is replaced by what ADR-0004 actually establishes — a Screen's
resolution and a Display's canvas size are different things, so swapping in a larger
TV of the same shape needs no change at all, while a different *shape* means a new
Display. `help.php` describes the canvas as the size of the display being edited,
explains that the address is what decides which sign a screen shows, documents the
three Viewer notices, and covers the Displays tab, the grant matrix and the zoom
control.

Proofreading turned up two real defects, both fixed here and recorded in
BUILD-REFERENCE §4f. Brand Standards could not be edited on a fresh install: the
form saves with `UPDATE … WHERE block_type = ?` and four of the six branded rows were
never seeded, so saving them was a silent no-op — both schema files now seed all six.
And `help.php`'s nav still linked to a bare `viewer.php`, which since ADR-0003 is the
"no display specified" notice; the link is gone, and §5 has a grep to keep it that
way. A third correction is a documentation-only one worth knowing: Brand Standards
reach every Screen within 30 seconds *without* a publish, because the typography is
part of every snapshot — three places said to publish afterwards.

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
- **`builder.php` is ~3050 lines, mostly inline JavaScript** — `php -l` cannot see
  those errors. Phases 2 and 5 touch it heavily and need reading, not just linting.
- **No tests** — verification is manual against the live site.

## Before this reaches the sign

In order, on the one visit:

1. **Back up the database.** Publishing has no undo and this deploy rewrites how
   every element is addressed. A phpMyAdmin export is enough.
2. **Rehearse on a copy**, never on live:
   `php tools/rehearse_phase1.php --host=localhost --db=<copy> --user=<user> --pass=<pass> --confirm-copy`.
   It converges the schema, proves the backfill left no unscoped element, publishes
   to two throwaway Displays, and removes them again. Expect "Rehearsal clean."
3. **Upload the files**, including the new `lib/` and `tools/` folders *with* their
   `.htaccess` files — those are what keep the modules and the rehearsal script
   unreachable from a browser. **What must not go up with them** is the half this
   step never said, and the full list with the reasons is
   [`DEPLOY-SKIP.md`](DEPLOY-SKIP.md) — read it here, not afterwards, because none
   of these announces itself:
   - **Do not overwrite `branding_config.php`.** The server generates it from the
     Branding page. The repo's copy is the pre-branding default, and it reverts the
     store's name and the address password-reset codes and alerts are sent *from*.
   - **Do not upload `setup.php`**, or `.git/`, or the `.md` files. `setup.php` was
     deleted from the server after setup and re-uploading puts the first-admin form
     back.
   - **Do not mirror or sync-with-delete.** File-by-file, or folder overwrite. A
     mirroring client deletes `uploads/` — every image and video on every sign, in
     no backup, with no undo.
   - And **read the live root `.htaccess` before replacing it — then replace it.** It
     must go up (the security headers and the `viewer.php` framing exception are in
     it); the reason to read it first is that a hand-raised `upload_max_filesize`
     lives only there and reverts silently. Carry any hand-edit forward.

   `setup.php` is already gone from the server, and it now deletes itself if it ever
   comes back — but only when something requests it, so uploading it still opens a
   window. See the *Known live state* section of `DEPLOY-SKIP.md`.
4. **Sign in once as an admin.** That first authenticated request is what runs the
   schema convergence: it creates `displays`, seeds the drive-thru Display from
   `canvas_settings`, and backfills `display_id`. (If the sign's poll gets there
   first it self-heals, but signing in makes it deliberate.)
5. **Check the sign at its new URL.** `viewer.php?display=drive-thru` shows the
   drive-thru layout unchanged, pixel for pixel. A bare `viewer.php` now shows "No
   display specified" — that is Phase 2 working as designed (ADR-0003), not a fault.
6. **Publish once** from the Builder and confirm the Screen updates within 30s.
7. **Prove the refusal**: leave a second Builder tab open, publish from the first,
   then publish from the stale tab — it must be refused by name, and the layout
   must not change.
8. **Re-point the Screen and the widget** to `…/viewer.php?display=drive-thru` —
   the SmartSign2Go widget and the TV both. This is the Phase 2 cutover and the
   only manual step in the project; until it is done that Screen shows the notice.
9. **Exercise the zoom** in the Builder: at Fit and at 100%, drag a block and
   resize it, then confirm the inspector's X/Y/W/H match where it actually sits and
   that publishing puts it there on the Screen. Zoom is the one place Phase 2 could
   drift silently.
10. **Prove a different shape** — the one thing that shows the dimensions are really
    data-driven. Admin Panel → **Displays** → Add a Display: preset *1080×1920
    Portrait HD*, title "Test Portrait", blank canvas. Open it in the Builder (the
    canvas is portrait and opens zoom-to-fit), add a block, publish, and open its
    screen address in a second tab — the layout letterboxes inside the window
    rather than distorting.
11. **Prove duplication.** Add another Display at 1920×1080 and choose *a copy of an
    existing display's layout* → the drive-thru. The copy must have the same blocks
    in the same places, and the drive-thru must be untouched. Check the refusal too:
    at 1080×1920 the drive-thru is **not** offered as a source, and at 1920×1080 the
    portrait Display is not.
12. **Prove the Work Area is Display-scoped.** Switch the Display selector: each one
    lists only its own elements. Hide one element on a test Display and confirm the
    drive-thru is unaffected — and that a Builder tab opened before the hide is now
    refused when it publishes.
13. **Prove retirement, then deletion.** Turn a test Display off: its screen address
    says "This display is turned off" within 30s, and it still opens in the Builder
    with the red banner (that is `get_editor_layout` doing its job). Then delete it —
    a mistyped tag must refuse, the right tag must delete it and its elements, and
    the drive-thru must still be intact after both.
14. **Prove the picker.** While more than one Display exists, a bare `builder.php`
    lists them to choose from — and as an admin it lists every Display, retired ones
    included.
15. **Prove a grant, with a second account.** This is the one thing only two
    accounts can show. Add a `basic` account, sign in as it in another browser (or a
    private window — one session per browser), and confirm it sees *"No displays have
    been assigned to you yet"*. Then tick that account against **one** test Display
    in Admin Panel → Displays → *Who can edit which display*, reload as the basic
    account, and confirm it lands straight in that Display with no picker.
16. **Prove the refusal, not just the absence.** Still signed in as the basic
    account, put the drive-thru's address in the Builder by hand:
    `builder.php?display=drive-thru`. It must say the display has not been assigned
    to you and offer only their own. That is the check that matters — a Display
    missing from a list proves nothing about what the API accepts.
17. **Prove revoking reaches an open tab, and frees the sign.** With the basic account
    editing its Display, untick its grant as the admin and save. Three things must
    happen. The admin's answer says an edit lock was released. Within a minute the
    basic account's tab shows a red bar saying its access was removed — it must not
    have to try publishing to find out — and what it had done is still on its screen.
    And that Display card in Admin Panel → Displays no longer says anybody has it
    open, so the next person can start straight away rather than waiting out fifteen
    minutes. Publishing from the stale tab is refused and nothing reaches the screen.
    Two more things on that same screen, both of which need the second browser only
    for setting up:
    **the grid saves what it showed** — open the Displays tab in both browsers as the
    admin, add a Display and grant it in one, then press *Save access* in the other;
    the new grant must survive, because that page never showed the new column. Then
    press F5 on the page you saved from: it must reload without re-sending the grid
    (the success line appears once and is gone).
    **A promotion clears the assignments** — make the basic account an admin. The
    answer must say which displays were cleared, that account must vanish from the
    grid, and making it a basic user again must leave it holding *nothing* rather
    than what it had before. If it had a Display open when it was demoted, that
    Display must be free.
    **And the same for the other three doors** — one at a time, with the basic account
    editing its Display each time and its grant restored in between:
    *turn the Display off* (its tab must show a red bar saying the display was turned
    off, and the admin's answer must say a lock was released — then turn it back on and
    check an *admin* editing a Display they retire is not thrown out of their own
    session);
    *suspend the account* (its tab must say it has been signed out, and the Display card
    must stop naming it as the holder immediately, not in fifteen minutes);
    *rename the screen name tag* (its tab must ask it to **reload** rather than say it
    lost the Display — and after reloading, that same account must still have the sign
    and still be able to publish it, because a rename is not a change of access. Re-point
    the Screen afterwards; the old address stops working).
18. **Prove one editor at a time.** With the basic account editing its test Display
    in one browser, open that same Display as the admin in the other. It must come up
    **read-only**: a purple bar naming the other account and since when, no *+ block*
    buttons, no background controls, no Publish button, and clicking a block does
    nothing. Each Display card in Admin Panel → Displays also says who has it open.
19. **Prove the takeover.** In the admin's read-only tab click **Take over editing**
    and confirm. The page reloads as a normal Builder. Back in the basic account's
    tab, wait up to a minute for its heartbeat: a red bar appears saying the admin
    has it. Its canvas is untouched — that is deliberate — but publishing from it is
    refused by name, and nothing reaches the screen.
20. **Prove the idle release.** This is the one step with a real wait in it, and it
    can run in a spare tab while the rest is checked. Open a test Display, touch
    nothing, and watch: at **13 minutes** a warning bar appears with *Keep editing*
    (clicking it must keep the Display, provably — the other browser still sees
    read-only afterwards); at **15** the bar says the lock was released, and the other
    browser can then open that Display and edit it normally. Then go back to the first
    tab and change something: it takes the Display back if nobody claimed it, or says
    who did.
21. **Prove that leaving releases it at once.** Close the Builder tab, or click away
    to the Asset Library, then immediately open that Display in the other browser. It
    must be editable straight away — nobody should have to wait out 15 minutes for a
    display nobody is looking at.
22. **Then clean up.** Delete the test Displays and the test account. A bare
    `builder.php` as an admin goes straight into the drive-thru again — that is the
    single-sign entry rule — and the drive-thru layout is exactly as it was.

If the sign goes blank after step 4, the backfill is the thing to check:
`SELECT COUNT(*) FROM canvas_elements WHERE display_id IS NULL` should be 0. It
re-runs on every authenticated request, so loading an admin page repairs a partly
applied migration; a non-zero count that persists means the `UPDATE` is being
refused and needs running by hand.

## Verification

- `php -l` every touched PHP file before committing — no PHP runtime here.
- `php tools/selftest_layout.php` — the real modules against an in-memory
  database. A failure is a release blocker, not a broken test.
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
