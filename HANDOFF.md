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

- **Working branch:** `claude/website-build-migration-nfurek` (all work goes here)
- Push with `git push -u origin claude/website-build-migration-nfurek`
- No PR has been opened. Do **not** open one unless the user asks.
- Commit history so far:
  - `ab78cc8` migrate batch 1 (auth/setup/viewer)
  - `8c0cb66` migrate core files (auth, api, builder, admin, assets)
  - `47b7e28` bug fixes from adversarial review
  - `b5d82a9` CSRF + asset-dropdown filter + logo hardening

## 3. File map (all at repo root — flat, relative includes)

| File | Role |
|------|------|
| `config.php` | Site constants (`SITE_NAME`, `MAIL_FROM`); loads `branding_config.php` |
| `db_connect.php` | PDO `$pdo`; loads creds from `../../private/db_credentials.php` |
| `auth.php` | `session_start`; `requireLogin/requireAdmin/isAdmin/currentUser`; `csrfToken()/verifyCsrf()` |
| `branding_config.php` | Generated brand theme (`BRAND_*` constants) |
| `login.php` / `logout.php` | Auth; session-based login lockout (5 tries / 5 min) |
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

`users`, `password_resets`, `assets`, `canvas_elements`, `canvas_settings`,
`block_styles`. There is **no `schema.sql` in the repo yet** (see open items).
`api.php` auto-adds newer columns (`text_align`, `z_index`, `hidden`) and seeds
`item_title_2`/`price_2` styles — but **only on authenticated (non-`get_layout`)
requests** now (was previously running on every public poll).

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

## 8. Conventions / gotchas for the next session

- Files use flat relative includes — keep everything at repo root.
- `php -l` every PHP file you touch before committing (no PHP runtime to execute).
- Commit messages: descriptive; do not include model identifiers.
- Two files are large (`builder.php` ~112KB, `admin_panel.php` ~43KB) — consider
  subagents for deep review. `builder.php` is mostly inline JS; `php -l` won't
  catch JS errors, so read edited JS carefully.
- The user uploads files in batches of ≤5 (platform limit). A git stop-hook
  requires untracked files to be committed, so commit each batch.
