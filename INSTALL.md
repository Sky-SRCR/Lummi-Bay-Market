# Installing the Store Display System

A PHP + MySQL digital-signage builder. Somebody designs a layout in a drag-and-drop
builder, presses **Publish**, and a fullscreen viewer running on a TV picks it up within
30 seconds. One installation drives any number of signs.

**The whole install is one file and two forms.** Upload `install.php` into an empty
folder, open it in a browser, and it unpacks the app, writes the database credentials
above the webroot, creates the tables, makes your account and your first venue, and then
deletes itself.

**It asks for four things about your store, and all four are optional:** the store name, the
address mail is sent from, a logo file, and two colours. Skip any of them and the app ships
with a default you can change signed in. One is worth doing at install time even so — the
**mail-from address**, because left at its default a password reset is sent from a domain
this server does not own, is dropped as spam, and so is the alert that would have told you.

**What it does not ask for: your prices, or anything about a screen.** Those are the work the
app exists for rather than part of installing it — Admin Panel → Displays names each sign,
and the Builder is where a layout is made. Typography and a venue's full six-colour palette
are also left to Display Branding, which has a preview beside every swatch; a colour typed
blind into an install form is a colour you would set twice.

One thing it cannot do for you, and it is worth knowing before you start: **on shared
hosting it cannot create the database.** cPanel owns that — the names carry your account
prefix, the account-to-database mapping lives in cPanel's own records rather than in
MySQL, and the user it issues you has no privilege to create one. A database made behind
cPanel's back is one cPanel does not know about and cannot re-map after a restore. So
step 1 is three clicks in cPanel, and the installer does the rest. On a server you
administer yourself it will offer to create the database too, and succeed.

---

## What you need

| | |
|---|---|
| **PHP 8.2 or newer** | With `pdo_mysql`. On cPanel this is **MultiPHP Manager** — set the domain explicitly rather than leaving it on "inherit", so the version does not move when the host changes its default. The installer checks this before it does anything. |
| **MySQL 5.7 or newer** | And a database user holding eight privileges on it — `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES` — and deliberately not *All Privileges*. Step 1 has the grid to tick and what each one costs if you miss it. MariaDB is *untested*: nothing in this project has ever run on it. |
| **HTTPS working on the domain** | The app's own `.htaccess` redirects every plain-HTTP request to HTTPS once it is in place. Install the certificate first if you can: the installer warns rather than refusing, because a host mid-certificate should not be stranded, but the database password you type crosses the network in the clear until it is done. |
| **`AllowOverride` on** | Two `.htaccess` files carry the security headers and make the module folder unreachable. Almost every shared host allows this; if yours does not, check 3 at the end is the one that fails, and it is the one that matters. |
| **A way to upload one file** | FTP, SFTP, or cPanel's File Manager. Nothing needs a shell. |

No composer, no node, no build step, no cron job.

---

## What is in the package

```
install.php         ← the whole app, in one file. This is the one you upload.
INSTALL.md          this page
VERSION.txt         which build this is, and what it needs
MANIFEST.txt        every file in app/ with its size and SHA-256
app/                the same 49 files, loose, for the manual route
private/
  db_credentials.php  a blank credentials file, for the manual route
```

`install.php` at the top level **carries `app/` inside it**. The `app/` and `private/`
folders beside it are the same thing unpacked, for the manual route further down. You
need one or the other, not both.

The app travels inside one file for a specific reason: the manual route's worst failure
has nothing to do with the app. **Many FTP clients skip dotfiles by default**, so
`lib/.htaccess` does not arrive, and every module in `lib/` is then readable in a browser.
A folder listing cannot see that; only a request can. One file cannot lose part of itself
in transit.

---

## The install

### 1. Create the database — in cPanel, not here

cPanel → **MySQL Databases**. Three things, in this order:

1. **Create New Database.** The name gets your account prefix — `youracct_signs`. Write the
   whole thing down, prefix included; that is what you type into the installer.
2. **Add New User.** Any name (it gets the prefix too) and a password you have written down.
3. **Add User To Database** → pick both → **Add**. That opens the privileges grid, and
   which boxes you tick is the part worth getting right.

#### The eight privileges to tick

| | |
|---|---|
| `SELECT` `INSERT` `UPDATE` `DELETE` | reading and writing the layout, the accounts, the assets |
| `CREATE` | the nine tables, on the install |
| `ALTER` `INDEX` `REFERENCES` | the columns, keys and foreign keys the app adds to a database as it converges — there is no migration tool here, so the app brings its own schema up to date on a signed-in page load |

**Not "ALL PRIVILEGES".** `DROP`, `TRUNCATE`, `LOCK TABLES`, `CREATE TEMPORARY TABLES`,
`EVENT`, `TRIGGER` and the routine privileges appear in **no statement this app ever
issues**. In an app where nothing published can be taken back, a privilege it never uses
is only risk. Leave them unticked.

If you tick "ALL PRIVILEGES" anyway, nothing breaks and the install works. It is a
narrower blast radius you are giving up, not a feature.

#### What each one costs if you miss it

This matters more than it looks, because a missing privilege does **not** announce itself
as a permissions problem:

- **No `CREATE`** — the installer prints the engine's refusal and stops. Loud, and the
  easy case.
- **No `ALTER`** — and this is the bad one. The tables get created, the app **loads**, and
  the column every query is scoped by is missing. Nothing crashes. Settings → Database
  Structure reads *"Nothing is scoped to a Display. Do not publish."* if you get that far.
  A crash would have been kinder.
- **No `REFERENCES`** — the foreign keys are refused, so deleting a display stops taking
  its layout with it and the database stops enforcing what the app assumes.

The installer reads your user's privileges before it creates anything and names any of the
eight it cannot see, so you get told rather than having to work it out from eleven
"command denied" errors. It is a report and not a gate — a role or a wildcard grant can
make `SHOW GRANTS` unreadable — so the engine's own refusals are the real answer, and they
are printed in full.

Then write down all four values: host (`localhost` on almost every shared host), the full
database name **including the prefix**, the user name, the password.

Nothing else in this install is done by hand.

### 2. Upload `install.php`

Create the folder the app will live in — `public_html/signs/`, or whatever name you want
in the URL — and put `install.php` in it. Nothing else.

**Upload it in binary mode**, or use cPanel's File Manager, which always does. An FTP
client set to ASCII or text mode rewrites bytes inside data it believes is text; the app
inside this file would not survive it. If that happens the installer says so and names the
cause — it checks a checksum per file rather than assuming — but re-uploading is the fix
and doing it right the first time is quicker.

### 3. Open it in a browser

`https://your-domain/signs/install.php`

The app unpacks itself, and the page tells you what it found: the PHP version against the
8.2 the app is written for, whether MySQL support is loaded, whether this folder can be
written to, and whether the folder **above** the webroot can be. Anything marked with a ✕
stops the install; a ⚠ will work and has a consequence worth reading.

### 4. Fill in the database form

The four values from step 1. What happens when you press the button:

- it connects, and if the database does not exist it offers to create one — succeeding on
  a server you administer, and telling you plainly to use cPanel when it cannot;
- it reads your user's privileges and names any of the eight it cannot see, so that if
  the next step fails you have already been told why;
- it writes the credentials to `/home/YOUR_ACCOUNT/private/db_credentials.php`, one level
  **above** the webroot where no browser request can reach it, and reads the file back
  before saying it saved;
- it creates the nine tables from `schema.sql`, and if the engine refuses a statement it
  prints every refusal with the database's own message and stops rather than continuing
  over half a schema;
- it converges the rest, which is what creates your first display.

If it cannot write above the webroot, it prints the file for you to place by hand and
waits. **Do not solve that by putting the credentials beside the app.** PHP normally
executes such a file and emits nothing, which reads as safe right up until a
configuration change stops PHP running and Apache hands your database password over as
text.

### 5. Fill in the administrator form

A username, an email address, a password of at least 8 characters, and the **venue
name** — the shop or restaurant this sign belongs to. Every display wears a **Brand**,
which is its typography, palette and logo; naming the venue names the first one. You can
add more later, one per venue.

Under it, **your store — all optional**:

| | |
|---|---|
| **Store name** | Shown in the browser tab, on the sign-in page, and as the name mail comes from. Not the same as the venue above: the venue is one Brand, this is the whole installation. On a single-site shop they are usually the same words; on a property with several restaurants they are not. |
| **Email address mail is sent from** | **The one worth doing now.** Use an address on this domain. Left at the shipped `noreply@yourdomain.com`, password-reset codes are sent from a domain this server does not own, are dropped as spam — and so is the alert that would have told you. |
| **Logo** | JPG, PNG, GIF or WEBP, up to 2 MB. One file, three places: the Asset Library as *Store logo*, the sign-in page, and the venue's Brand. A display only shows it when a layout puts it there. |
| **Colour across the top of the admin pages** | Six-digit hex. The text on it is set to black or white, whichever can be read on what you choose — that one value is worked out rather than asked for, and the form says so. |
| **Background a new sign starts with** | Six-digit hex, and this one a customer sees. It is the venue Brand's default, so every sign wearing that Brand starts here. |

A colour that is not a colour is **refused, not corrected** — you are told which of the two
it was and what a hex colour looks like. Nothing is stored that you did not choose.

Everything on this form is applied in one order, and the order is what decides what a
failure leaves behind: the venue, then the store details, then the account. If the venue
cannot be saved, nothing else is attempted. If the store details cannot be written, the
venue is named and there is still no account. **The account is last because it is the
account that switches the installer off** — so anything that fails above it leaves a page
you can simply fill in again.

One thing that follows from that: the logo is moved into `uploads/` only once every other
field has been checked. A password that does not match its confirmation does not leave a
file behind.

### 6. It deletes itself

The last screen says so. If it says it *could not* delete itself, the host did not allow
the write — **delete `install.php` from the server by hand, now**, before you do anything
else. While it is still being served, pointing the app at an empty database turns it back
into a public form for creating an administrator. The page never claims to have gone
without checking the file is really absent.

---

## The three checks that catch a mistake

The last screen lists these too. Worth doing before you walk away.

| # | Ask for | You want | What another answer means |
|---|---------|----------|---------------------------|
| 1 | `/signs/install.php` | **404** | Anything else means the installer is still being served. Asking for it is also what makes it delete itself, so this check repairs the thing it looks for. |
| 2 | `/signs/schema.sql` | **403** | 403 proves the `.htaccess` at the top of the folder is in place and this host honours it. |
| 3 | `/signs/lib/schema.php` | **403** | A **404** means `lib/.htaccess` never arrived. A **200** means it arrived and Apache is not reading it, which is `AllowOverride` being off — and every module in `lib/` is then readable in a browser. |

A folder listing cannot answer check 3: an `.htaccess` in a directory where
`AllowOverride` is off sits there being ignored, and nothing but a request can see that.

Then one thing that is not a check but is the setting most likely to cost you weeks:
**Admin Panel → Site Branding**, and set the site name and the address mail is sent from.
If you filled those in on the install form, this is already done — open the page and read
them back rather than skipping it, because a wrong address behaves exactly like a right one
until somebody needs a password reset. Left at the shipped `noreply@yourdomain.com`,
password-reset codes are sent from a domain this server does not own and are dropped as spam
— and so is the alert that would have told you.

---

## Your first sign

The install starts with one display, tagged **drive-thru**, at 1920×1080. That name is the
previous shop's, not a setting — rename it in **Admin Panel → Displays**. Two of its
fields decide things that cannot be changed afterwards:

- **Screen name tag** — the sign's address. It is what goes in the viewer URL, so renaming
  it is renaming the URL every TV is pointed at.
- **Canvas size** — fixed when the display is created. Portrait is fine; any size is fine.
  It cannot be changed later, because every block on the canvas is positioned against it.

Then point the television, kiosk or signage player at:

```
https://your-domain/signs/viewer.php?display=YOUR-TAG
```

No login. It scales the canvas to whatever screen it is on and re-reads the layout every
30 seconds, so a publish reaches the sign within half a minute without anybody touching
the TV. **Every viewer URL names its display** — a URL with no `?display=` shows a plain
notice rather than guessing, so a truncated URL can never silently show the wrong prices.

---

## The manual route

Use this if the one-file installer cannot unpack — a host without zlib, which is rare —
or if you would simply rather see the files.

1. Upload **the contents of `app/`** into the folder. Not the `app` folder itself: you
   want `signs/login.php`, not `signs/app/login.php`. **Make sure the two `.htaccess`
   files arrive** — one at the top, one in `lib/` — and set your client to show hidden
   files if it does not already. `MANIFEST.txt` lists every file with its SHA-256, so an
   upload that dropped one can be found rather than guessed at.
2. Upload file-by-file or folder-by-folder with overwrite. Never "mirror", "sync" or "make
   remote match local" — that mode deletes anything on the server the package does not
   have, which later means every photograph and video anybody has uploaded.
3. Make sure the folder is writable by the web user. The app writes `branding_config.php`
   when you save the Branding page and creates `uploads/` the first time somebody adds an
   image. `755` is enough on almost every host.
4. Open `install.php` and carry on from step 3 above. It finds the app already unpacked
   and says so.

`private/db_credentials.php` in the package is the same file the installer writes, blank.
You only need it if you are placing the credentials by hand.

---

## If something is wrong

**"The app is not unpacked", and it mentions binary mode.** The upload rewrote bytes
inside the file. Re-upload in binary mode, or through cPanel's File Manager.

**"Database unreachable" on every page, after a successful install.** The credentials file
was moved or the database user's privileges changed. The app looks two folders above
itself, in `private/`.

**A blank white page.** PHP older than 8.2, or a file that did not finish uploading. The
installer checks the version before anything else, so this is more likely to be the second
— `MANIFEST.txt` answers it.

**The tables were refused.** The installer prints the engine's own message for each
refusal. Almost always a missing privilege: `CREATE` gives you *"Base table or view not
found"* later, and — worse — `CREATE` without `ALTER` produces an app that **loads**, with
the tables present and the column every query is scoped by missing. Settings → Database
Structure says so in as many words if you get that far.

**The sign shows "no display specified".** The viewer URL has no `?display=` on it, or the
tag does not match one in Admin Panel → Displays.

**Reset emails never arrive.** The **Mail from** address on the Site Branding page, on a
domain this server does not own.

**Nothing is recorded when something breaks.** Settings → Errors and Alerts names the path
it wants to write to. Give that folder write permission, or set `LBM_LOG_DIR` in the
credentials file to a folder outside the webroot that it can write to.

**It said "Installed" straight away — no database form, no account form, nothing to fill
in.** This is the one branch that looks like a broken installer and is a working guard.
There was already a credentials file two folders above this folder, from another install on
the same account, and the database it names already holds an administrator — so there was no
first administrator to create, and `install.php` disabled itself and deleted itself, which is
what it does on any database that already has an account in it. What you were then looking at
was **the other install's app**, on the other install's database. Nothing was overwritten.
This build names the database it reached on that screen; older ones did not, which is why it
read as nothing having happened.

To get the install you meant: create the credentials file for *this* folder first (see
**Pointing an install at a different database**), then upload `install.php` again — it
deleted itself, so it has to come back — and open it. It will find the new file, see a
database with no tables in it, and start at the database step.

---

## Pointing an install at a different database

**One file, above the webroot, and nothing in the app folder changes.**

The app looks for its credentials in two places, in this order:

```
/home/YOUR_ACCOUNT/private/db_credentials_<folder>.php   ← if it exists
/home/YOUR_ACCOUNT/private/db_credentials.php            ← otherwise
```

`<folder>` is the folder the app is installed in — `db_credentials_signs-test.php` for an
install in `public_html/signs-test/`. So to move one install onto its own database, create
the name-specific file with that database's host, name, user and password. It is looked
for first, the shared file stops applying to that folder, and every other install on the
account is unaffected. Copy `private/db_credentials.php` out of the package for the shape,
or copy the file that is already there and change the four values.

Two things to know before you do it:

- **A database that has never been installed into is empty**, and the app cannot build
  itself from nothing — `schema.sql` creates nine tables and only four of them are ones
  the app converges on its own. So after writing the credentials file, upload
  `install.php` into that folder again and open it. It will find the new file, see a
  database with no tables in it, and carry on from there. Signing in first gets you an
  error about a missing table, not an install.
- **The old database is untouched.** Nothing is moved, copied or deleted. If the layout
  you want is in the *old* database, this is not the tool for that — export it in
  phpMyAdmin and import it into the new one before you point anything at it.

Then check it, before you publish anything: **Admin Panel → Settings → This Server** names
the install folder, the database it reached, and — new since this build — which of the two
credentials files it read. Nothing else in the app will tell you.

**And one line worth adding to the credentials file you keep.** A file the installer wrote
records the folder it was written for; a file written by hand, or by an installer older than
this build, does not — so the installer cannot tell whether it belongs to this folder or the
one next to it, and a *later* install in a second folder will adopt it rather than asking for
a database of its own. Add the line to the file that is already there:

```php
define('DB_INSTALL_FOLDER', 'signs');   // the folder this app is installed in
```

Nothing in the app reads it. What it buys is that the next install in a second folder is
**refused** that file — no connection made through it, and a database form instead — rather
than joining this one's signs in silence.

---

## Installing a second copy, for rehearsing

Two installs on one account walk up to the same `private/` folder, so a second copy with
no credentials file of its own connects to the **first one's database** — and then behaves
perfectly. Signing in converges schema on the live tables; pressing Publish overwrites a
real sign. From the app's point of view nothing is wrong.

The installer will not walk into that on its own. It reads the credentials file it found
before it connects to anything, and every file it has written since this build says which
install folder it was written for. A file that names a different folder is **not** used:
no connection is made through it, and you are asked for a database for this install
instead. The file it then writes is named after this folder, so the two stay apart from
that point on.

The one case it cannot decide is a credentials file written **before** this build, or by
hand — the shared one on an install that predates the stamp. Nothing on disk says whether
that file belongs to this folder or the one next to it, so the installer says exactly that,
names the database it reached and names the file to create, and otherwise behaves as it
always did. Read that sentence rather than clicking past it; it is on the screen that makes
your account, and on the screen that says the install is finished.

Either way, check it before signing in a second time, not after: **Admin Panel → Settings
→ This Server** names the install folder and the database it reached. If the database is
the first one's, stop and do not publish.

---

## Updating an install that already exists

**This package is for an empty database.** Do not unpack it over a running install.

`install.php` must not go up at all. It self-disables and deletes itself once an
administrator exists, so a re-upload heals on the first request that reaches it — but the
window between the upload and that request is real, and during it a restore to an empty
database would find a public administrator form.

`branding_config.php` is *generated on the server* by the Branding page. The package's copy
is the pre-branding default: uploading it reverts the store's name, its colours, its logo
and the address mail is sent from.

And two things exist only on the server, are in no backup unless somebody took one, and are
destroyed by any client set to mirror rather than merge: `uploads/`, which is every image
and video anybody has added, and the log folder.

For an update, upload the changed files and skip those two. `docs/DEPLOY-SKIP.md` in the
source repository is the full list, including what to re-check afterwards.

---

## What is deliberately not in here

- **No `tools/`.** The test suites, the gates and the mutation tool assert things about a
  checkout, not an install. The node suites need a Node the server does not have, and one
  of them rewrites source files a line at a time on purpose. If the app ever suggests
  running one — the note on a MariaDB host does — run it from a checkout of the source
  repository, pointed at a *copy* of the database.
- **No documentation but this page.** In the source repository, `README.md` is the tour,
  `CONTEXT.md` is the vocabulary the app and its screens use — Display, Viewer, Screen,
  screen name tag, canvas, grant, edit lock — `docs/adr/` records the decisions that are
  load-bearing, and `docs/DEPLOY-SKIP.md` is the one to read before any upload to a server
  that is already running.

Inside the app, **Help** is a full user guide for whoever is building layouts.
