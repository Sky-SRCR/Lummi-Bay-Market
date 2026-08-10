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
| [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md) | **What not to overwrite, upload or delete when files go to the server.** Read before any upload — the repo and the server hold different files, and uploading the tree reverts live branding and restores `setup.php` silently. |

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
- **PHP 8.2 is the floor** — stated by the store owner, 2026-08-10 (#51, §4k). Nothing
  in this repo has *observed* it: an earlier branch claimed it from Settings → This
  Server, which ships with the build #46's probe found undeployed, and Cloudflare hides
  the version from every header. So the floor rests on a person, and
  `ServerReport::phpVersionNote()` is the alarm if the host is ever moved or
  downgraded. Modern syntax is allowed; today no file uses any, which is what keeps the
  floor one line to lower again. **Be deliberate about spending it** — the failure mode
  changed direction with the answer. Guessing low only forwent syntax; a declared floor
  that turns out wrong is a parse error, and a parse error in a file a Screen loads is
  a blank sign in the shop. The 7.1-era fallbacks in `auth.php` and `.htaccess` stay
  for the reason they always did: they cover a host that moves, and what they prevent
  is silent.
- **Nothing that has been published can be taken back.** Publishing overwrites; a
  deleted Display, a swept asset row and a saved brand standard are gone. Prefer
  refusing a write to merging one. The **one** exception is the Builder's Undo
  (ADR-0010), which reaches back a few steps over the canvas in one browser tab
  *before* a publish — so a function that changes that canvas ends by committing a
  step (invariant 27), and everything on the server side of a publish is still
  written as if no undo existed, because there it does not.

## Before pushing

```
php -l <every touched .php>
php tools/selftest_layout.php
php tools/check_invariants.php             # the mechanical half of BUILD-REFERENCE §5
php tools/check_doc_numbering.php          # if a doc gained a section or invariant
node tools/selftest_builder_readonly.js    # if builder.php was touched
node tools/selftest_builder_uploads.js     # if builder.php was touched
node tools/selftest_builder_colors.js      # if builder.php was touched
node tools/selftest_builder_editing.js     # if builder.php was touched
node tools/selftest_builder_undo.js        # if builder.php was touched
node tools/selftest_viewer.js              # if viewer.php was touched
```

`check_doc_numbering.php` also prints the next free section letter. That is the
question every branch cut from the same base has to answer before it writes a
write-up, and four of them once answered it with the same letter — ask the tool
rather than counting, and note that it will not let a document cite a section
that does not exist yet, which is what a guess looks like from the outside.

`php -l` cannot see inline JavaScript, and `builder.php` is ~3300 lines of it —
which is why the standing gate is not enough on its own. Extract the `<script>`
block and run `node --check` over it after touching that file; the same goes for
`viewer.php`, which runs unattended on a TV where a thrown exception is a blank
sign rather than a stack trace anybody will read.

The six node suites go further and *run* that JavaScript, each under a premise the
others cannot hold — a page that may not edit, an admin uploading a file, an admin
opening a Display whose stored data is already wrong, an admin working the controls the
inspector puts on a block, an admin taking back the last thing they did, and a Screen
whose server has stopped answering or whose blocks have nothing in them — because the
defects they exist for are invisible to a parse: a lookup for a control the edit lock
took away, a `fetch` chain with no `.catch()`, a colour the CSSOM discarded in silence
and the publish payload then sent as black, a field a rebuild forgot to carry, a
`.catch()` that correctly ignores a dropped packet and therefore also ignored a failure
that was never going to stop, and a sentence written for whoever was building the
layout, drawn on the board a customer reads prices off.

- **`json_encode` is never called outside `lib/http_reply.php`.** It returns `false`,
  not a throw, and `echo false` prints the empty string — so a reply holding one byte
  of invalid UTF-8 left as a zero-length 200 and the sign kept its layout for good.
  Printed into a page's own `<script>` the same `false` emits `var X = ;`, which is a
  parse error that takes the whole block down. `HttpReply::json()` and
  `HttpReply::jsValue()` are the two doors; `tools/check_invariants.php` enforces it.
- **`htmlspecialchars` is never called outside `lib/markup.php`.** Its default flag set
  changed in PHP 8.1, so an unflagged call escapes single quotes on one host and not on
  another, and blanks the whole value on one byte of bad UTF-8. `Markup::text()` names
  both flags once. The other half of the same rule is contextual and is the one that bit:
  a value escaped for HTML and then dropped into a JavaScript string inside an event
  attribute is **not** escaped, because the HTML parser decodes the attribute before the
  JavaScript parser reads it. `Markup::jsInAttr()` is that case, passed as the whole
  argument and never spliced into one; the sentence belongs in a JS function.
  `tools/check_invariants.php` enforces both — and holds every echo on a page to one of
  five shapes safe by construction (a door, a literal, a safe call, a validated colour, a
  class constant whose declaration is a number), which is what makes *forgetting* to
  escape a failing check rather than something noticed later. There is no allow-list: a
  new line either says which shape it is or converts.
- **A colour in a `<style>` block is validated, never escaped.** Escaping is for a
  delimiter and a stylesheet has none — `#fff; } body { … }` survives `Markup::text()`
  intact and is a closed rule and a new one. The store's brand colours go through
  `Brand::navBg()` and its three siblings, which answer `#rrggbb` or the documented
  default because `Color::read()` decided. No page names a `BRAND_*` constant. The same
  holds one boundary further in, inside a `style` **attribute**: escaping stops a value
  ending the attribute and not the declaration, so a stored Brand Standards row is drawn
  through `BrandStyles::readable()`, never read out of the row. Both say which stored
  value they could not use — a substitute nobody is told about is #21 again.
- **A reply's status code comes from its `reason`, never from beside it.**
  `HttpReply` maps the app's own vocabulary of failure onto HTTP once. A code chosen
  at a call site is a second opinion, and it disagrees silently.
