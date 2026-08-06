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
| `schema.php` | `ensureSignageSchema(PDO): void`, plus the three pieces it is made of: `readSchemaFacts(PDO) → SchemaFacts`, `signageSchemaPlan(SchemaFacts) → array`, `runSchemaPlan(PDO, array) → array` | Every idempotent `ALTER`/`CREATE`, the `displays` and `display_permissions` tables, `display_id` + backfill + index + FK, the drive-thru seed, the Brand Standards row seed, the "run at most once per request" latch — and **whether any of it needs to run at all**. The one place in the repo that reads `information_schema`; `ServerReport` asks this rather than writing its own catalogue query. `signageSchemaPlan()` is pure (facts in, ordered work out), which is the only reason this file has any automated coverage: its statements are MySQL-only and the fixture is SQLite. `runSchemaPlan()` returns the entries that failed, each with the database's own reason, and `reportSchemaFailures()` tells an admin — but only about a statement the catalogue said was missing, never about one included as a guess (invariant 20). `SchemaLatch` is the "once per request" latch, as something a test can clear. |
| `displays.php` | `Display` + `Background` + `LockState` value objects, `DisplayStore` | **Every** `displays` statement: tag rules and suggestion, canvas bounds, background intents, the publish stamp and record, the edit lock (claim / release / seize, and the idle window that decides held-from-free on read), and self-healing when the table is not there yet. |
| `grants.php` | `GrantStore`, `Actor` | **Every** `display_permissions` statement, and the whole of "may this account have that Display?" — the two axes of ADR-0005 combined in one predicate, `Actor::mayOpen()`, that the seam and the picker both ask. |
| `display_admin.php` | `DisplayAdmin(PDO, DisplayStore, LayoutStore, GrantStore)` → `DisplayResult` | Administering a Display: what a complete one needs, creating it blank or as a duplicate of one the same shape, renaming, retiring, destroying it with its layout and its grants, and setting the whole access matrix — each all-or-nothing. Writes no SQL of its own; holds the transaction that spans the three stores. |
| `layout_store.php` | `LayoutStore(PDO, DisplayStore)` | The publish transaction end to end: edit-lock and staleness checks, wipe-and-reinsert scoped to one Display, temp-id mapping, asset auto-save, plain-text stripping, admin/basic section rules, element index, lock-checked hide/delete, `assetUsage()` — which Displays depend on a library entry — and the sweep of the library rows a publish strands, scoped to the ids that Display's own previous layout held. |
| `assets.php` | `AssetLibrary(PDO)` — `all` / `forId` / `create` / `update` / `delete` / `pool` / `pooledNotIn` / `discardPooled` | **Every** `assets` statement. The decision it holds: `pool()` no longer de-duplicates, so a published text block's words belong to that block alone — sharing a row meant editing one line changed two signs and deleting it blanked both, permanently, with no undo. The cost is rows left behind, so a pooled row carries a marker and only marked rows are ever swept; a row a person made, or renamed, survives every sweep however it is asked. `firstCharacters()` keeps a label from being cut mid-character. One documented read of `assets` lives elsewhere: `LayoutStore::snapshot()`'s LEFT JOIN, read-only and on the path a Screen polls every 30 seconds. |
| `upload_limits.php` | `UploadLimit::bytes` / `describe` / `describeBytes` / `bodyWasDropped` / `smallestOf` / `toBytes` | How big a file can actually reach this server — the smallest of the app's 50 MB ceiling and PHP's `upload_max_filesize` and `post_max_size`, not the app's opinion. And the silent case: exceeding `post_max_size` is not an error PHP reports, it abandons the body, so a 40 MB video was answered *"Security token mismatch. Please reload the page."* `smallestOf()` takes the ini values as an argument because both settings are PHP_INI_PERDIR and the cases worth testing are unreachable otherwise. Depends on nothing. |
| `brand_styles.php` | `BrandStyles(PDO)` | The six branded block types: the only reader and writer of `block_styles`, the validation for every stored value, and the rule that a type absent from a save is left untouched. |
| `password_resets.php` | `ResetTokenStore(PDO)` — `issue` / `redeem` / `discard` | **Every** `password_resets` statement, the 30-minute lifetime, and the guess budget: five tries per issued code, counted on the code's own row so a fresh cookie cannot buy five more. `redeem()` returns a bare boolean on purpose — the reset page must answer "wrong code", "no such account" and "budget spent" in the same words, and a caller that cannot tell them apart cannot leak the difference. |
| `accounts.php` | `AccountStore`, `AccountAdmin` → `AccountResult` | What it means for an account to be **closed**, and the transaction that closes one: grants surrendered, edit lock released, `closed_at` stamped, all or nothing. Also the two refusals that exist because closing cannot be undone — your own account, and the last admin who can still sign in. Not a gatekeeper for all of `users`: creating, role changes and password resets are still written by `admin_panel.php`, and sign-in by `login.php`. What lives here is closure and the reads that depend on it, so the five files with an opinion about a user row cannot disagree about what a closed one means. |
| `server_report.php` | `ServerReport(PDO)` — `runtime()` / `convergence()` / `isConverged()` | What machine this is, and whether the schema actually converged. Reads the database catalogue (through `readSchemaFacts()`, not its own query) and PHP's own configuration, and **no application data at all** — which is why it may name `users`, `displays` and `canvas_elements` without being a second writer. It trusts the catalogue only for a table the read actually covered; anything else falls back to a `SELECT … LIMIT 0`, because a confident wrong "missing" from the one report meant to be trusted is worse than no report. It exists because two things this repo depends on were never observable: the live PHP version (the whole 7.1 rule rests on it) and whether a `schemaTry()` statement landed, which by design fails silently. |
| `error_policy.php` | `ErrorPolicy::install(mode)` / `log` / `fail` / `report` / `noticeFor` / `status` | What happens when something goes wrong: the ini settings, set in code so they travel with the deploy and can be read back; the three handlers; where the log lives and when it rotates; and — the part that needed a module rather than a line — the last thing a request prints, which differs by audience. A Screen gets a self-re-checking kiosk notice, an endpoint gets JSON its caller can parse, a person gets a sentence. `noticeFor()` is pure so all three are testable without a failing server. `report()` is the one for a problem the app survived but an admin should hear about, and it takes a window: a problem that recurs on its own schedule — a schema statement retried on every page load, or every 30 seconds per Screen — has its *log line* throttled too, not only its email, or the record of it buries everything else in a 2 MB file. Depends on nothing: no database, no session, no config. |
| `alerts.php` | `AlertMailer(stateDir, siteName)` — `notify` / `remember` / `recipients` | Telling somebody. Both halves are on disk rather than in the database, because the commonest thing to alert about *is* the database: the rate limiter is a stamp file (one email per problem per hour, keyed by kind + file + line) and the recipient list is a cache written whenever an admin opens the admin panel. With nowhere writable it sends nothing at all — a limiter that fails open means one email per Screen per poll. `deliver()` is the single line that reaches `mail()`, separated so the rules can be tested without one. |
| `plain_text.php` | `toPlainText(string): string` | ADR-0002's sanitising, in a file with no session side effects so the store can include it. |
| `display_request.php` | `DisplayRequest::forViewing/forEditing(...)` → `DisplayResolution` | Which Display an HTTP request means and whether the account asking may have it, the ADR-0003 notice wording per failure case, and the editing entry rule. The one place grants are enforced. |

`lib/` is denied to the browser by `lib/.htaccess`. Nothing in `lib/` prints,
redirects, reads `$_POST`/`$_GET`, or touches `$_SESSION` — adapters pass what
the module needs as arguments. That is what keeps them testable without a web
server.

### Where later phases attach

Adding a phase should mean *filling in a module*, not threading a new concept
through the app again:

- ~~**Phase 2** (canvas dimensions)~~ **Done.** `viewer.php` and `builder.php` are
  adapters over `DisplayRequest` + `Display`; no `1920`/`1080` literal remains in
  either. No new module was needed. See §7 for the zoom mechanics.
- ~~**Phase 3** (admin Displays screen)~~ **Done**, with one planned shape
  changed — see §4c. The write surface landed as `DisplayStore` (statements and
  table rules) plus a new `DisplayAdmin` (the use case), rather than all of it on
  `DisplayStore`: `duplicateLayoutFrom()` there would have meant `DisplayStore`
  writing `canvas_elements`, which invariant 1 forbids. Both things Phase 2 left
  are settled: `get_editor_layout` is the authenticated editing read, and the
  Builder's picker is in place.
- ~~**Phase 4** (grants)~~ **Done**, with one planned shape changed — see §4d. The
  grant check landed in `DisplayRequest::forEditing()` as planned, so every
  endpoint is covered by construction and none has its own `if`. The picker filter
  did *not* land as `DisplayStore::editableBy()`: it is `Actor::openable()`, next
  to the grants it consults, rather than in the module that owns `displays`.
- ~~**Phase 5** (edit lock)~~ **Done**, with two planned shapes changed — see §4e.
  `Display::lockState()` decides lapsed-from-held by comparing timestamps on read,
  as planned, and `LayoutStore::publish()` refuses when the lock has moved on. But
  the planned `takeLock()` and `heartbeat()` are one method, `claimLock()`, and
  `forceUnlock()` is `seizeLock()` because it hands the lock over rather than
  freeing it.
- ~~**Phase 6** (docs/schema)~~ **Done** — see §4f. `schema.sql` matches
  `schema.php`, and the four docs that described a single fixed 1920×1080 sign
  (`README.md`, `CONTEXT.md`, `HANDOFF.md`, `help.php`) describe what the code does.
  Proofreading them found two real defects; both are fixed and recorded in §4f.

---

## 2. Invariants — must hold after every phase

1. **Every read and write of `canvas_elements` is Display-scoped.** No statement
   anywhere may touch the table without a `display_id` predicate. The publish
   `DELETE` is the dangerous one: it deletes *this Display's* rows only. Grep
   `canvas_elements` after any change; every hit must be inside
   `lib/layout_store.php` — with two standing exceptions that a reader has to know
   about, or the grep trains them to skim: `lib/schema.php` carries the table's own
   DDL and its backfill, and the string also appears in the endpoint *name*
   `get_canvas_elements`, which puts hits in `api.php` and `admin_panel.php` that
   are not statements at all. A genuinely unscoped statement added to `api.php`
   would arrive among hits already classified as normal, so read them, don't count
   them. `lib/server_report.php` is a third standing exception and a deliberately
   inert one: it names the table in a list of columns it expects to find, and asks
   the catalogue whether they are there. It reads no rows, from any table — and
   `lib/schema.php` is where that catalogue question is actually asked, which is the
   same exception one file further in.
   There is also a back door the grep cannot see at all: a pooled text block keeps
   no content of its own, so deleting its `assets` row blanks that line without the
   string `canvas_elements` appearing anywhere. `LayoutStore::assetUsage()` exists
   so `crud.php` can refuse. Publishing no longer *creates* a row two Displays
   share (invariant 17), which narrows the blast radius to one sign — but only for
   rows created since; every shared row the old code left is still there, which is
   why the refusal and its wording stay plural.
2. **No `WHERE id = 1`, ever again.** A Display's background lives on the Display
   row. `canvas_settings` is retired to exactly one reader: `seedLegacyDisplay()`
   in `lib/schema.php` reads its lowest row once, to carry the old background
   forward onto the Display that replaces it. Nothing writes it, and nothing else
   reads it. (This invariant used to claim *no* code read it, which was wrong the
   day it was written — and the §5 grep for it, `WHERE id = 1`, structurally cannot
   match the `ORDER BY id ASC LIMIT 1` that does the reading. Two other docs stated
   the truth; this one contradicted them.)
3. **A Viewer URL names its Display** (ADR-0003). No default, no master, no
   fallback Display on the viewing path, ever. The one place a request may name no
   Display is the *editing* entry rule in §3, which resolves only when the
   installation has exactly one Display.
4. **Canvas dimensions are immutable after creation** (ADR-0004). Nothing may
   offer, accept, or infer a resize. Duplication is offered only between Displays
   of identical dimensions.
5. **A publish that would overwrite someone else's work is refused, not merged**
   (ADR-0006, ADR-0007). There is no undo; refusal is the whole safety net. Two
   things can make a publish that refusal: a stamp that has moved, and an edit
   lock that has. Both are checked inside `LayoutStore::publish()`, under the same
   row lock, so neither can be talked out of by a client. And a body that did not
   *decode* is not an empty layout: `PublishRequest::fromPostedJson()` refuses it,
   because publishing an empty layout deletes every element and the old
   `json_decode(...) ?: []` read an unreadable request as exactly that.
6. **Text-block content is plain text** (ADR-0002). `toPlainText()` on save for
   `type = 'text'` only; render with `textContent`. Never `innerHTML`, never
   strip carousel/table/marquee JSON or media paths.
7. **The public path runs no DDL, and opens no session.** `get_layout` is polled
   every 30s by every Screen forever. Schema convergence happens on authenticated
   requests, plus one self-healing retry if the schema is genuinely absent (§3).
   The session half is newer and was quietly false for a while: `api.php` includes
   `auth.php`, which opens a session at include time, so the poll was minting one
   session file per request on any Screen that discards cookies — which a framed
   Screen does, because the cookie is SameSite=Lax. `AUTH_NO_SESSION` is defined
   before the include on that one path.
8. **Grants and roles are two axes** (ADR-0005). Grants say *which* Displays;
   `users.role` still says *how much* power. Enforcement is server-side, in the
   resolution seam, on reads and writes alike — never by an endpoint's own `if`,
   and never by a Display merely being absent from the picker. Every statement
   against `display_permissions` is inside `lib/grants.php`; every question about
   whether an account may have a Display is one call to `Actor::mayOpen()`.
9. **One filename for the Viewer.** `viewer.php` stays a single file — the
   `<Files "viewer.php">` block in `.htaccess` drops `X-Frame-Options` for the
   SmartSign2Go embed and the kiosk scroll lock rides on it. Renaming or
   splitting it breaks the live sign.
10. **The live database is behind the repo.** Assume nothing about what columns
    exist. Every schema change is an idempotent statement in `schema.php`.
11. **The edit lock is held by work, not by presence** (ADR-0007). Only a real
    interaction — a click, a key, a drag, an edit — may extend it; never a timer,
    a poll, mouse movement, or the fact that a tab is open. A heartbeat reports
    the *age* of the last interaction, so a forgotten tab keeps beating and still
    frees the Display on time. Anything added to the Builder that keeps the lock
    alive must be something a person did on purpose.
12. **A page that knows its Display says which record it means.** The screen name
    tag is the *address*, not the identity: an admin may rename one, and the name
    it vacated may be given to another sign the same afternoon. So a page built
    for a Display sends `display_id` alongside `display` on every call, and
    `DisplayRequest::confirmIdentity()` refuses the pair when they disagree —
    which is the only thing standing between a Builder left open across a rename
    and a publish that silently overwrites another screen's layout. The claim is
    optional by design (a Viewer URL on a TV carries the tag and nothing else),
    so anything added to `builder.php` or the Work Area that names a Display must
    send the id too, or it opts itself out of the check without a word.
13. **A guess budget is stored with the secret, never in the guesser's session.**
    ADR-0001 moved the login lockout onto the account row for this reason; the
    password-reset limiter was still counting in `$_SESSION`, so clearing a cookie
    bought five more tries against the one live passcode and forty cookie jars
    bought two hundred. It now lives in `password_resets.attempts`, spent by the
    same `UPDATE` that checks it so two simultaneous guesses cannot both spend the
    last one. Anything added that rations attempts at anything — a code, a token,
    a one-time link — counts them somewhere the person guessing cannot reach, and
    says the same sentence for every refusal, or the count itself becomes the
    oracle it was rationing against.
14. **An account number is never reused.** Accounts are *closed*, never deleted
    (CONTEXT.md). `DELETE FROM users` freed the id, and MySQL hands a freed id to
    the next account created — so a grant that outlived its cascade, a held edit
    lock, a publish record or a signed-in browser could silently come to mean a
    different person. Each of those was defended one at a time, by remembering to;
    keeping the row removes the thing they were defending against. `closed_at` is
    deliberately not `is_active`: inactive is a suspension an admin undoes, closed
    is permanent, and collapsing them would put an "undo" button on the one thing
    that must not be undone. There must be no `DELETE FROM users` anywhere, and
    anything that resolves an account id to a person must keep resolving a closed
    one — `AccountStore::names()` covers every account for exactly that reason.
15. **`schema.sql` is what `schema.php` converges to.** They are two statements of
    one structure, and the runtime one is authoritative — a column that exists only
    in `schema.sql` never reaches the live server. Add to both in the same commit,
    and remember `CREATE TABLE IF NOT EXISTS` is a no-op on a database that already
    has the table, so a column added to an existing table needs its own `ALTER`.
16. **No failure reaches a visitor as PHP's own output.** `lib/error_policy.php`
    sets `display_errors` off and `log_errors` on *in code*, on every request, and
    installs the three handlers — warning, uncaught exception, fatal — that stand
    between a failure and the page. The rule is not "wrap things in try/catch": it
    is that the last thing any request can print is one of three sentences chosen
    by mode, and none of them carries a file path, a class name, an SQL fragment or
    anything from the request. A new entry point declares its mode before it opens
    the database (`viewer.php`, `api.php`) or inherits `PAGE` from `db_connect.php`;
    a new page that prints its own raw exception text has left the policy. The
    Screen mode is the one that matters most and the one with no user to notice it
    is wrong: its notice re-checks every 30 seconds, so a sign that went dark on a
    database blip comes back without anybody driving to the store.
17. **Publishing never makes two Displays share a library row.** `AssetLibrary::pool()`
    always inserts. The de-duplication it replaced was an optimisation for a
    database with one sign in it: once there are several, one edit in the Library
    changed two signs and one delete blanked both, permanently, because the elements
    keep no copy of their own text and there is no undo. The cost is rows nothing
    points at, and that cost is paid by the marker — `assets.auto_pooled` — plus two
    sweeps: a publish drops what its own previous layout stranded, and the Library's
    tidy button reaches what no publish can. Neither may ever remove a row a person
    made, so both go through `discardPooled()`, which carries the marker as a
    database predicate. A caller that miscounts can leave the library untidy; it
    cannot blank a sign.
18. **A file's size limit comes from `UploadLimit`, never from a number in the file
    doing the checking.** The app's 50 MB is usually not the binding ceiling, and
    the one that usually is — `post_max_size` — is not an error PHP reports: it
    throws the request body away and lets the script run, so the missing CSRF token
    became the message and *"reload the page and try again"* became the advice for a
    file that will never fit. Every refusal names the real number, `api.php` answers
    the dropped-body case before its CSRF gate, and the Builder refuses in the file
    picker so the wait is not spent first.
19. **Convergence asks the catalogue before it alters anything, and a converged
    database is issued no DDL at all.** `signageSchemaPlan()` decides; only what it
    returns runs. Two rules make the decision safe. First, a `null` from
    `SchemaFacts` means *cannot tell*, never *not there* — an unreadable catalogue
    puts every statement back in the plan, which is exactly what this file did
    before it started asking, so nothing stops converging on a host that hides
    `information_schema`. Second, the plan may only ever *remove* work the catalogue
    proves is done; a statement whose need cannot be established still runs.
    Adding a statement here means adding its gate and a check that the plan asks for
    it, or the gate is untested and the statement may as well not have one. The
    consequence of getting this wrong is not a slow page: an `ALTER` takes an
    exclusive metadata lock on the table holding every sign's layout, and the
    Screens' 30-second polls queue behind one that is waiting on a publish.
20. **Only a schema statement the catalogue said was missing is ever reported to an
    admin.** A statement included because the catalogue could not be read is a guess,
    and on a host that hides `information_schema` twelve of them fail on every
    request — reporting those would fill an inbox with the normal case and teach the
    one person who can act to ignore the alert that matters. The `need` on each plan
    entry is the test, and it is checked for `=== true`, not for truthiness. Anything
    that reports a failure the app *expected* is a defect in this invariant, not a
    tuning problem: the two seeds carry `true` on any host because their need comes
    from a row count rather than the catalogue, and a benign seed race is turned back
    into a success before it can be mistaken for one.

---

## 3. Which Display does a request with no tag mean?

**Viewing: none.** `DisplayRequest::forViewing()` is strict — a Viewer URL names
its Display or renders a notice (ADR-0003), even when a single Display exists and
the guess would have been right. A truncated URL can never silently show another
sign. This became strict in Phase 2, when the Screens started sending their tag.

**Editing: the one Display this account may open, if there is exactly one.** Nobody
with a single sign to work on should be asked which sign they meant, so a Builder
or API request naming no Display resolves to `Actor::openable()` when that list has
one member. At a single-sign store that is the only Display there is; for a `basic`
account holding one grant it is the sign they were given. It is a decided rule, not
a leftover: the roadmap's *Builder entry* decision is "one Display → straight in;
more than one → pick".

The safety property is that this resolves to nothing the moment two Displays are
openable. A write is never routed to a guessed sign — the request fails, and the
Builder shows its picker. It is implemented in exactly one place,
`DisplayRequest::locate()`, which distinguishes viewing from editing by whether it
was handed an `Actor` at all.

Phase 3 removed the *transitional* part: with a picker in place, failing to resolve
is no longer a dead end. Phase 4 generalised the rule from "the installation's only
Display" to "this account's only Display" — the same sentence, once an account can
hold fewer Displays than exist.

**And which record does a request that *does* name one mean?** The tag it names,
unless the caller also says which Display it believed that tag stood for. Pages
built for a Display — the Builder, the Work Area — send `display_id` beside
`display`, and `DisplayRequest::confirmIdentity()` refuses the pair when they
disagree (invariant 12). The tag still does the routing; the id only ever turns a
resolution into a refusal, never into a different Display. That asymmetry is
deliberate: it means the check can be added to a caller without changing where
anything resolves to, and that a caller which does not send an id — a Screen
polling `get_layout` — behaves exactly as it did before.

A `basic` account with more than one grant returns to whatever it last opened
instead of picking again. That lives in `builder.php`, not here: it reads
`$_SESSION`, and nothing in `lib/` may. It is a *preference*, never a permission —
the remembered tag is resolved through `forEditing()` like any other, so a Display
since retired, deleted or un-granted simply falls back to the picker.

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
- **`canvas_settings` is kept on the server, read exactly once.** Dropping a
  table on a live database with no backup buys nothing; leaving it costs nothing
  and is a rollback path during the one visit where it matters. `schema.sql`
  documents it as retired and no longer creates it. `seedLegacyDisplay()` reads
  its lowest row to carry the old background onto the Display that replaces it —
  the one reader, and the reason invariant 2 says "retired to one reader" rather
  than "unread".
- **PHP 7.1-compatible syntax.** The live server's PHP version is unverified and
  `.htaccess` still carries `mod_php7` blocks, so no typed properties,
  constructor promotion, enums, `readonly`, `match`, or arrow functions. This
  container has PHP 8.4 for `php -l` only.
- **An inactive Display returns no elements from `get_layout`.** The API reports
  `status: "inactive"` and the Phase 2 Viewer renders the notice. A Phase 1
  Viewer would show an empty canvas — unreachable in practice, since nothing can
  deactivate a Display before the Phase 3 UI exists.

---

## 4b. Decisions taken during Phase 2

- **The canvas is CSS-scaled, and every screen-pixel measurement divides by
  `ZOOM`.** `interact.js` reports drag and resize deltas in screen pixels, so a
  scaled canvas desynchronises editing unless each conversion is divided:
  `handleMove` (`event.dx/dy`), `handleResize` (`event.deltaRect` *and*
  `event.rect`, since the new width is a measurement too), and
  `getCanvasDropCenter` (frame scroll geometry). Those three are the complete list;
  a fourth conversion added later must divide as well or dragging will drift at any
  zoom but 100%.
- **`#canvas-sizer` wraps the canvas.** A CSS transform does not change layout
  size, so without a wrapper carrying the *scaled* footprint the editor frame
  cannot scroll to the far edge of a zoomed-in canvas.
- **Zoom-to-fit never magnifies past 100%.** Fitting a small canvas by blowing it
  up would misrepresent how it will look on the Screen.
- **Known caveat:** the section minimum size (`restrictSize`, 100×60) is in screen
  pixels, so at 50% zoom the effective floor is 200×120 canvas pixels. A floor, not
  a correctness problem — left alone rather than adding a fourth zoom conversion.
- **The Viewer resolves server-side.** The canvas is correct on first paint and the
  notice needs no round-trip. It also means `viewer.php` includes no `auth.php` —
  the public path starts no session and runs no DDL.
- **A Display turned off mid-run flips to the notice within one poll.** The Viewer
  treats any non-`success` status as a notice, so deactivating or deleting a
  Display reaches the Screen in ≤30s rather than leaving a stale layout up.
- **The transitional `settings` alias is gone.** Both clients read `display` now,
  and the self-test asserts the alias is absent so it cannot come back.

## 4c. Decisions taken during Phase 3

- **`DisplayAdmin` exists because administering a Display spans both tables.**
  This file planned `duplicateLayoutFrom()` on `DisplayStore`; that would have put
  `canvas_elements` statements in the module that owns `displays`, contradicting
  invariant 1 — the project's central safety property. Resolved by splitting on
  *table* versus *use case*: `DisplayStore` keeps the statements and the table's
  own rules (tag format, tag uniqueness, canvas bounds), and `DisplayAdmin`
  composes the two stores with the validation and the transaction. It holds a PDO
  for `beginTransaction`/`commit` only and writes no SQL — the invariant grep in §5
  is what keeps that honest.
- **Deleting a Display deletes its elements explicitly**, rather than trusting the
  `ON DELETE CASCADE`. The constraint is added by `schemaTry()`, which swallows
  failures, so on a live database behind the repo it may never have applied. A
  Display row deleted without its elements leaves rows no scoped query can ever
  see again.
- **Duplication refuses a non-empty target.** It is a fill, not a merge. Combined
  with the identical-dimensions rule (ADR-0004), the only thing `copyLayout()` can
  do is populate a Display that was created a moment ago.
- **The panel edits background *colour* only.** Images are uploaded from the
  Builder, where the validated upload path already exists and you can see the
  canvas you are changing. Adding a third copy of the upload whitelist to
  `admin_panel.php` (which already has its own, for the logo) was the alternative.
- **A background change from the panel advances the layout stamp.** The background
  is part of what the Screen shows, and an admin with the Builder open would
  otherwise publish their stale colour straight back over it. They get ADR-0006's
  refusal instead.
- **`get_editor_layout` is a separate endpoint, not a flag on `get_layout`.** The
  Viewer's read is public and strict; the Builder's requires a session and accepts
  a deactivated Display. A boolean on one endpoint would have meant the public
  path carrying an authenticated branch.
- **A retired Display is editable by admins only**, which is what CONTEXT.md has
  always said and what the code did not do. `forEditing()` now uses the `$actor`
  argument that had been sitting unused: a `basic` account naming a deactivated
  Display gets the same INACTIVE resolution a Screen would, and the picker does not
  list Displays that account could not open. One check, in the seam — the first use
  of the mechanism Phase 4's grants will extend.
- **The Builder's picker is shown to every account.** The roadmap's *Builder entry*
  decision has `basic` accounts resuming their last Display instead of picking;
  that arrives with grants in Phase 4, because until grants exist every account can
  see every Display and "their last" would be a memory of an unrestricted set.
- **Canvas dimensions have no update statement anywhere.** The way not to offer a
  resize (ADR-0004) is for no method to be able to perform one:
  `DisplayStore::updateDetails()` writes tag, title and location, and that is all.

## 4d. Decisions taken during Phase 4

- **The check lives in the seam, not in `api.php`.** ADR-0005 says enforcement is
  "server-side in `api.php`", which was written before Phase 1 turned that file into
  a thin adapter. The decision it was making — server-side, not merely absent from
  the picker — is honoured more completely here: one check in
  `DisplayRequest::forEditing()` covers all five Display-scoped endpoints and every
  endpoint added later. The ADR is left as written; this is where its address
  changed to.
- **The grant check is one predicate, and the wording is downstream of it.**
  `forEditing()` decides with `Actor::mayOpen()` and only *then* asks the narrower
  question — "is it theirs at all?" — to choose between "turned off" and "not
  assigned to you". A refusal therefore cannot disagree with the decision that
  produced it, which two parallel `if`s would eventually manage.
- **`Actor` carries the account's grants; `DisplayStore` never learns they exist.**
  This file planned `DisplayStore::editableBy(account)` for the picker. That would
  have made the module that owns `displays` depend on the module that owns
  `display_permissions`, to answer a question that is not about the table at all.
  The filter is `Actor::openable(array $displays)` instead — the authority lives
  with the thing that has it, and the picker and the entry rule are then provably
  the same list.
- **`DisplayStore::sole()` is gone.** The entry rule is no longer a fact about the
  table ("is there exactly one row?") but about the account ("is there exactly one
  I may open?"), so the method had nothing left to answer. What remains of it is one
  `count() === 1` in `DisplayRequest::locate()`.
- **A refusal names the sign rather than hiding it.** FORBIDDEN says "That display
  has not been assigned to you", which admits the Display exists. Returning UNKNOWN
  would leak less and help nobody: a clerk who typed a real address would go hunting
  for a typo instead of asking an admin. The Displays a `basic` account is *offered*
  still contain only their own.
- **Grants are read once per request, and only for `basic` accounts.** An admin
  holds every Display by role (ADR-0005), so `Actor::signedIn()` does not even
  query. The public `get_layout` path builds no `Actor` at all — it has no account,
  and a grant read on the poll every Screen makes every 30s would be a query per
  poll against a table the viewing path has no business in.
- **A failed grant read grants nothing.** `GrantStore::pairs()` swallows a database
  failure and returns no grants, so the failure mode is a `basic` account locked out
  (a support call) rather than one let in (a silent hole). The table is created by
  the same convergence every authenticated request runs, so this is a genuinely
  broken database, not a first-run condition.
- **Deleting a Display or an account deletes the grants explicitly**, for the same
  reason Phase 3 deletes elements explicitly: both `ON DELETE CASCADE` constraints
  are added by `schemaTry()`, which swallows failures. An orphaned grant is worse
  than an orphaned element — it is one id reuse away from granting a sign nobody
  assigned.
- **The matrix is one save, covering only the accounts it rendered.** A tick is a
  grant; the whole submitted matrix is applied in one transaction, so what an admin
  sees on screen is what ends up stored. Accounts *absent* from the submission keep
  their grants, so a form left open while someone was added cannot strip the new
  account. Ids naming a deleted Display are dropped rather than refused — the only
  way to send one is a stale form, and there is nothing to save by making an admin
  retype the matrix.
- **Only `basic` accounts are offered grants.** `DisplayAdmin` cannot enforce that:
  roles live on `users`, which is not its table. The panel intersects the submitted
  accounts with the `basic` ones it queried, which both keeps admins out of the
  matrix and stops a forged POST writing grant rows for one.
- **"Basic users resume their last Display" is a session, not a column.** The
  roadmap's entry decision is honoured for the working session: the Builder
  remembers the tag in `$_SESSION` and re-resolves it. A durable version would mean
  a new column on `users` and a stored preference that goes stale the moment a grant
  is revoked, for a store where a `basic` account holding two signs is already the
  unusual case. Signing in afresh shows the picker once.

## 4e. Decisions taken during Phase 5

- **Taking and keeping the lock are one method, and one endpoint.** This file
  planned `takeLock()` and `heartbeat()`. They differ only in whether there was a
  holder, and both have to get the same idle cutoff right — so they are one
  conditional `UPDATE`, `DisplayStore::claimLock()`, whose `WHERE` clause allows a
  claim when the lock is free, already this account's, or lapsed. That also covers
  the third case ADR-0007 asks for, silently re-taking a lapsed lock on return,
  without a third method. The API surface follows: one `hold_lock` endpoint the
  Builder calls to open, to beat, and to come back.
- **A conditional UPDATE, not a read then a write.** Two people opening the same
  Display in the same second must not both be told it is theirs. The claim is one
  statement whose guard *is* the rule; the caller reads the row back afterwards and
  asks its `LockState` what happened, rather than trusting a `rowCount()` that
  means different things on different engines.
- **A heartbeat sends the age of the last interaction, not "now".** This is what
  makes invariant 11 enforceable rather than aspirational: the Builder can beat on
  a lazy 60-second timer, and a tab left open on a back-office monitor keeps
  beating and still loses the Display exactly 15 minutes after the last real
  interaction. It also means the beat interval and the idle window are independent
  — changing one cannot quietly change the other. The age is a client's claim, and
  a client that wanted to lie could synthesise interactions anyway; the lock is a
  courtesy between colleagues, not a security boundary. It is clamped to the window
  and ignored past it.
- **The lock's clock is PHP's, on both sides.** `recordPublish()` can use
  `CURRENT_TIMESTAMP` because nothing subtracts one publish time from another; the
  lock is the one thing here that compares two timestamps, so its statements bind a
  PHP-formatted time. If MySQL and PHP disagreed about the hour, a 15-minute window
  would be an hour long or already expired.
- **Force-unlock hands the lock over rather than freeing it.** ADR-0007 calls it
  force-unlock; `DisplayStore::seizeLock()` transfers instead, because a cleared
  lock is a free lock and the ousted Builder's next heartbeat would claim it back
  within the minute — the takeover would silently undo itself. Transferring also
  gives that tab something definite to report instead of a glitch. The self-test
  asserts exactly this: the ousted account's next claim does not get it back.
- **Read-only is a mode the page is built in, not a set of disabled controls.**
  `builder.php` resolves the lock server-side and renders no creation buttons, no
  background controls and no Publish button at all when somebody else holds it. The
  JavaScript guards (`setupInteract`, the mousedown handlers, `contenteditable`,
  the Delete key) are the second line, for what is reachable without a button. This
  is the only shape that scales in a file that is mostly inline JavaScript: a
  control added later is either inside the `if (!$readOnly)` block or it is
  reachable, and that is visible in the diff.
- **`builder.php` claims the lock on a GET.** Normally worth avoiding, and taken
  deliberately: the alternative is to render the editor and let script claim a
  moment later, which means a flicker at best and somebody starting to drag a block
  that is about to turn read-only at worst. Claiming during the render is also what
  makes two simultaneous opens resolve to one holder. What a crafted link can
  achieve is making the victim hold a Display *they may already edit* for at most
  one idle window — an annoyance, not an escalation.
- **Losing the lock mid-session does not tear the editor apart.** ADR-0007 says the
  unsaved edits stay on screen and the publish is refused, so `READ_ONLY` is fixed
  for the life of the page and the lost-lock case is a banner plus a refusal. That
  removes the hardest part of the phase — dynamically disabling ~40 controls — and
  it is also the kinder behaviour: nobody's work vanishes because a colleague
  clicked something.
- **The lock refusal is checked before the staleness refusal.** When both apply,
  "reload and re-apply" is bad advice: re-applying while somebody else is mid-edit
  would only be refused again. The lock message says to wait instead, and says the
  work is still on screen.
- **Publishing keeps the lock, and takes it back if it had lapsed.** Publishing is
  as real as an interaction gets. It is never a steal — the check a few lines above
  has already established nobody else holds the Display.
- **A lock is not in `toClientArray()`.** That array is what the public `get_layout`
  poll hands to every Screen, and who is editing a sign is nobody's business from
  the street. The self-test asserts the snapshot carries neither the holder nor the
  activity time.
- **A held lock with no recorded activity counts as free.** That is the state a
  half-finished write or a hand edit would leave, and the safe reading of it is
  that nobody is editing.
- **Deleting an account releases its locks explicitly**, alongside its grants and
  for the same reason: `ON DELETE SET NULL` is added by `schemaTry()`, which
  swallows failures, and a lock naming a deleted account blocks a sign for a full
  idle window under a name no banner can print.
- **The admin panel shows who is editing; the Builder is where you take over.** The
  panel is where "why can I not edit this?" gets asked, so each Display card names
  its current editor. The taking-over lives in the Builder, where you can see what
  you would be interrupting — and one action surface means one confirm to keep
  honest.
- **The Work Area's hide and delete still ignore the lock.** They are admin
  element-level edits, not the Builder, and they already advance the stamp — so a
  Builder holding the old layout is refused by ADR-0006 the moment it publishes.
  Extending the lock to cover them would be a second rule about the same collision.

## 4f. Decisions taken during Phase 6

Phase 6 was meant to be proofreading. Reading the docs against the code found two
things the code had wrong, which is the argument for doing this phase last rather
than skipping it.

- **`schema.sql` is a rebuild artefact, not a description of the server, and it
  says so.** Its header now names the two runtime convergence functions and states
  the order of authority: `lib/schema.php` and `auth.php` decide what the live
  database becomes, and `schema.sql` has to agree with them (invariant 15). The
  alternative — documenting the live server's current lagging structure — describes
  something that changes the next time an admin signs in, and would have to be
  rewritten after every deploy.
- **Brand Standards could not be edited on a fresh install** (fixed). The form
  saves with `UPDATE block_styles … WHERE block_type = ?`, and only `item_title_2`
  and `price_2` were ever seeded — so on a database that never had the original four
  rows, saving Section Header, Item Title, Price or Description was a silent no-op:
  the field reverted on reload and nothing said why. Both `schema.php` and
  `schema.sql` now seed all six branded types with `INSERT IGNORE`, which fills gaps
  without touching the store's own values. Fixing the save to upsert was the
  alternative; seeding is one statement in the file that already owns convergence,
  and it keeps "a branded block type has a row" true for every reader of the table
  rather than just for that one form.
- **Brand Standards reach the Screens without a publish.** `LayoutStore` builds
  `block_styles` into every snapshot, and the Viewer applies it on each poll, so a
  saved style change appears on every Display within 30 seconds. Two help pages and
  the panel's own blurb said to publish afterwards. Corrected rather than changed:
  it is the one edit that is genuinely global, and making it wait for a publish
  would mean waiting for a publish *per Display*. Worth knowing that it is also the
  one edit that can alter a sign while somebody else holds its lock — the lock
  guards a Display's layout, not the typography shared across all of them.
- **`help.php` links to no Viewer.** Its nav carried `View Display ↗` pointing at a
  bare `viewer.php`, which after ADR-0003 is the "no display specified" notice. The
  link is gone rather than pointed somewhere: this page is not about one Display,
  the Builder already links to the one it is editing, and the admin Displays tab
  lists every address. Any future page-level "view the sign" link needs a Display in
  hand — hence the new grep in §5.
- **The user guide names sizes only as examples.** `help.php` had 1920 × 1080 in
  three places as a property of the system. It now describes the canvas as the size
  of the Display being edited, explains that the address decides which sign a screen
  shows, documents the three Viewer notices, and covers the Displays tab, the grant
  matrix and the zoom control. The size presets in `admin_panel.php` are the one
  place those numbers still belong.

## 4g. What a ten-agent adversarial audit changed

Ten independent passes, each told to break one surface and to refute its own
findings before reporting. Worth recording because the pattern in what it found
is more useful than the list: **every defect that mattered was a write that
failed quietly, and most of them printed a success message while failing.**

What held, first, because it is most of the build: cross-Display scoping survived
every attempt — forged publishes, forged section ids, element-id IDOR, numeric
display ids, `$_GET` overriding a POST. `claimLock` is genuinely one atomic
conditional UPDATE. 41 hostile screen-name tags all produced the right notice or
the right Display. No XSS anywhere in the Viewer. No hardcoded 1920/1080 left in
the Builder's JS, and its drag/resize maths is correct at every zoom level. No
upload sink anywhere uses a client-supplied filename. `schema.sql` and
`lib/schema.php` agree column for column.

What it found, by class:

- **`?:` on a decode.** `json_decode($raw, true) ?: []` reads "unreadable request"
  as "empty layout", and publishing an empty layout deletes everything. Three
  agents found this independently. The lesson generalises: in a module whose
  refusals are the only safety net, a falsy-coalesce is a decision to write.
- **`catch (Exception)` in PHP 7+.** A `TypeError` is an `Error`, so it escaped
  the catch that existed to turn a failure into a value — *after* the DELETEs had
  run. Every catch in `lib/` is `Throwable` now, and rollbacks go through a helper
  that cannot itself throw.
- **A latch set before the work it describes.** The Viewer stamped the layout hash
  before rendering, so one bad element blanked a sign permanently and silently.
- **A guard that passes when it has nothing to compare.** `hash_equals('', '')` is
  true, and the admin's own landing page never minted a token, so CSRF was off on
  every login.
- **State cached at login and never re-read.** The role. Demotion, deactivation
  and deletion all left a live session fully admin.
- **Local wall-clock time used as if it were absolute.** The lock, for one hour
  every autumn. See the amendment on ADR-0007.
- **A shared row nobody owned.** `block_styles` had two writers that disagreed
  about what a partial POST means, and `assets` had none at all — which is why
  deleting one library row could blank a line on every sign. `block_styles` got
  `lib/brand_styles.php` in that pass; `assets` got `lib/assets.php` later, along
  with the reason the sharing existed at all — see §4n.

And the test suite was itself audited by mutation: **60% of realistic single-point
defects survived it.** The scoping checks were real — the unscoped publish DELETE
fails seven of them — but the fixture set `PRAGMA foreign_keys = ON` and declared
a cascade `lib/schema.php` never creates, so several checks were verifying the
fixture rather than the module. The harness also could not fail: no error handler,
so PHP warnings were invisible; no count anchor, so deleting a whole section still
printed "0 failed". Both are closed, and `reportChecks()` now takes the number of
checks it expects. Every fix in this pass ships with a check that was verified to
fail against the unfixed code — that verification is the point, not the check.

Known and not fixed, so nobody assumes otherwise:

- ~~**The Builder addresses its Display by mutable tag**~~ **Fixed** — see §4h.
- ~~**The password reset's guess limiter lives in `$_SESSION`**~~ **Fixed** — see §4i.
- ~~**Read-only Builder is only partly server-rendered**~~ **Fixed** — see §4j.
- ~~**Convergence issues three real `ALTER TABLE`s on every authenticated
  request**~~ **Fixed** — see §4o. Three was right, and one of the other seventeen
  turned out to be an active bug rather than a wasted round trip.
- ~~**`schema.php` has no automated coverage at all**~~ **Partly fixed** — see
  §4o. Its *decision* is now a pure function with 43 checks on it. Its *statements*
  are still MySQL-only and still only reachable by `tools/rehearse_phase1.php`
  against a copy of live data, which is the tool that now also asserts the plan is
  empty once that database has converged.

### 4h. The tag addresses a Display; it does not identify one

The audit's largest remaining correctness gap, and the first of the reviewed list
to be closed. Every call the Builder makes named its Display by screen name tag
alone. A tag is admin-editable by design, and the name a rename vacates is free to
be given to another sign — so a Builder open across both changes held an address
that still resolved, cleanly and quietly, to a different screen. Its next publish
would have deleted that screen's layout, reinserted this one's, and reported
success. ADR-0006's stamp could not catch it: the stamp is per Display, both
counters are small integers, and equal ones are common.

The fix is a second parameter, not a new addressing scheme. `display_id` says which
record the page was built for; `DisplayRequest::confirmIdentity()` compares it with
what the tag resolved to and returns a new `MISMATCH` resolution when they differ.
Three properties made it cheap to add everywhere at once:

- **It only ever refuses.** A resolution can go from found to refused, never to a
  *different* Display. So a caller can start sending the id without moving where
  anything resolves to.
- **An absent claim is not a claim.** The Screens send a tag and nothing else
  (ADR-0003), and so does a Builder tab left open across the deploy. Neither is
  broken by the check, and neither is protected by it — which is the price of not
  breaking them, and the reason invariant 12 is worded as a rule for *new* callers.
- **A malformed claim is a disagreement, not a puzzle.** `?display_id[]=1`, `1abc`
  and `007` are all refused rather than coerced. Nothing that knows its Display
  sends one, so there is nothing to be forgiving about.

It is checked in `locate()`, which means the no-tag entry rule of §3 is covered too
— otherwise a write carrying a stale id and no tag would have walked straight past
the check that exists for it. Fourteen checks in the self-test, verified against
two mutations: dropping the check outright fails six, and relaxing the comparison
to `==` fails the two that pad and suffix the number.

### 4i. The guess budget moved to where the secret is

ADR-0001 rewrote the login lockout onto the account row precisely because a
counter in `$_SESSION` belongs to whoever is guessing. The password-reset passcode
still had the session-side version: five tries, counted in a cookie the guesser
controls. Clearing it bought five more against the same live code, and the step-2
lookup was keyed on `(user_id, passcode)` with no tie to the session that had
requested the code — so forty cookie jars all tested the one live six-digit number
in parallel. That is an evening's work, and it ends in someone else's account.

`password_resets.attempts` is now the budget, and `lib/password_resets.php` is the
only thing that touches the table. Three details are load-bearing:

- **The guess is spent before it is judged.** `redeem()` increments with
  `UPDATE … WHERE … AND attempts < 5` and reads the row afterwards. Read-then-write
  would let two simultaneous guesses both see four spent and both spend the fifth;
  the `UPDATE` takes the row's write lock, so one waits for the other.
- **The page cannot tell the refusals apart.** `redeem()` returns a bare boolean,
  and step 2 prints one sentence for wrong code, expired code, exhausted budget and
  *no such account*. This cost the "3 attempt(s) remaining" counter, deliberately: a
  real shared count answers "does this username exist?" out loud, where the old
  fake one — the same for everybody, because it was per-cookie — did not.
- **Comparison is `hash_equals`, not `==`.** A six-digit code compared loosely is a
  numeric-string comparison, and `'000123' == '123'` has been true on some of the
  versions this app might be running on.

Twenty-nine checks, each building a *new* `ResetTokenStore` per guess — which is
what a fresh cookie jar looks like to the server, and the only shape that can tell
an account-keyed limiter from a session-keyed one. Verified against four mutations:
counting per-caller instead of per-row fails 3, not consuming a correct code fails
4, `intval()` comparison fails 2, and skipping the discard on reissue fails 1.

One deploy note: `expires_at` is written and compared in UTC now (`gmdate`, like
the edit lock), where it used to be server-local on both sides. Any reset code
issued in the half-hour before the deploy reads as expired afterwards; the person
holding it requests another. Nothing else in the app reads that column.

### 4j. A read-only Builder now is what the file said it was

Two comments in `builder.php` stated that when somebody else holds the edit lock,
"every control that would have changed something is simply not on the page". The
control bar honoured that. `#inspector`, `#align-bar` and both editor modals were
emitted unconditionally — the whole inspector, the carousel and table editors, and
thirty-six write-intent handlers behind them, on a page that is not allowed to
write anything.

Nothing could reach them, and that is the interesting part. A read-only page
cannot select a block: both `mousedown` handlers return on `READ_ONLY`, so
`activeBlock` is permanently null, and every one of those handlers opens with
`if (!activeBlock) return`. The controls were inert. But *inert* is a property of
today's call graph, not a rule — it survives only as long as nobody adds a control
that does not need a selection, and `uploadSlideImage()` was already that: no
guard of any kind, and it posts a file. The markup was the belt, and the belt was
missing.

So the markup is now conditional, and the code is written for its absence rather
than around it:

- **Any lookup of a node in one of those blocks can come back null**, and the
  functions that still run on a read-only page say so. Three of them run on *every
  click* in the canvas area — `deselectAll`, `clearMultiSel` → `updateAlignBar`,
  `clearTargetSection` — plus `loadAssets`, which runs on every page load because
  a block pointing at a library entry still has to render.
- **One of those was already broken.** `clearTargetSection()` tested the account's
  role, but `#section-banner` is emitted only when the account is basic *and* the
  page can edit — so a read-only basic clerk got an uncaught `TypeError` on every
  click in the canvas area. The lookup now goes through `setSectionBanner()`, which
  is null-safe and needs no role test at all.
- **`uploadSlideImage()` gets an explicit `READ_ONLY` guard**, because it is the
  one handler that never needed a selected block, and "its modal is not in the
  page" is the argument this section exists to stop relying on.

What did *not* change: `CSRF_TOKEN` still ships, because a read-only admin can take
the lock over and that POST needs it, and the server-side refusals are untouched.
Console access remains console access — this closes the gap between what the file
claims and what it does, not the one between a browser and an API.

`tools/selftest_builder_readonly.js` is new, and is the reason this is checkable
rather than merely done: it strips the PHP, evaluates `builder.php`'s own inline
JavaScript with `READ_ONLY = true`, and stubs a DOM holding **only** the ids that
page emits, so any lookup of a removed control throws and a throw is a failure. It
also walks the file's `<?php if (!$readOnly):` blocks to assert the four regions
really are inside one. Sixteen checks, verified against four mutations: shipping
the inspector again fails 3, dropping `deselectAll`'s guard fails 1, restoring the
role-only banner test fails 2, and dropping `loadAssets`'s guard fails 1.

### 4k. Two things this repo believed without ever looking

**"PHP 7.1-compatible syntax — the live server's version is unverified."** That
sentence has shaped every file here. It is also the only rule in the project with
no way to check it, and the one real violation it ever caught was not syntax at
all: `session_set_cookie_params()`'s options-array form arrived in 7.3, and on 7.1
it is a warning-and-no-op that silently drops HttpOnly, Secure and SameSite from
the sign-in cookie. A syntax rule cannot catch a library signature, and neither
can `php -l` at any version. Meanwhile, if the host is actually on 8.x — which a
shared cPanel account usually is by now — the repo has been paying that price for
nothing.

**And whether the schema converged.** Invariant 10 says assume nothing about what
columns exist, and `schemaTry()` swallows the failure of a statement that cannot
apply, because most of those failures mean "already applied". The cost is that a
statement which genuinely could not run is indistinguishable from one that did.
The login lockout columns sat missing on the live database for months and nothing
said so; the feature simply did not work.

Both are now on one screen: **Admin Panel → Settings → This Server**, admin-only,
read-only, nothing to submit. It reports the PHP and MySQL versions, the time zone
(a server left on UTC prints every "editing since" seven hours out for a
Washington store), whether errors are shown to visitors or written to a log, and
what actually took on the session cookie — read back out of the *path* on a
pre-7.3 server, where that is where `SameSite` has to live. Below it, one row per
runtime-added column, green or red, each red one carrying the consequence rather
than leaving it to be inferred: `canvas_elements.display_id` missing reads
"Nothing is scoped to a Display. Do not publish."

Two design points worth keeping:

- **It reads the catalogue, never the rows.** `information_schema` is how it can
  name `users`, `displays` and `canvas_elements` without becoming the second
  writer those tables are not allowed to have — and it falls back to a
  `SELECT … LIMIT 0` on engines that have no catalogue, which is what lets the
  SQLite fixture exercise it.
- **Nothing branches on it.** No code path reads `isConverged()` to decide
  anything. A diagnostic that changes behaviour becomes a second, undocumented
  configuration system.

`admin_panel.php` now also converges the reset-token table, which otherwise only
happens on the pre-auth reset page — without that, a site nobody had ever reset a
password on would show a red row an admin had no way to clear, and the screen's
own advice ("sign out and back in") would have been false.

Twenty-one checks, including one that removes `display_id` from the fixture
outright and asserts the report goes red and says why. Verified against two
mutations — hard-coding the column check to true, and letting the no-catalogue
fallback answer true on error — each of which fails 3.

**Still open:** what the live server actually runs. The screen answers it the
first time an admin opens Settings after this deploys; until then the 7.1 rule
stands unchanged, because guessing in the other direction is the one mistake that
breaks sign-in on the live site.

### 4l. An account number is never handed to a second person

`DELETE FROM users` returned the id to the pool, and MySQL gives a freed id to the
next account created. Everything that identifies a person by number could therefore
come to mean somebody else: a `display_permissions` row that outlived a cascade
which is added by `schemaTry()` and may never have applied, a `lock_holder_id` on a
Display, a `last_published_by`, a session in a browser in the back office. Every one
of those had already been defended, individually, by remembering to — the delete
handler revoked grants and released locks by hand, with a comment explaining why.
Closing the account removes the thing all of them were defending against.

The row stays. `closed_at` is stamped, `is_active` goes to 0 in the same statement
so every existing "may this account sign in" check refuses it without being taught
anything, the grants are surrendered and the lock released — all inside one
transaction, because an account marked closed that still holds a grant is precisely
the stale pointer this exists to prevent.

Four consequences worth stating, because each is a thing somebody will meet:

- **The name stays taken.** That is deliberate: a re-registered `kayla` would
  inherit a stranger's publish history at a glance. But "username already exists"
  for a name that is nowhere on the page is a dead end, so closed accounts get
  their own list on the Users tab, and the create-user error names the clash.
- **There is no reopening.** A number that can come back into service is a number
  that can be reused. A returning employee gets a new account.
- **Because it cannot be undone, two things are now refused** that a delete never
  needed to refuse: closing your own account (which the old handler did check) and
  closing the last admin who can still sign in (which it did not — you could delete
  your way out of having any admin, and re-create one; now you cannot).
- **`AccountStore::names()` covers closed accounts on purpose.** "Published by
  Kayla" and a lock banner both resolve an id to a name, and they have to keep
  working after she leaves. That is the whole argument for closing over deleting,
  and it is asserted rather than assumed.

`closed_at` is not `is_active`. Inactive is a manager suspending somebody for a
fortnight; closed is final. Collapsing them would put a "reactivate" tick-box on the
one state that must not be reversible.

Twenty-eight checks, including a database with no `closed_at` column at all — which
is what the live server looks like until this deploys, and which must report nobody
closed rather than throwing. Verified against five mutations: going back to a
`DELETE` fails 7, skipping the grant surrender fails 1, skipping the lock release
fails 1, hiding closed accounts from `names()` fails 1, and dropping the last-admin
guard fails 2.

### 4m. The sign was one PHP warning away from printing the webroot

Two items, and they are one thing seen from either end: nothing in this repo ever
set `error_reporting`, `display_errors` or `log_errors`, and nothing on the public
path caught what escaped. So the policy was whatever the hosting account's php.ini
happened to say — on a shared host, usually "print it" — and the consequence was
not an ugly page. It was **an absolute server path rendered in grey on a menu board
in the shop**, or a white page where the prices had been, for as long as it took
somebody to walk past and mention it.

The database was the likeliest trigger. `db_connect.php` ended a failed connection
with `die("Database connection failed. Please contact your system administrator.")`
— black text, white page, on a TV, addressed to a system administrator who is not
in the room. Everything after it was uncaught: a `PDOException` from
`DisplayRequest::forViewing()` reached PHP's default handler with a stack trace
attached.

**The policy is set in code, not in `.htaccess`.** `php_flag display_errors Off`
only works under mod_php, and this app has been bitten once already by assuming a
mechanism was in force when it silently was not — the session cookie flags in
`auth.php`, which do nothing at all on PHP 7.1. Code travels with the deploy, is
true on every SAPI, and — the argument that settled it — can be read back, which is
what the new Settings card does.

**Three modes, because the last sentence is the whole problem.** The same failure
has to become a JSON error for an endpoint the Builder is polling, a plain sentence
for somebody signed in, and, on a TV, a notice that looks like the Viewer's own and
**re-checks every 30 seconds**. That last clause is the one that matters: a Screen
has nobody in front of it, so a sign taken down by a thirty-second database blip
must come back on its own or it stays down until the store closes. `api.php`'s
public poll additionally overrides the wording, because its reply is JSON but its
reader is a shop.

**The alerts are on disk, deliberately.** The commonest thing worth an email is the
database being unreachable, so a rate limiter that needed a query would fail open —
and failing open is four Screens × one poll per 30 seconds × two emails a minute,
from the address the store's real mail depends on. The limiter is a stamp file
keyed by kind *and* file *and* line, so two different bugs in an hour are two emails
and one bug hit three thousand times is one. The recipient list has the same
problem and the same answer: `AccountStore::adminEmails()` is read on the admin
panel, which is by definition a working moment, and cached to a file that is read
back when nothing is working. **With nowhere writable it sends nothing at all** —
a log entry nobody reads is recoverable; a mail bomb is not.

The corollary nobody likes: silence is not proof that nothing is wrong. An
unwritable directory means no log *and* no alert, and looks exactly like a healthy
week. That is why Settings → Errors and Alerts prints the log's path, when it was
last written, and who an alert would reach — with "Nobody" spelled out as a
sentence rather than left as an empty row.

One narrowing to the self-test harness came with this. It counted *any* PHP
diagnostic as a failed check; it now exempts diagnostics the code deliberately
suppressed with `@`. The app suppresses in exactly the places where failure is an
expected outcome rather than a defect — writing the log, stamping the limiter,
calling `mail()` on a host with no MTA — and those paths could not be tested at all
while reaching them failed the suite. Unsuppressed diagnostics, which is what the
hardening was for, still fail it; that was re-verified against an injected
`Undefined array key`.

Fifty-six checks. Verified against twelve mutations, each a defect somebody could
plausibly introduce: the Screen notice losing its 30-second re-check fails 2, losing
its reload after partial output fails 2, the sentence going unescaped fails 2, a log
entry not flattened to one line fails 2, suppressed diagnostics being logged fails
2, rotation removed fails 4, the rate limiter failing open fails 6, an empty list
blanking the cached recipients fails 2, sending without being able to record it
fails 4, a newline accepted in an address fails 2, and alerting closed or
deactivated admins fails 4 and 2.

Left standing, and worth knowing: the rate limiter's check-then-stamp is not atomic
across processes, so two requests colliding inside the same second can both send.
Two emails is the worst case and the alternative is a lock file with its own failure
modes. And `#9` — schema failures logged rather than papered over — is not done;
`ErrorPolicy::report()` is the seam it attaches to.

---

### 4n. A failed upload said nothing, and the library shared rows between signs

Decisions `#6` and `#7`. Two problems, and they meet in the same place: the file
somebody chose in the Builder, and what the app did with the content of a block.

**The upload that faded away.** Three of the four upload handlers had
`.then().then()` and no `.catch()`. In the shop that reads: an admin picks a 60 MB
clip on the store's Wi-Fi, `builder.php` shows *Uploading video…*, the request
dies, and **nothing else ever runs**. The toast fades after three and a half
seconds and that is the last word on the subject. They publish, get a green
*Published to Deli Board*, and the sign shows an empty rectangle where the video
should be. `uploadSectionBg` and `uploadBlockImage` were worse in one respect: no
progress toast at all, so a failed upload and one still uploading looked identical.
`r.json()` was a second silent failure on the same line — it rejects on any reply
that is not JSON, which is exactly what a file over `post_max_size` produces.

There is now **one** function, `startUpload()`, on `XMLHttpRequest` rather than
`fetch` — because `fetch` cannot report upload progress, and progress was half of
what was missing. Every ending has a branch that puts words on the screen: too big
to send, connection dropped, browser gave up, HTTP status ≥ 400, a body that will
not parse, a JSON refusal, a success with no path in it. Two details that are not
decoration. The file input is **cleared at the end of every attempt**: without it,
choosing the *same* file again fires no change event, so the obvious response to a
failed upload — try it again — did nothing whatsoever. And each handler captures
its block rather than reading `activeBlock` in the callback, so a reply arriving
after the selection moved on lands on the block that asked for it or on nothing.

**The limit was the app's opinion, not the server's.** `api.php` refused over
50 MB and named that number. On shared hosting the binding ceiling is usually one
of PHP's two, and the one that binds most often is not an error PHP reports:
exceeding `post_max_size` makes it abandon the request body and carry on, so
`$_POST` is empty, the CSRF token is missing, and a 40 MB video was answered
**"Security token mismatch. Please reload the page and try again."** Reloading
changes nothing. `UploadLimit` owns the arithmetic; `api.php` answers the
dropped-body case *before* the CSRF gate; and the Builder is handed the number so
the file is refused in the picker instead of after two minutes of uploading. The
same number is what Settings → This Server now prints, and it says when the host
rather than the app is the limit — because a video that will not fit is a fact
worth knowing before somebody drives in to change a sign.

**The library shared rows, and that was the dangerous half.** `assets` was the last
table with no owning module: five statements in `crud.php`, one in `api.php`, one
inside `LayoutStore`'s publish transaction. What those three files disagreed about
was pooling. A published text block's words moved into `assets` and the element was
left pointing at the row with `manual_content` set to NULL — **de-duplicated by
exact content**. So the deli board and the lobby screen both saying "OPEN 7 DAYS"
shared one row; an admin editing it changed both signs within thirty seconds with
nobody publishing anything, and an admin deleting it blanked that line on both,
permanently, because `asset_id` is ON DELETE SET NULL and neither element had a
copy left. There is no undo.

`AssetLibrary::pool()` now always inserts. The de-duplication was an optimisation
for a database with one sign in it, where two elements sharing a row could only be
two elements on the same canvas.

**And the cost of that, paid rather than hidden.** Publishing is destructive, so the
third time somebody fixes a typo the first two rows are pointed at by nothing.
`assets.auto_pooled` marks a row a publish made, and only marked rows are ever
swept — an image an admin uploaded and has not placed yet is next week's job, not
junk, and renaming a pooled row clears the marker because naming it is how somebody
adopts it. Two sweeps, because there are two ways to strand a row and only one
happens where a publish can see it: a publish drops what *its own previous layout*
held and nothing holds now, and the Library's tidy button reaches what no publish
can (a block deleted from the admin Work Area releases its row with no publish
anywhere near it).

Three choices inside that worth stating. The publish sweep is **scoped to those
ids** rather than a table-wide `DELETE … WHERE NOT IN (…)`, which would take locks
across every Display's rows and could deadlock with a publish to a different sign.
"No longer referenced" is asked of **every** Display, not the one publishing — a row
shared by two signs from before this change is still live for the other one. And
`referencedAssetIds()` returns **null** rather than an empty list when it cannot
read: an empty list means "nothing is referenced", and a caller acting on that
would sweep the entire pool.

Counting references means reading `canvas_elements`, which is `LayoutStore`'s table.
So neither module answers the whole question — `LayoutStore` says which ids are
referenced, `AssetLibrary` says which of the rest are pooled, and `crud.php` puts
the two together. `discardPooled()` carries the marker as a database predicate, so
a caller that miscounts can leave the library untidy but cannot blank a sign.

Ninety-seven checks across three suites, including a new one:
`tools/selftest_builder_uploads.js`, which evals the same inline JavaScript as the
read-only harness under the opposite premise — an admin on a Display nobody else
holds — and drives a stubbed `XMLHttpRequest` through every ending one at a time. A
missing `.catch()` is invisible to `php -l` and to `node --check`; the only way to
see one is to run the handler with a request that fails. Its `land()` helper treats
a throw inside `onload` as a failure in its own right, because in a browser that is
an uncaught error nobody sees.

Verified against eighteen mutations, all killed: pooling de-duplicating again
fails 5, the sweep ignoring the marker fails 4, editing not adopting the row
fails 4, publish never sweeping fails 4, the sweep ignoring other Displays fails 2,
unreadable references reading as none fails 1, a failed pool write linking to id 0
fails 5; no size ceiling in the picker fails 16, the network-error branch removed
fails 10, a non-JSON reply treated as success fails 6, the picker not cleared after
a failure fails 4, a second upload allowed to race the first fails 4, progress never
reported fails 2, a success with no path applied fails 4; a zero ini limit becoming
the answer fails 3, sizes rounding up fails 1, an empty POST looking dropped
fails 1, and the ini shorthand suffix ignored fails 7.

Left standing, and worth knowing:

- **The `Auto: ` label prefix is still a marker**, used when `assets.auto_pooled`
  did not land. It is the only thing that identified a pooled row before the column
  existed and it is what the column is backfilled from, so the fallback is coherent
  rather than arbitrary — but an admin who names a row `Auto: something` and never
  places it on a sign can have it tidied away. The Library's label field says so.
  Settings → Database Structure says whether the column is there.
- **The tidy button is not transactional.** A block published between reading the
  orphan list and deleting it would have its row refused by the database rather
  than removed, leaving the library untidy. That is the right way for a sweep to
  fail, and `discardPooled()` swallows it for exactly that reason.
- **`post_max_size` detection is inferential.** "A POST that announced a content
  length and arrived with no fields and no files" has exactly one cause *while no
  endpoint reads the raw body*. Nothing in the repo reads `php://input`; an endpoint
  added later that does would need this reconsidered, and the §5 grep is there for it.
- **Uploads still have no cancel button.** A ten-minute timeout ends a stuck one;
  nothing lets somebody stop one deliberately. `xhr.onabort` is wired for it.

### 4o. Convergence rebuilt every sign's table on every page load

`ensureSignageSchema()` ran twenty statements on every authenticated request and
swallowed the twelve that failed. That was recorded above as a cost worth paying
until a slow publish held the table. Reading all twenty properly turned up three
separate things, only one of which was about speed.

**Three of them rewrote the layout table's definition every time.** The two ENUM
widenings and the `display_id NOT NULL` tighten are all `ALTER TABLE
canvas_elements MODIFY COLUMN`, and all three *succeed* on a database where the
column already says exactly what they ask for: MySQL has no "nothing to do here"
path for `MODIFY COLUMN`, it performs the ALTER. An ALTER takes an exclusive
metadata lock on `canvas_elements`, the table holding every Display's layout. A
publish transaction holding that table makes the ALTER *wait*, and in MySQL
everything arriving behind a waiting exclusive lock waits too — including the
Screens' `get_layout` polls, the one query in this app that must never block. So one
person opening the Builder while somebody else published could stall every sign in
the store. Nobody had seen it, and it needed no bug to happen.

**One of them was an outright bug, not a wasted round trip.** The
`assets.auto_pooled` backfill reads the `Auto: ` label prefix, and it ran every
request. Adopting a pooled row in the Library — saving it — clears the marker and
leaves the label alone, which is exactly what §4n's "renaming an auto entry makes it
yours" means. The backfill re-marked it on the next page load, and Tidy up could
then delete what somebody had claimed. The self-test now demonstrates that: adopt a
row, run the statement directly, watch the marker come back. What stops it is not a
change to the statement but the gate that lets it run only on the request that adds
the column — the one request where no row can have been adopted yet.

**And DDL commits the surrounding transaction in MySQL, silently.** Nothing calls
convergence inside one today. With a converged database there is now no DDL to do
it with either.

The fix is to ask first, and the design point is *where* the asking lives:

- **`readSchemaFacts()`** does three `information_schema` reads — columns with their
  types and nullability, index names, constraint names — filtered to the eight tables
  this app has an opinion about, because on shared hosting one MySQL database can
  hold several applications.
- **`SchemaFacts`** is a pure value object over those three maps, and every answer is
  three-valued. `null` means *cannot tell*, and that is the whole safety argument: an
  unreadable catalogue puts every statement back in the plan, which is precisely the
  behaviour this file had before it started asking. Reading `null` as "not there"
  would issue `CREATE TABLE` against a live database; reading it as "there" would
  stop converging. Both mutations are in the check.
- **`signageSchemaPlan()`** is pure — facts in, an ordered list of work out. That is
  the only reason this file has automated coverage at all: its statements are
  MySQL-only, so the SQLite fixture can never run them, but it can run the decision.

Three gates needed care:

- **A column versus a constraint on a table being created.** A `CREATE TABLE` here
  declares its columns, so the follow-up `ADD COLUMN lock_taken_at` is redundant when
  the table is absent — but it does *not* declare its foreign keys, which are added by
  their own `ALTER`, so those are still needed on a table created a moment ago. The
  two helpers answer the missing-table case oppositely and say why.
- **The ENUM comparison.** `COLUMN_TYPE` is compared with whitespace stripped and
  case folded, and both ENUM definitions are written once as constants used by the
  statement *and* the comparison, so they cannot drift. Anything that does not match —
  including a future MySQL that words it differently — counts as a difference and the
  ALTER runs. A needless ALTER is what this removes; a skipped one would be a missing
  column definition.
- **`display_id`'s three-statement sequence.** Added nullable, backfilled, then
  tightened, in that order, because a `NOT NULL` column with no default cannot be
  added to a table that already holds rows. So "needs tightening" has to answer *yes*
  for a column that is absent, since the plan is about to add it nullable. The backfill
  is gated on the same fact: while the column can hold a `NULL`, an unscoped row can
  still be hiding, and once it cannot, there is nothing to find.

Two steps stay in every plan, because no catalogue can answer "are there any rows":
seeding the six branded block types, and creating the drive-thru Display. Both are
now a small `COUNT` that usually finds what it is looking for and stops — the block
style seed in particular used to send a six-row `INSERT IGNORE` at a table the Brand
Standards form might be saving to at that moment.

`ServerReport` was doing the same catalogue read with its own query, one statement
per column. It now asks `readSchemaFacts()`, which makes `lib/schema.php` the single
answer to "how do we find out what columns exist" — and drops the report from seven
queries to three. It trusts that answer only for a table the read actually covered;
anything outside it still falls back to `SELECT … LIMIT 0`, because a confident
"missing" from the one screen in this app that exists to be trusted would send
somebody hunting for a column sitting right there.

The result on a converged database: three catalogue reads, two small `COUNT`s, and
no DDL. On a database that cannot be read: exactly what it did before.

**43 checks**, and one of them is worth naming because it is the check that made the
rest credible: the old and new code were run side by side with `schemaTry()` stubbed
to record, and the twenty statements they issue when nothing can be gated are a
byte-identical set. Nothing about *what* convergence does changed — only when.
`tools/selftest_layout.php` also runs the catalogue reader for real, against a SQLite
database wearing an attached schema called `information_schema` with a
`sqliteCreateFunction`-supplied `DATABASE()`, so the query text itself — three table
names, four column aliases, the `IN` list, the `YES`/`NO` of `IS_NULLABLE` — is
executed rather than trusted. A typo there would otherwise have surfaced as a live
server that had quietly stopped converging. Fourteen mutations, all killed (kill
counts 9, 3, 3, 2, 1, 1, 1, 2, 2, 1, 1, 5, 15, 2). `tools/rehearse_phase1.php`
carries the claim only MySQL can settle: after converging a copy of live data, the
plan for that database is empty, and it prints what is still wanted if it is not.

Left standing:

- **The plan is built from facts read before any of it runs.** That is fine because
  the order already works from any starting state and gating only removes proven-done
  work — but it means a statement that fails is not re-decided within the same
  request. It is re-attempted on the next one.
- **The pool-marker backfill has one shot.** If the `ALTER` that adds
  `auto_pooled` lands and the backfill that follows it does not, the column exists,
  so no later request will queue the backfill again — the accumulated pool stays
  unmarked and Tidy up reports zero forever. No sign is affected and nothing is
  deleted; it is a button that under-reports. The recovery is one statement by hand,
  and HANDOFF §5 says which.
- ~~**`runSchemaPlan()` returns the failures and nothing reads them**~~ **Fixed** —
  see §4p, which is the whole of what the return value was for.
- **`schemaTry()` still swallows.** The catalogue can be silent about a constraint
  under a name MySQL chose itself, and a convergence failure must never break the
  request that happened to trigger it. What changed in §4p is that the reason no
  longer dies with it.

### 4p. A statement that genuinely could not run now says so

`schemaTry()` has swallowed every failure since the pattern was invented, because
most of them mean "already applied". The cost is stated in invariant 10 and it was
paid in full once already: the login-lockout columns were missing on the live
database for months, the feature silently did not work, and nothing anywhere said
so. `ServerReport` closed half of that — an admin who *opens* Settings sees a red
row — but a diagnostic nobody looks at is not a diagnostic when the person who would
look is the one who does not know to.

What made this fixable was §4o. Once convergence asks the catalogue first, the
statements it sends are the ones the database said it needed, so one that fails is
information. Before, twelve failed every request by design and there was no way to
tell one kind from the other.

The rule is deliberately narrow, and it is the whole safety argument:

> **Only a statement the catalogue positively said was missing is ever reported.**

Every plan entry carries the `need` it was included on. `true` means the catalogue
was read and the thing is not there. `null` means the catalogue could not be read and
the statement is a guess — and on such a host twelve of them fail on every single
request, so reporting those would fill an inbox with the normal case and teach
whoever reads it to ignore the one that matters. `null` is never reported. That is
what stops a host which hides `information_schema` from becoming a mailing list.

Two entries carry `true` even on a host with no readable catalogue, and that is not
an exception to the rule but the rule applied honestly: the block-style seed and the
Display seed are decided by a row count, not by the catalogue, and a count runs and
answers everywhere. Their failure means something real wherever it happens.

Three further choices:

- **The throttle covers the log, not just the email.** `AlertMailer` has always
  limited itself to one message per problem per hour. The log had no limit, and a
  refused statement is retried on every signed-in page load *and* on the Viewer's
  self-heal path every 30 seconds per Screen — thousands of identical lines a day in
  a file that rotates at 2 MB, burying everything worth reading. `ErrorPolicy::report()`
  takes a window and skips both. It keeps its own stamp rather than reusing
  `AlertMailer`'s, because that one is only written when there is somebody to email:
  on a site where no admin has an address on file it would never be written, the
  throttle would never engage, and the log — the only record left — is exactly what
  would flood.
- **The key is the set of failures, not the clock.** So the same problem stays quiet
  for an hour and a *new* one is reported immediately rather than waiting out the old
  window.
- **The message says what, not how.** It names each statement in the words the plan
  gave it (`display_id is NOT NULL`), carries the database's own reason, and points
  at Settings → Database Structure for what a missing column costs. It never contains
  SQL. Restating the consequences here would be a second list to keep in agreement
  with `ServerReport::convergence()`, and eventually they would disagree about one.
  It also says the thing an admin most needs to hear: a row that is green on that
  screen is already in place, so the statement is being refused for some other reason
  — most likely a name the database chose for itself — and nothing is wrong with the
  data.

One quiet path was found while wiring this up and is now not reported: two
first-ever requests racing to seed the drive-thru Display means the loser's `INSERT`
fails on a unique tag. The Display exists, which is all the seed was for, so
`seedLegacyDisplay()` re-asks `legacyDisplayId()` and returns success. Without that,
the first deploy would have emailed an admin about two people signing in at the same
moment.

**Fifty-three checks.** Two are worth naming. The first runs a blind plan against the
SQLite fixture — the shape of a host with no catalogue, where nearly every statement
fails — and asserts that not one line is logged and not one email is sent. The second
exists because a mutation exposed it: every other check called
`reportSchemaFailures()` directly, so deleting the call from `ensureSignageSchema()`
altogether failed nothing at all. Closing that needed the "once per request" latch to
become something a test can clear, which is `SchemaLatch`. Twelve mutations, all
killed (kill counts 9, 11, 6, 3, 2, 2, 2, 2, 2, 2, 1, 1).

Left standing:

- **The race branch in `seedLegacyDisplay()` cannot be reached in one process.**
  SQLite rolls a trigger's writes back with the statement that failed, so the
  interleaving is not constructible. The branch is three lines with no logic of its
  own; what it asks — `legacyDisplayId()` — is covered directly, including the
  renamed-tag fallback.
- **Nothing reports a *skipped* statement.** If the catalogue says a column is there
  and it is not — a catalogue this app has no reason to distrust, but still — nothing
  notices. `ServerReport` is the answer to that, and it asks the same reader, so the
  two would be wrong together.
- **`api.php`'s upload-fault report is still unthrottled.** Its window is a person
  clicking, which bounds it. Worth revisiting if a broken temp directory ever turns
  out to produce a run of them.

---

## 5. Verification

No CI, no test suite, no PHP runtime on the target — verification is deliberate
and manual. Run all of it before every push.

```
php -l <every touched .php>              # syntax; also a GitHub Action
php tools/selftest_layout.php            # the real modules, in-memory database
node tools/selftest_builder_readonly.js  # builder.php's own JS, run against a DOM
                                         # that has only what a read-only page emits
node tools/selftest_builder_uploads.js   # the same JS under the opposite premise — an
                                         # admin who can edit — driving a stubbed
                                         # XMLHttpRequest through every way an upload ends
grep -rn "canvas_elements" --include=*.php .   # lib/layout_store.php; plus schema.php's DDL,
                                              # the get_canvas_elements endpoint NAME, and
                                              # server_report.php's expected-column list
grep -rn "information_schema\." --include=*.php lib/  # only lib/schema.php: the three reads
                                              # plus one comment. server_report.php asks
                                              # readSchemaFacts() instead of writing a fourth
                                              # query, so there is one answer to "which columns
                                              # exist". A new hit in lib/ is a second opinion —
                                              # put the question on SchemaFacts instead.
                                              # (tools/ has its own: rehearse_phase1.php reads
                                              # KEY_COLUMN_USAGE against real MySQL, and
                                              # test_fixture.php *builds* a fake catalogue)
grep -rn "schemaTry(\$pdo" --include=*.php .   # only lib/schema.php, and only from inside the
                                              # named steps. A statement added anywhere else
                                              # bypasses signageSchemaPlan() and is therefore
                                              # ungated and untested — invariant 19
grep -rn "ErrorPolicy::report" --include=*.php .  # api.php (an upload the server refused),
                                              # lib/schema.php (a schema statement it refused),
                                              # and one check in the self-test. A new caller is
                                              # a new thing an admin gets emailed about: check
                                              # it cannot fire on a condition the app expected,
                                              # and give it a window if it can repeat on its
                                              # own — invariant 20
grep -rn "DELETE FROM users" --include=*.php . # nothing outside tools/ (invariant 14). Accounts
                                              # are closed, never deleted, so a freed id can
                                              # never be handed to somebody new
grep -rn "closed_at" --include=*.php .        # lib/accounts.php, schema.sql's DDL, the fixture,
                                              # and ONE render in admin_panel.php that prints the
                                              # date. A hit that *decides* something is a second
                                              # opinion about what closed means — ask
                                              # AccountStore::isClosed() instead
grep -rEn "(INTO|UPDATE|FROM|JOIN|TABLE) +`?displays`?" --include=*.php .  # lib/displays.php + schema.php's ALTERs
grep -rn "INTO display_permissions\|FROM display_permissions" --include=*.php .  # only lib/grants.php, plus tools/
grep -rn "lock_holder_id\|lock_activity_at\|lock_taken_at" --include=*.php .  # only lib/displays.php + lib/schema.php
grep -rn "block_styles" --include=*.php .     # only lib/brand_styles.php + schema.php's seed
grep -rEn "WHERE +`?id`? *= *'?1'?" --include=*.php .  # must be empty — whitespace and quotes included
grep -rn "1920\|1080" --include=*.php .        # admin size presets, the seed, tools/, and prose
grep -rn "viewer.php\"\|viewer.php'" --include=*.php .  # every link must carry ?display=
grep -rn "catch (Exception" --include=*.php lib/  # must be empty: a TypeError is an Error, not an Exception
grep -rn "display_errors\|error_reporting(\|set_exception_handler\|register_shutdown" --include=*.php .
                                              # lib/error_policy.php owns all of it, plus tools/ (the
                                              # self-test harness has handlers of its own on purpose).
                                              # A second file setting any of these is a second opinion
                                              # about what a visitor sees when something breaks —
                                              # invariant 16
grep -rn "hash_equals(" --include=*.php .     # auth.php's csrfOk(), which fails closed on an empty token,
                                              # and the passcode comparison in lib/password_resets.php
grep -rEn "(INTO|UPDATE|FROM|TABLE) +password_resets|reset_attempts" --include=*.php .
                                              # only lib/password_resets.php, plus tools/. A statement in
                                              # reset_password.php means the token table grew a second
                                              # writer; `reset_attempts` anywhere means the guess budget
                                              # crept back into the session — invariant 13's whole point
grep -rEn "(INTO|UPDATE|FROM|JOIN|TABLE) +`?assets`?" --include=*.php .
                                              # only lib/assets.php, plus schema.php's ALTER and the
                                              # fixture — with ONE standing exception: the LEFT JOIN in
                                              # LayoutStore::snapshot(), read-only and on the path a
                                              # Screen polls every 30 seconds. A second writer here is
                                              # how row sharing came back — invariant 17
grep -rn "auto_pooled\|Auto: " --include=*.php .  # lib/assets.php owns the marker; schema.php backfills
                                              # it; crud.php renders a badge and warns in the label hint.
                                              # Anything that *decides* whether a row may be deleted
                                              # belongs in discardPooled(), not in a caller
grep -rn "post_max_size\|upload_max_filesize\|MAX_BYTES" --include=*.php .
                                              # lib/upload_limits.php and the one row in
                                              # server_report.php that prints its answer. A number in
                                              # any other file is an opinion about a limit it cannot
                                              # see — invariant 18
grep -rn "php://input" --include=*.php .      # must be empty: UploadLimit::bodyWasDropped() infers the
                                              # post_max_size case from an empty $_POST, which only
                                              # holds while nothing reads the raw body
grep -rn "[^_]DISPLAY_TAG\|waDisplay()" --include=*.php .  # every request naming a Display must send
                                              # DISPLAY_ID / waDisplayId() with it (invariant 12), which
                                              # omission silently opts out of. viewer.php is the one
                                              # exception: a Screen sends the tag alone (ADR-0003)
```

`php -l` cannot see inline JavaScript, and `builder.php` is ~3300 lines of it.
Anything touching that file needs reading, not linting. `node --check` over the
extracted `<script>` body proves it parses; the two node suites go further and *run*
it. `selftest_builder_readonly.js` stubs a DOM holding only the ids a read-only page
emits, which is the only automated way to catch a lookup reaching for a control the
lock took away. `selftest_builder_uploads.js` takes the opposite premise — an admin
who can edit everything — and drives a stubbed `XMLHttpRequest`, which is the only
way to see a missing `.catch()`: the file parses perfectly without one.

`schema.sql` has no automated check at all — nothing reads it, so a column missing
from it fails silently on a future rebuild and nowhere else. Diff it against
`lib/schema.php` by eye whenever either changes (invariant 15), and use
`tools/rehearse_phase1.php` on a copy of live data to see what MySQL actually ends
up with.

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

In the event, Phases 1–5 went out as one PR — `main` had none of them, so there was
nothing to restart from and no way to review Phase 5's lock without the Displays it
locks. Phase 6 joined it for the same reason: the docs it corrects describe the code
in that PR. The rhythm applies from the next phase of work, whatever it is.
