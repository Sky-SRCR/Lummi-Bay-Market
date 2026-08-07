# Undo is a step stack in the Builder, not a history of publishes

## Status

accepted

## Context

Until now the standing rule was that no undo exists anywhere in this app.
Publishing overwrites (ADR-0006), and every safety net built since has been a
*refusal* rather than a reversal: a stale layout stamp refuses a publish, an edit
lock refuses a second editor, a wrong-shaped value is refused rather than coerced.
Refusing works when the danger is two people's work colliding. It does nothing at
all for the ordinary case — one person, alone, who drags a price off a section, or
deletes the wrong block, or types over a description they wanted.

Today the only answer to that is to reload the Builder, which throws away
everything not yet published, or to rebuild the block by hand. Both are worse than
the mistake. Hide exists partly because of this: it is the only reversible removal
in the app, which is why people reach for it instead of Delete.

Two quite different features answer to the word "undo", and they were weighed
together:

1. **Undo the last few edits on the canvas**, before publishing. Browser-side, one
   tab, nothing stored anywhere.
2. **Undo a publish** — restore a Display to a layout that was previously on the
   sign. Server-side, a new table, a history per Display.

## Decision

**Undo is (1): a bounded stack of canvas snapshots held in the editor's browser
tab, taken back one step at a time.** The Builder gets an Undo button and Ctrl+Z.
Nothing is written to the server, no schema changes, and a publish is still
irreversible.

**How many steps is an admin setting**, on the Settings page, default 5, range 0 to
20. `0` removes the button, the shortcut and the snapshots — a real off switch, not
a hidden button with the machinery still running.

**What counts as one step** is the part that decides whether the feature is worth
having:

- Every change is **measured, not announced**. `commitUndoStep()` snapshots the
  canvas and compares it against the last committed snapshot; identical means no
  step was taken. A control operated with no effect therefore costs nothing, and an
  Undo that visibly does nothing — the fastest way to teach somebody a button is
  broken — cannot happen.
- A control **the person is operating** commits on `onchange`, never on `oninput`.
  The browser already knows when an edit is finished. That is what makes dragging a
  colour picker one step rather than forty, and a piece of text one step rather than
  one per keystroke.
- A change **the code makes on its own** — create, delete, a finished drag, an
  upload that landed, a modal saved — commits at the end of the function that made
  it. A drag commits on interact.js's `end`, not in `handleMove`, which fires
  continuously.
- **Ctrl+Z inside a text block or a form field is the browser's own**, working a
  character at a time. Only once the caret leaves does the whole edit become one
  step that the Builder's Undo takes back.

**Restore goes through `renderSection()` and `renderBlock()`** — the same two
functions that build the canvas from the server's layout. There is one idea of how
an element becomes a node, so a block type added later is restorable the day it is
added rather than the day somebody remembers to teach a second renderer about it.

**The snapshot is the publish payload plus two fields.** `serializeCanvas()` was
lifted out of `publishCanvas()` so both read the same description; a snapshot adds
`snap_content` (what a block is showing even when a library entry is supplying it)
and `snap_manual_path` (where its uploaded file is), because publish is entitled to
leave both out and a restore is not. Neither is sent to the server.

**The canvas background is out of scope, and says so.** An uploaded background lives
in a `<input type="file">` that no snapshot can put back. Restoring the colour but
not the picture would be an undo that quietly lies about what it undid.

## Considered options

- **Undo a publish (version history)** — rejected *for now*, not rejected in
  principle. It is the feature that actually answers "publishing overwrites", and it
  is a much larger one: a new table through `signageSchemaPlan()` with its gate, a
  decision about how it interacts with the layout stamp (ADR-0006) and the edit lock
  (ADR-0007), and one trap that is easy to miss — `LayoutStore::publish()` runs
  `discardPooled()` and deletes pooled asset rows the new layout stranded, so a
  snapshot stored by asset id restores into blank blocks. Any future attempt must
  denormalise content into the snapshot or teach the collector about the history.
  Worth doing; not worth conflating with this.
- **Capture the canvas *before* each change instead of measuring after** — rejected.
  It is the obvious design and it has two defects. Every control that changed nothing
  spends a step, so Undo starts doing nothing at all for a press or two. And a call
  site somebody forgets loses that change from the history entirely; measuring after
  means a missed call site folds the change into the next step, which is wrong but
  recoverable rather than silently absent.
- **A `MutationObserver` on the canvas**, so no call site can be forgotten —
  rejected. It is genuinely the more complete design, and it cannot be run by any of
  this repo's self-test harnesses: they are hand-rolled DOMs with no observer, no
  timers and no event loop. An undo whose correctness rests on machinery no test can
  drive is an undo nobody can change safely afterwards. Explicit commits, driven and
  asserted one at a time by `tools/selftest_builder_undo.js`, were preferred.
- **A redo stack** — rejected for this change, and only for scope. Undo was what was
  asked for. It is worth revisiting: an Undo pressed once too often is currently
  itself irreversible, which is a small version of the problem this whole decision
  is about.
- **Persisting the stack** (sessionStorage, or the server) — rejected. Reloading the
  Builder re-reads the layout from the server, so the steps no longer describe
  anything that happened; offering them would be offering to paste an old canvas over
  a sign somebody else may have published to in the meantime. The history ending with
  the page is the honest behaviour.
- **A fixed number of steps in the code** rather than a setting — rejected. The
  owner asked for it to be settable, and the setting doubles as the off switch if the
  feature ever misbehaves on the shop floor, where nobody can edit JavaScript.

## Consequences

- The standing rule changes from "no undo exists anywhere in this app" to "no undo
  exists for anything that has been published". `CLAUDE.md` and
  `docs/BUILD-REFERENCE.md` say so.
- Publishing is still the point of no return, and the layout stamp is still the only
  thing standing between two editors. Undo does not soften either.
- Every function that changes the canvas now has to end in a commit, and a new one
  that does not is a change Undo will fold into the following step. Invariant 25 in
  `docs/BUILD-REFERENCE.md` states it, and the undo self-test drives each capture
  point so that removing one turns a check red.
- `serializeCanvas()` now has two callers. A block type added to one is added to
  both, which is the point of extracting it — but it also means a change to that
  function is a change to what reaches the sign.
- Each step holds a full copy of the canvas as a JSON string in the editor's tab.
  At the default of 5 that is negligible; the 20 ceiling is what stops a much larger
  number being typed into the setting.
