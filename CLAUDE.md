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
