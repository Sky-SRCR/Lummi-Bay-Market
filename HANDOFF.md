# Lummi Bay Market — Digital Signage System · Session Handoff

> Handoff for a fresh Claude Code session. Read this first.

## 1. What this project is

A PHP + MySQL **digital-signage / display-builder** app for the Lummi Bay Market
drive-thru (tobacco/nicotine pricing display). Admins/users design a
1920×1080 layout in a drag-and-drop **builder**, click **Publish**, and a
fullscreen **viewer** running on a TV/kiosk auto-refreshes every 30s to show it.

- **Live site:** https://srcresort.com/lbm/  (served from an `/lbm/` subfolder)
- **Live database:** `silverad_lummi_market_drive_thru` (MySQL, localhost:3306)
- **Stack:** vanilla PHP (PDO, `ERRMODE_EXCEPTION`, real prepared statements),
  no framework, inline CSS/JS. Uses `interact.js` in the builder for drag/resize.

The migrated code is **faithful to production** — same behavior, verified against
the live site. Only runtime assets (`uploads/` images, the private credentials
file) live on the server and are intentionally **not** in the repo.

## 2. Git / branch

- **Working branch:** `claude/load-handoff-3az5j6` (all work goes here)
- Push with `git push -u origin claude/load-handoff-3az5j6`
- **PR #2** (`claude/load-handoff-3az5j6` → `main`):
  https://github.com/Sky-SRCR/Lummi-Bay-Market/pull/2 — covers everything in
  sections 6–7 below. Once merged, start future sessions from `main`.
- Recent commit history (newest first):
  - `4087204` fully lock viewer.php against scrolling in both axes
  - `81aa05f` track full live .htaccess + viewer.php framing exception
  - `6be4026` allow public viewer.php to be embedded in external signage widgets
  - `464b8f5` builder: highlight a newly added text block until first use
  - `df2ad5c` Asset Library UI: type hint + clearer save button label
  - `c8d6773` block SVG/non-image references via the Asset Library URL field
  - `04c8633` security hardening: stored XSS, session cookies, reset enumeration
  - `a8cfc9c` correct schema.sql against live database structure
  - `7d7b1cf` add repo housekeeping: .gitignore, schema.sql, README
  - `9ecd8a0` fix L8: account-keyed, DB-backed login lockout
  - (earlier: migration + adversarial-review fixes — `ab78cc8`, `8c0cb66`,
    `47b7e28`, `b5d82a9`)

## 3. File map (page scripts at repo root; data access in `lib/`)

Since Phase 1 of the multi-display build, page scripts are thin adapters over
four modules in `lib/`. Read [`docs/BUILD-REFERENCE.md`](docs/BUILD-REFERENCE.md)
before changing any of them — it is the standing contract for the whole build.

| File | Role |
|------|------|
| `lib/schema.php` | `ensureSignageSchema()` — every idempotent `ALTER`/`CREATE`, the `displays` table, `display_id` + backfill, the drive-thru seed |
| `lib/displays.php` | `Display` value object + `DisplayStore` — all `displays` SQL, screen name tag rules |
| `lib/layout_store.php` | `LayoutStore` — the **only** place that touches `canvas_elements`: publish transaction, staleness check, scoped hide/delete |
| `lib/display_request.php` | Which Display a request means; ADR-0003 notice wording; where Phase 4 grants attach |
| `lib/plain_text.php` | `toPlainText()` — plain-text signage content (ADR-0002), moved out of `auth.php` |
| `tools/selftest_layout.php` | `php tools/selftest_layout.php` — real modules, in-memory DB, 85 checks. Run before pushing |
| `tools/rehearse_phase1.php` | Rehearses the Phase 1 migration + scoping against a **copy** of live data |
| `config.php` | Site constants (`SITE_NAME`, `MAIL_FROM`); loads `branding_config.php` |
| `db_connect.php` | PDO `$pdo`; loads creds from `../../private/db_credentials.php` |
| `auth.php` | `session_start`; `requireLogin/requireAdmin/isAdmin/currentUser`; `csrfToken()/verifyCsrf()` |
| `branding_config.php` | Generated brand theme (`BRAND_*` constants) |
| `login.php` / `logout.php` | Auth; account-keyed DB login lockout (5 tries / 15-min window) |
| `.htaccess` | Server config: index/sensitive-file blocks, security headers, PHP hardening, HTTPS redirect. Frames `viewer.php` for external widgets (see §7). |
| `reset_password.php` | 2-step emailed 6-digit passcode reset (30-min expiry) |
| `setup.php` | First-run admin creation; self-disables once a user exists. **Delete on server after setup.** |
| `setup_branding.php` | Redirect shim → `admin_panel.php?tab=branding` |
| `builder.php` | ~2400-line drag-and-drop canvas editor (mostly inline JS). The heart of the app. |
| `admin_panel.php` | User mgmt + Brand Standards + Store Branding + Work Area (element hide/delete) |
| `crud.php` | Asset Library (text/image assets) |
| `api.php` | JSON API: get_layout (public), get_assets, upload_file, upload_video, publish, save_brand_styles, get_canvas_elements, set_element_hidden, delete_canvas_element |
| `viewer.php` | Public fullscreen renderer; polls `api.php?action=get_layout` every 30s |
| `help.php` | In-app user guide |

## 4. Database (tables already exist on the live server)

`users`, `password_resets`, `assets`, `canvas_elements`, `displays`,
`block_styles`, and the retired `canvas_settings`. `schema.sql` in the repo is
the structure the code expects; the live server lags it (it still lacks the three
lockout columns on `users`, and — until Phase 1 is deployed — `displays` and
`canvas_elements.display_id`).

Schema convergence is now one call, `ensureSignageSchema()` in `lib/schema.php`,
run on **authenticated (non-`get_layout`) requests only** so the public 30-second
poll never runs DDL. It adds the newer element columns (`text_align`, `z_index`,
`hidden`), seeds the `item_title_2`/`price_2` styles, creates `displays`, and
backfills `canvas_elements.display_id` to the drive-thru Display. Every statement
is idempotent, and the backfill re-runs on every authenticated request — so if a
partly applied migration ever left elements unscoped (which would show as a blank
sign), an admin page load repairs it.

`canvas_settings` is retired: nothing reads it except the one-time seed that
carries its background onto the drive-thru Display.

Roles: `admin` (full) and `basic` (adds content inside existing sections only).

## 5. Deployment facts a new session should know

- `db_connect.php` expects `../../private/db_credentials.php` (outside webroot)
  defining `DB_HOST/DB_NAME/DB_USER/DB_PASS`. Not in repo by design.
- The live `branding_config.php` still uses the default `SITE_NAME`
  ("Store Display System"), not "Lummi Bay Market".
- `viewer.php` requires **no login** (so any screen on the network can display it).
- To see the live rendered display without logging in:
  `GET https://srcresort.com/lbm/api.php?action=get_layout` returns the current
  layout JSON. WebFetch can read live pages but can't run JS or log in.

## 6. Work completed this session

1. **Migration** of all 15 PHP files into the repo.
2. **Adversarial review** (two subagents on the big files + manual review of the
   rest). 18 findings; 1 was a false positive (raw `SITE_NAME` — `config.php`
   always defines it as a fallback, so not a bug).
3. **Fixes applied (16):**
   - *High:* admin self-lockout guard (can't deactivate/demote own account);
     `hidden` flag now persists through publish (was silently un-hiding elements).
   - *Medium:* CSRF protection wired into `api.php`/`crud.php`/`reset_password.php`
     (was admin_panel-only); asset dropdown filtered by block type; image-fit no
     longer blanks an asset-linked image; basic users now see the saved bg color;
     video saved as relative path; duplicate-email edit no longer 500s; SVG logo
     removed (stored-XSS); 2 MB logo size enforced server-side.
   - *Low:* migrations no longer run on the public poll; HIDDEN badge preserved on
     carousel/table/marquee edit; section move-cursor only for admins; section X/Y
     clamped to canvas; `created_at` guarded; help doc reset-expiry 15→30 min.

## 7. Open items (nothing in progress — safe to stop)

- ~~**L8 — login lockout is bypassable.**~~ **Resolved.** Rebuilt as an
  account-keyed, DB-backed lockout (5 failures / 15-min window) on three new
  `users` columns; clears on successful login or password reset. See
  `docs/adr/0001-account-keyed-login-lockout.md` and `CONTEXT.md`.
- ~~**Repo housekeeping (never started):**~~ **Done.**
  - `.gitignore` (uploads/, local credentials, editor/OS cruft) — added
  - `schema.sql` (all 6 tables, reconstructed from app queries) — added
  - `README.md` (setup, roles, builder/viewer overview, security) — added
- CSRF end-to-end sanity check worth doing live: log in → edit an asset → publish;
  all should succeed, while a stale/forged POST gets "Security token mismatch."
- ~~**Security hardening pass**~~ **Done** (audited whole codebase; verified
  against the live dump in a real browser):
  - *Stored XSS (high):* text blocks are now plain text — rendered with
    `textContent` in viewer + builder, stripped server-side on save
    (`toPlainText()` in `auth.php`). Removed the rich-text toolbar. See
    `docs/adr/0002-plain-text-signage-content.md`.
  - *Session cookies:* `HttpOnly + Secure + SameSite=Lax` set in `auth.php`.
  - *Password-reset enumeration:* `reset_password.php` now advances every
    request to the code screen with identical messaging.
  - *Low:* `SITE_NAME` escaped in `builder.php` inline JS.
  - Audit verified SAFE: SQLi (all parameterized), per-endpoint authz,
    CSRF coverage, upload handling (no runnable files / path traversal).
- ~~**Asset Library — SVG/non-image references.**~~ **Done.** SVG and other
  non-image types are blocked on both file upload and the URL/reference field,
  on create and edit (`isAllowedImageRef()` in `crud.php`). Plus UI tweaks:
  accepted-types hint under the file input; "Save Library Asset" button label.
- ~~**Builder — new text blocks are hard to see.**~~ **Done.** A newly added
  text block is highlighted (yellow 50% + dashed outline) until its first
  edit/move/text change, then renders as designed. Text blocks only.
- ~~**Public viewer embedding + kiosk lock.**~~ **Done.**
  - `.htaccess` keeps `X-Frame-Options: SAMEORIGIN` on the whole app but drops
    it for `viewer.php` only (`Content-Security-Policy: frame-ancestors *`), so
    the public display embeds in external signage widgets (e.g. SmartSign2Go).
    `viewer.php` is public/read-only, so framing it carries no clickjacking risk.
  - `viewer.php` is locked against scrolling in both axes (no scrollbars,
    overscroll, or touch-drag) for kiosk/embedded use.
  - Point the signage widget at `https://srcresort.com/lbm/viewer.php` (not the
    app root, which redirects to login). The viewer auto-scales its 1920×1080
    design to fill any 16:9 screen; a viewer-only resolution/aspect change is
    NOT possible without also changing the builder's 1920×1080 coordinate grid.

## 8. Conventions / gotchas for the next session

- Files use flat relative includes — keep everything at repo root.
- `php -l` every PHP file you touch before committing (no PHP runtime to execute).
- Commit messages: descriptive; do not include model identifiers.
- Two files are large (`builder.php` ~112KB, `admin_panel.php` ~43KB) — consider
  subagents for deep review. `builder.php` is mostly inline JS; `php -l` won't
  catch JS errors, so read edited JS carefully.
- The user uploads files in batches of ≤5 (platform limit). A git stop-hook
  requires untracked files to be committed, so commit each batch.
