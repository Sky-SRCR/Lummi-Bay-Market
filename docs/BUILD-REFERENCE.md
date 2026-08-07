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
| `schema.php` | `ensureSignageSchema(PDO): void`, plus the three pieces it is made of: `readSchemaFacts(PDO) → SchemaFacts`, `signageSchemaPlan(SchemaFacts) → array`, `runSchemaPlan(PDO, array) → array` | Every idempotent `ALTER`/`CREATE`, the `displays` and `display_permissions` tables, `display_id` + backfill + index + FK, the drive-thru seed, the Brand Standards row seed, the "run at most once per request" latch — and **whether any of it needs to run at all**. The one place in the repo that reads `information_schema`; `ServerReport` asks this rather than writing its own catalogue query. `signageSchemaPlan()` is pure (facts in, ordered work out), which is the only reason this file has any automated coverage: its statements are MySQL-only and the fixture is SQLite. `runSchemaPlan()` returns the entries that failed, each with the database's own reason, and `reportSchemaFailures()` tells an admin — but only about a statement the catalogue said was missing, never about one included as a guess (invariant 20). `SchemaLatch` is the "once per request" latch, as something a test can clear. And the second door: `repairSchemaAfterFailure(PDO, &$why) → bool` is how a caller that has *already failed a query* converges, with the three refusals of invariant 21 in front of it; `schemaErrorSaysTableMissing($sqlstate, $message)` is the only thing outside this file that needs saying about a database error, and `withSchemaRepairLock()` makes convergence installation-wide single-file. |
| `displays.php` | `Display` + `Background` + `LockState` value objects, `DisplayStore` | **Every** `displays` statement: tag rules and suggestion — including that a non-scalar folds to `''` rather than to the perfectly valid tag `array` (invariant 24), which matters because the panel's create and delete forms read a posted tag without going near `DisplayRequest` — canvas bounds, background intents, the publish stamp and record, the edit lock (claim / release / seize, and the idle window that decides held-from-free on read), and self-healing when the table is not there yet. It decides only whether the error was the kind a repair could fix; whether repairing is *safe right now* belongs to `schema.php` (invariant 21), because that question is about DDL and transactions rather than about the `displays` table. |
| `grants.php` | `GrantStore`, `Actor` | **Every** `display_permissions` statement, and the whole of "may this account have that Display?" — the two axes of ADR-0005 combined in one predicate, `Actor::mayOpen()`, that the seam and the picker both ask. |
| `display_admin.php` | `DisplayAdmin(PDO, DisplayStore, LayoutStore, GrantStore)` → `DisplayResult` | Administering a Display: what a complete one needs, creating it blank or as a duplicate of one the same shape, renaming, retiring, destroying it with its layout and its grants, and setting the access matrix — each all-or-nothing. Writes no SQL of its own; holds the transaction that spans the three stores. `setAccess()` takes **both** axes of the matrix the form covered — the accounts *and* the Displays — because an unticked box and a cell the form never rendered are the same absence in a POST, and only one of them means "revoke"; and a revoke frees the edit lock on the Display it takes away, by holder, inside the same transaction. |
| `layout_store.php` | `LayoutStore(PDO, DisplayStore)` | The publish transaction end to end: edit-lock and staleness checks, wipe-and-reinsert scoped to one Display, temp-id mapping, asset auto-save, plain-text stripping, admin/basic section rules, element index, lock-checked hide/delete, `assetUsage()` — which Displays depend on a library entry — and the sweep of the library rows a publish strands, scoped to the ids that Display's own previous layout held. |
| `assets.php` | `AssetLibrary(PDO)` — `all` / `forId` / `create` / `update` / `delete` / `pool` / `pooledNotIn` / `discardPooled` | **Every** `assets` statement. The decision it holds: `pool()` no longer de-duplicates, so a published text block's words belong to that block alone — sharing a row meant editing one line changed two signs and deleting it blanked both, permanently, with no undo. The cost is rows left behind, so a pooled row carries a marker and only marked rows are ever swept; a row a person made, or renamed, survives every sweep however it is asked. `firstCharacters()` keeps a label from being cut mid-character. One documented read of `assets` lives elsewhere: `LayoutStore::snapshot()`'s LEFT JOIN, read-only and on the path a Screen polls every 30 seconds. |
| `upload_limits.php` | `UploadLimit::bytes` / `describe` / `describeBytes` / `bodyWasDropped` / `smallestOf` / `toBytes` | How big a file can actually reach this server — the smallest of the app's 50 MB ceiling and PHP's `upload_max_filesize` and `post_max_size`, not the app's opinion. And the silent case: exceeding `post_max_size` is not an error PHP reports, it abandons the body, so a 40 MB video was answered *"Security token mismatch. Please reload the page."* `smallestOf()` takes the ini values as an argument because both settings are PHP_INI_PERDIR and the cases worth testing are unreachable otherwise. Depends on nothing. |
| `brand_styles.php` | `BrandStyles(PDO)` | The six branded block types: the only reader and writer of `block_styles`, the validation for every stored value, and the rule that a type absent from a save is left untouched. |
| `password_resets.php` | `ResetTokenStore(PDO)` — `issue` / `verify` / `consume` / `redeem` / `discard`, and `PasswordResetCompletion(PDO, ResetTokenStore, AccountStore)` → `ResetOutcome` | **Every** `password_resets` statement, the 30-minute lifetime, and the guess budget: five tries per issued code, counted on the code's own row so a fresh cookie cannot buy five more. `redeem()` returns a bare boolean on purpose — the reset page must answer "wrong code", "no such account" and "budget spent" in the same words, and a caller that cannot tell them apart cannot leak the difference. It is now the composition of two halves that have to fall on opposite sides of a transaction boundary: `verify()` spends the guess and must never be rolled back, `consume()` spends the code and must be. `PasswordResetCompletion` is the use case (invariant 22) — code consumed, password changed, lockout released, or nothing at all — and `ResetOutcome` has three answers rather than two, because "refused" and "the database would not take it" have to look different to the visitor and identical to a stranger probing for usernames. |
| `accounts.php` | `AccountStore`, `AccountAdmin` — `close()` / `edit()` → `AccountResult` | What it means for an account to be **closed**, and the transaction that closes one: grants surrendered, edit lock released, `closed_at` stamped, all or nothing. Also the two refusals that exist because closing cannot be undone — your own account, and the last admin who can still sign in. And `edit()`, the other three-table change: the role, the active flag and the email in one write, then the grants a **promotion** makes meaningless (an admin holds every Display by role, so the rows would sit there displayed nowhere and removable by nothing) and the locks a **demotion** puts out of reach (no grants left, so the account cannot even release what it is holding). Not a gatekeeper for all of `users`: creating an account and setting somebody's password from the panel are still written there, and sign-in by `login.php`. What lives here is closure and the reads that depend on it, so the five files with an opinion about a user row cannot disagree about what a closed one means — plus the three `users` writes that have to happen inside somebody else's transaction, `setPassword()`, `clearLoginLockout()` and `updateProfile()`, because a page cannot hold a transaction over SQL it writes itself. Those three are the only methods in the class that let an exception out, deliberately: everything else answers a question, and a question is better answered "no" than not at all, but these are halves of a change that must not half-happen. `clearLoginLockout()` answers true when the three ADR-0001 columns are absent, for the same reason `isClosed()` answers false: a database without them has never locked anybody out. |
| `server_report.php` | `ServerReport(PDO)` — `runtime()` / `convergence()` / `isConverged()` | What machine this is, and whether the schema actually converged. Reads the database catalogue (through `readSchemaFacts()`, not its own query) and PHP's own configuration, and **no application data at all** — which is why it may name `users`, `displays` and `canvas_elements` without being a second writer. It trusts the catalogue only for a table the read actually covered; anything else falls back to a `SELECT … LIMIT 0`, because a confident wrong "missing" from the one report meant to be trusted is worse than no report. It exists because two things this repo depends on were never observable: the live PHP version (the whole 7.1 rule rests on it) and whether a `schemaTry()` statement landed, which by design fails silently. |
| `error_policy.php` | `ErrorPolicy::install(mode)` / `log` / `fail` / `report` / `noticeFor` / `status` | What happens when something goes wrong: the ini settings, set in code so they travel with the deploy and can be read back; the three handlers; where the log lives and when it rotates; and — the part that needed a module rather than a line — the last thing a request prints, which differs by audience. A Screen gets a self-re-checking kiosk notice, an endpoint gets JSON its caller can parse, a person gets a sentence. `noticeFor()` is pure so all three are testable without a failing server. `report()` is the one for a problem the app survived but an admin should hear about, and it takes a window: a problem that recurs on its own schedule — a schema statement retried on every page load, or every 30 seconds per Screen — has its *log line* throttled too, not only its email, or the record of it buries everything else in a 2 MB file. `firstInWindow()` and `stateFile()` are public for the same reason `report()` needs them: a repeated *attempt to fix* something needs the same restraint as a repeated report of it, and this module is where the state directory is decided. Depends on nothing: no database, no session, no config. |
| `http_cache.php` | `HttpCache::headers()` / `neverStore()` | What may be kept of a reply this app gives: nothing. The three headers it takes to say that to caches of every vintage — `no-store` is the only one that forbids *keeping* the bytes; the other two are for HTTP/1.0 caches, which is what a signage widget or a shop router turns out to be — and the one condition under which it must not try, output having already begun. `headers()` is the rule as data so it can be read without a web server, the same reason `ErrorPolicy::noticeFor()` is pure. There are exactly two callers, `auth.php` and `db_connect.php`, because every entry point includes at least one of them and neither is universal on its own — `viewer.php` opens no session, `logout.php` and `setup_branding.php` open no database. Not three, and not one: there is no page in this app worth caching, and a second *opinion* about that is how one path gets missed, while a second *statement* of the same rule is what makes the coverage total. Deliberately does not reach `uploads/`: those are served by Apache, their filenames carry a `uniqid()` so the bytes behind a path never change, and no-storing a 40 MB video would re-fetch it on the store's connection every time a sign reloaded. Depends on nothing. |
| `alerts.php` | `AlertMailer(stateDir, siteName)` — `notify` / `remember` / `recipients` | Telling somebody. Both halves are on disk rather than in the database, because the commonest thing to alert about *is* the database: the rate limiter is a stamp file (one email per problem per hour, keyed by kind + file + line) and the recipient list is a cache written whenever an admin opens the admin panel. With nowhere writable it sends nothing at all — a limiter that fails open means one email per Screen per poll. `deliver()` is the single line that reaches `mail()`, separated so the rules can be tested without one. |
| `plain_text.php` | `toPlainText(string): string` | ADR-0002's sanitising, in a file with no session side effects so the store can include it. |
| `display_request.php` | `DisplayRequest::forViewing/forEditing(...)` → `DisplayResolution` | Which Display an HTTP request means and whether the account asking may have it, the ADR-0003 notice wording per failure case, **the status line that goes with it** (`httpStatus()`, and `statusForKind()` for the one case nothing can construct), and the editing entry rule. The one place grants are enforced. It also decides what a parameter that is not a string means: an array `display` is no sign named, an array `display_id` is a refused write, and the two answers differ because an address and a confirmation of one are different jobs (invariant 24). The wording and the code live together because a Viewer and the poll inside it answering one fact with two different codes is a disagreement nobody would notice was wrong. |

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
   requests, plus one self-healing retry if the schema is genuinely absent — bounded
   by invariant 21, which is what stops "one retry per store per request" meaning
   two a minute per Screen for as long as the repair keeps failing.
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
   Two consequences of the axes crossing, both of them writes that have to happen
   somewhere. **A grant taken away frees the edit lock**, because the account can no
   longer open the Display to release one — `DisplayAdmin::setAccess()` does it by
   holder, in the same transaction, so a colleague on the same sign keeps theirs.
   **A promotion to admin clears that account's grants**, because from then on
   nothing displays them and nothing could remove them; `AccountAdmin::edit()` does
   it, and frees the locks on the way back down. Anything new that changes what an
   account may reach — a role, a grant, a Display going out of service — answers the
   same question: what is that account holding right now that it will not be able to
   let go of?
   The four ways that happens today are a revoked grant, a demotion, a **suspended
   account** (`AccountAdmin::edit()`, on `!$isActive` rather than on "was just
   deactivated") and a **Display turned off** (`DisplayAdmin::setActive()`, which frees
   only a non-admin holder's lock, because a retired Display stays an admin's to work
   on). Renaming a tag is deliberately *not* one of them: it changes the address, not
   who may edit, so the holder keeps the lock and their page is asked to reload.
   **And a lock is never honoured for a holder who cannot sign in.** Freeing at the
   moment of the change only ever covers the paths somebody enumerated;
   `LockState::isHeld()` returning false for an inactive holder covers the rest, and is
   the only part that helps a row already stranded on the live database. The same rule
   is in `claimLock()`'s `WHERE` as well as in the read, because a read and a write that
   disagree about who holds a sign disagree *silently* — an editable canvas whose every
   claim does nothing, and a refusal at the publish.
   **And an absence is never an instruction.** The grant matrix POSTs a tick per
   granted cell, so an unticked box, an account added since the page was rendered and
   a Display added since the page was rendered are all the same silence. The form
   therefore declares both of its axes — `grants_accounts[]` and `grants_displays[]` —
   and `setAccess()` only revokes inside the part it was told the admin could see. A
   form that submits state rather than intent has to say what it covered, or it saves
   over work it never showed.
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
    oracle it was rationing against. **A spent guess is never rolled back**: it is
    the price of having asked, not part of the change being attempted, so
    `ResetTokenStore::verify()` runs before the transaction that finishes the reset
    and never inside it. A budget that a failed write refunds is not a budget.
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
21. **No DDL inside a transaction, and one repair at a time.** MySQL commits the
    surrounding transaction when DDL runs and says nothing about having done so, and
    this app has no undo. `ensureSignageSchema()` is therefore called only where the
    call is written — the top of an authenticated entry point, before any transaction
    exists. Convergence reached any other way goes through
    `repairSchemaAfterFailure()`, which refuses while `inTransaction()`, refuses if
    convergence already ran on this request, and refuses again for five minutes after
    an attempt. The one caller is `DisplayStore::healSchema()`, on the public path,
    where the trigger is a query that already failed with "no such table" — and
    where the alternative is a sign that stays dark until an admin happens to sign
    in. `ensureSignageSchema()` additionally holds an installation-wide `flock` for
    the duration, so six Screens failing on the same 30-second tick produce one
    convergence rather than six racing for the same `ALTER` — five of which would
    lose it, fail with "duplicate column name" on a `need` the catalogue said was
    `true`, and satisfy invariant 20's test for something worth emailing about.
    Anything new that converges, or that calls a `DisplayStore` read from inside a
    transaction, is covered by this or it is a way back to the failure mode.
22. **A change that spans two tables is one transaction, held by a use-case module,
    and what the person is told is what actually happened.** There are three of these
    now — `DisplayAdmin` (a Display with its layout and its grants, and the access
    matrix with the edit locks a revoke strands), `AccountAdmin` (closing an account:
    grants, lock, `closed_at`; and editing one: the role, then the grants and locks
    that role decides the meaning of) and `PasswordResetCompletion`
    (the code consumed, the password changed, the lockout released) — and they are
    all the same shape on purpose: the module holds `beginTransaction`, writes no SQL
    of its own, rolls back quietly on any failure, and returns a result object the
    page turns into a sentence. A page that issues the writes itself cannot do this,
    which is why none of them do any more. The failure mode is not a half-written
    database so much as a **lie**: three writes in a row on a page mean the second
    one can fail after the first has landed, and the message printed then is decided
    by which line threw rather than by what is now true. With no undo anywhere,
    somebody acting on that message is the whole problem.
23. **A reply that is not a working sign does not say `200`, and no reply may be
    stored.** The two halves are one rule because a 200 is exactly what a cache is
    allowed to keep, and the reply most likely to be kept — `get_layout`, fetched
    every 30 seconds forever — is the one whose failure payload is a *notice*, a
    statement about right now. Stored, it survives the thing it was reporting: the tag
    is corrected or the sign is turned back on and the Screen stays dark, while the
    Builder reports every publish as a success. So the code for a resolution comes
    from `DisplayResolution::httpStatus()` — never from the page emitting it, or the
    Viewer and its own poll will eventually disagree about one fact — and an
    unrecognised kind answers 500, never 200.
    And `HttpCache::neverStore()` is stated in **two** places, `auth.php` and
    `db_connect.php`, because every entry point includes at least one of them and
    neither covers the app alone: `viewer.php` opens no session, and `logout.php` and
    `setup_branding.php` open no database. This invariant first shipped claiming one
    call site and was therefore false the day it was written — those two redirects
    answered with no caching rule at all, and a *redirect* is the worst thing to leave
    cacheable because most of what it is is a side effect: `logout.php` served from a
    cache is somebody landing on the login screen with their session still alive.
    The self-test checks both halves — the call is present in both files, and every
    entry point reaches one — because a grep only helps whoever remembers to run it.
    Two things are deliberately outside the rule and both are named in §4u: `uploads/`,
    which Apache serves and whose filenames never change content, and the Builder's
    picker page, which is a working page reached by an ordinary `no_tag`. Anything new
    that answers a machine — a second poll, a health endpoint, a webhook — says what it
    is in the status line, or it is a dark sign that reads as a working one.
24. **A request parameter is not a string until something checks.** `?display[]=x`
    and `tag[]=x` are things any browser will send and no page here links to, and
    `(string)` on the array they produce is not a rejection — it is the word `array`,
    which `isValidTag()` accepts, so it is a *working address* to whatever sign an
    admin tagged that (decision #27). Both seams that turn a request into a tag now
    refuse to fold a non-scalar: `DisplayRequest::locate()` treats it as no sign
    named, and `DisplayStore::normalizeTag()` answers `''`. The tag is the one
    parameter with two independent readers, which is why it needs the rule in both —
    the panel's create form and its delete confirmation never go near `locate()`.
    Note the asymmetry with an array `display_id`, which is a `MISMATCH` rather than
    an absence, and it is not an inconsistency: an id claims to confirm a Display
    already in hand, so a malformed claim disagrees with something and a write must
    be refused; an address that is not an address disagrees with nothing.

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
- ~~**The public Viewer can repair the database, unbounded**~~ **Fixed** — see §4q.
  It still can, deliberately, because the alternative is a sign that stays dark until
  somebody signs in; what it can no longer do is retry every 30 seconds per Screen,
  or race five other Screens for the same `ALTER`.
- ~~**The self-repair path has never been executed by any test**~~ **Fixed** — see
  §4q. Its trigger was a SQLSTATE only MySQL raises, so the SQLite fixture could not
  produce the error the whole sequence starts from. The detector is now a pure
  function that recognises both engines' shapes, and whether a repair ran is
  observable through the retry window.
- ~~**A schema fix can land in the middle of a publish**~~ **Fixed** — see §4q and
  invariant 21. It was already unreachable, but only because a `static` in another
  file happened to be set — a protection invisible from the call site and worth
  nothing to the next transaction somebody opens.
- ~~**`reset_password.php` makes two irreversible writes with no transaction**~~
  **Fixed** — see §4r and invariant 22. Three, in fact, and the third ran `ALTER
  TABLE` and then assumed the columns, so on a database where that ALTER had never
  applied it threw *after* the password had already changed.
- ~~**`tools/rehearse_phase1.php` proves a tautology and never exercises the widened
  ENUM**~~ **Fixed** — see §4r. Four of its checks were weaker than they read; the
  worst printed the reassuring answer in precisely the situation that needed the
  alarming one.
- ~~**The grant matrix treats an absent Display as "revoke", and no PRG means F5
  replays the write**~~ **Fixed** — see §4s. Both halves were the same defect from
  opposite ends: a form that submits *state* rather than *intent*, saved over
  whatever it had not been shown.
- ~~**Revoking a grant strands the edit lock on the revoked account**~~ **Fixed** —
  see §4s. And the account could not release it even deliberately, because releasing
  goes through the seam that had just started refusing it.
- ~~**A granted account promoted to admin keeps an invisible, unrevocable grant
  row**~~ **Fixed** — see §4s. The row was displayed nowhere and removable by
  nothing, and a demotion months later handed the old access back.
- ~~**A lock stranded by deactivating a Display, deactivating an account, or renaming a
  tag**~~ **Fixed** — see §4t. Three more doors into the room §4s closed one door of,
  plus the reading rule that covers the doors nobody has found yet.

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

### 4q. The repair nobody asked for

`ensureSignageSchema()` is *deliberate*: it is called at the top of an authenticated
entry point, where nothing is open and nobody is mid-write, because that is where the
call is written. `DisplayStore::healSchema()` is the other door — a query already
failed with "no such table", and it converges from wherever that happened, including
the public poll. That door exists for a good reason: the first request after a deploy
may well be a Screen's poll, and a sign that stays dark until an admin happens to
sign in is worse than one convergence run on the public path.

It had no rules. Three were needed, and each is a live defect rather than tidiness.

**A repair must not run inside a transaction.** MySQL commits the surrounding
transaction when DDL runs, and says nothing about it. `LayoutStore::publish()` deletes
a Display's whole layout and re-inserts it inside one transaction, and its last two
calls — `recordPublish()` and `claimLock()` — read the `displays` row through the same
`LEFT JOIN users` that raises this error. A repair fired from there would have
committed the publish, rethrown, been caught, rolled back nothing, and answered
*"Publish failed. Nothing was saved."* to somebody whose work had in fact been saved.
They would re-apply it, find the stamp had moved (ADR-0006), be told someone else had
published, and reload. With no undo anywhere in this app, that is the worst outcome it
has. Refusing costs a dark sign for 30 more seconds.

What made this one interesting to establish is that it was already unreachable, and
for the wrong reason. On an authenticated path `SchemaLatch` has already been taken,
so `ensureSignageSchema()` returns before running anything — the protection was a
`static $done` in another file, invisible from the call site, and worth nothing to the
next transaction somebody opens. `repairSchemaAfterFailure()` says no because a
transaction is open, which is a reason that stays true.

**One repair at a time, installation-wide.** Six Screens poll on the same 30-second
tick and fail together. Unguarded, all six read the catalogue, all six see the same
column missing, and five lose the `ALTER` — failing with "duplicate column name" on a
`need` the catalogue said was `true`, which is precisely invariant 20's test for
something worth emailing an admin about. The alert built in §4p would have announced
its own success as a failure, six times, on deploy day. Two concurrent `ALTER`s on
`canvas_elements` are also the metadata-lock pile-up that §4o set out to end. The lock
is an `flock` rather than a stamp or a lock directory, because the operating system
releases it when the process ends: a repair killed mid-`ALTER` must not leave behind a
file that stops the next request fixing the database. It is non-blocking — a page load
must not wait behind somebody else's `ALTER` — so the loser skips convergence and
renders against the database as it stands, which is what it would have done anyway
while the winner's statements were still running.

**Not again for five minutes.** A repair that *cannot* succeed — no `CREATE`
privilege, a tighten the data refuses — was otherwise retried every 30 seconds by
every Screen, forever, on the one query that must never be slow. The sign is already
dark; trying twelve times an hour rather than seven thousand loses nothing. The window
is spent only by an attempt that actually ran, and an authenticated request declines
before spending it, so the budget belongs to the callers that need it.

**And the path had never been executed.** Not once, in any test — because the trigger
was SQLSTATE `42S02`, which MySQL raises and SQLite does not. The fixture could not
produce the error that starts the whole sequence. `schemaErrorSaysTableMissing()` is
now a pure function of the two strings and recognises both shapes: MySQL's `42S02` and
SQLite's generic `HY000` with *no such table* in the message. That is a small widening
made for the test, and it is honest rather than hidden — a wrong answer costs one
throttled convergence. It stays narrow deliberately: a missing *column* is `42S22`, a
missing *database* is `42000`, and convergence repairs neither.

Whether the repair ran is observable in the tests through the retry window: an attempt
spends it, a refusal leaves it. That is what lets a check assert *"the store tried"*
and *"inside a transaction the store did not try"* without reaching inside either
module.

**Thirty-nine checks**, and the two that matter most are the pair just described,
because before them the recovery path was covered by nothing at all. Thirteen
mutations, all killed (kill counts 4, 2, 4, 5, 4, 3, 1, 4, 1, 3, 1, 1, 4), plus §4o's
and §4p's re-run against the rewritten file and still killed (12, 2, 29, 5).

`tools/rehearse_phase1.php` carries the one claim SQLite cannot settle: it opens a
transaction on real MySQL, inserts a row, runs an `ALTER` inside it, rolls back, and
asserts the row *survived*. That is the hazard, demonstrated rather than asserted, on
a throwaway table it drops again. If that check ever starts passing the other way,
MySQL has changed and this whole section can be revisited.

Left standing:

- **A fourteenth mutation survived, and the code it broke is now gone.** Deleting the
  explicit unlock from a `catch` around the repair failed nothing, because PHP
  releases the `flock` when the handle falls out of scope as the exception unwinds.
  The branch was unreachable by construction, so it was removed rather than kept and
  excused — which is also the argument for `flock` over a stamp file, since the same
  property is what stops a killed request leaving the database unfixable. The two
  checks asserting it (a repair that throws still throws; the next one can still take
  the lock) stayed.
- **An install with nowhere writable has neither the lock nor the window.** Both are
  files, and `stateFile()` answers `''` when there is no writable directory, in which
  case the repair runs unguarded. That is the deliberate choice: such an install has
  no log and no alerts either, and it needs the repair more than the coordination.
  Settings → This Server already says *"Nowhere to write"* in as many words, which is
  the place that problem gets fixed.
- **The lock is per-installation, not per-database.** Two copies of this app sharing
  one MySQL database but not one filesystem would not see each other's lock. Nothing
  in HANDOFF.md describes such a deployment, and the ambition here is a bound, not
  mutual exclusion.
- **`repairSchemaAfterFailure()` has exactly one caller.** If a second module ever
  needs to converge off a failure, it goes through this function; invariant 21 is the
  rule, and `grep -rn "ensureSignageSchema" --include=*.php .` in §5 is what finds a
  new call that skipped it.

### 4r. A reset that half-happened, and a rehearsal that agreed with itself

Two items from the reviewed list, and they turned out to be the same mistake twice:
a sequence of steps that reports on itself, where the report is decided by something
other than what actually happened.

**The reset.** The last step was three writes in a row on the page — consume the
code, set the password hash, clear the login lockout — with nothing tying them
together. Each failure mode ends in the visitor being told the opposite of the truth:

- The password write fails after the code is consumed. The person is told the reset
  failed, requests another code, and never learns the first one was spent doing
  nothing. The five-guess budget went with it.
- The lockout clear fails after the password has changed. Worse, and it did not need
  a fault to happen: the page ran `ensureLockoutColumns()` — three runtime `ALTER`s —
  and then issued an `UPDATE` naming those three columns. On a database where the
  ALTER cannot apply (no privilege, a full disk) that `UPDATE` throws *every time*,
  after the password has already been changed. The person is told the reset failed
  while holding a password that works, and the one instruction they will act on is
  the wrong one. `login.php` had the same latent bug at the end of a successful
  sign-in, with the comment above it already claiming this helper swallowed its own
  failures; it does now, because the statement moved into `AccountStore`.

`PasswordResetCompletion` holds the transaction (invariant 22). The interesting part
is where the boundary falls, because two rules pull against each other:

- **The guess must survive a rollback.** Spending one of five tries is the price of
  having asked, not part of the change. Inside the transaction, a failed write would
  refund it — five at a time, forever, which is the `$_SESSION` limiter invariant 13
  was written to kill, reintroduced by a rollback. So `verify()` is called before
  `beginTransaction()`, and its docblock says why in the imperative.
- **The consume must not.** Marking the code used and changing the password are one
  act. So `consume()` is inside, and it is what makes two browsers holding the same
  correct code safe: exactly one gets `true`, and the loser has not changed a
  password on the way to finding out.

`ResetOutcome` has three answers, not two. *Refused* stays the one sentence four
different refusals share — wrong code, expired, no such account, budget gone — or the
page starts answering "does this username exist?" out loud. *Failed* is new and says
so plainly ("Your code was accepted, but the password could not be changed"), because
it only ever happens after a **correct** code, so it leaks nothing, and telling
somebody their code was wrong when it was not sends them round a loop that is now
four tries long.

Two things this cost, both deliberate: the reset page no longer runs the three
lockout `ALTER`s at all (`login.php` adds them on any sign-in attempt, and the clear
copes with their absence), and `AccountStore` grew the only two methods in it that
let an exception out — a caller holding a transaction needs the failure, not a
shrug.

`setPassword()` asks whether the row exists *before* writing rather than reading
`rowCount()` afterwards, and that is the second time this build has been bitten by
that number: MySQL counts rows it *changed*, so storing a hash identical to the
stored one comes back as zero and reads exactly like "no such account". The first
draft inferred it and fell back to a `SELECT`; a mutation that replaced the fallback
with `return false` survived, because on SQLite `changes()` counts matched rows and
the branch is unreachable there. Asking first has no unreachable branch and the same
answer on both engines.

It also refuses outright inside a transaction it did not open — the guess would be
rolled back with that transaction, and the rollback in here would end one belonging to
somebody else. Nothing calls it that way today; the point is that the next thing
cannot.

**Forty-nine checks.** Fourteen mutations, all killed (kill counts 2, 7, 6, 1, 5, 3, 1,
29, 3, 11, 1, 14, 5, 1), plus §4i's four re-run against the split-apart store and still
killed (10, 4, 2, 1). Two are worth naming: making `verify()` consume as well fails 29
checks, and not rolling back a refused password write fails six — among them *"the code
was not consumed, so the person can simply try again"*, which is the property the whole
section exists for.

Two checks had to be written defensively, and the reason generalises. The mutations they
catch do not make a check *fail*, they make the suite **die**: one raises an exception
where the fixed code returns a boolean, and the other leaves a committed transaction
where a `rollBack()` was waiting. A run that ends on line 1535 prints no total at all,
so every check after it is silently uncovered — and the count anchor cannot notice,
because it never runs either. Asserting against a throw means catching the throw;
cleaning up after a guard means asking whether the guard held.

**The rehearsal.** `tools/rehearse_phase1.php` is the one tool that runs against a
copy of live data before a deploy, so a check in it that cannot fail is worse than no
check — its output is read as a green light. Four were:

- *"convergence can be re-run without error"* was **true of a database that rejects
  every statement**. `ensureSignageSchema()` latches per request, so the second call
  returned at the latch having issued nothing: run one swallows its failures, run two
  attempts none. It drops the latch first now, and asserts the latch was really taken
  again, which is how we know the statements ran.
- *"no unscoped elements remain (found 0)"* printed **ok when `display_id` was
  absent entirely** — the count was initialised to zero and only asked the database
  when the column existed. The one situation that needs the alarm got the
  reassurance. Cannot-tell is a failure here.
- It published a `section` and a `price`, **both of which existed before Phase 1
  widened the two ENUMs**. So a database where `MODIFY block_subtype` never applied
  passed clean, and the first real publish using Title 2 or Price 2 either failed
  outright (strict mode) or silently stored an empty subtype — which shows up as
  wrong typography on the sign and nowhere else. It now publishes one block for
  **every value both ENUMs list**, read out of the constants themselves so widening
  an ENUM widens the rehearsal without anybody remembering to, and reads them back:
  MySQL with strict mode off stores `''` for a value the column does not list and
  says nothing.
- A foreign key was checked for **existing**, not for cascading. A constraint that
  restricts passed that check — and then the cleanup at the bottom threw, uncaught,
  and left two throwaway Displays and their layouts in the copy the tool had been
  pointed at. `DELETE_RULE` is read from `information_schema` now, for the elements
  and both halves of a grant, and the cleanup deletes in dependency order inside a
  `try` so a failure names the rows to remove by hand instead of exiting on them.

Two things it never looked at, added because they cost a query each: `block_styles`
(the seed is a *step*, whose failure is reported and survivable, and a missing row
makes the Brand Standards form a silent no-op that reverts on reload) and the five
columns pages add rather than convergence — the three ADR-0001 lockout columns,
`closed_at`, `password_resets.attempts`. Those five are **printed, not checked**: a
copy of live data can legitimately be without them, each arrives on the first request
that needs it, and a red run for that would train somebody to ignore a red run.

Left standing:

- **None of the rehearsal's own checks can be mutation-tested here**, because none of
  them can be *run* here — the tool needs MySQL, which is the entire reason it exists.
  Its new layout builder was verified the only way available: run through the real
  `LayoutStore::publish()` against the SQLite fixture, which confirmed thirteen rows
  in and thirteen out with every type and subtype intact. That proves the layout is
  well-formed and the round-trip logic reads what it wrote; it cannot prove anything
  about an ENUM, which is what the MySQL run is for.
- **`setPassword()`'s "already that hash" case is only reachable on MySQL.** The
  check for it passes through the ordinary path on SQLite. What it asserts is true on
  both; it just is not the interesting branch on one of them.
- **`admin_panel.php` still writes `password_hash` itself** when an admin sets
  somebody's password. That is one write, not three, so nothing can half-happen —
  but if it ever grows a second write it goes through `AccountStore`, and
  `grep -rn "password_hash" --include=*.php .` in §5 is what finds it.

### 4s. Three ways access changed without the app noticing

Items #16–#18 of the reviewed list, and one shape underneath all three: **a change
to what somebody may reach, decided by something other than what the person doing it
meant.** In two of them the deciding thing was an *absence*; in the third it was a
row that no screen displayed any more.

**The grid saved what it had not been shown.** *Who can edit which display* is a
matrix of checkboxes, and a browser posts only the ticked ones. So an unticked box, an
account added since the page was rendered, and a Display added since the page was
rendered all arrive as the same silence — and the save read all of it as "revoke".
The accounts half had already been closed: the form names them in `grants_accounts[]`
and `setAccess()` leaves anything not on the list alone. The Displays half had not, so
this sequence lost work:

1. Two admins open the Displays tab.
2. One adds *Lobby Screen* and gives Kayla access to it.
3. The other presses **Save access** on the page they already had open. Their form has
   no Lobby column, so Kayla's brand-new grant is an unticked box, and it goes.

Nobody is told, because from the second admin's point of view nothing was unticked.
The fix is symmetry: the form declares its columns in `grants_displays[]`, and a
revoke now needs three things to be true at once — the grant is held, the column was
covered, and the box was not ticked. A tick outside the covered columns grants nothing
either, so the two axes cannot be played against each other by a hand-built POST.

**And F5 replayed it.** The page answered the POST by rendering, so the whole-matrix
write sat in the browser's history — one reload, one refresh-to-see-if-it-saved, one
back-button, and the *old* form state was written over a page that had moved on. It is
the same defect from the other end, so it has the same answer: the grid redirects
(post/redirect/get) and the sentence travels in `$_SESSION` via `flashMessage()`.
`takeFlashMessage()` removes what it returns, so a reload of the redirected-to page
shows the page without the sentence rather than repeating a claim about a state that
may since have changed. This is the only form on the panel that got PRG, and
deliberately: replaying any of the others is idempotent or self-refusing (a create
collides on its tag, a close reports "already closed"), while this one is the one that
rewrites a table wholesale.

**A revoke stranded the edit lock.** Take a Display away from somebody who has the
Builder open on it and the lock stayed theirs for a full fifteen-minute idle window,
with their name on the read-only banner every colleague saw. They could not release it
either — releasing goes through `DisplayRequest::forEditing()`, which had just started
refusing them — so the only way back was an admin's force-unlock on a lock held by
somebody who was not allowed near the sign. `setAccess()` now releases it in the same
transaction, **by holder**, so a colleague editing the same Display keeps theirs; the
count of locks actually freed is what decides which sentence the admin gets back,
because "somebody's editing session just ended" and "nobody was in there" are
different things to have done.

*And the person is told.* The Builder heartbeats every minute, and
`applyLockAnswer()` returned silently on anything that was not a success — correct for
a dropped connection, which the next beat covers, and wrong for exactly one answer:
`forbidden` never comes back. So the page carried on looking editable, the beats kept
failing, and the first word of it was a refused publish some minutes later. There is
now a bar for it, emitted for a read-only page as well as an editing one because access
can be taken from somebody who was only watching, and `accessLost` is kept separate
from `lockLost` because a lock can return on its own and a grant cannot. Losing access
stops the heartbeat, stops the leaving-beacon (the revoke already freed it), hides the
three lock bars — the lost-holder one in particular would name a holder there is not
one of — and, on a read-only page, takes down the banner offering a reload once the
display frees up, which is now an offer to be refused. A `forbidden` publish became an
`alert` rather than a toast, for the same reason the other three refusals are: it is
the one that does not fix itself.

**A promotion left grants nothing could remove.** An admin holds every Display by
role (ADR-0005), so a promoted account's grant rows stop being displayed — the matrix
lists `basic` accounts only. The rows stayed. Invisible on the one screen that
administers them, and therefore impossible to take away; demote that person in the
autumn and the access they were given in March came back, silently, decided by a table
nobody could see. `AccountAdmin::edit()` clears them on promotion, which makes the
matrix the whole truth about who was given what, and says which ones went so the admin
knows a demotion will not bring them back.

That decision creates its own stranded lock, so the same method closes it: a demoted
account holds no grants (there were none left to hold), which means it may open
nothing — including the Display it is holding open at that moment. So a demotion frees
that account's locks. This is why editing an account had to move off the page and into
the module at all: it is three writes across `users`, `display_permissions` and
`displays`, and invariant 22's point is that the sentence printed afterwards must be
decided by what is now true rather than by which line threw.

One thing moving that form into the module made possible rather than fixed: it now
refuses a **closed** account. `is_active` back to 1 on a closed row is the only thing
in this app that looks like an undo of a closure (invariant 14), and it was reachable
by a hand-built POST — the panel renders no edit form for a closed account. Sign-in
would have refused it anyway, because `login.php` asks about `closed_at` *before*
`is_active`; the refusal is in the module so the rule does not rest on the order of two
checks in a different file.

**Seventy-five checks**, and twenty deliberate mutations, all twenty killed
(kill counts 5, 2, 5, 1, 1, 4, 8, 5, 3, 2, 2, 5, 1, 3, 3, 3, 1, 1, 2, 1). Four are
worth naming because they are the ones a reader would doubt:

- Removing the `lock_holder_id` predicate from `releaseLockOn()` kills eight, which is
  the check that revoking Kayla's access does not free the lock Sam is working under.
- Freeing the lock *before* `beginTransaction()` — the tempting simplification, since
  it needs no `Display` object — kills three, all of them the refused-revoke case where
  the admin is told nothing changed while a sign sits unlocked.
- Letting an empty column list fall back to "every Display" kills two. That fallback
  looks like defensiveness and is the original defect with a friendlier face.
- Making `applyLockAnswer()` swallow a `forbidden` beat again kills three in the
  uploads suite, which is the only suite that runs `builder.php`'s JavaScript under the
  premise that the page can edit.

The `users` fixture gained `UNIQUE` on `username` and `email`, which the live table has
always had. Without it the duplicate-email check would have asserted a rollback that
never had anything to roll back — one fewer of the twelve fixture divergences the audit
counted.

Left standing, and named rather than quietly skipped:

- ~~**Deactivating an account does not free its locks**~~ **Fixed** — see §4t, which
  took the other three doors and the reading rule behind all of them.
- **The other forms on the admin panel still answer a POST by rendering.** The
  reasoning is above; the flash helper is in `auth.php` and general, so adopting it
  elsewhere is two lines rather than a mechanism.
- **`AccountAdmin::edit()` does not guard the last admin.** It does not need to, given
  it refuses to demote *you* — a one-admin store has nobody else who can press the
  button. Written down because that is a proof, not an obvious truth, and it stops
  being one the moment somebody adds an "edit any account" path that skips the actor.

### 4t. A lock outliving the reach behind it

§4s closed one door into this room. There were four, and the difference between them
turns out to matter more than the thing they had in common.

**The three that were open.** Turning a Display off takes it away from a `basic`
account and leaves it with an admin (`Actor::mayOpen` — a retired Display stays an
admin's to work on), so a clerk holding it lost the sign. Suspending an account ended
its session outright. Renaming a screen name tag broke the address a Builder was
holding, so every request from it resolved to `unknown`. In all three the lock stayed
put for a full idle window, with that person's name on every colleague's read-only
banner — and they could not hand it back even deliberately, because releasing goes
through `DisplayRequest::forEditing()`, which had just started refusing them. The only
way out was an admin forcing the lock, on a sign nobody was actually editing.

**The one that was not open, and should not be.** A rename is not a change of reach. It
changes where the sign answers; the same account may still edit it. Freeing that lock
would punish somebody for an admin's retyping — so `updateDetails()` leaves it alone,
tells the admin that the person will be asked to reload, and the reload picks the same
lock straight back up because `claimLock` extends a lock held by the same account. The
tests assert the lock *survives* a rename, which is the only one of the four where
"free it" would have been the plausible-looking mistake.

**The reading rule is the part that generalises.** Freeing at the moment of the change
covers the doors somebody enumerated. It does not cover the fifth, and it does nothing
for a row already stranded on the live database — where at least one such row plausibly
exists, arrived at by a path that no longer exists. So `LockState::isHeld()` now answers
false when the holder cannot sign in. Nothing is swept; there is no cron on this host and
a sweep would have to outlive the tab that left the row. It is a rule applied on read, the
same way "lapsed" has always been.

**Which forced the read and the write to agree.** `claimLock()`'s conditional `UPDATE`
had its own copy of "may this be taken over", and a rule added only to the read would
have made the two disagree — *silently*, in the worst available direction: a colleague
shown an editable canvas because the read said free, every claim quietly matching no
row, and the first word of it at the publish. The disjunct is in both. A correlated
`NOT EXISTS` rather than a join, because a multi-table `UPDATE` is MySQL-only and the
fixture is SQLite.

**And five ways to lose a sign needed five sentences.** `builder.php` acted on one
refusal, `forbidden`, and ignored the rest — right for a dropped connection, wrong for
these, because none of them ever starts working again. `LOCK_TERMINAL` is now a fixed map
of reason to sentence, and the wording differs because what to do differs: ask an admin,
copy your work, reload the page, sign in again. A single "you have lost this display"
would send somebody hunting an admin over a renamed tag. It is a **fixed list**, not
"anything with a `reason`", so a reason added to the server later cannot become fatal to
an editor by accident — and `api.php`'s inactive-session refusal had to be given a name
(`signed_out`) before the page could tell it from a timeout.

**Sixty-one checks** across the three suites (45 + 7 + 9), and twenty-three deliberate mutations,
all twenty-three killed (kill counts 3, 3, 1, 1, 2, 8, 2, 2, 1, 1, 1, 2, 2, 2, 2, 1, 2,
11, 3, 6, 1, 1, 5). Four worth naming:

- Asking `claimLock`'s new disjunct the wrong way round — `EXISTS` for `NOT EXISTS`, the
  single-character version of this mistake — kills eight.
- Freeing every lock when a Display is retired, rather than only the holders who lost it,
  kills two: an admin retiring the sign they are editing would have kicked themselves out.
- Making a *rename* free the lock kills one. It is the mutation that looks most like
  consistency with the other three doors and is the one place consistency would be wrong.
- Restoring the old `loadTestDisplay()` — a test helper carrying its own copy of the
  store's SELECT — kills five. It had already drifted: the store learned to join two
  columns and the helper did not, so every Display a test loaded was missing the columns
  two rules are decided from, and those rules read their absent-means-unknown defaults
  and the tests agreed with themselves. It now goes through `DisplayStore::forId()`. That
  is the §4r lesson again, in a different file: a fixture that assembles a domain object
  by hand can only test the object it assembled.

Left standing, and named rather than skipped:

- **Closing a Display's Builder is still best-effort.** A freed lock reaches the page on
  its next beat, up to a minute later. Sixty seconds of somebody typing into a sign they
  have lost is the accepted cost of not holding a socket open per editor.
- **`is_active` is the only question asked about the holder.** Closing an account clears
  `is_active` in the same statement (§4l), so it is covered — but by consequence rather
  than by name. A future state that shuts an account out *without* clearing `is_active`
  would strand locks again, and the fix would belong in `LockState`.

### 4u. Every answer was 200 OK, and nothing said what could be kept

A sign showing "Display not found" answered `200 OK`. So did one showing "No display
specified", and so did one that had been deliberately turned off — and so did the
poll behind each of them. Meanwhile nothing in the repo set a caching rule on any
path, so what happened to any of those replies was decided by the browser, the
kiosk's WebView, and whatever proxy sits between the store and the host.

**Why those are one item and not two.** A `200` is precisely the thing a cache is
permitted to keep. The app was simultaneously telling every cache in the chain that a
dark sign was a working one, and declining to say for how long that was true. The two
halves compound: the reply most likely to be stored is `get_layout`, fetched every 30
seconds forever, and the payload most damaging to store is a *notice*, because a
notice is a statement about right now. An admin corrects a screen name tag, or turns
the sign back on, and a stored notice keeps the Screen dark afterwards — with the
Builder reporting every publish as a success. That is the same shape as the latched
layout hash in §4g: a negative answer remembered for longer than the negative lasted.

Neither half is the whole fix, and the reason is specific. A cache given no explicit
instruction may choose a freshness lifetime of its own for a `200` **or a `404`**, but
not for a `503`. So honest codes on their own would have fixed the switched-off sign
and left the mistyped-tag one exactly as it was, and `no-store` on its own would have
left every monitor unable to tell a dark sign from a working one. They were decided as
one item because they are one defect seen from two ends.

**The status line is chosen by the resolution, not by the page.** `DisplayResolution`
already owns the *wording* per case, because a Screen has to say the same sentence
whoever asked (ADR-0003); it now owns the *code* per case for the identical reason. A
Viewer and the poll inside it answering one fact with two different codes is a
disagreement nobody would ever notice was wrong. Six kinds, and each code is a
decision rather than a default:

| Kind | Code | Why that one |
|------|------|--------------|
| `found` | 200 | |
| `no_tag` | 400 | The URL is missing the one parameter that names a sign. Nothing is wrong at this end (not 5xx) and there is nothing to go looking for (not 404). |
| `unknown` | 404 | |
| `forbidden` | 403 | Not 404. §4d decided that a refusal names the sign rather than hiding it, and a code that pretends the Display does not exist would contradict the sentence beside it. |
| `mismatch` | 409 | A conflict, and specifically the kind reloading resolves — which is what the message already tells the person. |
| `inactive` | 503 | A real sign, deliberately out of service, expected back. 404 was the alternative and it throws away the one distinction somebody standing in front of a blank sign needs: "turned off" is not "no such display". |

Anything the mapping does not recognise answers **500, never 200**. That branch is
unreachable through the constructor, so the mapping is also exposed over a bare kind
(`statusForKind`) — a rule with no way to test it is a comment, and this is the exact
shape the defect would return in: a seventh resolution added later with no code of
its own, quietly answering `200 OK` on a sign that is showing nothing.

**Two emitters, and one page that deliberately keeps its 200.** `viewer.php` sets the
code on its notice branch; `api.php` sets it on the public poll's failure and inside
`failResolution()`, which covers every Display-scoped endpoint at once. The Builder's
own not-found page is *not* one of them: a `no_tag` there is the ordinary way an admin
arrives, and the page it draws is a working picker. A status code is for the caller
with nobody in front of it.

**Nothing needed to change on any client.** `fetch` does not reject on a non-2xx, and
every caller here reads the body (`.then(r => r.json())`) rather than the code — and
`api.php` was already answering 403 for a dead session and 413 for a dropped upload
before any of this, so the shape was proven. The notice *body* is byte-for-byte what
it was, on the same 30-second meta refresh, because a browser renders the document it
was given whatever the status line says.

**The caching rule is one call, in the one file every entry point includes.**
`db_connect.php` already installs the error policy for the whole app on the same
argument, and there is no page in this app worth caching — every one is a sign, an
endpoint or a form. So `HttpCache::neverStore()` goes there, *before* the connection
is attempted: the reply from a database that is down is also a statement about now,
and a stored copy of it outlives the outage. A page added later inherits the rule
instead of having to remember it.

Three headers for one rule. `no-store` is the load-bearing word — `no-cache` only
means "revalidate before reuse", which a cache can honour while still holding the
bytes. `Pragma: no-cache` and `Expires: 0` are for HTTP/1.0 caches, which is exactly
what a signage widget or a small-business router turns out to be running. They cost
30 bytes and remove a class of failure that can only be diagnosed by standing in
front of the sign.

**Thirty-one checks, and sixteen deliberate mutations, all sixteen killed** (kill
counts 12, 1, 1, 2, 1, 1, 1, 2, 1, 1, 1, 2, 2, 1, 1, 1). Five worth naming:

- Restoring the old behaviour — every kind answers 200 — kills twelve.
- Collapsing `inactive` into 404 kills one, and it is the mutation that looks most
  like tidying: two "there is nothing here" cases sharing a code.
- Adding a seventh resolution kind with no code kills one, through a reflection count
  rather than through the kind itself. That check is the gate, in the sense invariant
  19 uses the word: adding a kind means adding a code and a check for it.
- Lifting the `neverStore()` call out of either `auth.php` or `db_connect.php` kills one
  each, and adding a new root entry point that includes neither kills one. All three had
  to be added after the first version of that check passed a mutation: it proved every
  page reached a call site without ever proving the call sites still called anything.
- Dropping the `headers_sent()` guard from `HttpCache::neverStore()` kills one, and it
  is the reason the guard exists rather than a warning: a `header()` call after output
  raises a diagnostic, and PHP's own output on a page is what invariant 16 is about.
  The suite has been printing since its first section, which makes the refusal the
  observable branch and the emission the one the §5 grep covers.

**Amendment: what the framed Screen actually does with a non-2xx.** The first version
of this section shipped with an open question — `viewer.php` is embedded in a
SmartSign2Go widget, and a widget or a CDN that treats a non-2xx as "content
unavailable" would replace the very notice ADR-0003 exists to put in front of a
customer. That was worth answering rather than finding out on the sign, and the answer
changed one thing in the code.

What was established:

- **It is an iframe.** The `.htaccess` block for `viewer.php` unsets
  `X-Frame-Options` and sets `frame-ancestors *`; neither header does anything except
  to a frame. A browser renders the body of a framed 404 or 503 the same as a 200 —
  the historical exception, error-page substitution for bodies under 512 bytes, was
  IE-only, and these notices are 723–732 bytes.
- **There is a CDN in front of the live site, which nothing in this repo had recorded.**
  `srcresort.com` answers with `server: cloudflare`. Asked twice for a path the origin
  404s, it returned the origin's own body unchanged, `cf-cache-status: DYNAMIC` both
  times — so this zone passes a non-2xx through and is not caching `.php` today. It
  also confirmed the defect: the live Viewer sends no `Cache-Control` at all, in front
  of a cache that stores a 404 for three minutes by default.
- **A non-2xx on that URL was never new.** `ErrorPolicy::emit()` has answered `500`
  with the kiosk notice on the Screen path since §4m, which is what a Viewer does when
  the database will not open. Whatever the widget does with a status line, it has been
  doing it to the failure that matters most for longer than #28 has existed.

So the codes stay on `viewer.php`. But the probe found a real gap on the *poll*, and it
was one this decision had created. `loadLayout()` used `r.json()`, which rejects on a
body that will not parse — landing in the `.catch()` branch, whose job is "we never
reached the server" and which therefore leaves the sign exactly as it is. Anything that
answered *for* the endpoint with a page of its own — a CDN error page, a captive
portal, the host's own 500 — would have left a retired Display showing last week's
prices, every 30 seconds, forever, while §4b promises it flips to the notice within one
poll. The status line survives that substitution when the payload does not, so the
Viewer now reads the body as text, parses it itself, and splits the two cases:

- an answer that arrived and **cannot be read**, with a non-2xx: believe the status
  line, show the notice, keep re-checking. The server answered, whoever composed the
  reply, and it was not a working sign.
- an answer that arrived and cannot be read with a **2xx**: a truncated success. Leave
  the sign up and drop the latch so the next good answer redraws it. Blanking a working
  sign over one garbled reply is the §4g failure mode from the other direction.
- an answer that **never arrived**: unchanged — the sign stays exactly as it is.
- the status line never *overrides* a body it could read. An intermediary that rewrote
  a 503 to a 200 must not be able to put a dead Display back on the air, so
  `data.status` still decides whenever there is a `data`.

That turned the codes from decoration into a second, independent statement of the same
fact — which is the argument for having them on a page nobody reads. It also needed the
page's first test suite: `tools/selftest_viewer.js`, **32 checks**, four mutations, all
four killed. Restoring the `r.json()` chain kills eight; treating an unreadable 2xx like
an unreadable 5xx kills one; letting the status line override a readable body kills
three.

**Amendment: two gaps found by auditing this section against the code.** Asked whether
the item was finished, it was not, in two places — both of them inside the decision as
written rather than beyond it:

- **`lockPayload()` answered `200` with "Display not found."** That is decision #28's
  own sentence. It is the reply when a Display is deleted in the seconds between a lock
  request resolving and the lock being claimed, and it now takes 404 from
  `statusForKind()` like every other missing sign rather than from a literal beside it.
- **Two entry points stated no caching rule at all.** `logout.php` and
  `setup_branding.php` include `auth.php` and never open a database, so the single call
  in `db_connect.php` never reached them — which made invariant 23 false the day it was
  written. Both answer a *redirect*, which is the worst thing of all to leave
  cacheable, because most of what a redirect is is a side effect: `logout.php` served
  from a cache is somebody landing on the login screen with their session still alive,
  on a computer several people in the store share. Latent rather than live — a `302` is
  not heuristically cacheable and this zone reports `.php` as `DYNAMIC` — but one page
  rule from real, in a dashboard nobody here has audited. The call is now made from
  `auth.php` as well, and the self-test checks that both files make it *and* that every
  entry point reaches one, because the first version of that check tested only the
  second half and a mutation walked straight through it.

Left standing, and named rather than skipped:

- **Cloudflare's own configuration is not ours and was not audited.** Origin 5xx
  pass-through is the default and replacing it is an Enterprise-tier option, so a
  `503` from `get_layout` reaching the Screen intact is very likely but not
  guaranteed. If it is ever intercepted, the Viewer now shows its notice from the
  status line rather than the body — which is exactly the case that was built for.
- **`uploads/` stays cacheable, on purpose.** Images and videos are served by Apache
  and never reach PHP, so this rule does not touch them — which is the wanted answer:
  every filename there carries a `uniqid()`, so the bytes behind a path never change,
  and no-storing a 40 MB video would re-fetch it on the store's connection every time
  a sign reloaded.
- **The other refusals in `api.php` still answer 200.** "Admins only", "Unknown
  action", a publish that would not decode. They are read by a script that reports the
  message to the person who caused them, and none of them is polled by anything. If a
  second endpoint ever gets a watcher, the code belongs with whatever decides the
  refusal, the way the resolution's does.
- **No `Retry-After` on the 503.** The Screen's cadence is already 30 seconds and it
  is set by the Viewer, not negotiated; a second statement of the same interval is one
  more thing to keep in step.
- **Nothing is asserted about the headers actually leaving the server.** The CLI
  cannot observe a sent header. The rule is data and the data is checked; the single
  call site is a grep.

### 4v. The address that was not a string

`viewer.php?display[]=x` is a URL anybody can type, and PHP hands the page an array
for the one parameter that names a sign. The line that read it cast it to a string.

Two things came out of that cast, and only one of them was in the item as written.

**The warning.** `(string)` on an array is `E_WARNING: Array to string conversion`,
and decision #27 recorded it printing *above the document* — which it did, when the
item was raised. §4m has since taken that away: `ErrorPolicy` sets `display_errors=0`
in code, and its handler logs the warning and returns, so nothing reaches the page.
Confirmed by running the cast under the real policy rather than assumed: nothing
printed, one line written. So the live consequence today is the line, and `log()` is
the one path in that module with **no** throttling — `report()` has a window,
deliberately, and this is not `report()`. An unauthenticated URL anybody can repeat
writes an entry per request into a 2 MB file that keeps one generation, which is how
the record of something worth reading gets rotated away.

**The address.** This is the half that survived, and it is not a crash: the cast
yields the string `Array`, `normalizeTag()` lowercases it to `array`, and
`isValidTag('array')` is **true** — five characters of lowercase letters. So the URL
did not fail. It resolved, cleanly, to whatever Display an admin had tagged `array`,
and to `UNKNOWN` / "Display not found" only because most stores have not got one. A
notice that sends somebody hunting for a sign that was never named is the wrong
sentence (ADR-0003), and on the one store where the tag exists it is not a notice at
all — it is another sign's layout on the Screen, which is the thing ADR-0003 exists
to prevent.

**The decision was "treat it as no sign named",** and that is now what both seams do:

| Seam | Reads | An array now means |
|------|-------|--------------------|
| `DisplayRequest::locate()` | `display` from the URL or the post | nothing named a sign — `NO_TAG`, "No display specified", `400` |
| `DisplayStore::normalizeTag()` | `tag` and `confirm_tag` from the panel's forms | `''`, which every caller already treats as "not given" |

Two seams because the tag has two independent readers and only one of them is the
resolution. The panel's own forms never go near `locate()`, and they are where the
cast had teeth: `tag[]=x` on the create form named a sign `array`, and then
`confirm_tag[]=x` on the delete form *spelled that tag exactly* — the typed-back tag
is the entire safeguard on the one action in this app that loses work with no way
back. The mutation that removes the guard from `normalizeTag()` does not fail an
assertion about a string; it deletes a Display, in the test, and the suite says so.

**Not the answer an array `display_id` gets.** That is a `MISMATCH` (§4h), and the
difference is what the two parameters are for. The id claims to confirm a Display the
caller is already holding, so a claim that could not have come from a page that knew
its Display is a *disagreement*, and a write is refused. The tag is the address
itself; a non-address disagrees with nothing, and "no sign named" is a state both the
Viewer and the entry rule already have an answer for. Both arms are one line apart in
the same method, so a reader will notice they differ — the comment there says why.

**One consequence worth stating rather than discovering.** Because an array tag means
no tag, the editing entry rule (§3) now covers it: an account with exactly one Display
to work on that posts `display[]=x` gets that Display, where before it got `UNKNOWN`
and a refusal. That is a widening, and it is the same widening `?display=` (empty) and
a missing parameter have always had. The safety property is unchanged and it was never
the tag: the write still has to agree about *which record* it is publishing to
(invariant 12), so the id claim refuses it, and the entry rule still resolves to
nothing the moment a second Display is openable. Both are asserted.

20 checks, and three mutations, each verified to fail:

| Mutation | Killed |
|---|---|
| `locate()` casts the parameter blindly again | 31 |
| `normalizeTag()` folds a non-scalar again | 25, one of them a Display deleted by an array |
| an array tag answers `UNKNOWN` instead of "no sign named" | 16 |

The first two are the code as it shipped for six phases. The third is the plausible
*alternative* decision, and it is in the list because a check that only proves "not a
crash" would pass it — the codes and the wording are pinned to what was decided.

Left standing:

- **Nothing else in the repo casts a request value this way.** `grep -rn "(string)\$_GET"`
  and the `$_POST` form return nothing; every other parameter is read through a module
  that validates it or through `intval()`, which has no opinion about arrays. The two
  seams here are the whole surface.
- **`is_scalar()` and not `is_string()`.** `normalizeTag(12)` has always worked and a
  caller passing a number is not doing anything wrong. An *object* would still fatal
  on the cast — but nothing reaches these seams except `$_GET`, `$_POST` and one
  session value, and none of those can hold one.

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
                                         # XMLHttpRequest through every way an upload ends,
                                         # and a stubbed fetch through every way the two
                                         # opening reads fail
node tools/selftest_viewer.js            # viewer.php's own poll, against a stubbed DOM and
                                         # fetch, through every way an answer can arrive:
                                         # readable, refused, unreadable-with-a-status,
                                         # and never arriving at all. The last two are
                                         # different branches on purpose (§4u)
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
grep -rn "ensureSignageSchema(\$pdo)" --include=*.php .  # four entry points — admin_panel.php,
                                              # builder.php, crud.php, api.php — each within the
                                              # first ~25 lines, before any transaction exists; plus
                                              # tools/, one comment, and the call inside
                                              # repairSchemaAfterFailure(). That *position* is the
                                              # invariant, not the call: DDL commits an open
                                              # transaction in MySQL silently. A call deeper into a
                                              # file wants the guarded door instead — invariant 21
grep -rn "repairSchemaAfterFailure" --include=*.php .  # lib/schema.php defines it and lib/displays.php
                                              # is the one place that *calls* it; the rest are the
                                              # self-test and comments in api.php/viewer.php pointing
                                              # here. A second caller is fine, a second *door* is not:
                                              # anything converging off a failure asks this, or it has
                                              # none of the three refusals
grep -rn "DELETE FROM users" --include=*.php . # nothing outside tools/ (invariant 14). Accounts
                                              # are closed, never deleted, so a freed id can
                                              # never be handed to somebody new
grep -rn "SET password_hash" --include=*.php . # exactly two: lib/accounts.php (setPassword, which the
                                              # reset goes through) and admin_panel.php:160, where an
                                              # admin sets somebody's password in one write with
                                              # nothing to be atomic with. A third means a page is
                                              # changing a password beside another write again — the
                                              # defect invariant 22 exists for
grep -rn "ensureLockoutColumns\|clearLockout" --include=*.php .  # auth.php defines both; login.php calls
                                              # them. reset_password.php must NOT appear: it stopped
                                              # running the three ALTERs when the clear learned to cope
                                              # without the columns (§4r), and a hit there is that DDL
                                              # back on a public page, mid-reset
grep -rn "closed_at" --include=*.php .        # lib/accounts.php, schema.sql's DDL, the fixture,
                                              # and ONE render in admin_panel.php that prints the
                                              # date. A hit that *decides* something is a second
                                              # opinion about what closed means — ask
                                              # AccountStore::isClosed() instead
grep -rEn "(INTO|UPDATE|FROM|JOIN|TABLE) +`?displays`?" --include=*.php .  # lib/displays.php + schema.php's ALTERs
grep -rn "INTO display_permissions\|FROM display_permissions" --include=*.php .  # only lib/grants.php, plus tools/
grep -rn "grants_accounts\|grants_displays" --include=*.php .  # admin_panel.php only, and BOTH names must
                                              # appear twice — once as a hidden input in the grant form,
                                              # once being read. The form declares both axes of the matrix
                                              # it rendered because a browser posts only ticked boxes, so an
                                              # unticked cell and a cell that was never on the page are the
                                              # same absence. One name here without the other is the §4s
                                              # defect back: a save that revokes what it never showed
grep -rn "releaseLockOn\|releaseLocksHeldBy" --include=*.php .  # lib/displays.php defines both; the callers
                                              # are every place a change of *reach* has to free a lock the
                                              # account can no longer let go of — DisplayAdmin twice (a grant
                                              # revoked, a Display turned off) and AccountAdmin twice (an
                                              # account closed; demoted or suspended). builder.php's
                                              # releaseLockOnLeave is a different thing and only matches on
                                              # the name. A new caller is fine; a change of reach with NO
                                              # call is invariant 8's second paragraph
grep -rn "holderActive\|lock_holder_active" --include=*.php .  # lib/displays.php only, and it must appear on
                                              # both sides: the join that fetches it and LockState::isHeld
                                              # that acts on it. The same rule is spelled `users.is_active = 1`
                                              # inside claimLock's WHERE — a read and a write that disagree
                                              # about who holds a sign disagree silently (§4t)
grep -rn "LOCK_TERMINAL\|isTerminalLockReason" --include=*.php .  # builder.php only. The map, and three call
                                              # sites: the heartbeat, the read-only poll, the publish refusal.
                                              # A refusal that ends a session and is acted on in only two of
                                              # the three leaves one kind of page silent, which is the whole
                                              # §4t defect in miniature. `res.reason ===` next to one of them
                                              # is the old shape coming back
grep -rn "flashMessage\|takeFlashMessage" --include=*.php .  # auth.php defines them, admin_panel.php uses
                                              # them for the grant matrix's post/redirect/get. A `flashMessage`
                                              # with no `header('Location'` after it leaves a sentence nobody
                                              # will ever be shown
grep -rEn "UPDATE users SET" --include=*.php . # lib/accounts.php (four: closed_at, password_hash, role/
                                              # is_active/email, the lockout clear), login.php's two failure
                                              # counters, admin_panel.php:168 where an admin sets a password
                                              # in one write, plus tools/. A fifth in a *page* means a `users`
                                              # write beside another write again — invariant 22
grep -rn "lock_holder_id\|lock_activity_at\|lock_taken_at" --include=*.php .  # SQL against them only in
                                              # lib/displays.php. lib/schema.php and lib/server_report.php
                                              # name them as *catalogue entries* — a column this database
                                              # should have — which is not a read of the table
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
grep -rn "HttpCache::" --include=*.php .      # lib/http_cache.php defines it; auth.php and db_connect.php
                                              # each make the call, at the top, and those are the only two.
                                              # Every entry point includes one or both, which is what makes
                                              # the coverage total — neither is universal alone (viewer.php
                                              # opens no session; logout.php and setup_branding.php open no
                                              # database, and answered with no rule at all until this was
                                              # two calls instead of one). A third call is a page that
                                              # thinks it is special; a `Cache-Control` written by hand
                                              # anywhere is a second opinion — invariant 23. The self-test
                                              # checks both halves, so this grep is for reading, not
                                              # policing
grep -rn "http_response_code" --include=*.php .  # lib/error_policy.php (the 500 a failed request ends on),
                                              # api.php (403 signed_out, 413 too_large, and the resolution's
                                              # own code twice), viewer.php's notice branch, auth.php's CSRF
                                              # refusal, and tools/'s browser guard. Every one that concerns
                                              # a *Display* takes its number from
                                              # DisplayResolution::httpStatus() — a literal beside a
                                              # resolution is the second copy of that mapping
grep -rn "httpStatus\|statusForKind" --include=*.php .  # lib/display_request.php decides; viewer.php and
                                              # api.php (twice) emit; the self-test checks every kind. A
                                              # resolution kind added without a code answers 500 and fails
                                              # the reflection count — add the code and the check together
grep -rn "is_scalar\|(string)\$_GET\|(string)\$_POST" --include=*.php .  # the two seams that turn a request
                                              # into a screen name tag, and nothing else: locate() in
                                              # lib/display_request.php and normalizeTag() in
                                              # lib/displays.php. The casts must return nothing —
                                              # `?display[]=x` and `tag[]=x` are an array, and `(string)`
                                              # on one is a log line plus the valid tag `array`, which is
                                              # an address to whatever sign is called that — invariant 24
grep -rn "[^_]DISPLAY_TAG\|waDisplay()" --include=*.php .  # every request naming a Display must send
                                              # DISPLAY_ID / waDisplayId() with it (invariant 12), which
                                              # omission silently opts out of. viewer.php is the one
                                              # exception: a Screen sends the tag alone (ADR-0003)
```

`php -l` cannot see inline JavaScript, and `builder.php` is ~3300 lines of it.
Anything touching that file needs reading, not linting. `node --check` over the
extracted `<script>` body proves it parses; the three node suites go further and *run*
it. `selftest_builder_readonly.js` stubs a DOM holding only the ids a read-only page
emits, which is the only automated way to catch a lookup reaching for a control the
lock took away. `selftest_builder_uploads.js` takes the opposite premise — an admin
who can edit everything — and drives a stubbed `XMLHttpRequest`, which is the only
way to see a missing `.catch()`: the file parses perfectly without one. It also covers
the page's two opening reads, where the defect was subtler than a missing handler — one
handler serving two unrelated failures, so the sentence it printed was false half the
time it appeared.
`selftest_viewer.js` covers the page with the least supervision of any of them — a TV
in a shop — and asks the question a parse cannot: given an answer, does the sign end
up showing the right thing? Its cases are the four an endpoint can produce, and the
two that look alike are the point: an unreadable reply that *arrived* is a refusal to
act on, and one that never arrived is a sign to leave alone.

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
