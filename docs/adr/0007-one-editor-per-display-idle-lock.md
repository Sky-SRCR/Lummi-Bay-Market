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

## Amendment — the lock covers a Display's content, not only its publishes

The lock as first built guarded one action: publishing. An adversarial audit
found two ways round it, and neither needed any trickery.

The admin panel's Work Area hides and deletes elements directly, and checked only
the grant. So an admin could remove a block from under somebody who was twenty
minutes into an edit. The staleness stamp then refused that person's publish —
which protects the *layout*, and is exactly the outcome this ADR exists to
prevent on top of ADR-0006. "Reload and redo it" is the cost being avoided, and
the stamp cannot avoid it. Both writes now refuse while another account holds a
live lock, and say who holds it. They return an `ElementResult` rather than a
bool, because a bool could only say "wrong Display" and had nowhere to put a
second refusal.

Brand Standards is the harder case and the reason this is an amendment rather
than a bug fix. Its typography is shared by every Display (decision C in the
roadmap), is part of every snapshot, and is applied at render — so it reaches
every Screen on the next 30-second poll with **no publish at all**. A per-Display
lock cannot guard a table every Display reads. Three options were weighed:

- **Refuse while anyone else is editing anything** — chosen. Any held lock is
  somebody sizing blocks against typography that is about to change under them,
  and they would never be told. No new state, nothing to leak, nothing to wedge.
  The cost is real: a busy shop can block a brand change until a lock lapses,
  which takes at most one idle window.
- **A separate global Brand Standards lock** — rejected. More machinery, another
  lock that can strand, and it still would not stop a brand change landing on a
  Display somebody is mid-edit on, which is the actual harm.
- **Per-Display typography** — rejected here as out of scope. It is the fully
  consistent answer and it reverses decision C: a migration, a reworked admin
  surface, and the shared-look-across-signs property goes away unless copied by
  hand. Worth its own project if the store ever wants signs that look different.

A lapsed lock blocks nothing, in all three cases. That keeps "free and lapsed are
the same state" true everywhere, which is what lets this scheme work without a
cron.

One more thing this amendment fixes rather than decides: the lock's timestamps
are stored in UTC now. They were PHP local wall-clock strings, compared both as
ordered SQL strings and through `strtotime` — and local time repeats an hour every
autumn, which broke the lock in three directions for that hour. Nothing about the
15-minute rule changed; only the clock it is measured against.
