# Lummi Bay Market — Digital Signage

A PHP + MySQL **display-builder** for the Lummi Bay Market drive-thru pricing
sign. Admins design a fixed **1920×1080** layout in a drag-and-drop builder,
click **Publish**, and a fullscreen **viewer** running on a TV/kiosk
auto-refreshes every 30 seconds to show the current layout.

- **Live site:** https://srcresort.com/lbm/ (served from an `/lbm/` subfolder)
- **Stack:** vanilla PHP (PDO, prepared statements, `ERRMODE_EXCEPTION`), MySQL,
  inline CSS/JS. No framework. The builder uses [interact.js](https://interactjs.io/)
  for drag/resize.

## How it works

1. **Builder** (`builder.php`) — the canvas editor. Sections are top-level
   containers; text/image/video/carousel/marquee/table blocks live inside them.
2. **Publish** (`api.php?action=publish`) — writes the layout to the database.
3. **Viewer** (`viewer.php`) — a public, login-free fullscreen renderer that
   polls `api.php?action=get_layout` every 30s. Any screen on the network can
   display it.

## Roles

| Role    | Can do |
|---------|--------|
| `admin` | Everything: user management, branding, section layout, publish, assets. |
| `basic` | Add/edit content *inside existing sections* only — not the section layout itself. |

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

`schema.sql` is reconstructed from the application's queries and includes the
columns the app also adds lazily at runtime (via idempotent `ALTER TABLE`
blocks in `api.php` and `auth.php`). It's safe to re-run — every statement is
`IF NOT EXISTS` / `INSERT IGNORE`.

### 3. First-run admin

Visit `setup.php` once to create the first admin account. It **self-disables**
as soon as any user exists. **Delete `setup.php` from the server after setup.**

### 4. Branding (optional)

Store branding is edited under **Admin → Branding**
(`admin_panel.php?tab=branding`), which regenerates `branding_config.php`.

## File map

All PHP files live at the repo root (flat, relative includes — keep them there).

| File | Role |
|------|------|
| `config.php` | Site constants; loads `branding_config.php` |
| `db_connect.php` | PDO `$pdo`; loads private credentials |
| `auth.php` | Sessions; `requireLogin/requireAdmin/isAdmin`; CSRF + login-lockout helpers |
| `branding_config.php` | Generated brand theme (`BRAND_*` constants) |
| `login.php` / `logout.php` | Auth; account-keyed login lockout |
| `reset_password.php` | 2-step emailed 6-digit passcode reset (30-min expiry) |
| `setup.php` | First-run admin creation (delete after setup) |
| `builder.php` | Drag-and-drop canvas editor — the heart of the app |
| `admin_panel.php` | User management, brand standards, work area |
| `crud.php` | Asset library (text/image/video assets) |
| `api.php` | JSON API (get_layout, publish, upload, styles, element ops) |
| `viewer.php` | Public fullscreen renderer (no login) |
| `help.php` | In-app user guide |

## Security notes

- **CSRF** protection on all state-changing POSTs (`csrfToken()`/`verifyCsrf()`).
- **Login lockout** is account-keyed and database-backed: 5 failed attempts
  inside a 15-minute window trigger a 15-minute lockout, cleared by a
  successful login or a completed password reset. See
  [`docs/adr/0001-account-keyed-login-lockout.md`](docs/adr/0001-account-keyed-login-lockout.md).
- **Uploads** (`uploads/`) are validated by extension + MIME; SVG is rejected
  (stored-XSS). This directory is server-only and git-ignored.

## Domain language

See [`CONTEXT.md`](CONTEXT.md) for the project glossary and
[`docs/adr/`](docs/adr/) for architecture decision records.

## Development

- There's no build step. Edit PHP directly.
- Run `php -l <file>` on every PHP file you touch before committing.
- `builder.php` is large and mostly inline JS — `php -l` won't catch JS errors,
  so read edited JavaScript carefully.
