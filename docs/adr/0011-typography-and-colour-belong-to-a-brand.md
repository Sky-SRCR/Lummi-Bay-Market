# Typography and colour belong to a Brand, not to the whole install

## Status

accepted — reverses decision C of the multi-display roadmap, and supersedes the
"Per-Display typography — rejected here as out of scope" paragraph in the
amendment to ADR-0007.

## Context

Decision C of the multi-display roadmap made Brand Standards global: one row per
branded block type in `block_styles`, keyed by `block_type` and shared by every
Display. It was the right call for a store with one sign and then a handful, all
in the same building, all wanting to look alike. The shared look *was* the
feature — a price on the beer cave board matching a price on the deli board took
no work at all, because there was only one number to set.

ADR-0007's amendment revisited it and left it standing. It was weighing how to
stop a brand change landing under somebody mid-edit, and reached for the global
refusal — refuse the save while *anyone* holds a live lock on *any* Display —
because "a per-Display lock cannot guard a table every Display reads." It named
the alternative and priced it honestly:

> **Per-Display typography** — rejected here as out of scope. It is the fully
> consistent answer and it reverses decision C: a migration, a reworked admin
> surface, and the shared-look-across-signs property goes away unless copied by
> hand. Worth its own project if the store ever wants signs that look different.

That project is now wanted, and for a reason the original decision did not
anticipate: the installation is no longer one store. It drives signs in several
venues on one property — restaurants, bars, a casino floor — each with its own
identity, each already branded in the physical world. One set of colours across
all of them is not a feature there, it is a defect that reaches every screen.

## Decision

**Typography and colour belong to a Brand: a named, reusable identity that a
Display points at.** `block_styles` is keyed by Brand and block type instead of
by block type alone; `displays.brand_id` is `NOT NULL`; several Displays share
one Brand.

The grain is deliberately **per-Brand and not per-Display**, which is the part
that distinguishes this from what ADR-0007 rejected. Per-Display typography
would have destroyed the shared-look property outright — eleven signs, eleven
places to set the red. Per-Brand *rescopes* it: the look is still shared, and now
shared across the set of signs that ought to share it. A venue with three boards
has one red, edited once.

Three things follow, and each is part of this decision rather than a detail of
it:

**A Brand carries more than typography.** It holds the six branded block-type
standards, a **palette** of named colours, a logo asset, and a default canvas
background. The palette exists because typography alone brands only six block
types: a Display whose marquee, section backgrounds and free text still carry
hand-picked colours is not branded in any sense a person in the venue would
accept. The palette is **offered as swatches and never enforced** — a block with
its own colour keeps it. Refusing a colour because it is not on a list is a
different kind of rule from `Color::read()` refusing a value that is not a
colour, and the second is a safety property while the first is a matter of taste
that people route around by picking the nearest swatch and disliking the sign.

**The lock refusal narrows to the Brand.** ADR-0007 chose the global refusal for
a stated reason — "any held lock is somebody sizing blocks against typography
that is about to change under them, and they would never be told." That reason
was airtight when one table reached every sign and is simply false now: an
account editing a casino floor board cannot be affected by the Salmon House red
changing. The save is refused while a live lock is held on a Display **using this
Brand**, and the refusal names the Display and the holder. This is the one place
where this work makes the app less restrictive rather than more, and it is the
consistency ADR-0007 said it was deferring.

**Assigning a Brand is staged; editing one is not.** Picking a Brand in the
Builder repaints the canvas in the browser immediately and writes nothing; the
assignment reaches the database through Publish, on the path
`DisplayStore::applyBackground()` already takes for the canvas background —
"only ever reached for an admin publish". Editing a Brand in the Admin Panel
stays immediate across the venue, as Brand Standards has always been. The
asymmetry is deliberate: the Builder's whole contract is that nothing on it
reaches a sign until Publish, and a control that silently breaks that promise is
worse than one that behaves differently from a different page which never made
it.

## Considered options

- **Per-Brand, reusable** — chosen, above.
- **Per-Display values, no Brand entity.** The literal reversal ADR-0007 named:
  `block_styles` gains a `display_id` and nothing else changes. No new table, no
  assignment surface, and no picker — there would be nothing to pick. Rejected
  because it makes the venue the thing that cannot be expressed: the Salmon
  House red would live in three rows with nothing relating them, and the first
  time one drifted nobody would find out from the app.
- **Keep it global; brand by hand.** An admin sets colours per block on each
  sign. Rejected: this is what the store does today, and it is the reason this
  project exists. The six branded types exist precisely so that per-block
  colouring is not the answer.
- **Per-Brand and enforced** — the palette is the only source of colour anywhere
  on a Display; no free hex. Rejected. It guarantees consistency and it is the
  first thing anybody would ask to have turned off, at which point the enforcement
  has cost a feature and bought nothing.

## Consequences

**The upgrade moves nothing on any sign.** Convergence seeds Brand #1 from the
existing global `block_styles` rows, names it after `SITE_NAME`, and points every
existing Display at it. An install that never creates a second Brand behaves
exactly as it does today. A fresh install cannot finish setup without naming a
first Brand, because `brand_id` is `NOT NULL` and a sign with no identity has no
sensible rendering.

**A Brand in use cannot be deleted.** The delete is refused, naming the Displays
that point at it, in keeping with the standing rule that a write is refused
rather than merged. The alternative — reassigning those Displays to a default —
would repaint three signs in a restaurant within thirty seconds, on one click,
which is exactly the merge the rule exists to prevent. `brand_id` values are
never reused, for the reason `users` rows are never deleted: a stale pointer that
silently becomes somebody else's is a class of bug this codebase has spent its
history removing.

**Editing a Brand is still irreversible and still instant, now across a venue
rather than an install.** Brand Standards are read at render out of the snapshot,
so a change reaches every Screen of that Brand on the next thirty-second poll
with no publish. The blast radius is smaller than it was; it is not zero, and
nothing about this decision makes a brand edit undoable.

**One latent defect must be fixed before any of this lands.** `applyBlockStyle()`
writes the shared standard into a node's inline style, and `serializeBlock()`
reads those same inline styles back and publishes them into the element's own
`font_*` columns. Today this is invisible: the values are identical everywhere
and the branded-subtype branch ignores those columns at render. With Brands it
becomes two live faults — a block that changes subtype later inherits whichever
Brand was selected at its last publish, and switching Brands changes the undo
snapshot even though no element changed, which is invariant 27 the other way
round. Publish must send nothing for the six typography fields of a branded
subtype, because the Brand owns them. That fix lands on its own, before the
feature that would activate it.
