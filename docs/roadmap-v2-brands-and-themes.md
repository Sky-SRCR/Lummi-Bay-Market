# Roadmap v2 — Brands, Workspace Themes, and the Builder workbench

Status: **all five steps built; none deployed.** Settled in a grilling session on
2026-08-13; the decisions are recorded here and the one that reverses a previous
decision is in [ADR-0011](adr/0011-typography-and-colour-belong-to-a-brand.md).

v1 is **live and running**, and `lbm-test/` is still standing. That is the whole
of why this roadmap looks different from
[`roadmap-multi-display.md`](roadmap-multi-display.md), which was written as one
big cutover ending in a 22-step deployment visit. There is no backlog to clear
here: each step below is deployable on its own, and each can be rehearsed against
`lbm-test/` before it touches the shop — which closes the one gap
[`BUILD-REFERENCE.md`](BUILD-REFERENCE.md) §5 lists as covered by no suite, *"the
rehearsal against a database that genuinely lags the repo."*

Three documents were stale as of this being written and were corrected in step 1:
the status line of `roadmap-multi-display.md` (PR #3 is merged and deployed), the
"what is left is the live deploy" framing in [`work-lanes.md`](work-lanes.md), and
the "re-run against the live install after the deploy" note in
[`browser-pass.md`](browser-pass.md) — which is owed *now*, not after something.
`CLAUDE.md`'s row for that file said the same thing and was corrected with them.

## Why

The installation stopped being one store. It drives signs in several venues on
one property — restaurants, bars, a casino floor — each already branded in the
physical world, each wanting its own colours and its own logo on its own boards.
One set of colours across all of them is not the shared-look feature decision C
was protecting; it is a defect that reaches every screen.

Separately, and for a different audience: the Builder's own chrome is a stack of
up to five horizontal bars with a properties panel floating over them, and a
role-gated toolbar that shows a basic account four buttons and six gaps. That is
a workspace problem, not a sign problem, and the two must not be solved with the
same word — see the language note below.

## The language, first

**Brand** is what a customer sees on a TV. **Workspace Theme** is what an
employee sees while working. They share no storage, no audience, no admin
surface and no vocabulary, and the word *theme* never appears on anything that
reaches a Screen. Both terms, and **Brand palette**, are defined in
[`CONTEXT.md`](../CONTEXT.md) with `_Avoid_` lines pointing at each other.

This is not tidiness. The two pickers land on the same page in step 5, and the
sentence "I changed the theme and the sign looks wrong" has to be unambiguous
when it is said at nine at night by somebody standing in a restaurant.

## Decisions

The sixteen settled in the grilling session, compressed. Where one reverses or
narrows an existing decision, the ADR is named.

| # | Decision |
|---|---|
| 1 | Two nouns — **Brand** (sign-facing) and **Workspace Theme** (application-facing). Never one word. |
| 2 | A Brand is **named and reusable**; several Displays share one. Content, layout, canvas size and screen name tag stay per-Display. ADR-0011. |
| 3 | A Brand carries the six branded block-type standards, a **palette**, a logo asset and a default canvas background. |
| 4 | The palette is **offered as swatches, never enforced**. A block with its own colour keeps it. |
| 5 | The Brand's logo is a named asset the Builder can place in one click, and it is shown inside the Builder's Brand control. **The Viewer never draws it automatically** — a fixed corner and size cannot be right for both a landscape menu board and a portrait specials board (ADR-0004). |
| 6 | **Brand edits are immediate** across the venue, as Brand Standards has always been. **Brand assignment is staged**: picking one repaints the Builder canvas in the browser and is written by Publish, on the path `applyBackground()` already takes. |
| 7 | The Brand Standards lock refusal **narrows** from "anyone editing anything" to "anyone editing a Display using this Brand", naming the Display and the holder. Narrows ADR-0007's amendment. |
| 8 | `displays.brand_id` is **`NOT NULL`**. Deleting a Brand in use is **refused**, naming the Displays. Ids are never reused. |
| 9 | Upgrade **seeds Brand #1** from today's global `block_styles`, named after `SITE_NAME`; every existing Display points at it and no sign moves. Fresh setup cannot finish without a first Brand. |
| 10 | A Workspace Theme has **12 roles** — 6 chrome, 5 status, 1 canvas-overlay. Status colours and the selection outline/handles **are** themable, at the admin's discretion. |
| 11 | **The canvas is never themable.** `#builder-canvas` and everything drawn on it belong to the Brand. Enforced by a check, not by convention. |
| 12 | A Workspace Theme applies to **every signed-in page**, not only the Builder. `login.php` and the Viewer are unaffected by construction. Site Branding becomes **the default theme**. |
| 13 | Admins create themes; everyone selects one. Admins own their own legibility policy — contrast is **warned about, not refused**. |
| 14 | The **theme picker always renders un-themed**, and "use the store default" works from any state. A preference you cannot reverse is not a preference. |
| 15 | The Builder becomes **option B**: left palette, centre canvas, right rail with a resting state. Align tools become *Arrange* in the rail; the align bar retires; the properties panel is docked, never floating. |
| 16 | `lastPublishDescription()` changes to `n/j/y g:ia` in **all three** places that use it — the Builder, the Admin Panel's Displays tab, and the refused-publish message. One sentence, one format. |

## Steps

Each step leaves the app coherent and is independently deployable, so work can
stop after any of them. There is no CI or deploy pipeline — every change reaches
the sign by hand — so **step order is deployment order**, exactly as in v1.

The furniture is built before the things that sit in it. Step 2 rebuilds the
Builder's chrome and leaves room for a Brand control at the top of the left
column and a Workspace Theme item in the gear; steps 4 and 5 fill them. The
alternative order — Brands first — would build the Brand control twice, once in
the old toolbar and once in the new chrome.

Neither slot is stubbed out in the markup. A caption over an empty box reads as
something that failed to load, so what step 2 left is a comment saying where the
control goes and why — which is also why the left column's rule is emitted only
when there is something above it to divide.

### Step 1 — Publish stops writing what the Brand owns · size S · risk Medium

**Done — 2026-08-13. Written up as §4az; it is invariant 32.** What follows is the
plan as it was written, with the two things it got wrong marked. It has not been
deployed: like every step here it is deployable on its own, and it changes nothing
anybody can see, which makes it the safe one to send first.

**This is a bug fix, and it lands alone.** `applyTextStyles()` (`builder.php:1828`
— the plan called it `applyBlockStyle()`, which is not a function in this codebase)
writes the shared standard into a node's inline style; `serializeBlock()`
(`builder.php:3126`) reads those same inline styles back out and publishes them
into the element's own `font_*` columns. Every publish already bakes the global
standards into every branded element's own row.

It is invisible today, because the values are identical for every Display and the
branded-subtype branch ignores those columns at render. Brands would make it two
live faults:

- **A stale-brand fossil.** Change a block's subtype from `price` to `free` a
  month later and it inherits whichever Brand was selected at its last publish —
  possibly a venue it was never part of. Nothing says so.
- **A phantom undo step.** `snapshotCanvas()` serializes through
  `serializeBlock()`, so switching Brands changes the snapshot string although no
  element changed. The next real edit pushes a step recording a difference nobody
  made — what `BUILD-REFERENCE.md` calls "invariant 27 the other way round: a
  history holding an entry for something that never changed."

**What changes.** For a branded subtype, publish sends nothing for the six
typography fields, and the server stores the documented defaults rather than
whatever it was sent. The undo snapshot stops carrying brand-derived values.

> **Corrected while building.** The plan said the server would "leave those
> columns untouched — the *absent means untouched* property `BrandStyles` already
> promises for its own table." It cannot: a publish deletes the layout and
> re-inserts it, so there is no row left to leave alone, and for an admin publish
> the ids are reassigned as well. `BrandStyles::DEFAULTS` is what lands instead —
> that module's own answer to "what a field is when the row does not say", and the
> `schema.sql` column defaults written down. Not NULL, because `intval(null)` is 0
> and a `font_size` of 0 read by anything that does not expect it is an invisible
> block.
>
> The plan also under-specified the condition. "For a branded subtype" is not
> enough: with no `block_styles` row stored for a type, both renderers fall back to
> the element's own columns, so stripping them there would blank the block on the
> sign. `BrandStyles::paints()` asks the renderers' whole question, and both of
> `LayoutStore`'s writers ask it — `copyLayout()` too, which the plan did not
> mention and which would otherwise have carried a pre-fix fossil onto a new sign.

**Done when** a mutation run over the touched files kills the new checks with the
`assertion` grade, and `selftest_builder_undo.js` proves that re-applying block
styles does not move the snapshot. Also corrects the three stale documents named
at the top of this file. — **All three met.** The undo suite asserts the stronger
form: after a Brand switch, `commitUndoStep()` returns `false`, so the phantom step
is not there to record. The layout suite gained a section proving the row, the
`copyLayout` case, and the half-seeded install; the colours suite proves an
unreadable *Brand* colour is no longer copied onto every block that wears it.
`selftest_layout.php` went from 1,805 checks to 1,830, the undo suite from 122 to
137, the colours suite from 43 to 47.

Gates: `php -l`, `selftest_layout`, `check_invariants`, `check_doc_numbering`,
`selftest_builder_undo.js`, `selftest_builder_editing.js`, and
`php tools/mutate.php` over every `lib/` file touched.

### Step 2 — The Builder workbench · size L · risk Medium

**Done — 2026-08-13. Written up as §4ba.** Not deployed; like every step here it
goes on its own. What follows is the plan as written, with what it got wrong
marked.

Option B, and the nav from the sketch. No schema, no new data — pure chrome, which
is what makes it safe to land before the features that use it.

**Layout.** A left column replaces the add-block buttons in the top bar, in two
parts divided by a rule. The properties panel becomes a **docked right rail** — a
sibling of the canvas in a flex row, so the overlap that started this is
arithmetically impossible rather than nudged out of the way, and the panel stops
looking like a window that can be dragged. The rail keeps a resting state
("Select a block to edit it") rather than vanishing. Align tools move into the
rail as an *Arrange* group and `#align-bar` retires.

**The left column, above the rule** — *which sign this is, and the way off it*:
*(an empty Brand slot)* and `⇄ Switch sign`, when more than one is switchable.

**The left column, below the rule** — *what you can put on it*: grouped
add-block items (Layout · Text · Media · Brand assets).

**The rule is a boundary in the markup, not only a line.** Everything below it is
an editing control and is not emitted for a read-only Builder; the block above it
always is. `Switch display` sits outside the read-only branch today for a reason —
somebody looking at a sign they cannot edit still has to be able to leave it — and
a person who cannot edit still needs to know which venue they are looking at. A
read-only Builder therefore keeps a left column carrying those two things and
nothing under them, which is more than it has today.

**Nav**, left to right: `LUMMI BAY MARKET` (text, no logo) │ display title · tag ·
dimensions · `OFF` when off · `View ↗` │ spacer │ `Undo` `Publish` │ `jporter`
`Sign Out` `⚙`. The role chip leaves the Builder. The gear becomes the
account-and-settings menu and holds the role as text, Asset Library, Admin Panel
(admins), Help, and *(an empty Workspace Theme slot)*.

**Why the Brand and Switch are not in the nav.** They were, in the first draft of
this spec, and both had to be squeezed to icons to fit a bar that was being
cleared on purpose. A bare `⇄` is not a clean bar, it is an unreadable one — it
failed the first time it was shown to the person who specified the bar. The left
column has width for words, and the two controls read as a sequence there that
they never did in the nav.

**Canvas footer**, a thin strip along the bottom of the canvas column — between
the palette and the rail, so both stay full-height columns and the three-column
structure survives. It carries the two things that are facts rather than
controls-you-reach-for:

- left: the zoom controls — `Fit` · `100%` · `−` · `+` · `79%`
- right: `Last published by jporter 9/13/25 11:42am`, muted

**Zoom had nowhere else to go.** It lived in the old control bar, and neither
option B as pitched nor the nav sketch has a slot for it — an omission in the
mockup rather than a decision. The footer is where it belongs anyway: it is a
property of how you are looking at the canvas, so it sits with the canvas rather
than with the sign's identity.

The publish line is here rather than in the gear because of what
`builder.php:635` says it is for — *"is what I'm looking at live, and did somebody
else change it?"* — a question that has to answer at a glance or not at all.
Behind a click it stopped answering; in the nav it crowded the bar the sketch
was clearing. The footer is quiet and still on screen. It is drawn for
**read-only Builders too**, which is the case it was originally written for:
somebody who cannot edit still needs to know whether the sign moved under them.
The `pub-state` node and the script that refreshes it after a publish move here
with it.

**`lastPublishDescription()`** changes format to `n/j/y g:ia`, in `lib/displays.php`
and therefore in all three places that print it.

**What this costs, honestly.** `help.php` describes the old toolbar and must be
rewritten. Three assertions in the node suites are about the old chrome and must
change — `selftest_builder_readonly.js:93` (the align bar is emitted only when
editable), `:109` (the id list including `control-bar`), and
`selftest_builder_undo.js:745` (`inspector.style.display === 'none'` when put
away, which becomes "the rail shows its resting state and no block control is
populated" — a stronger check than a display property). **A rewritten check is a
check that has to be re-proved able to fail**, which is #50's whole lesson; each
one gets a mutation run rather than a green line.

**Done when** all six node suites pass against the new chrome, `help.php` matches
what the page now looks like, and the browser pass is re-walked against
`lbm-test/` — it is the only verification here a person does, and this step
changes every page it describes. — **Two of three met.** The suites pass (editing
175 → 182, undo 137 → 139, layout 1830 → 1831) and `help.php` is rewritten
throughout: the palette, the gear, the canvas footer, *Arrange*, and "Inspector"
retired as a word the app never used on screen. **The browser pass has not been
re-walked, and this step is the one that most needs it** — nothing in this repo
can see a three-column layout fail to be three columns.

> **Two corrections while building.** The rail's resting state is not only the
> sentence the plan specified: for an admin it carries the **canvas background**.
> That control was in the retired bar and the plan gave it nowhere to go — the
> left column is *what you can put on the sign*, and a background is not
> something you put on. Same class of omission as the zoom controls, and found
> the same way.
>
> The plan named three suite assertions to rewrite. There were **five**: the two
> in `selftest_builder_readonly.js` and the one in `selftest_builder_undo.js` it
> listed, plus `selftest_builder_uploads.js`'s three publish-line checks (the
> wording changed to *Last published by*) and `selftest_layout.php`'s grep for
> the old `d-pub` markup. Each was hand-mutated and seen to fail before its new
> form was kept.

Gates: `php -l`, `node --check` over the extracted script blocks of `builder.php`,
all six node suites, `check_invariants`, `check_doc_numbering`, mutation runs over
the rewritten checks.

### Step 3 — Brands: data and the admin surface · size L · risk **High**

**Done — 2026-08-13. Written up as §4bb; it is invariant 33.** Not deployed, and
**the rehearsal against a copy of live data has not been run** — that is this step's
own stated gate and it needs a person and a database this container cannot reach.
What follows is the plan as written, with what it got wrong marked.

Risk lives here, as it did in v1's phase 1, and for the same reason: this step
converges schema on a live database that is driving signs.

**Schema.** A `brands` table (name, palette roles, logo asset, default canvas
background). `block_styles` re-keyed on `(brand_id, block_type)`.
`displays.brand_id` `NOT NULL`. Every statement goes into `signageSchemaPlan()`
**with its gate**, and the gate answers `null` rather than `false` when it cannot
tell — an ungated `schemaTry()` re-runs on every signed-in page load, and an
`ALTER` locks the table every sign's layout lives in.

**Convergence seeds Brand #1** from the existing global `block_styles` rows,
names it after `SITE_NAME`, and backfills every `displays.brand_id`. An install
that never makes a second Brand renders identically to today. This is the piece
that must be **rehearsed on a copy of live data** before it goes near the shop.

**A new module, `lib/brands.php`,** becomes the only writer of `brands`, and
`BrandStyles` is re-scoped rather than replaced — it keeps the two properties it
already promises (*absent means untouched*, *every stored value renders*) and
gains a Brand to be about. Nothing outside those two modules writes either table.

**Admin Panel → Display Branding** becomes a list of Brands, each opening to its
standards, palette, logo and default background. Deleting a Brand in use is
refused, naming the Displays. The lock refusal narrows to the Brand and says
which Display and who holds it.

**Setup** gains a required first Brand — fresh installs only; `setup.php` deleted
itself on this host in 2026-08-07 and the live path is convergence.

**Done when** a database built from `schema.sql` has nothing left for convergence
to do, a rehearsal against a copy of live data leaves every sign rendering
identically, and the Displays tab shows each Display's Brand. — **Two of three
met.** The first is asserted by the MySQL arm of `selftest_layout`, which *did not
run*: this container has no MySQL server, so that arm's expected check count is
derived from the standing delta rather than observed. The Displays tab shows and
sets each Display's Brand. **The rehearsal has not been run and is owed before this
goes near the shop** — `tools/rehearse_phase1.php` grew the checks for it (the
primary key's columns out of `SHOW KEYS`, both backfills as rows, `brand_id`
`NOT NULL` on both tables, and the `RESTRICT` rule), and running it needs a copy of
live data.

Gates: the standing set, plus `selftest_layout` sections for the seeding and the
narrowed refusal, plus `php tools/mutate.php lib/brands.php` and over
`lib/brand_styles.php`.

> **Four corrections while building.**
>
> The plan said nothing about the *name*. `lib/brand.php` already held a class
> called `Brand`, and what it held was the navigation bar's colours — a Workspace
> Theme by `CONTEXT.md`'s own vocabulary. It is `SiteChrome` now, renamed in its own
> commit before any of this, because doing it inside the schema diff would have made
> the risky half unreadable. Step 5 takes it further and keeps its four method names.
>
> The plan said `block_styles` re-keyed and left the gate unspecified. The gate has
> to read the key's **columns**: every table has a `PRIMARY`, so an existence test
> would either never run or run every request, and a second `DROP PRIMARY KEY, ADD
> PRIMARY KEY` does not fail harmlessly — it rebuilds the table. `SchemaFacts` gained
> `indexColumns()` and `needsPrimaryKey()`, and the catalogue read now orders by
> `SEQ_IN_INDEX`.
>
> The plan did not mention that a Brand needs a *use case* module. Creating one
> spans two tables — the row, and the six `block_styles` rows without which its
> typography form is an `UPDATE` matching nothing that reports success — so
> `lib/brand_admin.php` holds the transaction, the fourth module of the shape
> `DisplayAdmin`, `AccountAdmin` and `PasswordResetCompletion` share.
>
> And it did not anticipate the palette needing a shape. Six ordered slots, not named
> roles: a role is an instruction, and an enforced palette is the option ADR-0011
> rejected. `CONTEXT.md`'s "Brand palette" entry was corrected to say so.

### Step 4 — Brands in the Builder · size M · risk Medium

**Done — 2026-08-14. Written up as §4bc; it adds no invariant, and the write-up says
why.** Not deployed; like every step here it goes on its own. What follows is the plan
as written, with what it got wrong marked.

The Brand slot at the top of step 2's left column gets its control: the venue's
logo and `Salmon House ▾` for admins, the same without the chevron for basic
accounts and for read-only Builders — they should know which venue they are
building for and be unable to change it.

Picking one **repaints the canvas in the browser and writes nothing**. Publish
writes `brand_id` beside the layout, refusing the whole publish on an invalid
value rather than half-applying, on `applyBackground()`'s path. The palette
appears as swatches above every colour control in the rail — marquee text and
background, section, free text, canvas background. The palette item "Venue Logo"
drops an image block already linked to the Brand's asset.

Because step 1 landed, cycling Brands moves no undo snapshot and bakes nothing
into an element row.

**Done when** switching Brands in the Builder changes only what is drawn,
publishing writes the Brand that was selected at that moment, and a read-only
Builder renders its Display's Brand while offering no control to change it. —
**All three met, and each is asserted rather than observed.** The switch sends no
request at all (counted, not inspected), repaints the branded block and leaves the block
that owns its typography alone, and records no undo step. A publish carries the Brand
that was staged; one naming something that is not an id, or a Brand somebody deleted
while the tab sat open, is refused whole rather than half-applied. The read-only checks
went to `selftest_builder_readonly.js`, which owns that premise and the walker over this
file's conditionals: it now knows `$canPickBrand` is derived from the role *and* the
lock, so the Brand menu can never be emitted there, while the control itself can.

Gates: the standing set, all seven node suites, and a new
`selftest_builder_brands.js` covering the preview, the read-only case and the
publish payload. — **Met, with the read-only third of it in the other suite** for the
reason above. `selftest_builder_brands.js` is 121 checks; `selftest_builder_readonly.js`
went from 45 to 65; `selftest_layout.php` from 1,975 to 2,051.

> **Five corrections while building.**
>
> **There is no section colour control.** The plan named the palette's four homes as
> "marquee text and background, section, free text, canvas background". A section carries
> a background *image* with a `|fit` suffix and has never had a colour of its own, so
> there are four.
>
> The plan said "repaints the canvas in the browser" and left the mechanism open. It has
> to be `restoreCanvas(snapshotCanvas())` — the pair the undo history already uses —
> because invariant 32 is why a branded block's own typography is *not* on the node, so
> `applyTextStyles()` needs an element to fall back to and only the serializer produces
> one.
>
> The plan did not mention that **the publish has to re-read the Display**: the rows
> about to be written will be read under the Brand this publish is setting, and that is
> the Brand that decides whether a typography column is the Brand's to paint. The
> `copyLayout()` rule, arriving by the one door that had not needed it.
>
> The plan did not anticipate the state where there is **no Brand at all** — a database
> whose convergence has not run. No control is drawn and no `brand_id` is sent; sending 0
> would be refused and would turn a lagging schema into a sign nobody can publish to.
>
> And the plan said nothing about the Brand's **default canvas background**, which the
> Builder deliberately does not apply — it is what a *new* Display starts from. Unsaid,
> that is the first question somebody asks when a venue's colours appear and the canvas
> behind them does not change, so the page says it.

### Step 5 — Workspace Themes · size M · risk Low

**Done — 2026-08-14. Written up as §4bd; it is invariant 34.** Not deployed; like every
step here it goes on its own. What follows is the plan as written, with what it got wrong
marked.


A `workspace_themes` table and a `users.workspace_theme_id`. Today's
`branding_config.php` values become a seeded theme named "Store default", and
`SiteChrome::navBg()` and its three siblings stop reading the file directly and start
answering *"the theme this request should use"*. Every page keeps calling the
same four methods. **The account is passed in, never read from `$_SESSION`** — a
static that reaches for session state is the hidden coupling this codebase has
spent its history removing.

Twelve roles: nav background, nav text, accent, work area, panel, panel border,
five status roles covering all seven of today's banners, and the canvas selection
outline and handles. **Never `#builder-canvas` or anything drawn on it** —
enforced in `tools/check_invariants.php`, so a later change cannot quietly extend
the reach.

**Admin Panel → Site Branding** gains theme creation with a live preview and a
contrast **warning** — "Nav text on Nav background is hard to read" — that does
not block the save. The picker in the Builder's gear is rendered in fixed,
un-themed colours and offers "use the store default", because the control for
changing your theme is otherwise drawn in your theme.

**Done when** a signed-in person's chosen theme paints every page they can reach,
the sign-in page and the Viewer are provably unaffected, and a theme whose every
colour is identical still leaves its own picker legible.

Gates: the standing set, plus a `check_invariants` rule that no canvas colour
resolves through a theme, plus `selftest_layout` sections for resolution and
fallback. — **Met, and the rule turned out to have two halves.** No role but
`--selection` may appear in a rule that paints the canvas, *and* `--selection` may appear
nowhere else: a role that is allowed in one place is a fact only if the other places are
checked too. It fails three ways and each was seen to fail, and the third — the
canvas-selector list going stale — found a defect in the check itself. There is an eighth
node suite as well, `selftest_builder_theme.js` at 110 checks, because the picker sits on
a page that may be holding unpublished work and no other premise here covers that;
`selftest_builder_readonly.js` went from 65 to 68 and `selftest_layout.php` from 2,068 to
2,221.

> **Six corrections while building.**
>
> **Thirteen roles, not twelve.** The six chrome roles the plan names omit the navigation
> border, which is one of the four colours a shop can already set from Site Branding. A
> theme that could not hold it would repaint the live nav the moment anybody chose one —
> decision 9's "no sign moves" has an application-side twin.
>
> **No seeded theme.** The plan had today's `branding_config.php` values "become a seeded
> theme named Store default". A seeded row is a *copy* of that file, and the first Site
> Branding edit makes the copy disagree with it while still being called the default —
> the two-readers defect `SiteChrome::load()` already refuses one layer out. So the store
> default is the file plus the documented defaults and not a row, which also means
> convergence inserts nothing and cannot repaint anybody.
>
> The plan said the four accessors "start answering the theme this request should use"
> and did not notice that **three callers want the opposite**. `all()`, `unreadable()`
> and the Branding form ask about the store's colours *as configured*, and through the
> layered read the form would have shown an admin their own theme as "what is there now"
> and saved it over the shop's. `SiteChrome::configColor()` is that door.
>
> The plan did not say **how** a page reaches a role, and the answer is forced: CSS custom
> properties, because the picker is in the Builder's gear and the obvious implementation
> — post, reload, come back painted — throws away unpublished work. It is also what makes
> decision 11 checkable at all.
>
> The plan said the picker renders un-themed and stopped there. **The way to it has to as
> well**: a picker you cannot reach is not legible, and the way to it is a grey glyph on a
> themed nav bar. `$gearNeedsChip` asks the contrast rule at render, so the gear gets a
> fixed chip exactly when it needs one.
>
> And it did not anticipate that the contrast rule would end up **written in two
> languages**. A warning that appears while somebody drags a picker cannot ask the server
> per frame. Only the arithmetic is duplicated; the threshold and the words are printed
> from `Color::READABLE_RATIO`, and neither copy is checked against the other — both are
> checked against WCAG's own fixed points.

## Risks and watch-items

- **Step 3's convergence is the one that can empty a shop.** `brand_id NOT NULL`
  on a live `displays` table, and a re-key of `block_styles`, both while signs are
  polling. Rehearse on `lbm-test/` against a copy of live data; back up first;
  the ALTERs lock tables the layouts live in.
- **A Brand edit is still instant and still irreversible**, now across a venue.
  Nothing in this plan makes it undoable, and the ADR says so rather than leaving
  it to be discovered.
- **`php -l` cannot see the floor.** This container runs PHP 8.4 and the shop runs
  8.2 (`ea-php82`, pinned explicitly). Invariant 31's denylist catches seven
  constructs and twenty functions and prints that it is a denylist. A construct
  not seen before is still worth looking up.
- **Section letters are allocated by `check_doc_numbering.php`, not counted.** It
  prints the next free letter on every run; four branches once answered that
  question by counting, and all four answered it the same way. No letter is
  written down here on purpose — the tool refuses a document that cites a
  write-up which does not exist yet, which is how it caught the first draft of
  this very line.
- **Invariant numbers cannot be reserved** the way a section letter can
  ([`work-lanes.md`](work-lanes.md) item 4). The four this plan implies —
  publish sends no brand-owned typography; no canvas colour resolves through a
  theme; nothing outside `lib/brands.php` writes `brands`; nothing outside
  `lib/workspace_themes.php` writes `workspace_themes` — take their numbers when
  they land, not here. The first landed with step 1 and took **32**; the third
  landed with step 3 and took **33**; the other two are still unnumbered, and a
  branch cut beside another one has to read that item before writing one down.
- **The browser pass is a list, not a receipt.** Step 2 rewrites every page it
  describes, and five of its seven defects were things a screen did not *say* —
  the category no harness here is pointed at. It gets re-walked.

## Out of scope

- **Undoing a Brand edit.** A draft-and-apply step on a Brand is a real feature
  and a different one; assignment is stageable, editing is not.
- **Per-block override of Brand Standards.** The six branded types exist so that
  a price looks like a price; an override switch returns the app to the state
  this project is fixing.
- **A Viewer-drawn logo.** Decision 5, and the reasoning is in ADR-0011.
- **Themable canvas.** Decision 11, permanently.
- **Venue as an entity.** A Brand stands for a venue today. If a venue ever needs
  facts of its own — an address, opening hours a sign reads — that is a new noun
  and a new conversation.
