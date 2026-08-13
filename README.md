# Lummi Bay Market — Digital Signage

A PHP + MySQL **digital-signage builder** for the Lummi Bay Market's pricing
signs. Someone designs a layout in a drag-and-drop builder, clicks **Publish**,
and a fullscreen **viewer** running on a TV or kiosk picks it up within 30
seconds.

One installation drives any number of signs. Each one — a **Display** — carries
its own canvas size, its own layout, and its own list of people allowed to edit
it. Sizes are arbitrary and include portrait; the drive-thru sign's 1920×1080 is
just the first Display's dimensions, not a property of the system.

- **Live site:** https://srcresort.com/lbm/ (served from an `/lbm/` subfolder)
- **Stack:** vanilla PHP (PDO, prepared statements, `ERRMODE_EXCEPTION`), MySQL,
  inline CSS/JS. No framework, no build step. The builder uses
  [interact.js](https://interactjs.io/) for drag/resize.

## How it works

1. **Displays** (`admin_panel.php?tab=displays`) — an admin creates a Display,
   choosing its dimensions and its **screen name tag**. The tag is the sign's
   address; the dimensions are fixed at creation (ADR-0004).
2. **Builder** (`builder.php?display=<tag>`) — the canvas editor for one Display,
   at that Display's size. Sections are top-level containers;
   text/image/video/carousel/marquee/table blocks live inside them. Opening a
   Display takes its **edit lock**, so a second person gets it read-only rather
   than overwriting the first (ADR-0007). **Undo** (button or Ctrl+Z) reaches back
   over the canvas a few steps, in that tab, before anything is published; how many
   is an admin setting, default 5 (ADR-0010).
3. **Publish** (`api.php?action=publish`) — replaces that one Display's layout in
   a single transaction, and refuses the write if the Display changed since the
   tab was opened or if the lock has moved on. Undo stops here — nothing published
   can be taken back and nothing is versioned — so refusing beats merging (ADR-0006).
4. **Viewer** (`viewer.php?display=<tag>`) — a public, login-free fullscreen
   renderer that polls `api.php?action=get_layout&display=<tag>` every 30s and
   scales the canvas to whatever screen it is on. **Every viewer URL names its
   Display**; a bare or unknown `?display=` shows a plain notice rather than some
   other sign's content (ADR-0003).

## Who can edit what

Two independent axes (ADR-0005):

| Axis | Answers | Where it lives |
|------|---------|----------------|
| **Grant** | *Which* Displays may this account open? | `display_permissions`, one row per grant. Admins hold every Display by role and are never granted anything. |
| **Role** | *How much* may it do once inside? | `users.role` — unchanged by multi-display. |

| Role    | Can do |
|---------|--------|
| `admin` | Everything: displays, user accounts, grants, branding, section layout, publish, assets. |
| `basic` | Add/edit content *inside existing sections* only — not the section layout itself — and only on Displays granted to it. |

Enforcement is server-side on every read and write, not just in the builder's
picker: a forged request naming an ungranted Display is refused.

## Setup

### 1. Database credentials (outside the webroot)

`db_connect.php` loads credentials from `../../private/db_credentials.php`
(intentionally **outside** the webroot, never in the repo):

```php
<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'silverad_lummi_market_drive_thru');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

### 2. Create the schema

```bash
mysql -u <user> -p <database> < schema.sql
```

`schema.sql` is what a fresh rebuild should produce. It is safe to re-run — every
statement is `IF NOT EXISTS` / `INSERT IGNORE`.

It also includes everything the app converges at runtime, because there is no
migration tool here and the live database is edited in place: `ensureSignageSchema()`
in `lib/schema.php` runs on every authenticated request (never on the public poll)
and `ensureLockoutColumns()` in `auth.php` runs on the first login. On an existing
database, signing in as an admin once is what applies the multi-display structure
and hands every pre-existing element to the drive-thru Display. It checks the
database catalogue before altering anything, so once the structure is in place those
requests issue no `ALTER TABLE` at all.

The public poll gets one bounded exception: if a table is genuinely missing, the
Screen's own failed read converges the schema once, so a sign comes up after a
deploy without waiting for somebody to sign in. That repair never runs inside a
transaction, never runs twice at once, and will not retry for five minutes.

### 3. First-run admin

Visit `setup.php` once to create the first admin account. It **self-disables** as soon
as any user exists, and then **deletes itself** — at the end of a successful setup, or
on the first request that finds it already disabled. Nothing to remember afterwards.

If the page says it *could not* delete itself, the server did not allow the write:
delete `setup.php` by hand. It never reports success without checking the file is
really gone, because while it is still being served a restore to an empty database
turns it back into a public admin-creation form.

### 4. Branding (optional)

Store branding is edited under **Admin → Branding**
(`admin_panel.php?tab=branding`), which regenerates `branding_config.php`. The
**directory** must be writable by the web user, not just that file: the save
writes a temporary copy beside it and renames over it.

### 5. Updating an install that already exists

Read [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md) first. Both of the steps above
leave the server holding files the repo cannot supply: `setup.php` is *gone* from the
server and `branding_config.php` was rewritten there, so uploading the tree over the
top restores the first-admin form and reverts the branding — including the address
reset codes and alerts are sent from. `uploads/` and the log folder exist only on the
server. That file lists what to skip and what to check afterwards.

## File map

Page scripts live at the repo root (flat, relative includes — keep them there)
and are thin adapters. **Data access lives in `lib/`**, one module per table:

| Module | Owns |
|--------|------|
| `lib/schema.php` | `ensureSignageSchema()` — every idempotent `CREATE`/`ALTER`, the drive-thru seed, the `display_id` backfill. Asks `information_schema` first, so a database that is already up to date is altered not at all |
| `lib/displays.php` | `Display`, `Background`, `LockState`, `DisplayStore` — the only place that writes SQL against `displays` |
| `lib/layout_store.php` | `LayoutStore` — the only place that touches `canvas_elements`: the publish transaction, the staleness and lock checks, scoped hide/delete |
| `lib/grants.php` | `GrantStore`, `Actor` — the only place that writes SQL against `display_permissions` |
| `lib/display_admin.php` | `DisplayAdmin` — create/edit/delete a Display across all three tables; writes no SQL itself |
| `lib/display_request.php` | Which Display a request means, and whether this account may have it |
| `lib/plain_text.php` | `toPlainText()` — signage content is plain text (ADR-0002) |
| `lib/branding.php` | `BrandingConfig` — the eight branding settings and their defaults, in one place. The only writer of `branding_config.php`, and it never writes it in place: a temporary copy is written, checked and `rename`d over, because every page in the app requires that file |
| `lib/login_attempt.php` | `LoginAttempt` — every sentence a refused sign-in may say, the order the questions are asked in, and the lockout arithmetic behind it (ADR-0008) |
| `lib/request_scheme.php` | `RequestScheme` — is this request HTTPS, and may the session cookie claim `Secure` (ADR-0009). Also the scheme the viewer address is built from |
| `tools/selftest_layout.php` | `php tools/selftest_layout.php` — the real modules against an in-memory database. Run before pushing |
| `tools/rehearse_phase1.php` | Rehearses schema convergence and scoping against a **copy** of live data, never the live database |

`lib/` and `tools/` each carry an `.htaccess` that makes them unreachable from a
browser. Keep those when deploying.

| File | Role |
|------|------|
| `config.php` | Brings the eight branding constants into being through `lib/branding.php`; the one place that loads `branding_config.php` |
| `db_connect.php` | PDO `$pdo`; loads private credentials |
| `auth.php` | Sessions; `requireLogin/requireAdmin/isAdmin`; CSRF + login-lockout helpers |
| `branding_config.php` | Generated brand theme (`BRAND_*`, `SITE_NAME`, mail-from). Written only by `lib/branding.php` |
| `login.php` / `logout.php` | Auth; account-keyed login lockout |
| `reset_password.php` | 2-step emailed 6-digit passcode reset (30-min expiry) |
| `setup.php` | First-run admin creation; self-disables and then deletes itself once an admin exists |
| `builder.php` | Drag-and-drop canvas editor for one Display — the heart of the app |
| `admin_panel.php` | Displays, grants, user management, brand standards, work area |
| `crud.php` | Asset library (text/image/video assets), shared by every Display |
| `api.php` | JSON API (get_layout, publish, upload, styles, element ops, edit lock) |
| `viewer.php` | Public fullscreen renderer for one named Display (no login) |
| `help.php` | In-app user guide |

## Security notes

- **CSRF** protection on all state-changing POSTs (`csrfToken()`/`verifyCsrf()`).
- **Login lockout** is account-keyed and database-backed: 5 failed attempts
  inside a 15-minute window trigger a 15-minute lockout, cleared by a
  successful login or a completed password reset. See
  [`docs/adr/0001-account-keyed-login-lockout.md`](docs/adr/0001-account-keyed-login-lockout.md).
- **Uploads** (`uploads/`) are validated by extension + MIME; SVG is rejected
  (stored-XSS). This directory is server-only and git-ignored.

## Domain language and decisions

[`CONTEXT.md`](CONTEXT.md) is the glossary — **Display**, **Viewer**, **Screen**,
**screen name tag**, **canvas**, **grant**, **edit lock**. Use those words in code
and UI copy. [`docs/adr/`](docs/adr/) records the decisions with the alternatives
that were rejected; [`docs/BUILD-REFERENCE.md`](docs/BUILD-REFERENCE.md) is the
module map and the invariants any change has to preserve.

## Development

- There's no build step and no CI beyond a lint action. Edit PHP directly.
- Before committing:

  ```bash
  php -l <every touched .php>
  php tools/selftest_layout.php     # expect "N checks, 0 failed"
  ```

- **PHP 8.2 is the floor** — stated by the store owner 2026-08-10, and **observed twice on
  2026-08-11** (#51, §4k): the runtime reports 8.2.33, and cPanel's MultiPHP Manager shows
  `srcresort.com` pinned explicitly to `ea-php82` against a system default of 8.3. The pin
  being explicit is what keeps the floor from drifting when the host moves its default.
  This paragraph said "nothing here has observed it" until 2026-08-13, on the true but
  incomplete reasoning that the app's own card ships with a build that was undeployed and
  Cloudflare hides the version from every header — cPanel was the third place to look, and
  the build is live now besides. Modern syntax is
  allowed, and today no file uses any — which is what keeps the floor one line to lower
  again. Be deliberate about spending it: a declared floor that turns out wrong is a
  parse error, and a parse error in a file a Screen loads is a blank sign in the shop.
  The 7.1-era fallbacks in `auth.php` and `.htaccess` stay — they cover a host that
  moves, and what they prevent is silent.
- `builder.php` is large and mostly inline JS — `php -l` cannot see those errors,
  so read edited JavaScript carefully.
- **Nothing that has been published can be taken back.** Publishing overwrites, and
  a deleted Display or asset row is gone. Prefer refusing a write to merging one. The
  Builder's Undo (ADR-0010) is the one exception and a narrow one: a few steps over
  the canvas, in one browser tab, before a publish.
