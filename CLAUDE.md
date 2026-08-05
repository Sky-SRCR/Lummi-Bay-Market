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
  outside `lib/layout_store.php` may write SQL against `canvas_elements`, and
  nothing outside `lib/displays.php` may write SQL against `displays`.
- **Deep modules**: small interface, substantial implementation. A new query
  means a new method on the module, not a `$pdo` handed to a caller.
- **PHP 7.1-compatible syntax** — the live server's version is unverified.
- **No undo exists anywhere in this app.** Publishing overwrites. Prefer
  refusing a write to merging one.

## Before pushing

```
php -l <every touched .php>
php tools/selftest_layout.php
```

`php -l` cannot see inline JavaScript, and `builder.php` is ~2450 lines of it.
