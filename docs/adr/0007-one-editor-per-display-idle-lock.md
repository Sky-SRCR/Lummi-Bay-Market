# One editor per Display, held by an idle-expiring lock

## Status

accepted

## Context

Publishing replaces a Display's layout with whatever the publishing browser holds,
and there are no drafts (ADR-0006). Two people editing the same Display therefore
race, and the loser's work disappears without a trace. ADR-0006 chose to catch this
at publish time and explicitly rejected locking; that call was revisited, because a
refusal *at publish* still means the second editor's work is wasted — they find out
only after doing it. A lock says no at the door instead.

The hard part of any lock is release. The app can tell when someone opens the
Builder; it cannot be told when they walk away — a closed lid, a dead battery, or a
crashed browser sends nothing. And presence is not work: a Builder left open on a
back-office monitor would otherwise hold a sign hostage all afternoon, while
somebody genuinely mid-edit — reading, thinking, on the phone, typing a long
description — must not have the lock pulled out from under them.

## Decision

**A Display has at most one editor at a time.** The first account to open it in the
Builder holds an **edit lock**; a second account may open it read-only, with a banner
naming the holder and since when. Read-only means no moving, no editing, no publish.

The lock is held by **activity, not presence**. The Builder reports the time of the
last real interaction — a click, drag, keystroke, or edit, not mouse drift. After
**15 minutes** without one, the lock lapses even if the tab is still open. At 13
minutes the holder is warned in the Builder and one click keeps it. Leaving the
Builder or closing the tab releases the lock immediately when the browser cooperates;
the idle window covers when it does not.

Returning to a lapsed tab silently re-takes the lock if nobody else claimed it. If
someone did, publish is refused with a message naming the current holder — the
unsaved edits stay on screen but cannot be published.

**Admins can force-unlock**, behind a confirm stating that the current holder loses
unsaved work.

The publish-time staleness check from ADR-0006 stays, covering the sequence a lock
alone misses: hold the lock, go idle, lose it, a colleague edits and publishes, then
the first tab publishes over them.

## Considered options

- **Publish-time check only** (ADR-0006 as first written) — insufficient on its own.
  It protects the layout but not the person's time; being told "reload and redo it"
  after twenty minutes of work is a bad outcome when the collision was knowable up
  front.
- **Manual release only** — "you hold it until you click *done editing*." Simplest to
  build, but one forgotten click blocks the sign until an admin intervenes, and that
  gets discovered at 6am when prices need changing.
- **Lock on tab presence rather than activity** — rejected. It cannot distinguish a
  forgotten tab from a working editor, so it either frees locks too aggressively or
  never frees them.
- **A short idle window (5 minutes)** — rejected as the default. Frees locks quickly
  but interrupts anyone doing slow, deliberate work, which is most of the real
  editing on a pricing sign.

## Consequences

- Two people cannot edit one Display, by design. Different Displays in parallel are
  unaffected — the lock is per Display.
- A read-only Builder view is new surface: every editing control must respect it, in
  a file that is largely inline JavaScript.
- The lock needs a heartbeat endpoint and two columns on the Display (holder, last
  activity). Nothing schedules cleanup — a lapsed lock is decided by comparing
  timestamps on read, so no cron is required.
- 15 minutes is a judgement call, not a law. It is one constant, changeable if the
  store's rhythm turns out different.
