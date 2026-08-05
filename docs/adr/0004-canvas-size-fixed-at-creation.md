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

- Elements can never be orphaned outside their canvas, so neither the Builder nor
  the admin panel needs out-of-bounds warnings or repair tooling.
- Replacing a Screen with a different resolution of the same shape needs no change
  at all — not a new Display, not an edit.
- A genuine shape change (landscape → portrait) means rebuilding that layout by
  hand. Judged acceptable: it is rare, and the redesign would be manual anyway.
- The Builder must handle canvases larger or taller than the editor viewport (a
  portrait 1080×1920 does not fit the current fixed-scale editor frame), so a
  zoom-to-fit control is part of making dimensions data-driven.
