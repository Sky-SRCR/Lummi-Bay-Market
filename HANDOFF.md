# Lummi Bay Market — Digital Signage System · Session Handoff

> Handoff for a fresh Claude Code session. Read this first, then
> [`docs/BUILD-REFERENCE.md`](docs/BUILD-REFERENCE.md).

## 1. What this project is

A PHP + MySQL **digital-signage builder** for the Lummi Bay Market
(tobacco/nicotine pricing displays). Someone designs a layout in a drag-and-drop
**Builder**, clicks **Publish**, and a fullscreen **Viewer** running on a TV or
kiosk picks it up within 30 seconds.

One installation drives any number of signs. Each one — a **Display** — has its
own canvas size, its own layout, and its own list of accounts allowed to edit it.
The drive-thru sign's 1920×1080 is the first Display's dimensions, not a property
of the system.

- **Live site:** https://srcresort.com/lbm/  (served from an `/lbm/` subfolder)
- **Live database:** `silverad_lummi_market_drive_thru` (MySQL 5.7, localhost:3306)
- **Stack:** vanilla PHP (PDO, `ERRMODE_EXCEPTION`, real prepared statements),
  no framework, inline CSS/JS. Uses `interact.js` in the builder for drag/resize.

> **The multi-display build is finished in the repo and has never run on the
> server.** The live site is still the single-sign app. Nothing in §6 has been
> deployed, and deploying it is a scripted 22-step visit — see §7.

## 2. Git / branch

- **Working branch:** `claude/app-update-planning-1pjqfr` (all work goes here)
- Push with `git push -u origin claude/app-update-planning-1pjqfr`
- **PR #3** (`claude/app-update-planning-1pjqfr` → `main`):
  https://github.com/Sky-SRCR/Lummi-Bay-Market/pull/3 — the whole multi-display
  build. Open, CI green, not merged.
- PR #2 (security hardening, login lockout, embeddable viewer, housekeeping) was
  merged into `main` by hand as `ff361cc`. Start future sessions from `main` once
  PR #3 lands too.
- Commit history on this branch (newest first):
  - `195cb0c` Phase 5: the edit lock and the read-only Builder
  - `29ed18c` Phase 4: grants and the Display picker
  - `435cc27` Phase 3: the admin Displays screen
  - `7765638` Phase 2: canvas dimensions from the Display record
  - `8eae330` Phase 1: Display-scoped data model and API
  - `e504857` Add the multi-display roadmap
  - `31ffc06`, `ff33379`, `94c37ba` — the domain model and the first ADRs

## 3. File map (page scripts at repo root; data access in `lib/`)

Page scripts are **thin adapters** over the modules in `lib/`. One module owns
each table, and nothing outside it may write SQL against that table. Read
[`docs/BUILD-REFERENCE.md`](docs/BUILD-REFERENCE.md) before changing any of them —
it is the standing contract, with the invariants and where later work attaches.

| File | Role |
|------|------|
| `lib/schema.php` | `ensureSignageSchema()` — every idempotent `CREATE`/`ALTER`, the drive-thru seed, the `display_id` backfill, the Brand Standards seed |
| `lib/displays.php` | `Display`, `Background`, `LockState`, `DisplayStore` — the **only** SQL against `displays`; screen name tag rules; the edit lock |
| `lib/layout_store.php` | `LayoutStore` — the **only** place that touches `canvas_elements`: publish transaction, staleness + lock checks, layout copy, scoped hide/delete |
| `lib/grants.php` | `GrantStore`, `Actor` — the **only** SQL against `display_permissions`; `Actor` answers "may this account open this Display" |
| `lib/display_admin.php` | `DisplayAdmin` — create/edit/delete a Display across all three tables; writes no SQL itself |
| `lib/display_request.php` | Which Display a request means, and whether the actor may have it. Both the Builder and every API write resolve through here |
| `lib/plain_text.php` | `toPlainText()` — signage content is plain text (ADR-0002) |
| `lib/error_policy.php` | The error policy, set in code: errors off, logging on, the three handlers, and the notice a Screen / an endpoint / a person gets when something breaks |
| `lib/alerts.php` | `AlertMailer` — one email per problem per hour to admins, rate-limited and addressed from files rather than the database |
| `tools/selftest_layout.php` | `php tools/selftest_layout.php` — real modules, in-memory SQLite, **462 checks**. Run before pushing |
| `tools/rehearse_phase1.php` | Rehearses schema convergence, scoping, grants and the lock against a **copy** of live data |
| `config.php` | Site constants (`SITE_NAME`, `MAIL_FROM`); loads `branding_config.php` |
| `db_connect.php` | PDO `$pdo`; loads creds from `../../private/db_credentials.php` |
| `auth.php` | `session_start`; `requireLogin/requireAdmin/isAdmin/currentUser`; `csrfToken()/verifyCsrf()`; the login-lockout columns |
| `branding_config.php` | Generated brand theme (`BRAND_*` constants) |
| `login.php` / `logout.php` | Auth; account-keyed DB login lockout (5 tries / 15-min window) |
| `.htaccess` | Server config: index/sensitive-file blocks, security headers, PHP hardening, HTTPS redirect. Frames `viewer.php` for external widgets (see §8) |
| `lib/.htaccess`, `tools/.htaccess` | Make both folders unreachable from a browser. **Deploy them with the folders** |
| `reset_password.php` | 2-step emailed 6-digit passcode reset (30-min expiry) |
| `setup.php` | First-run admin creation; self-disables once a user exists. **Delete on server after setup** |
| `setup_branding.php` | Redirect shim → `admin_panel.php?tab=branding` |
| `builder.php` | ~3050-line canvas editor for one Display, mostly inline JS. The heart of the app. Also the read-only mode and the lock heartbeat |
| `admin_panel.php` | Six tabs: User Management, **Displays** (+ the grant matrix), Display Branding, Site Branding, Settings, Work Area |
| `crud.php` | Asset Library (text/image/video), shared by every Display |
| `api.php` | JSON API: `get_layout` (public), `get_editor_layout`, `get_assets`, `upload_file`, `upload_video`, `publish`, `hold_lock`, `lock_state`, `release_lock`, `take_over_lock`, `save_brand_styles`, `get_canvas_elements`, `set_element_hidden`, `delete_canvas_element` |
| `viewer.php` | Public fullscreen renderer for one named Display; polls `get_layout` every 30s |
| `help.php` | In-app user guide |

## 4. Database

Tables: `users`, `password_resets`, `assets`, `block_styles`, `canvas_elements`,
**`displays`**, **`display_permissions`**, and the retired `canvas_settings`.

`schema.sql` is the structure the code expects and what a fresh rebuild should
produce. **The live server lags it** and closes the gap itself:

- `ensureSignageSchema()` in `lib/schema.php`, on every authenticated request —
  the newer `canvas_elements` columns (`text_align`, `z_index`, `hidden`), the
  widened ENUMs, `displays`, `display_permissions`, the publish stamp and lock
  columns, the Brand Standards rows, and the `display_id` backfill that hands
  every pre-existing element to the drive-thru Display.
- `ensureLockoutColumns()` in `auth.php`, on the first login or password reset —
  the three lockout columns on `users`.

Every statement is idempotent, and the backfill re-runs on every authenticated
request: if a partly applied migration ever left elements unscoped (which shows as
a **blank sign**), loading an admin page repairs it. The check is
`SELECT COUNT(*) FROM canvas_elements WHERE display_id IS NULL` — it should be 0.

The public `get_layout` poll deliberately runs **no** DDL; every Screen hits it
every 30 seconds forever.

`canvas_settings` is retired but deliberately left on the server as a rollback
artefact. Nothing reads it except the one-time seed that carries its background
onto the drive-thru Display.

Roles: `admin` (full) and `basic` (content inside existing sections, on granted
Displays only). Grants live in `display_permissions`; admins are never granted
anything, they hold every Display by role.

## 5. Deployment facts a new session should know

- `db_connect.php` expects `../../private/db_credentials.php` (outside webroot)
  defining `DB_HOST/DB_NAME/DB_USER/DB_PASS`. Not in repo by design.
- The live `branding_config.php` still uses the default `SITE_NAME`
  ("Store Display System"), not "Lummi Bay Market".
- `viewer.php` requires **no login**, so any screen on the network can display it.
- **Every Viewer URL names its Display** (ADR-0003):
  `…/viewer.php?display=drive-thru`. A bare `viewer.php` shows a "no display
  specified" notice — by design, not a fault. This is why the deploy includes a
  one-time URL change on the TV and in the SmartSign2Go widget.
- To read a Display without logging in:
  `GET https://srcresort.com/lbm/api.php?action=get_layout&display=drive-thru`
  returns its layout JSON. WebFetch can read live pages but can't run JS or log in.
- Uploads live in `uploads/` on the server only (git-ignored), as does the private
  credentials file.
- **The error log is written on the server and is not in the repo.** `ErrorPolicy`
  picks the first writable of: `LBM_LOG_DIR` if the credentials file defines it,
  `../../private/logs/` (beside the credentials, outside the webroot — preferred,
  and only tried if `private/` already exists), then `logs/` inside the app, which
  it creates with a deny-all `.htaccess`. `lbm-error.log` rotates to `.1` at 2 MB;
  the alert rate-limiter's `alert-*.stamp` files and the cached admin recipient
  list sit beside it. **Admin Panel → Settings → Errors and Alerts** prints which
  path won, when it was last written, and who an alert would reach. If it says
  "Nowhere to write", nothing that goes wrong is being recorded and no alert can be
  sent — that is the one row on that screen worth acting on immediately.
- Alerts go out via `mail()` to admins with an email address on file, at most one
  per problem per hour. The recipient list is refreshed every time an admin opens
  the admin panel, so a fresh install alerts nobody until somebody has been there
  once.

## 6. The multi-display build (this branch)

Six phases, each shippable on its own, planned and tracked in
[`docs/roadmap-multi-display.md`](docs/roadmap-multi-display.md):

1. **Display-scoped data model and API** — `displays`, `canvas_elements.display_id`,
   every read and write scoped. Publish used to `DELETE FROM canvas_elements`
   unscoped; that is now one transaction against one Display, behind a row lock.
2. **Dimensions from the Display record** — no `1920`/`1080` literal left in
   `builder.php` or `viewer.php`; the Builder gained zoom-to-fit for canvases that
   don't fit the editor frame.
3. **The admin Displays screen** — create, edit, activate, delete, with
   confirm-by-name deletion and the screen name tag shown wherever a Display is.
4. **Per-Display grants** — `display_permissions` plus a grant matrix, enforced
   server-side on every read and write, not just hidden from the picker.
5. **The edit lock and the read-only Builder** — one editor per Display, the lock
   held by *work* rather than presence, 15-minute idle release with a warning at
   13, admin takeover, and publish refused when the lock has moved on.
6. **Docs and schema** — this file, `README.md`, `CONTEXT.md`, `help.php` and
   `schema.sql` brought in line with what the code now does.

Decisions, with the alternatives that were rejected, are in
[`docs/adr/`](docs/adr/): 0003 (every Viewer names its Display), 0004 (canvas size
fixed at creation), 0005 (grants say which, role says how much), 0006 (publish
staleness check, no version history), 0007 (one editor per Display).

## 7. Open items

- **Deploy it.** The only open item of substance. `docs/roadmap-multi-display.md`
  ends with a 22-step *"Before this reaches the sign"* checklist, in order, for one
  visit: back up, rehearse on a copy, upload (including `lib/` and `tools/` with
  their `.htaccess`), sign in once to converge the schema, check the sign at its new
  URL, then re-point the TV and the SmartSign2Go widget. Steps 15–21 need a second
  account, two browsers, and one unavoidable 15-minute wait.
- **Nothing here has run against MySQL or in a browser.** Verification so far is
  `php -l`, 316 self-test checks against SQLite, and the invariant greps in
  BUILD-REFERENCE §5. `php tools/rehearse_phase1.php --host=… --user=… --pass=… --db=<copy> --confirm-copy`
  is the tool for the MySQL half; expect "Rehearsal clean."
- **The cutover window.** Between deploying and re-pointing the screen, the bare
  `viewer.php` URL shows the notice instead of the sign. Same visit, or closed hours.
- CSRF end-to-end sanity check worth doing live: sign in → edit an asset → publish;
  all succeed, while a stale or forged POST gets "Security token mismatch."

Everything from the previous session is resolved and merged: the account-keyed
login lockout (ADR-0001), the security-hardening pass (stored XSS → plain-text
content per ADR-0002, session cookie flags, reset enumeration), the Asset Library
SVG/non-image block, the new-text-block highlight, and the viewer framing +
kiosk scroll lock. `git log origin/main` has the detail.

## 8. Conventions / gotchas for the next session

- **Data access lives in `lib/`.** A new query means a new method on the owning
  module, not a `$pdo` handed to a page script. One module per table (§3).
- **PHP 7.1-compatible syntax** — the live server's version is unverified and
  `.htaccess` still carries `mod_php7` blocks. No typed properties, constructor
  promotion, enums, `readonly`, `match`, or arrow functions. This container has a
  much newer PHP, for `php -l` only.
- Before pushing: `php -l` every touched file, then `php tools/selftest_layout.php`.
  A self-test failure is a release blocker, not a broken test.
- **No undo exists anywhere in this app.** Publishing overwrites. Prefer refusing a
  write to merging one — that is why publish has both a staleness check and a lock
  check, and why neither tries to merge.
- Use the vocabulary in [`CONTEXT.md`](CONTEXT.md) — Display, Viewer, Screen, screen
  name tag, canvas, grant, edit lock — in code, comments and UI copy.
- Files use flat relative includes — keep page scripts at repo root.
- Commit messages: descriptive; do not include model identifiers.
- `builder.php` is ~144 KB and mostly inline JS; `admin_panel.php` ~84 KB. `php -l`
  cannot see JavaScript errors, so read edited JS carefully rather than trusting the
  lint. Read-only mode in the Builder is *server-rendered* — editing controls are
  absent from the HTML, not disabled — so a new control belongs inside the
  `if (!$readOnly)` block or it is reachable by someone who doesn't hold the lock.
- The `.htaccess` framing exception keeps `X-Frame-Options: SAMEORIGIN` on the whole
  app but drops it for `viewer.php` (`Content-Security-Policy: frame-ancestors *`),
  so the public Viewer embeds in external signage widgets. `viewer.php` is
  public/read-only, so framing it carries no clickjacking risk, and it is locked
  against scrolling in both axes for kiosk use. Anything that renames or splits
  `viewer.php` breaks both rules for every Display at once.
- A Screen's resolution is not a Display's canvas size. `scaleToFit()` scales the
  canvas to whatever screen it lands on, so swapping in a 4K TV of the same shape
  needs no change at all. A genuinely different *shape* means a new Display built at
  those dimensions — canvas size is fixed at creation (ADR-0004).
- The user uploads files in batches of ≤5 (platform limit). A git stop-hook requires
  untracked files to be committed, so commit each batch.
