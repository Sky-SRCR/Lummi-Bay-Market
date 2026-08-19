# The deployment visit, as a walk sheet

**This document owns none of its content.** Three do, and where any of them disagrees
with this sheet, that one is right:

| Source | What it owns |
|--------|--------------|
| [`roadmap-multi-display.md`](roadmap-multi-display.md) → *Before this reaches the sign* | The 22 numbered steps. Step numbers below are its numbers. |
| [`../HANDOFF.md`](../HANDOFF.md) §5 → *Deploy notes since the multi-display checklist* | Everything merged **after** those steps were written. The numbered list is never renumbered, so this table is where later work lands. |
| [`DEPLOY-SKIP.md`](DEPLOY-SKIP.md) | What must not go up, and the six checks that catch it afterwards. |

It exists for three reasons the prose cannot fix by being better written:

- **The 22 steps are 89 separate things that must be true.** Step 17 alone carries
  eighteen, behind three lines of setup. A step you can tick when four of its six
  assertions passed is not a check.
- **Step 20 has a fifteen-minute wait in it**, and the roadmap says it "can run in a
  spare tab while the rest is checked" without saying when to start it. Started at
  step 20 it is fifteen minutes of standing still. Phase F below starts it at step 15.
- **Step 17's three doors each need the grant put back first.** That setup line is
  not in the prose and it is the easiest thing in the visit to get wrong — the second
  door tested against a revoked grant proves nothing.

**Every sentence in `code` below is verbatim from the source it will appear from** —
grepped, not remembered. That is deliberate: five of the seven defects the browser
pass found were things a page did not *say* rather than wrong answers
([`browser-pass.md`](browser-pass.md)), so on this visit the wording **is** the check.
A page that is roughly right is a page that has not been checked.

**What this deploys:** v1 — phases 1–6 of the multi-display build, from `main`.
Roadmap v2 (Brands, Workspace Themes) is not in this visit; its own deploy row is
already in HANDOFF §5 (`v2 step 3` · §4bb) for when it is.

---

## Phase 0 — Before you go

None of this needs the server, and each one is something that turns into a wasted trip
if it is discovered at the shop.

- [ ] **0.1 — The database user's privileges.** Confirm **SELECT, INSERT, UPDATE,
  DELETE, CREATE, ALTER, INDEX, REFERENCES** on the target database. Convergence issues
  22 DDL statements, 20 of them `ALTER`. A failed schema statement is logged and
  emailed, never thrown — so **missing privileges do not announce themselves**; the page
  carries on and dies later against a column that was never created. `CREATE` without
  `ALTER` is the worst case: the Builder *loads*, and Settings → Database Structure reads
  `Nothing is scoped to a Display. Do not publish.` (HANDOFF §5, rehearsal 2026-08-12)
- [ ] **0.2 — And not these four.** `DROP`, `TRUNCATE`, `LOCK TABLES`,
  `CREATE TEMPORARY TABLES` appear nowhere in the plan and should stay unchecked. In an
  app with no undo, a privilege it never uses is only risk.
- [ ] **0.3 — The webroot directory is writable by the web user**, not just
  `branding_config.php` itself (#36 · §4y). The file is now replaced by writing a temp
  copy beside it and renaming over, so the permission that matters moved from the file
  to the folder. Symptom if wrong: Branding refuses with *"Check the folder
  permissions."* and changes nothing — safe, but branding cannot be edited at all.
- [ ] **0.4 — A second browser, and a `basic` account you are willing to delete.**
  Steps 15–21 cannot be done with one browser: one session per browser, so it is two
  browsers or one browser plus a private window.
- [ ] **0.5 — Three test Displays' worth of patience.** Phase E creates *Test
  Portrait* (1080×1920) and a 1920×1080 copy of the drive-thru; Phase F wants a third
  for the idle timer so the wait does not block the grant work. All three are deleted
  in step 22.
- [ ] **0.6 — Does anything back up `uploads/`?** Unverified, and step 1 backs up the
  *database only*. Every image and video on every sign lives there and nothing in the
  repo copies it. If the host takes file-level backups, write where they are into
  `DEPLOY-SKIP.md` §C — that is the difference between losing the store's photographs
  and losing an afternoon.

---

## Phase A — Data safety · steps 1–2

- [ ] **A.1 (step 1) — Back up the database.** phpMyAdmin export is enough. Publishing
  has no undo and this deploy rewrites how every element is addressed.
- [ ] **A.2 (step 2) — Rehearse on a copy, never on live.**
  `php tools/rehearse_phase1.php --host=localhost --db=<copy> --user=<user> --pass=<pass> --confirm-copy`
- [ ] **A.3 — It ends with exactly `Rehearsal clean.`** Anything else is a count of
  failures and the visit stops here.
- [ ] **A.4 — Read what it says is still wanted after converging.** That is the fastest
  way to see whether the live database is genuinely finished (HANDOFF §5).

> **New since this step was written:** the rehearsal script now runs in CI against real
> MySQL 5.7, 8.0 and 8.4 on every push (§4bk). Until 2026-08-19 it had not completed a
> run since 11 August. This is the gate protecting your live data and it was unproven
> the last time you read this list.

---

## Phase B — The upload · step 3

Read [`DEPLOY-SKIP.md`](DEPLOY-SKIP.md) **here, not afterwards.** None of these
announces itself and the app comes up looking fine.

- [ ] **B.1 — File-by-file, or folder-by-folder with overwrite.** Never mirror, sync,
  or "make remote match local". That is the mode that deletes `uploads/`, for which
  there is no undo and no backup.
- [ ] **B.2 — Do not overwrite `branding_config.php`.** The server generates it. The
  repo's copy reverts the store name and — the half that is not cosmetic — `MAIL_FROM`,
  which is the `From:` on password-reset codes and every schema alert. Sent from a
  domain this host does not own, those are dropped as spam: the first symptom is
  somebody unable to reset a password weeks later, and the alert that would have
  explained it never arrives either.
- [ ] **B.3 — Do not upload `setup.php`, `.git/`, `*.md`, `docs/`, `.github/`,
  `tools/*.js`.** `setup.php` deletes itself now, but only when something requests it —
  the window between the upload and the first hit is real.
- [ ] **B.4 — `lib/` and `tools/` go up *with* their `.htaccess` files.** Many FTP
  clients skip dotfiles by default. Those two files are the only thing making either
  folder unreachable.
- [ ] **B.5 — Read the live root `.htaccess`, then replace it.** It must go up: the
  security headers and the `viewer.php` framing exception are in it. Read first because
  a hand-raised `upload_max_filesize` lives only there and reverts silently. Carry any
  hand-edit forward.

### The six checks that catch a mistake from that list

- [ ] **B.6 — Settings → This Server:** the site name is the store's, not
  `Store Display System`, and the upload limit is what it was.
- [ ] **B.7 — Settings → Errors and Alerts:** it names a writable path and says who an
  alert would reach. `Nowhere to write` means nothing going wrong is being recorded and
  no alert can be sent — the one row worth acting on immediately.
- [ ] **B.8 — `…/lbm/setup.php` answers 404.** Not the form, and not "Setup is
  complete". Requesting it is also what makes it delete itself if it was uploaded, so
  this check repairs the mistake it looks for — but only if you run it. A page reading
  *"could not delete itself"* needs a hand.
- [ ] **B.9 — The brand logo still renders in the nav bar.** The cheapest proof
  `uploads/` survived.
- [ ] **B.10 — `…/lbm/README.md` answers 403**, not 404. 403 proves the `.htaccess`
  block parsed; 404 only proves nobody uploaded the docs this time. Load a page of the
  app first — a mistake in `.htaccess` is a 500 on everything.
- [ ] **B.11 — `…/lbm/lib/schema.php` answers 403, and so does a file under
  `tools/`.** A **404** means the folder is there and its guard is not. A **200** means
  the guard is there and Apache is not reading it. Both were 403 on `lbm-test/` on
  2026-08-13; today `lib/` does not exist under `lbm/` at all.

---

## Phase C — Converge and audit · steps 4, 4a

- [ ] **C.1 (step 4) — Sign in once as an admin.** That first authenticated request
  creates `displays`, seeds the drive-thru from `canvas_settings`, and backfills
  `display_id`.
- [ ] **C.2 — Settings → Database Structure: every row green.** This is where a missing
  privilege from 0.1 shows up, and it is the only place it does.
- [ ] **C.3 (step 4a) — `php tools/audit_colors.php`**, read-only, pointed at the live
  database. After convergence, because it reads `displays`.
- [ ] **C.4 — The clear answer is `Every stored colour reads. Nothing to do.`** It
  belongs on this visit because of D.3: a `font_color` nobody can read makes its Display
  **refuse every publish from here on, by name** (§4ac). Better to learn it now than
  standing at the sign. Fixes are a person picking colours in the Builder and Settings.
- [ ] **C.5 — Settings → This Server, the database's session zone.** Anything other than
  a zero offset means the host refused the app's request for UTC (#44 · §4ap). Nothing
  had ever shown this before; the cost of a refusal is bounded (a creation date a few
  hours out).
- [ ] **C.6 — Settings → This Server, largest file that can be uploaded.** If the host's
  ceiling is under 10 MB, the Asset Library and logo forms will state that smaller figure
  instead. That is correct (§4au).

> **If the sign goes blank after step 4**, the backfill is the thing to check:
> `SELECT COUNT(*) FROM canvas_elements WHERE display_id IS NULL` should be **0**. It
> re-runs on every authenticated request, so loading an admin page repairs a partly
> applied migration. A non-zero count that *persists* means the `UPDATE` is being
> refused and needs running by hand.

---

## Phase D — The cutover · steps 5–8

- [ ] **D.1 (step 5) — `viewer.php?display=drive-thru` shows the drive-thru layout
  unchanged, pixel for pixel.**
- [ ] **D.2 — A bare `viewer.php` says `No display specified`.** That is ADR-0003
  working as designed, not a fault.
- [ ] **D.3 (step 6) — Publish once from the Builder; the Screen updates within 30s.**
- [ ] **D.4 (step 7) — Prove the refusal.** Second Builder tab left open, publish from
  the first, then publish from the stale tab. It must be refused by name —
  `This display changed since you opened it (last published by …). Nothing was saved —
  reload the page and re-apply your change, so you do not overwrite someone else's
  work.` — and **the layout must not change.**
- [ ] **D.5 (step 8) — Re-point the TV** to `…/viewer.php?display=drive-thru`.
- [ ] **D.6 (step 8) — Re-point the SmartSign2Go widget** to the same address. This and
  D.5 are the Phase 2 cutover and the only manual step in the project; until they are
  done that Screen shows the notice.

### Things to expect here that are not faults

The first layout you open after the cutover was built before four changes that now
report themselves. None is a fault and all four are on a live-copied layout
(HANDOFF §5):

- [ ] **D.7** — An orange `⚠ 1 CLIPPED — NOT ON THE SIGN` badge along the bottom of any
  section whose blocks stick out past it, **the moment the layout opens** (§4as). The
  section was already hiding those blocks from the sign, silently, all along. `viewer.php`
  clips too, so nothing the sign shows has changed — only whether the Builder mentions it.
- [ ] **D.8** — The **Layer** number changing on blocks nobody selected, the first time
  Back / Backward / Forward / Front is pressed (§4at). Every block on the old sign is on
  layer 1, so paint order came from a tie the buttons could not move. **No block moves
  visually** — the renumbering preserves the order the sign already showed. Those numbers
  are published, so the first press on each Display writes new `z_index` values.
- [ ] **D.9** — A `published by sky, Aug 12 at 3:42pm` line in the top bar (§4ax). On the
  live sign the *first* reading may look two hours out: every `last_published_at` written
  before #44's fix is shifted by the host's offset until that Display is next published,
  which corrects it. A Display nobody has published to reads `not published yet`.
- [ ] **D.10 — Tell whoever edits prices two things** (§4av, §4aw). A **locked section no
  longer takes new blocks** — select it, un-tick *Lock this block*, add, re-tick. And a
  **locked block now refuses six things, not two**: the Delete button, the Delete key, the
  Inspector's X/Y/W/H boxes and both rows of Align buttons all refused nothing before. A
  section holding any locked block also refuses to be deleted. Watch the corner: **if
  every section on a sign is locked, a basic account has nowhere to add at all.**

---

## Phase E — Shape and scope, one account · steps 9–14

- [ ] **E.1 (step 9) — Zoom at Fit:** drag a block and resize it; the inspector's
  X/Y/W/H match where it actually sits.
- [ ] **E.2 (step 9) — Zoom at 100%:** the same, and publishing puts it there on the
  Screen. Zoom is the one place Phase 2 could drift silently.
- [ ] **E.3 (step 10) — Add a Display:** preset *1080×1920 Portrait HD*, title
  "Test Portrait", blank canvas.
- [ ] **E.4 — It opens portrait and zoom-to-fit** in the Builder.
- [ ] **E.5 — Add a block, publish, open its screen address in a second tab: it
  letterboxes inside the window rather than distorting.** This is the one thing that
  shows the dimensions are really data-driven.
- [ ] **E.6 (step 11) — Add a Display at 1920×1080 from *a copy of an existing
  display's layout* → the drive-thru.** Same blocks in the same places.
- [ ] **E.7 — The drive-thru is untouched.**
- [ ] **E.8 — The refusals:** at 1080×1920 the drive-thru is **not** offered as a
  source, and at 1920×1080 the portrait Display is not.
- [ ] **E.9 (step 12) — Switch the Display selector: each Display lists only its own
  elements** in the Work Area.
- [ ] **E.10 — Hide one element on a test Display: the drive-thru is unaffected.**
- [ ] **E.11 — A Builder tab opened before that hide is refused when it publishes.**
- [ ] **E.12 (step 13) — Turn a test Display off:** its screen address says
  `This display is turned off` within 30s.
- [ ] **E.13 — It still opens in the Builder with the red banner** — that is
  `get_editor_layout` doing its job.
- [ ] **E.14 — Delete it: a mistyped tag refuses.**
- [ ] **E.15 — The right tag deletes it and its elements.**
- [ ] **E.16 — The drive-thru is still intact after both.**
- [ ] **E.17 (step 14) — With more than one Display, a bare `builder.php` lists them to
  choose from** — and as an admin lists **every** Display, retired ones included.

---

## Phase F — Two accounts, two browsers · steps 15–21

**The one part of the visit with an order that matters.** Step 20's wait is fifteen
real minutes; started where the prose puts it, that is fifteen minutes of standing
still. Start it at the top of this phase instead.

Call them **Browser A** (admin) and **Browser B** (the `basic` account). You want
**three** test Displays here: **D1** for the grant work, **D2** for the timer, **D3**
free for the "somebody else can take it" half of step 20.

### The timeline

```
T+0    F.1–F.5    grants set up in A, B lands in its Display
T+0    F.6        ── start the idle timer: B opens D2, touches nothing ──
T+0    F.7–F.28   everything else, on D1, while D2 just sits there
T+13   F.29–F.30  the warning bar on D2, and Keep editing
T+15   F.31–F.33  the release, and the other browser takes D2
T+15   F.34       leaving releases at once
```

- [ ] **F.1 (step 15) — Add a `basic` account.** Sign in as it in Browser B.
- [ ] **F.2 — With no grant it says `No displays have been assigned to you yet`.**
- [ ] **F.3 — Tick it against exactly one test Display (D1)** in Admin Panel →
  Displays → *Who can edit which display*, in Browser A.
- [ ] **F.4 — Reload as B: it lands straight in D1, no picker.**
- [ ] **F.5 (step 16) — The refusal, not the absence.** Still as B, type
  `builder.php?display=drive-thru` by hand. It must say the display has not been
  assigned to you and offer only their own. **This is the check that matters** — a
  Display missing from a list proves nothing about what the API accepts.
- [ ] **F.6 — Start the timer now.** In a spare tab of Browser B, open **D2** (grant it
  first) and touch nothing. Note the wall-clock time. Come back at +13 and +15.

#### Step 17 — revoking reaches an open tab, and frees the sign

- [ ] **F.7 — With B editing D1, untick its grant in A and save.**
- [ ] **F.8 — A's answer says an edit lock was released.**
- [ ] **F.9 — Within a minute B's tab shows a red bar:**
  `Your access to this display has been removed. An admin has taken it off your list, so
  nothing here can be published any more and the display has been released for somebody
  else. What you have done is still on this screen — copy anything you need before you
  leave the page. Ask an admin if this was not expected.` It must **not** have to try
  publishing to find out.
- [ ] **F.10 — What B had done is still on its screen.**
- [ ] **F.11 — D1's card in Admin Panel → Displays no longer says anybody has it
  open** — so the next person starts straight away rather than waiting out fifteen
  minutes for a lock held by somebody no longer allowed near the sign.
- [ ] **F.12 — Publishing from B's stale tab is refused and nothing reaches the
  screen.**

#### Step 17 — the grid saves what it showed

- [ ] **F.13 — Open the Displays tab in both browsers as the admin.** Add a Display and
  grant it in one; press *Save access* in the other.
- [ ] **F.14 — The new grant survives** — that page never showed the new column, and
  reading "not ticked" and "not on the page" as the same thing is how this grid once
  saved over work it had never shown.
- [ ] **F.15 — Press F5 on the page you saved from: it reloads without re-sending the
  grid.** The success line appears once and is gone. That is deliberate, not a lost
  message.

#### Step 17 — a promotion clears the assignments

- [ ] **F.16 — Restore B's grant on D1, and have B open it.** Then make B an admin.
- [ ] **F.17 — The answer says which displays were cleared.**
- [ ] **F.18 — That account vanishes from the grid.**
- [ ] **F.19 — Making it a basic user again leaves it holding *nothing*** — not what it
  had before.
- [ ] **F.20 — The Display it had open is free.**

#### Step 17 — the other three doors, one at a time

**Restore B's grant and have B open D1 before each one.** A door tested against a
revoked grant proves nothing.

- [ ] **F.21a — Turn D1 off.** B's tab:
  `This display has been turned off. An admin has retired it, so it is no longer yours to
  edit and nothing here can be published. What you have done is still on this screen —
  copy anything you need before you leave the page. Nothing you had not published reached
  the screen.` A's answer must say a lock was released.
- [ ] **F.21b — Turn it back on, then check the admin case:** an *admin* editing a
  Display they retire is **not** thrown out of their own session.
- [ ] **F.22a — Suspend the account.** B's tab:
  `You have been signed out. This account can no longer sign in — an admin may have
  suspended it. Nothing here can be published. What you have done is still on this
  screen, so copy anything you need before you leave the page, and ask an admin if this
  was not expected.`
- [ ] **F.22b — D1's card stops naming it as the holder immediately**, not in fifteen
  minutes. (A lock held by somebody who cannot sign in is not honoured at all — the rule
  is applied on read, in both `LockState::isHeld()` and `claimLock()`'s `WHERE`.)
- [ ] **F.23a — Rename D1's screen name tag.** B's tab must ask it to **reload** rather
  than say it lost the Display:
  `This display is no longer at this address. Its screen name tag has been renamed, or
  the display has been deleted. Reload the page to find out which — if it was renamed it
  is still yours, and still where you left it. Copy anything you cannot afford to lose
  first. Nothing you had not published reached a screen.`
- [ ] **F.23b — After reloading, that same account still has the sign and can still
  publish it.** A rename is not a change of access — it changes the address, not who may
  edit, which is why this is the one door of the five that does not free the lock.
- [ ] **F.24 — Re-point the Screen afterwards; the old address stops working.**

#### Steps 18–19 — one editor at a time, and the takeover

- [ ] **F.25 (step 18) — With B editing D1, open D1 as the admin in A. It comes up
  read-only:** a purple bar naming B and since when, **no *+ block* buttons, no
  background controls, no Publish button**, and clicking a block does nothing.
- [ ] **F.26 — Each Display card in Admin Panel → Displays says who has it open.**
- [ ] **F.27 (step 19) — Click *Take over editing* in A and confirm.** The page reloads
  as a normal Builder.
- [ ] **F.28 — Back in B, within a minute its heartbeat raises a red bar saying the
  admin has it.** Its canvas is untouched — that is deliberate — but **publishing is
  refused by name and nothing reaches the screen.**

#### Step 20 — the idle release (the tab from F.6)

- [ ] **F.29 — At 13 minutes** a warning bar appears on D2: `Still working? Nothing has
  been touched here for a while, so this display will be released for other people to
  edit in about 2 minutes.` with a *Keep editing* button.
- [ ] **F.30 — Click *Keep editing* and prove it kept the Display**: the other browser
  still sees read-only afterwards. (Clicking it and *assuming* is how a check that could
  not fail gets written.)
- [ ] **F.31 — At 15 minutes** the bar says the lock was released:
  `The edit lock was released after 15 minutes with nothing happening, so somebody else
  can take this display. Carry on — changing anything takes it straight back, unless
  somebody has started in the meantime.`
- [ ] **F.32 — The other browser can then open D2 and edit it normally.**
- [ ] **F.33 — Go back to the first tab and change something:** it takes the Display
  back if nobody claimed it, or says who did.
- [ ] **F.34 (step 21) — Leaving releases it at once.** Close the Builder tab, or click
  away to the Asset Library, then **immediately** open that Display in the other
  browser. It must be editable straight away — nobody waits out 15 minutes for a display
  nobody is looking at.

---

## Phase G — Clean up · step 22

- [ ] **G.1 — Delete the test Displays** (D1, D2, D3, Test Portrait, the drive-thru
  copy).
- [ ] **G.2 — Delete the test account.**
- [ ] **G.3 — A bare `builder.php` as an admin goes straight into the drive-thru
  again** — the single-sign entry rule.
- [ ] **G.4 — The drive-thru layout is exactly as it was.**

---

## Then one piece of bookkeeping

This visit **is** one of the five re-walks
[`browser-pass.md`](browser-pass.md) is owed — `public_html/lbm/` after the cutover.
Do not budget them as two trips. When it is walked, its outcome table at the top of
that file is the place it gets recorded, and a defect found here gets a §4-something of
its own the same way §4as–§4ax did — ask `tools/check_doc_numbering.php` for the next
free letter rather than counting.

The other four are unaffected by this visit: `lbm-test/` for the step-2 workbench,
Display Branding, the Builder's Brand control, and Workspace Themes — the last three of
which this pass has no step for at all, because they are v2.

**95 boxes: 6 to settle before you go, 89 on the visit.** If a phase ends with one
unticked, it ends unticked — the value of a list like this is entirely in not rounding
up.
