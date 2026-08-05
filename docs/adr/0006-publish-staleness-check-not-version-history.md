# Publish refuses stale writes; there is still no version history

## Status

accepted

## Context

Publishing replaces a Display's elements wholesale with whatever the publishing
browser holds — `DELETE` the Display's rows, re-insert from the submitted layout.
There is no draft and no history. Two people with the Builder open on the same
Display therefore overwrite each other silently, last publish winning, and a tab
left open since the morning will restore the morning's layout over everything done
since. This is true today with a single sign; more Displays and more editors make it
likelier to be hit.

The obvious full answer — drafts, version history, rollback — is a project in its
own right and would delay multi-Display support substantially.

## Decision

Add a **staleness check** and nothing more. Each Display records when its layout was
last published and by which account. The Builder captures that stamp when it loads a
Display; publish submits it back; if the Display has moved on since, the publish is
**refused** with a message naming who changed it and when, prompting a reload.

The same two columns give the admin Displays list a "last published by" column at no
extra cost.

## Considered options

- **Leave publishing untouched** — rejected. Silent, unrecoverable loss of a
  colleague's work is a bad failure mode, and the fix is a column comparison. This
  project already rebuilds the publish path, so it is the cheapest it will ever be.
- **Drafts, version history and rollback** — deferred, not rejected. It solves
  overwrites *and* mistakes, but it is a separate project; bundling it would hold up
  multi-Display.
- **Locking a Display while someone edits it** — rejected. Locks need expiry,
  override, and a story for the tab someone closed at 5pm; a check at publish time
  needs none of that.

## Consequences

- The guard prevents *silent* overwrites. It does **not** provide undo: once a
  publish succeeds, the previous layout is gone. Anyone expecting recovery will be
  disappointed, which is precisely why this is written down.
- A refused publish is a real interruption — the editor reloads and re-applies their
  change. That is the intended trade: an annoyance instead of a loss.
- Version history remains the natural follow-on and would build directly on these
  columns.
