# Lummi Bay Market — working notes

A PHP + MySQL digital-signage builder for the store's pricing signs. No
framework, no build step, no test runner on the server; the live database is
edited in place and every change reaches the sign by hand.

## Read these first

| File | Why |
|------|-----|
| [`docs/BUILD-REFERENCE.md`](docs/BUILD-REFERENCE.md) | **The standing build contract.** Module map, the invariants every phase must preserve, and where later phases attach. **Re-read it after finishing each module.** |
| [`CONTEXT.md`](CONTEXT.md) | The domain language. Use these words — Display, Viewer, Screen, screen name tag, canvas, grant, edit lock — in code, comments and UI copy. |
| [`docs/roadmap-multi-display.md`](docs/roadmap-multi-display.md) | The phased plan and its current status. |
| [`docs/reviewed-decisions.md`](docs/reviewed-decisions.md) | **The 51-item list from the adversarial audit, with what was decided and what is left.** The numbering the owner uses. Two numbering traps are documented there; read them before quoting an issue number. |
| [`docs/adr/`](docs/adr/) | Decisions with their rejected alternatives. Don't re-litigate one without reading it. |
| [`HANDOFF.md`](HANDOFF.md) | Deployment facts: live URLs, credentials layout, what is and isn't in the repo. |

## Conventions

- **Data access lives in `lib/`.** Page scripts are thin adapters. Nothing
  outside `lib/layout_store.php` may write SQL against `canvas_elements`, nothing
  outside `lib/displays.php` against `displays`, and nothing outside
  `lib/assets.php` against `assets`.
- **Deep modules**: small interface, substantial implementation. A new query
  means a new method on the module, not a `$pdo` handed to a caller.
- **A new schema statement goes into `signageSchemaPlan()`, with its gate.**
  Convergence asks `information_schema` first and sends only what is missing, so an
  ungated `schemaTry()` re-runs on every signed-in page load — and an `ALTER` locks
  the table every sign's layout lives in. Add the gate and a check that the plan
  asks for it. Its gate is also what decides whether a failure emails an admin, so
  a gate that cannot tell must answer `null`, never `false`.
- **`ensureSignageSchema()` is called at the top of an entry point, never deeper.**
  DDL commits an open transaction in MySQL and says nothing about it, so converging
  from inside `LayoutStore::publish()` would commit half a publish and then report it
  failed. Anything that needs to converge because a query *already failed* calls
  `repairSchemaAfterFailure()` — the one guarded door, which refuses inside a
  transaction, refuses twice on one request, and refuses again for five minutes.
- **A change spanning two tables is one transaction, held by a use-case module.**
  `DisplayAdmin`, `AccountAdmin` and `PasswordResetCompletion` are the three, and they
  are the same shape on purpose: the module owns `beginTransaction`, writes no SQL
  itself, rolls back quietly, and returns a result the page turns into a sentence. A
  page doing the writes itself cannot roll back what already landed, so the message it
  prints is chosen by which line threw rather than by what is now true.
- **A form that submits state must say what it covered.** A browser posts only the
  ticked checkboxes, so an unticked box and a row or column that was never on the page
  are the same silence — and reading that silence as "remove it" is how the grant
  matrix saved over work it had never shown. Both axes are declared
  (`grants_accounts[]`, `grants_displays[]`) and the save only changes what it was told
  was on screen. A whole-table save also redirects afterwards (`flashMessage()` in
  `auth.php`), or F5 re-sends the old state over a page that has moved on.
- **A change to what somebody may reach frees what they are holding.** A revoked grant,
  a closed account, a demotion, a suspended account and a Display turned off all leave an
  edit lock the account can no longer even release — releasing goes through the seam that
  has just started refusing it. Free it in the same transaction, by holder, so a colleague
  on the same sign keeps theirs. Renaming a tag is *not* one of these: it changes the
  address, not who may edit, so the holder keeps the lock and is asked to reload.
  Freeing at the moment of the change only covers the paths somebody listed, so a lock is
  also never *honoured* for a holder who cannot sign in — the rule is in `LockState::isHeld()`
  **and** in `claimLock()`'s `WHERE`, because a read and a write that disagree about who
  holds a sign disagree silently. Then make sure the Builder *says so*: `applyLockAnswer()`
  ignores a failed heartbeat on purpose, and `LOCK_TERMINAL` is the fixed list of refusals
  that never come back — each with its own sentence, because what to do next differs.
- **The front door answers before it reads the password.** Closed, suspended and
  locked-out are properties of the *account*, so `LoginAttempt` settles all three
  before `password_verify()` runs — otherwise the sentence a person reads is a
  function of the password, and a guesser working a suspended account is told the
  moment they get it right (ADR-0008). Closed is asked before suspended because
  closing clears `is_active` too; both come before the lockout, because "wait 15
  minutes" has to be advice that comes true. Those two accrue no failed attempt
  either — a counter that moves for one password and not another is the same oracle
  in another form. And the session cookie's `Secure` flag is decided per request by
  `RequestScheme::isSecure()`, never set flat: a browser discards a `Secure` cookie
  that arrived over plain HTTP, so the flat version was a correct password landing
  back on a blank login form for ever, with nothing logged and nothing to read. A
  protection that cannot apply is reported, not applied. The page also runs **no
  DDL** — three `ALTER`s per sign-in POST made it the one piece of schema work a bot
  could reach without an account — and it checks a CSRF token before it looks at the
  account, **softly**, because a 403 on the front door answers "your browser is not
  keeping cookies" with the word *security* and no way forward.
- **PHP 7.1-compatible syntax** — the live server's version is unverified.
- **No undo exists anywhere in this app.** Publishing overwrites. Prefer
  refusing a write to merging one.

## Before pushing

```
php -l <every touched .php>
php tools/selftest_layout.php
node tools/selftest_builder_readonly.js    # if builder.php was touched
node tools/selftest_builder_uploads.js     # if builder.php was touched
```

`php -l` cannot see inline JavaScript, and `builder.php` is ~3300 lines of it —
which is why the standing gate is not enough on its own. Extract the `<script>`
block and run `node --check` over it after touching that file; the same goes for
`viewer.php`, which runs unattended on a TV where a thrown exception is a blank
sign rather than a stack trace anybody will read.

The two node suites go further and *run* that JavaScript, under opposite premises
— a page that may not edit, and an admin uploading a file — because the defects
they exist for are invisible to a parse: a lookup for a control the edit lock took
away, and a `fetch` chain with no `.catch()`.
