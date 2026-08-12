# A Display's canvas size is fixed at creation

## Status

accepted

## Context

Each Display carries its own canvas dimensions, which the Builder edits at and the
Viewer renders at. Allowing an admin to change those dimensions later looked
natural, but every element position in `canvas_elements` is an absolute integer and
both the Viewer canvas and its sections are `overflow: hidden` — so shrinking a
canvas silently makes anything outside it invisible on the Screen while the rows
still exist in the database. The app has no undo and no version history, so any
automatic repair would be unrecoverable.

The pressure to allow resizing came from an imagined hardware swap. That pressure
turned out to be misplaced: `scaleToFit()` in `viewer.php` already scales the whole
canvas to the Screen and centres it, so a 1920×1080 canvas fills a 4K 16:9 TV at
scale 2.0. Canvas size is a **design grid and an aspect ratio**, not a statement
about the Screen's pixel count.

## Decision

**Canvas dimensions are chosen when a Display is created and never change.** The
creation flow asks for the dimensions first; only then does it offer *blank* or
*duplicate*, and the duplicate list contains only Displays with exactly those
dimensions. Title, screen name tag, location, and background stay editable
afterwards; size is shown read-only.

A differently shaped Screen — portrait, or 16:10 — means a new Display built at
those dimensions. Copying a layout across different dimensions is deliberately
**not** supported.

## Considered options

- **Auto-clamp elements into the new bounds on resize** — rejected. Nothing ends up
  invisible, but a tuned layout returns as a pile along the canvas edges, with no
  undo.
- **Scale all coordinates proportionally on resize** — rejected. It looks correct
  and isn't: brand-styled blocks (`section_header`, `item_title`, `price`,
  `description`) read shared absolute pixel sizes from `block_styles`, which cannot
  scale per Display, so boxes would move while their type stayed put.
- **Copy a layout into different dimensions** — considered and dropped once the
  Viewer's scaling was understood. It only ever applied to an aspect-ratio change,
  and a wide three-column pricing layout has to be redesigned for a tall frame
  regardless of how the copy places things.
- **Letterbox versus stretch on a shape mismatch** — letterboxing (today's
  behaviour) is kept: dark bars on the mismatched axis, nothing distorted. Stretching
  would remove the bars by squashing every price and logo on the sign.

## Consequences

- Elements can never be orphaned outside their **canvas**, so the admin panel needs
  no out-of-bounds repair tooling. This originally read "neither the Builder nor the
  admin panel needs out-of-bounds warnings or repair tooling", which was wrong about
  the Builder — see the correction below.
- Replacing a Screen with a different resolution of the same shape needs no change
  at all — not a new Display, not an edit.
- A genuine shape change (landscape → portrait) means rebuilding that layout by
  hand. Judged acceptable: it is rare, and the redesign would be manual anyway.
- The Builder must handle canvases larger or taller than the editor viewport (a
  portrait 1080×1920 does not fit the current fixed-scale editor frame), so a
  zoom-to-fit control is part of making dimensions data-driven.

## Correction — a section is not the canvas (2026-08-12)

Found by dragging a resize handle during the browser pass (lane 0, step C), which is
the first time anybody had.

This decision identified the hazard precisely: shrinking a container that is
`overflow: hidden` makes anything outside it invisible on the Screen while the rows
still exist in the database, and with no version history an automatic repair would
be unrecoverable. It then closed the door on the **canvas** and concluded no
out-of-bounds warning was needed anywhere.

But the reasoning transfers whole to a **section**, and a section was never frozen:

- `.section-block` is `overflow: hidden` in `builder.php` and in `viewer.php`. Both
  clip, so the Builder is honest — the sign hides exactly what the Builder hides.
- A section resizes freely, by dragging a handle or by typing into the inspector's
  W/H. `applyDim()` clamps a section to the canvas, never to its own contents.
- A clipped child still publishes. `collectElements()` walks the canvas by class,
  with no visibility or bounds test.
- A clipped child cannot be clicked back: clipping removes it from hit testing, and
  the inspector's X/Y and Layer controls all act on the *selected* block. There is
  no layers panel to reach an unselectable one from.

So the hazard this ADR refused to accept for the canvas was reachable for a section
by the most ordinary gesture in the editor, and the layout that documented the
danger is the reason nobody looked: the door was closed, so the room was assumed
empty.

**What changed.** Nothing about the decision — canvas dimensions stay fixed, and
sections stay freely resizable. What was added is the warning this consequence said
was unnecessary: a section whose children extend past its edge carries a badge
naming the count (`applyClipWarning()` in `builder.php`), updated live while the edge
moves, on load for a layout that arrives already clipped, and after an Undo.

**What was deliberately not added**, on this ADR's own reasoning:

- **Auto-clamping children into the section** — rejected here for the canvas because
  a tuned layout returns as a pile, and it is no better one container down. Undo
  reaches five steps; a rearranged layout does not come back.
- **Refusing to shrink a section below its contents** — safe, and a worse editor. It
  makes a section un-shrinkable until every block inside it has been moved by hand,
  in an editor where moving a block is how you decide where it goes.
- **A toast instead of a badge** — the risk is not missing it in the moment. It is
  publishing half an hour later having forgotten, and a toast is gone by then.
