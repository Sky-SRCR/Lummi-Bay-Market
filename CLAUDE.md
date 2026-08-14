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
| [`docs/reviewed-decisions.md`](docs/reviewed-decisions.md) | **The 51-item list from the adversarial audit, with what each was decided to be.** All 50 numbered items are now Done — which closes the audit, not the app: nothing on that list was ever the browser pass. The numbering the owner uses. Two numbering traps are documented there; read them before quoting an issue number. |
| [`docs/browser-pass.md`](docs/browser-pass.md) | **The only verification in this project that a person does, walked in full on 2026-08-13 against `lbm-test/`.** Its outcome table is at the top: seven defects, §4as–§4ax, five of them things a page did not *say* rather than wrong answers a suite could have caught. Read it before assuming a green gate means a working screen. **Five re-walks are owed and the table at the top names them**: the live install (v1 is live), `lbm-test/` for the step-2 workbench, Display Branding, the Builder's Brand control, and Workspace Themes — the last three of which this pass has no step for at all. It is a list, not a receipt. |
| [`docs/adr/`](docs/adr/) | Decisions with their rejected alternatives. Don't re-litigate one without reading it. |
| [`HANDOFF.md`](HANDOFF.md) | Deployment facts: live URLs, credentials layout, what is and isn't in the repo. |
| [`docs/DEPLOY-SKIP.md`](docs/DEPLOY-SKIP.md) | **What not to overwrite, upload or delete when files go to the server.** Read before any upload — the repo and the server hold different files, and uploading the tree reverts live branding and restores `setup.php` silently. |
| [`docs/work-lanes.md`](docs/work-lanes.md) | **What can be worked on at the same time, and what two parallel branches have to agree before they start.** Read before starting a branch beside another one — the collisions are not in the app, they are in the three files every branch touches, and the section letter and invariant number are allocated there rather than discovered. |

## Conventions

- **Data access lives in `lib/`.** Page scripts are thin adapters. Nothing
  outside `lib/layout_store.php` may write SQL against `canvas_elements`, nothing
  outside `lib/displays.php` against `displays`, nothing outside `lib/assets.php`
  against `assets`, nothing outside `lib/brand_styles.php` against `block_styles`,
  and nothing outside `lib/brands.php` against `brands` (invariant 33), and nothing outside
  `lib/workspace_themes.php` against `workspace_themes` (invariant 34 — whose other
  half is that no chrome role is ever drawn on the canvas).
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
  `DisplayAdmin`, `AccountAdmin`, `BrandAdmin` and `PasswordResetCompletion` are the
  four, and they are the same shape on purpose: the module owns `beginTransaction`, writes no SQL
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
- **Two writes are not about a Display, and they are the two that had no check.** The
  access rule above works because almost everything is scoped to a sign, so the resolution
  seam can answer once for all of it. The asset library is one pool behind every sign and
  `uploads/` is one folder behind every library entry — nothing resolves a Display for
  either, so an account with no grant could fill both, one screen after the Builder told it
  no display was assigned to it. `Actor::holdsASign()` is the predicate and
  `Actor::NO_SIGN_REFUSAL` the sentence; `crud.php` and `api.php` are the doors, and both
  ask **before** `move_uploaded_file()`, which cannot be rolled back. It is the grant axis
  and not `openable()` on purpose: a sign switched off for the afternoon is still a sign
  somebody was given, and the refusal's wording has to stay true of everyone it refuses.
  Not sending a form somebody may not use is courtesy; the refusal behind it is the check.
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
- **PHP 8.2 is the floor** — stated by the store owner 2026-08-10, and **observed twice on
  2026-08-11** (#51, §4k, HANDOFF §7): 8.2.33 on the runtime card, and `ea-php82` pinned
  explicitly to `srcresort.com` in cPanel's MultiPHP Manager, against a system default of
  8.3. The pin is explicit rather than `inherit`, so the floor does not drift with the
  host; `ServerReport::phpVersionNote()` remains the alarm for the one route below it,
  which is a person choosing an older version by hand. Modern syntax is allowed; today no
  file uses any, which is what keeps the floor one line to lower again. **Be deliberate
  about spending it** — the failure mode changed direction with the answer. Guessing low
  only forwent syntax; a declared floor that turns out wrong is a parse error, and a parse
  error in a file a Screen loads is a blank sign in the shop. **And `php -l` cannot check
  this for you**: the container these sessions run in has PHP 8.4, so an 8.3-or-8.4-only
  construct lints clean here and breaks the live sign — demonstrated, not argued. That
  hole is what invariant 31 covers: `tools/check_invariants.php` reads real tokens and
  refuses seven above-floor constructs and twenty functions, and prints on every run that
  it is a denylist. So the gate now disagrees with you when it can — but a denylist cannot
  know what 8.5 adds, so a construct you have not seen before is still worth looking up
  rather than trusting the green line. The 7.1-era fallbacks in `auth.php` and `.htaccess` stay
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
node tools/selftest_builder_brands.js      # if builder.php was touched
node tools/selftest_builder_theme.js       # if builder.php was touched
node tools/selftest_viewer.js              # if viewer.php was touched
```

And one that is not a gate, because it takes minutes rather than seconds:

```
php tools/mutate.php lib/whatever.php      # over each lib/ file you changed
```

It breaks that file one way at a time and runs the suite each time, so a check you
just wrote is *seen* to fail rather than assumed to. That is invariant 30 and it is a
rule because the alternative has shipped here more than once: a check asserting what
PHP 8 guarantees, a `setupInteract()` call that could not fail, a grep whose stated
answer had been unreachable since the day it landed, and an "absent setting" check
running in a process where the setting was present. All four read as one more `ok`
line. A surviving mutant is a check to write **or a reason to write down** — §4am's
`flock(LOCK_UN)` survives because the runtime would release the lock anyway, which is
the docblock being right. It is never a reason to delete the line: three of #49's
survivors were load-bearing. And a kill has grades — only the `assertion` grade is a
check knowing what the line was for; `diagnostic`, `count` and `fatal` are the harness
noticing something moved.

`check_doc_numbering.php` also prints the next free section letter. That is the
question every branch cut from the same base has to answer before it writes a
write-up, and four of them once answered it with the same letter — ask the tool
rather than counting, and note that it will not let a document cite a section
that does not exist yet, which is what a guess looks like from the outside.

`php -l` cannot see inline JavaScript, and `builder.php` is ~4100 lines of it —
which is why the standing gate is not enough on its own. Extract the `<script>`
block and run `node --check` over it after touching that file; the same goes for
`viewer.php`, which runs unattended on a TV where a thrown exception is a blank
sign rather than a stack trace anybody will read.

The eight node suites go further and *run* that JavaScript, each under a premise the
others cannot hold — a page that may not edit, an admin uploading a file, an admin
opening a Display whose stored data is already wrong, an admin working the controls the
inspector puts on a block, an admin taking back the last thing they did, an admin
deciding which venue a sign belongs to, somebody changing a setting about *themselves*
with unpublished work on the canvas, and a Screen whose server has stopped answering
or whose blocks have nothing in them — because the
defects they exist for are invisible to a parse: a lookup for a control the edit lock
took away, a `fetch` chain with no `.catch()`, a colour the CSSOM discarded in silence
and the publish payload then sent as black, a field a rebuild forgot to carry, a
`.catch()` that correctly ignores a dropped packet and therefore also ignored a failure
that was never going to stop, a swatch that fills a control and changes nothing on the
canvas because setting `.value` from script fires no event, a preference that repaints
the screen and was never stored, and a sentence written for
whoever was building the layout, drawn on the board a customer reads prices off.

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
- **A stored moment is UTC; one place reads it, and the store's zone is what a person
  sees.** Three rules that are one, because separating them is how #44 survived a year.
  Written with `gmdate()`, never `date()` — local wall-clock is not monotonic and the
  autumn fall-back replays an hour — and never by asking the *database* for the time,
  because `CURRENT_TIMESTAMP` is MySQL's session zone, which is a third clock beside
  PHP's and the store's and the one no screen showed. Read by `StoreClock::epochOf()`
  and nowhere else: a bare `strtotime()` on a `Y-m-d H:i:s` uses the process zone, so
  the `' UTC'` suffix *is* the rule, and it was written out three times with the third
  copy missing it — the two that were right are exactly what made the third invisible,
  because a stamp stored in the wrong frame and one read in the wrong frame produce the
  same sentence, and on a host where the frames agree they produce the *right* one.
  Shown through `StoreClock::label()`, in the zone `STORE_TIMEZONE` names, and **a fixed
  offset is not a timezone** — `+08:00` and `PST` both build a valid `DateTimeZone` and
  are both wrong for half the year, so only the identifiers PHP lists will do. The
  process default is set once, in `config.php`, so a bare `date()` added later agrees
  with the door rather than being two hours from it — the live host sets
  `America/Chicago`, observed 2026-08-11 after this repo had asserted UTC without looking
  (§4ap) — and is deliberately not what the door depends on, since `viewer.php` loads
  neither `config.php` nor `auth.php`.
- **A colour in a `<style>` block is validated, never escaped.** Escaping is for a
  delimiter and a stylesheet has none — `#fff; } body { … }` survives `Markup::text()`
  intact and is a closed rule and a new one. The store's brand colours go through
  `SiteChrome::navBg()` and its three siblings, which answer `#rrggbb` or the documented
  default because `Color::read()` decided. No page names a `BRAND_*` constant. The same
  holds one boundary further in, inside a `style` **attribute**: escaping stops a value
  ending the attribute and not the declaration, so a stored Brand Standards row is drawn
  through `BrandStyles::readable()`, never read out of the row. Both say which stored
  value they could not use — a substitute nobody is told about is #21 again.
- **A reply's status code comes from its `reason`, never from beside it.**
  `HttpReply` maps the app's own vocabulary of failure onto HTTP once. A code chosen
  at a call site is a second opinion, and it disagrees silently.
