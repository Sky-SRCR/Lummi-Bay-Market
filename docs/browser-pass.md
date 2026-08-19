# The browser pass — lane 0, in order

Everything in this repo is verified by something except what a browser does. Six node
suites run `builder.php`'s JavaScript against a stubbed DOM, and a stub cannot see a CSS
rule that does not apply, a button that overlaps another at 1080p, or `interact.js` —
which is un-run by anything at all (§4al). Four commits of `builder.php` have never been
rendered: the six inspector controls (#42), the split opening reads, the root-content
`db_id` payload, and Undo. This is the list for closing that.

## It was walked, and it closed — 2026-08-13

**All ten sections were run against `lbm-test/` by the store owner over 2026-08-12/13.**
Seven defects, all seven fixed on the same branch and re-checked in the browser that found
them. What each section produced:

| Step | What the browser said |
|---|---|
| **A** | Passed. `lbm-test` against `_2`, the database's session zone `+00:00`, Database Structure all green. The isolation guarantee held for the whole pass. |
| **B** | Passed, with one thing explained rather than changed: **100 % means 1:1**, so a 1920-wide canvas overhangs a 1920-wide *window* — the top bar is inside that window too. Fit is the answer to that, and Fit reported 84 %. Zooming scaled proportionally throughout. |
| **C** | `interact.js` driven for the first time by anything. Drag, resize, the edges and the floor all held — and shrinking a **section** over its own children hid them from the sign in silence. §4as, and ADR-0004's Consequences corrected. |
| **D** | Every block on a layout copied from live sat on layer 1, so Back and Backward were moving a number that could not break a tie. §4at. |
| **E** | Passed, unchanged. |
| **F** | Three file pickers, three ceilings, none of them stated — and the file over the host's `post_max_size` was answered *"Security token mismatch"*. §4au. |
| **G** | Three things, then a fourth. A locked section still accepted new blocks and an account with no sign was still offered the Asset Library (§4av); the 15-minute lock lapse was checked and is **not** a bug; and a locked block turned out to be refused by the mouse and by nothing else — six doors, not the one reported. §4aw. |
| **H** | H.1 only: who published and when had been recorded correctly for a year and shown nowhere in the Builder. §4ax. Everything else passed, including the Viewer's unattended 30-second pickup, the wrong-tag / no-tag / inactive notices, and a few minutes with the console open. |
| **I** | Passed. The read-only Builder tells an account with no sign so, the editing markup is absent rather than disabled, and the upload is refused. |
| **J** | Passed. Both of #44's live-only questions answered in the store's zone, and the picker offers region names only. |

**The list below is not spent.** It was written for the rehearsal install, and every step
of it applies again to the live sign — which is the one thing this pass does not cover
(see the end). Re-run it there after the 22-step deploy.

**Do it in `lbm-test/`.** It exists for this. As of 2026-08-12 it is isolated against
`silverad_lummi_market_drive_thru_2` and the multi-display build runs there.

**Order matters.** The cheap observations come first, the irreversible one (Publish) comes
after the things that would explain a bad publish. Undo is deliberately before Publish:
it is the only feature here whose purpose is to change the canvas out from under you, and
a round trip that is byte-identical in a stub can still be wrong on screen.

You need **two accounts** — one admin, one non-admin — and ideally **two browsers** (or
one plus a private window). Steps G and I cannot be done with a single session.

---

## A. Before anything else: is this the rehearsal or the live sign?

**Admin Panel → Settings → This Server.**

| Row | Must read |
|---|---|
| This install | `lbm-test` |
| Database | `silverad_lummi_market_drive_thru_2` |

If Database says `silverad_lummi_market_drive_thru` with no suffix, **stop** — the
credentials file is missing and you are about to rehearse on the live sign
(`DEPLOY-SKIP.md` §E). This is the whole isolation guarantee, and the sign-in that shows
it to you is the same one that converges schema on whatever database it found.

While on that card, read the three time-zone rows and the upload ceiling:

- **Store time zone** — `America/Los_Angeles` unless somebody changed it.
- **PHP time zone** — expect `America/Chicago`. The host sets it; nothing in this repo does.
- **Database time zone** — must be **`+00:00`**. `db_connect.php` asks every connection
  for it and the request is *suppressed rather than fatal*, so a host that refused it says
  so **here and nowhere else**. Anything but a zero offset means the app is back to two
  clocks and creation dates read hours out.
- **Largest file that can be uploaded** — note the number for step F. 50 MB is the app's
  own ceiling; the host may be lower and this row prints whichever wins.

Then **Settings → Database Structure**: every row green. A red `canvas_elements.display_id`
reads *"Nothing is scoped to a Display. Do not publish."* and means exactly that — go no
further.

---

## B. The Builder at the size a sign actually is

Open a Display in the Builder. Set the browser to **1920×1080** if you can, or zoom to
fit and note it.

- [ ] No control overlaps another in the top bar. It wraps (`flex-wrap`) — check it wraps
      *tidily* rather than hiding a button behind another.
- [ ] The canvas is the Display's own dimensions, not a hardcoded 1920×1080.
- [ ] Every block on the stored layout is drawn, in roughly the positions the old sign had.
- [ ] Nothing is drawn off-canvas or at 0,0 in a pile — that shape means a field a rebuild
      forgot to carry.
- [ ] Zoom in and out. Blocks scale with the canvas and stay where they belong.

## C. `interact.js` — the one thing nothing has ever run

- [ ] **Drag a block.** It follows the pointer and stays where dropped.
- [ ] **Resize a block** from a corner handle. It resizes, and does not snap back.
- [ ] Drag a block to a canvas edge. It stops at the boundary rather than vanishing.
- [ ] Resize a block down to nothing. There is a floor; it should refuse to go below it
      rather than reaching zero or inverting.
- [ ] Drag a block *inside a section*. It stays parented to the section — a section's
      children move with it.
- [ ] Move the section itself and confirm its children follow.
- [ ] **Shrink a section until a block inside it is cut off.** The block *should*
      disappear — sections clip, here and on the sign. What must also happen is that the
      section grows an orange badge along its bottom edge naming the count (§4as). A
      fully clipped block cannot be clicked any more, so the badge is the only thing
      telling you it is still in the layout and still going out with the next publish.
      Grow the section back, or press Undo, and the badge must go away with it.

If drag or resize does nothing at all, `interact.js` did not load — check the browser
console for a 404 before assuming a logic fault.

## D. The inspector's six controls (#42)

Select a block. For each control, change it and confirm the canvas changes **and** the
value survives a page reload (before publishing — a reload re-reads the server's copy, so
anything that vanishes was never saved).

- [ ] Text align
- [ ] Z-index / layering — send one block behind another and confirm the paint order.
      **The Layer number will move on blocks you did not select**, and that is the fix
      rather than a fault (§4at): everything is created on layer 1, so the buttons
      renumber the whole group to 1..n and no two blocks share a layer afterwards.
      Check both directions from a fresh canvas — Back and Backward are the two that
      did nothing at all before, and Back must work on the **first** press.
- [ ] Hide / unhide — a hidden block should be visibly marked in the Builder, and absent
      from the Viewer later
- [ ] The slide fields
- [ ] The marquee
- [ ] Colour pickers — set a colour, reload, confirm it came back **as the colour you
      chose**. This is the one with history: a colour the CSSOM silently discarded was
      published as black.

## D2. The table block: filling one from a CSV (§4az)

Needs a real file and a real drag, so nothing in the repo can stand in for it. Make a
small `.csv` in Excel or Numbers with a heading line — `Title,Price,SKU` over two or three
rows — and put `"Sockeye, wild"` in a cell, quoted comma and all.

- [ ] Select a table block and press **Edit Table**. Drag the file onto the drop area in
      the modal. It outlines in green while the file is over it, and the rows arrive.
- [ ] The note under the drop area names the file, the row and column counts, which
      headings were styled by name, and which became Plain. `SKU` must be *named*, not
      silently styled.
- [ ] The quoted comma is still one cell.
- [ ] Untick **First row names the columns**. The heading line becomes a row of content
      without the file being asked for again.
- [ ] Now edit the imported table the way anybody would: type over a price, change a
      column's style dropdown, set a column width, change **Row padding**, add a row, add
      a column, delete one of each. All of it must work exactly as it does on a table
      typed by hand — an import fills the editor, it does not replace it.
- [ ] Press **Cancel**. The block still holds what it held before the drop — nothing is
      stored until Save Table.
- [ ] Click the drop area instead of dragging, and choose the same file. Same result.
- [ ] Drop a `.jpg` on the drop area — refused by name, not read.
- [ ] Close the modal. Now drop the file **on a table block on the canvas**, then on the
      canvas background, then on a text or image block. In all three the browser must
      **not** open the file: the page stays, nothing is imported, no block outlines, and
      it says to open the table and use the area inside Edit Table. The block one is the
      point — a table block is not a drop target — and the navigation is the one that
      costs an unpublished canvas if it regresses.
- [ ] On a Display somebody else is holding, drag the file anywhere on the Builder:
      nothing is imported, the page does not navigate, and it says who is holding it.
- [ ] Save Table, then Publish, and read the sign.
- [ ] Open a table that was published **before** this change and save it untouched. Row
      padding and everything else must come back exactly as it was — this change stores
      the same shape it always did.

## E. Undo — before you publish anything

Default depth is **5 steps** (Settings, capped at 20). Undo works only *before* a publish
and only in the tab that made the changes (ADR-0010).

- [ ] Make 3 distinct changes — move a block, retype some text, change a colour. Undo
      three times. Each step reverses **one** change, newest first, and the canvas on
      screen matches what it looked like at that point.
- [ ] Undo past the beginning. The button should become unavailable rather than emptying
      the canvas.
- [ ] Make 6+ changes and undo repeatedly — it stops at 5 and does not misbehave at the
      boundary.
- [ ] Undo a **delete**. The block comes back with its text, size, colour and parent
      section intact, not as a bare box.
- [ ] Undo while a text field has focus — it should not fight the field's own undo
      (`keyboardIsInAField()` guards this).
- [ ] Reload the page. The undo stack is empty and the button is unavailable. That is
      correct: the stack lives in the tab.

## F. Uploads

Ceiling from step A (50 MB app maximum). **There are four separate file pickers in this
app and they do not behave the same way** (§4au) — which one you are testing decides what
you should see, so they are listed apart:

**The inspector's uploads** (Upload Image, Upload Video, a section's background, a
carousel slide). These upload immediately.

- [ ] Upload a small image. Progress shows, then it appears in the Asset Library and can
      be placed on the canvas.
- [ ] Upload a file **over** the ceiling. It must be refused with a sentence naming the
      limit — not a silent failure and not a raw 500.
- [ ] Upload a non-image (a `.txt` renamed, say). Refused, with a reason.
- [ ] Cancel a large upload midway with the **Cancel upload** button on the progress box.
      It says the upload was cancelled and nothing was changed, and the page stays usable.

**The top bar's `Background:` picker.** This one does *not* upload — the image travels with
the next Publish, so there is no progress bar and there should not be one.

- [ ] Pick a valid image. It appears behind the canvas **and** a note appears beside the
      picker naming the file and saying it goes on the sign at the next Publish.
- [ ] Pick one over the ceiling. Refused at pick time, naming the size and the limit.
      Before §4au this did nothing whatsoever and the refusal only arrived at Publish,
      where it abandoned the entire publish rather than the image.
- [ ] Pick a non-image. Refused, naming the type.
- [ ] Switch Background to **Color**. The note goes away — it has stopped being true.

**The Asset Library (`crud.php`).** A plain form POST, so its progress bar is
indeterminate on purpose: there is nothing to measure.

- [ ] The form states the size limit next to the accepted types. Check the number matches
      step A's row if the host's limit is below 10 MB.
- [ ] Pick an image over the limit. Refused under the picker — *file too big* — and the
      file is **not** left in the picker.
- [ ] Pick a `.txt` renamed to `.png`. Refused under the picker — *wrong file type*.
- [ ] Save a valid image. The button reads Uploading… and the bar moves while it works.
- [ ] If you can raise a file past the host's `post_max_size`, do it. The answer must
      name the **size**. A page reading *"Security token mismatch"* means the guard
      before `verifyCsrf()` is gone — that was the original defect.

**The brand logo (Admin Panel → Branding).** Same shape as the Library.

- [ ] The label offers PNG, JPG, GIF, WEBP and **not SVG** — an SVG is refused by the
      handler, so offering it was inviting a refusal.
- [ ] Pick something over the stated maximum. Refused at pick time, and no preview appears.
- [ ] Pick a valid logo. It previews, and the note says it saves when you press Save
      Branding.

## G. The edit lock — needs a second browser

The lock lapses after **15 minutes** of no real interaction (`IDLE_LAPSE_SECONDS = 900`).

- [ ] Open a Display as account 1. In browser 2, sign in as account 2 and open the same
      Display. Account 2 gets a **read-only** Builder naming who holds it and *since when*.
- [ ] **Read that "since" time carefully — see step J.** It is the store's clock, not yours.
- [ ] Account 1 releases the lock (or closes the page). Account 2 reloads and can now edit.
- [ ] While account 1 holds it, have an admin **revoke account 1's grant** to that Display.
      Account 1's page should say their access was removed, in a sentence, and stop
      offering Publish. This is one of the five terminal refusals — it never comes back,
      and the wording tells them their work is still on screen to copy.
- [ ] Have an admin **rename the screen name tag** while account 1 holds the lock. This one
      is *not* terminal: account 1 keeps the lock and is asked to reload.
- [ ] Have an admin **turn the Display off** while it is held. Terminal, with its own
      sentence.

**Two things here are easy to read as faults and are not** (§4av):

- If more than 15 minutes of *no real interaction* passed between the two sign-ins, the
  admin's lock had already lapsed, so account 2 gets a **fully editable** Builder and can
  publish. That is `IDLE_LAPSE_SECONDS` doing its job — the alternative is a Builder left
  open on a back-office monitor blocking the sign until somebody walks back to it. Check
  instead that **account 1's tab notices**: its next heartbeat should raise the "lost it"
  bar and refuse the publish.
- A basic account being unable to add a block until it clicks a section is the role, not
  the lock. It should see *"Please click on a section first to add content."*

- [ ] **Lock a section as admin, then try to add a block to it** (either account). Clicking
      it must not target it, and the block buttons must refuse with a sentence — a locked
      section takes no new blocks (§4av). Blocks *already* inside it stay editable unless
      they are locked themselves.
- [ ] Sign in as a basic account with **no Display assigned**. The footer must offer only
      **Sign Out** — no Asset Library link, because the Library needs a sign to add to.

### G2. What a lock on a block refuses — six doors (§4aw)

The lock was asked at the drag and resize seams only, so everything below except the first
line went through it. Take one block, tick **Lock this block**, and try all seven:

- [ ] Drag it, and pull a resize handle. Both refuse (these two always did).
- [ ] **Delete Block** in the Inspector, and then the **Delete** key. Both refuse, each with
      a sentence, and the Delete key must refuse *before* the "Delete this block?" confirm.
- [ ] Type a new number into the Inspector's **X** and **W** boxes. Both refuse — and the
      box must snap back to the block's real value rather than keeping what you typed.
- [ ] Press an **Align** button and an **Align to Parent** button. Both refuse.
- [ ] Press **Send to Back**. It refuses too.
- [ ] Press **Undo** after all of that. It must have nothing to take back — a refusal is not
      a step.
- [ ] With that block still locked, select it *and* an unlocked one (shift-click) and press
      **Align → centre**. The locked one stays put, the unlocked one moves, and a message
      says how many were left where they are.
- [ ] Put a locked block inside a section, select the **section**, and press Delete. It
      refuses and says how many locked blocks it found — deleting a section deletes what is
      inside it.
- [ ] Un-tick the lock. Everything above works again — a lock is an accident-preventer, not
      a permission, and anyone who can lock can unlock.

Two things a lock deliberately does **not** stop: editing the block's text or its colour,
and the layer numbers of its *siblings* moving when one of them is re-layered.

## H. Publish, and the sign

Only now, and only with step A's Database Structure green.

- [ ] Press **Publish**. The green note names the sign **and** who published it and when
      ("…by sky, Aug 12 at 3:42pm"), and the top bar's **published by** line — beside the
      canvas size — says the same thing and stays there after the note fades (§4ax).
- [ ] Reload the Builder. That line is still there, read from the row rather than from
      the publish you just made. On a Display nothing has been published to it reads
      **not published yet**.
- [ ] The time is the **store's** clock, not this browser's — see step J. This is the same
      sentence the Admin Panel's Displays tab shows, so the two must agree to the minute.

  *This step used to ask for "a revision" as well. It should not: the publish stamp is
  opaque by design (ADR-0006) — callers compare it, nobody reads it — so who and when is
  the answer, and a revision number on screen would invite somebody to quote it.*
- [ ] Open the Viewer for that Display in another tab:
      `lbm-test/viewer.php?display=<tag>`. The layout matches the Builder.
- [ ] A **hidden** block from step D is absent from the Viewer.
- [ ] Change one block, publish again, and **leave the Viewer alone**. It must pick the
      change up **within 30 seconds** without being touched.
- [ ] Open the Viewer with a **wrong tag**. It renders a notice, never another sign's
      layout, and never a blank screen (ADR-0003).
- [ ] Open the Viewer with **no tag at all**. Same — a notice, even though only one
      Display exists and the guess would have been right.
- [ ] Turn the Display off in the admin panel and reload the Viewer. It reports the
      Display is inactive rather than drawing an empty canvas.
- [ ] Leave the Viewer running for a few minutes. No JavaScript error appears in the
      console — on a TV a thrown exception is a blank sign, not a stack trace anybody
      reads.

## I. The read-only Builder, from an account with no sign

- [ ] Sign in as an account with **no Display assigned**. The Builder tells them no
      display is assigned to them.
- [ ] From that same account, confirm the inspector, align bar and modals are **not on the
      page at all** (#3) — not merely disabled. View source and search for the inspector's
      markup.
- [ ] Try an upload from that account. Refused — the asset library and `uploads/` are one
      pool behind every sign, so the refusal is the check, not the missing form.

## J. #44's two live-only questions

These are why the timezone work needed a browser at all.

- [ ] **The "since" time in step G reads in the store's zone**, `America/Los_Angeles` by
      default — **not yours and not the server's.** This is the easiest thing on this page
      to misread as a bug:

      If you are on Eastern time, a lock you took at 2:15pm your time should display as
      **11:15am**. That is correct. Before #44 it displayed Central — 1:15pm. So the
      number to check against is *Pacific*, not your wall clock.

- [ ] **Settings → Store Time Zone.** Change it to something obviously different
      (`America/New_York`), save, and confirm the "since" time in step G moves by the right
      number of hours. Then set it back to `America/Los_Angeles`. A save must also
      **redirect** — press F5 afterwards and it must not re-submit.
- [ ] The picker offers region names only. If you can find `PST` or `+08:00` in it,
      something is wrong: a fixed offset is not a timezone and is wrong for half the year.

---

## If something fails

Capture, in this order:

1. **The browser console** — for anything in B–F, a JS error is usually the whole answer.
2. **The PHP error log**, around the timestamp. Note that a *schema* failure is logged
   rather than thrown, so the visible error can be two steps downstream of the fault —
   that is exactly how a missing `CREATE` privilege presented as
   `Base table or view not found: displays`. Read the lines *before* the exception.
3. **Settings → Database Structure**, if the failure is a query. A red row names the
   missing column and what it costs.
4. **Settings → This Server**, if the failure involves a time, an upload size, or a
   cookie.

A screenshot of the Builder at 1080p is worth more than a description for anything in
section B.

---

## What this pass does not cover

- **The live sign.** Passing here says the build runs on this host against a *copy* of the
  data, in `public_html/lbm-test/`. `public_html/lbm/` is still the single-sign app and the
  cutover is still the 22-step visit in
  [`docs/roadmap-multi-display.md`](roadmap-multi-display.md) plus the rows in `HANDOFF.md`
  §5. **Walk this list again afterwards** — same code, the shop's own data, and §5 says
  which findings will look like faults on layouts copied from the old sign.
- **A database that lags the repo.** `_2` is a copy of live and converged on first
  sign-in. A deploy against data that has drifted further is a different rehearsal.
- **More than one real Screen.** Two TVs polling the same install at once is untested by
  anything, here included.
