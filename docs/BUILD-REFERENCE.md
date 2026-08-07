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
| `displays.php` | `Display` + `Background` + `LockState` value objects, `DisplayStore` | **Every** `displays` statement: tag rules and suggestion, canvas bounds, background intents — including **what a background colour is** (§4x): `Background::color()` cannot build a colour that is not one, so the publish path refuses the request and `applyBackground()` declines to write it, rather than each door carrying its own regex and the one without it carrying none — the publish stamp and record, the edit lock (claim / release / seize, and the idle window that decides held-from-free on read), and self-healing when the table is not there yet. It decides only whether the error was the kind a repair could fix; whether repairing is *safe right now* belongs to `schema.php` (invariant 21), because that question is about DDL and transactions rather than about the `displays` table. |
| `grants.php` | `GrantStore`, `Actor` | **Every** `display_permissions` statement, and the whole of "may this account have that Display?" — the two axes of ADR-0005 combined in one predicate, `Actor::mayOpen()`, that the seam and the picker both ask. |
| `display_admin.php` | `DisplayAdmin(PDO, DisplayStore, LayoutStore, GrantStore)` → `DisplayResult` | Administering a Display: what a complete one needs, creating it blank or as a duplicate of one the same shape, renaming, retiring, destroying it with its layout and its grants, and setting the access matrix — each all-or-nothing. Writes no SQL of its own; holds the transaction that spans the three stores. `setAccess()` takes **both** axes of the matrix the form covered — the accounts *and* the Displays — because an unticked box and a cell the form never rendered are the same absence in a POST, and only one of them means "revoke"; and a revoke frees the edit lock on the Display it takes away, by holder, inside the same transaction. |
| `layout_store.php` | `LayoutStore(PDO, DisplayStore)` | The publish transaction end to end: edit-lock and staleness checks, wipe-and-reinsert scoped to one Display, temp-id mapping, asset auto-save, plain-text stripping, admin/basic section rules, element index, lock-checked hide/delete, `assetUsage()` — which Displays depend on a library entry — and the sweep of the library rows a publish strands, scoped to the ids that Display's own previous layout held. |
| `assets.php` | `AssetLibrary(PDO)` — `all` / `forId` / `create` / `update` → `AssetEdit` / `delete` / `pool` / `pooledNotIn` / `discardPooled` / `isAllowedImageRef` | **Every** `assets` statement. The decision it holds: `pool()` no longer de-duplicates, so a published text block's words belong to that block alone — sharing a row meant editing one line changed two signs and deleting it blanked both, permanently, with no undo. The cost is rows left behind, so a pooled row carries a marker and only marked rows are ever swept; a row a person made, or renamed, survives every sweep however it is asked. And **the row says what kind of thing it is** (§4w): `update()` takes no type from its caller, because the two rules an edit must pass — plain text for a text row (ADR-0002), `IMAGE_EXTENSIONS` for an image row — were both switchable by a hidden form field that said the other word. A type is written once, by `create()`, and only ever `text` or `image`; the `carousel`/`table`/`marquee` rows `pool()` writes are stored verbatim, since stripping JSON leaves neither markup nor JSON. `contentIssue()` is the read that goes with all of it: what a row the old rules let through would be refused or changed for today, in words the Library shows on hover — because nothing rewrites those rows, and an admin cannot decide about a state nothing displays. `firstCharacters()` keeps a label from being cut mid-character. One documented read of `assets` lives elsewhere: `LayoutStore::snapshot()`'s LEFT JOIN, read-only and on the path a Screen polls every 30 seconds. |
| `upload_limits.php` | `UploadLimit::bytes` / `describe` / `describeBytes` / `bodyWasDropped` / `smallestOf` / `toBytes` | How big a file can actually reach this server — the smallest of the app's 50 MB ceiling and PHP's `upload_max_filesize` and `post_max_size`, not the app's opinion. And the silent case: exceeding `post_max_size` is not an error PHP reports, it abandons the body, so a 40 MB video was answered *"Security token mismatch. Please reload the page."* `smallestOf()` takes the ini values as an argument because both settings are PHP_INI_PERDIR and the cases worth testing are unreachable otherwise. Depends on nothing. |
| `brand_styles.php` | `BrandStyles(PDO)` | The six branded block types: the only reader and writer of `block_styles`, the validation for every stored value, and the rule that a type absent from a save is left untouched. |
| `password_resets.php` | `ResetTokenStore(PDO)` — `issue` / `verify` / `consume` / `redeem` / `discard`, and `PasswordResetCompletion(PDO, ResetTokenStore, AccountStore)` → `ResetOutcome` | **Every** `password_resets` statement, the 30-minute lifetime, and the guess budget: five tries per issued code, counted on the code's own row so a fresh cookie cannot buy five more. `redeem()` returns a bare boolean on purpose — the reset page must answer "wrong code", "no such account" and "budget spent" in the same words, and a caller that cannot tell them apart cannot leak the difference. It is now the composition of two halves that have to fall on opposite sides of a transaction boundary: `verify()` spends the guess and must never be rolled back, `consume()` spends the code and must be. `PasswordResetCompletion` is the use case (invariant 22) — code consumed, password changed, lockout released, or nothing at all — and `ResetOutcome` has three answers rather than two, because "refused" and "the database would not take it" have to look different to the visitor and identical to a stranger probing for usernames. |
| `accounts.php` | `AccountStore`, `AccountAdmin` — `close()` / `edit()` → `AccountResult` | What it means for an account to be **closed**, and the transaction that closes one: grants surrendered, edit lock released, `closed_at` stamped, all or nothing. Also the two refusals that exist because closing cannot be undone — your own account, and the last admin who can still sign in. And `edit()`, the other three-table change: the role, the active flag and the email in one write, then the grants a **promotion** makes meaningless (an admin holds every Display by role, so the rows would sit there displayed nowhere and removable by nothing) and the locks a **demotion** puts out of reach (no grants left, so the account cannot even release what it is holding). Not a gatekeeper for all of `users`: creating an account and setting somebody's password from the panel are still written there. What lives here is closure and the reads that depend on it, so the files with an opinion about a user row cannot disagree about what a closed one means — plus the three `users` writes that have to happen inside somebody else's transaction, `setPassword()`, `clearLoginLockout()` and `updateProfile()`, because a page cannot hold a transaction over SQL it writes itself — plus sign-in's two, `findForSignIn()` and `registerFailedLogin()`, which `login.php` used to issue itself and which had to move before `LoginAttempt` could be a module with no database in it. Those three are the only methods in the class that let an exception out, deliberately: everything else answers a question, and a question is better answered "no" than not at all, but these are halves of a change that must not half-happen. `clearLoginLockout()` answers true when the three ADR-0001 columns are absent, for the same reason `isClosed()` answers false: a database without them has never locked anybody out. It adds no schema of its own: `closed_at` used to arrive from an `ensureSchema()` here that the admin panel called on every load, and is a gated plan entry as of §4v — which makes the *order* on that page load-bearing, since the store caches whether the column exists and is built after convergence, not before. |
| `server_report.php` | `ServerReport(PDO, server?)` — `runtime()` / `convergence()` / `isConverged()` | What machine this is, and whether the schema actually converged. Reads the database catalogue (through `readSchemaFacts()`, not its own query) and PHP's own configuration, and **no application data at all** — which is why it may name `users`, `displays` and `canvas_elements` without being a second writer. It trusts the catalogue only for a table the read actually covered; anything else falls back to a `SELECT … LIMIT 0`, because a confident wrong "missing" from the one report meant to be trusted is worse than no report. It exists because two things this repo depends on were never observable: the live PHP version (the whole 7.1 rule rests on it) and whether a `schemaTry()` statement landed, which by design fails silently. It takes the request as an argument because the session cookie's `Secure` flag is now decided per request (§4u), and a report that could only describe the request it is running inside could not be checked against the other one. |
| `error_policy.php` | `ErrorPolicy::install(mode)` / `log` / `fail` / `report` / `noticeFor` / `status` | What happens when something goes wrong: the ini settings, set in code so they travel with the deploy and can be read back; the three handlers; where the log lives and when it rotates; and — the part that needed a module rather than a line — the last thing a request prints, which differs by audience. A Screen gets a self-re-checking kiosk notice, an endpoint gets JSON its caller can parse, a person gets a sentence. `noticeFor()` is pure so all three are testable without a failing server. `report()` is the one for a problem the app survived but an admin should hear about, and it takes a window: a problem that recurs on its own schedule — a schema statement retried on every page load, or every 30 seconds per Screen — has its *log line* throttled too, not only its email, or the record of it buries everything else in a 2 MB file. `firstInWindow()` and `stateFile()` are public for the same reason `report()` needs them: a repeated *attempt to fix* something needs the same restraint as a repeated report of it, and this module is where the state directory is decided. Depends on nothing: no database, no session, no config. |
| `alerts.php` | `AlertMailer(stateDir, siteName)` — `notify` / `remember` / `recipients` | Telling somebody. Both halves are on disk rather than in the database, because the commonest thing to alert about *is* the database: the rate limiter is a stamp file (one email per problem per hour, keyed by kind + file + line) and the recipient list is a cache written whenever an admin opens the admin panel. With nowhere writable it sends nothing at all — a limiter that fails open means one email per Screen per poll. `deliver()` is the single line that reaches `mail()`, separated so the rules can be tested without one. |
| `login_attempt.php` | `LoginAttempt(AccountStore)` — `attempt(username, password, now?)` → `LoginOutcome` | One sign-in, decided: which of six answers it is, and the sentence that goes with each. The rule it exists to make checkable is an **ordering** (ADR-0008, invariant 23) — closed, suspended and locked-out are settled before `password_verify()` runs, so the sentence never varies with the password and a guesser on a suspended account is not told when they have got it right. It also carries ADR-0001's two numbers and the counting they drive, in UTC (§4v): a `locked_until` further out than one window was not written by this code and is not honoured, which is both true of the policy and what stops a row left in the old local-time format from locking somebody out for a shift. **It holds no PDO**: every statement is `AccountStore`'s, which is what stops the file that decides what to say from growing a query that says something else, and leaves the thing under test as the decision rather than a database. `login.php` is the adapter — start a session, or print the sentence. |
| `request_scheme.php` | `RequestScheme::isSecure(array $server): bool` | Whether the *browser's* leg of a request is HTTPS, asked for exactly one reason: whether the session cookie may carry `Secure`. A flat `true` there is not a hardening on an `http://` deployment, it is a correct password landing back on a blank login form for ever, because the browser discards the cookie and nothing anywhere says so. Believes the forwarded proxy headers, deliberately — refusing them costs a real Cloudflare-fronted deploy its `Secure` flag, while believing a forged one costs only the forger their own sign-in. Says false when the request says nothing. Depends on nothing, reads no superglobal. |
| `plain_text.php` | `toPlainText(string): string` | ADR-0002's sanitising, in a file with no session side effects so the store can include it. |
| `branding.php` | `BrandingConfig` — `apply` / `current` / `save` → `BrandingWrite`, plus `path` / `load` and the pure `render` / `parses` | The generated `branding_config.php`: the eight settings it holds, their defaults, and **how a file the whole app requires is replaced while the app is running** (§4y). Nothing writes the live path but one `rename()`, and it is not reached until the replacement has been rendered, parsed, written to a temporary file beside it and read back byte for byte — so a reader gets the whole old file or the whole new one, and every failure leaves the site on exactly what it had and says so. `save()` takes only the settings a form actually edited and applies them over `current()`, which is what the old eight-positional-argument call could not do: each of the two forms passed the other's values back in from page variables. And the read side: `apply()` is one call for what used to be seven lines repeated in `config.php`, `login.php`, `builder.php` and `help.php`, each spelling out the same defaults and two of them guarding the `require` on a different constant from the other two. `config.php` calls it and `auth.php` requires `config.php`, so the eight names exist by the time anything renders; nothing already defined is ever overridden, which is what `config.php` promises about `db_credentials.php`. Defining constants is a global side effect no other module has — the exception is deliberate, because the names are the interface every template already reads. Depends on nothing — no database, no session, no config, because the page that manages this file is also the page that has to work when it is missing. |
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
23. **The front door answers every question it can before it reads the password, and
    never hands the browser a cookie the browser will throw away.** Two halves of one
    page, and both defects were invisible in what the screen said. **The ordering:**
    closed, suspended and locked-out are properties of the *account*, so `LoginAttempt`
    settles all three before `password_verify()` runs — otherwise the sentence a person
    reads is a function of the password, and a guesser working a suspended account is told
    when they have got it right (ADR-0008). Closed is asked before suspended because
    closing clears `is_active` as well; both are asked before the lockout because "wait 15
    minutes" has to be advice that comes true. Those accounts also write no failed attempt,
    because a counter that moves for one password and not another is the same oracle in
    another form. Anything added to this path answers before the password or it is the
    defect again, and it will look like tidying. **The cookie:** `Secure` is set from
    `RequestScheme::isSecure()`, per request, never flat — a browser discards a `Secure`
    cookie that arrived over plain HTTP, so on an `http://` deployment the flat version was
    a correct password landing back on a blank login form for ever, with nothing logged and
    nothing to read. A protection that cannot apply is not applied; it is reported instead,
    on Settings → This Server, in words that say which case it is. **And two things the
    page must not do:** it runs no DDL — the three lockout columns are gated plan entries
    like everything else, because `ensureLockoutColumns()` firing three `ALTER`s per POST
    made the login form the one piece of DDL in the app a bot could reach without an
    account; and it verifies a CSRF token *before* looking at the account, **softly**,
    because a hard 403 on the front door answers the commonest cause — a browser not
    keeping the session cookie — with the word "security" and no way forward.
24. **A generated file the app requires is replaced by `rename()`, never written in
    place.** There is one of these — `branding_config.php`, which `config.php` loads
    and `auth.php` requires `config.php`, so it is on every page — and
    `file_put_contents` on it opens the live path with `O_TRUNC`: for the length of the write, every page
    of the app, sign-in included, requires a file that is empty and then partial. A
    write that does not finish leaves it that way. So the replacement is built beside
    it, checked, and moved over it in one atomic step, and nothing else touches the
    live path; a failure is a save that refused, not a site that is down. The rule is
    about the *shape* rather than the file: anything generated into a path something
    else loads — a cache, a compiled template, a second config — belongs behind
    `BrandingConfig`'s pattern or it is this defect again with a different filename.

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
  is null-safe and needs no role test at all. **This is decision #40**, closed here
  rather than separately, the way #43 was closed by #20.
- **The same banner had a second lookup, and it was the more expensive one.** The
  reveal at `DOMContentLoaded` spelled the emit condition out again in JavaScript
  — `if (!IS_ADMIN && !READ_ONLY)` against markup emitted on `!$isAdmin && !$readOnly`.
  Two copies of one rule that happened to agree, which is the shape of the defect
  above and not a fix for it. It now goes through `showSectionBanner()` and carries
  no role test either, so the markup is the only thing that decides. Worth doing
  even though the copies did agree: the two calls after it in that handler are the
  zoom fit and `setupLockWatch()`, so had the rule ever drifted, a basic clerk
  would have lost not just the banner but the watch that is how a read-only page
  learns it has lost the sign at all.
- **`uploadSlideImage()` gets an explicit `READ_ONLY` guard**, because it is the
  one handler that never needed a selected block, and "its modal is not in the
  page" is the argument this section exists to stop relying on.
- **The background controls were the last place the rule was written twice.**
  `toggleBgInputs()`, `applyBg()` and `applyBgFile()` guarded with
  `if (!IS_ADMIN || READ_ONLY) return` against markup emitted on
  `$isAdmin && !$readOnly`, and `loadLayout()` calls the first two on every page
  load. That copy was correct in all four role/lock combinations — each was
  checked — and correctness is not what was wrong with it: it is the same shape,
  one storey down, as the banner test that threw. All three now ask for their
  control and give up if it is not there, and the `IS_ADMIN &&` at the call site
  went with them.
- **What is *not* guarded, and why.** Roughly ninety derefs inside the inspector
  and the two modals stay unguarded. Every one sits behind `if (!activeBlock)
  return`, and `activeBlock` cannot be set here: `selectBlock()` is the only
  assignment that can make it non-null and it returns on `READ_ONLY`. That is the
  call-graph property this section warned about rather than a rule — so it is now
  a rule. The suite asserts both halves: that `selectBlock()` refuses and leaves
  `activeBlock` null, and that there is still exactly **one** assignment able to
  make it non-null, since a second one appearing is the change that would put all
  ninety back in reach.

What did *not* change: `CSRF_TOKEN` still ships, because a read-only admin can take
the lock over and that POST needs it, and the server-side refusals are untouched.
Console access remains console access — this closes the gap between what the file
claims and what it does, not the one between a browser and an API.

`tools/selftest_builder_readonly.js` is new, and is the reason this is checkable
rather than merely done: it strips the PHP, evaluates `builder.php`'s own inline
JavaScript with `READ_ONLY = true`, and stubs a DOM holding **only** the ids that
page emits, so any lookup of a removed control throws and a throw is a failure. It
also walks the file's `<?php if (!$readOnly):` blocks to assert the four regions
really are inside one. Thirty-nine checks, and it now runs the page load itself —
`loadLayout()` for both background types, every block type drawn, then the zoom fit
and the lock watch — rather than only the click paths. That matters more than
coverage arithmetic: the three background seams above were converted and then left
unheld, and the first mutation run proved it by changing them back with the suite
still green.

Drawing is covered because it is what a read-only page is *for* — somebody watching
a sign sees every block on it, and `renderBlock()` runs for all of them on a page
with nowhere to put an inspector. Getting that honest took one correction worth
recording: `document.querySelector` in the stub returned null for everything, and
`loadLayout()` finds a block's parent section with exactly that call and skips the
block when it comes back null. A child block therefore never rendered and
`renderBlock()`'s `isChildBlock` branch was never taken, while the suite reported
having drawn the layout. The stub now answers that one selector, and a mutation
confirms the branch is reached.

Verified against nine mutations: shipping the inspector again fails 3, dropping
`deselectAll`'s guard fails 1, restoring the role-only test in
`setSectionBanner()` fails 2 and in `showSectionBanner()` fails 1, dropping
`loadAssets`'s guard fails 1, dropping the null guard in `applyBg()` fails 1, in
`toggleBgInputs()` fails 2 and in `applyBgFile()` fails 1, letting `selectBlock()`
run on a read-only page fails 1, adding a second `activeBlock` assignment fails 1,
and pointing `zoomToFit()` at a control that is not there fails 1. The drawing
check carries five of its own, each pinning a branch rather than the call: throwing
from `renderSection()`, from `renderBlock()`, from its `isChildBlock` branch and
from its locked-block branch each fail it, as does making `renderBlock()` reach for
an inspector field — which is the actual defect class, rehearsed.

One deliberate non-failure: restoring `IS_ADMIN &&` in front of the
`toggleBgInputs()` call leaves the suite green, and should. That call was safe
before and after — short-circuiting is not a bug — so it was tidied rather than
fixed, and a check that failed on it would be pinning a preference.

**That stub DOM is now checked against the page rather than trusted.** The list of
ids it answers to was hand-written, and it was the one part of this suite nothing
held to the file — which matters more than it sounds, because the failure is
silent and inverted: a name listed there hands back an element where a browser
hands back null, so the very null-deref this suite exists to catch stops being
visible to it. It had already drifted. `lock-holder` was in the list and was never
an id at all — it is `LOCK_HOLDER`, a JavaScript variable. Nothing looked it up, so
nothing broke; but nothing was stopping the next entry either.

So the same conditional walk that proves the four regions are editable-only now
also asks, for every id in that list, whether the markup can emit it at all when
`$readOnly` is true and `$isAdmin` is false. Conditions it cannot decide —
`!$display->isActive()` is the real one — are tried both ways and a single "yes" is
enough, because the question is whether the page *can* emit the node, not whether
it always does. Two checks, and the second is a control: it asserts the walker
still judges `#inspector` absent, so a walker that had degenerated into answering
"present" to everything fails rather than passing while proving nothing. Verified
against four mutations: restoring `lock-holder` fails 1, adding `inspector` fails 2
(the list check, and then `showInspector()` really does throw — which is the hazard
demonstrating itself), adding `bg-type` fails 1, and making the walker always
answer "present" fails 1.

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

### 4u. Two ways the front door lied

Both of these are on `login.php`, both are one line long in the diff, and neither is
visible in the words on the screen. That is what they have in common: the sentence a
person reads was right in every case, and the defect was in *when* it was chosen and
*whether it could ever arrive*.

**The password oracle.** The page decided in this order — locked out, wrong password,
closed, suspended, in — with three of those branches below `password_verify()`. So a
guesser working through passwords on a suspended account got "Incorrect username or
password" for every wrong one and a *different sentence* for the right one. The app
announced the correct password on exactly the accounts where a password was supposed to
have stopped being worth anything, and it announced it to the one party who wanted to
know. Passwords are reused; a guess confirmed against a retired clerk's account is a
guess to take to their email.

The fix is an ordering, not a wording: **every question that does not depend on the
password is answered before the password is read**. Closed, suspended and locked-out are
properties of the account, so they are settled first and the sentence they produce is
then the same whatever was typed. The order *within* that group is decided too — closed
before suspended, because closing clears `is_active` and the other way round sends a
retired employee to ask a manager for the one thing this app will not do; both before
locked out, because "wait 15 minutes" has to be advice that comes true. ADR-0008 carries
the alternatives, including the obvious one — a single generic sentence for everything —
and why it was rejected: it leaves a closed account with no true information anywhere in
the app, and the reset page silently does nothing for them.

The cost is stated rather than hidden: someone who knows a username can now learn it is
suspended or closed without knowing the password. ADR-0001 already accepted a weaker
version of that trade, and what this leaks names accounts nobody can sign in to under any
password.

It also had a **side channel that a message rule alone would not have closed**. A
suspended account used to accrue failed attempts, so with four failures already banked, a
fifth guess that locked the account and a fifth that did not would have told the guesser
the same thing the sentence no longer does. Closed and suspended accounts now write
nothing at all — there is nothing to protect on an account that cannot be signed in to.

**The invisible sign-in loop.** `auth.php` set `Secure` on the session cookie
unconditionally. A browser silently discards a `Secure` cookie that arrives over plain
HTTP, and PHP silently sets it anyway — so on any deployment reached over `http://`, and
`.htaccess` forcing HTTPS is a *configuration* rather than a guarantee, sign-in did this:
the password verified, the session was written, the redirect went to `builder.php`, the
cookie was thrown away in transit, `builder.php` found no session, and the browser landed
back on a clean, blank login form. A correct password, any number of times, with no error
anywhere — nothing in a log, nothing to search for, and nothing the person could change
that would help. The one class of failure this app cannot afford is the one where the
thing that is wrong is invisible, and this is that failure on the page everything else is
behind.

`lib/request_scheme.php` answers one question — is the *browser's* leg of this request
HTTPS — and the cookie flag follows it. Two things about it are deliberate:

- **The forwarded headers are believed**, which is the opposite of the usual advice, and
  the reasoning runs backwards from the usual case. Refusing them breaks a real
  deployment: behind Cloudflare or any TLS-terminating front end the request reaches PHP
  as plain HTTP, and a browser that *is* on HTTPS would be handed a cookie without the
  flag it could have had. Believing a forged one costs the forger their own sign-in and
  nobody else's, because these attributes are set on the response to the request that
  carried the header — a per-request header cannot mark another person's cookie.
- **An array that says nothing answers false.** The safe direction here is the one where
  sign-in works and the cookie is unprotected, not the one where the cookie is perfect and
  nobody can get in.

And the one screen that would otherwise call the fix a fault had to be taught the
difference. **Settings → This Server** used to print "One of the protections on the
sign-in cookie did not apply" for any missing flag; on a plain-HTTP host that is now the
correct configuration being reported as broken, which is how a true row gets ignored. It
now says which case it is looking at and, for the plain-HTTP one, what to do about it —
reach the site over https and the row reads yes.

**Where the decision went.** `login.php` used to hold the ordering, a `SELECT`, a
fallback `SELECT`, and two lockout `UPDATE`s. The statements moved to `AccountStore`
(`findForSignIn`, `registerFailedLogin` — which is the function name auth.php's comments
had been claiming existed for some time), and the decision moved to `LoginAttempt`, which
**holds no PDO at all**. That is not tidiness: it is what makes "the refusal is decided
before the password is read" a property that can be checked rather than one that is meant,
because a file with no database handle cannot grow a query that quietly says something
else. The page is left with the two things a page does — start a session, or print a
sentence.

**Fifty-seven checks**, and eleven deliberate mutations, all eleven killed (kill counts
10, 8, 2, 4, 18, 2, 2, 6, 6, 2, 7). Four worth naming:

- Restoring the original order — every state check back below `password_verify()` — kills
  ten. The checks that catch it assert that the message is the **same** for a right and a
  wrong password, not what the message says, so the sentences stay free to be reworded and
  the ordering does not.
- Asking suspended before closed kills eight. It is the mutation that looks like
  alphabetical tidying and is the one place the order carries a meaning.
- Letting a suspended account accrue failures again — the side channel rather than the
  sentence — kills four, none of them a message check.
- Reading the *last* hop of `X-Forwarded-Proto` instead of the first kills two. The list
  describes legs behind the front door; only the first one is the leg the browser judges.

Three things were left standing when the two halves above landed — the lockout's clock,
the DDL the login page was firing, and the missing CSRF token. All three are done, in
§4v, which is where the reasoning for each of them is.

### 4v. The three §4u left standing

Not a tidy-up. Each of these is the same shape as the two in §4u: something the page does
that nobody can see it doing, and that only shows up as a person unable to sign in.

**The lockout kept local time.** `date()` on the way in, a bare `strtotime()` on the way
out. Self-consistent, and wrong for the reason the edit lock was wrong before §4t: local
wall-clock is not monotonic. The autumn fall-back replays an hour, so a stamp written in
the second pass sorts below one written in the first, and `strtotime()` resolves the
repeated hour to its first occurrence — fifteen minutes of brute-force protection going
quietly missing, once a year, on the night nobody is looking. Both stamps are UTC now,
the way `claimLock` has been since §4t.

The reason this was left standing the first time was the migration, and it is worth being
exact about it rather than repeating the shape of the worry. **Every `locked_until` on the
live database is in the old format**, and what happens when one is read as UTC depends on
which side of UTC the server sits:

- On UTC — which is what PHP falls back to when `date.timezone` is unset, and §4k says the
  live value is still unknown — nothing happens at all. Old rows and new rows are the same
  string.
- **West** of UTC, which is where the store is, an old stamp reads seven or eight hours
  *earlier*, so any lockout in force at the moment of the deploy is released. Bounded and
  self-correcting: `locked_until` is never more than fifteen minutes ahead in the first
  place, so every affected row is gone within fifteen minutes of the deploy, and the
  failure counter beside it is untouched.
- **East** of UTC an old stamp reads *later*, and that is the direction that matters: a
  fifteen-minute lockout lasting the rest of the shift, on the one page there is no way
  around.

So the read carries a rule: **a `locked_until` further out than one window is not a
lockout this code wrote, and is not honoured.** ADR-0001 says a lockout is one window long,
so that is a true statement about the policy as well as a migration guard. It ignores
rather than truncating, because "no later than one window from now" re-anchors on every
request and therefore never arrives. And ignoring costs almost nothing: `failed_attempts`
is left alone, so the one guess it hands back counts as the sixth and locks the account
straight again, with a stamp in the format this code can read. The hole a legacy row opens
is one guess wide and it closes itself.

**The login page was running DDL.** `ensureLockoutColumns()` fired three unconditional
`ALTER TABLE users` statements on every sign-in POST — before the password was checked,
before anything knew whether the username existed. That made it the only piece of DDL in
the app reachable **with no account at all**, and the traffic that reaches it most is
precisely what ADR-0001 was written about: a credential-stuffing bot, issuing three no-op
table alterations and taking three metadata locks on `users` per guess. Item #8 removed
exactly this pattern everywhere else in the app a year of requests at a time; this one
survived because ADR-0001 had put it there on purpose, for a reason (keep migrations off
the public viewer poll) that gating satisfies just as well.

They are three ordinary entries in `signageSchemaPlan()` now, with three ordinary gates,
which means they run on an authenticated page or not at all. The consequence is stated in
the code rather than discovered later: on a database where the columns have never landed,
the **first** sign-in happens without a lockout — the state `findForSignIn()` was already
written to answer for — and it lasts until the first authenticated page load, which is the
Builder that same sign-in lands on. `ensureLockoutColumns()` is gone; nothing pre-auth
converges anything any more.

**The front door had no CSRF token.** Login CSRF is the one people wave away because "the
attacker already knows the password" — but the password they know is *their own*. It signs
a visitor into the attacker's account, silently, and everything that person then does —
every price they type, every publish — happens in an account somebody else can read. On an
app whose whole job is what a customer reads off a sign, that is a defacement channel with
a login form in front of it. Every other POST in this app was covered.

It refuses **softly**, and that is the whole design of it rather than a nicety.
`verifyCsrf()` ends the request with a 403 and the word "security", and the commonest cause
of a missing token on *this* page by a distance is a browser that is not keeping the
session cookie — which has no token to send and never will. A hard failure there is §4u's
invisible sign-in loop all over again with something frightening written on it. So the page
keeps the username, says what is actually likely to be wrong ("this browser is not keeping
cookies for this site, which sign-in needs"), and lets them try again. The gate is also
ahead of the account lookup, so a request with no token cannot be used to run a stranger's
failed-attempt counter up to the lockout.

**Twenty-one checks**, and eight deliberate mutations, all eight killed (kill counts 4, 6,
6, 19, 20, 2, 2, 2). Two worth naming:

- Leaving the three lockout columns out of the plan kills nineteen — most of them the
  existing convergence checks, because `convergedSchemaShape()` now lists them and a
  converged database that is still issued DDL is the thing invariant 19 is.
- Reading the stamps as local time again kills four, and *only* because the checks set the
  process timezone to the store's own first. On a server that happens to be on UTC the
  mutation is invisible, which is exactly how the defect survived this long.

The four checks covering the CSRF token read `login.php`'s **source**, and that is a
weaker instrument than everything else in the suite — it shows the lines are there, not
that the page behaves. The behaviour they gate, `csrfOk()`, is checked properly; what
cannot be reached from a CLI suite is a page that prints HTML and opens a real database.
Named rather than dressed up.

**And the last one, since it was the same defect.** `AccountStore::ensureSchema()` was an
ungated `ALTER TABLE users ADD COLUMN closed_at` on every admin-panel load — much smaller
than the login one (authenticated, one statement, a page nobody hammers) and the same
shape, so it is a gated plan entry now too and the method is gone. Two consequences worth
writing down rather than discovering:

- **The order on `admin_panel.php` is load-bearing.** `AccountStore` caches "is `closed_at`
  there" on first ask and never re-asks, and `ensureSchema()` used to clear that cache
  after altering. The page converges on line 29 and builds the store on line 50, so the
  cached answer is taken after the column has had its chance. A store built *above*
  `ensureSignageSchema()` would answer no for the whole request and quietly hide every
  closed account from the panel that just created the column.
- **`ServerReport` now names `closed_at` too**, which it never did — the one report in the
  app whose job is to say whether a runtime-added column landed was silent about this one.
  Its consequence line is the real one: no account can be *closed*, only suspended, and an
  id can still come back into service under a different person (invariant 14).

That leaves `ResetTokenStore::ensureSchema()` as the only convergence still running off an
unauthenticated page, and it stays there **deliberately** rather than by omission. The
difference is that the lockout and closure columns are optional — the app has a defined
behaviour without them — and the reset-token *table* is not: no table, no password reset.
Moving it behind authentication would mean the one person who cannot sign in is the one
person who cannot repair the thing that would let them, on an installation where nobody had
opened the admin panel yet. It is one `CREATE TABLE IF NOT EXISTS` plus its column, and
that is the right trade.

---

### 4w. The form said what kind of thing it was editing

Decision #37. The Library's edit form posted the row's type back in a hidden field,
and `crud.php` decided from that field alone what the new content was allowed to be.
Two rules hung off one request parameter:

```php
$type    = $_POST['edit_type'] ?? '';
$content = ($type === 'text') ? toPlainText(...) : trim(...);   // ADR-0002
if ($type === 'image' && !isAllowedImageRef($content)) { refuse; }
```

Send the other word and the matching rule is not enforced — it is not bypassed by a
trick, it is simply not the branch that runs. `edit_type=image` while editing a text
entry stores markup in a value ADR-0002 says is plain text. `edit_type=text` while
editing an image entry skips the allow-list, so the entry every sign reads its
picture from can be pointed at an `.svg` on any host. Both are one edit to one row
that every Display drawing on it picks up within thirty seconds, with no publish and
no undo.

**The type was never the caller's to state.** Nothing changes a row's type, no form
offers to, and the database already knows it. So `update()` takes an id, a label and
a content candidate, reads the row, and applies the rule for the type it finds. The
interface has no parameter to get wrong and no branch a caller can pick. That is the
whole fix; everything below is what fell out of reading the row.

- **A save is not a save when there is no row.** The `UPDATE … WHERE id = ?` it
  replaced matched nothing and returned `true`, so an entry deleted in one tab and
  saved in another printed *"Asset updated successfully."* — the §4g pattern exactly,
  a write that failed quietly and reported success. `update()` returns `AssetEdit`
  now: `ok`, `missing`, `refused`, `failed`, four outcomes because the page has four
  things to say and must not work out which by reading a message string.
- **`!empty($content)` was refusing a real price.** The page's guard against blanking
  a row — right to exist, since blanking one blanks that line on every sign reading
  it — is false for a text entry reading exactly `0`. It is `=== ''` inside the
  module now, which is the same lesson the self-test already carries for
  `manual_content` and the same falsy-coalesce family as §4g's `?:` on a decode.
- **A file upload onto a text entry is refused before the file is moved.** The form
  only ever shows a file picker for an image entry, so a POST carrying one for a text
  entry is not the form we rendered. It used to be accepted, and the entry's words
  became `uploads/crud_1712….jpg` — rendered, as words, on every sign using it.
- **A failed `move_uploaded_file()` said nothing at all**, and fell through to save
  whatever was in the path field. It reports now.
- **The allow-list moved to the module and there is one of it.** `IMAGE_EXTENSIONS`
  is what an upload's extension is checked against, what a path typed into the add
  form is checked against, and what an image row's content is checked against on
  every write. Three doors into one table had been carrying their own copy of the
  same list.
- **`carousel`, `table` and `marquee` rows are ordinary here, and the old form drew
  them as images.** Publishing pools a block's content under the *block's* type, so
  those rows exist — holding JSON. The editor had two branches, text and
  everything-else-is-an-image, so a carousel entry was rendered with its JSON inside
  an `<img src>` and offered a file picker to replace it. Now the third case is drawn
  as what it is, and stored verbatim: stripping markup out of JSON leaves neither.
  This is also why the rule is "text is plain text" rather than "not-text is an
  image" — the latter would have made those entries uneditable.
- **`create()` is the one moment a caller's word for a type is taken**, and it takes
  it only for the two the add form offers. A row of some third type created there
  could afterwards only be edited by guessing what it had been.

**Twenty-eight checks, eight mutations, all eight killed** (kill counts 2, 6, 6, 4,
2, 4, 2, 2). The two worth naming are the two halves of the original defect —
dropping the plain-text conversion kills 2, dropping the image allow-list kills 6 —
because those are what a hidden field saying the wrong word actually did, and neither
was detectable before: no test could send a type at all, since the page was the only
thing that had one. Two of the checks read `crud.php`'s source rather than running
it, for the field itself: a hidden input still on the page is one `$_POST` read from
deciding this again, and the page is not reachable from the fixture.

Left standing, and named rather than skipped:

- **The add form still reads its type from the request**, which is correct — there is
  no row yet, and the person is choosing. It is pinned to the two values the
  `<select>` offers, so the range of what a stored type can be is closed at both ends.
- **Nothing repairs a row a previous version mis-typed** — and that stays true. A
  text entry that already holds markup, or an image entry already pointing at an
  `.svg`, is left exactly as it is. Rewriting stored content on read is a write the
  person did not ask for, on a table with no undo, and it would change what a sign
  says without anybody pressing anything. What *was* wrong is that the state was
  invisible: nothing showed it, and the first anybody learned of one was a refusal
  while editing something else. `AssetLibrary::contentIssue()` answers what is wrong
  with a row in a few words, the Library marks those rows **check** with the reason
  on hover and counts them above the table, and the decision to change anything stays
  a person's. The text test is the exact predicate *"saving this would change it"*
  rather than a guess at whether the markup was meant, so the warning is never wrong
  about the thing it warns about — `Halibut < 5 lb` is not accused of anything.
- **The Library page is admin-only for editing, so this was never a privilege
  boundary** — an admin can point an image entry at any allowed host either way. What
  it was is a rule that could be skipped by accident: a stale tab, a resubmitted form,
  a copied POST. Decision #24 was the same shape on the `displays` side; §4x.

### 4x. One door checked the colour and the other did not

Decision #24, and the same shape as §4w one table over: a rule that lived at a door
rather than in the module, so the door without it was the one with no rule at all.
`DisplayAdmin` put every colour it stored through `cleanColor()`; `api.php`'s publish
path called `Background::color($_POST['bg_val'] ?? '#1a1a2e')`, which took whatever
arrived. So a publish could write any string into `displays.bg_val`.

**What that is worth, stated accurately.** The decision list describes this as
pointing every screen at any host, which is the `url()` concatenation the *image*
branch would allow — and the API cannot reach that branch: an image background comes
from a validated upload under a server-generated name, or from `keepImage()`, which
changes no path. The colour branch reaches `canvas.style.backgroundColor = bg_val` in
both clients, and the CSSOM silently discards an assignment it cannot parse. So the
real defect is narrower and quieter than the row reads: **an unreadable value sits in
a column four readers assume is six hex digits, and every one of them fails its own
way without saying so.** The Viewer and the Builder keep the colour they already had;
the panel's `strcasecmp` sees a change that is not one; and `<input type="color">`
rounds what it cannot parse to `#000000`, so the next admin who saves anything on
that Display publishes black. That last one is decision #41, arriving by this route.
Recording the correction rather than the row, for the same reason #32 carries one:
the numbering is the owner's and stays as it is, but nobody should re-derive the
severity from a sentence that was aimed at a different branch.

**The rule is on `Background`, which is what neither door could bypass.**
`Background::color()` now returns a colour intent or an `INVALID` one — it cannot
build a colour that is not a colour — and three things fall out:

- **`LayoutStore::publish()` refuses the whole publish**, before the transaction,
  because nothing about the Display's state decides this and a doomed request should
  not queue for the row lock. Refusing the *publish* rather than dropping the
  background from it is invariant 5: dropping it is a merge, and the admin would be
  told their publish succeeded while the one change they made was discarded. The new
  `PublishResult::invalid` is a fourth kind because it is a fourth thing to do about
  it — a stale stamp says reload, a lock says wait, this says the request itself is
  wrong and neither will help.
- **`DisplayStore::applyBackground()` declines to write one**, which is the write
  side agreeing with the checker for the reason §4t gives: a writer that stores what
  the checker rejects only needs one caller who forgot to check.
- **`DisplayAdmin::cleanColor()` asks instead of restating.** It still *coerces* —
  that is decision #21 and still open, and the tests assert the coercion so the
  difference between the two doors is deliberate rather than forgotten. What it no
  longer has is its own regex. A drifted copy would have been the worst of both: the
  panel accepting a spelling the store then declines to write, and reporting success
  over a Display that did not change. There is a check for exactly that, because the
  mutation that restores the second regex kills nothing without it — the two agreeing
  is invisible until they disagree.

Three-digit `#fff` and named colours are refused on purpose. Every value this app has
written is the six-digit form, and `bg_val` is compared as text, so a second spelling
of white would make two rows that mean the same thing look different.

**Twenty-three checks, five mutations, all five killed** (20, 8, 4, 2, 2). Not
touched: `builder.php`, which cannot produce the refusal — its colour input always
yields six hex digits, and a reason it never sees does not belong in its fixed list
of ones it acts on (§4t). The generic branch shows the message and keeps the work on
screen, which is the right outcome for a client that is not this Builder.
`BrandStyles::cleanColor()` keeps its own rule as well: a different table, a
different fallback, and the same coercion question, which is #21's to answer.

### 4y. The file every page requires, rewritten under them

Decision #36, and the only defect in this list whose blast radius is the whole
application at once. `branding_config.php` is generated PHP — eight `define()` calls
for the logo path, four nav colours, the site name and the two mail-from fields — and
four separate files require it: `config.php` for everything signed in, and
`login.php`, `builder.php` and `help.php` each on their own account. It is the one
file here whose *contents* the running app edits and whose *syntax* the running app
depends on.

The Admin Panel wrote it with `file_put_contents($path, $php)`. That opens the live
path with `O_TRUNC`, so for the length of the write the file is empty, then partial,
then complete:

- **A reader arriving mid-write** requires a file that stops in the middle of
  `define('BRAND_ACCENT`. A parse error is a fatal, and a fatal on a required file is
  a blank page — not on the Admin Panel, on *every* page including `login.php`, for
  everybody, for as long as the write takes. The window is small. It is also the
  window during which the person who pressed Save is reloading the page.
- **A write that does not finish** leaves it there permanently: a full disk, a quota,
  the host reaping the process. `file_put_contents` returns the byte count it managed
  and the old code compared that against `false`, so a half-written file was reported
  as **"Branding saved."** while the site stayed dark. The error message it had for
  the other case — *"Could not write branding_config.php. Check file permissions."* —
  could only ever be printed by a request that had already truncated the file it was
  talking about.

**The fix is the decision as written, with the checks that make it mean something.**
`BrandingConfig` renders the source, parses it, writes it to `.branding_config.<rand>.tmp`
in the same directory, reads *that file back* and compares every byte, matches the
live file's permissions, and moves it over with one `rename()` — atomic within a
filesystem on POSIX. A reader gets the whole old file or the whole new one. Nothing
else touches the live path, which is why every refusal here can say, and does say,
that the site is still running on exactly what it had.

Four details that are not decoration:

- **The read-back replaces a byte count, rather than joining it.** The count a
  syscall returns is a claim about the bytes; the bytes are what a reader will get.
  There is deliberately no `strlen()` comparison beside it — a second check that can
  only agree is a second opinion waiting to disagree, and the same reasoning removed
  the `is_writable()` guard in front of the write: it answers about the moment
  before, it is wrong for root and for ACLs, and the attempt itself gives the true
  answer with nothing at stake.
- **The parse is the module's promise, not input validation.** Nothing a caller can
  pass reaches it — `var_export` emits a single-quoted literal, and the self-test
  counts the `define` calls to prove a site name full of quotes and semicolons cannot
  add a ninth. Deleting the guard on its own therefore kills no check. What it does
  is stop a *future* edit to `render()` from taking the site down: the mutation that
  breaks `render()` **and** removes the guard is the one that makes the live file
  stop parsing, and it was run. With the guard, a broken renderer is a refused save.
- **The temporary file is never named `*.php`.** Apache's
  `AddHandler application/x-httpd-php .php` matches that extension *anywhere* in a
  filename, so `branding_config.php.1234.tmp` is executed by a common configuration.
  The leading dot keeps it out of a listing and the root `.htaccess` denies the name
  outright — that deny rule is checked by the self-test, because the name is now
  spelled in two files.
- **`opcache_invalidate()` after the swap**, because `rename` changes the inode and
  `opcache.validate_timestamps=0` is an ordinary production setting. Without it the
  admin is told the branding saved and nothing on the site changes. This is the one
  thing here with no automated check: opcache is off for CLI, so the mutation that
  removes the call kills nothing. It is here on the argument, not on a test.

**One thing this changes about the deployment, and it is not a small one.** Writing
in place needed write permission on `branding_config.php`. Swapping needs write
permission on the **directory** that holds it, because that is where the temporary
file is created and what `rename()` modifies. On a host where the webroot is owned by
one account and only that one file was made writable for the web user — an ordinary
arrangement — branding and settings saves will start failing after this deploy, with
*"The new settings file could not be written … Check the folder permissions."*, which
is the right sentence and still a surprise. Check it on the first save after this
reaches the server; nothing else in the app cares.

**A second defect fell out of the same rewrite.** `writeBrandingConfig()` took all
eight settings positionally, so each of the two forms passed the other's three or
five values back in from page variables — the Branding form re-wrote Site & Email,
and Site & Email re-wrote the colours, every time. It worked only because those
variables happened to be correct. `save()` now takes just the settings a form edited
and applies them over `current()`, and nothing else can be written at all: a name
that is not one of the eight is refused rather than stored in a file every page loads
and nothing reads.

**And the read side, which is where the eight names were really kept.** Four files —
`config.php`, `login.php`, `builder.php`, `help.php` — each carried the same seven
lines: a guarded `require` of the generated file, then five `if (!defined(…)) define(…)`
fallbacks. Four copies of one list is four things to change and three chances to
forget, and they had already drifted in two ways worth naming:

- The `require` was guarded on `BRAND_LOGO` in `config.php` and on `BRAND_NAV_BG` in
  the other three, so a file defining one and not the other loaded on some pages and
  not others.
- `config.php` defined `MAIL_FROM`, `MAIL_FROM_NAME` and `SITE_NAME` as a **group**
  behind a single `if (!defined('MAIL_FROM'))`. A branding file naming one and not
  the rest left `SITE_NAME` undefined — which in PHP 8 is an `Error`, so a fatal, on
  every page. Nothing generated such a file, but the whole point of `#36` is that
  this one is hand-editable and sometimes hand-edited.

All of it is `BrandingConfig::apply()` now, called once from `config.php`, which
`auth.php` requires at the top of every page. Each name is filled in on its own and
**nothing already defined is ever overridden**, which is what `config.php` has always
promised about `db_credentials.php` — a promise the generated file was breaking, since
a bare `define()` of a name already taken raises a warning and then keeps the first
value. So `render()` emits `defined(…) || define(…)`: the override works, silently,
and the file is safe to load after anything else with an opinion about those names.

Defining constants from inside `lib/` is a global side effect nothing else there has.
That is deliberate rather than an oversight: the names *are* the interface every
template reads — `<?= htmlspecialchars(BRAND_ACCENT) ?>` sits inside the CSS — and the
alternative is the four copies coming back. The two checks that keep it that way read
the page sources: **no `.php` file outside `branding_config.php` may declare one of
these names, and none may reach for the generated file directly.**

The reload check is the sharp one, and it needs no assertion of its own. Every name is
already defined when the suite runs, so the section writes a rendered file and
`include`s it — and this harness turns any unsuppressed diagnostic into a failed
check. Going back to a bare `define()` produces eight warnings, which is eight
failures and a check count that no longer matches.

Proving that merge needed a test double. In a self-test run all eight constants sit
at their defaults, so "kept what was in force" and "reset to the defaults" produce
identical bytes; `PinnedBrandingConfig` pins them apart. `ShortWriteBrandingConfig`
is the other one, and the more important: `file_put_contents` cannot be made to run
out of disk on demand, so `putTemp()` is a `protected` seam and the double hands back
half a file. What the test asserts around it is not that the save failed — it is that
the file every page of the app requires is exactly as complete afterwards as it was
before.

The section also `chdir`s into its throwaway directory and back, which is not
tidiness. This is the one module whose subject is a *path*, so the mutation that
makes `path()` answer with a bare filename points every save in the section at the
deployment's own `branding_config.php` — it rewrote the repo's copy once before the
`chdir` was there. A relative answer now lands in the throwaway directory with
everything else, and the check that `path()` is absolute still fails.

**Fifty-seven checks, eighteen mutations, seventeen killed.** For the swap: 8, 5, 1,
2, 1, 2, 4, 3, 1, 4, 1, 2, 2, and 0 for the opcache call. For the read side: a bare
`define()` in the generated file kills 9, an `apply()` that overrides kills 14, a page
declaring one of the names kills 1, and taking the `apply()` out of `config.php` does
not fail a check — it ends the run, because `SITE_NAME` stops existing and the suite
dies where the app would. Two hardening opportunities
were deliberately left: the four `preg_match('/^#[0-9a-fA-F]{6}$/')` copies in
`admin_panel.php`'s branding form could ask `Background::isValidColor()`, which is
§4x's argument one table over and is #21's to settle; and `AlertMailer::remember()`
still rewrites its recipients cache in place. That one stays because the failure is
not the same shape — `recipients()` reads it line by line and drops anything without
an `@`, so a torn write costs one email rather than every page in the app.

---


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
grep -rn "ensureLockoutColumns\|clearLockout" --include=*.php .  # must be empty of *calls*: both helpers
                                              # are gone. The three lockout ALTERs are gated plan entries
                                              # (§4v) and the clear goes straight to AccountStore. A hit
                                              # outside a comment is pre-auth DDL back on the login page —
                                              # the one piece of it a bot could reach
grep -rn "csrf_token" login.php               # twice: the hidden input the form emits, and csrfOk()
                                              # reading it. The gate must sit ABOVE `new LoginAttempt`,
                                              # or a request with no token can still run somebody's
                                              # failed-attempt counter up to the lockout (§4v)
grep -rEn "\bdate\('Y-m-d" --include=*.php lib/  # must be empty — the word boundary matters, or every
                                              # gmdate() matches too. Every *stored* moment in lib/ is UTC,
                                              # written with gmdate() and read back with a ' UTC' suffix.
                                              # Local wall-clock is not monotonic: the autumn fall-back
                                              # replays an hour, and both the edit lock (§4t) and the login
                                              # lockout (§4v) compare stored moments as absolute. Plain
                                              # date() is fine for *printing* one — `\bdate(` alone finds
                                              # the three that do, and all three are words for a person
grep -rn "closed_at" --include=*.php .        # lib/accounts.php, schema.sql's DDL, the fixture,
                                              # lib/schema.php's ALTER (§4v — it used to be an ungated
                                              # one on every admin-panel load), lib/server_report.php
                                              # naming it as a column that should exist, and ONE render
                                              # in admin_panel.php that prints the date. Everything else
                                              # is a comment. A hit that *decides* something is a second
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
grep -rEn "UPDATE users SET" --include=*.php . # lib/accounts.php (five: closed_at, password_hash, role/
                                              # is_active/email, the lockout clear, and the failure counter
                                              # a refused sign-in writes), admin_panel.php:168 where an admin
                                              # sets a password in one write, plus tools/. **No page may
                                              # appear here but that one**: login.php's two lockout UPDATEs
                                              # moved into the store in §4u, and a `users` write in a page
                                              # beside another write is invariant 22 again
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
grep -rn "edit_type" --include=*.php .        # prose in crud.php and lib/assets.php naming the field that
                                              # used to be here, plus the two self-test checks that read
                                              # crud.php's source for it. No hit may be code: a
                                              # `'edit_type'` string or a `name="edit_type"` input is the
                                              # §4w defect back — the row's type stated by the request,
                                              # and with it which rule an edit has to pass
grep -rn "isValidColor\|DEFAULT_COLOR" --include=*.php .  # lib/displays.php defines both on Background;
                                              # lib/layout_store.php quotes the default in the refusal;
                                              # lib/display_admin.php's cleanColor asks rather than
                                              # restating. A `preg_match` against a hash and hex digits
                                              # anywhere else is a second opinion about what a background
                                              # colour is — and the door holding it will accept values the
                                              # store then declines to write, saying it saved (§4x).
                                              # BrandStyles::cleanColor is a different table's rule
grep -rn "IMAGE_EXTENSIONS\|isAllowedImageRef" --include=*.php .  # lib/assets.php defines both; crud.php
                                              # asks for the add form and for the upload MIME/extension
                                              # check. A literal list of image extensions in any other
                                              # file is a fourth opinion about what an image entry may
                                              # point at — the three that existed disagreed by omission
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
grep -rn "branding_config" --include=*.php .  # lib/branding.php owns the name, and after §4y it is
                                              # the ONLY .php file that spells it — plus tools/ and
                                              # prose. config.php reaches the file through
                                              # BrandingConfig::apply(); login.php, builder.php,
                                              # help.php and admin_panel.php do not reach it at all.
                                              # A page requiring it directly is four copies of the
                                              # defaults growing back; a second *writer* is invariant
                                              # 23 undone, and the file it would half-write is the one
                                              # every page of the app loads
grep -rn "define('BRAND_" --include=*.php .   # the generated branding_config.php, one line of prose in
                                              # lib/branding.php, and the two self-test checks that read
                                              # the page sources for it. No *page* may appear: a page
                                              # declaring one of these names has its own opinion about
                                              # what colour the nav bar is, and it will differ from the
                                              # Admin Panel's the first time somebody changes one
grep -rn "file_put_contents" --include=*.php .  # lib/error_policy.php (the log, appended under LOCK_EX,
                                              # and the state-dir guards), lib/alerts.php (the recipient
                                              # cache and the rate-limit stamps), lib/branding.php's
                                              # putTemp — and tools/. Never a page. A generated file
                                              # something else loads is written to a temporary path and
                                              # renamed over, never opened with O_TRUNC where a reader
                                              # can find it half-done (invariant 24, §4y)
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

One PR per unit of work, cut fresh from `main` each time. Every merge reaches the
sign by hand, so merge order is deployment order and each PR must leave the app
coherent on its own.

The phases were the unit while there were phases. In the event, Phases 1–5 went out
as one PR — `main` had none of them, so there was nothing to restart from and no way
to review Phase 5's lock without the Displays it locks; Phase 6 joined it because the
docs it corrects describe the code in it. That work is merged. The unit now is **one
item from `docs/reviewed-decisions.md`**, and there is no standing working branch:
an earlier version of this section named one, and by the time anybody read it, it was
six merges behind.

**Two sessions can be working at once.** That has already produced two PRs for the
same decision, and two branches each claiming `§4u` — a collision git merges without
a conflict marker, because the two headings are not the same lines. Before opening a
PR that adds a write-up, and again before merging one that has been open a while, run
`php tools/check_doc_numbering.php`. It also runs in CI, deliberately even when the
step before it failed, since a red suite on every branch is what a shared broken base
looks like and merging is what happens next.
