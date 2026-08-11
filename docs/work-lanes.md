# What can be worked on at the same time

**Lane B (#44) has landed** — §4ap, invariant 28. Two audit items are open, **#33** and
**#50**, plus a browser pass that nothing in this repo can do. This file says which of
them can run in parallel, which cannot, and what the ones that do run in parallel have
to agree about before they start. Read the two corrections in §2 below before starting a
branch: the invariant-number reservation did not survive contact with the checker, and
#44 landing changed what lane C is measuring.

It exists because the last round of parallel work produced four branches that each
solved something and each had to be adjudicated afterwards, and because the collisions
were not where anybody expected. They were not in the app. They were in three files
every branch touches.

## The short version

| Lane | Item | Run it | Why |
|------|------|--------|-----|
| **0** | Browser pass on `lbm-test/` | **First, before any of the below** | Four commits of Builder changes have never run in a browser. Nothing here can do it. |
| **A** | #33 — an account with no signs can still write the shared library | In parallel with B | Touches `crud.php`, `api.php`, `lib/assets.php`. Nothing B touches. |
| ~~**B**~~ | ~~#44~~ | **Landed** — §4ap, invariant 28 | Touched `lib/branding.php`, `admin_panel.php`, `lib/displays.php`, `config.php`, and — beyond the footprint predicted here — `db_connect.php`, `lib/login_attempt.php`, `lib/server_report.php` and the new `lib/store_clock.php`. Still nothing A touches. |
| **C** | #50 — 29 checks that cannot fail, 5 invariants with no automated check | **Alone, after A and B have landed** | Its whole subject *is* the file A and B are both editing. |

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

Read **Settings → This Server** before signing in a second time. It must say
`lbm-test` and `silverad_lummi_market_drive_thru_2`. That check is the whole isolation
guarantee, and the sign-in that shows it to you is also the one that converges schema
on whatever database it found.

## Why A and B really are disjoint, and C really is not

The test was footprint, not subject:

| File | A (#33) | B (#44) | C (#50) |
|------|:-------:|:-------:|:-------:|
| `crud.php`, `api.php`, `lib/assets.php` | ● | | |
| `lib/branding.php`, `admin_panel.php`, `lib/displays.php` | | ● | |
| `config.php`, `db_connect.php`, `lib/login_attempt.php`, `lib/server_report.php` | | ● | |
| `tools/selftest_layout.php` | ● | ● | ●●● |
| `tools/check_invariants.php` | ● | ● | ●●● |
| `docs/BUILD-REFERENCE.md` | ● | ● | ● |
| `docs/reviewed-decisions.md` | ● | ● | ● |

A and B shared no application file at all, and that held: B ended up touching four
files more than the row above predicted and none of them were A's. What the prediction
got wrong is the other column — B *did* touch `tools/check_invariants.php`, because
adding an invariant means adding its mechanical check, and the invariant-number
reservation in §2 had already conceded that. A footprint table drawn from the
application files will always miss that one, since the reason a lane touches the checker
is not the subject it is working on.

C shares no application file with either — and that is the trap, because C's
*deliverable* is `tools/selftest_layout.php` and `tools/check_invariants.php`, which is
where A and B both add their coverage.

Running C beside A and B is not a merge problem, it is a measurement problem. C has to
decide which of 29 checks cannot fail. Doing that against a suite two other branches
are adding to means the count is stale before it is written down, and a check A added
last night is a check C never looked at. **C reads the suite. A and B write it. Reads
go after writes.** B has now written 59 checks into it, so the 29 were already going to
need recounting before A lands.

C also has the one dependency that is not about files: its second half is *five
invariants with no automated check*, and §5 names them. That list has moved three times
now — the `schema.sql`-versus-`lib/schema.php` grep became a MySQL assertion, the
sanitiser grep became mechanical, and B added three mechanical rules while adding one
by-eye entry (`STORE_TIMEZONE`, where the key of a save and a read look identical to a
pattern). C should read the list it is actually meant to close, not the one that was
there when #50 was filed.

One thing for C specifically, found by B and deliberately not fixed by it:
`check_invariants.php` strips **PHP** comments before matching and not **HTML** ones, so
an `<!-- … -->` explaining why a line no longer makes a forbidden call fails the rule
against its own explanation. `codeWithoutComments()` works on tokens and the fix has to
decide about PHP embedded inside an HTML comment, which changes what all 25 rules see.
That is a measurement question, which is C's, not a fix to bundle into a lane that is
also adding rules.

## What A and B have to agree before they start

Three files are shared. Each has a specific way of going wrong that has already
happened at least once.

### 1. Section letters — assigned here, not discovered

`check_doc_numbering.php` prints the next free letter, and four branches cut from one
base all asked it and all got the same answer. Asking is right; asking *at the same
time* is the failure. So they are allocated in advance. Phase 4 now runs to §4ap,
because lane B wrote it. The reservations stand:

- **Lane A (#33) writes `4ao`** — still free, and the gap is fine.
  `check_doc_numbering.php` checks letters for duplication and for dangling citations,
  not for an unbroken run, so lane B taking `4ap` while `4ao` is unwritten costs
  nothing. That asymmetry with the invariant numbers is the correction in §2.
- ~~**Lane B (#44) writes `4ap`.**~~ Written.
- **Lane C (#50) writes `4aq`.**

Written without the `§` on purpose: a reservation is not a citation, and
`check_doc_numbering.php` reads every `§`-reference in every doc and fails on one that
points at a write-up which does not exist yet. That is the check doing its job — a
citation of a section nobody has written is what a guess looks like from outside — so
this file does not pretend to cite them. Add the `§` when the write-up exists.

Whoever lands second will find the letter after theirs unused, which is correct and
costs nothing. Run `php tools/check_doc_numbering.php` anyway.

### 2. Invariant numbers — likewise

**Corrected, because reserving these the way the letters are reserved does not work.**
The plan was A takes 28, B takes 29, C takes 30 and up. Lane B landed first, and
`check_doc_numbering.php` requires the list to run **unbroken from 1** — so writing 29
with no 28 in the file is not a deferred conflict, it is an immediate failure of your own
push. A gap in the section letters is harmless; a gap here is not, and that is the one
place the two schemes differ.

So the rule that actually works: **the branch that lands first takes the next number,
and the reservation only settles who renumbers.** As it stands:

- ~~Lane B~~ took **28** — a stored moment is UTC, read in one place, shown in the
  store's zone (§4ap).
- **Lane A takes 29** if it adds one (it probably does: "an account with no grant
  writes nothing" is a rule with more than one enforcement point). If lane A was cut
  before this and has already written 28, the checker will report two 28s on the merge,
  which is it doing its job — renumber to 29.
- **Lane C takes 30 and up**, and is the lane most likely to add several.

### 3. `reportChecks()` — the anchor conflicts every time, and adding is wrong

`tools/selftest_layout.php` ends with one line holding two numbers:

```php
reportChecks(testIsMysql() ? 1716 : 1693);
```

Every branch that adds a check changes it, so it conflicts on every merge. **Resolve it
by running the suite and using what it reports, never by adding the two branches'
deltas together.** The comment above that line already says why: a section can change
shape as well as size when the behaviour it describes changes, and two deltas summed on
paper give a number that never existed. The MySQL figure is the SQLite one plus the
engine-only section (23 today) — if that section did not change, the difference is the
same difference.

### 4. `reviewed-decisions.md` — recount, never carry across

The count line and the table have disagreed twice, both times because a branch carried
its own total across a merge. Recount from the Status column mechanically:

```
awk -F'|' '/^\| *[0-9]+ *\|/ {st=$5; gsub(/^ +| +$/,"",st);
  if (st ~ /^\*\*Done\*\*/) d++; else if (st ~ /^Part done/) p++; else if (st ~ /^Open/) o++}
  END {print "done="d" part="p" open="o}' docs/reviewed-decisions.md
```

The 51st item is the unnumbered policy at the top of that file and has no row, so it is
counted as neither — two branches have each quietly folded it into a different total.

## Sequencing, in one line each

1. **Lane 0** — deploy to `lbm-test/`, read the isolation card, then walk the Builder:
   drag, resize, hide, unhide, edit a price, Undo it, publish, and look at the sign.
2. ~~**Lanes A and B** — in parallel.~~ B has landed; **A can go now**, alone, with
   `4ao` and invariant 29.
3. **Lane C** — after A has landed, against the suite as it then is.

Nothing in lanes A–C is blocked by lane 0. But a browser defect found after three more
branches have landed is a defect in a bigger diff, and finding it now costs an
afternoon rather than a bisect.

## What is deliberately not a lane

- **A publish-level version history.** Weighed and deferred in ADR-0010, with the trap
  that sinks a naive attempt recorded there: `LayoutStore::publish()` sweeps the pooled
  asset rows an old layout referenced, so a snapshot stored by asset id restores into
  blank blocks. It is a table with its gate, an interaction with the stamp and the lock,
  and a decision the owner has not asked for.
- **Automating the mutation runs.** #49's numbers were measured by hand, one file at a
  time. Making that repeatable is inside #50's scope, not beside it.
- **Deleting the retired branches.** One command, no code, and it needs rights this
  repo's automation does not have. Both lists of shas are in `reviewed-decisions.md`.
