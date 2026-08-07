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
| `setup.php` | Creates the **first admin account** when the `users` table is empty, and is meant to be deleted from the server once setup is done (`README` §3 says so, and so does the page itself). It self-disables while it can count users — but point the app at an empty or freshly restored database and it is a public "make yourself an admin" form again. Nothing in `.htaccess` blocks it. Re-uploading the tree silently puts it back. |
| `.git/` | Served, if it lands in the webroot. It hands out the entire history of the repo to anyone who asks for it. Upload the working files, never the repository. |
| `*.md` and `docs/` | `HANDOFF.md` names the live database, the credentials path outside the webroot, and where the error log goes. `.htaccess` now denies `.md` as a backstop, but the rule is belt-and-braces — the reason not to upload them is that they are of no use to the server. |
| `.github/`, `tools/*.js`, `.gitignore` | Nothing on the server runs them. The node suites need a Node that isn't there. |

`schema.sql` and everything in `lib/` and `tools/` **are** already denied by an
`.htaccess` (root, `lib/`, `tools/` respectively), so uploading those is safe — and
`lib/.htaccess` and `tools/.htaccess` must go up *with* their folders or the modules
and the rehearsal script become readable.

### Known live state — checked 2026-08-07, and one thing is outstanding

The audit framed this group as *re-uploading restored `setup.php`*. It was never
deleted, so there is a file to clear before the rule about not re-uploading it means
anything. What the live server answered:

| Path | Answered | Reading |
|------|----------|---------|
| `setup.php` | **200** | *"Setup is complete. This page is disabled. Please delete setup.php from your server."* |
| `HANDOFF.md`, `README.md`, `CLAUDE.md`, `docs/BUILD-REFERENCE.md` | 404 | Never uploaded. The `.md` deny is a backstop, not a fix for a live exposure. |
| `.git/config` | 404 | The repository has never been uploaded. |
| `schema.sql` | 403 | The root `.htaccess` is deployed and its `FilesMatch` blocks work on this host. |
| `lib/schema.php` | 404 | `lib/` is not on the server yet — the multi-display build is still undeployed. |

**Outstanding: delete `setup.php` from the live server.** It is *not* an
admin-creation hole today, because accounts exist and it disables itself. It is an
unauthenticated page that touches the database on every request, confirms what the
app is, and prints its own removal instructions — and it becomes the form again the
moment the app is pointed at an empty or restored database. Deleting it needs no
downtime and nothing depends on it; the app is fully set up.

Do this **before** the next upload rather than during it. Check 3 below currently
fails, which is what a working list looks like on its first use.

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

## After you upload, five checks that catch a mistake from this list

1. **Admin Panel → Settings → This Server** — the site name is the store's, not
   "Store Display System" (A), and the upload limit is what it was (D).
2. **Admin Panel → Settings → Errors and Alerts** — it names a writable path and
   says who an alert would reach. *"Nowhere to write"* means the log folder was
   deleted or its permissions changed (C), and until it is fixed nothing that goes
   wrong is recorded and no alert can be sent.
3. **`https://srcresort.com/lbm/setup.php` must be gone** — a 404, not the setup
   form and not "Setup is complete" (B). *This check fails today; see the outstanding
   item above.*
4. **The brand logo still renders** in the nav bar — the cheapest proof that
   `uploads/` survived (C).
5. **`https://srcresort.com/lbm/README.md` must answer 403** — not the file, and not
   404. 403 proves the new `.htaccess` block parsed and is doing its job; **404 only
   proves nobody uploaded the docs this time**, which is the right outcome but not a
   test of the rule. Load any page of the app first: a mistake in `.htaccess` is a 500
   on everything, so the app answering at all is what clears that risk (D).
