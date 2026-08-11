# What can be worked on at the same time

**Lanes A and B have both landed** — #33 as §4ao and #44 as §4ap — and they were the
first two lanes this file actually governed. **One audit item is open, #50**, plus a
browser pass that nothing in this repo can do. This file says which of them can run in
parallel, which cannot, and what the ones that do run in parallel have to agree about
before they start.

It exists because the round of parallel work before this one produced four branches that
each solved something and each had to be adjudicated afterwards, and because the
collisions were not where anybody expected. They were not in the app. They were in the
files every branch touches.

**Read items 2 and 4 below before starting a branch beside another one.** They are the
two the A/B round corrected, and both were corrected by something going wrong rather
than by argument: an invariant number cannot be reserved the way a section letter can,
and the count line in `reviewed-decisions.md` does not conflict when it should.

## The short version

| Lane | Item | Run it | Why |
|------|------|--------|-----|
| **0** | Browser pass on `lbm-test/` | **First, before C** | Four commits of Builder changes have never run in a browser, and #44 added two things only a live page can confirm. Nothing here can do it. |
| ~~**A**~~ | ~~#33 — an account with no signs can still write the shared library~~ | **Landed** — §4ao, invariant 29 | Touched `crud.php`, `api.php`, `lib/grants.php` — not `lib/assets.php`, which is what this table predicted. |
| ~~**B**~~ | ~~#44 — no timezone, so "editing since 2:15pm" followed the host's `php.ini`~~ | **Landed** — §4ap, invariant 28 | Touched `lib/branding.php`, `admin_panel.php`, `lib/displays.php`, `config.php`, and — beyond what was predicted — `db_connect.php`, `lib/login_attempt.php`, `lib/server_report.php` and the new `lib/store_clock.php`. Still nothing A touched. |
| **C** | #50 — 29 checks that cannot fail, 5 invariants with no automated check | **Now unblocked, and alone** | Its whole subject *is* the two files A and B were both adding to. Both have landed, so the suite it has to measure finally holds still. |

## Lane 0 first, and it is not optional

`https://www.srcresort.com/lbm-test/` exists precisely so this can happen without
risking the live sign (`DEPLOY-SKIP.md` §E). Since the last deploy, `builder.php` has
gained the six inspector controls (#42), the split opening reads, the root-content
`db_id` payload, and Undo. All of it is covered by six node suites over a stubbed DOM,
and **none of it has been rendered by a browser**. The suites cannot see a CSS rule
that does not apply, a button that overlaps another at 1080p, or interact.js — which is
still un-run by anything (§4al).

Undo raises the stake specifically: it is the first feature in this app whose whole
purpose is to *change* the canvas out from under whoever is looking at it. A round trip
that is byte-identical in a stub and wrong on screen is exactly the shape a harness
cannot catch.

Neither A nor B touched `builder.php`, so that list is the same four commits it was —
but **#44 added two things to look at on the same visit**, and both are things only a
live page can answer:

- **Admin Panel → Settings → Store Time Zone.** The default is `America/Los_Angeles`,
  so a deploy that never touches it is already right for this store. Check it once, and
  read the three time-zone rows on **This Server** while you are there.
- **The database's session zone**, on the same card, which nothing had ever shown.
  `db_connect.php` now asks every connection for `+00:00` and the request is suppressed
  rather than fatal, so a host that refused it says so *there and nowhere else*.
  Anything other than a zero offset means the app is back to two clocks, and what it
  costs is a creation date reading a few hours out.

Read **Settings → This Server** before signing in a second time. It must say
`lbm-test` and `silverad_lummi_market_drive_thru_2`. That check is the whole isolation
guarantee, and the sign-in that shows it to you is also the one that converges schema
on whatever database it found.

## What the A/B round proved, and what it did not

The test was footprint, not subject:

| File | A (#33) | B (#44) | C (#50) |
|------|:-------:|:-------:|:-------:|
| `crud.php`, `api.php`, `lib/grants.php` | ● | | |
| `lib/branding.php`, `admin_panel.php`, `lib/displays.php` | | ● | |
| `config.php`, `db_connect.php`, `lib/login_attempt.php`, `lib/server_report.php`, `lib/store_clock.php` | | ● | |
| `tools/selftest_layout.php` | ● | ● | ●●● |
| `tools/check_invariants.php` | ● | ● | ●●● |
| `docs/BUILD-REFERENCE.md` | ● | ● | ● |
| `docs/reviewed-decisions.md` | ● | ● | ● |

**The conclusion held: A and B shared no application file.** Both ended up outside the
footprint this table predicted — A landed in `lib/grants.php` rather than
`lib/assets.php`, B in four files more than were listed — and neither drifted into the
other's. So the useful part is not the accuracy of the rows. It is that **a footprint
guessed from an item's subject is a guess**, twice over, and the guesses happened to be
safe. A predicts where a rule *belongs*, not where the item *is about*: #33's rule
belongs beside the other `Actor` predicates, and #44's belongs in a module of its own.

**What the table cannot predict at all is the last four rows.** Both lanes touched
`tools/check_invariants.php`, because adding an invariant means adding its mechanical
check, and both touched the same two docs, because that is where a write-up goes. Those
rows are not a function of the subject, so no footprint table drawn from application
files will ever get them right. They are what items 1–4 are for.

**The merge is where all four collisions actually appeared**, and three of them were
already wrong on paper before it: two branches claiming invariant 28, an anchor with two
values, and a count line that read `48 done, 2 open` in both trees when the truth after
the merge is `49 done, 1 open`. None of that is a mistake either branch made; each
counted correctly against a base that could only see its own item close.

C shares no application file with either — and that is the trap, because C's
*deliverable* is `tools/selftest_layout.php` and `tools/check_invariants.php`, which is
where A and B both added their coverage. Running C beside them would not have been a
merge problem, it would have been a measurement problem: C has to decide which of 29
checks cannot fail, and doing that against a suite two other branches are adding to
means the count is stale before it is written down. **C reads the suite. A and B wrote
it. Reads go after writes.** Between them they added 81 checks, so the 29 need
recounting before anything else in C happens.

C also has the one dependency that is not about files: its second half is *five
invariants with no automated check*, and §5 names them. That list has moved four times
now — the `schema.sql`-versus-`lib/schema.php` grep became a MySQL assertion, the
sanitiser grep became mechanical, B added three mechanical rules while adding one
by-eye entry (`STORE_TIMEZONE`, where the key of a save and a read look identical to a
pattern), and A added one mechanical rule. C should read the list it is actually meant
to close, not the one that was there when #50 was filed.

One thing for C specifically, found by B and deliberately not fixed by it:
`check_invariants.php` strips **PHP** comments before matching and not **HTML** ones, so
an `<!-- … -->` explaining why a line no longer makes a forbidden call fails the rule
against its own explanation. `codeWithoutComments()` works on tokens and the fix has to
decide about PHP embedded inside an HTML comment, which changes what all 26 rules see.
That is a measurement question, which is C's, not a fix to bundle into a lane that is
also adding rules.

## What a lane has to agree before it starts

Kept for the next round rather than for C, which runs alone. Four things are shared, and
each has a specific way of going wrong that has now happened at least once.

### 1. Section letters — assigned here, not discovered

`check_doc_numbering.php` prints the next free letter, and four branches cut from one
base all asked it and all got the same answer. Asking is right; asking *at the same
time* is the failure. So they are allocated in advance. Phase 4 now runs to §4ap:

- ~~**Lane A (#33) writes `4ao`.**~~ Written.
- ~~**Lane B (#44) writes `4ap`.**~~ Written.
- **Lane C (#50) writes `4aq`.**

**This scheme worked exactly as designed, and it is the only one of the four that did.**
B wrote `4ap` while `4ao` was still unwritten and its push passed, because
`check_doc_numbering.php` checks letters for duplication and for dangling citations, not
for an unbroken run. A gap is harmless here. That asymmetry with the invariant numbers is
the whole of item 2.

A reservation is written without the `§` on purpose: a reservation is not a citation, and
`check_doc_numbering.php` reads every `§`-reference in every doc and fails on one that
points at a write-up which does not exist yet. That is the check doing its job — a
citation of a section nobody has written is what a guess looks like from outside. Add the
`§` when the write-up exists, which is why `4ao` and `4ap` carry one now and `4aq` does
not.

### 2. Invariant numbers — reserving them does not work, and here is what does

**Corrected by the A/B merge.** The plan was A takes 28, B takes 29, C takes 30 and up.
Both branches wrote **28**, and both were right to: `check_doc_numbering.php` requires the
list to run **unbroken from 1**, so writing 29 into a tree whose list ends at 27 is not a
deferred conflict, it is an immediate failure of your own push. A gap in the section
letters is harmless; a gap here is not. **A number cannot be reserved across a base that
does not contain the number before it.**

So the rule that actually works: **every lane writes the next free number in its own tree,
and the reservation only settles who renumbers on the merge.** As settled:

- **Lane B kept 28** — a stored moment is UTC, read in one place, shown in the store's
  zone (§4ap).
- **Lane A renumbered to 29** — an account that has been assigned no sign writes nothing
  shared (§4ao).
- **Lane C takes 30 and up**, and is the lane most likely to add several.

B renumbered nothing and A renumbered everything, and the tie was broken by counting:
`invariant 28` appeared 15 times across 7 files on B's side and 7 times across 6 on A's,
so moving A's was the smaller edit. **That is the tiebreak — cost, not seniority.** Note
what it implies: the write-ups now read `§4ao` → invariant 29 and `§4ap` → invariant 28,
out of order with each other. That is fine and it is not worth a renumber to fix; the
invariant list is ordered by when a rule was written, and the letters by when a write-up
was.

### 3. `reportChecks()` — the anchor conflicts every time, and adding is wrong

`tools/selftest_layout.php` ends with one line holding two numbers:

```php
reportChecks(testIsMysql() ? 1738 : 1715);
```

Every branch that adds a check changes it, so it conflicts on every merge. **Resolve it
by running the suite and using what it reports, never by adding the two branches' deltas
together.** The A/B merge is the first time that instruction was tested, and the
interesting part is that **the sum was right**: from a shared base of `1657 : 1634`, A
reported `1679 : 1656` and B `1716 : 1693` — deltas of +22 and +59 — and 1634 + 22 + 59
is 1715, which is what the merged run then reported.

That is not a reason to start summing. It was right because neither branch changed the
*shape* of a section the other had counted, which is a property of those two diffs and
not of arithmetic; the same addition was wrong the last time this line was merged, when
#21 closed while a section describing the coercion it removed was still open. A sum is a
prediction you can check in one command. Check it. The MySQL figure is the SQLite one plus
the engine-only section (23 today) — if that section did not change, the difference is
the same difference.

### 4. `reviewed-decisions.md` — recount, and the merge will not warn you

The count line and the table have now disagreed three times. The first two were a branch
carrying its own total across a merge. **The third is worse and is the reason this item
moved up the list: two branches wrote the *same* wrong line, so git merged it with no
conflict at all.** A and B each closed one item against a base with three open, so both
wrote `48 done, 2 open`, and an identical change on both sides is not a conflict — it is
agreement. The file merged clean and said 48 when it was 49. Nothing in the standing gates
reads that line, so it would have shipped.

Recount from the Status column mechanically, on every merge, whether or not the file
conflicted:

```
awk -F'|' '/^\| *[0-9]+ *\|/ {st=$5; gsub(/^ +| +$/,"",st);
  if (st ~ /^\*\*Done\*\*/) d++; else if (st ~ /^Part done/) p++; else if (st ~ /^Open/) o++}
  END {print "done="d" part="p" open="o}' docs/reviewed-decisions.md
```

The 51st item is the unnumbered policy at the top of that file and has no row, so it is
counted as neither — two branches have each quietly folded it into a different total.

## Sequencing, in one line each

1. **Lane 0** — deploy to `lbm-test/`, read the isolation card, check the two things #44
   added, then walk the Builder: drag, resize, hide, unhide, edit a price, Undo it,
   publish, and look at the sign.
2. ~~**Lanes A and B**~~ — landed, §4ao/invariant 29 and §4ap/invariant 28.
3. **Lane C** — alone, against the suite as it now is, starting by recounting the 29.

Lane C is not blocked by lane 0. But a browser defect found after three more branches
have landed is a defect in a bigger diff, and finding it now costs an afternoon rather
than a bisect.

## What is deliberately not a lane

- **A publish-level version history.** Weighed and deferred in ADR-0010, with the trap
  that sinks a naive attempt recorded there: `LayoutStore::publish()` sweeps the pooled
  asset rows an old layout referenced, so a snapshot stored by asset id restores into
  blank blocks. It is a table with its gate, an interaction with the stamp and the lock,
  and a decision the owner has not asked for.
- **Automating the mutation runs.** #49's numbers were measured by hand, one file at a
  time. Making that repeatable is inside #50's scope, not beside it.
- **Deleting the retired branches.** One command, no code, and it needs rights this
  repo's automation does not have — the delete has been attempted and refused with
  `HTTP 403`. `reviewed-decisions.md` holds the shas and, more usefully, the one-liner
  that asks git which refs add anything to `main` instead of trusting a list of names.
  Nine of sixteen added nothing.
