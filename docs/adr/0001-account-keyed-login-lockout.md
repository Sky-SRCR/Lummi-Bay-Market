# Account-keyed login lockout

## Status

accepted

## Context

The original brute-force guard counted failed logins in `$_SESSION`, which an
attacker trivially bypasses by not sending the session cookie — every request
starts a fresh counter. The login page is on the public internet, so the real
threat is internet-wide credential-stuffing bots guessing passwords against
known or guessed usernames.

## Decision

Track failed logins durably, keyed on the **account (username)**, in three
columns on `users` (`failed_attempts`, `last_failed_at`, `locked_until`). A
single 15-minute **attempt window** governs both the age-out of stale failures
and the lockout duration: 5 failures inside a window trips a 15-minute lockout;
a correct password is refused while the lockout is active; a successful login or
a completed password reset clears the state. Only usernames that match a real
account are tracked, and the failure message is identical for unknown users and
wrong passwords.

## Considered options

- **IP-keyed lockout** — rejected. All store staff sign in from the store's
  single public (NAT'd) IP, so one bot or one fat-fingered login would freeze
  the entire store, while a botnet with many IPs would sail past.
- **Composite account+IP key** — rejected as needless complexity for a
  five-person store; it keeps the shared-IP hazard without meaningfully
  improving on account keying against the actual bot threat.

## Consequences

- Accepts a mild account-level denial-of-service (someone who knows a username
  can lock that account) and a weak username-enumeration oracle (only real
  accounts ever lock). Both are low-value against a small internal tool and are
  mitigated by the short auto-expiry plus the password-reset recovery path.
- ~~The lockout columns are added by an idempotent `ALTER TABLE` run from the
  pre-auth login/reset pages only — never from `db_connect.php` — so the public
  viewer poll continues to run no migrations.~~ **Superseded.** It did keep the
  poll clean, but it left the columns arriving by the one piece of DDL in the app
  reachable with no account at all: three unconditional `ALTER`s per sign-in POST,
  each taking a metadata lock on `users`, fired by exactly the credential-stuffing
  bot this ADR is about. They are ordinary gated entries in `signageSchemaPlan()`
  now (invariant 19), which runs on authenticated pages only — so the viewer poll
  still runs no migrations, and neither does the login page.

  The cost of that move, which is real and small: on a database where the columns
  have never been added, the *first* sign-in happens without them and therefore
  without a lockout. `AccountStore::findForSignIn()` already answered for that
  state — signing in with no counter beats nobody signing in at all — and it lasts
  until the first authenticated page load, which is the Builder that same sign-in
  lands on.
- **The two stamps are stored in UTC.** They were local wall-clock, written with
  `date()` and read with a bare `strtotime()` — self-consistent, and still wrong,
  because the autumn fall-back replays an hour and a stamp from the second pass
  then sorts below one from the first. A `locked_until` further out than one window
  is not honoured at all: the only way one can exist is a row left in the old format
  on a server east of UTC, and serving it would mean a fifteen-minute lockout
  lasting the rest of the shift on the one page nobody can work around. The counter
  is left untouched when that happens, so the next wrong password locks the account
  straight back with a stamp this code wrote.
- **The login page verifies a CSRF token**, softly: a POST without one is refused
  before the account is looked at, so it can neither sign anybody into somebody
  else's account nor be used to run a stranger's failed-attempt counter up to the
  lockout.
