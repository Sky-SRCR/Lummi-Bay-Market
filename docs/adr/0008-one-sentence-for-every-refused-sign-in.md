# One sentence for every refused sign-in

## Status

accepted. Extends ADR-0001, which decided the lockout this shares its columns with.

## Context

ADR-0001 was careful that "unknown username" and "wrong password" say the same
thing. It said nothing about the other two ways a sign-in can be refused, and
those two checks sat **after** `password_verify()` in `login.php`:

| what was typed | what came back |
|---|---|
| wrong password, live account | Incorrect username or password. |
| wrong password, suspended account | Incorrect username or password. |
| **right** password, suspended account | Your account has been deactivated. |
| **right** password, closed account | This account has been closed and cannot be used again. |

So a guesser working a list of usernames could not sign in to those accounts, and
did not need to: the change of sentence told them the password was right. The
accounts it told them about are the worst ones to be told about — the leaver's, the
suspended one, the one nobody is watching and whose password nobody will now
change — and people reuse passwords, so what leaks is not access to this app.

There are three possible orderings and each leaks something different:

- state **after** the password — leaks a *credential* (what we had)
- state **before** the password — leaks that a named account exists and is
  switched off, to anyone who types the name
- state **not disclosed at all** — leaks nothing, and tells a real member of
  staff nothing either

## Decision

**Nothing that is not a completed sign-in may say why.** One sentence,
`LoginGate::REFUSAL`, answers a wrong password, an unknown username, a suspended
account and a closed account alike:

> Incorrect username or password. If you are sure they are right, your account may
> have been switched off — ask your manager to check.

The second half carries the information the two removed sentences used to. It is
safe because it is *unconditional*: it says the same thing to a stranger, and a
stranger learns from it only what this paragraph already tells them.

Two supporting rules, because an identical sentence is not an identical answer:

- **An account that may not sign in still costs a password check.** Its refusal
  would otherwise come back a bcrypt sooner than a live account's — a hundred
  milliseconds is a signal, not a rounding error.
- **Only an account that could have signed in accrues failed attempts.** There is
  nothing to brute-force on one that cannot, and counting there would leak its
  state back out through the wait message.

The whole decision — every sentence, the ordering, the lockout arithmetic — lives
in `lib/login_gate.php`, decided in one function from facts handed in. `login.php`
looks up a row, applies the answer, and chooses no wording of its own.

## Considered options

- **State checks before the password** — rejected. It is a real improvement on
  what we had, and still an enumeration oracle: type a name, learn it exists and
  has been switched off. It would also contradict `reset_password.php`, which has
  always answered a suspended account exactly as it answers a stranger.
- **Keep both sentences, and email the account holder instead** — the textbook
  answer, rejected for this host. An email per attempt is precisely what the alert
  rate limit exists to prevent (BUILD-REFERENCE §4m), and the shared host's mail
  allowance would be spent by the first bot to arrive.
- **Say nothing extra at all** — rejected. A clerk whose account was switched off
  would read "Incorrect username or password", assume a typo, and try until the
  lockout, which is a worse fifteen minutes than a sentence.

## Consequences

- A person whose account was switched off is told to ask their manager rather
  than told which of the two things happened. The manager can see which on the
  User Management tab; the difference has never been actionable by the person
  locked out.
- **The wait message still marks a real, usable account.** Five deliberate wrong
  guesses distinguish "exists and could sign in" from "suspended, closed or
  unknown", because somebody locked out has to be told to stop trying. ADR-0001
  accepted that oracle; this narrows what it reveals to existence.
- **A username that exists is still measurable by timing**, because there is no
  hash to check for one that does not. Unchanged by this decision and not closed
  by it: closing it means verifying every attempt against a dummy hash, which is
  a constant to maintain and a decision of its own.
