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
| 19 | Deleting a Display never asked whether anyone was editing it, and the confirm undercounted what a mid-edit clerk loses. | Refuse while somebody else is editing. | **Done** — the refusal is asked twice, before the typed tag and again inside the transaction on a row the module reads itself. The confirm now names the accounts that lose access and, when somebody has it open, who and since when. | §4x |
| 20 | Deleting an account freed its number for the next person, leaving "last published by" naming a stranger. | Keep the username as text, and never reuse an account number — close accounts instead of deleting them. | **Done** | §4l |
| 21 | The admin panel coerced values it could not parse and reported success — an unreadable colour, an account id that isn't one. | Refuse, and say so. | **Done** — taken with #41; they are one defect from two ends. What a colour is now lives in one pure module instead of four disagreeing copies, and an id reaches the module raw so `intval` cannot pick a different, real account. | §4w |
| 22 | Turning off a Display, suspending an account, or renaming a tag each left the edit lock behind, and never re-checked whether the holder may still sign in. | Free the lock when reach changes, never honour a lock whose holder cannot sign in, and tell the person. A rename tells them but keeps their lock. | **Done** | §4t |
| 23 | Choosing "Image" for a background when no image is stored leaves a colour where a filename goes — the sign goes near-black. | Refuse the change. | **Done** — closed with #24: it is the same `keep-image` arm. Not in the batch asked for; see §4v. | §4v |
| 24 | The background address was validated in the admin panel but not in the API, so a publish could point every screen at any host. | Validate it in the module. | **Done** | §4v |
| 25 | The public feed served blocks an admin had deliberately hidden, content and all. | Leave them out. | **Done** | §4v |
| 26 | A reply that failed to encode sent back a zero-length success, and the sign kept its old layout forever with no notice. | Send a real error, and let the sign notice. | **Done** — with one refinement stated rather than assumed: malformed UTF-8 is *repaired and reported* rather than refused, because refusing takes a whole sign dark over one character **and** makes the fault unfixable through the app (the Builder would refuse to load the text that needs editing). Everything json_encode cannot be talked into is still a real 500. The sign notices after ten failed polls — not one, or a Wi-Fi roam blanks a working price board. | §4z |
| 27 | `?display[]=x` became the tag "array" and printed a warning above the document. | Treat it as no sign named. | **Done** — and the printing half had already stopped at §4m, so what was left was the wrong answer: `array` is a valid tag, so a Screen was told "Display not found" when nothing had been named, and a Display genuinely tagged `array` was rendered. | §4y |
| 28 | Missing, unknown and switched-off signs all answered "200 OK", and nothing anywhere set caching rules. | Real error codes, and stop caching. | **Done** — 400 / 404 / **503**, derived from the payload's own `reason` so a code and a reason cannot disagree, and `no-store` everywhere including the pages behind the sign-in. The two halves turned out to be load-bearing on each other: a 404 is heuristically cacheable where the unlabelled 200 it replaced was not, so the codes without the caching would have made a mistyped tag stickier than before. | §4z |
| 29 | Publish accepted any block type, so a basic account could insert top-level content. | Accept only known types and refuse the rest. | **Done** | §4v |
| 30 | Wrong-shaped and absurd values were coerced and written rather than refused. | Refuse the publish. | **Done** | §4v |
| 31 | Two blocks sharing a temporary id silently reparented one of them into the wrong section. | Refuse the publish. | **Done** | §4v |
| 32 | Line height was stored with a thousands separator, so some values could not be read back. *(First framed as a prices problem. It never touched prices — no sign has ever shown a stray comma.)* | Clamp it to 0.5–5 and store it plain. | **Done** | §4v |
| 33 | An account with no signs assigned could still write the shared asset library and upload files. | Nothing until it has a sign. | Open | — |
| 34 | A file bigger than the server's real limit was reported as a security problem. | Detect it and say so plainly. | **Done** | §4n |
| 35 | A publish that collided with another died as a PHP timeout before it could reach its own clean message. | Give up on the collision sooner, and report it properly. | **Done** | §4v |
| 36 | The branding file was written in place with no locking, so a short write took the whole app down. | Write a temporary file, then swap it in. | Open | — |
| 37 | The asset editor read the file type from a hidden form field instead of the stored record. | Read it from the record. | Open | — |
| 38 | Two login problems: a secure-cookie setting causes an invisible sign-in loop on plain HTTP, and the suspended-account message tells a guesser the password was right. | Fix both. | Open | — |
| 39 | Double-clicking Publish produced a success message and a stale-sign warning together. | Ignore the second while the first is still running. | Open | — |
| 40 | A basic account with the sign open read-only threw an error on every canvas click. | Guard the lookup. | Open | — |
| 41 | An unreadable stored colour round-tripped through the colour picker and published back as black. | Validate on the way in and on the way out. | **Done** — taken with #21. Out: the Builder keeps a stored colour it cannot read instead of publishing `#000000` over it, and says so in the inspector. In: the publish path refuses one and names the block. | §4w |
| 42 | Six smaller Builder rough edges: section minimum size measured in screen pixels, Fit cannot fit a very large canvas, no way to unhide a section, deleting a slide field cannot be undone, marquee "Transparent" loses the colour, and dead code. | All six. | Open | — |
| 43 | Deleting an account wrote to three tables with no transaction, going around the owning module. | All-or-nothing, through the module. | **Done** — settled by #20: closing is one transaction in `AccountAdmin`, and no `DELETE FROM users` exists anywhere. | §4l |
| 44 | Nothing set a timezone, so "editing since 2:15pm" followed whatever the host's `php.ini` happened to say. | A store timezone setting on the Branding page. | Open | — |
| 45 | The sign itself printed "Carousel — no slides added yet" where a customer could read it. | Draw nothing. | Open | — |
| 46 | Deployment step 3 had no do-not-overwrite list, so re-uploading reverted live branding and restored `setup.php`. | Write down what to skip. | Open | — |
| 48 | The test database differs from MySQL in twelve ways, including row locking stubbed out entirely. | Test against real MySQL as well. | **Done** | §4u |
| 49 | `plain_text.php` had 20% mutation coverage and `schema.php` had none at all. | Cover both. | Part done — `schema.php`'s *decision* is a pure function with 43 checks (§4o). Its *statements* now run for real on the MySQL leg (#48), which leaves the mutation coverage on `plain_text.php` as the open half. | §4o, §4u |
| 50 | About 29 checks in the suite could not fail, and five invariants had no automated check at all. | Replace the hollow ones, and cover the missing rules. | Open — the harness itself was hardened so a suite that stops early now fails, but the 29 have not been swept. | — |
| 51 | CI pins PHP 8.2 against a 7.1 target, and runs neither the consistency greps nor the rehearsal. | Match the live version, and run everything. | **Done** — and the version half was a false alarm: **the live server runs 8.2**, so the pin was correct and the 7.1 target was a guess. The repo's syntax rule now says 8.2 too, in all four places it is written down; the 7.1-era fallbacks in `auth.php` and `.htaccess` stay, because they cover a host that moves rather than a version that was guessed. CI now also runs the greps (`tools/check_invariants.php`), the rehearsal, the two node suites, and the MySQL self-test. | §4u, §4k |

## Where this stands

**38 done, 1 part done (#49), 12 open.**

#48 and #51 were taken together because they are the same subject — what the tests
run against, and whether anybody runs them. Both are Done; the version question
inside #51 was answered rather than worked around, and the answer was that the
premise was wrong.

**#24, #25, #29, #30, #31, #32 and #35** were then taken as one batch, for the same
reason: they are all one subject, which is that the publish path coerced every value
it was given and refused none of them. They are written up together in §4v.

**#23 was not in that batch and is Done anyway.** Its fix is literally the same arm
of the same `switch` as #24's — choosing "Image" with nothing stored, and a poisoned
colour being promoted to an image path, are the same two lines. Closing one and
leaving the other would have meant knowingly shipping a near-black sign in code that
had just been rewritten. Recorded here rather than folded in quietly.

Two things #30 deliberately did **not** cover, so the items that own them stayed
clean: colour *semantics* on the publish path (#41), and `DisplayAdmin::cleanColor()`
still coercing an unreadable colour to `#1a1a2e` (#21). Both were named in §4v and
both are now closed together in **§4w** — they are one defect from two ends, which is
that the app held four disagreeing opinions about what a colour is and every one of
them substituted a value rather than refusing.

**#19 completes the edit-lock set begun in #17 and #22.** Those two made every change
of reach free the lock it stranded and tell the person holding it. Deletion could not
join them — afterwards there is no row to free a lock on and nobody to tell — so it is
the one that refuses in advance instead. §4x also names one thing it deliberately did
not fix: `normalizeTag()` still raises a warning on an array, which is **#27**'s half
of the same function — and **#27 then closed it**, so that note in §4x is struck
through rather than left standing.

**#27 is the second item whose premise had expired.** Its "printed a warning above
the document" half stopped being true at §4m, which turned `display_errors` off and
sends warnings to a log. What was left was worse than the wording suggested: the cast
produced the tag `array`, which is valid, so a Screen was told "Display not found"
when nothing had been named — and a Display genuinely tagged `array` was rendered by
`?display[]=x`. #51 was the first item like this. Both were answered rather than
worked around, and in both cases the answer changed what the work was.

**#26 and #28 were taken together because they are one absence.** Nothing in the app
owned what an HTTP reply looks like — not the status line, not the caching rules, not
the bytes of the body — so a payload that would not encode left as a zero-length 200
and a sign that did not exist left as a 200 too. `lib/http_reply.php` owns all three
now, and taking them apart would have been the wrong economy: the caching half of #28
is what stops the status-code half from making things *worse*, because a 404 is
cacheable by default where the unlabelled 200 it replaces is not.

Two things came out of the pair that were not in either item. The same unchecked
encode was in **nine** places printing values into a page's own `<script>`, where a
failure is a parse error that takes the whole block down — a blank television, or a
Builder whose controls do nothing — and eight of the nine were passing XSS-escaping
flags by hand that the ninth, viewer.php, was not passing at all. And #26's "let the
sign notice" needed a suite that *runs* viewer.php's JavaScript rather than parsing
it, which is `tools/selftest_viewer.js` and is now part of the standing gate. It
exists to hold a judgement rather than a rule: how many failed polls a sign may show
prices through. One is too few and never is too many, and neither end of that is
obvious enough to leave to memory.

The order has been the owner's call throughout, one item at a time. There is no
suggested order in this file on purpose — anything left is worth doing, and which
one comes next is a judgement about the store, not about the code.
