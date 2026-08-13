# What can be worked on at the same time

**All four lanes have landed.** #33 as §4ao, #44 as §4ap, #50 as §4aq — and with #50 the
numbered audit list closed. **Lane 0, the browser pass, was walked on 2026-08-12/13 and
closed too**: ten sections, seven defects, §4as through §4ax. What is left is the live
deploy, and that is not a lane — it is the 22-step visit in
[`docs/roadmap-multi-display.md`](roadmap-multi-display.md).

So this file has stopped being a plan and become the thing it was always more useful as:
what a branch cut beside another one has to agree about before it starts. The round of
parallel work before this one produced four branches that each solved something and each
had to be adjudicated afterwards, and the collisions were not where anybody expected.
They were not in the app. They were in the files every branch touches.

**Read items 2 and 4 below before starting a branch beside another one.** They are the
two the A/B round corrected, and both were corrected by something going wrong rather
than by argument: an invariant number cannot be reserved the way a section letter can,
and the count line in `reviewed-decisions.md` does not conflict when it should.

## The short version

| Lane | Item | Run it | Why |
|------|------|--------|-----|
| ~~**0**~~ | ~~Browser pass on `lbm-test/`~~ | **Walked 2026-08-13** — §4as–§4ax | It was never an audit item and it found seven defects the audit did not contain. Two needed a rendered page over real data (§4as, §4at). The other **five were things the screen did not say** (§4au–§4ax) — the category no harness here was ever pointed at, and one of them needed this host to be noticed at all. |
| ~~**A**~~ | ~~#33 — an account with no signs can still write the shared library~~ | **Landed** — §4ao, invariant 29 | Touched `crud.php`, `api.php`, `lib/grants.php` — not `lib/assets.php`, which is what this table predicted. |
| ~~**B**~~ | ~~#44 — no timezone, so "editing since 2:15pm" followed the host's `php.ini`~~ | **Landed** — §4ap, invariant 28 | Touched `lib/branding.php`, `admin_panel.php`, `lib/displays.php`, `config.php`, and — beyond what was predicted — `db_connect.php`, `lib/login_attempt.php`, `lib/server_report.php` and the new `lib/store_clock.php`. Still nothing A touched. |
| ~~**C**~~ | ~~#50 — 29 checks that cannot fail, 5 invariants with no automated check~~ | **Landed** — §4aq, invariant 30 | Ran alone, and the sequencing below was the reason: its whole subject was the two files A and B were both adding to. It ended up rewriting neither of them much and adding a third tool, `tools/mutate.php`. |

## What lane C confirmed about this file's one real prediction

C was ordered last on the argument that it *reads* the suite while A and B *write* it, so
running it in parallel would not have been a merge problem but a measurement one: the
count of hollow checks would have been stale before it was written down. That held, and
more sharply than expected — because the answer C arrived at is that **the count was
never recountable by hand at all.** It came from a two-hundred-check suite and the suite
is 1778 checks. What shipped instead is an instrument that answers the question per file,
on demand, which is the form a measurement has to take when the thing being measured
grows every week.

So the rule generalises past this round: **a lane whose deliverable is a number about
another lane's output should be asked whether the number is the deliverable.** Usually
the instrument is.

One thing C did *not* need, which was predicted: it barely touched
`tools/selftest_layout.php`'s existing sections. It added two new ones, corrected the
label on one check, and left the rest alone. The collision this file spent three
paragraphs on would have been the anchor line and nothing else.

## Lane 0, and what walking it actually cost

**The ordered list is [`docs/browser-pass.md`](browser-pass.md)** — what to click, what
should happen, and the real numbers (the lock lapses at 15 minutes, Undo defaults to 5
steps, the Viewer picks a publish up within 30 seconds). It now carries its own outcome
table at the top, and **it is still the list**: nothing about it was consumed by being run
once, because the live sign is a second install and every step applies there again.

This section used to argue that a browser was necessary. It is kept in the past tense
because the argument was right in a way worth being able to re-read, and wrong about which
part would hurt.

The prediction was that `interact.js` and the un-rendered CSS were the exposure —
`https://www.srcresort.com/lbm-test/` exists precisely so that could be found without
risking the live sign (`DEPLOY-SKIP.md` §E), and four commits of `builder.php` had never
been drawn by anything but a stub. **That paid**: step C's section-clipping defect is
exactly a resize handle over a real `overflow: hidden` box (§4as), and step D's is a paint
order that only a layout copied from the shop could produce, because every block on it
shares layer 1 (§4at).

**Undo, singled out here as the biggest stake, came through clean.** So did the Viewer, the
read-only Builder and both of #44's live-only questions. The stake was mis-sited: five of
the seven findings were not the JavaScript computing the wrong answer, they were the page
**not saying** something — a ceiling nobody was told (§4au), a lock that stopped a mouse and
nothing else (§4av, §4aw), a publisher and a time recorded for a year and shown in two
places, neither of them the Builder (§4ax). A harness that asserts what a function returns
is blind to that whole category by construction, and a person clicking is not. Worth
carrying into the live pass: **the question that found things was "does this page tell me
what just happened", not "did it compute correctly".**

The two things #44 added, both confirmed on the visit:

- **Admin Panel → Settings → Store Time Zone.** The default is `America/Los_Angeles`,
  so a deploy that never touches it is already right for this store. Changing it moved the
  "since" time by the right number of hours and the save redirects, and the picker offers
  region names only.
- **The database's session zone**, on the same card, which nothing had ever shown.
  `db_connect.php` asks every connection for `+00:00` and the request is suppressed
  rather than fatal, so a host that refused it says so *there and nowhere else*. It read
  `+00:00` on this host. Anything else means the app is back to two clocks, and what it
  costs is a creation date reading a few hours out.

Read **Settings → This Server** before signing in a second time — on the live host too.
It must say the install and database you meant. That check is the whole isolation
guarantee, and the sign-in that shows it to you is also the one that converges schema
on whatever database it found.

## What the A/B round proved, and what it did not

The test was footprint, not subject:

| File | A (#33) | B (#44) | C (#50) |
|------|:-------:|:-------:|:-------:|
| `crud.php`, `api.php`, `lib/grants.php` | ● | | |
| `lib/branding.php`, `admin_panel.php`, `lib/displays.php` | | ● | |
| `config.php`, `db_connect.php`, `lib/login_attempt.php`, `lib/server_report.php`, `lib/store_clock.php` | | ● | |
| `tools/selftest_layout.php` | ● | ● | ● |
| `tools/check_invariants.php` | ● | ● | ● |
| `tools/test_fixture.php`, `tools/mutate.php` | | | ● |
| `docs/BUILD-REFERENCE.md` | ● | ● | ● |
| `docs/reviewed-decisions.md` | ● | ● | ● |

**The conclusion held: A and B shared no application file.** Both ended up outside the
footprint this table predicted — A landed in `lib/grants.php` rather than
`lib/assets.php`, B in four files more than were listed — and neither drifted into the
other's. So the useful part is not the accuracy of the rows. It is that **a footprint
guessed from an item's subject is a guess**, twice over, and the guesses happened to be
safe. A predicts where a rule *belongs*, not where the item *is about*: #33's rule
belongs beside the other `Actor` predicates, and #44's belongs in a module of its own.

**What the table cannot predict at all is the last five rows.** All three lanes touched
`tools/check_invariants.php`, because adding an invariant means adding its mechanical
check, and all three touched the same two docs, because that is where a write-up goes.
Those rows are not a function of the subject, so no footprint table drawn from
application files will ever get them right. They are what items 1–4 are for.

**The A/B merge is where all four collisions actually appeared**, and three of them were
already wrong on paper before it: two branches claiming invariant 28, an anchor with two
values, and a count line that read `48 done, 2 open` in both trees when the truth after
the merge was `49 done, 1 open`. None of that is a mistake either branch made; each
counted correctly against a base that could only see its own item close.

C ran alone and so collided with nothing, which is the sequencing above working rather
than luck: it was ordered last because it reads the suite the other two were writing, and
what it found is that the number it had been sent to produce was not producible by hand at
all. The one shared line it did have to settle was the anchor, which is item 3.

## What a lane has to agree before it starts

The audit list is closed, so nothing here is allocated to a lane any more. It is kept
because the next change to this app will still touch these four things, and each has a
specific way of going wrong that has now happened at least once.

### 1. Section letters — assigned here, not discovered

`check_doc_numbering.php` prints the next free letter, and four branches cut from one
base all asked it and all got the same answer. Asking is right; asking *at the same
time* is the failure. So they are allocated in advance. Phase 4 now runs to §4aq, and
**`4ar` is the next free letter** — ask the tool rather than counting, and if two branches
start at once, write the allocation down here before either of them writes prose.

- ~~**Lane A (#33) wrote `4ao`.**~~ ~~**B (#44) wrote `4ap`.**~~ ~~**C (#50) wrote `4aq`.**~~

**This scheme worked exactly as designed, and it is the only one of the four that did.**
B wrote `4ap` while `4ao` was still unwritten and its push passed, because
`check_doc_numbering.php` checks letters for duplication and for dangling citations, not
for an unbroken run. A gap is harmless here. That asymmetry with the invariant numbers is
the whole of item 2.

A reservation is written without the `§` on purpose: a reservation is not a citation, and
`check_doc_numbering.php` reads every `§`-reference in every doc and fails on one that
points at a write-up which does not exist yet. That is the check doing its job — a
citation of a section nobody has written is what a guess looks like from outside. Add the
`§` when the write-up exists, which is why `4ao`, `4ap` and `4aq` carry one and `4ar`
does not.

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
- **Lane C took 30**, and only 30 — a check ships having been seen to fail (§4aq). It was
  expected to add several; what it actually needed was one rule about how a check earns
  its line, because the four mechanised greps are rules the *checker* enforces rather than
  invariants a reader has to hold. **31 is the next free number.**

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
reportChecks(testIsMysql() ? 1805 : 1782);
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

**And this line has a second reader now.** `tools/mutate.php` runs the suite once per
mutant and requires the unmutated run to pass first, so an anchor left stale does not
produce a wrong number — it produces a tool that refuses to start, saying the baseline
failed. Which is the right failure, and it is how the anchor got caught mid-pass in C:
every mutant was being graded "killed by the count anchor" until the baseline guard said
so.

### 4. `reviewed-decisions.md` — recount, and the merge will not warn you

The count line and the table have now disagreed three times. The first two were a branch
carrying its own total across a merge. **The third is worse and is the reason this item
moved up the list: two branches wrote the *same* wrong line, so git merged it with no
conflict at all.** A and B each closed one item against a base with three open, so both
wrote `48 done, 2 open`, and an identical change on both sides is not a conflict — it is
agreement. The file merged clean and said 48 when it was 49. Nothing in the standing gates
reads that line, so it would have shipped.

It now reads **50 done, 0 open**, which is a state worth being careful about rather than a
reason to stop recounting: a table with nothing open is one where the next status change
is somebody re-opening a row or adding a 52nd, and the same silent-merge shape applies to
both.

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

1. ~~**Lane 0**~~ — walked 2026-08-12/13 after waiting behind three branches. Seven
   defects, §4as–§4ax, five commits, each fixed and re-checked in the browser that found
   it.
2. ~~**Lanes A and B**~~ — landed, §4ao/invariant 29 and §4ap/invariant 28.
3. ~~**Lane C**~~ — landed, §4aq/invariant 30, alone and against a suite that held still.

Nothing was blocked by lane 0, and it stayed last three times running because it needs a
person, a deploy and a screen. **The cost of that ordering can now be stated instead of
guessed, and it was smaller than this file feared.** The prediction was an afternoon of
bisecting, on the grounds that a browser defect would be four commits deep in
`builder.php` plus everything since. No bisecting happened: all seven findings were
diagnosable by reading, because each was a door or a sentence that had never existed
rather than a behaviour that had regressed. Deferring a *first* look is not the same risk
as deferring a re-look — nothing had been right and then broken, so there was no point in
history to find. The lesson keeps only for a pass that has been walked once: from here on,
a browser defect in `builder.php` really is a regression with a date, and that is the
version of this cost the deferral argument was actually about.

## What is deliberately not a lane

- **A publish-level version history.** Weighed and deferred in ADR-0010, with the trap
  that sinks a naive attempt recorded there: `LayoutStore::publish()` sweeps the pooled
  asset rows an old layout referenced, so a snapshot stored by asset id restores into
  blank blocks. It is a table with its gate, an interaction with the stamp and the lock,
  and a decision the owner has not asked for.
- ~~**Automating the mutation runs.**~~ **Done in #50** — `tools/mutate.php`, one file at
  a time and deliberately not a gate, because `lib/layout_rules.php` alone is 187 mutants
  and half an hour of runs. What is *not* done is the sweep: six of twenty-six `lib/`
  modules, and the remaining twenty are a command each rather than a lane.
- **Deleting the retired branches.** One command, no code, and it needs rights this
  repo's automation does not have — the delete has been attempted and refused with
  `HTTP 403`. `reviewed-decisions.md` holds the shas and, more usefully, the one-liner
  that asks git which refs add anything to `main` instead of trusting a list of names.
  Nine of sixteen added nothing.
