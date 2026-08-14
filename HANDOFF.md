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
- **Rehearsal install:** https://www.srcresort.com/lbm-test/ against
  `silverad_lummi_market_drive_thru_2`, a copy. **It is only isolated if
  `/home/ACCOUNT/private/db_credentials_lbm-test.php` exists** — both folders walk up to
  the same `private/` directory, so without that file the copy connects to the live
  database and behaves perfectly while doing it. Settings → This Server reports **This
  install** and **Database**; that card is the only thing in the app that can tell you.
  See [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md) §E.
- **PHP on this host: 8.2** — and this is now *observed twice*, not stated: the runtime
  reports **8.2.33**, and cPanel's MultiPHP Manager shows `srcresort.com` pinned
  explicitly to `ea-php82` (both 2026-08-11, §7). It is the reason the repo's floor is
  8.2 rather than 7.1. Note the pin is **explicit, not `inherit`** — the system default
  is PHP 8.3, and this domain does not follow it.
- **Stack:** vanilla PHP (PDO, `ERRMODE_EXCEPTION`, real prepared statements),
  no framework, inline CSS/JS. Uses `interact.js` in the builder for drag/resize.

> **The multi-display build runs in `lbm-test/` as of 2026-08-12, and has never run
> live.** That first half is new and the sentence above it said "has never run on the
> server" for two phases. The rehearsal install now signs in, converges the schema
> against its own database and opens the Builder. The live site is still the
> single-sign app: nothing in §6 has been deployed there, and deploying it is a
> scripted 22-step visit — see §7.
>
> **The rehearsal has now been driven, not just loaded.** The browser pass
> ([`docs/browser-pass.md`](docs/browser-pass.md), work-lanes lane 0) was walked in full
> on 2026-08-12/13: all ten sections, seven defects, all seven fixed and re-checked in
> the browser. Undo, the Viewer, the read-only Builder and both timezone questions came
> through clean. The fixes are the six §5 rows tagged *browser pass step …*, and every one
> of them changes something a person will see on the live sign — read those rows before
> the deploy, not after. **Re-run that list against the live install once it is deployed**;
> nothing about it was used up by running it here, and `lbm-test/` is a different
> database with different data.

## 2. Git / branch

**Start from `main`.** The phase-by-phase work is finished and merged; the rhythm
now is one branch and one PR per item from
[`docs/reviewed-decisions.md`](docs/reviewed-decisions.md), cut fresh from `main`
each time. There is no standing working branch — an old `HANDOFF` said there was
(`claude/app-update-planning-1pjqfr`), and following it would have branched off
something six merges behind.

What has landed, oldest first:

| PR | What | In `main` as |
|----|------|--------------|
| #1 | The 22 vendored skills under `.claude/skills/` | `aa9c2af` (squashed — its branch head is not an ancestor) |
| #2 | Security hardening, login lockout, embeddable viewer, housekeeping | `ff361cc` |
| #3 | Multi-display, phases 1–6 | `2a5cd84` |
| #4 | Decision #38, first attempt | **closed unmerged** — superseded by #7, its four unique items carried across in `003f1f1` |
| #5 | Decision #40, the section banner | `50822b5` |
| #7 | Decision #38, and the three things that fix left standing | `4961fb8` |
| #8 | The log's size read from the filesystem, not a cached stat | `5b410c1` |

**Two sessions can be working at once, and twice now they have collided.** Both
times it was invisible until somebody looked:

- **#4 and #7 were the same decision**, started in parallel from the same `main`.
  One of them had to be closed and hand-salvaged.
- **Two branches each took the next free `§4` letter** and produced two `### 4u.`
  sections. Git merges those without a conflict marker, because they are not the
  same lines. `php tools/check_doc_numbering.php` exists for this and runs in CI
  even when the step before it failed; run it before opening a PR that adds a
  write-up, and before merging one that has been open a while.

Before starting an item, check whether a branch already exists for it.

## 3. File map (page scripts at repo root; data access in `lib/`)

Page scripts are **thin adapters** over the modules in `lib/`. One module owns
each table, and nothing outside it may write SQL against that table. Read
[`docs/BUILD-REFERENCE.md`](docs/BUILD-REFERENCE.md) before changing any of them —
it is the standing contract, with the invariants and where later work attaches.

| File | Role |
|------|------|
| `lib/schema.php` | `ensureSignageSchema()` — every idempotent `CREATE`/`ALTER`, the drive-thru seed, the `display_id` backfill, the Brand Standards seed. Reads `information_schema` once and runs only what is actually missing, so a converged database is issued no `ALTER` at all — and emails an admin when a statement the catalogue said it needed is refused anyway |
| `lib/displays.php` | `Display`, `Background`, `LockState`, `DisplayStore` — the **only** SQL against `displays`; screen name tag rules; the edit lock |
| `lib/layout_store.php` | `LayoutStore` — the **only** place that touches `canvas_elements`: publish transaction, staleness + lock checks, layout copy, scoped hide/delete |
| `lib/grants.php` | `GrantStore`, `Actor` — the **only** SQL against `display_permissions`; `Actor` answers "may this account open this Display" |
| `lib/display_admin.php` | `DisplayAdmin` — create/edit/delete a Display across all three tables; writes no SQL itself |
| `lib/display_request.php` | Which Display a request means, and whether the actor may have it. Both the Builder and every API write resolve through here |
| `lib/plain_text.php` | `toPlainText()` — signage content is plain text (ADR-0002) |
| `lib/login_attempt.php` | `LoginAttempt` — what a refused sign-in is allowed to say, and in what order the questions are asked (ADR-0008). Every question that does not depend on the password is answered first, so no sentence and no counter can tell a guesser the password was right. Holds no PDO: it decides, `AccountStore` writes |
| `lib/request_scheme.php` | `RequestScheme` — is the browser's own leg of this request HTTPS, and may the session cookie therefore claim `Secure` (ADR-0009). Asserting it over plain HTTP made every sign-in loop back to a blank form in silence. Also the scheme the viewer address is built from, which had its own copy of the question and got it wrong behind a proxy |
| `lib/markup.php` | `Markup::text()` — the only `htmlspecialchars` in the app, with both flags named rather than defaulted (the default changed in PHP 8.1). `Markup::jsInAttr()` for a value used as JavaScript inside an attribute, which HTML escaping does **not** make safe |
| `lib/site_chrome.php` | The store's own colours, read through `Color::read()` rather than escaped — they land in a `<style>` block, where there is no delimiter for an entity to neutralise and a value that is not a colour is CSS. Also holds the defaults, which four pages used to carry a copy of each, and reads the generated config through `BrandingConfig`, which owns it |
| `lib/error_policy.php` | The error policy, set in code: errors off, logging on, the three handlers, and the notice a Screen / an endpoint / a person gets when something breaks. `report()` is for a problem the app survived, and throttles the log as well as the email when the problem repeats on its own |
| `lib/alerts.php` | `AlertMailer` — one email per problem per hour to admins, rate-limited and addressed from files rather than the database |
| `lib/assets.php` | `AssetLibrary` — the **only** SQL against `assets`. Publishing no longer shares a row between signs; pooled rows carry a marker so the ones nothing uses can be tidied and the ones a person made never can |
| `lib/branding.php` | `BrandingConfig` / `BrandingWrite` — the **only** writer of `branding_config.php`, which every page of the app requires. Renders it, parses it, writes a temporary copy, reads that back byte for byte, and swaps it in with one `rename()`, so a reader gets the whole old file or the whole new one and a failed save leaves the site on exactly what it had |
| `lib/install_paths.php` | Which install this folder is, and whose credentials it uses. Pure. One account can hold the live app and a rehearsal copy at the same depth, so a single shared credentials path made an unmodified copy connect to the **live** database in silence — the folder's own name selects `private/db_credentials_<folder>.php` when it exists, and the shared file otherwise, so no tracked file has to differ between the two |
| `lib/upload_limits.php` | `UploadLimit` — how big a file can actually reach this server (the smallest of 50 MB, `upload_max_filesize`, `post_max_size`), and the detection of a request body PHP silently threw away |
| `tools/selftest_layout.php` | `php tools/selftest_layout.php` — real modules, in-memory SQLite, **2221 checks** — and the same suite against real MySQL when `SELFTEST_MYSQL_DSN` is set, where it runs 2246 (that figure is the SQLite one plus a count of the engine-only section; see the note above `reportChecks()`). Run before pushing |
| `tools/mutate.php` | `php tools/mutate.php lib/whatever.php` — breaks that file one way at a time and runs the suite each time, to answer whether the checks over it *can* fail (#50, invariant 30, §4aq). Minutes rather than seconds, so it is a tool to run over what you changed rather than a gate. `--list` shows what it would break without running anything |
| `tools/selftest_builder_readonly.js` | `node tools/selftest_builder_readonly.js` — builder.php's own JS against a DOM holding only what a read-only page emits — including the Brand control, which that page *does* get, and the menu that changes it, which it does not, and the Workspace Theme picker, which it gets in full — **68 checks** |
| `tools/selftest_builder_uploads.js` | `node tools/selftest_builder_uploads.js` — the same JS as an admin who can edit, driving a stubbed `XMLHttpRequest` through every way an upload can end (and what it does when it loses the display mid-edit, and that each of the page's two opening reads reports its own failure rather than sharing one sentence, and that Publish cannot be fired twice), **100 checks** |
| `tools/selftest_builder_colors.js` | `node tools/selftest_builder_colors.js` — the same JS again with the inspector open on stored values the CSSOM cannot parse, which it discards without saying so; the publish payload used to turn that silence into black, **47 checks** |
| `tools/selftest_builder_editing.js` | `node tools/selftest_builder_editing.js` — the same JS under the premise of an admin having an ordinary good day: the six inspector controls that quietly did less than they claimed (#42), and every canvas change committing its undo step, **182 checks** |
| `tools/selftest_builder_undo.js` | `node tools/selftest_builder_undo.js` — the same JS under the premise of an admin who wants the last thing they did back: the canvas round-tripped through snapshot and restore as whole strings, so a rebuild that drops a field this suite never thought to name still fails, plus every mutating control driven to prove it leaves exactly one step, **139 checks**. Undo is the one thing in this app that can be taken back (ADR-0010) |
| `tools/selftest_builder_brands.js` | `node tools/selftest_builder_brands.js` — the same JS under the premise of an admin deciding which venue a sign belongs to: picking a Brand repaints the canvas, sends **no request at all**, and records no undo step, while Publish is what writes it; the palette is offered above all four colour controls and enforced nowhere, and Venue Logo drops a block already linked to the brand's library row, **121 checks** |
| `tools/selftest_builder_theme.js` | `node tools/selftest_builder_theme.js` — the same JS under the premise of somebody changing a setting about *themselves* while unpublished work sits on the canvas: choosing a Workspace Theme sets thirteen CSS custom properties and touches nothing else, holds the canvas nodes by identity, and moves neither the undo stack nor its baseline. It also drives the save that fails — the paint happens before the round trip, so a swallowed failure would leave the screen showing a theme the account is not on — and both the reply that says no and the request that never arrives, **110 checks** |
| `tools/selftest_viewer.js` | `node tools/selftest_viewer.js` — `viewer.php`'s own JS: the poll loop against a `fetch` the test controls, and every renderer given a block with nothing in it. The page that runs unattended on a television, where a throw is a blank sign, **169 checks** |
| `tools/check_invariants.php` | `php tools/check_invariants.php` — the mechanical greps from BUILD-REFERENCE §5, run as pass/fail against the whole file set rather than a count, with comments dropped so prose about a rule does not fail it. **60 checks.** Prints what it deliberately does not cover on every run |
| `tools/check_doc_numbering.php` | `php tools/check_doc_numbering.php` — no two write-ups share a number, no citation dangles, the invariants run unbroken, and **the next free section letter**, which is the question every branch cut from the same base has to answer. **6 checks** |
| `tools/audit_colors.php` | `php tools/audit_colors.php` — **read-only, and the one tool here that is safe to point at the live database.** Reports every stored colour the app cannot read: the element colours that make a Display refuse to publish, and the backgrounds and Brand Standards rows that quietly render in a colour nobody chose, and the brand colours in `branding_config.php`, which no sign uses and which are reported under a heading that says so. It changes nothing; a person picks the colour. Exit 0 clean, 1 with findings, 2 if it could not look |
| `tools/rehearse_phase1.php` | Rehearses schema convergence, scoping, grants and the lock against a **copy** of live data. It also publishes every element type and block subtype the schema allows and reads them back, checks that a deleted Display really cascades, and prints which of the five page-added columns landed |
| `config.php` | Brings the eight branding constants (`BRAND_*`, `SITE_NAME`, `MAIL_FROM`, `MAIL_FROM_NAME`) into being through `lib/branding.php`. The one place that loads `branding_config.php` |
| `db_connect.php` | PDO `$pdo`; loads creds from `../../private/db_credentials.php` |
| `auth.php` | `session_start` with the cookie attributes `RequestScheme` decides, in both the pre-7.3 and 7.3+ forms; `requireLogin/requireAdmin/isAdmin/currentUser`; `csrfToken()/verifyCsrf()`. It no longer adds the login-lockout columns — those are gated entries in `signageSchemaPlan()` |
| `branding_config.php` | Generated brand theme (`BRAND_*` constants) plus `SITE_NAME` and the two mail-from fields. Written only by `lib/branding.php`, and never in place |
| `login.php` / `logout.php` | Auth; account-keyed DB login lockout (5 tries / 15-min window, stamped in UTC). Every sentence it can print and every counter it writes are `LoginAttempt`'s, so no message it shows depends on something a stranger must not learn. It runs no DDL and it carries a CSRF token |
| `.htaccess` | Server config: index/sensitive-file blocks, security headers, PHP hardening, HTTPS redirect. Frames `viewer.php` for external widgets (see §8) |
| `lib/.htaccess`, `tools/.htaccess` | Make both folders unreachable from a browser. **Deploy them with the folders** |
| `reset_password.php` | 2-step emailed 6-digit passcode reset (30-min expiry) |
| `setup.php` | First-run admin creation; self-disables once a user exists and then **deletes itself** — at the end of a successful setup, or on the first request that finds it disabled. It reads the answer back from disk, so it never claims to have gone while it is still being served |
| `setup_branding.php` | Redirect shim → `admin_panel.php?tab=branding` |
| `builder.php` | ~5500-line canvas editor for one Display, mostly inline JS. The heart of the app. Also the read-only mode, the lock heartbeat, and the Brand a sign wears — staged there and written by Publish |
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
  columns, the `assets` pool marker, the Brand Standards rows, and the `display_id`
  backfill that hands every pre-existing element to the drive-thru Display.
- `ensureLockoutColumns()` in `auth.php`, on the first login or password reset —
  the three lockout columns on `users`.

Every statement is idempotent, and **only the ones the database actually needs are
sent**: convergence reads `information_schema` once per request and skips whatever
is already there, so once the live database has caught up it is issued no
`ALTER TABLE` at all. That matters because an `ALTER` locks `canvas_elements` — the
table every sign's layout lives in — and a lock that waits on a publish makes the
Screens' polls wait too. See BUILD-REFERENCE §4o.

The `display_id` backfill re-runs on every authenticated request *while the column
can still hold a `NULL`*: if a partly applied migration ever left elements unscoped
(which shows as a **blank sign**), loading an admin page repairs it. Once the column
is `NOT NULL` there can be no unscoped row to find, which is when it stops. The
check is `SELECT COUNT(*) FROM canvas_elements WHERE display_id IS NULL` — it should
be 0.

The public `get_layout` poll deliberately runs **no** DDL; every Screen hits it
every 30 seconds forever. It has one exception, and it is bounded: if a table is
*genuinely absent* — a first-ever request after a deploy, which may well be a
Screen's poll rather than an admin signing in — the failed read triggers one
convergence, so the sign comes up on its own rather than staying dark until
somebody happens to log in. That repair refuses to run inside a transaction, runs
one-at-a-time across the whole installation, and will not try again for five
minutes however many Screens are asking. See BUILD-REFERENCE §4q.

`canvas_settings` is retired but deliberately left on the server as a rollback
artefact. Nothing reads it except the one-time seed that carries its background
onto the drive-thru Display.

Roles: `admin` (full) and `basic` (content inside existing sections, on granted
Displays only). Grants live in `display_permissions`; admins are never granted
anything, they hold every Display by role.

## 5. Deployment facts a new session should know

- **An upload is not a deploy: [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md) lists what
  to leave alone.** The repo and the server hold different files. `branding_config.php`
  is generated on the server and the repo's copy is a stale default; `setup.php` was
  deleted from the server and re-uploading restores the first-admin form; `uploads/` and
  the log folder exist only on the server and are in no backup. Uploading the tree over
  the top reverts the first, restores the second, and — with a mirroring client — deletes
  the third, all silently. That file also has the five checks to run afterwards. It
  applies to **every** upload, not just the multi-display one.
- `db_connect.php` expects `../../private/db_credentials.php` (outside webroot)
  defining `DB_HOST/DB_NAME/DB_USER/DB_PASS`. Not in repo by design.
- The live `branding_config.php` still uses the default `SITE_NAME`
  ("Store Display System"), not "Lummi Bay Market". Which is also why overwriting it
  costs almost nothing *today* — the list above is what keeps that true once somebody
  has used the Branding page.
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
- **The upload limit is the host's, not the app's.** `UploadLimit` takes the
  smallest of the app's 50 MB and PHP's `upload_max_filesize` / `post_max_size`.
  **Admin Panel → Settings → This Server** prints the effective number and says when
  the host is the one deciding — worth reading before promising anybody a video will
  fit. If it is small, raising `php_value upload_max_filesize` / `post_max_size` in
  `.htaccess` only works under mod_php; on this host that is unverified, which is why
  no code depends on it.
- **The Asset Library grows `Auto:` rows.** Publishing a text block copies its words
  into `assets`, so editing and republishing leaves the earlier copies behind. A
  publish clears up what its own Display stranded; anything else (a block removed in
  the admin Work Area) collects until an admin presses **Tidy up** on the Asset
  Library page, which appears with a count only when there is something to remove.
  It never touches a row somebody typed, uploaded, or renamed.
- `assets.auto_pooled` is added by schema convergence on the first signed-in
  request. Until it lands, the tidy-up identifies a pooled row by its `Auto: ` label
  prefix instead — workable, and reported in **Settings → Database Structure**.
  The statement that marks the *existing* pool runs in the same request that adds the
  column, and only that request, because re-running it would un-adopt a row somebody
  had renamed. So if the `ALTER` lands and that one `UPDATE` does not — a dropped
  connection between the two — Tidy up will report 0 forever on a library full of
  `Auto:` rows. Nothing is lost and no sign is affected; the recovery is one
  statement by hand:
  `UPDATE assets SET auto_pooled = 1 WHERE auto_pooled = 0 AND label LIKE 'Auto: %';`
- **Schema convergence issues no `ALTER TABLE` once the live database has caught
  up.** It reads `information_schema` first. If a host ever hides the catalogue it
  falls back to attempting everything, exactly as earlier builds did — slower and
  noisier, never wrong. `tools/rehearse_phase1.php` reports what is still wanted
  after converging a copy, which is the fastest way to see whether the live
  database is actually finished.
- **An email titled "Schema updates are being refused" means a statement the
  database said it needed would not apply.** It lists them in words and gives the
  database's reason. Read **Settings → Database Structure** before doing anything
  else: a red row there is a real missing column and the email says what it costs;
  **all rows green** means the refusal is a foreign key or an index the database
  will not create under the name this app asks for — most likely `users.id` being
  `unsigned` on the live table while `displays.last_published_by` is signed — and
  the data is fine. One email per hour per set of failures, and only on a host whose
  catalogue can be read, so this cannot arrive because of a hosting quirk.
- **A file called `schema-repair.lock` appears beside the error log.** Expected, and
  it should be zero bytes. It is how two requests arriving at the same moment produce
  one schema convergence instead of two racing for the same `ALTER` — which on deploy
  day would have emailed the alert above about its own success. It is an `flock`, so
  nothing is left holding it if a request dies; deleting it while the site is idle is
  harmless, and there is no reason to.
- **A sign can now fix the database on its own, once.** If a table is genuinely
  missing on the first request after a deploy, the Screen's own poll converges the
  schema rather than showing the notice until somebody signs in. It will not retry
  for five minutes, however many Screens are pointed at the app, so a repair that
  *cannot* succeed costs about twelve attempts an hour rather than seven thousand.
  The one place it will never run is inside a publish: DDL commits an open
  transaction in MySQL, and committing half a publish and then reporting it failed is
  the worst thing this app could do to somebody's work.
- **Taking somebody's access to a display away now frees the sign and tells them.**
  Untick a display in *Who can edit which display* and, if that person had the Builder
  open on it, their edit lock is released in the same write — so the next person can
  start immediately instead of waiting out fifteen minutes for a lock held by somebody
  who is no longer allowed near the sign. Their page shows a red bar within a minute
  saying the access was removed; what they had done stays on their screen to be copied
  out, and nothing unpublished reaches the display. The same bar appears for somebody
  who was only watching it read-only.
- **The permissions grid only changes what was on the screen you saved.** It used to
  read "not ticked" and "not on the page" as the same thing, so a tab left open while a
  colleague added a display and assigned it could silently undo their assignment. Two
  admins can now work on that grid at once. It also redirects after saving, so pressing
  F5 — or the back button — no longer re-sends the whole grid over a page that has
  since changed. The success line appears once and is gone on reload; that is
  deliberate, not a lost message.
- **Making somebody an admin clears the individual displays they were given**, and
  says which ones. An admin can edit every display, so those assignments meant nothing
  and were shown nowhere — but they used to stay in the database, which meant making
  that person a basic user again silently handed back whatever they had months earlier.
  A demotion now starts from nothing (assign the displays they should have) and
  releases any display they had open, since from that moment they cannot reach it to
  release it themselves.
- **Every way of taking a sign off somebody now frees the lock and tells them.** There
  are four: a revoked assignment, a demotion, a **suspended account**, and a **display
  turned off** (which stays editable by admins and stops being editable by anyone else,
  so only a basic account's lock is freed — an admin retiring a sign they are editing
  keeps it). Renaming a screen name tag is deliberately not one: it changes the address,
  not who may edit, so the person keeps the display and their page asks them to reload.
  The Builder's red bar now says which of the five happened, because what to do next
  differs — ask an admin, copy your work, reload, or sign in again.
- **A lock held by somebody who cannot sign in is not honoured at all.** Independently
  of the above: the moment an account is suspended or closed, any sign it was holding
  reads as free, so a colleague can pick it up immediately rather than waiting out
  fifteen minutes or asking an admin to force it. This one also unsticks any row already
  stranded on the live database — nothing is swept, the rule is applied on read.
- **A password reset now either happens or does not.** The code, the new password and
  the login-lockout clear are one transaction, so nobody is told the reset failed
  while holding a password that works, or told it succeeded when it did not. There is
  a second message on that screen: *"Your code was accepted, but the password could
  not be changed just now"* means exactly that — the code was right, nothing was
  written, and trying again in a moment is the correct advice. It costs one of the
  five guesses, because the guess is spent by asking and is never refunded by a
  failure. The reset screen also stopped adding the three lockout columns; `login.php`
  adds them on any sign-in attempt, and the clear now copes if they were never added
  at all, which used to raise "unknown column" *after* the password had changed.

### Deploy notes since the multi-display checklist

The numbered visit in
[`docs/roadmap-multi-display.md`](docs/roadmap-multi-display.md) → *"Before this
reaches the sign"* is **one specific deploy**, written when Phase 6 landed. Work
merged after it does not get renumbered into that list, so anything a later change
needs at deploy time is a row here. Read both before going to the server.

**When a PR changes what a deploy has to do, add a row.** One line, with the
decision number and the write-up it comes from. Several of them below are "nothing
to do" — they are here because they will be noticed and are not faults.

| Since | At deploy time | Why |
|-------|----------------|-----|
| **Sections now say when they are hiding a block** (browser pass step C, §4as) | Nothing to do. Expect an orange badge along the bottom edge of any section whose blocks stick out past it — `⚠ 1 CLIPPED — NOT ON THE SIGN` — the moment a layout opens. | It is new, it is not a fault, and on a layout copied from live it may appear immediately: a section that was already smaller than its contents has been hiding those blocks from the sign all along, silently. The badge is the first time anybody is told. Sections clip in `viewer.php` too, so nothing about what the sign shows has changed — only whether the Builder mentions it. ADR-0004 carries the correction to the consequence that said this warning was unnecessary. |
| **The four layer buttons renumber the whole group** (browser pass step D, §4at) | Nothing to do. Expect the **Layer** number to change on blocks nobody selected the first time Back, Backward, Forward or Front is pressed on a Display copied from live. | Every block on the old sign is on layer 1, so its paint order came from a tie the buttons could not move — Back and Backward did nothing at all, and Front was the only direction that worked. The buttons now give the group distinct layers, 1..n, which is what makes the number on screen mean the order on the sign. Those numbers are published, so the first press on each Display writes new `z_index` values for its blocks. **No block moves visually as a result** — the renumbering preserves the order the sign was already showing. |
| **Every file picker now refuses before it uploads** (browser pass step F, §4au) | Nothing to do, but check one number: Admin Panel → Settings → This Server, *largest file that can be uploaded*. If the host's ceiling is **under 10 MB**, the Asset Library form and the logo form will state that smaller figure rather than 10 MB / 2 MB. That is correct. | The Asset Library and the brand logo carried their own size limits inline and stated one of them nowhere on the page; both now come from `UploadLimit`, capped by what can actually reach the server. More importantly, both pages lacked the dropped-body guard `api.php` has had for a year, so **a file over the host's `post_max_size` was answered with a 403 reading "Security token mismatch. Please go back and try again."** — a security failure reported for a size problem, where going back and trying again reproduces it. If anybody has ever reported that message while uploading, this was why. |
| **A locked section no longer takes new blocks** (browser pass step G, §4av) | **Tell whoever edits prices.** If a section on the live layout is locked and somebody is used to adding blocks inside it, they now get a refusal instead. The fix is to select the section, un-tick **Lock this block**, add, and tick it again. Same row, second door: **an account with no Display assigned is no longer offered the Asset Library link** in the Builder's top bar. If somebody is meant to be curating the library without holding a sign, give them a grant or use an admin account — the link is drawn under the same condition `crud.php` draws its add form under. | Locking refused a drag and refused a resize and then accepted a new block silently — the third way of changing a locked section, and the one the lock exists to prevent. This narrows what Lock means, so `help.php` gained a *Locking a section* subsection. Watch for the corner: **if every section on a sign is locked, a basic account has nowhere to add at all**, because a basic account must aim at a section first. That is correct and it is a thing to know before locking everything. |
| **A locked block now refuses six things, not two** (browser pass step G, §4aw) | **Tell whoever edits the signs**, and expect it to surprise somebody. A locked block could always be dragged nowhere — but it *could* be deleted, moved and resized by every other control: the Delete button, the Delete key, the Inspector's X/Y/W/H boxes, and both rows of Align buttons. All six now refuse, each with a sentence. **A section holding any locked block also refuses to be deleted**, whether or not the section itself is locked. | The lock was read in three places, all of them on the way in from a pointer, so the 🔒 icon kept its promise against the mouse and nothing else. Every door now asks one predicate. Two knock-ons to know before locking things on the live sign: a multi-selection Align moves the unlocked members and says how many it left behind, and **you cannot delete a section until you unlock what is inside it** — the refusal counts them for you. Unlocking is never refused, and a lock still does not stop text or colour edits. |
| **The Builder now says who published and when** (browser pass step H.1, §4ax) | Nothing to do, but two things to expect. The top bar gains a **published by sky, Aug 12 at 3:42pm** line beside the canvas size, and the green publish note names the account and time too. On the live sign the *first* reading may look wrong: every `last_published_at` written before #44's fix is shifted by the host's offset and will read two hours out until that Display is next published, which corrects it (`recordPublish()`'s docblock). A Display nobody has published to reads **not published yet** — and so does one whose only changes were an element hidden or deleted from the admin Work Area, because `advanceLayoutRevision()` records no publisher on purpose. | The fact was recorded correctly for a year and shown in two places, neither of them the Builder: the Admin Panel's Displays tab, and the message a *refused* publish prints. You could find out who last changed the sign by leaving the page, or by failing. It is the same sentence in all three now, so if the Builder and the admin panel ever disagree about a publish, that is a real fault and not a formatting difference. |
| **The database user's MySQL privileges** (rehearsal, 2026-08-12) | Confirm the user owns **SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES** on the target database *before* the first sign-in. Not `ALL PRIVILEGES` — see why. | Convergence issues 22 DDL statements and only two are `CREATE TABLE`; the other 20 are `ALTER TABLE` (14 add or modify a column, 5 add a foreign key, 1 adds a key). A failed schema statement is logged and emailed, never thrown (#9), so **missing privileges do not announce themselves** — the page carries on and dies later at the first query against what was never created. That is how `lbm-test` presented as `Base table or view not found: displays` when the real fault was a `CREATE` the user could not issue. Worse, a user with `CREATE` but not `ALTER` produces a Builder that *loads*: the two tables appear, `canvas_elements.display_id` does not, and Settings → Database Structure reads "Nothing is scoped to a Display. Do not publish." A crash is honest; that state is not. **`DROP`, `TRUNCATE`, `LOCK TABLES` and `CREATE TEMPORARY TABLES` appear nowhere in the plan** and should stay unchecked — in an app with no undo, a privilege it never uses is only risk. |
| **#36** · §4y | **Make sure the webroot directory is writable by the web user**, not just `branding_config.php` itself. Confirm by saving anything on Admin Panel → Branding. If only the file is writable the save refuses with *"Check the folder permissions."* and changes nothing — safe, but branding cannot be edited until it is fixed. | That file is now replaced by writing a temporary copy beside it and `rename`-ing over it, so the permission that matters moved from the file to the folder. |
| **#37** · §4w | **Nothing to do.** Expect **check** marks on the Asset Library the first time it is opened, with a count above the table. | Entries saved before the type rules are labelled with what today's rules would refuse or change. Nothing was rewritten, and no sign changed. |
| **#38** · §4v | **Nothing to do.** Any login lockout in force at the moment of the deploy is released — the store is west of UTC. | `locked_until` moved to UTC and old rows read earlier from here. Bounded: a lockout is never more than 15 minutes out, and the failure counter beside it is untouched. §4v has the east-of-UTC case, which does not apply to this store. |
| **#38** · §4u | **Nothing new to do** — step 4 of the checklist already covers it. The three lockout columns are now added by schema convergence on the first authenticated request rather than by `login.php`. On this installation they are already there. | `ensureLockoutColumns()` was the one piece of DDL in the app a bot could reach with no account. |
| **#46** · §4z | **Upload by [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md), not by dragging the tree over.** Four things on the server are not in the repo or differ from it, and a mirroring client reverts or deletes them silently. Do not upload the repo's `branding_config.php` or `setup.php`; do not let the client delete `uploads/` or the log folder. That file also lists the five checks to run afterwards. | The step that needed these facts did not carry them — every one was already in this repo, in a file the person at the FTP client was not reading. |
| **v2 step 3** · §4bb | **Rehearse against a copy of live data first — this one re-keys a table.** `php tools/rehearse_phase1.php --host=… --db=COPY --user=… --pass=… --confirm-copy`, and read the *Brands* heading it prints. Then, at deploy: expect one new Brand named after `SITE_NAME`, every existing display already wearing it, and every sign rendering exactly as before. Nothing to click. Two privileges matter that the row above does not stress: convergence now issues `DROP PRIMARY KEY, ADD PRIMARY KEY` on `block_styles`, which needs **ALTER** and **INDEX** — a user without them leaves the table on the old key, and Settings → Database Structure says so in as many words. | ADR-0011: one set of Brand Standards for the whole property becomes one per venue. `block_styles` is re-keyed on `(brand_id, block_type)` and `displays.brand_id` is `NOT NULL`, both converged in place on a live database. The upgrade moves nothing on any sign — the existing standards *become* the first Brand's — but it is the only statement in the plan that replaces structure rather than adding to it, and a second `DROP PRIMARY KEY` rebuilds the table rather than failing harmlessly, which is why its gate reads the key's columns. Admin Panel → Display Branding is now a list of brands; the Displays tab shows and sets which one each sign wears. |
| **#33** · §4ao | **Nothing to do**, unless an account is meant to be adding to the library without holding a sign — check the grant matrix if so. An account with no display assigned now sees the Asset Library with an explanation where the add form was, and its uploads from the Builder are refused. Since the browser pass (§4av) the Builder no longer *offers* that account the link either — the page still answers with its explanation if they reach it by hand, which is what keeps the refusal the check. Admins are unaffected, including on an installation with no Displays yet. | The library is shared by every sign and `uploads/` sits behind it, so neither is scoped to a Display and neither went through the check every other write does. A grant is what makes the library somebody's — a Display merely being switched off does not take it away. |
| **#44** · §4ap | **Check it once, and read the card under it.** The store's time zone is a setting — Admin Panel → **Settings** → Store Time Zone — and every time on every page is drawn in it. The default is `America/Los_Angeles`, so a deploy that never touches it is already right for this store. Then read the three time-zone rows on **Settings → This Server**: the one to look at is the **database's session zone**, which nothing had ever shown. Anything other than a zero offset means the host refused the app's request for UTC. | `db_connect.php` now asks every connection for `+00:00`, suppressed rather than fatal, because a protection that cannot apply is reported and not applied — and that card is the only place it is reported. What a refusal costs is bounded: a creation date reading a few hours out. Separately, `last_published_at` is a DATETIME already written in the old frame, so one sentence per Display reads wrong until its next publish. |
| **#46** · §4z | **Nothing to do**, twice over. `setup.php` deletes itself the moment the first admin exists, so the old "remember to delete it" step is no longer a step — it only needs *not re-uploading*. And `.htaccess` now denies `.md`, so a docs file that reaches the server is unreadable rather than serving `HANDOFF.md` and the paths in it. | Both are backstops for the upload that forgets, not instructions. A host that forbids the self-delete says so on the page instead of alerting. |
| **#50** · §4aq | **Nothing to do, and nothing to upload.** Every file it added or changed is under `tools/`, which goes to the server only with its own `.htaccess` and is never reached from a browser. No page, no query and no schema statement changed, so nothing on any sign moves. | It is a measuring instrument, not a feature: `tools/mutate.php` breaks a `lib/` file one way at a time and reports whether any check notices. The one thing worth carrying to the server visit is unrelated to this row — a *check* that could not fail was found in #44's own section, and the lesson is that a page which prints the right sentence in this process may be printing it for the wrong reason. |

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
  their `.htaccess`, and *not* including what
  [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md) lists), sign in once to converge the
  schema, check the sign at its new URL, then re-point the TV and the SmartSign2Go
  widget. Steps 15–21 need a second account, two browsers, and one unavoidable
  15-minute wait.
- **Nothing here has run against MySQL or in a browser.** Verification so far is
  `php -l`, 1778 self-test checks against SQLite, 546 node checks over `builder.php`'s and `viewer.php`'s
  own JavaScript, and the invariant greps in BUILD-REFERENCE §5. `php tools/rehearse_phase1.php --host=… --user=… --pass=… --db=<copy> --confirm-copy`
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

**What is left:** [`docs/work-lanes.md`](docs/work-lanes.md), and it is one thing.
**Every numbered item in `reviewed-decisions.md` is now Done — 50 of 50** — and what
remains was never on that list: **four commits of `builder.php` have never been rendered
by a browser**, and `lbm-test/` exists so they can be. `interact.js` is still un-run by
any suite, and #44 added two live checks to make on the same visit (§5's deploy-notes
table has them). A closed audit list is not a walked application, and three branches have
now landed in front of this one.

Read `work-lanes.md`'s items 1–4 before starting a branch beside another one. It allocates
the section letter and invariant number rather than leaving them to be discovered, because
four branches once asked `check_doc_numbering.php` the same question at the same time and
all got the same answer. Two things it corrected the hard way:

- **Invariant numbers cannot be reserved the way letters can.** #33 and #44 were cut from
  the same base and both wrote invariant 28 — correctly, because the checker requires the
  list to run unbroken from 1, so neither could have written 29. #44 kept 28 and #33
  renumbered to 29. The rule is that every branch writes the next free number *in its own
  tree* and the reservation only settles who renumbers at the merge. **31 is next, and
  `4ar` is the next free letter** — written without a `§` on purpose, since a citation of
  a write-up nobody has written is what `check_doc_numbering.php` fails on, and it does.
- **The count line does not conflict when it should.** Both branches wrote the same wrong
  total against a base that could only see its own item close, and git merged it clean.
  Recount from the table with the one-liner in item 4 on every merge.

And #50 left a tool worth knowing about before you write a check: **`php tools/mutate.php
lib/whatever.php`** breaks that file one way at a time and tells you whether any check
notices. Invariant 30 is that a check ships having been *seen* to fail, and the reason it
is a rule is that this suite has shipped the other kind more than once.

**What the host actually is, observed 2026-08-11** on Settings → This Server in the
`lbm-test/` folder — the first time anything in this project has read these rather than
assumed them:

| Row | Value |
|-----|-------|
| PHP version | **8.2.33** |
| MySQL version | 5.7.23-23 |
| Server time zone | **`America/Chicago`** |
| Session cookie | HttpOnly yes, Secure yes, SameSite Lax |
| Largest upload | 50 MB |

Two of those settle standing questions. The PHP version **agrees with the owner's stated
8.2** (#51, §4k) — that item's deploy-day confirmation step. And `America/Chicago` is not a
fallback: PHP's fallback for an unset `date.timezone` is UTC, so the host sets it, and
nothing in this repo does (the tracked `.htaccess` sets session flags and no `date.` value).
§4ap had asserted UTC and is corrected. Before #44 that made every time a person read two
hours ahead of store time, not seven or eight.

**And one thing that reading found the hard way:** `Database` on that same card read
`silverad_lummi_market_drive_thru` — the **live** database, from the `lbm-test/` folder.
`This install` correctly read `lbm-test`, so the folder-name logic worked and the file it
looks for was missing: `/home/ACCOUNT/private/db_credentials_lbm-test.php` has to exist, or
the rehearsal copy is the live sign with a different URL. This is the check
`docs/DEPLOY-SKIP.md` exists for, firing for real on the first attempt.

**The PHP floor, observed a second way (2026-08-11).** cPanel → MultiPHP Manager, which
is the configuration rather than the runtime, so it answers a question the card above
cannot:

| | |
|-|-|
| System PHP version | PHP 8.3 (`ea-php83`) |
| `srcresort.com` | **PHP 8.2 (`ea-php82`) — set explicitly, not `inherit`** |
| `golfloomis.com`, `golfloomistrail.com` | PHP 8.2 (`ea-php82`) |

`srcresort.com` is the domain this app is served from, so that middle row *is* the floor.
Two things follow that the runtime number alone did not give:

- **The floor is no longer resting on one person.** #51 was closed twice on evidence that
  could not exist and a third time on the owner's word. It now has two independent
  observations agreeing with that word — a runtime version and a host configuration — and
  the docs that described it as unobserved are corrected (§4k, §4aa, CLAUDE.md).
- **An explicit pin does not drift.** The system default is already *above* the floor, and
  because this domain is set rather than inheriting, a host-wide PHP upgrade does not move
  it. Clearing the pin back to `inherit` would move it to 8.3 — upward, which an 8.2 floor
  survives. The only route *below* the floor is somebody deliberately selecting an older
  version for this domain, which is exactly the case `ServerReport::phpVersionNote()` was
  built to announce.

**And the gap that reading it exposed, which is a real one.** The container these sessions
run in has **PHP 8.4.19**, so `php -l` — the first line of §5's pre-push gate — has never
once checked this code against 8.2. It cannot: a construct added in 8.3 or 8.4 lints
clean here and is a **parse error on the live host**, which is a blank sign in the shop
rather than a message anybody reads. Verified today that no file uses one (`json_validate`,
typed class constants, `#[\Override]`, property hooks, asymmetric visibility — none
present, and the two greps that hit were an HTML `readonly` attribute and a JavaScript
`.match()`). So there is no defect today; what there is, is a gate that cannot see the
one failure the floor exists to prevent. Nothing mechanical enforces it yet.

## 8. Conventions / gotchas for the next session

- **Data access lives in `lib/`.** A new query means a new method on the owning
  module, not a `$pdo` handed to a page script. One module per table (§3).
- **PHP 8.2 is the floor** — stated by the store owner, 2026-08-10 (#51, §4k). Nothing
  in this repo has *observed* it. An earlier branch claimed it from Settings → This
  Server; that screen ships with the multi-display build, which #46's probe of the live
  site found undeployed (`lib/` answers 404), so it cannot have answered there, and
  Cloudflare fronts the site so no response header reveals it either. So the floor rests
  on a person, and `ServerReport::phpVersionNote()` is what notices if the host is ever
  moved or downgraded — **confirming it is a step on the deploy checklist**, not a thing
  already done. Modern syntax is now allowed; as of today no file uses a typed property,
  constructor promotion, `readonly`, `match` or an arrow function, which is what keeps
  the floor one line to lower again. Be deliberate about spending it: guessing low only
  forwent syntax, where a declared floor that turns out wrong is a parse error, and a
  parse error in a file a Screen loads is a blank sign in the shop rather than anything
  anybody reads. Two 7.1-era fallbacks stay on purpose: `.htaccess` carries `mod_php7`
  blocks alongside its `mod_php8` ones, and `auth.php` keeps the pre-7.3 session-cookie
  form behind a version check. Both are free, and both are what stops a move to a
  different host from silently dropping HttpOnly and Secure off the sign-in cookie.
- Before pushing: `php -l` every touched file, then `php tools/selftest_layout.php`,
  then all seven builder node suites (`tools/selftest_builder_readonly.js`,
  `tools/selftest_builder_uploads.js`, `tools/selftest_builder_colors.js`,
  `tools/selftest_builder_editing.js`, `tools/selftest_builder_undo.js`,
  `tools/selftest_builder_brands.js` and `tools/selftest_builder_theme.js`) if
  `builder.php` was touched, and `tools/selftest_viewer.js` if `viewer.php` was. A self-test
  failure is a release blocker, not a broken test.
- **Nothing that has been published can be taken back.** Publishing overwrites.
  Prefer refusing a write to merging one — that is why publish has both a staleness
  check and a lock check, and why neither tries to merge. The Builder's Undo
  (ADR-0010) reaches back over the canvas *before* a publish, in one browser tab
  only; its depth is an admin setting (Settings → Builder Undo, default 5, 0 turns
  it off) stored in `branding_config.php`, not in the database.
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
