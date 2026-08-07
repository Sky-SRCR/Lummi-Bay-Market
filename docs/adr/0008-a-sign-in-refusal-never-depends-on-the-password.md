# A sign-in refusal never depends on the password

## Status

accepted

## Context

The login page decided in this order: locked out, wrong password, closed,
suspended, in. Every one of those branches prints a different sentence, and three
of them sit *after* `password_verify()`.

So on a suspended or closed account the page answered "Incorrect username or
password" for every wrong guess and something else entirely for the right one.
That is a password oracle on the accounts where a password was supposed to have
stopped being worth anything — and passwords are reused, so a guess confirmed
against a retired clerk's account is a guess to take to their email.

It was reachable by anyone who knew a username, and the accounts it worked on are
the ones nobody is watching: a suspended account generates no successful logins to
look wrong, and a closed one never will again.

The wording itself was not the mistake. "Your account has been deactivated,
please contact your manager" is the right sentence for a suspended clerk standing
at the till, and ADR-0001's generic refusal is the right sentence for a guesser.
The mistake was that *which* sentence you got was a function of the password.

## Decision

**Every question that does not depend on the password is answered before the
password is read at all.**

Closed, suspended and locked-out are properties of the account. `LoginAttempt`
settles all three first and returns; only if none of them applies does
`password_verify()` run, and its two outcomes are the generic refusal (shared with
an unknown username, per ADR-0001) and being let in.

The order inside that first group is also decided, and for reasons that are not
symmetry:

1. **Closed** before **suspended**, because closing clears `is_active` too
   (invariant 14) — the other order would send a retired employee to ask a manager
   to switch an account back on, which is the one thing this app will not do.
2. Both before **locked out**, because "please wait 15 minutes" is advice that has
   to come true, and on an account nobody can ever sign in to it would not.

A refused sign-in on a closed or suspended account writes nothing: no failed
attempt, no lockout. There is nothing to protect on an account that cannot be
signed in to under any password, and a counter that moved for one password and not
for another would be the same oracle wearing a different hat.

The rule lives in `lib/login_attempt.php`, which holds no PDO — every statement is
`AccountStore`'s — so what is under test is the ordering rather than a database.

## Considered options

- **One generic sentence for every refusal.** The obvious answer, and it does
  close the oracle. Rejected because it leaves a retired employee with no true
  information anywhere: the closed account gets "incorrect username or password",
  the password reset silently does nothing for them (step 1 requires
  `is_active = 1`), and there is no other channel — this app has no support inbox.
  The sentence would be a lie told to the only person it reaches most often.
- **A generic sentence plus a standing hint under the form** ("if you have been
  told your account was switched off, ask your manager"). Rejected: it still never
  tells a *closed* account the thing it needs to know, which is that waiting and
  asking will not help, and it adds a sentence about suspension to the screen every
  member of staff sees every morning.
- **Keeping the order and softening the wording** so that the closed and suspended
  sentences read more like the generic one. Rejected outright: as long as the
  sentence changes at all, it is still a bit that flips on a correct password.
  Wording cannot fix an ordering.

## Consequences

- **A narrower oracle is accepted in place of the password one.** Somebody who
  knows a username can now learn that it is suspended or closed without knowing
  the password. ADR-0001 already accepted a weaker form of the same thing (only
  real accounts ever lock out), and what this one leaks names accounts that cannot
  be signed in to at all. A password confirmed is worth carrying to another site;
  "this former employee's account is closed" is not.
- Active accounts are unaffected: they answer the generic refusal for a wrong
  password and for a username nobody has, exactly as before.
- A closed or suspended account can no longer be locked out, because it can no
  longer accrue a failure. Nothing depended on it being able to.
- Anything added to the sign-in path inherits the rule, and the way to break it is
  to move a check below `password_verify()` — which will look like tidying. The
  self-test asserts the *sameness* of the message across a right and a wrong
  password rather than its wording, so a reworded sentence stays green and a
  reordered check does not.
