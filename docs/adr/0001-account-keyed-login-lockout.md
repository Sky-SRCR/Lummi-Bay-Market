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
- The lockout columns are added by an idempotent `ALTER TABLE` run from the
  pre-auth login/reset pages only — never from `db_connect.php` — so the public
  viewer poll continues to run no migrations.
