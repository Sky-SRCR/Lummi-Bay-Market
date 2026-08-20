# Installing the Store Display System

A PHP + MySQL digital-signage builder. Somebody designs a layout in a drag-and-drop
builder, presses **Publish**, and a fullscreen viewer running on a TV picks it up
within 30 seconds. One installation drives any number of signs.

Read this whole page before you upload anything. It is about twenty minutes of work,
and two of the steps are ones you cannot see the mistake in afterwards.

---

## What you need

| | |
|---|---|
| **PHP 8.2 or newer** | With `pdo_mysql`, `zip` is not needed on the server. On cPanel this is **MultiPHP Manager** — set the domain explicitly rather than leaving it on "inherit", so the version does not move when the host changes its default. |
| **MySQL 5.7 or newer** | A database and a user with full rights on it. MariaDB is *untested* — nothing in this project has ever run on it. |
| **HTTPS working on the domain** | The bundled `.htaccess` redirects every plain-HTTP request to HTTPS. Install the certificate first, or the first page you load will fail rather than fall back. |
| **`AllowOverride` on** | Three `.htaccess` files carry the security headers and make two folders unreachable. Almost every shared host allows this; if yours does not, say so before you start, because two of the checks at the end will fail and they are the two that matter. |
| **An FTP/SFTP client, or cPanel's File Manager** | And the ability to write one file *above* the webroot. |

Nothing else. No composer, no node, no build step, no cron job.

---

## What is in this package

```
INSTALL.md          this page
VERSION.txt         which build this is, and what it needs
MANIFEST.txt        every file in app/ with its size and SHA-256
app/                → the CONTENTS of this go into your webroot folder
private/
  db_credentials.php  → this goes OUTSIDE the webroot
```

**Two folders, two destinations.** `app/` and `private/` do not go to the same place,
and putting `private/` inside the webroot is the one mistake in this install that
nothing will tell you about later.

---

## 1. Create the database

In cPanel → **MySQL Databases**: create a database, create a user, and add the user to
the database with **All Privileges**. Write down all four values — host (`localhost` on
almost every shared host), database name, user name, password.

Nothing is imported yet. Step 4 does that.

## 2. Put the credentials file above the webroot

Open `private/db_credentials.php` from this package, fill in the four values, and
upload it to a `private/` folder **one level above** your webroot:

```
/home/YOUR_ACCOUNT/private/db_credentials.php     ← the credentials
/home/YOUR_ACCOUNT/public_html/signs/             ← the app, from step 3
```

The app walks up two folders from its own directory to find it, so an install at
`public_html/signs/` looks in `/home/YOUR_ACCOUNT/private/`. That is the whole reason
the file is there: no browser request can reach a folder above the webroot, so a
misconfigured server cannot serve your database password as plain text.

**Do not** put the credentials inside `app/` instead. It works, and it is the one
change that reverts silently the next time somebody uploads a file over it.

## 3. Upload the app

Create the folder the app will live in — `public_html/signs/`, or whatever name you
want in the URL — and upload **the contents of `app/`** into it. Not the `app` folder
itself: you want `signs/login.php`, not `signs/app/login.php`.

Three things to get right, and all three are things an FTP client can get wrong on
your behalf:

- **Upload the dotfiles.** There are three `.htaccess` files — one at the top, one in
  `lib/`, one in `tools/`. Many clients hide and skip files beginning with a dot by
  default. The two in folders are the *only* thing keeping those folders off the web.
- **Upload file-by-file or folder-by-folder with overwrite.** Never "mirror", "sync"
  or "make remote match local". That mode deletes anything on the server the package
  does not have, which later means every photograph and video anybody has uploaded.
- **The app folder must be writable by the web user.** The app writes
  `branding_config.php` when you save the Branding page, and creates `uploads/` the
  first time somebody adds an image. On cPanel, PHP runs as your own account and this
  is already true; `755` on the folder is enough.

## 4. Import the schema

cPanel → **phpMyAdmin** → pick your database → **Import** → choose `schema.sql` from
the `app/` folder → **Go**.

Or from a shell, if you have one:

```bash
mysql -u YOUR_USER -p YOUR_DATABASE < schema.sql
```

It is safe to run twice — every statement is `IF NOT EXISTS` or `INSERT IGNORE`. It
creates the tables and seeds one Brand and one set of block styles. No accounts and no
content.

## 5. Create the first administrator

Open **`https://your-domain/signs/setup.php`** in a browser.

Fill in a username, an email address, a password of at least 8 characters, and the
**venue or brand name** — the name of the shop or restaurant this sign belongs to.
Every Display wears a Brand, which is its typography, palette and logo; this creates
the first one. You can add more later, one per venue.

Press **Create Admin Account**.

**This page deletes itself.** It only works while the `users` table is empty, and it
removes itself at the end of a successful setup. If it tells you it *could not* delete
itself, the host did not allow the write — delete `setup.php` by hand, now, before you
do anything else. While it is still being served, a restore to an empty database turns
it back into a public "make yourself an administrator" form.

## 6. Check it is gone

Load `https://your-domain/signs/setup.php` again. You want a **404**.

Not the form, and not the words "Setup is complete" — a 404. Asking is also what makes
it delete itself if it is still there, so this check repairs the thing it looks for.

## 7. Sign in and read the server card

Sign in at `https://your-domain/signs/login.php`, then go to **Admin Panel → Settings →
This Server**. This is the page that answers, in one place, what the install actually
found:

- **This install** and **Database** — the folder name, and the database it reached.
  If you have only one copy of the app installed, any answer here is fine. If you have
  two, and this one names the *other* one's database, stop: the name-specific
  credentials file is missing, and this folder is talking to a live sign.
- **PHP** — 8.2 or above, with no warning beside it.
- **MySQL** — 5.7 or above. A MariaDB note here means you are on untested ground.
- **Upload limit** — what the biggest image or video may be. Raise
  `upload_max_filesize` and `post_max_size` in the top-level `.htaccess` if it is
  smaller than the videos you plan to use.
- **Errors and Alerts** — it must name a writable path. *"Nowhere to write"* means
  nothing that goes wrong will be recorded and no alert can be sent.

Signing in as an admin is also what brings the database fully up to date. There is no
migration tool: the app checks the catalogue on each signed-in page load and applies
only what is missing, so once it is in place those requests alter nothing.

## 8. Set the site name and the address mail comes from

**Admin Panel → Site Branding.** Two of these matter beyond appearance:

- **Site name** — until you set it, every page and every email says *Store Display
  System*.
- **Mail from** — an address **on a domain this server owns**. It is the `From:` on
  password-reset codes and on every alert the app sends. Left at the shipped
  `noreply@yourdomain.com`, those messages are dropped as spam by the receiving end,
  and the first symptom is somebody unable to reset a password weeks later — with the
  alert that would have explained it undeliverable for the same reason.

The four navigation colours and the logo are on the same page. Saving rewrites
`branding_config.php` on the server; from then on that file is the authority and the
package's copy is a stale default.

## 9. Name your first sign

The install starts with one Display, called **Drive-Thru**, at 1920×1080. That name is
the previous shop's, not a setting — rename it in **Admin Panel → Displays**.

Two fields there decide things you cannot change later:

- **Screen name tag** — the sign's address. It is what goes in the viewer URL, so
  renaming it is renaming the URL every TV is pointed at.
- **Canvas size** — fixed when the Display is created. Portrait is fine; any size is
  fine. It cannot be changed afterwards, because every block on the canvas is
  positioned against it.

## 10. Point the screen at it

On the TV, kiosk or signage player, open:

```
https://your-domain/signs/viewer.php?display=YOUR-TAG
```

No login. It scales the canvas to whatever screen it is on and re-reads the layout
every 30 seconds, so a publish reaches the sign within half a minute without anybody
touching the TV.

**Every viewer URL names its Display.** A URL with no `?display=` shows a plain notice
rather than guessing at a sign — a truncated URL can never silently show the wrong
prices.

---

## The four checks that catch a mistake from this list

Worth doing in this order, once, before you walk away.

| # | Ask for | You want |
|---|---------|----------|
| 1 | Any page of the app | It loads. A mistake in `.htaccess` is a 500 on everything, so this clears that risk before the checks below mean anything. |
| 2 | `/signs/setup.php` | **404** |
| 3 | `/signs/schema.sql` | **403** — the top-level `.htaccess` is deployed and this host honours it. |
| 4 | `/signs/lib/schema.php` | **403**. A **404** means `lib/.htaccess` never arrived — an FTP client skipped the dotfile. A **200** means it arrived and Apache is not reading it, which is `AllowOverride` being off, and every module in `lib/` is then readable in a browser. |

A folder listing cannot answer 4: an `.htaccess` in a directory where `AllowOverride`
is off sits there being ignored, and nothing but a request can see that. `MANIFEST.txt`
is the other half — it lists every file with its SHA-256, so an upload that dropped one
can be found without guessing.

---

## If something is wrong

**"Database unreachable" on every page.** The four values in
`../private/db_credentials.php`, or the file not being where the app looks. It looks
two folders above `app/`, in `private/`. A user that exists but has not been *added to
the database* with privileges fails exactly the same way.

**A blank white page.** PHP older than 8.2, or a file that did not finish uploading.
Check the PHP version first; `MANIFEST.txt` answers the second.

**The sign shows "no display specified".** The viewer URL has no `?display=` on it, or
the tag does not match one in Admin Panel → Displays.

**Reset emails never arrive.** Step 8. Almost always the `Mail from` address being on
a domain this server does not own.

**Nothing is recorded when something breaks.** Settings → Errors and Alerts names the
path it wants to write to. Give that folder write permission, or set `LBM_LOG_DIR` in
the credentials file to a folder outside the webroot that it can write to.

---

## Updating an install that already exists

**This package is for an empty database.** Do not unpack it over a running install.
Two of its files are the ones a working server owns rather than the package:

- **`setup.php`** must not go up at all. It self-disables and deletes itself once an
  admin exists, so a re-upload heals on the first request that reaches it — but the
  window between the upload and that request is real, and during it a restore to an
  empty database would find a public administrator form.
- **`branding_config.php`** is *generated on the server* by the Branding page. The
  package's copy is the pre-branding default: uploading it reverts the store's name,
  its colours, its logo and the address mail is sent from.

And two things exist only on the server, are in no backup unless somebody took one,
and are destroyed by any client set to mirror rather than merge: `uploads/`, which is
every image and video anybody has added, and the log folder.

For an update, upload the changed files and skip those two. `docs/DEPLOY-SKIP.md` in
the source repository is the full list, including what to re-check afterwards.

---

## What is deliberately not in here

The package holds what a server runs and nothing else, which is why it is smaller than
the source repository by half:

- **No `tools/`.** The test suites, the gates and the mutation tool assert things about
  a checkout, not an install. The node suites need a Node the server does not have, and
  one of them rewrites source files a line at a time on purpose. If the app ever
  suggests running one — the note on a MariaDB host does — run it from a checkout of
  the source repository, pointed at a *copy* of the database.
- **No documentation but this page.** In the source repository, `README.md` is the
  tour, `CONTEXT.md` is the vocabulary the app and its screens use — Display, Viewer,
  Screen, screen name tag, canvas, grant, edit lock — `docs/adr/` records the decisions
  that are load-bearing, and `docs/DEPLOY-SKIP.md` is the one to read before any upload
  to a server that is already running.

Inside the app, **Help** is a full user guide for whoever is building layouts.
