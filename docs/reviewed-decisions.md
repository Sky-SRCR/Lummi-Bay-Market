# Reviewed decisions — the 51-item list

A ten-agent adversarial audit of this repo found everything below. Each item was
put to the store's owner in plain language with a recommended answer, and each got
a decision. **This file is the index of those decisions**, so the numbering survives
outside a chat window and anyone picking the work up can see what was agreed and
what is left.

The detailed write-up of anything marked **Done** — the reasoning, the alternatives
rejected, and the deliberate breakages the tests catch — is in
[`BUILD-REFERENCE.md`](BUILD-REFERENCE.md), in the section named in the last column.
This file is the map; that file is the territory.

## How to read it

- **The numbers here are the ones to use.** "Let's do #22" means the #22 in this
  table and nothing else.
- **Status** is what the repo records, not a fresh audit. `Open` means no work has
  been done on it, not that somebody re-confirmed it is still broken.
- **There is no #47.** The numbering skipped it while the list was being walked
  through. Nothing is missing; the count is 50 numbered items plus one unnumbered
  policy, which is the 51.

### Two numbering traps

Both of these have caused real confusion and neither is going away, so they are
written down rather than remembered:

1. **The task list in the assistant's tooling has its own numbering.** Task #22 is
   "Correct help.php for multiple Displays" and has nothing to do with decision #22.
   Tasks name their decision in brackets — `(#16)` — for exactly this reason.
2. **The raw audit list was numbered differently.** It had 52 rows, and from about
   its 13th onward it runs one ahead of this one. Anything quoting an audit row
   number needs translating; this table is the authority.

## The list

| # | The problem, plainly | Decided | Status | Written up |
|---|----------------------|---------|--------|-----------|
| 1 | The Builder addressed its sign only by the screen name tag, which an admin may rename. A publish could land on a different sign and report success. | Send the id as well as the tag, and refuse any write where the two disagree. | **Done** | §4h |
| 2 | The password-reset guess limit was counted in the browser's own session — the one thing a guesser controls. | Count the guesses in the database. | **Done** | §4i |
| 3 | The read-only Builder still sent the inspector, the align bar and the modals to people who may not edit. | Don't send them at all. | **Done** | §4j |
| 4 | Nothing anywhere said whether PHP may print errors, so a warning or a stack trace could appear on a public page. | Set the policy in code: display off, logging on. | **Done** | §4m |
| 5 | A database failure on the public path showed a stack trace or a blank page on the TV instead of the notice. | Show the kiosk notice, and send an alert. | **Done** | §4m |
| 6 | Three of the four Builder upload paths had no failure handling, so a failed upload said nothing at all. | Report what went wrong, and show progress while it uploads. | **Done** | §4n |
| 7 | The asset library had no owning module; pooled rows built up forever and were shared between signs. | Give it a module, stop sharing rows, clean up the junk. | **Done** | §4n |
| 8 | Schema convergence issued three real table alterations on **every** signed-in page load. | Ask the database what it already has, and send only what is missing. | **Done** | §4o |
| 9 | A schema fix that genuinely failed was swallowed, and then every screen returned a 500. | Log the real failure instead of papering over it, and email the admins. | **Done** | §4p |
| 10 | The public Viewer poll could set the database repair going — every 30 seconds, from every screen. | Signed-in requests only. | **Done** | §4q |
| 11 | The self-repair path was gated on a MySQL-only error code, so nothing on any engine ever tested it. | Make it engine-neutral and cover it with tests. | **Done** | §4q |
| 12 | Convergence could run inside an open save, where a schema change silently commits half of it. | Refuse outright inside a transaction. | **Done** | §4q |
| — | An alert per failure would exhaust the host's mail allowance exactly when a database is down, so the one alert that matters never arrives. | One email per problem per hour, to admins only. | **Done** | §4m |
| 13 | The last step of a password reset made two irreversible writes with no transaction between them. | All-or-nothing. | **Done** | §4r |
| 14 | The Phase 1 rehearsal script proved a tautology — it agreed with itself. | Make it prove what it claims. | **Done** | §4r |
| 15 | A username containing HTML reached a confirm box unescaped, and 133 escaping calls carried no flags. | Escape every stored value strictly, app-wide. | Open | — |
| 16 | The permissions grid read a column that wasn't on the page as "take that access away", and F5 replayed the whole save. | Save only what the form covered, then redirect. | **Done** | §4s |
| 17 | Taking access away left the edit lock stranded on the person who lost it, and told them nothing. | Release it, and tell them. | **Done** | §4s |
| 18 | Promoting somebody to admin left individual assignments nothing could see and nothing could remove. | Clear them on promotion. | **Done** | §4s |
| 19 | Deleting a Display never asked whether anyone was editing it, and the confirm undercounted what a mid-edit clerk loses. | Refuse while somebody else is editing. | Open | — |
| 20 | Deleting an account freed its number for the next person, leaving "last published by" naming a stranger. | Keep the username as text, and never reuse an account number — close accounts instead of deleting them. | **Done** | §4l |
| 21 | The admin panel coerced values it could not parse and reported success — an unreadable colour, an account id that isn't one. | Refuse, and say so. | Open | — |
| 22 | Turning off a Display, suspending an account, or renaming a tag each left the edit lock behind, and never re-checked whether the holder may still sign in. | Free the lock when reach changes, never honour a lock whose holder cannot sign in, and tell the person. A rename tells them but keeps their lock. | **Done** | §4t |
| 23 | Choosing "Image" for a background when no image is stored leaves a colour where a filename goes — the sign goes near-black. | Refuse the change. | Open | — |
| 24 | The background address was validated in the admin panel but not in the API, so a publish could point every screen at any host. | Validate it in the module. | Open | — |
| 25 | The public feed served blocks an admin had deliberately hidden, content and all. | Leave them out. | Open | — |
| 26 | A reply that failed to encode sent back a zero-length success, and the sign kept its old layout forever with no notice. | Send a real error, and let the sign notice. | Open | — |
| 27 | `?display[]=x` became the tag "array" and printed a warning above the document. | Treat it as no sign named. | Open | — |
| 28 | Missing, unknown and switched-off signs all answered "200 OK", and nothing anywhere set caching rules. | Real error codes, and stop caching. | Open | — |
| 29 | Publish accepted any block type, so a basic account could insert top-level content. | Accept only known types and refuse the rest. | Open | — |
| 30 | Wrong-shaped and absurd values were coerced and written rather than refused. | Refuse the publish. | Open | — |
| 31 | Two blocks sharing a temporary id silently reparented one of them into the wrong section. | Refuse the publish. | Open | — |
| 32 | Line height was stored with a thousands separator, so some values could not be read back. *(First framed as a prices problem. It never touched prices — no sign has ever shown a stray comma.)* | Clamp it to 0.5–5 and store it plain. | Open | — |
| 33 | An account with no signs assigned could still write the shared asset library and upload files. | Nothing until it has a sign. | Open | — |
| 34 | A file bigger than the server's real limit was reported as a security problem. | Detect it and say so plainly. | **Done** | §4n |
| 35 | A publish that collided with another died as a PHP timeout before it could reach its own clean message. | Give up on the collision sooner, and report it properly. | Open | — |
| 36 | The branding file was written in place with no locking, so a short write took the whole app down. | Write a temporary file, then swap it in. | Open | — |
| 37 | The asset editor read the file type from a hidden form field instead of the stored record. | Read it from the record. | Open | — |
| 38 | Two login problems: a secure-cookie setting causes an invisible sign-in loop on plain HTTP, and the suspended-account message tells a guesser the password was right. | Fix both. | Open | — |
| 39 | Double-clicking Publish produced a success message and a stale-sign warning together. | Ignore the second while the first is still running. | Open | — |
| 40 | A basic account with the sign open read-only threw an error on every canvas click. | Guard the lookup. | Open | — |
| 41 | An unreadable stored colour round-tripped through the colour picker and published back as black. | Validate on the way in and on the way out. | Open | — |
| 42 | Six smaller Builder rough edges: section minimum size measured in screen pixels, Fit cannot fit a very large canvas, no way to unhide a section, deleting a slide field cannot be undone, marquee "Transparent" loses the colour, and dead code. | All six. | Open | — |
| 43 | Deleting an account wrote to three tables with no transaction, going around the owning module. | All-or-nothing, through the module. | **Done** — settled by #20: closing is one transaction in `AccountAdmin`, and no `DELETE FROM users` exists anywhere. | §4l |
| 44 | Nothing set a timezone, so "editing since 2:15pm" followed whatever the host's `php.ini` happened to say. | A store timezone setting on the Branding page. | Open | — |
| 45 | The sign itself printed "Carousel — no slides added yet" where a customer could read it. | Draw nothing. | Open | — |
| 46 | Deployment step 3 had no do-not-overwrite list, so re-uploading reverted live branding and restored `setup.php`. | Write down what to skip. | Open | — |
| 48 | The test database differs from MySQL in twelve ways, including row locking stubbed out entirely. | Test against real MySQL as well. | Open | — |
| 49 | `plain_text.php` had 20% mutation coverage and `schema.php` had none at all. | Cover both. | Part done — `schema.php`'s *decision* is now a pure function with 43 checks (§4o); its statements are still MySQL-only. | §4o |
| 50 | About 29 checks in the suite could not fail, and five invariants had no automated check at all. | Replace the hollow ones, and cover the missing rules. | Open — the harness itself was hardened so a suite that stops early now fails, but the 29 have not been swept. | — |
| 51 | CI pins PHP 8.2 against a 7.1 target, and runs neither the consistency greps nor the rehearsal. | Match the live version, and run everything. | Part done — **the live PHP version is still unknown.** Settings → This Server answers it the first time an admin opens it after deploy; until then the 7.1 rule stands. | §4g |

## Where this stands

**22 done, 2 part done, 27 open** — 51 rows in all.

The 22 is 21 numbered rows plus the unnumbered mail-allowance policy, so counting
only the numbered `Done` rows gives 21 and looks like the tally is off by one. It
isn't. The two part done are #49 and #51; the 27 open are #15, #19, #21, #23–#33
(all eleven), #35–#42 (all eight), #44, #45, #46, #48 and #50.

The order has been the owner's call throughout, one item at a time. There is no
suggested order in this file on purpose — anything left is worth doing, and which
one comes next is a judgement about the store, not about the code. What the next
section adds is a different question with a factual answer: which of them can be
worked on *at the same time* without two people editing the same file.

## Parallel lanes

Grouped by which files an item actually touches, because that — not what the item
is about — is what decides whether two of them can run at once. Within a lane the
arrows are a working order; between lanes there is nothing to coordinate.

**Two items cannot be parallelised at all, and they bracket everything else:**

| | Why it stands alone |
|---|---|
| **#15** | The escaping sweep touches nearly every `.php` file in the repo. Anything running beside it loses its diff. Run it on its own, with every lane merged first — or run it before the lanes open. |
| **#49, #50** (and #48's fixture half) | They rewrite `tools/selftest_layout.php`, the one file *every* other item appends checks to. Run them after the lanes drain, not beside them. |

The remaining 26 fall into seven lanes. A, B, C, E and G share no files with each
other at all.

| Lane | Files it owns | Items |
|------|---------------|-------|
| **A — deploy & CI** | `HANDOFF.md`, `.github/workflows/php-lint.yml` | #46 → #51 → #48 (CI half) |
| **B — Viewer/API surface** | `api.php` (dispatch and headers), `viewer.php` | #27 → #28 → #26, and #45 |
| **C — the publish gate** | `lib/layout_store.php` | #29 → #30 → #31 → #25 → #32 → #35 |
| **D — admin panel & Displays** | `admin_panel.php`, `lib/displays.php`, `lib/display_admin.php` | #23 → #24 → #21 → #36 → #19 → #44 |
| **E — Builder JavaScript** | `builder.php` | #40 → #39 → #42, then #41 once Lane C has closed |
| **F — assets** | `crud.php`, `lib/assets.php` | #37 → #33 |
| **G — login** | `auth.php`, `login.php` | #38 |

Lane A touches no application code, so it can start immediately and finish
independently; #51 stalls until the deploy visit answers the live PHP version.
Lane E is gated by the two node suites rather than by `php -l`, and Lane C by
`php tools/selftest_layout.php` — see the *Before pushing* block in `CLAUDE.md`.

### Where a lane brushes another

Three items straddle a boundary, and each needs placing on purpose:

- **#32** is really two edits. The defect is `lib/layout_store.php` (lines 324 and
  729 format a line height with `number_format`'s default thousands separator), so
  Lane C owns it; `admin_panel.php:1216` is the same one-line follow-on and is
  Lane D's to take.
- **#44** needs a new statement and gate in `lib/schema.php`, which is also #49's
  file. Do #44 *before* #49 starts, not beside it.
- **#41** is the one item not to hand to a parallel worker. Validating a colour "on
  the way in and on the way out" means the publish path and the colour picker, so
  it wants Lanes C and E already settled — schedule it after they close, or split
  the two halves and name which lane has which.

Two smaller overlaps are safe but worth knowing: **#33** touches api.php's upload
handlers (around lines 325–357) and **#23**/**#24** touch its background handler
(around 200–230), both well away from the dispatch and header work in Lane B.

Every lane will also append checks to `tools/selftest_layout.php` and conflict
there on merge. Those are append-only additions in separate blocks, so they
resolve mechanically — keeping each lane's checks together and near their own
subject makes it close to automatic.

### One note on #38

The row above says a secure-cookie setting causes an invisible sign-in loop on
plain HTTP, and `auth.php` does set `secure` unconditionally. On the live site
`.htaccess` redirects to HTTPS, so that half is latent in production and bites a
local or plain-HTTP install instead. The half that is live today is the second
one: the suspended-account message tells a guesser the password was right. The
decision is still to fix both; only the urgency of the first is lower than the
row reads.
