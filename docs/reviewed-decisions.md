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
| 15 | A username containing HTML reached a confirm box unescaped, and 133 escaping calls carried no flags. | Escape every stored value strictly, app-wide. | **Done** — 159 of them by the time they were counted, and the flags were the smaller half. `htmlspecialchars()`'s default changed in PHP 8.1, so the same source escaped single quotes on one host and not on another, and blanked a whole value on one byte of bad UTF-8; `lib/markup.php` names both flags once and `check_invariants.php` holds the function to that file. The confirm box was a different bug in the same clothes: it *was* escaped, for HTML, inside a JavaScript string — which the HTML parser decodes first, handing the quote back. `Markup::jsInAttr()` is the fix, and an invariant catches the construction rather than the instance. A third pass then closed "app-wide": a classifier holds every echo on every page to one of five shapes safe by construction — a door, a literal, a safe call, a validated colour, or a class constant whose declaration is a number — with no allow-list, fifteen echoes converted to reach it, and twenty injections either side of the line. The same sweep found two families where escaping is the wrong tool entirely — the store's brand colours going into a `<style>` block, and six Brand Standards fields going into a `style` attribute, where escaping stops a value ending the attribute but not the declaration inside it. Both are validated on the way out now (`Brand::`, `BrandStyles::readable()`) and both say which stored value they could not use. | §4ah, §4ai |
| 16 | The permissions grid read a column that wasn't on the page as "take that access away", and F5 replayed the whole save. | Save only what the form covered, then redirect. | **Done** | §4s |
| 17 | Taking access away left the edit lock stranded on the person who lost it, and told them nothing. | Release it, and tell them. | **Done** | §4s |
| 18 | Promoting somebody to admin left individual assignments nothing could see and nothing could remove. | Clear them on promotion. | **Done** | §4s |
| 19 | Deleting a Display never asked whether anyone was editing it, and the confirm undercounted what a mid-edit clerk loses. | Refuse while somebody else is editing. | **Done** — the refusal is asked twice, before the typed tag and again inside the transaction on a row the module reads itself. The confirm now names the accounts that lose access and, when somebody has it open, who and since when. | §4ad |
| 20 | Deleting an account freed its number for the next person, leaving "last published by" naming a stranger. | Keep the username as text, and never reuse an account number — close accounts instead of deleting them. | **Done** | §4l |
| 21 | The admin panel coerced values it could not parse and reported success — an unreadable colour, an account id that isn't one. | Refuse, and say so. | **Done** — taken with #41; they are one defect from two ends. What a colour is now lives in one pure module instead of four disagreeing copies, and an id reaches the module raw so `intval` cannot pick a different, real account. | §4ac |
| 22 | Turning off a Display, suspending an account, or renaming a tag each left the edit lock behind, and never re-checked whether the holder may still sign in. | Free the lock when reach changes, never honour a lock whose holder cannot sign in, and tell the person. A rename tells them but keeps their lock. | **Done** | §4t |
| 23 | Choosing "Image" for a background when no image is stored leaves a colour where a filename goes — the sign goes near-black. | Refuse the change. | **Done** — closed with #24: it is the same `keep-image` arm. Not in the batch asked for; see §4ab. | §4ab |
| 24 | The background address was validated in the admin panel but not in the API, so a publish could point every screen at any host. *(The "any host" half was aimed at the image branch, which the API cannot reach — an image background is a validated upload under a name the server chose. What a publish could really do is write any string into the colour column four readers assume is six hex digits, which ends in a sign keeping the colour it had and a picker publishing black. §4x has the detail.)* | Validate it in the module. | **Done** | §4x, §4ab |
| 25 | The public feed served blocks an admin had deliberately hidden, content and all. | Leave them out. | **Done** | §4ab |
| 26 | A reply that failed to encode sent back a zero-length success, and the sign kept its old layout forever with no notice. | Send a real error, and let the sign notice. | **Done** — with one refinement stated rather than assumed: malformed UTF-8 is *repaired and reported* rather than refused, because refusing takes a whole sign dark over one character **and** makes the fault unfixable through the app (the Builder would refuse to load the text that needs editing). Everything json_encode cannot be talked into is still a real 500. The sign notices after ten failed polls — not one, or a Wi-Fi roam blanks a working price board. | §4af |
| 27 | `?display[]=x` became the tag "array" and printed a warning above the document. | Treat it as no sign named. | **Done** — and the printing half had already stopped at §4m, so what was left was the wrong answer: `array` is a valid tag, so a Screen was told "Display not found" when nothing had been named, and a Display genuinely tagged `array` was rendered. | §4ae |
| 28 | Missing, unknown and switched-off signs all answered "200 OK", and nothing anywhere set caching rules. | Real error codes, and stop caching. | **Done** — 400 / 404 / **503**, derived from the payload's own `reason` so a code and a reason cannot disagree, and `no-store` everywhere including the pages behind the sign-in. The two halves turned out to be load-bearing on each other: a 404 is heuristically cacheable where the unlabelled 200 it replaced was not, so the codes without the caching would have made a mistyped tag stickier than before. | §4af |
| 29 | Publish accepted any block type, so a basic account could insert top-level content. | Accept only known types and refuse the rest. | **Done** | §4ab |
| 30 | Wrong-shaped and absurd values were coerced and written rather than refused. | Refuse the publish. | **Done** | §4ab |
| 31 | Two blocks sharing a temporary id silently reparented one of them into the wrong section. | Refuse the publish. | **Done** | §4ab |
| 32 | Line height was stored with a thousands separator, so some values could not be read back. *(First framed as a prices problem. It never touched prices — no sign has ever shown a stray comma.)* | Clamp it to 0.5–5 and store it plain. | **Done** | §4ab |
| 33 | An account with no signs assigned could still write the shared asset library and upload files. | Nothing until it has a sign. | **Done** — one predicate (`Actor::holdsASign()`) and one sentence, on the two doors that no Display scopes: the Library's add form and the API's image upload. Both refuse before `move_uploaded_file()`, which cannot be undone. It is the **grant** axis and not `openable()`, so a sign switched off for the afternoon does not quietly take its clerk's library access away on another page — and the refusal's wording stays true of everybody it refuses. Reads are deliberately untouched: the library still lists, because staff get asked to look things up and a page that will not say what is in it cannot explain what it refused. Found next door and fixed with it: the edit form was drawn for anybody who typed `?edit_id=`, which put the new notice one query parameter away from an editor. | §4ao |
| 34 | A file bigger than the server's real limit was reported as a security problem. | Detect it and say so plainly. | **Done** | §4n |
| 35 | A publish that collided with another died as a PHP timeout before it could reach its own clean message. | Give up on the collision sooner, and report it properly. | **Done** | §4ab |
| 36 | The branding file was written in place with no locking, so a short write took the whole app down. | Write a temporary file, then swap it in. | **Done** | §4y |
| 37 | The asset editor read the file type from a hidden form field instead of the stored record. | Read it from the record. | **Done** | §4w |
| 38 | Two login problems: a secure-cookie setting causes an invisible sign-in loop on plain HTTP, and the suspended-account message tells a guesser the password was right. | Fix both. | **Done** | §4u, §4v |
| 39 | Double-clicking Publish produced a success message and a stale-sign warning together. | Ignore the second while the first is still running. | **Done** | §4ak |
| 40 | A basic account with the sign open read-only threw an error on every canvas click. | Guard the lookup. | **Done** — the click-path lookup was settled by #3; the same banner's second lookup, the one at page load, was guarded to match. | §4j |
| 41 | An unreadable stored colour round-tripped through the colour picker and published back as black. | Validate on the way in and on the way out. | **Done** — taken with #21. Out: the Builder keeps a stored colour it cannot read instead of publishing `#000000` over it, and says so in the inspector. In: the publish path refuses one and names the block. **Since:** refusing at the door made the rows already stored worse to hold — one of them makes its Display refuse every publish — so `tools/audit_colors.php` finds them, read-only, against the live database. It also turned up a third case with no refusal in front of it at all: a hand-edited `block_styles` colour renders on every sign, because BrandStyles cleans on the way in and not on the way out. | §4ac |
| 42 | Six smaller Builder rough edges: section minimum size measured in screen pixels, Fit cannot fit a very large canvas, no way to unhide a section, deleting a slide field cannot be undone, marquee "Transparent" loses the colour, and dead code. | All six. | **Done** | §4al |
| 43 | Deleting an account wrote to three tables with no transaction, going around the owning module. | All-or-nothing, through the module. | **Done** — settled by #20: closing is one transaction in `AccountAdmin`, and no `DELETE FROM users` exists anywhere. | §4l |
| 44 | Nothing set a timezone, so "editing since 2:15pm" followed whatever the host's `php.ini` happened to say. | A store timezone setting on the Branding page. | **Done** — the setting is a tenth `branding_config.php` entry, on the Settings tab rather than beside the four colour pickers, and it takes a zone *name*: `+08:00` and `PST` both build a valid `DateTimeZone` and are both wrong for half the year, which is this same defect with a smaller error bar. There were **three** clocks, not one — PHP's process zone, which the host sets to `America/Chicago`; MySQL's session zone, which `CURRENT_TIMESTAMP` used and nothing had ever set, so the same machine's Central; and the store's, nowhere in the repo. **Fixing one of them is not a partial fix, it is a new bug:** the first two agreeing is what made the missing `' UTC'` in `lastPublishDescription()` cancel out exactly, so setting either clock alone turns a uniform two-hour error into a five-hour one in the sentence a refused publish prints. That missing suffix is the defect nobody had asked about — the rule "a stored moment is UTC" was written out three times and the third copy left it off, latent, in the one sentence that names whose work is about to be walked over. Reading a stamp is one place now (invariant 28). **Corrected after the fact:** the write-up first said the host set nothing and therefore ran on UTC, making the error seven hours. It was two. That was an assertion about a machine nobody had looked at, which is #51's lesson recurring in a different item's write-up; the host was read on 2026-08-11 and §4ap records it with its provenance. | §4ap |
| 45 | The sign itself printed "Carousel — no slides added yet" where a customer could read it. | Draw nothing. | **Done** — and it was two blocks, not one: `renderTable()` printed "Table — no data" over a grey panel drawn to hold it, and both are closed. Drawing nothing loses no warning, because the Builder already labels the same blocks `↻ Carousel — 0 slides` and `⋞ Table — 0 cols, 0 rows` on its own canvas — the surface the author is actually looking at. A second pass then took the two cases that are the same defect in colour rather than in English: a marquee with no text painted a solid `#c0392b` bar and scrolled an empty span along it, and a carousel slide with no image filled its image well with `#1a1a2e`. A third took the last two, where the ink was the browser's rather than the page's — an **image** with no file is a *broken* image, not an absent one, and an empty `<video>` is a rectangle whose colour the browser picks. All five block types draw nothing now. The Builder gained a `'Video'` placeholder in the same pass, because that block had nothing on either surface. | §4ag |
| 46 | Deployment step 3 had no do-not-overwrite list, so re-uploading reverted live branding and restored `setup.php`. | Write down what to skip. | **Done** | §4z |
| 48 | The test database differs from MySQL in twelve ways, including row locking stubbed out entirely. | Test against real MySQL as well. | **Done** | §4aa |
| 49 | `plain_text.php` had 20% mutation coverage and `schema.php` had none at all. | Cover both. | **Done** — measured rather than asserted, which was the whole of the item. `plain_text.php` went from 2 of 17 mutations killed to **17 of 17**; `schema.php` from 43 of 67 to **65 of 67**, the two survivors being equivalent mutants named in §4am. Most of what lived was in the four convergence *steps*, the only part that touches rows rather than structure: either backfill's `WHERE` clause could be deleted in silence, and one of those hands every sign's layout to the drive-thru Display. Writing the checks also turned up a live bug, and once it was somebody's to decide it was fixed: `strip_tags()` deleted from a typed `<` to the end of a value, so `Kids <12 eat free` was stored as `Kids` (§4am). `schema.php`'s MySQL-only statements are covered by **#48's** second leg rather than by a mutant of their own. | §4o, §4aa, §4am |
| 50 | About 29 checks in the suite could not fail, and five invariants had no automated check at all. | Replace the hollow ones, and cover the missing rules. | **Done**, and the first half deliberately not as written. The 29 was a hand count from a two-hundred-check suite that is 1778 checks now, so a recount by hand would have been stale by the next merge — what shipped is `tools/mutate.php`, which breaks one line of a file at a time and reports whether any check notices, plus **invariant 30**: a check ships having been *seen* to fail. Nine modules swept so far, of twenty-six. `lib/grants.php` — the module answering "may this account reach that sign" — had ten lines nothing stood over, including a session with no id falling back to a number one digit away from the admin's, and both axes of the grant matrix returning whatever they liked; `Color::describe()`, the sentence a refused colour shows, had ten of thirteen mutants survive. One genuinely hollow check was found and corrected rather than deleted: §4ap's "an absent setting is not something to report" ran in a process where the setting was present, because a `define()` cannot be undone — `inFreshProcess()` is the instrument that reaches the other branch. The five by-eye invariants: four mechanised, the fifth halved (a new `ErrorPolicy::report` caller can no longer land unnoticed; whether it can repeat is still a reading). Also fixed the thing #44 left here — the checker now drops HTML comments, and an HTML comment holding PHP is code and stays. | §4aq, §4am |
| 51 | CI pins PHP 8.2 against a 7.1 target, and runs neither the consistency greps nor the rehearsal. | Match the live version, and run everything. | **Done** — both halves, the version one on the third attempt. *Running:* CI now runs the greps (`tools/check_invariants.php`), the rehearsal, all six node suites and the MySQL self-test, so three of §5's four pre-push steps no longer depend on somebody remembering. *Version:* **the store owner stated it — PHP 8.2, 2026-08-10** — and the floor is 8.2 on that basis, so CI's pin enforces the target instead of accepting everything the target forbids. Twice before, this was recorded as closed on evidence that could not exist: a branch cited Settings → This Server, which ships with the undeployed build (#46's probe found `lib/` answering 404), and Cloudflare hides the version from every header. The difference then was a source — a person, dated — rather than a screen that does not run. **It is now observed twice, and the owner's word was right** (2026-08-11, HANDOFF §7): the runtime reports 8.2.33, and cPanel → MultiPHP Manager shows `srcresort.com` pinned explicitly to `ea-php82` against a system default of 8.3. Two independent observations, one runtime and one configuration, so the deploy-day confirmation step is discharged. The explicit pin also means the floor cannot drift upward by itself, and the only route below it is a person choosing an older version by hand — which is what `ServerReport::phpVersionNote()` announces. What is *not* closed by that: the container these sessions run in has PHP 8.4, so `php -l` cannot detect above-floor syntax, and no file uses any today only because it was checked by hand. Note the risk changed direction: guessing low only forwent syntax, where a declared floor that is wrong is a parse error, and that is a blank sign rather than a message. | §4aa, §4k |

The **`<` bug** (§4am) has no number either, because the audit did not find it —
covering #49 did. A text block reading `Kids <12 eat free` was stored as `Kids`,
because `strip_tags()` deletes from a `<` to the end of a value when nothing closes
it. It is fixed, in `toPlainText()` and in the two places that were calling
`strip_tags()` themselves. Worth knowing about because it is the shape several open
items are also about: a value quietly coerced on the way into the database, with the
page reporting success.

## Work outside this list

The Builder's **Undo** (ADR-0010, §4an) was asked for directly rather than found by
the audit, so it has no number here and the tally below is unchanged by it. It is
worth knowing about while reading the rest of this file: several entries are written
on the premise that *nothing anywhere can be taken back*, and that is now true only
of things that have been published. Nothing on this list was closed by it and nothing
on it was reopened: what changed is the premise, not any item's status.

## Where this stands

**50 done, 0 open** — counted off the Status column above, which
is 50 rows because there is no #47. The 51st item is the unnumbered policy named at
the top; it has no row and therefore no status, and two branches counting the same
table have each quietly folded it into a different total. It is counted here as
neither. The count is recounted from the table on every merge rather than carried
across from either side: when #33 and #44 merged, both totals were right about their
own half and wrong about the whole.

**The list is closed, which is a smaller claim than it sounds and worth stating in the
smaller form.** Every numbered item from the adversarial audit has been answered. What
that does *not* mean:

- **Nothing on this list was ever the browser.** `docs/work-lanes.md` lane 0 is still
  first and still cannot be done from here: four commits of `builder.php` have never been
  rendered by one, `interact.js` is un-run by any suite (§4al), and #44 added two things
  only a live page can confirm. A list of closed audit items is not a walked application.
- **#50 closed by becoming a rule.** Its first half asked for a hand count to be swept,
  and what shipped is the instrument that makes the question answerable per file, plus
  invariant 30. Six of twenty-six `lib/` modules have been swept; the other twenty are a
  command each, and running that command is now part of writing a check rather than a
  task somebody finishes. Reading this line as "coverage is done" is the exact mistake
  #50 was filed about.
- **The two-numbering traps and the merge rules above still apply**, because the next
  change to this app is not an audit item and will still touch the three files every
  branch touches.

**The #33/#44 merge is why that last sentence is now a rule and not just good practice.**
Both branches were cut from a base with three items open and each closed one, so each
correctly wrote `48 done, 2 open` — and an identical change on both sides of a merge is
not a conflict, it is agreement. Git merged the line clean, and nothing in the standing
gates reads it. A wrong total here does not announce itself the way a wrong invariant
number does, so recount from the table on **every** merge, whether or not this file
conflicted. `docs/work-lanes.md` item 4 holds the one-liner.

#48 and #51 were taken together because they are the same subject — what the tests run
against, and whether anybody runs them. Both are Done, and **#51's version half is the
one item in this list that was answered wrongly twice before it was answered.** Worth
keeping in view, because each attempt failed differently:

1. A branch recorded 8.2 citing Settings → This Server — a screen that ships with the
   undeployed build and therefore cannot have reported anything. Withdrawn.
2. The withdrawal itself was incomplete for one merge: the four top-level docs were put
   back while `lib/server_report.php`, `auth.php`, `lib/markup.php`,
   `lib/layout_store.php`, `admin_panel.php` and §5 still stated 8.2 as fact — so the
   repo asserted both floors at once, and `ASSUMED_PHP` was the one an admin would have
   read off a screen. Fixed in one pass.
3. The store owner stated it: **8.2, 2026-08-10.** A source, dated. The floor is 8.2 on
   that basis, and the docs now say where the fact came from rather than asserting it
   bare — which is the whole difference between this attempt and the first.
4. **It was then observed, twice, and the owner was right** (2026-08-11): 8.2.33 on the
   runtime card, and `ea-php82` pinned explicitly to `srcresort.com` in cPanel's MultiPHP
   Manager. HANDOFF §7 holds both.

The lesson is not "check harder". It is that a fact with no recorded provenance reads
exactly like a fact with a bad one, and the only defence is writing down who said it and
when. Step 4 is what makes that concrete rather than a moral: **attempt 1 had the right
answer.** It cited a screen that could not have shown it, and the fix was to withdraw the
number — correctly, because an unsupported right answer is indistinguishable from a wrong
one, and the next person cannot tell which they inherited. A year later that same screen
was deployed and said 8.2.33. Provenance is not a tax on being right; it is the only thing
that tells being right from being lucky.

One thing step 4 does **not** close. The container these sessions run in has PHP 8.4, so
`php -l` cannot see a construct that is fine there and a parse error at 8.2 — and a parse
error in a file a Screen loads is a blank sign, not a message. No file uses one today,
checked by hand on 2026-08-11 rather than by any gate. That is a hole in §5, not an item
on this list, and it is the shape #50 was filed about: a check that cannot fail.

**#24, #25, #29, #30, #31, #32 and #35** were then taken as one batch, for the same
reason: they are all one subject, which is that the publish path coerced every value
it was given and refused none of them. They are written up together in §4ab.

**#23 was not in that batch and is Done anyway.** Its fix is literally the same arm
of the same `switch` as #24's — choosing "Image" with nothing stored, and a poisoned
colour being promoted to an image path, are the same two lines. Closing one and
leaving the other would have meant knowingly shipping a near-black sign in code that
had just been rewritten. Recorded here rather than folded in quietly.

Two things #30 deliberately did **not** cover, so the items that own them stayed
clean: colour *semantics* on the publish path (#41), and `DisplayAdmin::cleanColor()`
still coercing an unreadable colour to `#1a1a2e` (#21). Both were named in §4ab and
both are now closed together in **§4ac** — they are one defect from two ends, which is
that the app held four disagreeing opinions about what a colour is and every one of
them substituted a value rather than refusing.

**#19 completes the edit-lock set begun in #17 and #22.** Those two made every change
of reach free the lock it stranded and tell the person holding it. Deletion could not
join them — afterwards there is no row to free a lock on and nobody to tell — so it is
the one that refuses in advance instead. §4ad also names one thing it deliberately did
not fix: `normalizeTag()` still raises a warning on an array, which is **#27**'s half
of the same function — and **#27 then closed it**, so that note in §4ad is struck
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

**#45 was the first item that suite paid for.** The Viewer's renderers had never been
run by anything, so the placeholder it names had survived every gate the repo has —
it parses, and parsing is all `php -l` and `node --check` can ask. The item names the
carousel; the identical construction in `renderTable()` is closed with it.

The first pass stopped there and named three blocks that still drew something,
because none of them was a sentence and this app has no undo. The owner took two of
the three: a **marquee** with no text painted a solid red bar and scrolled an empty
span along it, and a **carousel slide** with no image filled its image well with
navy. Both are closed in a second pass under the same number (§4ag) — the marquee's
own `if (!text) return;` was already there, four lines below the paint. The suite
went 75 → 129, and the injection that matters most is the one that *passed*: making
an image stop counting as something a slide shows broke nothing, which would have
taken every photograph off every sign in the store. That gap is covered now.

A third pass then took the last two, on the same instruction. An **image** with no
file and a **video** with no source were held back because the ink is the browser's
rather than the page's — which turned out to be the argument for closing them, not
against: `src=''` is a *broken* image by definition, and what a broken image or an
empty `<video>` looks like differs by browser. A sign must not look different because
of which browser the television shipped with. Both branches moved out of the render
loop into `renderImage()` and `renderVideo()`, and the Builder gained a `'Video'`
placeholder, because that block had nothing to show on either surface. 129 → 169.

**#45 is closed.** Five block types, six drawings, all of them nothing now.

**#15 was two items sharing a sentence.** The flags half is a rule with 159 copies and
no opinion — `htmlspecialchars()`'s default changed in PHP 8.1, so the same source
behaved differently on different hosts, and one byte of bad UTF-8 blanked a whole
value. That is `lib/markup.php`, held to one file by the invariants. The other half —
the confirm box the item names — was still a live hole under the *strict* default,
because the value was escaped for HTML and then used as JavaScript, and the HTML
parser undoes the escaping before the JavaScript parser reads it. Two different
mistakes, one line of source. What #15 does **not** close is stated in §4ah: every
escaped value is now strict, which is not the same as every value being escaped.

The order has been the owner's call throughout, one item at a time. There is no
suggested order in this file on purpose — anything left is worth doing, and which
one comes next is a judgement about the store, not about the code.

## Branches closed without merging

Several branches were cut from the same base and solved overlapping items in parallel.
Where two of them solved the same thing, one implementation was picked rather than
both merged — merging two answers to one question is how a codebase ends up holding
four opinions about what a colour is, which is #21. What was **not** on the branch that
won is recorded here, because a closed branch is not a decision anybody can re-read.

The tip commit is given for each so the work is recoverable: `git show <sha>`, or
`git log <sha>` for the whole branch. Each is also reachable as `origin/retired/<name>`,
which is the ref that is meant to outlive the `claude/` one — deleting the last ref to
a commit leaves it danglable, so a bare sha in a document is a promise that expires.

**The `claude/` branches are still there.** Retiring them was meant to be a rename, and
only the first half of it could be done from the session that wrote this: these
credentials may create `refs/heads/*` but not delete a ref, and not create
`refs/tags/*` at all. So each of the three with a twin currently exists twice, which is
worse than either intended state. The delete has since been attempted from a later
session and refused the same way — `HTTP 403`, nothing removed — so this is a standing
limit of the automation rather than one session's bad luck. It needs a terminal with the
owner's own credentials, or the branch list in the GitHub web UI, which offers a Restore
button afterwards.

**Don't work from a list of names here; ask git.** The list this section carried for its
first two revisions named three branches, and by the time anybody read it there were
sixteen `claude/*` refs. A written list of branches goes stale the week it is written,
and the stale version reads exactly like the current one. The mechanical question is
whether a ref adds anything to `main`:

```
for b in $(git for-each-ref --format='%(refname:short)' refs/remotes/origin \
           | grep -v 'origin/main$'); do
  git merge-base --is-ancestor "$b" origin/main \
    && echo "MERGED   $b" \
    || echo "ahead by $(git rev-list --count origin/main..$b)   $b"
done
```

Anything it prints `MERGED` for is an ancestor of `main` — its every commit is already
reachable, and deleting the ref removes nothing. **Nine of the sixteen were in that
state** when this was written, which is why counting branches had made the cleanup look
larger than it was. What is left over needs a reason, and every one of them has a row
below or in the section after it.

A tag would say "not a line of development" more clearly than a branch does. Convert
the `retired/*` refs if you have the rights; the shas in the table are what they point
at either way.

| Branch | Tip | Why it was closed |
|--------|-----|-------------------|
| `claude/issue-28-l1comq` | `dd1a099` | #26/#28/#27/#45, all landed via the sweep. It split the reply into `lib/http_cache.php` beside the status code; `lib/http_reply.php` owns all three of status, caching and body instead, for the reason stated under #26 above — the caching half is what stops the status-code half making a mistyped tag *stickier*, so the two do not belong in separate modules. |
| `claude/open-issues-count-hub0pv` | `4214f2b` | #29–#32 in four commits where the sweep did it in one. Read closely because of that; see below. The sweep's `lib/layout_rules.php` was kept — it is a pure module, so every shape can be put through it in a test, where this branch's rules are private statics inside `LayoutStore` reachable only by attempting a publish (§4o's reason). It also reports every problem in a payload rather than the first. |
| `claude/start-38-ysumzb` | `4822e11` | #38, both halves — a competing implementation of what `claude/issue-38-7uky0k` landed. Same two defects found, same conclusions, different modules: `LoginGate` where `main` has `LoginAttempt`, and its own `RequestScheme`, ADR-0008 and ADR-0009. `main`'s line is the pick because the work built on top of it is not on this branch — the soft CSRF check, the removal of the three `ALTER`s a bot could reach without an account, and roughly six hundred more self-test checks. Its ADR-0008 is `main`'s ADR-0008 under a different title, which is worth knowing: three branches have now claimed that number. See below for the one thing it found that `main` did not. |
| `claude/app-db-domain-testing-h0ulyg` | `1526023` | **It was right about the floor, and the floor it argued for is now the rule.** Recorded here as closed for raising it to 8.2 on a decision rather than a measurement — its own message says so plainly — and that reasoning was rejected while the version was unverified. The owner then supplied the version and the same conclusion followed, so the disagreement was about evidence, not about the answer. Still closed rather than merged: what it did *around* the floor is not wanted. It uses `session_set_cookie_params()`'s array form alone, which below 7.3 sets nothing at all and silently drops `HttpOnly`, `Secure` and `SameSite` — `auth.php` guards that call by version instead — and it deletes `.htaccess`'s `mod_php7` hardening blocks, which is a separate decision that was not being made. Its `phpVersionNote()` design, "make a wrong floor say so", is the one on `main`. It is also the branch whose reading surfaced the both-floors-at-once contradiction. |
| `claude/project-handoff-review-wd557c` | `bf26346` | Nothing to close and nothing to port: its one contribution is vendoring the 22 `mattpocock/skills` entries into `.claude/skills/`, and that is already on `main` byte for byte — `git diff origin/main bf26346 -- .claude/` is empty. Listed because it was on a cleanup list for weeks as a branch nobody had read, which is the same cost as a branch holding something. The commit touches no application file. |
| `claude/remaining-issues-list-hsk3rv` | `151b2c9` | **Superseded by `docs/work-lanes.md`, and the successor disagrees with it on the one thing that matters.** A single docs-only commit, 2026-08-07, adding a seven-lane parallel map to this file. Its lane table is over items now closed and its tally (22 done, 27 open) was overtaken inside a week, so nothing in it is portable — which is itself worth noting, because it is a document that went stale in four days. Worth knowing *why* it was replaced rather than extended: it grouped by which files an item touches and concluded five of seven lanes shared no file at all. That is true of the application files, and the application files are not what decides it. `work-lanes.md` reaches the opposite conclusion about #50 for exactly that reason: #50's deliverable *is* `tools/selftest_layout.php`, the file every other lane appends to, so it is a measurement problem rather than a merge one and reads go after writes. This branch's own text saw the conflict ("every lane will also append checks… they resolve mechanically") and read it as merge friction. It is the near-miss that makes the later file's rule worth stating. |

**Three things were on those branches and were not on `main`. All three have now been
ported.** None was a competing answer to something already solved, so none was covered
by picking. That every one of the first four branches turned out to hold something is the
finding worth carrying forward: "its items are already closed" was true each time and
was never the same question as "it has nothing `main` lacks". If a superseded branch is
ever closed again, read it first.

The two rows added later are the counter-example that keeps that rule honest rather than
superstitious: read for the same reason, both held nothing, and one of them could be
settled by `git diff` in a second. **Six read, four held something.** The rule is "read
it", not "expect to find something" — and the cheap mechanical checks come first, because
an ancestor of `main` and an empty diff against `main` are both answers no reading
improves on.

- ~~**A sign-in refusal still arrives a bcrypt early.**~~ **Ported.** ADR-0008 closed the *message*
  oracle: a suspended account is refused in the same words whether or not the password
  was right. `LoginAttempt` does that by returning before `password_verify()` — so the
  refusal comes back measurably sooner than a wrong password on a live account does, and
  the timing now says what the wording no longer does. `start-38`'s `LoginGate` takes
  the password check as a callable specifically so it can spend it for **any account
  that exists**, and says why in the docblock: not to replace a message oracle with a
  timing one. Same defect as ADR-0008 in a different channel, and nothing on the record
  decided against fixing it — so unlike the item below this is a gap rather than a
  reversal. Not the unknown-username case, which both treat as existence (ADR-0001).

- ~~**A `basic` account can still publish content at root level**~~ — **Ported, and
  written up as §4aj.** The residual §4ab named and deferred, saying it "needs a payload
  change, not a check". `open-issues-count` made that payload change (`d39f3f9`):
  content carries `db_id` the way sections always have, and a basic publish accepts a
  root block only when that id is a root row the Display has right now, refusing the
  whole publish otherwise. It handles both cases that make the naive version wrong —
  sending nothing for a root row still deletes it, and a returned root row keeps its id
  so the Builder's ids do not go stale on every publish. This one reversed a decision
  §4ab had recorded, so it was the owner's call rather than a merge resolution, and it
  was taken. Fifteen checks; three existing ones had their payloads corrected to what a
  clerk's Builder actually sends, without changing what they assert.
- ~~**The Builder's two opening reads share one failure message**~~ — **Ported.**
  `builder.php` wrapped `loadAssets()` and `loadLayout()` in a single
  `Promise.all(...).catch()` that said "Failed to load layout." for either. `issue-28`'s
  `a3ccaee` splits them, on the ground that the sentence is false half the time it
  appears: the asset library failing means an empty dropdown, while the layout failing
  means the canvas on screen is not this sign and must not be published over. There was
  never a missing `.catch()`, so no suite caught it and no grep would have; fourteen
  checks in `tools/selftest_builder_uploads.js` drive the real chains, and restoring the
  shared handler kills the section outright.

## The branch that was drained rather than closed

`claude/start-37-jlkcgn` (tip `362e235`) is the one parallel branch that was **not**
adjudicated against another implementation, because four of its nine commits were the
same work `main` already had and the other three were not on `main` at all. It has now
been walked end to end and is empty:

| Its commit | What happened |
|-----------|---------------|
| `d526c85` (#37), `deb30c1` (#40), `746a38e` (#25), `f487d3f` (#19) | Already closed on `main` by other branches. Skipped. |
| `52d8722` "Publish once, whatever the mouse does" | Already closed as §4ak. Skipped. |
| `fd4821a` "Six controls that quietly did less than they said" | Landed earlier as `f692cb6` (#42, §4al). |
| `1deeccd` (#49), `362e235` (the `<` bug) | Landed as `856d3c0` and `964a043`. §4am. |
| `b4de714` (Builder undo) | Landed as `253b74e`. ADR-0010, §4an, invariant 27. |

**Nothing is left on it**, so it can go the way of the others:

```
git push origin --delete claude/start-37-jlkcgn
```

Three things are worth carrying out of the port rather than leaving in the diff:

- **A feature branch cut before a refactor carries the pre-refactor version of
  everything it touches.** `b4de714` wrote `branding_config.php` from a nine-argument
  `writeBrandingConfig()` on the admin page, which #36 replaced while that branch was
  open (§4y). Taking the *argument* — the undo depth is a stored setting — meant putting
  it in `BrandingConfig::DEFAULTS`, where the module that owns the file can see it.
  Taking the *code* would have restored a second writer, and the symptom would have been
  the depth silently resetting whenever somebody saved the Branding form.
- **Two correct features can be wrong together.** The undo restore and the root-content
  rule (§4aj) landed a week apart from different branches, and the pair had a defect
  neither had alone: a restored block lost its database id, so a basic account's next
  publish was refused for a placement they never made. Both suites passed. It took a
  fixture block with an id to make the round trip able to see it.
- **A duplicate commit is not the same question as a duplicate branch.** Five of the
  nine were genuinely already closed, and checking that was cheap. The three that were
  not would have been lost by closing the branch on the strength of the five.
