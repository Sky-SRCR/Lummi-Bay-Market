# Per-Display grants say which Displays; role still says how much power

## Status

accepted

## Context

With many Displays, an admin needs to control who edits which one. The app already
has exactly one axis of authority: the `users.role` column. An `admin` does
everything; a `basic` user may only add and edit content *inside existing sections*
and can never move the section layout — a rule enforced in the publish endpoint,
where a basic user's publish preserves all sections and replaces only the content
blocks.

The temptation was to express per-Display access as a richer permission system:
graded rights (edit-content / edit-layout / publish) per Display, or a per-Display
role replacing the global one.

## Decision

**A grant is a single flag: this account may edit this Display.** What an account
can do once inside is still decided by its existing global role. Publishing is part
of editing, not a separate right — a grant that cannot publish cannot reach a
Screen, which makes it useless.

Admins **implicitly hold every Display**; grants are meaningful only for `basic`
accounts. Ungranted Displays are hidden from a `basic` user entirely rather than
shown disabled; an account with no grants sees a plain "no displays have been
assigned to you yet" message in the Builder.

Enforcement is server-side in the resolution seam, `lib/display_request.php` on every write and on
`get_canvas_elements`, not merely absent from the Builder's picker.

## Considered options

- **Graded rights per Display** (edit-content / edit-layout / publish) — rejected.
  A permission matrix to design, build, enforce, and explain, for a store with a
  handful of accounts. The one plausible use — someone builds, an admin publishes —
  is not a workflow anyone asked for.
- **A role per Display**, replacing the global role — rejected. It changes the
  meaning of an existing column that login, user management, branding, and every
  authorization check already depend on, for no benefit at this size.
- **Admins granted explicitly like anyone else** — rejected. It contradicts what
  `admin` means everywhere else in this app, and it creates a way for an admin to be
  locked out of a Display they created. Restricting someone to one Display is
  expressed as a `basic` account with one grant.
- **Showing ungranted Displays greyed out** — rejected. Visible dead ends invite
  "why can't I click this?"; the admin panel is where the full list belongs.

## Consequences

- One admin cannot be kept out of one Display. If that is ever needed, that person
  becomes a `basic` account with grants.
- The existing section-layout restriction on `basic` users now applies per Display
  without any new code: their publish preserves that Display's sections.
- Two axes stay independent and easy to explain — *which* Displays from grants,
  *how much* from role.
