# What not to overwrite when you upload

Read this **before every upload**, not only the multi-display one. The repo and the
server are not the same set of files: three of the server's files are written by the
running app or by hand and the repo's copies are stale defaults, one repo file is
supposed to have been deleted from the server, and two folders exist only on the
server and are in no backup unless somebody took one.

Uploading the whole tree over the top reverts the first group, restores the second,
and — if the client is set to mirror rather than merge — deletes the third. None of
it announces itself. The app comes up looking fine.

**Upload file-by-file, or folder-by-folder with overwrite.** Never a mirror, sync,
or "make remote match local" — that is the mode that takes `uploads/` with it, and
there is no undo for a store's photographs and videos.

## A. Never overwrite — the server's copy is the authority

| File | What the repo's copy costs you |
|------|-------------------------------|
| `branding_config.php` | It is **generated on the server** by Admin Panel → Site Branding (`writeBrandingConfig()`, `admin_panel.php`). It holds the store's name, the logo path, the four nav colours, and the address mail is sent *from*. The repo's copy is the pre-branding default and does not even define `SITE_NAME` or `MAIL_FROM`, so `config.php` falls back to "Store Display System" and `noreply@yourdomain.com`. |

The colours reverting is cosmetic and obvious. `MAIL_FROM` reverting is neither: it
is the `From:` on password-reset codes (`reset_password.php`) and on every schema or
error alert (`lib/alerts.php`). Sent from a domain this host does not own, those
messages are dropped as spam — so the first symptom is somebody unable to reset a
password weeks later, and the alert that would have said why never arrives either.

Right now the live copy still holds the defaults (HANDOFF §5), so today there is
almost nothing to lose. This list exists so that stays true after somebody finally
uses the Branding page.

If you have already overwritten it: nothing is broken and nothing is lost from the
database. Open Admin Panel → Site Branding, re-enter the values, and save — that
rewrites the file.

## B. Never upload — the file must not be in the webroot at all

| File | Why |
|------|-----|
| `setup.php` | Creates the **first admin account** when the `users` table is empty. It self-disables while it can count users — but point the app at an empty or freshly restored database and it is a public "make yourself an admin" form again. Nothing in `.htaccess` blocks it. **It now deletes itself** once an admin exists, so a re-upload is self-healing on the first request that reaches it — which is a safety net, not permission to upload it. See below. |
| `.git/` | Served, if it lands in the webroot. It hands out the entire history of the repo to anyone who asks for it. Upload the working files, never the repository. |
| `*.md` and `docs/` | `HANDOFF.md` names the live database, the credentials path outside the webroot, and where the error log goes. `.htaccess` now denies `.md` as a backstop, but the rule is belt-and-braces — the reason not to upload them is that they are of no use to the server. |
| `.github/`, `tools/*.js`, `.gitignore` | Nothing on the server runs them. The node suites need a Node that isn't there. |

`schema.sql` and everything in `lib/` and `tools/` **are** already denied by an
`.htaccess` (root, `lib/`, `tools/` respectively), so uploading those is safe — and
`lib/.htaccess` and `tools/.htaccess` must go up *with* their folders or the modules
and the rehearsal script become readable.

### Known live state — checked 2026-08-07

The audit framed this group as *re-uploading restored `setup.php`*. It had never been
deleted at all: it answered **200** with *"Setup is complete. This page is disabled.
Please delete setup.php from your server."* It has since been deleted by hand, and it
now answers 404. What the live server said, once asked:

| Path | Answered | Reading |
|------|----------|---------|
| `setup.php` | 200 → **404** | Was never deleted after the original setup; deleted by hand 2026-08-07. |
| `HANDOFF.md`, `README.md`, `CLAUDE.md`, `docs/BUILD-REFERENCE.md` | 404 | Never uploaded. The `.md` deny is a backstop, not a fix for a live exposure. |
| `.git/config` | 404 | The repository has never been uploaded. |
| `schema.sql` | 403 | The root `.htaccess` is deployed and its `FilesMatch` blocks work on this host. |
| `lib/schema.php` | 404 | `lib/` is not on the server yet — the multi-display build is still undeployed. |

**And it will not need deleting by hand again.** `setup.php` now removes itself: at the
end of a successful setup, and otherwise on the first request that finds it already
disabled. That is the difference between a rule somebody has to remember and a file
that leaves on its own — which is the whole complaint behind this list.

Three things it does *not* mean, so nobody leans on it:

- **Upload it and it is still there** until somebody or something requests it. The
  window between the upload and the first hit is real, and during it a restore to an
  empty database would find the form intact. The rule stands: do not upload it.
- **It only goes when it can tell it is finished.** If the database is unreachable, or
  the `users` table is missing, the count throws and the file stays — correctly, since
  the app cannot tell whether setup is done.
- **A host that forbids the delete leaves it there and says so.** The page reads the
  answer back from the filesystem instead of trusting `unlink()`, so it never claims to
  have gone while it is still being served. That message — *"It could not delete
  itself"* — is the one to act on by hand.

## C. Never delete — these exist only on the server

| Path | What is in it |
|------|---------------|
| `uploads/` | Every image, video and background anybody has uploaded, **including the brand logo** `branding_config.php` points at. Git-ignored, so no upload can overwrite it — only a mirroring client can destroy it. |
| `logs/` — or `../../private/logs/`, whichever `ErrorPolicy::stateDir()` picked | `lbm-error.log` and its rotated `.1`, the alert throttle's `alert-*.stamp` and `report-*.stamp` files, the cached recipient list `alert-recipients.txt`, and `schema-repair.lock`. |
| `../../private/db_credentials.php` | `DB_HOST/DB_NAME/DB_USER/DB_PASS`, and `LBM_LOG_DIR` if it is set. Outside the webroot, so it is not in the upload path — the only rule is **never copy it inside**. |

**Whether anything backs `uploads/` up is unverified.** Nothing in this repo does, and
checklist step 1 backs up the *database* only — so treat it as unrecoverable until
somebody confirms the host takes file-level backups. If it does, name where they are
here; that is the difference between losing the store's photographs and losing an
afternoon.

Deleting the log folder is survivable, and it is worth knowing exactly how far:
the directory, its deny-all `.htaccess` and its empty `index.html` are rewritten on
the next request that needs them, and the throttle stamps rebuilding costs at most
one duplicate email. What does not come back is the error history — the record of
what went wrong on the sign, which is the thing you would be reading if something
had. `schema-repair.lock` is an `flock` and is expected to be zero bytes; deleting
it while the site is idle is harmless.

## D. Overwrite, but read the live one first

**The root `.htaccess` always goes up.** It carries the security headers, the `.sql`
and `.md` denials, and the `viewer.php` framing exception every Display depends on —
group B's backstop is *in* this file, so a deploy that skipped it to protect a
hand-edit would leave the docs served if they were ever uploaded. Overwrite it, and
carry any hand-edit forward by hand. **Read before you replace, not instead of
replacing** — that is the precedence rule, and it is the one place in this list where
"leave the live copy alone" would be the wrong instinct.

What to look for in the live copy: it is the one file somebody may have edited **on
the server**, because raising `php_value upload_max_filesize` / `post_max_size` to let
a bigger video through is done there and nowhere else. Uploading over it reverts that,
and the only sign is the upload limit quietly dropping back. Whether anyone ever did
is unverified — the file denies itself to a browser by design, so only somebody with
FTP can answer it. `schema.sql` answering 403 says the live copy is at least roughly
the repo's, which makes a hand-edit unlikely rather than impossible.

## E. A second install, for rehearsing on a copy

`https://www.srcresort.com/lbm-test/` is a rehearsal copy, with its own database
(`silverad_lummi_market_drive_thru_2`). PHP on this host is 8.2 (#51 — this is where
that came from).

**The trap this exists to close.** `lbm-test/` sits at the same depth as `lbm/`, and
credentials are found by walking two folders up to `private/`. Both folders reach the
*same* file, so an unmodified copy in `lbm-test/` connects to the **live** database —
and then behaves perfectly. Signing in converges schema on the live tables. Pressing
Publish overwrites a real sign. Nothing warns you, because from the app's point of view
nothing is wrong. There is no undo anywhere here, so the first symptom is a customer
reading the wrong prices.

**So the rehearsal copy gets its own credentials file, and nothing in the tree
changes.** `lib/install_paths.php` looks for a name-specific file first:

```
/home/ACCOUNT/private/db_credentials_lbm-test.php     <- create this, once
/home/ACCOUNT/private/db_credentials.php              <- the live one, untouched
```

`db_credentials_lbm-test.php` is the live file with two lines changed:

```php
<?php
define('CREDENTIALS_FOR', 'lbm-test');                     // the folder it is for
define('DB_HOST', 'localhost');
define('DB_NAME', 'silverad_lummi_market_drive_thru_2');   // the copy
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
```

Do **not** edit `lbm-test/db_connect.php` instead. A hand-edited tracked file survives
only as long as somebody remembers it, reverts on the next upload, and what it reverts
*to* is "the test folder points at the live database".

### The one line that has to go on the server *before* the tree does

**Do this first, on its own, and check it off.** Add one line to the top of the shared
credentials file — the live one, `/home/ACCOUNT/private/db_credentials.php`:

```php
define('CREDENTIALS_FOR', 'lbm');
```

`lbm` is the *folder name* the live app runs from. If the app is ever moved to a
differently-named folder, this line moves with it.

**Why before, and why on its own.** Finding a file was never the same question as being
allowed to use it, and the app now asks both: an install that reaches the shared file
and is not named in it refuses to connect and says so, instead of connecting to a
database nobody gave it. An undeclared file names nobody, so it is refused too — a rule
that only engages once somebody remembers to configure it protects exactly the installs
whose owner did not need protecting.

That is a rule the live install has to be on the right side of before it meets it. It
costs nothing to get right, because **the line is inert to every version of this app
that came before this one**: an unused constant, defined and never read. Add it today,
upload the tree next month, and there is no window in between where the sign is dark. Do
it the other way round and there is — a short one, ending the moment somebody adds the
line, and a Screen recovers on its own within 30 seconds of that.

If a sign or an admin page ever *does* say the install was not told which database
belongs to it, that is this and nothing else. The error log — and the alert email, if an
admin has an address on file — names the file and the exact line to add.

### Uploading to `lbm-test/`

Groups A–D above still apply, with two differences:

- **`branding_config.php`** — group A protects the *server's* copy because the app
  generates it. A brand-new folder has no copy to protect, so upload the repo's one
  here; the app rewrites it the first time you save on the Branding page.
- **`setup.php`** — group B, and it matters *more* here. If the copied database has its
  users table, the page self-disables and deletes itself. Point a fresh install at an
  empty database and it is a public "make yourself an admin" form. Do not upload it.

Everything else is unchanged, including the three `.htaccess` files. A fresh folder has
none, so `lib/` and `tools/` are browsable until they arrive — upload each with its
folder, not afterwards.

### The one check that proves it is isolated

**Admin Panel → Settings → This Server** now reports **This install**, **Credentials**
and **Database**. On `lbm-test/` they must read `lbm-test`,
`db_credentials_lbm-test.php` and `silverad_lummi_market_drive_thru_2`.

**Credentials** is the row to read first, because it is the one that does not need you
to already know the answer. `db_credentials_lbm-test.php` means this folder has a file
of its own and no fall-back happened at all. `db_credentials.php` — the shared file —
means it is using the live install's file *and has been named in it*, which on
`lbm-test/` is a mistake somebody made in the shared file rather than a hazard the app
missed.

If **Database** says `silverad_lummi_market_drive_thru`, the per-install credentials
file is missing or misnamed, and this folder is talking to the live sign. Stop, and do
not publish.

Run that check **before** signing in a second time, not after — the sign-in that shows
you the card is also the one that converges schema on whatever database it found.

**What has changed is what happens when you forget.** This card used to be the only
thing in the app that could tell you, so a folder set up wrongly worked perfectly until
somebody thought to look. Now a folder that has not been given credentials of its own,
and is not named in the shared file, does not connect at all — it prints a sentence and
writes the fix to the log. The card is still worth reading; it is no longer the only
thing standing between a rehearsal copy and a live sign.

## After you upload, five checks that catch a mistake from this list

1. **Admin Panel → Settings → This Server** — the site name is the store's, not
   "Store Display System" (A), and the upload limit is what it was (D). **Credentials**
   and **Database** name the file and the database this folder is really using (E). On
   the live install that is `db_credentials.php` and the live database; anywhere else it
   is that folder's own file. Reaching this page at all means the app was satisfied the
   credentials were this install's — a folder that was not named in the file it found
   never got as far as a page to show you.
2. **Admin Panel → Settings → Errors and Alerts** — it names a writable path and
   says who an alert would reach. *"Nowhere to write"* means the log folder was
   deleted or its permissions changed (C), and until it is fixed nothing that goes
   wrong is recorded and no alert can be sent.
3. **`https://srcresort.com/lbm/setup.php` must be gone** — a 404, not the setup form
   and not "Setup is complete" (B). Requesting it is also what makes it delete itself
   if it was uploaded, so this check *repairs* the mistake it is looking for — but only
   if you actually run it. A page reading *"could not delete itself"* needs a hand.
4. **The brand logo still renders** in the nav bar — the cheapest proof that
   `uploads/` survived (C).
5. **`https://srcresort.com/lbm/README.md` must answer 403** — not the file, and not
   404. 403 proves the new `.htaccess` block parsed and is doing its job; **404 only
   proves nobody uploaded the docs this time**, which is the right outcome but not a
   test of the rule. Load any page of the app first: a mistake in `.htaccess` is a 500
   on everything, so the app answering at all is what clears that risk (D).
