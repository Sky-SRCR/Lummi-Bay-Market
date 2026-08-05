# Build Reference — Multi-Display

> **Read this file after finishing each module, before starting the next one.**
> It is the standing contract for the whole multi-display build
> ([`roadmap-multi-display.md`](roadmap-multi-display.md), Phases 1–6). The roadmap
> says *what* each phase delivers; this file says *how the code is shaped* and
> *which invariants must survive every phase*. If a module you just wrote
> contradicts something here, one of the two is wrong — resolve it deliberately
> and update this file in the same commit.

Vocabulary is fixed in [`../CONTEXT.md`](../CONTEXT.md): **Display**, **Viewer**,
**Screen**, **screen name tag**, **canvas**, **grant**, **edit lock**. Use those
words in code, comments, commits, and UI copy. Never `slug`, `kiosk page`,
`monitor`, `artboard`, `checkout`.

---

## 1. Architecture: deep modules

The app was a flat set of page scripts where every page talked to the database
directly. Multi-display support adds one invariant that must hold in *every*
query — *this row belongs to that Display* — and a scattered version of that rule
is how you empty every sign at once. So the display-scoped data access lives
behind a small number of **deep modules** in `lib/`, and the page scripts
(`api.php`, `viewer.php`, `builder.php`, `admin_panel.php`) become **thin
adapters** over them.

Design rules, applied to every module added by this build:

- **Small interface, large implementation.** Callers say *what* they want
  (`publish this layout to this Display as this account`), never *how*
  (transactions, temp-id maps, staleness comparison, sanitising, scoping).
- **The invariant lives inside, once.** No caller may construct SQL that touches
  `canvas_elements` or `displays`. If a caller needs a new query, the module gets
  a new method — the caller does not get a `$pdo`. That holds between the modules
  too: `LayoutStore` owns `canvas_elements` and takes a `DisplayStore` to reach
  the stamp, the background and the publish record, rather than writing to
  `displays` itself.
- **Deletion test.** If deleting the module would make complexity reappear in
  several callers, it earns its keep. If it would just vanish, it was a
  pass-through and should be inlined.
- **The interface is the test surface.** Anything worth testing is reachable
  through the module's public methods. Pure logic that deserves a unit test is
  factored into an internal, private-by-convention helper (an *internal seam*)
  rather than exposed on the interface.
- **No new seam without two adapters.** There is one database and one HTTP
  surface; don't add interfaces for imagined swapability.
- **Errors are values, not strings.** A module returns a result object naming the
  outcome (`ok` / `stale` / `failed`); the adapter turns it into JSON and copy.
  Adapters must never re-derive an outcome from a message string.

### Module map (`lib/`)

| Module | Interface, in one line | Hides |
|--------|------------------------|-------|
| `schema.php` | `ensureSignageSchema(PDO): void` | Every idempotent `ALTER`/`CREATE`, the `displays` table, `display_id` + backfill + index + FK, the drive-thru seed, and the "run at most once per request" latch. |
| `displays.php` | `Display` + `Background` value objects, `DisplayStore` | **Every** `displays` statement: tag rules, background intents, the publish stamp and record, the lock columns, and self-healing when the table is not there yet. |
| `layout_store.php` | `LayoutStore(PDO, DisplayStore)` | The publish transaction end to end: staleness check, wipe-and-reinsert scoped to one Display, temp-id mapping, asset auto-save, plain-text stripping, admin/basic section rules, element index, scoped hide/delete. |
| `plain_text.php` | `toPlainText(string): string` | ADR-0002's sanitising, in a file with no session side effects so the store can include it. |
| `display_request.php` | `DisplayRequest::resolve(...)` → `DisplayResolution` | Which Display an HTTP request means, the ADR-0003 notice wording per failure case, the transitional no-tag fallback, and (from Phase 4) the grant check. |

`lib/` is denied to the browser by `lib/.htaccess`. Nothing in `lib/` prints,
redirects, reads `$_POST`/`$_GET`, or touches `$_SESSION` — adapters pass what
the module needs as arguments. That is what keeps them testable without a web
server.

### Where later phases attach

Adding a phase should mean *filling in a module*, not threading a new concept
through the app again:

- **Phase 2** (canvas dimensions): `viewer.php` and `builder.php` become adapters
  over `DisplayRequest` + `Display`. Every `1920`/`1080` literal is replaced by
  `$display->canvasWidth()` / `->canvasHeight()`. No new module.
- **Phase 3** (admin Displays screen): `DisplayStore` gains `create()`,
  `update()`, `rename()`, `deactivate()`, `delete()`, `duplicateLayoutFrom()`.
  The tag rules and the "duplicate only from identical dimensions" rule live in
  the store, not in the panel.
- **Phase 4** (grants): `display_permissions` table added to `schema.php`;
  `DisplayRequest::resolve()` gains the grant check so *every* endpoint is
  covered by construction; `DisplayStore::editableBy(account)` filters the
  picker. No endpoint gets its own `if` — that is the whole point of the seam.
- **Phase 5** (edit lock): the lock columns already exist on `displays`;
  `DisplayStore` gains `takeLock()`, `heartbeat()`, `releaseLock()`,
  `forceUnlock()`, and `Display::lockState()` decides lapsed-vs-held by comparing
  timestamps on read (no cron). `LayoutStore::publish()` already refuses on a
  stale stamp; it also refuses when the lock moved on.
- **Phase 6** (docs/schema): `schema.sql` is regenerated to match `schema.php`.
  Keep the two in step as you go so Phase 6 is proofreading, not archaeology.

---

## 2. Invariants — must hold after every phase

1. **Every read and write of `canvas_elements` is Display-scoped.** No statement
   anywhere may touch the table without a `display_id` predicate. The publish
   `DELETE` is the dangerous one: it deletes *this Display's* rows only. Grep
   `canvas_elements` after any change; every hit must be inside
   `lib/layout_store.php`.
2. **No `WHERE id = 1`, ever again.** `canvas_settings` is retired: the table is
   left on the server as a rollback artefact but no code reads or writes it. A
   Display's background lives on the Display row.
3. **A Viewer URL names its Display** (ADR-0003). No default, no master, no
   fallback Display — the *only* exception is the transitional no-tag fallback in
   §3, which is deleted in Phase 2.
4. **Canvas dimensions are immutable after creation** (ADR-0004). Nothing may
   offer, accept, or infer a resize. Duplication is offered only between Displays
   of identical dimensions.
5. **A publish that would overwrite someone else's work is refused, not merged**
   (ADR-0006). There is no undo; refusal is the whole safety net.
6. **Text-block content is plain text** (ADR-0002). `toPlainText()` on save for
   `type = 'text'` only; render with `textContent`. Never `innerHTML`, never
   strip carousel/table/marquee JSON or media paths.
7. **The public path runs no DDL.** `get_layout` is polled every 30s by every
   Screen forever. Schema convergence happens on authenticated requests, plus one
   self-healing retry if the schema is genuinely absent (§3).
8. **Grants and roles are two axes** (ADR-0005). Grants say *which* Displays;
   `users.role` still says *how much* power. Enforcement is server-side, in the
   resolution seam, on reads and writes alike.
9. **One filename for the Viewer.** `viewer.php` stays a single file — the
   `<Files "viewer.php">` block in `.htaccess` drops `X-Frame-Options` for the
   SmartSign2Go embed and the kiosk scroll lock rides on it. Renaming or
   splitting it breaks the live sign.
10. **The live database is behind the repo.** Assume nothing about what columns
    exist. Every schema change is an idempotent statement in `schema.php`.

---

## 3. Transitional behaviour introduced in Phase 1 (delete in Phase 2/3)

Phase 1 must be deployable on its own, and the live Screen and SmartSign2Go
widget still request a bare `viewer.php` (they are re-pointed at the end of
Phase 2). So a request that names no Display resolves to the installation's
**sole** Display:

- It resolves **only** when exactly one Display exists. With two or more, a
  request with no tag **fails** rather than guessing — so a Phase 3 second
  Display can never be misrouted a write.
- It is implemented in exactly one place, `DisplayRequest::resolve()`, tagged
  `PHASE-1 TRANSITIONAL`.
- **Phase 2** removes it for `get_layout` once `viewer.php` sends its tag.
  **Phase 3** removes it entirely once the Builder and admin panel send theirs.

Search `PHASE-1 TRANSITIONAL` to find every line that has to go.

---

## 4. Decisions taken during Phase 1

Recorded here rather than as ADRs — they are implementation-level choices inside
decisions already made, not new decisions.

- **The layout stamp is a revision counter, not a timestamp.**
  `displays.layout_revision` increments on every publish; the Builder holds it and
  submits it back. ADR-0006 describes the stamp as "when the layout was last
  published"; a counter implements the same rule without the one-second collision
  window two `DATETIME` publishes would share. `last_published_at` /
  `last_published_by` are still recorded — they are what the Phase 3 admin list
  shows and what the refusal message names.
- **A publish carrying no stamp is refused.** An old tab loaded before this
  deploy holds a pre-Phase-1 layout, which is exactly the write the check exists
  to stop. The refusal names the fix ("reload"), and no publish path in the
  shipped code omits the stamp.
- **Publish takes a row lock on the Display** (`SELECT … FOR UPDATE`) inside the
  transaction, so two simultaneous publishes serialise instead of both reading
  the same revision and both passing the check.
- **`canvas_settings` is kept on the server, unread.** Dropping a table on a
  live database with no backup buys nothing; leaving it costs nothing and is a
  rollback path during the one visit where it matters. `schema.sql` documents it
  as retired in Phase 6.
- **PHP 7.1-compatible syntax.** The live server's PHP version is unverified and
  `.htaccess` still carries `mod_php7` blocks, so no typed properties,
  constructor promotion, enums, `readonly`, `match`, or arrow functions. This
  container has PHP 8.4 for `php -l` only.
- **An inactive Display returns no elements from `get_layout`.** The API reports
  `status: "inactive"` and the Phase 2 Viewer renders the notice. A Phase 1
  Viewer would show an empty canvas — unreachable in practice, since nothing can
  deactivate a Display before the Phase 3 UI exists.

---

## 5. Verification

No CI, no test suite, no PHP runtime on the target — verification is deliberate
and manual. Run all of it before every push.

```
php -l <every touched .php>              # syntax; also a GitHub Action
php tools/selftest_layout.php            # pure publish logic, no database
grep -rn "canvas_elements" --include=*.php .   # only lib/layout_store.php may hit
grep -rn "WHERE id = 1\|id=1" --include=*.php . # must be empty
grep -rn "1920\|1080" --include=*.php .        # Phase 2 target list
```

`php -l` cannot see inline JavaScript, and `builder.php` is ~2450 lines of it.
Anything touching that file needs reading, not linting.

On a server with a **copy** of live data — never the live database:

```
php tools/rehearse_phase1.php            # converge schema, prove scoping, publish twice
```

Then in a real browser: sign in → edit → publish → the Screen updates within
30s; publish again from a second stale tab → refused with a named holder;
`api.php?action=get_layout` with no session still returns the drive-thru layout.

Phases 4–5 additionally need two accounts (one admin, one `basic` with a single
grant) and, for the lock, two browsers. Phase 2 needs a genuinely
different-shaped Display, including a portrait one.

---

## 6. Delivery

One PR per phase, on `claude/app-update-planning-1pjqfr`, restarted from `main`
after each merge. Every merge reaches the sign by hand, so phase order is
deployment order and each phase must leave the app coherent on its own.
