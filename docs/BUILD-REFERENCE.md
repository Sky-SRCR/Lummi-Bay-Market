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
| `server_report.php` | `ServerReport(PDO, server?)` — `runtime()` / `convergence()` / `isConverged()` | What machine this is, and whether the schema actually converged. Reads the database catalogue (through `readSchemaFacts()`, not its own query) and PHP's own configuration, and **no application data at all** — which is why it may name `users`, `displays` and `canvas_elements` without being a second writer. It trusts the catalogue only for a table the read actually covered; anything else falls back to a `SELECT … LIMIT 0`, because a confident wrong "missing" from the one report meant to be trusted is worse than no report. It exists because two things this repo depends on were never observable: the live PHP version (the whole 7.1 rule rests on it) and whether a `schemaTry()` statement landed, which by design fails silently. It takes the request as an argument because the session cookie's `Secure` flag is now decided per request (§4u), and a report that could only describe the request it is running inside could not be checked against the other one. `phpVersionNote()` is public and pure so its three bands can be tested on a machine that is none of them. |
| `error_policy.php` | `ErrorPolicy::install(mode)` / `log` / `fail` / `report` / `noticeFor` / `status` | What happens when something goes wrong: the ini settings, set in code so they travel with the deploy and can be read back; the three handlers; where the log lives and when it rotates; and — the part that needed a module rather than a line — the last thing a request prints, which differs by audience. A Screen gets a self-re-checking kiosk notice, an endpoint gets JSON its caller can parse, a person gets a sentence. `noticeFor()` is pure so all three are testable without a failing server. `report()` is the one for a problem the app survived but an admin should hear about, and it takes a window: a problem that recurs on its own schedule — a schema statement retried on every page load, or every 30 seconds per Screen — has its *log line* throttled too, not only its email, or the record of it buries everything else in a 2 MB file. `firstInWindow()` and `stateFile()` are public for the same reason `report()` needs them: a repeated *attempt to fix* something needs the same restraint as a repeated report of it, and this module is where the state directory is decided. Depends on nothing: no database, no session, no config. |
| `alerts.php` | `AlertMailer(stateDir, siteName)` — `notify` / `remember` / `recipients` | Telling somebody. Both halves are on disk rather than in the database, because the commonest thing to alert about *is* the database: the rate limiter is a stamp file (one email per problem per hour, keyed by kind + file + line) and the recipient list is a cache written whenever an admin opens the admin panel. With nowhere writable it sends nothing at all — a limiter that fails open means one email per Screen per poll. `deliver()` is the single line that reaches `mail()`, separated so the rules can be tested without one. |
| `login_attempt.php` | `LoginAttempt(AccountStore)` — `attempt(username, password, now?)` → `LoginOutcome` | One sign-in, decided: which of six answers it is, and the sentence that goes with each. The rule it exists to make checkable is an **ordering** (ADR-0008, invariant 23) — closed, suspended and locked-out are settled before `password_verify()` runs, so the sentence never varies with the password and a guesser on a suspended account is not told when they have got it right. It also carries ADR-0001's two numbers and the counting they drive, in UTC (§4v): a `locked_until` further out than one window was not written by this code and is not honoured, which is both true of the policy and what stops a row left in the old local-time format from locking somebody out for a shift. **It holds no PDO**: every statement is `AccountStore`'s, which is what stops the file that decides what to say from growing a query that says something else, and leaves the thing under test as the decision rather than a database. `login.php` is the adapter — start a session, or print the sentence. |
| `request_scheme.php` | `RequestScheme::isSecure(array $server): bool` | Whether the *browser's* leg of a request is HTTPS, asked for exactly one reason: whether the session cookie may carry `Secure`. A flat `true` there is not a hardening on an `http://` deployment, it is a correct password landing back on a blank login form for ever, because the browser discards the cookie and nothing anywhere says so. Believes the forwarded proxy headers, deliberately — refusing them costs a real Cloudflare-fronted deploy its `Secure` flag, while believing a forged one costs only the forger their own sign-in. Says false when the request says nothing. Depends on nothing, reads no superglobal. |
| `plain_text.php` | `toPlainText(string): string` | ADR-0002's sanitising, in a file with no session side effects so the store can include it. Seven statements whose **order** is the substance (§4am): breaks are rewritten before the strip, or `strip_tags` takes the line break away with the tag; a `<` that cannot open a tag is escaped before the strip, because `strip_tags` is not a parser and deletes from a `<` to the end of the value; and entities are decoded after it, since a browser sends a typed `<` back as `&lt;`. `PLAIN_TEXT_NOT_A_TAG` is the single answer to "is this markup?" and is used in exactly one place. The cost of the order is that encoded markup lands as literal text, inert only because every renderer draws stored text with `textContent`. **The only caller of `strip_tags()` in the repo** — a label or a preview that wants plain text asks this, or it disagrees with the sign. |
| `branding.php` | `BrandingConfig` — `apply` / `current` / `save` → `BrandingWrite`, plus `path` / `load` and the pure `render` / `parses` | The generated `branding_config.php`: the eight settings it holds, their defaults, and **how a file the whole app requires is replaced while the app is running** (§4y). Nothing writes the live path but one `rename()`, and it is not reached until the replacement has been rendered, parsed, written to a temporary file beside it and read back byte for byte — so a reader gets the whole old file or the whole new one, and every failure leaves the site on exactly what it had and says so. `save()` takes only the settings a form actually edited and applies them over `current()`, which is what the old eight-positional-argument call could not do: each of the two forms passed the other's values back in from page variables. And the read side: `apply()` is one call for what used to be seven lines repeated in `config.php`, `login.php`, `builder.php` and `help.php`, each spelling out the same defaults and two of them guarding the `require` on a different constant from the other two. `config.php` calls it and `auth.php` requires `config.php`, so the eight names exist by the time anything renders; nothing already defined is ever overridden, which is what `config.php` promises about `db_credentials.php`. Defining constants is a global side effect no other module has — the exception is deliberate, because the names are the interface every template already reads. Depends on nothing — no database, no session, no config, because the page that manages this file is also the page that has to work when it is missing. |
| `store_clock.php` | `StoreClock::zone` / `isZone` / `zones` / `pick` / `unreadable` / `apply`, and the two doors `epochOf` / `label` | Which zone a person in the shop reads a time in, and — the half that had gone wrong on its own — **that every stored moment is UTC**. There were three clocks (§4ap): PHP's process zone, which the live host sets to `America/Chicago`; MySQL's session zone, which `CURRENT_TIMESTAMP` used and nothing had ever set, so the same machine's Central; and the store's, in Washington. Those first two agreeing is what made the missing `' UTC'` below cancel out exactly and therefore stay hidden. `epochOf()` is the only place in the repo that calls `strtotime()` (invariant 28) — that `' UTC'` suffix was written out three times and the third copy left it off, so a refused publish named the wrong hour and nothing could see the difference. `label()` converts through a `DateTimeZone` rather than relying on the process default, so it is right in `viewer.php`, which loads neither `config.php` nor `auth.php`, and its zone is a **parameter** with the setting as its default (§4o) because a `define()` cannot be undone and the property worth testing is that the sentence follows the setting. **A fixed offset is not a timezone**: `+08:00` and `PST` both build a valid `DateTimeZone` and are both wrong for half the year, so the accepted set is the identifiers PHP *lists* — a name is the only thing that knows when daylight saving starts. `unreadable()` names a stored value it will not use rather than substituting in silence (#21). Depends on nothing but `BrandingConfig`, which owns the file the setting lives in, and does not open that file a second way. |
| `color.php` | `Color::read` / `isColor` / `describe` | What a colour is — `#rrggbb`, and nothing else. One rule, because it used to be written out four times and the four copies disagreed about what to do when a value failed it: `DisplayAdmin` substituted `#1a1a2e`, `BrandStyles` `#ffffff`, the Branding form whatever was already saved, and the Builder's `rgbToHex()` `#000000`. All four then reported success, so "saved" meant four different things and none of them meant "what you typed" (#21, #41). **It never picks a colour.** `read()` answers the colour or `''` and the caller decides what an empty answer means for it — a form refuses and names the field, the publish path refuses and names the block, a caller with a genuine default applies it visibly at the call site. Blank is deliberately *not* a colour: "nothing supplied" and "supplied and unreadable" are different answers and collapsing them is the defect. Not a normaliser either — no trimming, no `#fff` expansion, no `rgb()` — the accepted set is exactly what the three old regexes shared, so nothing that used to be storable stopped being storable. Pure, and depends on nothing. |
| `display_request.php` | `DisplayRequest::forViewing/forEditing(...)` → `DisplayResolution` | Which Display an HTTP request means and whether the account asking may have it, the ADR-0003 notice wording per failure case, and the editing entry rule. The one place grants are enforced. |
| `http_reply.php` | `HttpReply::json(payload[, code])` / `noStore` / `jsValue`, and the pure `reply` / `codeFor` / `codeForPayload` / `codeForResolution` / `cacheHeaders` behind them | The envelope every answer leaves in: the status line, the caching rules, and the bytes of the body. **The encode**, because `json_encode` returns `false` and `echo false` prints nothing — one bad byte sent a zero-length 200 and a sign kept its layout for good (#26). Malformed UTF-8 is repaired and reported; anything else becomes a real 500 with a body that is known to encode, so no JSON request is ever answered with something that is not JSON. **The code**, derived from the payload's own `reason` rather than chosen beside it, because twenty-odd call sites cannot be kept in step by hand and a code that disagrees with a reason disagrees silently (#28). **The caching**, `no-store` everywhere, from one place — needed most by the 404s this module introduced, which are heuristically cacheable where the unlabelled 200 they replaced was not. `jsValue()` is the same encode for a value printed into a page's own `<script>`, where a `false` emits `var X = ;` and takes the whole block down. Pure functions under thin senders, the way `ErrorPolicy::noticeFor()` is, so all of it is testable where `header()` does nothing. Depends only on `ErrorPolicy`, for the sentence. |

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
   (ADR-0006, ADR-0007). Nothing published can be taken back — the Builder's Undo
   (ADR-0010) stops at this button — so refusal is the whole safety net. Two
   things can make a publish that refusal: a stamp that has moved, and an edit
   lock that has. Both are checked inside `LayoutStore::publish()`, under the same
   row lock, so neither can be talked out of by a client. And a body that did not
   *decode* is not an empty layout: `PublishRequest::fromPostedJson()` refuses it,
   because publishing an empty layout deletes every element and the old
   `json_decode(...) ?: []` read an unreadable request as exactly that.
   **The other half is the client's, because the server cannot do it.** A second
   publish on an already-spent stamp is indistinguishable, from the server, from a
   colleague's — so it is refused, correctly, and a double-click therefore earned a
   green success and a "somebody else changed this sign, reload" alert at once, with
   the alert being the one acted on and reloading being what discards unpublished
   work. Only the tab that sent both requests can tell a duplicate from a conflict.
   `publishInFlight` in `builder.php` is where it does, and anything else added that
   writes on a stamp — a save button, a keyboard shortcut, an autosave — needs the
   same guard or it manufactures the same false conflict.
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
25. **What a stored row *is* comes from the row, never from the request editing it.**
    `assets.content` means words a sign prints or a path a sign fetches, and only
    `assets.type` says which — so the type decides whether markup is stripped, whether
    an extension is checked, and whether a file may be uploaded into the row at all.
    The Library's edit form used to send the type back in a hidden field, which let a
    POST pick the rules applied to a row it was not describing: markup into a text row,
    an `.svg` into an image row, an uploaded path into a row a Screen prints as words.
    `AssetLibrary::saveEdit()` therefore takes no type argument — it reads the row — and
    the raw write is private behind it. The general rule, for anything added later:
    an identifier may come from a form, because the record is looked up by it and
    disagreement is impossible; a *property of the record* may not, because nothing
    checks it. Invariant 12 is this same rule one level up, where a tag names a
    Display and the id proves which one — there, the pair is sent precisely so they
    can be caught disagreeing.
26. **In the Builder, a size is a canvas measurement — screen pixels are divided by
    `ZOOM` once, at the edge, and never compared to anything afterwards.** The canvas
    is CSS-scaled, so a pointer that moves 100 screen pixels moves 200 canvas pixels at
    50% zoom, and the sign is laid out in the second of those. `handleMove`,
    `handleResize` and `getCanvasDropCenter` do the divide; everything downstream —
    limits, clamps, the readout, the inspector's W and H — is in canvas pixels and says
    so. The rule earns its place because the one measurement that skipped the divide
    did not look like a measurement: `interact.modifiers.restrictSize({min:{width:100}})`
    reads as "sections are at least 100 wide" and meant "at least 100 *on this screen,
    at this zoom*", so the smallest a section could be dragged to was 200 canvas px
    zoomed out and 50 zoomed in. A limit interact.js enforces is a limit in its units;
    ours are enforced after the divide, in `handleResize`, from one table (`BLOCK_MIN`)
    that `applyDim()` reads too — a floor a drag stops at and a floor a typed number
    stops at must be the same floor. The zoom itself has the mirror-image rule: its
    floor is `min(ZOOM_MIN, fitZoom())`, because a floor that a canvas cannot fit inside
    is a Fit button that does not fit.
27. **A function that changes the Builder's canvas ends by committing an undo step
    (ADR-0010).** Undo is a stack of canvas snapshots in the editor's tab, and it is
    the one place in this app where something can be taken back — so what it can take
    back is exactly what somebody remembered to report. The rule has two halves,
    because the alternative to each is a defect that shows up as an Undo that lands
    somewhere nobody was ever looking at. *A change the code makes on its own* —
    create, delete, a finished drag or resize (interact.js's `end`, never
    `handleMove`), an upload's success callback, a modal's Save, a text block's
    `blur` — commits at the end of the function that made it. *A control the person
    is operating* commits on the element's `onchange`, never on `oninput`, and the
    function behind it commits nothing: `updateStyle()` is called from both a select's
    `onchange` and a colour picker's `oninput`, and a commit inside it would make
    dragging the swatch forty steps. The safety margin is that `commitUndoStep()`
    *measures* rather than trusting the call — it compares a fresh snapshot against
    the last one and keeps nothing if they match — so an operation that changed
    nothing never spends a step, and a call site somebody forgets folds its change
    into the next step rather than dropping it. Two matching rules hold the rest up:
    a restore rebuilds through `renderSection()`/`renderBlock()`, the same pair
    `loadLayout()` uses, so a block type added later is restorable the day it is
    added; and `serializeCanvas()` has exactly two callers, Publish and the snapshot,
    so what Undo believes a block is and what reaches the sign cannot drift apart.
28. **A stored moment is UTC, read in one place, and shown in the store's zone.**
    Three rules that are one rule, because separating them is how #44 survived. *Written*
    in UTC — `gmdate()`, never `date()`, because local wall-clock is not monotonic and the
    autumn fall-back replays an hour (§4t, §4v); `displays.last_published_at` was the last
    exception and asked MySQL for `CURRENT_TIMESTAMP`, whose value is MySQL's session zone
    and therefore a third clock. *Read* by `StoreClock::epochOf()` and nothing else: a bare
    `strtotime()` on a `Y-m-d H:i:s` uses the process zone, so the `' UTC'` suffix is the
    whole rule, and it was written out three times with the third copy missing it — the two
    that were right could not show that the third was wrong. *Shown* through
    `StoreClock::label()`, in the zone the `STORE_TIMEZONE` setting names, which is a
    parameter with the setting as its default so the sentence can be checked against a zone
    this process is not in. The reason all three belong in one invariant is that each is
    invisible without the others: a stamp stored in the wrong frame and a stamp read in the
    wrong frame produce the same sentence, and on a host where the frames happen to agree
    they produce the *right* sentence, which is how the missing suffix lived for a year. The
    process default is set once, in `config.php`, so a bare `date()` added to a page later
    agrees with the door instead of being two hours from it — the live host sets
    `America/Chicago` (§4ap) — and it is deliberately not what the door depends on, because
    `viewer.php` loads neither `config.php` nor `auth.php`.
    Two greps hold it: `strtotime(` outside `lib/store_clock.php`, and
    `date_default_timezone_set(` outside it.
29. **An account that has been assigned no sign writes nothing shared** (#33). Almost
    every write in this app is scoped to a Display, which is what makes the resolution
    seam (invariant 8) the only place access has to be enforced. Exactly two are not:
    the **asset library**, one pool behind every sign, and **`uploads/`**, one folder
    behind every library entry. Nothing resolves a Display for either, so nothing was
    checking anything — a `basic` account with no grant could fill the library every
    sign draws from and leave files on the server for good, having just been told by
    the Builder that no display was assigned to it. `Actor::holdsASign()` is the one
    predicate and `Actor::NO_SIGN_REFUSAL` the one sentence; `crud.php`'s add form and
    `api.php`'s `upload_file` are the two doors, and both ask **before**
    `move_uploaded_file()`, which cannot be rolled back — a gate below it leaves
    precisely what it exists to prevent, minus the row. It is the grant axis alone and
    not `openable()`: a sign switched off for the afternoon is still a sign somebody was
    given, and the refusal's own wording would be a lie to its holder. The doors also
    stop *drawing* what they will refuse, which is invariant 3's rule, and that is not
    the check — a POST need not come from a form this app rendered. Anything added later
    that writes something every sign shares asks the same question; anything scoped to a
    Display must not, because the seam already answered it and a second opinion is
    invariant 8's warning.
30. **A check ships having been seen to fail** (#50). Not "a check was added" — a check
    that was run against the unfixed code, or against a deliberate break of the line it
    covers, and observed to go red. The distinction is the whole of decision #50, because
    the two are indistinguishable from the outside: both read as one more `ok` line, both
    raise the count anchor, and only one of them will ever tell anybody anything. This
    suite has shipped the other kind more than once — a check asserting what PHP 8
    guarantees rather than what a module decides (§4aa), a `setupInteract()` call after a
    restore that could not fail because interact.js binds by selector (§4an), a
    `file_put_contents` grep whose stated answer of "exactly one hit" had been
    unreachable since the day it landed (§5), and an "absent setting is not something to
    report" that ran in a process where the setting was present and usable (§4aq). None
    of those was carelessness; each was written by somebody reasoning correctly about
    code and not measuring the check. `tools/mutate.php` is how it is measured now:
    break the line, run the suite, and read whether anything noticed —
    **`php tools/mutate.php <file>`**, one file at a time. A surviving mutant is either a
    check to write or a reason to write down, and writing the reason down is a real
    answer: §4am's `flock(LOCK_UN)` survives because the runtime would release the lock
    anyway, which is the docblock being right. What is *not* an answer is deleting the
    line because no test covers it — three of #49's survivors were load-bearing. The
    counterpart rule is that a kill has grades, and only one of them is coverage: a
    mutant that dies because a PHP warning appeared, or because the count anchor moved,
    is the harness noticing something moved rather than a check knowing what the line was
    for.
31. **No file uses PHP newer than the floor, and `php -l` is not what decides that**
    (#51, §4ar). The floor is 8.2, observed twice on 2026-08-11. The container these sessions
    run in is 8.4, so the first line of §5's gate — `php -l` on every touched file —
    reports "No syntax errors detected" on a file that cannot parse on the live host.
    Demonstrated rather than argued: a class with a typed constant and a property hook
    lints clean here, and both are parse errors at 8.2. A parse error takes the whole
    file down, so what reaches the shop is a blank board, not a message anybody reads.
    `tools/check_invariants.php` decides it instead, over real tokens and not text —
    the hand sweep that cleared the tree produced two false positives in a one-line
    grep, an HTML `readonly` attribute and a JavaScript `.match(`, because the names
    involved are ordinary English. Seven constructs are covered: typed class constants
    and `#[\Override]` (8.3), property hooks, asymmetric visibility, `#[\Deprecated]`
    and bare-chained `new` (8.4), plus twenty library functions the floor does not have.
    **It is a denylist and says so on every run**, because a denylist that matches
    nothing is indistinguishable from a clean tree — it cannot promise the tree is
    clean, only that what it names would have reached the sign. Two things about it are
    load-bearing and were both found by its own fixtures rather than by reasoning: the
    floor is *parsed out of* `ServerReport::ASSUMED_PHP` rather than restated, because a
    checker with its own copy keeps passing after somebody lowers the floor; and
    asymmetric visibility is matched in **both** lexings, since 8.4 reads `private(set)`
    as one token and 8.2 as four — matching only the 8.4 shape would have gone quietly
    blind in CI, which is the machine pinned to the floor, and would have looked exactly
    like a pass.
32. **Publish never writes what a Brand paints** (ADR-0011). A branded text block's
    typography lives in `block_styles`; both renderers read it from there and neither
    ever looks at the element's own six `font_*` columns. The Builder nevertheless
    paints the standard onto the node's inline style — it has to, or the block would
    not look like what it will become — and `serializeBlock()` read that inline style
    straight back out, so **every publish since those columns existed baked the shared
    standard into every branded element's own row.** Invisible while one set of
    standards reached every sign, because the values were identical everywhere and
    nothing read them. Two live faults once several Brands exist: a block whose subtype
    is changed to `free` a month later inherits whichever Brand was selected at its last
    publish, from a venue it may never have belonged to, with nothing saying so; and the
    undo snapshot — which serializes through the same function — moves when a Brand is
    merely *picked*, so the next real edit pushes a step recording a difference nobody
    made. That second one is invariant 27 the other way round, and it is why this
    landed on its own and before the feature that would activate it.
    The rule holds at both ends and they are not the same rule twice. The browser stops
    *sending* the six, which is what makes the snapshot stable — a server cannot fix an
    undo history that never leaves the tab. `LayoutStore` stops *storing* them
    whatever it is sent, which is what makes the row right for a Builder tab loaded
    before this landed; that is an ordinary thing to happen on the afternoon of a
    deploy, so it is ignored rather than refused. `BrandStyles::paints()` is the one
    place the question is asked, because the two ends disagreeing is worse than either
    being wrong: keeping the Brand's values is the fossil, and stripping values a
    renderer is still going to read is a blank price on a wall. That second case is
    real — a half-seeded install has no row for a type, both renderers fall back to the
    element's own columns, and `paints()` answers `false` for exactly that reason.
    Both of `LayoutStore`'s writers ask it. `copyLayout()` is the one that looks like an
    exception and is not: a copy is a new row, and copying a fossil faithfully puts it
    on a sign that never had one.
    Two more doors joined it with v2 step 4, and both are the same question asked
    somewhere new. On the server, a publish that *changes* the Brand writes its rows from
    a **re-read** Display: `paints()` has to be asked with the standards of the Brand the
    rows will be read under, and the Display the method was handed is still wearing the
    one before. In the browser, a Brand switch repaints by way of `snapshotCanvas()` and
    `restoreCanvas()` rather than by re-applying styles over the existing nodes — which
    is not a convenience but the only thing that can work: this invariant is why a
    branded block's own six fields are *not* on the node, so `applyTextStyles()` needs an
    element to fall back to and only the serializer can produce one. Both ends therefore
    keep asking exactly once, in the same two functions, which is the property this rule
    has always been about.
33. **Nothing outside `lib/brands.php` writes `brands`** (ADR-0011), and no page decides
    what a Brand *is*. A Brand is the identity several signs read their typography,
    palette, logo and default canvas background from, so a second writer is a venue
    repainted by a page that did not know it was the one deciding — and repainted
    within thirty seconds, on every screen wearing it, with no publish and no undo.
    `BrandStore` owns every statement against the table; `lib/schema.php` creates it and
    seeds the first Brand, the same exception `displays` has for the same reason.
    `BrandAdmin` is deliberately **not** on the list, and that is the sharper half of
    the rule: creating a Brand spans two tables — the row, and the six `block_styles`
    rows without which its whole typography form is an `UPDATE` that matches nothing and
    reports success — so it holds the transaction, composes `BrandStore` with
    `BrandStyles`, and writes no SQL of its own. If it ever appears in this rule's
    output it has started reaching past the module that owns the table, which is the
    shape `DisplayAdmin`, `AccountAdmin` and `PasswordResetCompletion` all avoid.
    Two consequences worth stating because neither is obvious from the table. **A Brand
    in use is never reassigned**, only refused, naming the Displays — moving three
    boards in a restaurant onto some other identity on one click is the merge invariant 5
    exists to prevent, and `displays.brand_id`'s foreign key carries no `ON DELETE`
    clause so the database says the same thing to anything reaching it another way. And
    **the lock refusal narrows rather than widens**: Brand Standards used to be refused
    while *anyone* was editing *anything*, which was airtight when one table reached
    every sign and is simply false now, so it asks
    `DisplayStore::editedByAnyoneElseUsingBrand()` instead. That is the one place this
    work makes the app less restrictive, and it is a rule getting *more* correct rather
    than being relaxed.
34. **Nothing outside `lib/workspace_themes.php` writes `workspace_themes`, and no chrome
    role is drawn on the canvas** (v2 roadmap decisions 10 and 11). Two halves of one
    rule, because the second is the only thing keeping the first from being a table like
    any other. A Workspace Theme is what an *employee's screen* is painted in and reaches
    no sign — the other of `CONTEXT.md`'s two nouns, never one word with Brand — so its
    danger is not what it writes but where it is read: what a canvas shows is what a sign
    shows, and a theme colour reaching a block would make the Builder a preview of
    something no Screen renders. Invisible, too, because the person who set the theme is
    the person looking at the canvas. So every role is drawn as a `var(--…)` custom
    property, `tools/check_invariants.php` refuses one inside any rule that paints the
    canvas, and it refuses `--selection` — the outline and handles, which *are* a theme's
    (decision 10) — anywhere else, since a role that may only be used in one place is a
    fact only if the other places are checked too.
    The write half has the usual exceptions and one that is not usual. `lib/schema.php`
    creates the table, as it does for `brands` and `displays`. **`AccountStore` writes the
    *choice*** — `users.workspace_theme_id` — and that is not a second writer of this
    table but the correct home for a fact about an account; this module reads it back,
    joined, because the read is on the path every signed-in page load takes.
    And there is deliberately **no use-case module**: a theme is one row with no second
    table to be half of, which is exactly what `BrandAdmin` exists for and why the
    absence is worth stating rather than looking like an oversight.
    Two consequences. **Resolution happens in one place** — `SiteChrome::stored()` layers
    a worn theme over `branding_config.php` over the documented defaults, in that order,
    and the reads that are about the *store's* colours rather than this reader's go
    through `configColor()`; without that split the Branding form shows an admin their own
    theme as "what is there now" and saves it over the shop's. **And a theme somebody is
    wearing is refused rather than reassigned**, naming them, with `users_ibfk_1` carrying
    no `ON DELETE` clause so the database says the same thing to anything reaching it
    another way. The same shape as a Brand in use, for the same reason.
35. **Every read of the machine is one somebody named, and every branch behind one has a
    seam that takes the value** (§4bg). Five files in `lib/` may touch `ini_get`,
    `$_SERVER`, `PHP_VERSION`, `PHP_SAPI`, `phpversion()`, `session_get_cookie_params()` or
    the engine's own `ATTR_SERVER_VERSION` / `ATTR_DRIVER_NAME` — `server_report.php`,
    `error_policy.php`, `upload_limits.php`, `alerts.php` and `displays.php` — and
    `tools/check_invariants.php` fails when a sixth appears. Not a ban: this app reports on
    its own host, so somebody has to read it. The rule is that a value taken from the
    machine has exactly *one* value on every machine that runs the tests, and whatever the
    shop's server holds everywhere else — so a branch chosen by one is a branch the suite
    asserts in the single configuration no shop is running, in green, for as long as it
    exists. `ServerReport` had already applied the fix three times and said why each time
    (`phpVersionNote($id)`, `storeZoneNoteFor($stored)`, `UploadLimit::smallestOf($values)`
    — "unreachable on whatever machine happens to run the test"); the four places it had
    not were exactly the four with no checks, one of which — `ErrorPolicy::status()`, the
    whole Settings-tab readout of what happens when something breaks — had none at all.
    The seam is the half that reaches what no flag can produce (an unset `date.timezone`
    cannot be made with `php -d`; PHP rejects the empty value at startup). The arms in
    `tools/selftest_installed.php` are the half that proves the real reads still work, and
    they refuse an arm set to what this machine already holds, because that one would agree
    with the plain run and say so in green.
36. **No parameter is implicitly nullable** (§4bh). `?Type $x = null`, never
    `Type $x = null` — understood back to 7.1, deprecated from 8.4. The sibling of 31 and
    the opposite direction: that one refuses syntax the shop's PHP cannot *parse*, and its
    cost is a blank sign; this one refuses syntax that parses everywhere and whose cost is a
    line in the error log on **every request that compiles the file**. Separate rules
    because what to do about each differs. `SiteChrome::wear()` was one, called on every
    signed-in page load, and three things could not see it: `php -l` is clean on both
    spellings; the deprecation fires when a file is *compiled*, so it precedes any handler
    the suite installs; and this container's `error_reporting` excludes `E_DEPRECATED`, so
    the suite runs green on 8.4 while the notice is emitted. A CI leg for a newer PHP does
    not close this — it would go green too. `tools/check_invariants.php` reads real tokens
    and **only inside parameter lists**, because a scan of every `$x = null` reports
    `private static $bytes = null;`, which `UploadLimit` and `ServerReport` both have.
37. **No check writes a value the engine the shop runs would refuse** (§4bi). A suite that
    proves a reader degrades gracefully has to get the bad value *into* the column first,
    and SQLite takes anything: it stored `bg_type = 'nonsense'` in an ENUM, `'nonsense'` in
    a DATETIME and eight characters in a `VARCHAR(7)`, and the check that read each one
    back passed. On MySQL all three are errors under the strict mode that has been the
    default since 5.7 — a thrown `PDOException`, mid-run, which ends the job and takes the
    rehearsal step under it down as well. **That is what had CI's MySQL half dead for eight
    days**, over four writes, while the SQLite half stayed green and every local gate agreed
    with it. So the rule is not "guard the write": it is that a value the column cannot hold
    does not belong in a column. Hand it to the reader as a row — both readers take one —
    and where the point is genuinely a stored value, make it one the column can store: a
    colour nobody can read has to **fit** `VARCHAR(7)` before anybody can be shown the wrong
    thing by it. `tools/check_invariants.php` reads `schema.sql` for the four types that can
    refuse a literal and matches ENUM members **case-insensitively**, because MySQL does —
    `role = 'Admin'` has always been accepted and is not what this is about.

    The rule has a second half (§4bj), and it is the half that cost the run once the
    literals were fixed: **no check sends SQLite-only SQL down a connection the MySQL leg
    may be holding.** A refused value is one statement failing; the wrong *dialect* is a
    fatal, thrown where no check is looking, so the suite ends without reporting and the
    rehearsal step never starts. `AUTOINCREMENT`, a trigger body, `PRAGMA`,
    `sqlite_master`, `INSERT OR REPLACE` — and `TEXT … DEFAULT`, which is valid SQLite and
    rejected by InnoDB, the same fatal by a subtler route. Unlike the literals, this one
    can read *which* connection a statement is on, because the suite names them: a handle
    from `newSqliteTestDb()` is believed, one from `newTestDb()` is whichever engine the
    run chose. Accepting a write and storing what was written are also different
    questions, and only the second is one a check can assert — the same `role = 'Admin'`
    MySQL accepts is stored as `admin`, so the state that check wanted has to be handed to
    the reader as a row too.

    What neither half can answer is whether the arm *finishes*. Only the run says that,
    and a dead gate hides how much is still wrong behind it: fixing §4bi's four took the
    leg from ~100 checks to 1383, where four more of the same class were waiting.

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
- **PHP 8.2 is the floor** (#51, §4k) — stated by the store owner 2026-08-10 and
  **observed twice on 2026-08-11** (HANDOFF §7): the runtime card reports 8.2.33, and
  cPanel → MultiPHP Manager shows `srcresort.com` — the domain this app is served from —
  pinned explicitly to `ea-php82`, against a **system default of PHP 8.3**. Two
  independent observations, one runtime and one configuration. It was twice recorded as
  8.2 before either, on evidence that could not exist: Settings → This Server ships with
  the undeployed build, #46's probe found `lib/` answering 404 live, and Cloudflare hides
  the version from every response header. The deploy-day confirmation step is now
  discharged; CI's 8.2 pin enforces the target rather than accepting everything the target
  forbids. Because the pin is explicit rather than `inherit`, the floor does not follow a
  host-wide upgrade, and clearing it would move to 8.3 — upward, which an 8.2 floor
  survives. The one route below it is a person selecting an older version for this domain,
  which is what `ServerReport::phpVersionNote()` announces.
  Modern syntax is permitted. As of today **no file uses a typed property, constructor
  promotion, `readonly`, `match` or an arrow function**, which is what keeps the floor
  one line to lower again — check that before assuming it is still true. Spend it
  deliberately: guessing low only forwent syntax, where a floor that turns out wrong is
  a parse error, and a parse error in a file a Screen loads is a blank sign in the shop.
  The two 7.1-era fallbacks — `.htaccess`'s `mod_php7` blocks and `auth.php`'s pre-7.3
  session-cookie form — stay, because they are free and what they prevent is silent.
  **This container has PHP 8.4, and that is a hole in §5 rather than a footnote.** The
  line above used to read "PHP 8.4 for `php -l` only" and stop there — the fact was
  recorded and its consequence was never drawn, which is its own kind of defect. The
  consequence: `php -l`, the first step of the pre-push gate, cannot detect a construct
  introduced in 8.3 or 8.4. Those lint clean here and are a **parse error on the live
  host**, so the gate is blind to precisely the failure the floor exists to prevent, and
  it fails in the direction that blanks a sign rather than printing a message. Checked by
  hand on 2026-08-11 — no `json_validate()`, typed class constant, `#[\Override]`,
  property hook or asymmetric visibility anywhere in the tree. By hand, because nothing
  mechanical enforces it: writing that check is unclaimed work, and until it exists,
  "the gate passed" is not evidence the floor was respected.
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
- ~~**Deployment step 3 has no do-not-overwrite list**~~ **Fixed** — see §4z and
  `docs/DEPLOY-SKIP.md`. The only finding in the audit whose whole surface is outside
  the code: every fact was already in this repo, in a file the person doing the upload
  was not reading.

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
sentence — the rule as it stood until the end of this section — shaped every file
here. It was also the only rule in the project with no way to check it, and the one
real violation it ever caught was not syntax at
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
read-only, nothing to submit. It reports the PHP and MySQL versions, three time
zones (§4ap — the store's, which is a setting on this same tab and is what every time
on every page is drawn in; PHP's own, which no longer decides anything; and the
database's, which is where an account's creation date comes from and which no screen
showed at all until #44), whether errors are shown to visitors or written to a log, and
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

**Answered — 8.2 — on the third attempt, and the first two are worth keeping in view
because they are the same wrong answer arrived at twice.** A branch recorded 8.2 and
raised the rule to match, citing Settings → This Server. That cannot have been the
source: the screen ships with the multi-display build, and #46's probe of the live
site found `lib/` answering 404, so the page does not run there — and Cloudflare
fronts the site, so no response header carries the version either. The claim was
withdrawn and the rule put back to 7.1-compatible. That withdrawal was then itself
incomplete for one merge: the four top-level docs were reverted while
`lib/server_report.php`, `auth.php`, `lib/markup.php`, `lib/layout_store.php`,
`admin_panel.php` and §5 still stated 8.2 as fact, so the repo asserted both floors at
once — and `ASSUMED_PHP` was the one an admin would have read off a screen.

**The store owner then stated it: PHP 8.2, 2026-08-10.** That is the source the first
attempt never had. It is not a reading this repo can take for itself — nothing here
observes the version, and that is exactly why it is written down with a date and a
person rather than left as a fact with no provenance. `ServerReport`'s card is what
will confirm it the moment the build is deployed, and what will contradict it if the
host is ever moved or downgraded.

So **the floor is 8.2 and the rule no longer says "unverified"**. That is a real
loosening: modern syntax is permitted, and CI's 8.2 pin now enforces the target it was
always pinned to rather than accepting everything that target forbids. It is also
reversible in one line for as long as nothing uses a later construct — which is true
today, and stops being true the first time somebody writes a `match`.

The direction of the risk flipped with the answer, and that is the thing to hold on to.
While the version was a guess, guessing *low* only forwent syntax; the conservative
choice and the cheap choice were the same one. With a floor declared, being wrong means
a parse error, and a parse error in a file a Screen loads is a blank sign in a shop
rather than a stack trace anybody reads. So the floor is paired with making a wrong
floor *say so* — see the last item below.

Three things did *not* change with the answer, each for its own reason:

- **`auth.php` keeps the pre-7.3 branch.** The rule is about what may be written;
  the branch is about what happens if the host moves. It costs one `if` and it is
  the only thing standing between a different server and a sign-in cookie with no
  HttpOnly, Secure or SameSite. `.htaccess`'s `mod_php7` blocks stay for the same
  reason, next to the `mod_php8` ones that were already there.
- **No file was rewritten to use 8.x syntax.** Nothing gains from `match` or a typed
  property today, and a sweep like that is a large diff through code that has just
  been changed, on an app with no undo and no CI running.
- **`ServerReport` reports the version, and now points the other way.** `ASSUMED_PHP`
  was the *oldest* PHP this might have to run on, so anything newer was merely
  wasteful. It is now the floor the code is written to, and an *older* server is the
  failure — which is what makes the card the alarm for the paragraph above rather
  than routine commentary. `phpVersionNote()` says so in three bands, and is pure so
  all three are testable on a machine that is none of them:
  8.2 and up says nothing; below 8.2 warns that syntax this repo is now allowed to use
  may not parse, while noting the cookie is still hardened by the modern call; below
  7.3 says which session-cookie form `auth.php` has actually branched to, because
  there the version stops being a rule and becomes behaviour. Silent on the expected
  case on purpose — a note an admin reads every time is a note they learn to skip,
  and skipping it is how the two bands that matter get missed.

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

- On UTC — which is what PHP falls back to when `date.timezone` is unset — nothing happens
  at all. Old rows and new rows are the same string. *(When this was written §4k said the
  live value was unknown. It is known now and it is not this case: the host sets
  `America/Chicago`, observed 2026-08-11 — see §4ap. So the bullet that actually applied to
  this migration is the next one.)*
- **West** of UTC, which is where the store is *and where the host turned out to be* —
  Central, so five or six hours rather than the seven or eight this bullet guessed at from
  the store's own zone — an old stamp reads that much *earlier*, so any lockout in force at
  the moment of the deploy is released. Bounded and self-correcting either way, and the
  bound is what carried the reasoning rather than the number: `locked_until` is never more
  than fifteen minutes ahead in the first place, so every affected row is gone within
  fifteen minutes of the deploy, and the failure counter beside it is untouched.
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

### 4z. The deploy step that undid the two things it could not see

Step 3 of *"Before this reaches the sign"* said **upload the files**, and named only
what must go *up* — `lib/` and `tools/` with their `.htaccess`. Nothing said what must
not. Every fact needed was already in this repo: `README` §3 said delete `setup.php`
from the server, HANDOFF §5 says `branding_config.php` is generated there. **The
information existed and the step that needed it did not carry it** — which is the
defect, not a missing fact. A checklist read with one hand on an FTP client does not
send anybody to a third file.

So a re-upload restored the first-admin form and reverted the branding, and both are
invisible: the app comes up looking exactly right.

**The cost is not where it looks.** Reverting the nav colours is obvious and
cosmetic. `branding_config.php` also holds `MAIL_FROM`, which the repo's copy does not
define at all, so `config.php` falls back to `noreply@yourdomain.com` — a domain this
host does not own. That address is the `From:` on password-reset codes
(`reset_password.php`) and on every schema and error alert (`lib/alerts.php`). Both
then get dropped as spam, so the first symptom is somebody unable to reset a password
weeks later, and **the alert that would have explained it is silenced by the same
line**. `setup.php` is the same shape: it self-disables while it can count users, so
restoring it looks harmless — until the app is ever pointed at an empty or freshly
restored database, when it is a public *make yourself an admin* form again, and
nothing in `.htaccess` blocks it.

**Naming only those two would have been the same defect.** They are the two that bit;
the list is the class. [`docs/DEPLOY-SKIP.md`](DEPLOY-SKIP.md) is in four groups
because the *kind* of mistake differs and so does the recovery: never overwrite
(the server's copy is the authority — `branding_config.php`); never upload (`setup.php`,
`.git/`, the `.md` files); never delete (`uploads/`, the log folder, the credentials
file — these are in **no** backup); and one *diff it first* — the root `.htaccess`
must go up, but a hand-raised `upload_max_filesize` lives only on the live copy and
reverts silently.

The worst of them cannot be caused by an upload at all. `uploads/` is git-ignored, so
no overwrite can touch it — only a client set to *mirror* rather than *merge*, which
deletes every photograph and video on every sign, from a folder nothing backs up. That
is why the instruction is a mode ("file-by-file, or folder overwrite") and not a file
list: no list can protect a folder whose absence from the repo is the point.

**Nothing here is testable, so it is guarded three other ways.** No assertion in this
repo can see the server's filesystem, and a list that only a person can follow rots
the moment the code moves. So:

- **An `.htaccess` backstop for the one class where forgetting is silently
  exploitable.** Root `.htaccess` now denies `.md` alongside `.sql`. Uploading
  `HANDOFF.md` would publish the live database name, the credentials path and the log
  locations to anyone who guessed the URL; every other skipped file is either already
  denied (`schema.sql`, `lib/`, `tools/`) or harmless. The rule is belt-and-braces — it
  is not a reason to upload docs. It also **reverses the instinct on one file**: the
  backstop lives in the root `.htaccess`, so that file must be *overwritten* on every
  deploy, and group D's "look at the live one first" means read-then-replace, never
  read-instead-of-replace. A list that pointed two ways on the file every page depends
  on would be this same defect in miniature, so the precedence is written down.
- **Four checks afterwards, on screens the app already has.** Settings → This Server
  answers A and D (the site name, the upload limit); Settings → Errors and Alerts
  answers C (*"Nowhere to write"* means the log folder was deleted, and until it is
  fixed nothing that goes wrong is recorded); `setup.php` must 404; the brand logo
  still rendering is the cheapest proof `uploads/` survived. Each check maps to a
  group, so a failure names the mistake.
- **A grep, so the list cannot go stale in silence.** `file_put_contents` at the repo
  root is exactly one hit — `admin_panel.php`'s branding writer. That is the whole
  membership test for group A: a repo-tracked file the running app rewrites. A second
  root-level hit is a second file an upload would revert, and it belongs on the list
  in the same commit.

**Then the live server was asked, and the framing was wrong.** The audit said a
re-upload *restored* `setup.php`. It answered **200** — *"Setup is complete. This page
is disabled."* — so it had never been deleted, and the rule about not re-uploading it
had nothing to be a rule about yet. The rest of group B is the opposite: `HANDOFF.md`,
`README.md`, `CLAUDE.md`, `docs/` and `.git/config` all answer 404, so the `.md` deny
guards a hypothesis rather than a live exposure. `schema.sql` answering 403 is the
useful one — it proves the live `.htaccess` is roughly the repo's and that
`FilesMatch` + `Order allow,deny` parse on this host, which is what makes the new
block low-risk on a server with no staging. That table is recorded in DEPLOY-SKIP.md
with its date, because a list written from the repo's beliefs about a server is how
this defect happened the first time.

**And one entry earned its way off the list by deleting itself.** `setup.php` was
removed from the live server by hand, which closed the finding but not the class: the
next upload would put it back, and "delete this afterwards" is a job nobody is
assigned — the reason it survived the original install by months. So it now calls
`removeSelf()` at both moments it becomes dead weight: after a successful admin
`INSERT`, and on the first request that finds it already disabled. A rule a person has
to follow became a file that leaves on its own, which is the shape every other fix in
this section wanted and could not have.

Three details are the whole design:

- **It reads the answer back from the filesystem**, not from `unlink()`. "Still there"
  is the only outcome that needs acting on, and a write that fails while printing
  success is the defect this document has spent twenty sections unpicking — the page
  would have claimed to be gone while still serving an admin-creation form.
- **A forbidden delete is expected, not alerted on.** Some hosts will refuse it. An
  `ErrorPolicy::report()` here would email an admin every hour forever and be triggered
  by any passing bot, so the page says *"It could not delete itself"* and that sentence
  is the thing to act on. This is invariant 20's rule applied to decline a caller
  rather than to add one.
- **It goes only when it can tell it is finished.** An unreachable database or a
  missing `users` table throws before either branch, and the file correctly stays.

`removeSelf()` has **no automated check**, and the reason is worth stating rather than
hiding: it is a page script that runs on include and needs a live database, so
`selftest_layout.php` cannot reach it, and extracting it into `lib/` would mean a
module whose entire job is deleting one named file — `lib/` is for data access. It was
verified by running its exact body twice instead: once against a real file, which
unlinked itself mid-execution, continued running, and reported `true`; once against a
target `unlink()` cannot remove, which reported `false` rather than claiming success.
That second run is the one that matters, and it is the case a passing test would most
easily have faked.

Left standing, and named rather than skipped:

- **Uploading `setup.php` still opens a window.** Self-deletion needs a request, so
  between the upload and the first hit the file is intact — and that is exactly when a
  restore to an empty database would find a working form. The do-not-upload rule is not
  retired by the mechanism, only backstopped by it. Post-upload check 3 now *repairs*
  what it looks for, but only if somebody runs it.
- **Two claims in the list are inferences, and say so.** Whether anything backs up
  `uploads/` (nothing in this repo does, and checklist step 1 backs up the database
  only) and whether anybody ever hand-raised `upload_max_filesize` in the live
  `.htaccess`. Both are one sentence from somebody with FTP; both are marked unverified
  rather than asserted, because the severity of group C and the existence of group D
  rest on them.
- **Nothing verifies the deploy actually followed the list.** The five checks catch
  three of the four groups after the fact; a mirroring client's deletion of `uploads/`
  is caught by check 4 and by then it has happened. A `.deployignore` would only help
  if the upload went through a tool that reads one, and it does not — this is
  phpMyAdmin and an FTP client.
- **The list is written for this host.** It names `srcresort.com/lbm/`, the
  `../../private/` layout and the drive-thru sign. Moving the app makes it prose to
  re-derive, not prose to trust.

### 4aa. The suite had never met the database it is about (#48, #51)

Two decisions, one subject: what the tests were actually running against, and how
much of §5 anybody was really doing.

**The stub nobody could remove.** `SELECT … FOR UPDATE` has no SQLite equivalent,
so `TestDisplayStore` replaced `lockLayoutRevision()` with a plain SELECT. Every
other statement in the publish path was real; that one was not — and it is the row
lock the publish transaction takes before it deletes anything, which makes it the
line in this repo with the most riding on it and, until now, no test over it at
all. Setting `SELFTEST_MYSQL_DSN` builds the fixture on a real MySQL database and
`newTestDisplayStore()` hands back the real store, so all 827 checks go through the
actual statement. SQLite stays the default: it needs nothing installed and runs in
about a second, and the fast loop is the one people use.

**Building the MySQL fixture from `schema.sql` was the cheap part and the valuable
one.** Nothing read that file. A column missing from it failed silently on a future
rebuild and nowhere else, which is why invariant 15 said to diff it against
`lib/schema.php` by eye. The MySQL run now asserts that convergence has *nothing
left to do* against a database built from it — the same property, mechanised. Drift
between the two files fails a check instead of waiting for a rebuild.

**A database per fixture, not one wiped between calls.** The suite holds two at
once on purpose — one scoping check proves a rename in database A cannot reach into
B — so a shared connection that reset itself would delete rows an earlier `$pdo`
was still being asserted against. This cost an hour to find and is worth writing
down: it presents as a `TypeError` about a null Display, forty checks after the
real cause.

**Four checks stay on SQLite deliberately**, and each says so where it sits. Three
need a catalogue that *disagrees* with the tables — a column that is really there
on a table `information_schema` never mentioned — and MySQL's cannot be made to
lie. One needs `INSERT IGNORE` to be *refused*, which is the witness that the seed
statement was never sent. `fakeCatalogue()` now rejects a non-SQLite handle rather
than failing later with a confusing message about `ATTACH`.

**What MySQL found:** the self-repair path completes. On SQLite the CREATE is
refused, the table stays missing, and the exception comes back out — all the
fixture could ever show. On MySQL `displays` and `display_permissions` are really
recreated, the drive-thru Display is seeded, and the read that triggered the repair
returns. §4q called that path "fixed" on the strength of a detector test; this is
the first time the sequence has run end to end anywhere except
`tools/rehearse_phase1.php` against live data.

**#51's version half took three attempts**, and this section stated two different
wrong things along the way. It read "CI pins PHP 8.2 against a 7.1 target", and the
answer first recorded here was that the live server runs 8.2 — evidence: Settings →
This Server, which cannot have been the source, because that screen is part of the
build that has not been deployed and #46's probe found `lib/` 404 on the live site.
That was withdrawn and the rule put back to 7.1-compatible. **The store owner then
stated it: 8.2, 2026-08-10**, which is the source the first attempt lacked, and the
floor is 8.2 on that basis. Still not a measurement this repo took — confirming it on
that screen is a deploy-day step. §4k carries the whole sequence, including why the
direction of the risk flips once a floor is declared rather than guessed low.

Running the suite on 7.1 did surface two ways this code misbehaves below PHP 8, and
both are fixed even though neither is reachable on 8.x:

- An array where a `temp_id` belongs. PHP 8 throws on the subscript and the publish
  is refused; earlier versions warn and continue, mapping nothing, so the section's
  content lands at root level — #31's silent reparenting. The refusal was real but
  it lived in the language rather than in `LayoutStore`, and the check covering it
  passed for a reason no reader of that file could see. It is written down now.
- The error log never rotating, because the size was read from a stat cache PHP 8
  invalidates and earlier versions do not.

The second one leaves a check that cannot fail on PHP 8, which is the shape #50 is
about. It is kept and labelled as such rather than deleted, because what it records
is worth more than the line — but it is not coverage and is not counted as any. Note
that "not reachable" rests on the same statement the floor does: 8.x is what the store
was said to run, and neither of these was ever observed there. They were fixed rather
than noted because a refusal that lives in a language version leaves when the host
does, and because the cost of writing it down was one check either way.

**What still is not covered**, printed by `tools/check_invariants.php` on every run
so it cannot be mistaken for full coverage: five §5 greps that no pattern can
decide, and the rehearsal against a database that genuinely *lags* the repo, which
needs a copy of live data and remains a deploy-day step.

---

### 4ab. The publish coerced everything and refused nothing (#23, #24, #25, #29–#32, #35)

Seven decisions, one shape. Every field of a published layout arrived through
`intval($el['x_pos'] ?? 0)` or `$el['font_family'] ?? 'Arial'` — and that is not a
rule, it is a coercion with an answer for everything and a report on nothing.
`"abc"` became 0. An array became 1, silently, which for `asset_id` meant the block
pointed at library row 1 — whatever that is on this installation. A `type` of
`"script"` was stored as `''` by a non-strict MySQL: a row that renders as nothing,
matches no type filter, and is invisible in the Work Area. A width of 999999999 was
either a failed publish reported as "Publish failed" or a truncation reported as
success, depending on a MySQL setting nobody here has looked at.

None of that had anywhere to be caught, because there was no step between "the JSON
decoded" and "delete the layout and insert this". So the fix is a step, and the
rule it applies is the one this app applies everywhere else: **refuse the write.**

**`lib/layout_rules.php` is a pure function.** No PDO, no Display, no transaction.
That is deliberate and it is the same lesson as `schema.php`'s decision half
(§4o): a function with no I/O can be asked several hundred questions in a
millisecond, so the vocabulary, the bounds, the column widths and the message
wording are all covered exhaustively without a database anywhere near them. It runs
**before `beginTransaction()`** — a refused publish has not opened a transaction,
has not taken the row lock, and has not touched a row.

What it decides, and why each one is not a coercion:

| Decision | The rule |
|---|---|
| #29 | `type` and `block_subtype` must be in the vocabulary. `'Section'` mattered most: `insertContent` skips sections with `!==`, so a mis-cased one slipped past the skip and was inserted as top-level content — by a `basic` account, which is the rule ADR-0005 exists to hold. |
| #30 | Numbers must be numbers and inside bounds; strings must be strings and inside their column widths. Refused, never clamped — except where the owner said clamp. |
| #31 | Two sections cannot share a `temp_id`. The map is a plain array, so the second write replaced the first and every block belonging to the first section was inserted into the second: a whole column moved across the sign, silently, reporting success. |
| #32 | `line_height` is **clamped** to 0.5–5, and written with an explicit empty thousands separator. `number_format($v, 2)` handed a DECIMAL(4,2) column the string `"2,000.00"`. |

The clamp is the one place the answer is not "refuse", and it is not an
inconsistency — it is the owner's decision on #32, recorded as such. A line height
that is not a number at all is still refused; only an out-of-range *number* is
clamped.

**One vocabulary, not two.** `SCHEMA_ELEMENT_TYPE_ENUM` and
`SCHEMA_BLOCK_SUBTYPE_ENUM` are now generated from `LayoutRules::ELEMENT_TYPES` and
`::BLOCK_SUBTYPES` rather than spelled out again in `schema.php`. The drift between
the list the column stored and the list the publish accepted *was* #29; two hand-
written copies are how it got there. The generated strings are pinned by checks
against the literals that used to be there, so a rebuild cannot quietly widen one.

**A refusal is its own kind.** `PublishResult` gained `invalid` and `busy` beside
`stale`, `locked` and `failed`, because `failed` means "something broke, try again"
and these two mean the opposite things: `invalid` will be refused identically until
the payload changes, and `busy` will very likely work in ten seconds. Both were
added to the Builder's **alert** branch rather than its toast branch, for the reason
the four already there are: a publish that was refused looks exactly like one that
worked if the only trace is a toast that has already faded, and the next thing
somebody does with a sign they believe they published is walk away from it.

**#25 — the hidden blocks were public.** `api.php?action=get_layout` needs no
sign-in, by design: a TV in a shop window cannot log in. It answered with the whole
layout and let the Viewer's JavaScript skip the hidden blocks on the way to the DOM
— a *rendering* rule standing in for an *access* rule. Anything an admin had hidden
was one `curl` away, content and all: next month's prices, a promotion with a date
on it, a section pulled because it was wrong. `publicSnapshot()` leaves them out at
the query, along with the children of hidden sections. The Viewer's own filter
stays where it is; that page runs unattended on a TV and a payload it did not
expect must not be the thing between a customer and a price list.

**#24 and #23 — the background address.** The rules lived near the admin panel and
nowhere else, so the API had none of them. `bg_val` is written into `displays` by a
publish and read back by the Viewer as `background-image: url('…')`, which makes an
unvalidated colour field an address every Screen in the building fetches on every
render. It did not even need an `image` background to reach: publish a "colour" of
`https://elsewhere/x.png`, then publish `image` with no file, and the `keep-image`
arm promotes the stored string to the image path. The rules moved onto `Background`
itself, which is the thing that knows what a background is.

That `keep-image` arm is also #23 — choosing Image on a Display that has never had
one leaves `url('#1a1a2e')`, which loads nothing and takes the sign near black. It
is the same two lines as #24's hole, so it was closed with it rather than left as a
known defect in code being rewritten. **#23 was not in the batch that was asked
for**; it is marked Done in `reviewed-decisions.md` and named here rather than
folded in quietly.

The check asks about the *intent*, and for `keep-image` about the value already
stored — never about a stored value the intent is going to replace. A Display
sitting on a bad value from before these rules can still be published to; it just
cannot be switched onto it. Anything else would have locked an admin out of a sign
because of a row written years ago.

**#35 — a collision was a timeout.** InnoDB waits `innodb_lock_wait_timeout`
seconds for a row lock and that defaults to **50**; PHP's `max_execution_time`
defaults to **30**. So the second of two publishes to one Display was never told
anything — it was killed mid-wait, with the `Content-Type` header already promising
JSON, the transaction left for the connection's teardown, and "Network error." in
the Builder for a publish whose fate nobody could determine. Five seconds now, set
per session before the transaction opens, and 1205/1213 caught and turned into a
sentence that says the true thing: try again in a moment.

The detector is engine-neutral and message-based, which is #11's rule rather than a
preference: PDO puts the *SQLSTATE* in `getCode()` and leaves 1205 in the message,
and a check gated on a MySQL-only number is a check the SQLite leg can never reach,
so nothing ever runs it.

**The deliberate breakages the tests catch.** Verified by injection, not assumed:

- Removing the shortened lock wait leaves the collision check *passing* — PHP CLI
  has no time limit, so the second publish still eventually gets 1205 and still
  comes back `busy`. What fails is the assertion that it gave up in **seconds**,
  which waited the full 50. That timing assertion is the load-bearing one and the
  only thing standing between #35 and a check that cannot fail.
- The collision test needs two database sessions and is therefore MySQL-only. A row
  lock is only a row lock across connections: the same PDO handle re-entering its
  own transaction waits for nothing, so a same-connection version would have been
  a check that passes for the wrong reason. `secondConnectionToLatestTestDb()`
  exists for this one test and says so.
- Two pre-existing checks changed from `failed` to `invalid` — the hostile-payload
  pair from §4g. Their *surviving-layout* assertions are untouched, because the
  point was never which kind came back, it was that two DELETEs did not run.

**Left standing, and named rather than skipped:**

- ~~**A `basic` account can still publish content at root level.**~~ **Closed by §4aj.**
  ADR-0005 says they work inside sections, and refusing an unresolvable parent would
  enforce it — but a Display with admin-made root-level content would then refuse that
  clerk's every publish, because their Builder resubmits what it loaded. Distinguishing
  "resubmitted existing root content" from "new root content" was not something the
  payload supported, so this was left with the reason stated: it needed a payload
  change, not a check. The payload change is what §4aj does.
- ~~**Colour *semantics* are not validated on the publish path**, only shape and
  length.~~ **Closed by §4ac.** `font_color` was checked as a string within 50 bytes
  and no further, because "an unreadable stored colour" is #41 and "the panel
  coerced a colour it could not parse" is #21. Both were taken together in the
  section after this one, and the publish path now refuses a colour it cannot read
  and names the block.
- ~~**`DisplayAdmin::cleanColor()` still coerces to `#1a1a2e`.**~~ **Closed by §4ac.**
  It was left alone deliberately so the fix would have one clean place to land; it
  landed there. The method is gone and both background paths refuse.

---

### 4ac. Refusing a value rather than guessing at it (#21, #41)

Taken together because they are one defect seen from two ends. #21 is "the panel
coerced values it could not parse and reported success". #41 is "an unreadable
stored colour round-tripped through the colour picker and published back as black".
The thing in the middle — the reason a colour nobody could read existed to be
round-tripped, and the reason nothing ever said so — is that **the app had four
different opinions about what a colour is, and all four of them substituted rather
than refused**:

| Where | Rule | Substituted | Then said |
|---|---|---|---|
| `DisplayAdmin::cleanColor()` | `/^#[0-9a-fA-F]{6}$/` | `#1a1a2e` | "Display created" / "Background colour set" |
| `BrandStyles::cleanColor()` | the same regex | `#ffffff` | "Brand standards saved" |
| Branding form, four times inline | the same regex | whatever was already saved | "Branding saved." |
| `rgbToHex()` in `builder.php` | its own | `#000000` | nothing at all |

Four copies of one rule is a smell. Four copies that disagree about the *fallback*
is the bug: "saved" meant four different things and none of them meant "what you
typed".

**The rule is one pure function now** — `lib/color.php` — and its interface is the
decision. `Color::read()` returns the colour or `''`. It never picks one. Every
caller decides what an empty answer means for it, at the call site, where it is
visible:

- A form **refuses and names the field**.
- The publish path **refuses and names the block**.
- A caller with a genuine default — a new canvas has to have *some* background —
  applies it explicitly, and only for the case that actually means "nothing was
  supplied".

That last distinction is most of #21. **Blank and unreadable are different
answers.** `Color::isColor('')` is false on purpose; a predicate that said true for
blank would collapse the two again, which is exactly how a form that submitted
nothing and a form that submitted nonsense came to be treated identically.

`Color::read()` is also deliberately **not** a normaliser: no trimming, no `#fff`
expansion, no `rgb()`. Widening what the app stores is not what this change is for,
and the accepted set is exactly what the three old regexes shared, so nothing that
used to be storable stopped being storable.

**What #21 changed, one caller at a time.**

- `DisplayAdmin` — both background paths refuse, with the same sentence, because an
  admin who sees two explanations for one rejected swatch will look for two causes.
  `setBackgroundColor()` is the harsher half and the one worth stating plainly: it
  used to substitute the dark default, **advance the layout stamp**, and report the
  background "set". So an admin got a colour they had not chosen, every Screen took
  it within 30 seconds, and every Builder tab open at the time was invalidated on
  the way past. There is no undo for any of that. The test asserts all three
  stopped — including that the stamp does not move on a refusal, which is the one
  that would have gone unnoticed.
- The **Branding form** refuses the whole save, logo included. A save that stored
  the new logo and none of the colours is a half-applied change with no undo and
  nothing saying which half landed — and `move_uploaded_file()` cannot be rolled
  back, so the refusal has to happen *before* the upload rather than after it. That
  is the one-token guard on the upload block, and it is load-bearing.
- **Ids.** `intval` never fails, it guesses. `"abc"` is 0, which at least produced
  "No account was named". The dangerous one is `"7abc"`, which is **7** — a real and
  different account, edited, closed or password-reset with the change reported as a
  success under the name the form had been showing. `intval([])` is 1, and account
  number 1 is the first admin the store ever created; `intval(true)` is 1 too. So
  the id now arrives at the module raw and the module decides. `AccountAdmin` and
  `DisplayStore::forId()` each hold that predicate, and the panel stopped casting.
- **`AccountAdmin::resetPassword()` is new.** The panel was running
  `UPDATE users SET password_hash = ? WHERE id = ?` itself and printing "Password
  reset." whatever the statement matched — including nothing, and including somebody
  else. It goes through `AccountStore::setPassword()`, which asks whether the row
  exists rather than reading `rowCount()` afterwards, because MySQL counts rows
  *changed* and a re-used password comes back as zero.

**What #41 changed, and why refusing is right here too.** The mechanism is worth
writing down because every step of it is silent:

1. A `font_color` holds something that is not `#rrggbb` — a row edited by hand, or
   one written before §4ab.
2. The Builder assigns it: `block.style.color = value`.
3. **The CSSOM discards a value it cannot parse and says nothing.** The property is
   not set to the bad value and not set to a default; it keeps what it had, which
   for a fresh block is `''`.
4. `rgbToHex('')` returned `'#000000'`.
5. Publishing — changing nothing, touching nothing — wrote black over it. On a
   #1a1a2e canvas the block did not look recoloured, it looked deleted.

`readHex()` replaces `rgbToHex()` and answers `''` rather than inventing. But not
inventing is only half: the block still has to render, so it renders in the default
while **the value that was actually stored is kept on the element** and published
back unchanged. `LayoutRules` then refuses that publish and names the block, and the
inspector says so before you get there.

That combination is the part worth defending, because at first reading it looks like
a deadlock — the Builder round-trips a value the server will refuse. It is not a
deadlock, it is an instruction: *Block 2 has a text colour that is not a colour
("puce").* An admin picks a colour, the marker clears, the publish goes through. The
alternatives are both worse. Silently normalising is the defect. Refusing to *load*
the Display would take a sign away from the person who needs to fix it.

#### The rows that were already there

Everything above is about values arriving. None of it says anything about the ones
already stored, and refusing at the door made those *worse* to hold: a `font_color`
holding `puce` now makes its Display refuse **every** publish until somebody picks a
colour for the named block. That is the right refusal, and it means the way to
discover it was for somebody to press Publish and be told no — mid-change, standing
in front of the sign they came to fix. Nothing in the app could answer "is there any
such row?" and nothing outside it could either.

`tools/audit_colors.php` asks. It is **read-only** — every statement a `SELECT` —
and so it is the one tool in `tools/` that is safe to point at the live database.
That needed saying loudly in its header, because `rehearse_phase1.php` sits beside
it demanding `--confirm-copy`, and a reader who has learned that habit will assume
the flag is missing rather than absent by design. Reporting is the whole job:
writing a colour of our own over an unreadable one is precisely the defect #21 and
#41 exist to stop, and there is no undo. A person picks the colour.

It also does not include `db_connect.php`, which every page does — that file arms
the alert mailer, so a mistyped `--host` would email the store's admins because
somebody ran an audit. The price is that it knows where the credentials file lives,
which is now the second place; `check_invariants.php` holds the two to one value.

**One predicate, three consequences**, and separating them is most of the tool's
value, because they send a person to three different screens:

| Where | What it does now | Who refuses it |
|---|---|---|
| `canvas_elements.font_color` | that Display cannot publish at all | the publish door, by name |
| `displays.bg_val` | the Screen quietly shows `#1a1a2e` instead | nothing — it renders |
| `block_styles.font_color` | **every** branded block of that type, on **every** sign, renders in whatever the browser inherits | nothing at all |

The third is the worst and was the surprise: `BrandStyles` cleans on the way *in*,
not on the way out, so a row edited by hand is handed to every Screen as it stands.
Black text on a dark canvas, everywhere, with no refusal anywhere in front of it.

Three things the audit deliberately does **not** report, each of which would send
somebody to fix something that is not wrong: a `font_color` on a **section**, which
has no text of its own and which the publish door does not check either; a **blank**
colour, which means "no colour of its own" and is what every branded block carries —
#21's absent-versus-unreadable line, and a predicate that missed it would report the
whole store; and the `bg_val` of a Display currently showing an **image**, which
holds whatever it held before the switch and which nothing reads.

The module is `lib/color_audit.php`: a use-case module in the §2 sense — it owns the
sweep, writes no SQL of its own, and returns findings a caller turns into sentences.
The one statement it needs is `LayoutStore::unreadableFontColors()`, on the module
that owns the table, scoped by Display like every other statement there. 21 checks in
`tools/selftest_layout.php`, on both engines, including that the publish door really
does refuse the same value the audit reports — because an audit and a door that drift
apart are each defensible alone and useless together.

**Two things deliberately not done:**

- **`BrandStyles::cleanColor()` still clamps.** That module's contract is that every
  stored value renders, because these land on a wall-mounted Screen with nobody
  watching. What changed is only that the rule is `Color`'s rather than a fourth
  private copy. The caller with an admin in front of it — the panel — asks `Color`
  directly and refuses, so by the time a value reaches the clamp it has already been
  through that; the fallback covers the API path and a hand-built POST.
- **The panel still says "That display no longer exists." for a malformed `d_id`.**
  Accurate enough — no Display of that name exists — and the harmful half (deleting
  a *different* Display on a matching typed tag) is closed. Three identical blocks
  would need the distinction to say it better, and that is tidying rather than #21.

**Coverage.** 69 new checks in `tools/selftest_layout.php` (both engines) and a
third node suite, `tools/selftest_builder_colors.js`, at 43. The node suite is the
one where the *stub* is load-bearing: its `style` is a Proxy that discards an
unparseable colour and normalises a parseable one exactly as a browser does, because
a stub that stored `'puce'` would have passed against the original bug. Its fidelity
is asserted before anything is asserted through it.

**The deliberate breakages the tests catch.** Verified by injection, not assumed:

- Dropping `block.dataset.colorUnread ||` from the publish payload — the original
  #41 line — fails with `expected "puce", got "#000000"`, which is the defect
  reproduced verbatim.
- Restoring `rgbToHex()`'s `return '#000000'` fails 8 checks across three sections,
  because that one value propagates into the marker, the payload and the inspector.
- Removing the `delete` that clears a stale marker fails exactly one: a block
  somebody has just fixed would otherwise go on refusing to publish forever.
- Restoring `setBackgroundColor()`'s coercion fails 4, including the layout stamp
  moving on a refusal.

### 4ad. Deleting a Display never asked who was using it (#19)

Every other change of reach in this app frees the holder's edit lock in the same
transaction and lets their Builder say so — a revoked grant, a closed account, a
demotion, a suspension, a Display turned off (§4s, §4t). Deletion was the one that
could not, and so it was the one that did nothing at all.

It cannot, for a reason that is structural rather than an oversight: afterwards
there is no row to free a lock on and no Display for the holder's page to ask about.
The machinery those five changes rely on has nothing left to work with. So deletion
is the case that has to **refuse in advance** instead of repairing afterwards.

What happened without it: a clerk had Drive-Thru open and a shelf's worth of
unpublished layout on screen. An admin deleted the Display. The clerk's canvas was
still drawn — nothing tells a browser its subject has gone — and their next publish
had nowhere to land. The admin was never told there was anybody there, and there is
no undo anywhere in this app, so neither of them could get it back.

**The predicate is `heldByOther()`, not `isHeld()`** — the same one that makes a
Builder read-only and refuses a publish. An admin deleting a sign they have open
themselves is deleting their own work, knowingly. A lapsed lock does not block
either: `LockState` already rules that a Builder left open on a back-office monitor
is nobody, and a lock whose holder can no longer sign in is nobody too (§4t). Both
of those follow for free from asking `LockState` rather than testing the column, and
both are checked, because "a deletion this app can never perform again" is the
failure mode of getting that wrong.

**It is asked twice, and the two asks do different jobs.**

- *Before the typed tag.* The tag gate proves the admin means this sign; it says
  nothing about whether anyone is using it. Asking the immovable fact first is the
  difference between learning who is editing now and being sent away to retype a tag
  for a deletion that was never going to happen.
- *Inside the transaction, on a row the module reads itself.* Without it the
  guarantee is "the caller handed me a Display it read recently" — true of both
  callers today, and not something to rest an irreversible write on. This is also
  what catches the realistic case: a delete form drawn a minute ago, submitted after
  somebody opened the sign.

**What is left open, on purpose.** The re-read is a plain `SELECT`, so a lock claimed
between it and the delete still gets through. What that costs is the moment, not the
twenty minutes the refusal exists for, and the holder's Builder already has a
sentence for a Display that has gone. Closing it would mean a second `FOR UPDATE`, a
second SQLite seam for it, and a second encounter with #35's lock-wait timeout —
spent on the rarest write in the app, when the publish path carrying the first one
has two people colliding on an ordinary Tuesday. Written down rather than left as an
absence, because the next person to read `destroy()` will wonder.

**The second half of #19 was the confirm box.** It said "Delete Drive-Thru and its
12 elements?" — accurate, and the smallest part of the bill. Deleting also takes
every assignment on the sign, and it may be taking it out from under somebody right
now. Both are on the panel before the button: the assigned accounts are named, and a
held lock replaces the button with who has it, since when, and the two ways out. The
button is *disabled* rather than absent, because a page drawn while somebody was
editing and submitted after they stopped is a POST the server has to judge on its
own either way — the greying-out saves an admin typing a tag for nothing, and is not
where the rule lives. The confirmation message now counts the revoked grants too.

**Coverage.** 25 new checks in `tools/selftest_layout.php`, both engines, at the
module — because the panel's greyed-out button is a courtesy and a POST can arrive
without it. Every block builds its own Display through `freshDriveThru()`: half of
what is under test is a *refusal*, so a regression leaves the sign standing where
the test expected it gone, and the next block would otherwise die on the unique tag
or on a null, reporting a crash three blocks from the line that broke.

**The deliberate breakages the tests catch.** Verified by injection, not assumed:

- Removing both checks — `destroy()` exactly as it shipped — fails 12, led by
  *an admin cannot delete a Display somebody else is editing, correct tag and all*.
- Removing only the in-transaction re-check fails 4: the stale-argument case goes
  through, and the Display, its layout and its grant are all gone.
- Removing only the pre-tag check fails 1 — the ordering. The deletion is still
  refused, by the second ask; what is lost is the admin's next minute.
- `heldByOther()` → `isHeld()` fails 2: the holder can no longer delete their own
  sign, which is the over-correction this rule is one step away from.
- Dropping the revoked-grant clause from the confirmation fails the #19 check that
  the element count was never the whole cost.
- Replacing the gone-already arm with a fallback to the caller's Display fails 2:
  two admins on the button at once, and the second one told it worked.

~~**Not covered here, and deliberately.** `DisplayStore::normalizeTag()` still does
`(string)$tag`, so `confirm_tag[]=x` raises "Array to string conversion" above the
document before refusing.~~ **Closed by §4ae**, which owns that function.

### 4ae. A parameter that is not a tag names no sign (#27)

**Half of this item was already fixed, and by something else.** #27 is recorded as
"`?display[]=x` became the tag "array" **and printed a warning above the document**".
The printing stopped at §4m: `ErrorPolicy` sets `display_errors` off and swallows
warnings into a log rather than the response, so on the live server that warning has
not reached a page since. Worth saying plainly, because it is the second item on this
list whose stated premise had expired before anybody got to it (#51 was the first).

**What was actually still broken** is the more interesting half:

- `(string)['x']` is the literal word **`Array`**, and `array` is a perfectly valid
  screen name tag — lowercase letters, five of them. So the request did not fail, it
  went and looked one up. With no such Display the Screen said *"Display not found"*,
  which is a lie: nothing was named. **With a Display genuinely tagged `array`, it
  rendered that sign.** The self-test asserts exactly that case, and it is the check
  that fails loudest when the cast is put back.
- The warning still costs something even unprinted. A Screen hung on a wall with a
  malformed address writes an "Array to string conversion" line every 30 seconds,
  forever, into a 2 MB rotating log on a shared host.

**The fix is that a tag is a string, and nothing else is a tag.** No cast anywhere:
`DisplayRequest::locate()` answers `noTag()` for a `display` parameter that is not a
string, and `DisplayStore::normalizeTag()` answers `''` for anything that is not one.
The second closes the same defect at every other door in one line — `forTag()`, the
delete confirm's typed tag, and the Display form's tag field all read the empty
answer correctly already.

**Where the care went: the ordering, not the predicate.** The obvious implementation
is to fold a non-string to `''` and let the existing empty-tag path handle it. That
is wrong, and only for editing. `locate()`'s entry rule says an account with exactly
one Display to work on is not asked which one it meant — a convenience for a person
opening the Builder at a single-sign store. Fold `display[]=x` to `''` and a
**publish** carrying it inherits that convenience and lands on a Display the request
never named. So the check returns before the fallback: *a tag that cannot be read is
not a tag left out.* Injecting the fold-to-empty version fails exactly one check, by
name, and that check is the whole reason this paragraph exists.

The asymmetry with `confirmIdentity()` two methods below is deliberate and now sits
in plain sight: an array in **`display_id`** is a `mismatch`, not "no claim". That
parameter is a caller asserting which record it holds, so sending a malformed one is
a disagreement. `display` is an address, and a malformed address names nothing.

**Coverage.** 13 new checks, both engines. Four on `normalizeTag()` directly, the
rest on resolution — including the `array`-tagged Display, the editing ordering, and
a `set_error_handler` assertion that nothing warns. The suite's own "no PHP
diagnostics during the run" guard catches the warning independently, which is why
restoring either cast fails eight or nine checks rather than the two it breaks.

**The deliberate breakages the tests catch.** Verified by injection:

- Restoring `(string)` in `locate()` fails 9, led by *even with a Display genuinely
  tagged "array", a list still reaches nothing — expected 'no_tag', got 'found'*.
  That failure is the defect at its sharpest: a public URL rendering a sign nobody
  asked for.
- Restoring `(string)` in `normalizeTag()` fails 8, across the fold itself, the
  Viewer path and the delete confirm.
- Folding a non-string to `''` instead of stopping fails exactly 1: *a write is
  refused rather than routed to the sole Display the entry rule would have picked*.

### 4af. The envelope every answer leaves in (#26, #28)

Two items, one module, because they are two symptoms of the same absence: nothing
owned what an HTTP reply looks like. `lib/http_reply.php` now does — the status
line, the caching rules, and the bytes of the body.

**#26 — a reply that failed to encode.** The chain is short and every link is
silent:

`json_encode` returns **`false`** on failure — not an empty array, not a throw —
and `echo false` prints the empty string. So a payload holding one byte that is not
valid UTF-8 left as **200 OK, `Content-Type: application/json`, zero bytes**. On the
Screen `r.json()` rejects, and the Viewer's `.catch` does exactly the right thing
for a dropped packet: keeps the layout up, tries again in 30 seconds. But the cause
was a byte in the database, so the next poll hit it too, and the one after that. A
sign showing last week's prices, indefinitely, with nothing in any log.

Two decisions worth writing down.

**Malformed UTF-8 is repaired, not refused** — which needs saying, because
CLAUDE.md's rule is *prefer refusing a write to merging one*, and this reads like
the opposite until you look at what refusing costs:

- This is a **read**. The stored bytes are untouched.
- The write door is **already shut**: a publish arrives as a JSON string and
  `json_decode` refuses malformed UTF-8 outright, so nothing invalid can enter
  through the app at all. What is there came from a restore or a hand edit.
- Refusing the read would take a whole sign dark over one character **and make the
  fault unfixable through the app** — the Builder is the only tool for editing that
  text and it would refuse to load the layout containing it. The only remaining fix
  would be SQL against the live database, at a shop with no DBA.
- U+FFFD loses nothing that was not already lost: the byte could not be rendered,
  searched or exported. What it buys is that the damage becomes *visible*, in the
  Builder, in the text, in front of the person who can fix it.

And the admin is told — throttled to one report per sign per hour, because the
alternative is 2,880 identical lines a day per Screen in a log that rotates at 2 MB.
Anything json_encode cannot be talked into — INF, NAN, recursion, past the depth
limit — is not repairable and becomes a real 500 carrying a body built from a
payload this module controls. **The invariant is that no JSON request is ever
answered with something that is not JSON**, and the self-test asserts it as a
property over every input that has ever broken an encode here rather than as three
examples.

**The same defect in a different hat, and worse.** `var TAG = <` `?= json_encode($tag)
?` `>;` emits `var TAG = ;` when the encode fails — a **parse error that takes down
the whole script block**, not one value. Nine call sites had this: viewer.php (a
blank television), builder.php ×6 and admin_panel.php ×3 (a page of controls that do
nothing). All nine go through `HttpReply::jsValue()` now, which also fixes a second
thing found on the way: the eight in builder.php and admin_panel.php passed
`JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP` by hand at every call and
**viewer.php passed none** — safe only because a screen name tag is `[a-z0-9-]`,
which is an argument that lives in another file. `jsValue()` carries the four flags
for every caller.

**And the sign has to notice.** That is the second half of #26's wording and it is
a judgement, not a rule, so `tools/selftest_viewer.js` exists to pin where the line
falls. `.catch` counts consecutive failures; ten of them — five minutes — replaces
the layout with the notice. Not one, because blanking a working price board for a
Wi-Fi roam would be a worse fault than the one being fixed; not never, because a
stale *price* is a promise the store then has to keep, where a blank sign is not.
The watchdog counts too: a request that never settles is the one shape of this
failure no `.catch` can see, and before this it left the counter at zero however
long the sign had been stranded.

**#28 — real error codes, and no caching.** Missing, unknown and switched-off signs
all answered 200, so anything that never reads the body — a proxy, an uptime check,
`curl` after typing a tag onto a new television — was told all three had worked. The
code is now **derived from the payload's own `reason`**, not chosen beside it: there
are twenty-odd reply sites and a code that disagrees with a reason disagrees
silently. `no_tag` → 400, `unknown` → 404, `inactive` → **503** with `Retry-After: 30`
— "not here" and "here, switched off" being precisely the two the item says were
conflated. `forbidden` → 403; `mismatch`, `stale`, `locked`, `busy` → 409; `invalid`
→ 422; `failed` → 500. A reason nobody listed still gets a 400 rather than a 200.

**The two halves of #28 are load-bearing on each other**, which is the part that
would be easy to miss: a **404 is heuristically cacheable by default** (RFC 9110
§15.5.5) where an unlabelled 200 with no validator mostly is not. Fixing the status
codes *without* fixing the caching would have made a mistyped screen name tag
**stickier** than it was before the item was touched. `no-store` goes on every reply
from `HttpReply::json()`, on viewer.php before its first byte, and — via `auth.php`,
which every protected page includes — on the pages behind the sign-in, where the
ordinary case for a shop is a shared back-office computer whose back button after a
sign-out redrew the admin panel, account names and all, from the browser's own store
with no request the server could have refused.

**Nothing broke in the Builder, and that was checked rather than hoped.** `fetch`
does not reject on 4xx/5xx and builder.php reads `res.status` from the parsed body,
never `r.ok`; the one place that does read a code is the XHR upload path, which
already handled `>= 400` by showing the server's own message. `LOCK_TERMINAL` is a
fixed list, so the two `reason` values added here (`invalid` on an unreadable
publish, `failed` on a Brand Standards save with no rows) cannot become fatal to an
editor mid-work.

**Coverage.** 74 new checks in the PHP suite on both engines, plus the whole of
`tools/selftest_viewer.js` (32) — the first suite that runs viewer.php's own
JavaScript rather than only parsing it. It holds the interaction between the two
items as well: a 503 from a sign an admin switched off must **not** count as the
server being unreachable, or the deliberate notice would count down to a different
one. A new consistency rule, *one module encodes JSON*, keeps the whole class shut
rather than these nine instances of it.

**The deliberate breakages the tests catch.** Verified by injection, thirteen of
them; the ones worth naming:

- `reply()` trusting `json_encode` again — the original defect — fails 14, led by
  *a reply holding invalid UTF-8 is repaired, not dropped*.
- Removing only the substitution fails 10: the sign goes to a 500 rather than to
  the prices it could have shown.
- Repairing but not reporting fails 2, and reporting without the throttle fails 1.
- `inactive` answering 404 like `unknown` fails 3, one of them by name: *which is
  the distinction #28 is about: those two are not the same answer*.
- Dropping `no-store` fails 1; `codeForPayload` returning 200 for errors fails 2.
- In the Viewer: `.catch` swallowing again fails 8; the watchdog only freeing the
  flag fails 2; a poll that answers not clearing the count fails 6; and blanking on
  the **first** failure fails 5, led by *and the sign keeps showing what it last
  knew* — the guard against over-correcting this item.
- An endpoint going back to `echo json_encode` is caught by the consistency check,
  by file and line, not by a test.

**Not covered here, and deliberately.** The Viewer still re-renders from scratch
after any failed poll (`_layoutHash = ''`), which restarts videos and carousels — so
a flaky link makes a visibly stuttering sign. It is pre-existing, it is not #26, and
fixing it means deciding whether a re-render that produces identical markup should
count as a change at all. Recorded rather than folded in.

---

### 4ag. A block with nothing in it was explaining itself to the customer (#45)

An empty carousel drew **"Carousel — no slides added yet"** in grey, centred in the
block, on a board somebody is reading to decide what to order. The sentence is
addressed to whoever was building the layout, and it is the one audience that could
never receive it: a person standing in front of a price sign cannot add a slide to
it. What they can do is read it, and conclude the store's board is broken.

**It was two blocks, not one.** `renderTable()` had the identical construction one
function down — **"Table — no data"**, over an `rgba(0,0,0,0.3)` panel drawn
specifically to hold the message. Both are closed here. Leaving the second would
have meant knowingly shipping the defect the item describes, in code the item had
just been applied to.

**Drawing nothing costs the author nothing, and that is what makes it safe.** The
warning still exists; it was simply in the wrong place. The Builder never renders
carousel or table *content* on its canvas at all — it draws a label, and that label
already counts: `↻ Carousel — 0 slides` (`builder.php:3062`) and `⋞ Table — 0 cols,
0 rows` (`:3281`). So the surface the author is looking at while they forget to add
the slides tells them, and the surface a customer is looking at says nothing. Both
labels are asserted from the Viewer's own suite, because deleting one would quietly
turn this decision into a block nobody can see is empty.

**Returning is enough to draw nothing.** The caller appends the `.element-block`
either way, and that class sets only `position` and `overflow` — no background, no
border — so an empty one is not ink. That is the fact the fix rests on; it is
written into the code beside the `return` rather than left to be re-derived by
whoever next adds a rule to that stylesheet.

**"Not a list of slides" reaches the same answer, by answering.** Element content is
deliberately unvalidated for the non-text types (§2 invariant 6), so `slides` can be
a string, an object or absent. It previously threw into the caller's `try` and the
block was skipped — the same thing a customer sees, arrived at by accident. The
guard is `!Array.isArray(slides) || slides.length === 0`, and the suite checks that
each shape *decides* without throwing, because the two stop being equivalent the
moment anybody moves the call.

**Coverage.** `tools/selftest_viewer.js` grew from 32 checks to 75. It calls the two
renderers directly across eight empty shapes — no slides, never configured,
unparseable content, content that is not a list, and the four table equivalents —
asserting for each that nothing is appended, no words reach the sign, and no panel
is painted behind them. Then the same thing end to end through the real poll: a
price beside an empty carousel and an empty table, asserting all three blocks are
laid out and the only words on the canvas are `Sockeye 18.99`.

Three checks guard the other way, because quietening a carousel that *does* have
slides would be a far worse fault than the one #45 reports. Two more read the source
with whole-line comments stripped, matched on the ASCII half of each sentence — a
guard that looked for the em dash would be walked past by `—` or `&mdash;`,
which is not hypothetical: the first injection run wrote it that way by accident and
slipped through.

**Verified by injection, four times.** Restoring the carousel placeholder fails 9,
including *and a customer reads the price, and nothing addressed to the author*;
restoring the table's fails 10; making the carousel return unconditionally fails 3,
led by *a carousel that has a slide still draws it*; and deleting the Builder's
label fails 1.

#### Second pass: the marquee and the empty slide

The first pass took out two sentences. The owner then asked for the two cases that
are the same defect in colour rather than in English, and they turn out to be the
worse pair, because a sentence at least looks like a mistake.

**A marquee with no text painted a red bar and scrolled nothing along it.** The
block already meant to draw nothing — `if (!text) return;` was in the function — but
`block.style.background = bg` sat four lines above it, so the return ran after the
paint. Default `#c0392b`: a solid red band across a price board, with no message on
it, and an invisible span animating along it at 80 px/sec for as long as the sign is
on. The guard moved above the paint, and the same lossless argument holds as for the
carousel and the table, more directly than either: the Builder draws this block as
**"▶ Marquee text — click to edit in inspector"** (`builder.php:3444`), on that same
bar, on the screen where the box that fixes it is. The words the customer was
getting a red bar instead of are already sitting in front of the author.

Two smaller things the same guard settles. `'   '` is truthy, so a marquee of spaces
used to paint and animate exactly like an empty one; it is trimmed now. And only a
string or a number is a message — an object reached `textContent` and scrolled
`[object Object]` past the customer, for the invariant-6 reason the carousel's
`Array.isArray` exists.

**A carousel slide with no image filled its image well with `#1a1a2e`.** A navy
rectangle standing in for a picture nobody had chosen, hardcoded, drawn only in the
`else` of `if (s.image)` — which is what separates it from the marquee's bar: the
bar is a colour the author picked in the inspector, the navy is a colour the code
picked because something was missing. So a carousel of blank slides rotated coloured
panels past the customer every five seconds without ever saying anything.

A slide now draws only if it holds something: an image, or — when it is not
`imageOnly` — a title, price or description that is set. Slides with nothing in them
are filtered out *before* the loop rather than skipped inside it, which is what makes
the rotation right as well as the drawing: `slideEls` holds only slides that show
something, so three slides of which one is real is one slide, not one slide and ten
seconds of blank. A carousel whose every slide is empty draws nothing, exactly as one
with no slides at all. A slide that is `null` or a string reaches that same answer by
answering, where it used to throw on `s.textPosition`.

The empty well is still **appended**, just not painted. Taking it away would give the
text panel the full width and reflow a slide that is not the one at fault; the 40/60
split is the layout the author arranged around their words. Only the colour goes.

**Coverage.** 75 checks to 129. Eleven more empty shapes through `drewNothing` — six
marquees, five carousels — plus a `paintUnder()` walk that fails on `#c0392b` or
`#1a1a2e` appearing anywhere beneath a block, and the Builder's marquee sentence
asserted from here like the other two labels.

**Verified by injection, five times.** Painting the bar before the guard fails 7;
restoring the navy well and drawing every slide fails 8, including *a carousel whose
slides are not slides decides that without throwing*; a marquee that never draws
fails 2; deleting the Builder's sentence fails 1.

The fourth injection — making `s.image` stop counting as something a slide shows —
**passed all 127 checks**, which is the useful result of the five. A slide can be a
picture and no words at all; that is what `imageOnly` is for. Deciding a slide is
empty whenever it has no text would have taken every photograph off every sign in the
store and nothing would have said so. Two checks now cover it, and the suite is 129.

#### Third pass: the picture and the film nobody chose

The last two, taken on the same instruction. These are a different question from the
first four, and the difference is the whole reason they were held back: the ink is
not the page's. Nothing here writes a sentence or paints a panel — it appends an
element and lets the browser decide what a missing file looks like.

Which is exactly why they belong closed. `img.src = ''` is not an absent picture, it
is a **broken** one by definition — the empty string puts the element straight into
the broken state — and what a broken image looks like is the browser's choice: an
icon on some, a blank box on others, at 100% × 100% because that is what
`.element-block img` says. An autoplaying `<video>` with no `<source>` is the same
shape of thing: it never plays, and its rectangle is black on one browser and
transparent on the next. The old code even had the guard half-written — `if
(content)` skipped the `<source>` and appended the empty player regardless.

A store's sign must not look different because of which browser the television
shipped with. Appending nothing is the one rendering that is the same everywhere, and
it is also the true one: there is no picture here.

**The empty path is not only the unfinished block.** `content` is `db_content` for a
block linked to an asset, so an asset deleted out from under a live layout arrives
here as null. That is the case that reaches a sign without anybody editing it.

Both branches came out of the render loop into `renderImage()` and `renderVideo()`,
beside the other three. The loop is an entry point and was carrying two blocks of
element-shaping logic that nothing could call; a named function is testable, and
these are the two that most needed testing, being the ones whose failure looks
different in each browser.

**The Builder had to start speaking for the video.** Drawing nothing is only safe
while the author can still see the block, and the video was the one case with nothing
on either surface — an empty `<video>` in the Builder too. It now gets the drawn
placeholder an image already got (`svgPlaceholder(w, h, 'Video')`), cleared when a
file is uploaded or an asset linked. Without it this pass would have made a block
that exists in the database and is drawn by neither page.

**Coverage.** 129 checks to 169: eight more empty shapes, five positive checks that a
block with a file still shows it — path, fit mode, source and MIME type — and the two
Builder placeholders asserted like the three labels before them.

**Verified by injection, four times.** Appending the broken image anyway fails 6;
appending the empty player anyway fails 4; removing the Builder's `'Video'`
placeholder fails 1; a video that never plays fails 3.

**#45 is closed.** All five block types that drew something when they had nothing now
draw nothing: two sentences, two colours, and two elements whose appearance was the
browser's to choose.

### 4ah. Escaping was a default, and the default is not one behaviour (#15)

159 calls to `htmlspecialchars()`, and not one of them said what it wanted. That is
not a style complaint: **the function's default flag set changed in PHP 8.1.** Before
it, `ENT_COMPAT | ENT_HTML401` — `"` escaped, `'` left alone. From it,
`ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401` — both escaped. So

```php
<input value='<?= htmlspecialchars($x) ?>'>     <!-- single-quoted attribute -->
```

was safe or an injection depending on which PHP the host was running, and nothing in
the source recorded which was meant. This app was stated to run 8.2 (#51, §4k), so the
strict behaviour is what it gets today — which is precisely the situation where "the
default is fine now" is the assumption not to leave lying around, because it is true
until the host moves and says nothing when it does. The 7.1-era fallbacks in `auth.php`
and `.htaccess` are kept for that same reason (§5). Naming both flags removes the
question rather than answering it, which is the only version-independent fix.

The quieter half is worse and has nothing to do with attacks. Without
`ENT_SUBSTITUTE`, one byte of invalid UTF-8 makes `htmlspecialchars()` return **the
empty string** — not the value, not an error, nothing. That is #26's shape on a page
instead of in a reply: a stored character nobody typed on purpose, and a price that
silently stops being displayed. With it the bad byte becomes U+FFFD and the rest of
the value survives.

`lib/markup.php` names both flags once. `Markup::text()` is the only door, and
`tools/check_invariants.php` holds `htmlspecialchars(` to that file — with one
carve-out, `lib/error_policy.php`, for the same reason it keeps a `json_encode`: it
draws the last-resort notice, the path that must not depend on anything, and its call
passes the flags in full.

**Non-strings answer `''`,** for the reason `Color::read()` does: `(string)` on an
array is the warning *"Array to string conversion"* and the literal text `Array`,
printed onto the page. `null` matters more in practice — it arrives from every
nullable column, a Display with no location, and passing it to `htmlspecialchars()`
is a deprecation notice on 8.1 and later, logged on every single page load, saying
nothing anybody can act on.

**The flags were never the half that mattered most.** #15's other clause — "a username
containing HTML reached a confirm box unescaped" — is a different bug wearing the same
clothes, and it was still there with the strict default:

```php
onsubmit="return confirm('Close the account for <?= htmlspecialchars($name) ?>?…')"
```

That looks escaped. It is not. **The HTML parser decodes the attribute before the
JavaScript parser sees it**, so the `&#039;` that `ENT_QUOTES` just produced is a plain
`'` again by the time it is a string literal, and the string ends there. A username of
`o'brien` breaks the page; one chosen with more intent runs whatever it likes, in an
admin's session, on the Accounts screen. The escaping was not missing — it was correct
for the wrong one of two nested contexts.

`Markup::jsInAttr()` is the fix and the name for it: `HttpReply::jsValue()` produces a
JSON literal with `'`, `"`, `<`, `>` and `&` all as `\uXXXX`, so nothing inside it can
end anything, and `text()` then escapes the quotes that delimit it. It is passed as
the **whole** argument, never spliced into a string — the sentence moved into a
JavaScript function, `confirmCloseAccount()`, beside the `confirmTurnOff()` that had
already solved this once by hand and was the only call in the codebase passing flags.

The invariant that guards it catches the construction rather than the instance:
an event attribute holding a quote and then a `Markup::` call is a value escaped for
HTML and used as JavaScript, and no file may match. It fires on the natural way this
comes back, because whoever reintroduces it will reach for `Markup::text()` — that is
now what the rest of the file uses.

**What is still spliced into a JavaScript string in an attribute, and why it is not
the same thing:** `togglePanel('del-display-<?= $did ?>')` and two like it, plus
`confirm('Remove <?= intval($tidyCount) ?> …')` in `crud.php`. Every one is an
integer, and an integer has no representation that can end a string. The six
`updatePreview('<?= $t ?>')` calls were strings — from a hardcoded list, not from the
database, but strings — and they went through `jsInAttr()` anyway, so that the only
values left spliced are numbers.

**One trap found while writing the module, worth recording because it lints clean.**
A `?` followed by `>` inside a `//` comment **ends PHP mode** — one-line comments run
to the end of the line *or the end of the PHP block, whichever comes first*. The
header of `lib/markup.php` quoted `<?= htmlspecialchars($x) ?>` as an example of the
thing being fixed, and PHP dutifully left PHP mode there and printed the rest of the
header onto the page. `php -l` says nothing: the file is valid. It was caught by the
self-test loading the file at all, which is the only gate that could have. The
examples in that file now write the tags as `{{ … }}`, and say why.

**Coverage.** 19 checks in `tools/selftest_layout.php`, on both engines: each flag
asserted by what it does rather than by being present, the bad-byte case, the
non-string cases with no warning raised, and the nested-context defect demonstrated
end to end — `Markup::text()`'s output decoded the way a browser decodes an attribute
yields a live quote, and `jsInAttr()`'s does not.

**Verified by injection, five times.** Dropping `ENT_SUBSTITUTE` fails 2, both saying
the price no longer reaches the page; going back to the pre-8.1 default fails 3;
casting non-strings instead of refusing them fails 2, one of which is the warning
itself; putting the confirm box back fails the invariant **and** 2 checks; and a
single raw `htmlspecialchars(` anywhere in a page fails the invariant by name.

**What was left, and then closed.** #15 says "escape every stored value strictly,
app-wide". The two passes above make every *escaped* value strict and put each one in
the right context; neither proves every value **is** escaped. A page added later could
still forget, and nothing caught that — what the invariants caught was forgetting
*how*.

Measured rather than guessed at: of 319 echoes across the eight pages, 174 went
through a door and 145 did not. Almost all of the 145 were fine, and — this is the
part that made a rule possible — they were fine in shapes that can be recognised
rather than looked up. So the third pass is a classifier, in
`tools/check_invariants.php`. **Every echoed expression on a page must be one of five
things:**

| shape | why it is safe |
|---|---|
| a door | `Markup::text()`, `Markup::jsInAttr()`, `HttpReply::jsValue()` — the three functions that exist to answer this |
| a literal | a quoted string or a number, written in the source |
| a safe call | `count`, `intval`, `intdiv`, `floatval`, `number_format`, `urlencode`, `rawurlencode`, and `date()` with a literal format — each returns only digits, or only characters no parser is looking for, whatever it is handed |
| a colour | `SiteChrome::navBg()` and its three siblings, which return `#rrggbb` because `Color::read()` decided (§4ai) |
| a number | a class constant whose declaration in `lib/` is a numeric literal — **resolved**, not assumed, so `Foo::BAR = 'x <b>'` does not pass |

and a ternary is safe when both branches are, a concatenation when every piece is.
Both rules recurse, which is what makes five shapes enough: `' · ' .
Markup::text($d->location())` is a literal and a door, and
`!empty($u['created_at']) ? date('M j, Y', …) : '—'` is a safe call and a literal.
A ternary's *condition* is not echoed and is not examined.

**Nothing is on a list of exceptions, deliberately.** Fifteen echoes were converted to
reach that state — ids and dimensions to `intval()`, labels and roles to
`Markup::text()` — rather than named in an allow-list, because an allow-list is
somewhere to put the next one too, and it stops being read at about the tenth entry.
Widening the classifier is a change to what "safe" means here and gets reviewed as
one; adding an integer to the seven safe calls is not.

**Limits, stated.** `intval($x)` is trusted without asking what `$x` is — that is what
`intval` is *for*, and a checker that went looking would be re-implementing PHP. It
reads one expression at a time and knows nothing about where a value came from, so it
cannot tell a safe `$id` from an unsafe one and does not try: it asks only that the
line say which it is. An echo inside a `<script>` is judged by the previous rule as
well as this one — different questions about the same line, and both have to hold.

**Verified by injection, twenty times.** Ten shapes that must fail — `<?= $u['name'] ?>`,
`$x . $y`, `date($fmt)`, `strtoupper($x)`, an unknown class constant, a ternary with
one unsafe branch, `SiteChrome::logo()` (a path, not a colour), `$a ?: 'fallback'` (the
Elvis form echoes its condition), `'a' . $b . 'c'`, and `Markup::text($a) . $b` — and
ten that must pass, including a parenthesised nested ternary that a first version got
wrong and now does not.

---

### 4ai. The store's own colours, where escaping was the wrong tool (#15)

The sweep for §4ah turned up a family that was never an escaping problem at all.
`BRAND_ACCENT` and its three siblings were interpolated into the `<style>` block on
the Builder, the Help page and the sign-in page — 13 places — and every one of them
had been dutifully wrapped in `Markup::text()`.

Which does nothing. **Inside a `<style>` the HTML parser is looking for `</style` and
nothing else**, so escaping leaves the value untouched in every respect that matters,
while reading at the call site like the question has been dealt with. An accent colour
of

```
#fff; } body { background: url(https://example.invalid/x)
```

is, *after* escaping, exactly those characters inside a stylesheet: a closed rule and
a new one. There is no delimiter for an entity to neutralise. What is needed there is
not "make these characters inert" but **"this is a colour"** — and `lib/color.php`
already knew how to decide that (§4ac).

`lib/site_chrome.php` is the reader. `SiteChrome::accent()` answers `#rrggbb` or the documented
default, `Color::read()` having decided which; `tools/check_invariants.php` holds the
`BRAND_*` constants to that one file, so a page naming one is a page that has gone
back to interpolating whatever is in the config.

**It also collected the defaults, which had been written out four times** — once each
in `login.php`, `help.php`, `builder.php` and `admin_panel.php`, agreeing only because
nobody had yet had a reason to change one. Those four blocks are gone; `SiteChrome::load()`
reads the config file, and `db_connect.php` calls it beside `lib/markup.php` for the
same reason it requires that: a page that forgot would be a fatal error on a live
screen.

**Not currently reachable through the form, which is why it is worth doing.** #21 made
the Branding form read every colour through `Color::read()` and refuse the save,
naming the field — so nothing an admin can type gets here. The other door is the one
this is about: `branding_config.php` is *generated*, its own header invites a person
to edit it, it predates the rule that validates the form, and a deployment upgraded
from before #21 may already hold whatever the old silent substitution wrote. Exactly
the shape §4ac exists for — the write path closed, the rows that were already there
not.

**A default here is not the #21 defect.** `DisplayAdmin` substituting a colour for one
an admin had just typed was a lie about a save. This substitutes nothing and saves
nothing: it reads a file that holds what it holds, and answers the documented default
— the same one a deployment with no config at all gets. And it is said out loud rather
than inferred, in two places. `ColorAudit` reports it under a kind of its own,
`WRONG_IN_APP`, because it is the only unreadable colour in the app that **no sign
uses** and a finding that read like the others would send somebody to the shop floor
over a navigation bar; and the Branding tab opens with a notice naming each field and
quoting what is stored.

`SiteChrome::pick()` and `SiteChrome::unreadable()` are pure, taking the stored value rather
than reading it, for the reason `layout_rules.php` is (§4o): `define()` cannot be
undone, so a rule reachable only through the constants could only ever be tested with
the one value the machine running the suite happens to hold.

**Coverage.** 21 checks in `tools/selftest_layout.php` on both engines — every
fallback path, each colour falling back to *its own* default rather than a shared one,
an unknown key throwing rather than answering, the CSS-injection string refused with a
companion check proving that escaping it would **not** have helped, and the audit
finding end to end. Plus the invariant, and the tool run against a hand-edited config
to see the sentence it actually prints.

**The same boundary, one level further in.** The Brand Standards preview put six
stored fields into a `style` **attribute** — `font-family`, `font_color` and four
more. Escaping stops a value ending the *attribute*; nothing stopped it ending the
*declaration* inside it, so a stored `Arial; position: fixed; top: 0` was, after
escaping, exactly that. Smaller than the `<style>` case — the blast radius is extra
declarations on one `<span>` in the Admin Panel, not a rule that escapes into the
page — and the same mistake.

`BrandStyles` already had the answer and was only ever asked on the way *in*. Its
`cleanFamily()` refuses a family whole rather than stripping it down, because
`Arial;position:fixed` edited into `Arialpositionfixed` would store a font nobody
asked for and say nothing. What was missing was a way to ask on the way *out*:
`BrandStyles::readable()` runs a row through the same six cleaners `save()` uses, and
`unrenderable()` lists the fields where the stored value and the drawn value differ,
so the tab names them instead of quietly drawing the substitute.

**Writing the "they agree" check found a disagreement**, which is the reason to write
it: `readable()` was passing `DEFAULTS['font_color']` (`#000000`, the schema column
default) as `cleanColor()`'s fallback, while `save()` used `cleanColor()`'s own
(`#ffffff`). The page would have drawn one and a save stored the other. The fix is the
#21 distinction again, and it is now load-bearing in a second place: **absent** is
answered by `DEFAULTS`, **unreadable** by the cleaner's own substitute, and they are
different questions.

`all()` stays raw, deliberately — `ColorAudit` reads it, and an audit whose source had
already been tidied would report nothing and be believed.

**Coverage.** 25 further checks: every field's clamp, both `<style>`-attribute
injections with a companion check proving escaping would not have helped, absent-vs-
unreadable, the numeric fields compared as numbers (a `DECIMAL(4,2)` comes back
`'1.20'` from MySQL and `1.2` from SQLite, and a difference of engine is not a fault to
put in front of an admin), a loop asserting reader and writer agree field by field, and
three cross-file checks on the page itself — because the defect was never in the module,
it was in a caller trusting a promise the module had only made about values it wrote.

**Verified by injection, four times.** `readable()` handing back the row fails 5;
`unrenderable()` going quiet fails 6; the page reaching into the row again fails by
name; and deleting the notice while keeping the loop that computes it fails too — the
check is on the render, because working out a list and not drawing it is the same page
with more code in it.

---

### 4aj. A basic publish may return root content, never invent it

The residual §4ab named and deferred, closed with the payload change it said the fix
needed. Ported from a branch retired as superseded — see "Branches closed without
merging" in [`docs/reviewed-decisions.md`](reviewed-decisions.md), which is also where
the finding that *every* superseded branch held something is written down.

ADR-0005 splits reach from power: a grant says *which* Displays, the role says *how
much*. A `basic` account fills sections; an admin places them. A block whose
`parent_temp_id` resolves to none of this Display's sections lands with `section_id
NULL`, and that is layout — so the role that may not place layout could place it, by
sending a block with no parent at all. §4ab's type allowlist closed the forged-`type`
route in. A plain `text` block with no parent still walked through.

**Why it was deferred rather than fixed then, and why that reasoning was right.** The
obvious fix — refuse any block whose parent does not resolve — breaks the honest case.
An admin puts the store's logo on the canvas outside every section; the clerk's Builder
loads that layout and resubmits it, logo included, because a publish sends what is on
screen. Refuse the unresolvable parent and that clerk can never publish again, on the
Display they are employed to keep current, for a block they did not add and cannot see
the problem with. The payload could not tell "the root block that was already here"
from "a root block I just made", so no check on it could be written.

**What changed is the payload, in one field.** Content now carries `db_id`, the way
sections always have — empty for a block `createBlock()` just made, the row id for one
that came out of the database. A basic publish then accepts a root block only when that
id is a root row this Display has *right now*, and refuses the publish whole otherwise.
Sections were already doing exactly this to stop a forged `db_id` parenting content into
another Display's section; content had simply never been asked.

Four things fall out of that, and each is a check:

- **Returning the same id twice is inventing one of them.** One stored row cannot be two
  blocks on a canvas, and letting the second through is this role duplicating layout.
- **A root id from another Display is refused.** Same shape as the section case, and it
  subsumes a test that used to pass for the wrong reason: a forged section `db_id` was
  *accepted*, with the block landing at root on the publisher's own Display. Scoping
  held, which is all that check proved — but a basic account had placed layout. One
  refusal covers both halves now.
- **Sending nothing for a root row still deletes it.** This is the half the rejected
  alternative — preserve every root row the payload does not mention — would have
  broken, and broken silently: a delete that reports success and changes nothing. There
  is no undo here, so a write that lies about what it did is worse than a refusal.
- **A returned root row keeps its id.** Publishing replaces content wholesale, so
  without this every id in the Builder's hand goes stale the moment a publish succeeds,
  and the very next publish from that same tab is refused for returning a root block
  that no longer exists by id. Publishing twice without reloading is ordinary work.
  `insertContent()` takes the proven ids and re-inserts those rows under the id they
  had; an explicit `id` of `NULL` is how both engines say "assign one", so it is the
  same statement for every other block, and an admin publish passes no ids at all.

**Decided inside the transaction, unlike §4ab's other refusals.** A type or a number is
a property of the payload and is settled before `beginTransaction()`. This one depends
on rows another publish can change, so it belongs under the same row lock as the
staleness check — otherwise two clerks publishing at once could each be told their root
block was fine. Nothing is written when it refuses: the check runs before its own
`DELETE`, and the stamp does not move.

`sectionIdFor()` exists so the check and the insert cannot disagree about what "root
level" means. One resolving a parent the other did not would refuse a block that was
about to be parented correctly, or admit one that was not — and it would look like two
correct functions.

**Fifteen checks**, including the admin's root block being placed and kept, the clerk
sending it back and it keeping its id, publishing twice from one tab, the invented
block refused with nothing deleted and the stamp unmoved, the doubled id, the other
Display's id, and the leave-it-out delete really deleting. Three existing checks changed
their payloads rather than their assertions: `basicLayoutFor()` gives a clerk's layout a
real section `db_id`, which is what a clerk's Builder actually sends, and the admin
shape `layoutWith()` stays as it was.

---

### 4ak. Two clicks, two answers, and the wrong one was the loud one

Decision `#39`. Publish had no in-flight guard, so two clicks were two requests —
and both carried `LAYOUT_STAMP` as it stood when the *first* was assembled, because
the second was built before any reply could update it. The server takes the row
lock, commits the first, and refuses the second as stale (ADR-0006).

That refusal is correct and must stay correct. From the server, a second publish on
a spent stamp is indistinguishable from a colleague's, and the alternative —
guessing — is guessing about somebody's work in an app with no undo. What was wrong
was upstream of it: the page raised **both answers at once**, a green *Published to
Deli Board* toast and a modal alert saying the sign had changed underneath them and
this page should be reloaded. Both were about their own click. The alert is the one
that gets acted on, because it is the one that blocks, and reloading is precisely
what throws away everything on the canvas that had not been published.

**The guard goes where the duplicate can be recognised**, which is the only place it
can be: the tab that sent both requests. `publishInFlight` is raised after the last
refusal that returns without sending — a background image over the server's limit
must not leave Publish dead for the session — and dropped by `endPublish()` on every
ending. The button is disabled and reads *Publishing…* while it runs, which is what
actually prevents the second click; the flag is what catches the one that happens
anyway, and the second click gets a plain toast rather than a red one, because they
asked for something that is already happening.

Three details that are decisions rather than mechanics:

- **The stamp is adopted before the guard comes off.** Otherwise the click landing
  the instant Publish is usable again would carry the stamp this publish just
  replaced — the same false conflict, one line later.
- **`endPublish()` has no latch.** Both endings can arrive for one request, so it is
  written as assignments rather than toggles and costs nothing when called twice.
  A latch would be a line no test could fail on, which is decision `#50`'s complaint
  about this suite, and it was caught by exactly that: the mutation removing it
  killed nothing.
- **`.catch()` no longer says "Network error."** Two endings reach it and neither is
  only a dropped connection: the other is `r.json()` rejecting, which is what a reply
  with anything printed above the JSON does — the §4n failure, on the one path still
  using `fetch`. Neither knows whether the publish landed, so the message says to
  check the sign rather than claiming either way. It also returns early when the
  reply was already acted on: a throw in the success branch would otherwise print a
  connection failure over the green toast it had just written, which is this very
  defect wearing a different hat.

**Twenty-six checks** across the two node suites (23 + 3), and nine deliberate
mutations, all nine killed (4, 3, 1, 2, 1, 2, 1, 1, 1). The guard itself is invisible
to `php -l` and to `node --check` — a page with no guard parses perfectly — so these
run the handler with a second click landing mid-flight and read what is on screen
afterwards, which is the same premise `selftest_builder_uploads.js` was built on.
The read-only suite gets three of them because the guard hangs off a button that
page does not emit: an unguarded lookup in `setPublishBusy()` would be a TypeError
on the one publish path a read-only page still runs.

Left standing, and worth knowing:

- **Publishing while an upload is still in flight is not guarded.** An admin who
  picks an image and clicks Publish before it finishes publishes a block with no
  path in it. `uploadsInFlight` already counts them, so the guard is cheap — but it
  is a different defect from this one and belongs with whoever takes it.
- **Nothing cancels a publish.** The button is out of service until the request
  ends, and on shop Wi-Fi with a background image that can be minutes. Reloading the
  page is the only way out, and it costs the unpublished canvas.

---

### 4al. Six rough edges, and the third premise a Builder page can be under

Decision `#42` was one row on the list holding six unrelated complaints about the
Builder. They are unrelated on purpose — nobody thought them worth a row each — but
five of the six are the same *kind* of defect: **a control that quietly did less than
it said.** No error, no toast, no red anywhere; the thing simply did not happen, and
whoever was laying out a sign concluded they had misunderstood the button.

**Fit could not fit a large canvas.** `zoomToFit()` computed the zoom that shows the
whole canvas and handed it to `applyZoom()`, which floored every zoom at `ZOOM_MIN`
— 10%. A 20000-wide canvas in a 1000-wide frame needs 4.6%, so the button clamped to
10%, left two thirds of the canvas off-screen and reported nothing. The floor now
gives way to exactly one thing:

```js
function zoomFloor() { return Math.min(ZOOM_MIN, fitZoom()); }
```

so zooming out by hand also reaches the fit and stops there, and `nudgeZoom` needs no
special case. `fitZoom()` picked up a guard while it was being split out: a frame
measured before the browser has laid the page out reports `clientWidth` 0, which used
to give a negative zoom — `scale(-0.02)` is a mirrored canvas, and it says why no more
clearly than `scale(0)` does.

**How small a section could be depended on the zoom.** Sections carried
`interact.modifiers.restrictSize({min:{width:100,height:60}})`, which interact.js
enforces in screen pixels. Everything else in `handleResize` divides by `ZOOM`; that
one line did not, so the floor was 200 canvas px at 50% and 50 at 200%. The modifier
is gone and `handleResize` enforces `BLOCK_MIN` after the divide, which also gave the
root and child blocks the floor they never had. Two details that are not obvious:
`applyDim()` reads the same table, because typing 10 into W and dragging the edge as
far as it goes were answering differently; and an axis that has stopped shrinking now
stops *moving* too, or dragging the left edge past the minimum slides the block right
across the canvas while its width sits still. This is now invariant 24.

**A hidden section could not be brought back.** Hiding lives on
`canvas_elements.hidden` and the Work Area's Show/Hide writes it directly. In the
Builder, a hidden *block* got a fade and a HIDDEN badge; a hidden *section* got the
fade alone, which reads as a rendering quirk rather than as something somebody
decided — and the Builder offered no way to change either. Both halves are fixed by
one function: `applyHiddenLook(block)` puts the class and the badge in step with
`dataset.hidden`, and `renderSection()`, `renderBlock()` and the new inspector box all
go through it. The box is admin-only, matching the Work Area, because two doors onto
one column should not disagree about who may open them. It writes nothing — the change
rides out on the next publish, like everything else on that canvas — so `publish()`
carrying `hidden` for a section *and* for a block, in both directions, is now checked
in `selftest_layout.php` rather than assumed.

**"Restore" restored nothing.** A carousel slide's Title, Price and Description each
have a Delete beside them, which sets the field to the stored `null` a sign reads as
"this slide has no title". The button then relabels itself `+ Restore` — and restoring
handed back an empty box, because deleting had done `inp.value = ''` and kept no copy.
In an app whose first rule was that **no undo exists anywhere** — §4an has since carved
out the one exception, and it does not reach inside this modal — a control that offers
to put something back has to actually have it; the value is stashed on the node and
read back. A field that *arrived* deleted still restores empty, which is not a loss —
there is nothing behind it.

**Marquee "Transparent" ate the colour.** `md.bg` is what the sign reads and
`'transparent'` is not a colour, so ticking the box overwrote the chosen one. Untick
it in the same sitting and the picker still held it; reopen the block, or reload, and
both the picker and the next publish were the factory red. The colour is kept beside
it as `md.bgColor` — which the Viewer and the publish never look at — and the picker
reads from there, so Transparent is a state the block is in rather than a thing it
forgets. A marquee saved before this change has no `bgColor` and opens on its own `bg`.

**The sixth is dead code**, and it was doing work: the remains of a WYSIWYG format bar
that ADR-0002 settled against. `fmtCmd()` was called by nothing, but `trackSelection()`
— which exists only to fill the variable `fmtCmd()` read — was still registered on
`document`'s `selectionchange`, firing on every caret movement anywhere on the page to
write a value nothing would ever read. That, `FONT_FAMILIES`, `#wysiwyg-bar` and
`.fmt-btn` are out. The last `document.execCommand` in the file went with them, which
is the one that could put markup into a text block at all.

#### A third node suite

`tools/selftest_builder_editing.js` is new, and the naming is the point: the two
existing suites are named for what they hold the page to — a page that may **not**
edit, and an upload that goes **wrong** — and none of these six lives under either
premise. This one runs the Builder on an ordinary good day and asks whether the
controls mean what they say. Folding it into `selftest_builder_uploads.js` would have
put zoom arithmetic and slide fields under a name that claims neither.

Its DOM is hand-written rather than derived from the markup, unlike §4w's, and for a
reason worth stating: §4w's derivation answers *which ids a page emits*, which matters
when you are asserting absence. Nothing here asserts absence — everything is present
by premise. What this DOM does need, and the uploads suite's does not, is to **work**:
`classList` really adds and removes, `appendChild` really appends, and `querySelector`
really finds `:scope > .hidden-badge` among a node's children. A no-op `classList`
would have made every `applyHiddenLook` check pass without the function existing.

Two seams are named in the file's own header rather than left to be discovered. Slide
rows are built by assigning a string to `innerHTML`, which this DOM does not parse, so
the row markup is checked as the string `addSlideRow()` really emits and the
delete/restore/save round trip is driven over nodes built to match those three claims.
And `describe()` exists because `JSON.stringify` throws on a DOM node — several checks
compare a node against null, and a failing check that cannot print its own failure
takes the suite down with a stack trace instead of naming what broke.

**86 checks** in the new suite, **six more** in `selftest_layout.php` (854 → 860), and
**eighteen deliberate mutations, all eighteen killed** (6, 2, 1, 4, 9, 1, 11, 1, 4, 1,
1, 1, 1, 4, 1, 4, 3, 1). Three are worth naming because they are the defects
themselves: clamping Fit back to the 10% floor kills 6; comparing the resize minimum
before the divide kills 4; and letting Transparent overwrite the colour again kills 4.

Left standing, and deliberately:

- **A basic account cannot unhide its own block.** The visibility box is admin-only,
  which follows `set_element_hidden` being admin-only, not a fresh judgement. If the
  owner wants a clerk to be able to un-hide what an admin hid, that is a change to
  who may hide, and it belongs on both doors at once.
- **The badge sits over a section's own label.** `.hidden-badge` spans the top edge at
  `z-index:50` and `.section-label` is at 5. Being hidden is the more important fact,
  and a section is not identified by that label.
- **Nothing warns before a publish carries a hidden section.** Publishing a layout
  where a whole section is hidden takes it off the Screens with the same green message
  as any other publish. That is consistent with everything else on the canvas, and
  `#19`'s decision about mid-edit warnings is where a change would start.
- **`interact.js` is still un-run by any suite.** The drag and resize *listeners* are
  now driven directly with the event shape interact.js passes; that it passes that
  shape, and that dropping `restrictSize` changed nothing else, is a browser check.

### 4am. Two files nothing was really standing over (#49)

Decision #49 was measured, not asserted: `lib/plain_text.php` and `lib/schema.php`
were mutation-tested line by line, and the numbers are why the item existed.

| | before | after |
|---|---|---|
| `lib/plain_text.php` | 2 of 17 killed | **17 of 17** (and 26 of 26 once the bug below was fixed) |
| `lib/schema.php` | 43 of 67 killed | **65 of 67** |

Both survivor counts hide the same shape: the checks that existed were about *outcomes
somebody had thought about*, and the lines nobody had thought about could be deleted in
silence.

#### The sanitiser had one check on it, and it exercised one line

`toPlainText()` was six statements when it was measured, and every text block on every
sign goes through it —
`crud.php`, `AssetLibrary::saveEdit()` and `LayoutStore::publish()` all funnel in. The
only thing standing over it was a publish asserting that `<script>alert(1)</script>Hello`
stores as `alert(1)Hello`, which kills exactly one mutation: deleting `strip_tags`. The
other five statements — the two break rewrites, the entity decode, the trailing-space
trim, the blank-line collapse — could each be removed with the suite still green.

Two of them turned out to be load-bearing in a way nothing said:

- **The breaks are rewritten before the strip.** Delete the `<br>` line and `strip_tags`
  takes the break away with the tag: two prices run together on the sign, no error
  anywhere.
- **The entities are decoded after the strip, and it has to be that way round.** A
  browser sends a typed `<` back as `&lt;`, and `strip_tags` removes everything from a
  `<` to the end of the value when nothing closes it. Decode first and `Wings &lt;10
  pieces` reaches the sign as `Wings`. The check that pins this is the one that would
  have failed a reviewer's "surely the decode should come first, it's safer" — because
  it isn't; it loses the line.

The cost of that order is now written down rather than left to be found: markup that
*arrives* encoded decodes into text that looks like markup and is stored that way, and
it is inert only because every renderer draws stored text with `textContent`
(`viewer.php:502`, `builder.php:1495`) and never `innerHTML`. That is ADR-0002, and a
characterisation check states it, so a future renderer that forgets fails a test rather
than a sign.

#### What the schema numbers were hiding

43 of 67 sounds healthy, and the 24 that lived were not spread evenly — most were in the
four **steps**, which are the only part of convergence that touches rows rather than
structure, and therefore the only part that can lose somebody's data. What survived:

- **Either backfill's `WHERE` clause could be deleted.** Removing `WHERE display_id IS
  NULL` hands every element of every sign to the drive-thru Display — every other Screen
  blank at once, layouts gone rather than moved. Removing the `Auto: ` prefix test claims
  an admin's own uploads as pooled, which is Tidy up then offering to delete them.
- **The seed could be made to create a second drive-thru Display.** The count guard is
  "any Display exists", not "one called drive-thru exists", because the tag is the
  admin's to change (ADR-0003) — and a renamed tag is the one case where nothing but that
  count stands in the way. A `UNIQUE` index catches the other case, which is why the
  mutation lived.
- **The background could stop being carried forward.** `seedLegacyDisplay()` is the last
  reader of `canvas_settings` anywhere in the repo. Losing it means every Screen coming
  back from a deploy on the default navy instead of the store's wallpaper.
- **The report could stop being readable.** No sorted key (the same two failures in the
  other order becoming a second email inside the hour, from the alert whose whole purpose
  is not to do that), no 200-character cut on a message the driver chose the length of,
  no ten-item cap, and `1 schema updates`.
- **`need` could be tested for truthiness rather than for `true`.** The rule it protects —
  never report a guess — is only as good as the comparison, and `isset()` and `!empty()`
  both pass a plan the catalogue never backed.
- **Both levels of catalogue name-folding, and `IS_NULLABLE`.** MySQL reports names as
  they were declared, so a case-preserving host makes every column look absent if only
  the table name is folded. And a driver answering `yes` rather than `YES` reads a
  nullable `display_id` as already tightened — the tighten then never runs again, on the
  column every scoped query depends on.

**The deploy-day race is now produced rather than reasoned about.** The comment in the
self-test said the interleaving could not happen inside one process, and that was true of
PHP; it is not true of SQLite. A `BEFORE INSERT` trigger using `RAISE(FAIL)` aborts the
statement *without* undoing what the trigger program already did, so the row the "other
request" wrote survives and this request's insert throws — which is exactly the state the
catch block was written for. It must report success: the Display exists, which is all the
step was for, and a failure there is an email to an admin about two people signing in at
the same moment.

#### The two that lived, and why they stay

- **`WHERE auto_pooled = 0` in the pool backfill.** Removing it writes the same value to
  rows that already hold it. Equivalent by outcome; it narrows what is written, not what
  results.
- **`flock(LOCK_UN)` and `fclose()` at the end of `withSchemaRepairLock()`.** Removing
  them changes nothing, because the lock belongs to the open file and PHP releases it when
  the handle falls out of scope. That mutation surviving *is* the docblock being right —
  and that property is the reason `flock` was chosen over a stamp file, so both lines stay
  as the explicit form of something the runtime would do anyway.

One more is worth recording because of *how* it dies: making the repair lock blocking
(`LOCK_EX` without `LOCK_NB`) is caught by the suite never finishing, since it takes the
lock and then calls again in the same process. A hang rather than a red line, but not a
survivor.

#### The bug the coverage found, and the fix it got

Writing the characterisation check turned one up. `strip_tags` is **not a parser**: it
enters tag mode at any `<` that is not followed by whitespace, and with nothing to close
it, deletes the rest of the value. The Builder reads a text block with `innerText`, so a
typed `<` reaches the server literally — and `Kids <12 eat free` was stored as `Kids`.
Silently, on the way *into* the database, with nothing to see in the Builder, no error
anywhere, and no undo. It was recorded here as found-not-fixed for one commit, and then
fixed on the owner's say-so.

The fix is one statement before the strip: escape every `<` that HTML could not open a
tag with, and let the decode already at the end turn it back into a character.

```php
define('PLAIN_TEXT_NOT_A_TAG', '#<(?![a-zA-Z!/?][^<>]*>)#');
```

A `<` opens a tag only when a letter, `/`, `!` or `?` follows it **and** something closes
it before the next `<`. That second half matters as much as the first: `Sale <best value`
has a letter after the `<` and is still not a tag, because no tag spans the end of the
value. What reaches `strip_tags` afterwards is exactly what a browser would treat as
markup, so `<b>`, `</div>`, `<!-- -->`, `<?php`, `<B>` and an `<img>` full of quoted
attributes are all still taken away.

Rejected: **swapping the decode in front of the strip**, which is the fix that suggests
itself and is the one that breaks the sign — it hands `strip_tags` the very `&lt;` this
exists to keep away from it. And **an allow-list HTML parser**, which ADR-0002 already
turned down for the whole feature and which this does not need: the question here is not
*which* tags are safe, it is whether a thing is a tag at all.

Two other callers of `strip_tags` had the same defect one step further out, both on
already-plain text, and both now ask `toPlainText()` instead: the pooled row's auto label
(`Kids <12 eat free` was filed in the Library as *"Auto: Kids "*, losing the only clue to
which block it came from) and `crud.php`'s 40-character asset preview, which showed a
line the sign did not. `strip_tags()` is now called in exactly one place in the repo, and
§5 has the grep that keeps it that way.

**9 further mutations, all 9 killed** — including the two that needed the boundary put
under load: `[^<>]*` widened to `.*`, which lets the search for a closing `>` run past
the next `<` (caught by `Sale <best and <b>bold</b>`), and dropping the upper-case half of
the tag-name class (caught by `<B>OPEN</B>`). `crud.php` is a page and has no harness;
its change is the same one-word substitution as `assets.php`, which does.

Left standing:

- **`lib/schema.php`'s statements are still MySQL-only.** No SQLite fixture can execute an
  `ALTER TABLE … MODIFY COLUMN`, so none of the above reaches them; what does is #48's
  second leg, which runs the same suite against a real MySQL (§4aa) and asserts
  convergence has nothing left to do against a database built from `schema.sql`. That is
  the property, mechanised — but it is #48's mechanism, not a mutant of this file's.
- **The mutation runs are not automated.** They were done by hand, one file at a time,
  and the numbers above are a record of a measurement rather than something a future
  change re-runs by itself. Decision #50 is where that belongs.

---

### 4an. The first undo this app has ever had

The rule at the top of `CLAUDE.md` used to read *no undo exists anywhere in this
app*, and everything built since has honoured it by **refusing** rather than
reversing: a moved layout stamp refuses a publish, an edit lock refuses a second
editor, a wrong-shaped value is refused rather than coerced. That is the right
answer when the danger is two people's work colliding. It is no answer at all to the
ordinary case — one person, alone, who drags a price off a section or deletes the
wrong block. The only recourse was reloading the Builder, which throws away
everything unpublished, or rebuilding by hand. Hide is popular partly for this
reason: it has been the only reversible removal in the app.

**What was built** (ADR-0010) is deliberately the small one: a stack of canvas
snapshots in the editor's browser tab, taken back a step at a time, with an Undo
button and Ctrl+Z. Nothing on the server, no schema, no history of publishes. The
depth is an admin setting — Settings → Builder Undo, default 5, range 0–20, read
through `undoStepsSetting()` in `config.php` so the Builder and the settings form
cannot disagree about what a stored value means. `0` removes the button, the shortcut
*and* the snapshots.

It is stored as the **ninth** entry in `BrandingConfig::DEFAULTS`, which is where a
generated setting has to live rather than where it happens to fit: that module is the
only writer of `branding_config.php` and rewrites the file whole from its own list, so
a `define('UNDO_STEPS', …)` declared anywhere else would be dropped the next time
somebody saved the Branding form — value gone, form reporting *"Settings saved."* The
branch this came from wrote the file from a nine-argument `writeBrandingConfig()` on
the admin page, which #36 has since replaced (§4y); porting the setting meant porting
it into the module, not beside it. `UNDO_STEPS_MAX` stays a `define()` in `config.php`
because it is not an opinion about this store, and `check_invariants.php`'s echo
classifier learned to resolve a numeric `define()` there for the same reason it
already resolved a numeric class constant — the declaration is a literal number, and
it is read rather than assumed.

`undoStepsSetting()` takes the stored value as a **parameter** with the constant as
its default, which is §4o's argument in its smallest form: reading the global directly
means the clamp can only ever be tested against whatever the test process happens to
hold, and `500`, `-1` and `five` are exactly the values a running installation is not
in. Nine checks cover them. Both callers use the no-argument form.

Three decisions carry the weight, and each of them is the answer to a way an undo
goes quietly wrong.

**A step is measured, not announced.** `commitUndoStep()` snapshots the canvas and
compares it against the last committed snapshot; identical means nothing is kept.
The obvious design — capture the state *before* each change — fails twice over.
Clicking Align Left on a block already at the left would spend a step, so the next
Undo does nothing visible, which is the fastest way to teach somebody a button is
broken. And a call site somebody forgets would drop that change from the history
altogether; measuring afterwards folds it into the following step instead — still
wrong, but recoverable and visible rather than absent.

**A control the person is operating commits on `onchange`; the code commits at the
end of the function that changed something.** `updateStyle()` is reached from a
select's `onchange` and from a colour picker's `oninput`, so it records nothing
itself and the markup carries `onchange="commitUndoStep()"` alongside. That one
split is why dragging the swatch is one step rather than forty, and why typing a
price is one step rather than one per character — the browser already knows when an
edit is finished, and `blur` on a text block is exactly that moment. Ctrl+Z inside a
text block or a form field is left to the browser, working a character at a time;
only once the caret leaves does the Builder's own Undo take the whole edit back.
The keyboard handler was pulled out of `setupCanvas()` into a named
`handleBuilderKeydown()` on the way past, because a listener handed straight to
`addEventListener` cannot be run by the suite that would catch it breaking.

**Restore goes back through the renderer.** `restoreCanvas()` rebuilds with
`renderSection()` and `renderBlock()` — the same pair `loadLayout()` uses — so there
is one idea of how an element becomes a node and a block type added later is
restorable the day it is added. Both now return the node they made, which is how a
child block finds its section without a DOM lookup: a section created in this
session has no database id to be looked up by.

The serialization that both halves need was two loops inside `publishCanvas()`. It
came out as `blockContent()` / `serializeSection()` / `serializeBlock()` /
`serializeCanvas()`, because a second copy would have been two ideas of what a block
is, drifting apart one block type at a time, with the sign showing whichever one
publish happened to hold. A snapshot is that payload plus two fields the server never
sees: `snap_content`, because publish sends no content for a block linked to a
library entry and a restore still has to put the words back on the screen, and
`snap_manual_path`, because `renderBlock()` reconstructs an image from its path and
fit alone — so without it a restored upload looks identical and publishes as a file
the library was never told about.

**Verification.** `tools/selftest_builder_undo.js` is the fifth harness over
builder.php's inline JavaScript, and the fifth premise: the last thing they did was
not what they meant. **110 checks**, and its DOM is the editing suite's with three
additions the round trip needs — `offsetWidth`/`offsetHeight` read back from
`style`, `innerText` and `textContent` as one text, and a `dataset` that stores
strings the way a browser's does. All three are load-bearing rather than tidiness:
without the first, every serialized width is `0` and the round trip compares two
canvases of nothing; without the third, a snapshot that recorded `7` never compares
equal to the page's later `'7'`.

The central check is a round trip — snapshot, change every kind of thing, restore,
snapshot again, and the two **whole strings** must match. Whole strings on purpose:
a check that names the fields it cares about goes on passing the day a field is
added. **Twenty-one deliberate mutations, all twenty-one killed.** Three of them found real
defects rather than confirming the code, and all three are in the file now:

- `undoRestoring` was raised *after* `deselectAll()` rather than before it.
  `deselectAll()` blurs the text block that had focus, and a blur is where a text
  edit becomes a step — so one press of Undo on an uncommitted edit would record a
  step on its way to taking one back, then pop the step it had just created instead
  of the one it restored, leaving the stack claiming something that no longer
  described anything.
- `restoreCanvas()` ended with a `setupInteract()` call that could not fail. interact.js
  binds by CSS selector, not by node, so the restored blocks were already draggable —
  the same reason `createSection()` has never needed to call it. It is gone, with the
  reasoning in its place, because a line no test can fail on is decision #50's
  complaint.
- **A restored block did not know which row it came out of.** `renderBlock()` reads
  the database id as `el.id` and a snapshot spells it `db_id`, so putting a block back
  through the same function every other path uses dropped it — the one place the two
  names had to be reconciled, and the one that was not. The section branch did it and
  the block branch did not. What that costs is not cosmetic: a basic account may
  *return* root content and may not place any (§4aj), so their next publish would be
  refused, naming a placement they never made; for an admin it is a row number changing
  under whoever else is reading it. Found by giving a fixture block an id, which is
  what makes the whole-string round trip able to see it at all.

Left standing, and deliberately:

- **The canvas background is not covered.** An uploaded one lives in a
  `<input type="file">` no snapshot can put back. Restoring the colour but not the
  picture would be an undo that lies about what it undid, so it says plainly that the
  background is out of scope — asserted by the suite, so adding it later means
  amending this rather than discovering it.
- **There is no redo.** An Undo pressed once too often is itself irreversible, which
  is a small version of the problem this whole section is about. It was left out for
  scope, not because it is wrong; ADR-0010 says so.
- **The history ends with the page.** A reload re-reads the layout from the server,
  so the steps no longer describe anything that happened, and offering them would be
  offering to paste an old canvas over a sign somebody else may have published to.
- **A publish still cannot be taken back.** That is the feature that actually answers
  "publishing overwrites", and it is a much larger one — a table with its gate, an
  interaction with the stamp and the lock, and the trap that `LayoutStore::publish()`
  sweeps the pooled asset rows an old layout referenced, so a snapshot stored by asset
  id would restore into blank blocks. ADR-0010 records it as deferred, not rejected.

---

### 4ao. The two writes no sign scopes (#33)

Every access decision in this app is the same decision: *may this account have this
Display?* That is ADR-0005's whole point, and it is why the enforcement lives in one
seam — `DisplayRequest` resolves the Display, `Actor::mayOpen()` answers, and an
endpoint added later inherits the check by resolving its Display the same way
(invariant 8). The reasoning holds for as long as every write is *about* a Display.

Two are not.

- **The asset library** is one pool behind every sign. `crud.php` creates entries in
  it, and the page has been "all roles can access; delete is admin-only below" since
  before Displays existed.
- **`uploads/`** is one folder behind every library entry, and `api.php`'s
  `upload_file` is "images – all roles" for the same reason.

Neither names a Display, so neither goes through the seam, so neither was checking
anything at all. A `basic` account with no grant could add entries the whole building's
signs draw from, and put files on the server that nothing ever removes — one screen
after the Builder had told it, in as many words, *"No displays have been assigned to
you yet."* That picker page even links to the Library, which is how the dead end got
built: the link was courtesy, and what it led to was the one page where a person with
no sign could still change what the shop shows.

Worth being precise about the severity, because it is not "an outsider can write to the
sign". Everyone this is about is a signed-in member of staff, and nothing they add
reaches a Screen until somebody who *does* hold that sign places it and publishes. What
they could do is fill a shared workspace with rows an admin has to scroll past, and
leave files in a folder with no sweep behind it — and they could do it from an account
that had been told it holds nothing.

**The rule is one predicate and one sentence.** `Actor::holdsASign()` is the
predicate — it lives beside `mayEdit()`, `mayOpen()`, `openable()` and `granted()`,
because the docblock on that class promises every "may they?" question is a method
there, and a second copy of this one in a page script is how two doors come to disagree.
`Actor::NO_SIGN_REFUSAL` is the sentence, in one place because both doors refuse for
the same reason and one refusal met in two wordings reads as two problems. It names
what did not happen and who to ask, since there is nothing the person at the keyboard
can do about it themselves.

**The grant axis alone, deliberately not `openable()`.** This is the decision in the
change worth disagreeing with, so here is the case. `openable()` is the app's usual
predicate and it folds in a second axis: a Display switched off is not openable by a
`basic` account. Gating on it would mean turning a sign off for the afternoon also, and
silently, took its clerk's access to the library on a different page — a change of
*reach* with a consequence nobody enumerated, which is the family of defect §4s and
§4t are both about. It would also make the refusal wrong: the sentence says *no display
has been assigned to you*, which is true of everybody `holdsASign()` refuses, and a lie
to somebody whose one sign is merely out of service. And there is a working reason —
getting next week's promo into the library while the sign it is for is off is ordinary.
So: a grant is a sign, retired or not. That is the same distinction `granted()` versus
`openable()` was added for in Phase 4, used here for the first time outside the picker.

The predicate takes the Display list rather than reading the grant ids directly,
because a grant row is a permission only while the Display it names still exists. The
foreign key's `ON DELETE CASCADE` should make those two impossible to separate — the
self-test's fixture enforces it, and a stranded row cannot be inserted through
`GrantStore` at all — but that constraint is added by convergence and invariant 10 says
assume nothing about the live table. Asking against the list costs one query and makes
the answer true either way.

**Admins are true whatever the list holds, including empty.** They hold every Display
by role, and the one case where that differs from "the list is not empty" is a fresh
installation with no Displays yet — where the admin is the person about to add the
first one, and refusing them the library on the way in would be this rule aimed at
nobody.

#### Where the check is, and where it is not

Both doors ask **before** any file is moved. `move_uploaded_file()` cannot be rolled
back, so a gate below it leaves exactly what this exists to prevent, minus the row —
and that ordering is asserted, per door, from where the door opens rather than from the
top of the file. api.php moves an uploaded *background* three hundred lines above this
endpoint, and that upload is an admin's and is scoped to a Display, so a check
measuring against the file's first `move_uploaded_file` would be asking about the wrong
one. Writing that check also caught it being wrong about itself: both doors carry a
comment explaining that the move cannot be undone, sitting above the gate the comment
is about, so measured against raw source a correctly ordered file failed. It strips
comments first now, for the same reason `check_invariants.php` does.

`api.php`'s gate is an endpoint's own `if`, which is the shape invariant 8 warns
against. It is allowed here precisely because the question is about the account and not
about a sign: there is no Display to resolve, so there is nothing for the seam to
answer. The warning stands undiminished for anything that *does* name a Display.

The two doors also stop *drawing* what they will refuse — the Library shows an
explanation where the add form was, which is invariant 3's rule about a read-only
Builder applied one page over. **That is not the check.** A POST need not come from a
form this app rendered, and invariant 8's second paragraph is explicit that access is
never enforced by something merely being absent from a page. The form's absence is
courtesy; the refusal in the create branch is enforcement.

Reads are left alone, and that is a decision rather than an oversight. The library page
still lists everything, and `get_assets` still answers. Everyone concerned is signed-in
staff who may be asked to look something up, and a page that will not say what is in it
cannot explain what it just refused. The audit item is about writes; so is the fix.

#### One thing found next door

`crud.php` built its edit form for anybody who typed `?edit_id=`, though the save has
been admin-only throughout — a form whose only purpose was to be refused. It became
load-bearing here, because that same variable decides which panel the page draws: the
"no display assigned" notice was one query parameter away from being replaced by an
editor. It is `isAdmin() && isset($_GET['edit_id'])` now, which is §4j's rule again —
don't send a control to somebody who may not use it.
### 4ap. Three clocks, and the one a person was reading (#44)

Decision #44, in the words it was filed in: nothing set a timezone, so "editing since
2:15pm" followed whatever the host's `php.ini` happened to say. The store is in
Washington, and what the banner said was **4:15pm**.

**This paragraph was wrong when it was first written, and the correction is the more
interesting half.** It said the live host set nothing, so PHP fell back to UTC, so the
banner read 9:15pm — seven or eight hours out. That was an assertion about a machine
nobody had looked at, which is the failure #51 is a monument to, made again in the write-up
of a different item. It has now been looked at:

> **Observed 2026-08-11**, on Settings → This Server in the `lbm-test/` install:
> PHP **8.2.33**, MySQL **5.7.23-23**, **Server time zone `America/Chicago`**.
> `America/Chicago` cannot be a fallback — PHP's fallback for an unset `date.timezone`
> is UTC — so the host sets it explicitly. Nothing in this repo does: the tracked
> `.htaccess` sets four session flags and no `date.` value, and there is no `.user.ini`.

So the error was **two hours**, Central for Pacific, and that is worse to have shipped
rather than better. Seven hours is obviously broken and somebody reports it on the first
afternoon. Two hours reads like a colleague who genuinely started at 4:15pm — which is
the whole reason a wrong clock is a different kind of defect from a wrong colour, and
was already the argument for refusing an unusable zone rather than substituting one.
The magnitude was wrong in the write-up; the reasoning it was supporting was not.

"Set a timezone" sounds like one line. It was not, because there were three clocks and
only one of them was PHP's:

| Clock | What used it | What it actually was |
|-------|--------------|----------------------|
| PHP's process zone | every `date()` that printed a moment for a person | `America/Chicago`, set by the host |
| MySQL's session zone | `CURRENT_TIMESTAMP`, and every `TIMESTAMP` column on read | never set, so the host's system zone — same machine, so Central too |
| the store's own | the person standing next to the sign | nowhere in the repo |

**And the two being the same zone is what hid the second defect.** Those middle two
columns agreeing is not a coincidence — one machine, one system zone — and it meant the
missing `' UTC'` in `lastPublishDescription()` (below) cancelled the `CURRENT_TIMESTAMP`
frame *exactly*. A stamp MySQL wrote as `16:15` Central was read by PHP as `16:15`
Central and printed as `4:15pm`: the right Central time, by two errors that annihilated.
So that sentence was wrong by the same two hours as every other one, and no worse — it
was **latent**, not active.

What activates it is a change to either clock. Which is to say: the obvious one-line
version of this fix — set the process zone, or ask the connection for UTC — would have
turned a uniform two-hour error into a five-hour one in that one sentence, and only in
that one sentence. `SET time_zone = '+00:00'` on its own makes MySQL write `21:15` while
PHP still reads it as Central. That is the clearest possible statement of why "there were
three clocks" is the finding rather than a framing: fixing one of them is not a partial
fix, it is a new bug.

**What was already right, and why it is the reason this was safe.** Every moment *PHP*
writes has been UTC since §4t and §4v — `gmdate()` in, `strtotime($s . ' UTC')` out —
because local wall-clock is not monotonic and the autumn fall-back replays an hour.
That work is what made a store zone introducible at all: nothing in this app compares
two moments in the zone this setting names, so changing it cannot move a lock window,
expire a lockout early or lengthen one. The only thing it changes is a sentence. The
suite already asserted that property, from the other direction: two sections set the
process zone to `America/Los_Angeles` and assert the storage is absolute anyway.

#### The setting

A tenth entry in `BrandingConfig::DEFAULTS`, `STORE_TIMEZONE`, for the reason the
ninth is there (§4y, ADR-0010): that module is the only writer of
`branding_config.php`, and a `define()` of this name anywhere else would be dropped
the next time somebody saved the Branding form — the value gone, the form reporting
success.

It is on the **Settings** tab rather than beside the four colour pickers, and that is
a departure from the decision's own wording ("a store timezone setting on the Branding
page") worth stating rather than hiding. Both tabs write the same file; what separates
them is that Branding is four `type=color` inputs and a logo, and the two settings
that are neither — the undo depth and now the zone — are on Settings. It also puts the
control three inches above the card that reports the server's clock and the database's,
which is where somebody who has noticed a wrong time will actually be looking.

`StoreClock` in `lib/store_clock.php` is what the string means, and it is shaped like
`lib/site_chrome.php` on purpose — same problem, same file, same one-way door for a bad
value:

- **A fixed offset is not a timezone.** `+08:00` and `PST` both construct a perfectly
  valid `DateTimeZone`, and both are wrong for half the year — which is #44 again with
  a smaller error bar. So the accepted set is the identifiers PHP *lists*, which are
  region names and nothing else, because a name is the only thing that knows when
  daylight saving starts. `US/Pacific` and `EST` are casualties of that rule: they
  work in PHP and are refused here.
- **Refused, not substituted.** The form is a `<select>` of those identifiers, so
  nothing an admin can submit reaches the refusal — the same shape as the colour rule
  after #21, where the only remaining door is a hand-edited generated file. A value
  that arrives that way is *named*, on the Settings tab and on the This Server card,
  together with what is being used instead. It matters more here than for a colour:
  a sign shows a wrong colour, and a clock two hours out shows a perfectly ordinary
  time — which is exactly what the host turned out to be doing.
- **The default is not UTC.** A default of UTC would be "show every time in a zone the
  store is not in", which is the defect restated as a policy — the fact that the host
  turned out to be on Central rather than UTC changes the number and not that argument.
  `America/Los_Angeles` is the store's zone, is what the suite has called "the store's
  own zone" since §4t, and is the right answer for an installation that has never opened
  the page.

#### The half nobody had asked about: reading a stamp

The interesting defect was not the zone. It was that **the rule "a stored moment is
UTC" was written out three times, and one copy left the suffix off**:

```php
LockState::toEpoch()      strtotime($stamp . ' UTC')            // right
LoginAttempt::stamp()     strtotime($account[$key] . ' UTC')    // right
Display::lastPublishDescription()
                          date('M j \a\t g:ia', strtotime($at)) // wrong, silently
```

The third is the sentence a refused publish prints — *"sky, Aug 5 at 2:04pm"*, the one
thing telling an admin whose work they are about to walk over. It read a UTC stamp in the
process zone. And it was wrong at the *other* end too: `recordPublish()` wrote that stamp
with `CURRENT_TIMESTAMP`, so the value was in MySQL's session zone rather than PHP's.

On this host the two errors cancelled exactly, because both zones were Central — see the
observation at the top. That is what made it a **latent** defect rather than a visible
one, and latent is the harder kind to find: the sentence was wrong by the same two hours
as every other sentence, so nothing about it stood out, and it would have become five
hours out the moment anybody moved either clock. Which is exactly what this change does.

Neither engine could show it either. SQLite's `CURRENT_TIMESTAMP` is UTC *by definition*,
so the fixture always agreed with the reader whatever the process zone was; on MySQL the
suite runs wherever its host is set, which for a CI container is usually UTC as well. A
statement that is engine-independent is what made it assertable: `recordPublish()` binds
a PHP `gmdate()` now, the way every lock statement in that file already did and the way
its own comment already explained at length.

So the reading moved into one place, `StoreClock::epochOf()`, and invariant 28 is that
nothing else in the repo calls `strtotime()` at all. Two copies of a rule agree by
luck; three is where the third gets to be wrong on its own, and the two right ones are
what make it invisible.

The third frame is closed at the connection: `db_connect.php` asks for
`SET time_zone = '+00:00'`. It reaches the values PHP cannot write and therefore
cannot convert — the `created_at`/`updated_at` `TIMESTAMP` defaults — and it makes
"everything stored in this database is UTC" a whole sentence rather than nearly one. A
numeric offset and not `'UTC'`, because the named zones need MySQL's `mysql.time_zone`
tables loaded and a shared host may not have them. Existing `TIMESTAMP` rows are
unaffected: MySQL stores them as an instant and converts on read, so they start reading
correctly rather than stop. It is suppressed rather than fatal, and reported instead —
`ServerReport` prints the session zone the connection actually got, so a host that
refused it says so on a screen instead of being quietly back to three clocks.
`ErrorPolicy::report()` would have been the wrong channel: it would fire on every
request of every page for as long as the host refused it (invariant 20).

#### One migration, bounded the same way §4v's was

Every `last_published_at` already on the live database was written by MySQL in Central
and will now be read as UTC, so it reads **five hours early** (six in winter) until that
Display is next published — a known number now rather than "the host's offset", because
the frame those rows are in is the observation at the top of this write-up. Early rather
than late, which is the harmless direction: the sentence says a publish happened longer
ago than it did. It is the same shape as §4v's `locked_until` and is accepted for the same
reasons: the value appears in exactly one sentence, on a publish that was *refused*, one
publish replaces it, and nothing decides anything from it. The alternative was a schema
statement and a backfill, and a backfill needs the old frame to be a fact about every row
rather than about the host as it stands today.

`users.closed_at` needed nothing: it was already `gmdate()` in and read with the
suffix. `users.created_at` needed the connection change and nothing else.

The `SET time_zone` is unconditional, including on the path `api.php` serves to every
Screen every 30 seconds — the one place in this app where an extra statement per request
deserves a sentence. It is a session variable: no metadata lock, no I/O, nothing like
the DDL invariant 7 keeps off that path. Making it conditional on the caller would be
cheaper and would put the third clock back, in the form nothing can report, on the one
connection nobody ever looks at.

#### One clock deliberately left alone

The error log stamps its lines `gmdate('j M Y, H:i') . ' UTC'`, and it still does.
`error_policy.php` depends on nothing — no database, no session, no config — and that is
a stated property rather than an accident: it draws the last thing a request prints when
everything else has already failed. `StoreClock` reads a setting out of
`branding_config.php` through `BrandingConfig`, so routing the log through it would put a
file-read dependency in the one path that must not have any. A stamp that says which zone
it is in is honest, which is the whole of what #44 was about; a stamp that cannot be
written because the settings file was the thing that broke is not.

#### The setting cannot be seen by the request that saves it

A `define()` cannot be undone, so the ten constants are fixed at the top of the request
that writes the file. The Branding form deals with this by patching its own page
variables by hand — and that does not reach a *module* that reads the constant, which
`StoreClock::zone()` is. Left alone, saving a zone would have redrawn the dropdown
showing the new one and the This Server card three inches below it showing the old,
which is a page contradicting itself about the thing it was just used to change.

So a successful Settings save redirects — `flashMessage()` and
`Location: admin_panel.php?tab=settings`, the pattern the grant matrix already uses for
its own reason. A fresh request loads the file that was just written. F5 becoming
harmless is the other half of it, and comes free.

#### What This Server says now

The row that existed *because* a zone mismatch is otherwise invisible had to name all
three clocks, since there were three:

- **Store time zone** — the setting, and the time it is in the shop right now. First,
  because it is the question people actually have.
- **PHP time zone** — and its note changed direction. It used to warn that an unset
  `date.timezone` meant times next to an edit lock may be hours out. That is no longer
  true, and leaving it would send somebody after a problem the setting above has already
  answered. It now says the host is not set and that it is harmless, because the app sets
  its own. On this host the note does not render at all: `date.timezone` **is** set, and
  the row simply reads `America/Chicago`. Which is worth noticing — the row that reads
  like a plain fact is the one that was quietly deciding every time on every screen.
- **Database time zone** — new, and the clock no screen had ever shown. Anything other
  than a zero offset means the `SET time_zone` did not take, and the note says what
  that costs: a creation date a few hours out, and nothing a sign shows.

`SYSTEM` is not an answer, so it is expanded to `SYSTEM (…)`, and a non-MySQL engine
reads `not applicable` — SQLite has no session zone and every stamp it writes is UTC by
definition.

#### Coverage

**Sixty checks.** The ones worth naming are the ones that would have caught the
original defect, and they are all about a *disagreement* rather than a value:

- One instant, three zones. `2026-08-11 21:15:00` is `2:15pm` in the store and `4:15pm`
  in Central, which is what the Builder was printing; `9:15pm` in UTC is the third, kept
  because it is the widest gap and because the zone being a *parameter* is what makes any
  of these three reachable from a process holding one `define()`. The check that would
  have caught #44 is not any one of them — it is that they differ.
- The label does not move when the process clock does. Set the process to
  `Asia/Tokyo`, ask again, get `2:15pm`. That is what stops `viewer.php` — which loads
  neither `config.php` nor `auth.php` — from being a fourth clock the day somebody
  prints a time on it.
- Both stamp readings, run with the process zone on `America/Los_Angeles`, because on
  a server that happens to be on UTC every mutation in this area is invisible. That is
  exactly how the missing suffix survived: `check(abs(strtotime($stored) - time()) >
  3600)` is the defect written out as an assertion that it is still a defect.
- The default is asserted **not** to be UTC. The mutation that reintroduces this bug
  looks like caution.
- `lastPublishDescription()` on an unreadable stamp answers `sky`, not `sky, ` — a
  refusal reading short rather than reading wrong.

Two limits, named rather than dressed up. The Settings form's checks read
`admin_panel.php`'s **source**, which shows the lines are there and not that the page
behaves — the same weaker instrument §4v's CSRF checks use, and for the same reason: a
page that prints HTML and opens a real database is not reachable from a CLI suite. And
the `SET time_zone` statement is asserted to exist, not to have run, because the
fixture makes its own connection; what the MySQL run *can* say is that a bound
`gmdate()` publish stamp reads identically on both engines, which is the property the
old `CURRENT_TIMESTAMP` did not have.

#### One thing found and left for #50

`check_invariants.php` drops **PHP** comments before it greps and says so at length.
It does not drop **HTML** comments, which are `T_INLINE_HTML` and pass straight
through — so an `<!-- … -->` on a page explaining why a line no longer calls
`strtotime()` fails invariant 28 against the very sentence explaining why it holds.
The note in `admin_panel.php` is a PHP comment for that reason, with a line saying so.
Fixing the checker is #50's subject, not something to bundle into a rule it is meant to
be enforcing: `codeWithoutComments()` works on tokens, and stripping HTML comments
means handling PHP embedded inside one, which changes what every rule sees.

**Fixed in §4aq**, with the decision the note was waiting on: an HTML comment holding
PHP is code and stays.

---

### 4aq. Which of these checks can fail? (#50)

Decision #50 has two halves and one shape. The halves are *about 29 checks in the suite
could not fail* and *five invariants had no automated check at all*. The shape is that
neither can be settled by reading, and one of them cannot honestly be settled by hand
at all.

**The 29 was never going to be recountable.** It came out of the ten-agent audit (§4g),
when the suite was about two hundred checks; it is 1778 now, grown by twenty-odd
branches, and the two immediately before this one added 81 between them. A number
produced by reading a suite goes stale the week after it is produced — which is exactly
what happened, and is why the item sat open for a year with the note *"the 29 have not
been swept."* Any recount done the same way would be stale by the next merge. So the
deliverable here is not a corrected number. It is the instrument that produces one, on
demand, for the file you are about to change.

#### `tools/mutate.php`

Break one thing, run the suite, record whether anything noticed. §4am already did this
by hand for two files, found real gaps — either backfill's `WHERE` clause could be
deleted, the sanitiser's other five statements could each be removed with the suite
green — and closed with the honest admission that *"the mutation runs are not
automated… Decision #50 is where that belongs."* This is that.

```
php tools/mutate.php lib/grants.php        # break it 55 ways, 55 runs of the suite
php tools/mutate.php --list lib/color.php  # what it would break, without running
php tools/mutate.php --swept               # which modules have been swept, with the
                                           # section each was written up in, and what
                                           # is worth doing next. The ledger it prints
                                           # is by hand — a sweep leaves no artifact —
                                           # so the flag checks what it can: the module
                                           # still exists, the denominator is counted
                                           # from lib/, and every citation resolves
```

Every operator is a defect somebody could type rather than a mutation a paper would
list. In three families:

- **A comparison or a connective changed.** `===`→`==` and its four relatives,
  `<`→`<=` and its three, `&&`↔`||`, a `!` dropped, `true`↔`false`, an integer moved by
  one, and the bit-or that builds a flag set turned into a bit-and — because
  `ENT_QUOTES & ENT_SUBSTITUTE` is `0`, and `htmlspecialchars($v, 0)` leaves both quote
  characters alone.
- **Something removed.** A whole statement, a one-line guard (`if (!$ok) { return
  null; }` — the form this repo prefers), or a whole multi-line `if (…) { … }`. This is
  the family that finds a line nothing stands over, which is what #50 is about.
- **The scoping predicate dropped from a SQL `WHERE`**, either wholly or one `AND`
  conjunct at a time. The one operator that reaches inside a string, and it is here
  because the worst survivor #49 found was exactly that shape and no operator over PHP
  tokens can produce it: to PHP the entire statement is one string. A query that keeps
  `WHERE id = ?` and loses `AND display_id = ?` still returns a row, so it fails no test
  that only asks whether something came back.

Comments never reach any of them, because `token_get_all()` hands a comment back as one
token — so an `&&` inside one is not an operator, and `true` inside a string is not a
literal.

**A kill is graded, and only one grade is coverage.**

| | what it means |
|---|---|
| `assertion` | a check failed. The suite stands over this line's *behaviour*. |
| `diagnostic` | only a PHP warning failed it — usually an undefined variable, because the mutant deleted the line that set it. The harness noticed the mutant; no check knew what the line was for. |
| `count` | the count anchor failed and nothing else: a check *disappeared* rather than failed. |
| `fatal` / `hang` | the run died or had to be timed out. §4am records one hang on purpose — making the schema-repair lock blocking is caught by the suite never finishing. |
| `survived` | the finding. This line can be wrong and the suite will say it is fine. |

The grades matter because the four weak ones are easy to read as coverage. `lib/grants.php`
scores 52 of 57 killed, which sounds like a covered module; 32 of those are assertions and
20 are the harness noticing a variable go missing or the run die. `lib/display_request.php`
is starker: 37 of 43 killed, and only 13 of them by a check.

One limit on the grades, stated because it was seen: **the grade is the first failure, not
the worst one.** That is what makes a run cheap — `SELFTEST_STOP_ON_FAIL` leaves on the
first red line — and it means a mutant whose line is properly covered by an assertion is
reported as `diagnostic` if any warning fires earlier in the same run. It happened twice
while this section was being written, in runs launched four at a time against one temp
directory, and neither reproduced on its own. `survived` is unaffected: nothing failed at
all, in any order. If a grade is what you are deciding on, run the one file by itself and
re-check with `--only=N`.

**Why it is affordable.** The suite takes about ten seconds and a mutant needs one bit
off it, so `SELFTEST_STOP_ON_FAIL` (in `tools/test_fixture.php`) leaves on the first
failure. Most mutants then cost a fraction of a run and only the survivors — the ones
worth waiting for — pay in full. There is deliberately **no `--all`**: every `lib/` file
is thousands of runs and hours of them, and a report nobody reads is the same as no
report. It is a tool to run over what you changed, not a gate. The tree is copied into a
sandbox once and the mutants are written there, so the repo is never touched, and the
unmutated suite is run first and required to pass — a sandbox whose suite is already
failing grades every mutant as killed and reports perfect coverage, which is the mistake
this tool exists to find in other people's tests.

#### What it found

Nine modules, chosen by stake rather than by convenience. Every figure below is a run of
the tool **as it ships**, after the operator fix at the end of this section — the first
draft of this table was measured with a version whose `&&` operator silently generated
nothing, so it was thrown away and re-measured rather than adjusted.

| file | mutants | killed | survived | survivors before this pass |
|---|---|---|---|---|
| `lib/plain_text.php` | 11 | 11 | 0 | 0 — #49 had already done this one by hand |
| `lib/markup.php` | 4 | 4 | 0 | 0 |
| `lib/color.php` | 19 | 16 | **3** | 10 |
| `lib/grants.php` | 57 | 52 | **5** | 15 |
| `lib/store_clock.php` | 30 | 26 | **4** | 13, then 9 — see the second pass below |
| `lib/display_request.php` | 43 | 37 | **6** | 6 — all six equivalent |
| `lib/upload_limits.php` | 48 | 38 | **10** | 17 |
| `lib/http_reply.php` | 69 | 41 | **28** | 35 |
| `lib/layout_rules.php` | 208 | 155 | **53** | 64 |

Where a "before" figure is larger, the difference is the checks this pass added — and the
mutant count itself rises as checks are written, because a check written to kill a mutant
is a line the next run can break.

**`lib/store_clock.php` was measured twice, and the second run is the more useful
result.** The module landed a day before this tool did (§4ap), so the first run graded
checks written without it: 36 mutants, 9 surviving, the worst kill rate in the table at
75%. Four of those nine were in one line — a `!is_string($id) || $id === ''` guard in
front of `in_array($id, $zones, true)` — and none of them could be killed, because
**strict comparison already answers false for every shape the guard was refusing**. A
list, null, a float, `''`: the guard changed no answer for any of them. It was the same
rule written twice, which is this repo's most-repeated defect in its smallest form —
three copies of "a stored moment is UTC" (§4ap), four of the branding defaults (§4y) —
and the tell is exactly what the tool reported: neither statement can be tested while
the other stands.

Deleting the guard is the fix rather than a dodge, and the tool's own header is the
reason to be careful about saying so: three of #49's survivors were load-bearing. This
one is not, and the check that proves it is the one the deletion *makes possible*.
`in_array(true, $zones, false)` is **true** — every zone name is a non-empty string and
therefore casts to true — so with the guard gone, the `true` flag is the only thing
refusing a boolean, and `isZone(true)` fails the moment it is relaxed. With the guard
there, that mutant was masked and unkillable. **Five survivors closed by removing three
lines and adding two checks**, and the module is now 30 mutants, 26 killed, 4 surviving.

**The four that remain are equivalent, and provably so rather than plausibly.** All four
are `===` relaxed to `==`, and each was checked rather than argued:

| line | why it cannot be observed |
|---|---|
| `epochOf()`'s `$stamp === ''` | non-strings never reach it — `!is_string()` short-circuits first — and no PHP 8 string satisfies `== ''` but not `=== ''`. The guard itself is *not* redundant here and its mutants die: `strtotime(' UTC')` is **now**, so dropping it makes an empty stamp read as this moment. |
| `epochOf()`'s `$epoch === false` | `strtotime()` returns `int|false`, and the only int satisfying `== false` is 0 — which is what the true branch returns anyway. Both spellings answer 0. |
| `label()`'s `$epoch === 0` | `epochOf()` returns an int by construction, and for ints `== 0` and `=== 0` are the same test. |
| `labelForEpoch()`'s `$zone === null` | `==` would also take the "use the setting" branch for `''`, `0`, `false` and `[]` — and for all four, `pick()` answers `DEFAULT_ZONE`, which **is** the shipped setting. The two branches coincide for as long as the stored zone equals the default. This is the one that becomes testable the day a deployment sets a different zone, and it is worth knowing that a change to the setting is what turns this line from unobservable into checkable. |

That last row is the shape worth naming: not "no test covers it" but "the environment the
suite can construct cannot tell the branches apart". It is the same class as
`http_reply.php`'s header block below — unobservable by construction, not by omission —
and the honest response to both is a sentence rather than a check that cannot fail.

**`lib/http_reply.php`'s 28 are the most honest row in the table**, and they are not all
the same kind of thing. Eleven are loose-versus-strict comparisons that cannot differ at
the floor. Twelve are the block that actually sends the reply — `@http_response_code()`,
the `Content-Type`, the `Retry-After` on a 503, `noStore()` and the `headers_sent()`
guards around all of it — and those are **unobservable from a CLI suite by construction**:
`headers_sent()` is false there, `header()` is a no-op, and a check asserting that
`noStore()` returned true in CLI would itself be a check that cannot fail, which is the
thing this section exists to object to. They are named here instead. The remaining few are
a boundary constant nobody pins, `codeForResolution()`'s default for a resolution kind that
does not exist, and `subjectOf()`'s `&&` — which is a private helper feeding a throttle key.
That row is what "a module the tool cannot finish for you" looks like, and it stops here
rather than growing five checks written to move a number.

**`lib/layout_rules.php` is the opposite case: the biggest module and the most survivors,
and two of them were worth stopping for.** Of its 53, twenty-two are comparison
relaxations and sixteen are boundary constants nobody pins — `SIZE_MAX` moving from 20000
to 20001 is true and does not matter. But the publish validator caps five stored strings
against their column widths, and only `font_family` had a check: **`font_weight` and
`font_style` could each have lost their cap** with the suite green, which is a
five-thousand-character value reaching a `VARCHAR(20)` — a publish that fails outright on
a strict MySQL and truncates in silence on one that is not, which is the exact defect §4ab
wrote that table for. Two checks, in the same shape as `font_family`'s, verified against
mutants 95 and 96.

The rest of that module's survivors are named rather than closed, and the four worth a
future pass are `describeValue()`'s branches, the `!is_finite()` guard on a line height,
the `db_id` claim's three-part condition, and the `is_bool` guards in the two
`isTextLike`-style predicates. None of them is a wrong answer today; each is a line that
could become one without anything saying so.

Where two numbers appear, the second is after the checks written in this pass; the
mutant count rises because a check written to kill a mutant is itself a line the next
run can break, and falls once where an operator was corrected (below).

**The most instructive result is `lib/http_reply.php`'s status-code table**, because the
suite already had a loop over it. Fourteen checks, one per reason the app uses, of the
form `codeFor($reason, 0) !== 0` — *the map has an answer for this word*. Six of the
fourteen rows had nothing else standing over them, so `not_found`, `signed_out`,
`locked`, `busy`, `too_large` and `unencodable` could each be moved to a neighbouring
code with the suite green: a body too large to arrive answering 414, a session that ended
answering 404. **A loop over a table that asserts its keys reads from outside exactly like
one that asserts the table**, and this one had been read that way ever since it was
written — including by every branch that has added a row to the table since. It is now a
reason-to-code map in the suite, asserted row by row, with the `0` sentinel kept so an
unlisted reason still fails — plus three checks about which rows *share* a code, since a
table where every row happened to be 409 would satisfy fourteen row checks and still be
wrong.

`HttpReply::jsValue()` had the same shape one layer down. Its flags are a single `|`
chain, so one character mistyped as `&` collapses two of them, and nothing named the
characters it escapes — only that the answer parsed. Four checks now, one per character,
each of which ends something: `<` ends the script element, `'` and `"` end a string
literal, `&` starts an entity the attribute parser decodes first (§4ah).

`lib/upload_limits.php` gave up both edges of `describeBytes()` — exactly 1048576 bytes
and exactly 1024 — which is the number printed in the sentence telling somebody what to
trim their file to. `1024 KB` and `1048575 bytes` are what those mutants produce. Also
`describeBytes(null)` answering `" bytes"`, and a POST arriving with no `CONTENT_LENGTH`
header at all being reported as *dropped for its size*, which is the old defect with the
sentences swapped.

**And the same shape a third time, which is what makes it a shape rather than three
bugs.** `LayoutRules::describe()` — the part of a publish refusal naming the admin's own
value — had every branch removable with the suite green, and its 40-character cut movable
in either direction. `Color::describe()`, `UploadLimit::describeBytes()` and this one are
all pure functions whose only callers build a sentence around them, and in all three cases
the sentence was checked and the value was not. Anything that turns a value into words for
a person to read is worth asking this question about: the check that quotes the wording
passes whatever the words are about.

**Ten of the fifteen survivors in `lib/grants.php` were real**, and that is the module
holding the answer to "may this account reach that sign":

- **Both grouped reads could be emptied out.** `displayIdsByAccount()` and
  `accountIdsByDisplay()` are the two axes of the grant matrix the Admin Panel draws,
  and the entire body of both loops could be deleted with the suite green. Nothing had
  ever asserted what either returned — only that access decisions made *elsewhere* came
  out right. A matrix drawn empty over a full table, and a save from that page reads its
  own checkboxes.
- **Granting twice could stop being idempotent.** The docblock says already-granted is
  success rather than an error; nothing had ever granted the same pair twice. Removing
  the guard leaves two rows for one permission, which `revoke()` then removes both of —
  so the defect is invisible from every direction except the matrix's own count.
- **A session with no id could have become account 1.** `Actor::signedIn()` falls back to
  `0` for a missing id, and no account has that number. One digit up is the id the
  installer gives the first admin. The fallback was never asserted.
- **The actor's own id and username were never read back.** Exercised hard as an input
  to `mayEdit()`; never checked as an output, and the username is what an edit lock names
  on a colleague's screen.

**`Color::describe()` had almost nothing on it.** Ten of thirteen mutants survived: the
whole function could have been reduced to quoting the value back. It is the sentence a
refused colour actually shows — `DisplayAdmin` quotes it and the Admin Panel prints it
twice on the unreadable-colours card — so "blank", "nothing", "a list of values" and
"integer" are four different messages #21 exists to keep apart, and the twenty-character
cut lands inside a sentence on a page. Pure function of one argument, and it was covered
by inference from the messages built around it.

#### The one hollow check it found in the suite, and the instrument that fixes it

`checkSame('', StoreClock::unreadable(null), 'an absent setting is not something to
report')` — from §4ap, the newest section in this file when it was found, and it does not
test an absent setting. This suite requires `auth.php` at the top, which requires
`config.php`, which defines all ten branding names with their defaults. `unreadable(null)` means *read the constant*, and in
this process the constant is present and usable, so the check passes for a different
reason than its label gives. The mutation runner found it by deleting the branch the
label claimed to cover and watching nothing happen.

A `define()` cannot be undone, so that branch is unreachable from any check in a process
that has loaded `config.php` — and it is not a hypothetical branch. Every installation
whose generated `branding_config.php` predates the setting has no `STORE_TIMEZONE` at
all, which is all of them until somebody saves the Settings form, which is what the live
sign is running today.

So `inFreshProcess()` in `tools/test_fixture.php`: run a snippet in a PHP process that
has loaded nothing, and read what it printed. Three checks that no in-process check could
make — with nothing configured there is nothing to report and the zone is the documented
default; `StoreClock::load()` really does read the generated file, which is its only
statement and was previously unobserved; and the no-argument form really consults the
constant rather than answering from its own default parameter. The old check keeps its
place with a label that says what it tests. It is worth having. It was not worth what it
claimed.

Deliberately narrow: the snippet requires what it needs and echoes one string, and
nothing in there builds a database. The rules worth reaching this way are the pure ones
that read a constant.

#### Almost every surviving mutant is `===` → `==`, and that is about the floor

Twenty-three survivors remain across the six modules taken to completion. Sixteen are a
strict comparison relaxed to a loose one, and they survive because **on PHP 8 the two
cannot differ** for the values that reach them: `'0' == ''` is false since 8.0, and so is
`0 == 'admin'`. On 7.x both were true. So `$user['role'] == 'admin'` — with a role column
that a broken session could deliver as `0` — is an equivalent mutant at the declared floor
and a privilege escalation one version below it.

That is a cost of the floor resting on a person (§4k, #51) that nothing had priced
before: **this suite's mutation score is a function of the PHP version, and the lines it
stops covering if the host moves are comparisons in the access module.** The right
response is not sixteen checks pinning behaviour the language already guarantees — that
is manufacturing exactly what #50 objects to. It is that `ServerReport::phpVersionNote()`
is the alarm, that this paragraph says what the alarm is protecting, and that a host
moved down to 7.x is a re-run of `tools/mutate.php` over `lib/grants.php` and
`lib/display_request.php` before anything else.

The other seven are guards whose absence is unobservable because a later line already
refuses, and four of them are one guard: `isZone()`'s `if (!is_string($id) || $id === '')`
survives being deleted, having its `||` turned into `&&`, having its `!` dropped and having
its comparison relaxed — because the strict `in_array()` on the next line rejects a
non-string and an empty string anyway. Kept for §4am's reason — the mutation surviving *is*
the reasoning being right — and recorded here rather than left to be rediscovered by
somebody who then deletes the guard.

#### Five things wrong with the tool, and how each of them looked

Worth writing down because every one reads, from outside, exactly like good news. Four
were caught by running it. The first was caught by reading it, after four modules had
been swept and written up — which is the honest note to end a section about measurement
on, and the reason the table above is a run of the shipped version rather than of the one
that produced the first draft of these numbers.

- **A whole operator family generated nothing.** The token-swap loop gated on a list of
  token ids it expected to see, and the list left out `T_BOOLEAN_AND` and
  `T_BOOLEAN_OR` — so `&&`→`||` produced no mutants at all while sitting in the operator
  table above, fully implemented, looking present. **A missing operator is invisible from
  the report**, because a report only ever shows what was generated: a module with no
  connective mutants reads as a module with no connectives in it. `lib/layout_rules.php`
  alone has 21 of them. The gate is gone; the swaps match on the token's *text*, which
  is safe because none of them can be anything else — `and` and `or` are reserved words,
  and a string holding `&&` carries its quotes in its token text.

- **It graded its own subject's output.** The runner classified a mutant as INVALID by
  grepping the run's output for "syntax error" — and the suite echoes the label of every
  check it passes on the way, one of which is about a branding config with a syntax error
  in it. The fix is to lint the mutant with `php -l` before running it and to anchor the
  KILLED patterns to the start of a line.
- **Commenting out a line can close PHP.** The statement-deletion operator originally
  put `// ` in front of the line. The sanitiser's first statement is a regex matching a
  `<br />` tag, and a question-mark-greater-than inside it ends the PHP block *from
  inside a comment* — so that operator graded the one file #49 had measured by hand as
  INVALID and silently stopped mutating it. The removed line is now replaced by a block
  comment holding nothing of the file's own. The same trap is why `lib/markup.php`'s
  header writes short-echo tags as `{{ … }}`, and why this bullet does not spell the
  sequence out either.
- **It read English prose as SQL.** The scoping-predicate operator matched ` WHERE `
  case-insensitively, and `HttpReply`'s message about damaged text contains the sentence
  *"a replacement character where the bad bytes were"* — so it produced a mutant that
  mangled an admin's message and filed it on the survivor list as an uncovered scoping
  predicate. A finding that is the tool's own grammar is worse than no finding: it is a
  real-looking entry somebody will work through. It now requires upper case *and* a
  statement keyword beside it, which is the same argument `check_invariants.php` makes
  about `NOW()`: every SQL keyword in this repo is upper case.
- **`lib/markup.php` produced no mutants at all.** Every statement in it is a `return`,
  which the deletion operator excludes on purpose, and its two guards are multi-line
  blocks rather than the one-line form. A file the tool cannot mutate reads from outside
  exactly like a file with perfect coverage — #50's own complaint, pointed at the
  instrument instead of the suite. That is what the whole-`if`-block operator is for, and
  it immediately produced the mutant worth having: `ENT_QUOTES & ENT_SUBSTITUTE` is `0`,
  and `htmlspecialchars($v, 0)` leaves both quote characters alone, which is the defect
  the module exists to prevent written as one character.

#### The other half: five invariants with no automated check

Four are mechanised and the fifth is halved. Each was on that list for a stated reason,
and three of those reasons turned out to be about the *pattern* rather than about the
question:

- **`canvas_elements`** was listed as undecidable because the API action
  `get_canvas_elements` is indistinguishable from the table. It is one lookbehind apart
  from it. Everything else that made the grep noisy was prose in eight files, which the
  checker already drops. Now an exact rule over three files: the store that owns every
  statement, the convergence that shapes the table, and the one catalogue entry naming it
  as a column this database should have.
- **`STORE_TIMEZONE`** was listed because a page naming it as the key of a save looks
  identical to a page reading it. True of the *quoted* name — and the rule is not about
  the quoted name. One module reads the setting and reads it through
  `constant(self::SETTING)`, so the **bare** constant is spelled nowhere in the repo at
  all. That is now a rule expecting no matches anywhere, which is stronger than the
  by-eye version, plus a second rule holding the quoted spelling to the three files
  entitled to it. A bare `STORE_TIMEZONE` in an expression is an undefined-constant Error
  on whichever page did it, on every installation that has not saved the Settings form.
- **`grants_accounts` / `grants_displays`** was listed because it is about a form's shape
  rather than about which files match — which is a limit of the checker's rule format, not
  of pattern matching. Written out longhand instead: each name must appear as a hidden
  input, which is the declaring, *and* as a `$_POST` read, which is the acting on it, and
  nowhere outside `admin_panel.php`. One without the other is §4s back — a form declaring
  an axis nothing reads, or a save trusting an axis nothing drew.
- **`ensureSignageSchema()`'s position** was listed as a thing a pattern cannot see. The
  §5 note is right that the position is the invariant and wrong that it is undecidable —
  though the obvious mechanical form, a line-number bound, would have been wrong too:
  `api.php` legitimately converges at line 128, after the upload-limit and CSRF gates,
  because those send a reply and stop. What must hold is that the call comes before any
  transaction could exist, and every transaction in this app is held by one of three
  use-case modules reached through a store — so the check is that the call precedes the
  first *mention* of any store or use case in the file. Decidable, and stricter than
  counting lines.
- **`ErrorPolicy::report` callers** keeps the half that is a judgement. Whether a new
  caller can fire repeatedly on a condition the app expected, and therefore needs a
  window, is a reading of that call site and no pattern will do it. What was not
  mechanical and now is: *noticing*. A fourth caller used to be invisible; it now fails a
  check and has to be read before it lands.

The list printed at the bottom of every run is shorter and deliberately not empty. Five
entries, three of which are about instruments this repo does not have — a browser, a
database that lags the repo, an automated sweep — and one of which is the mutation runner
itself, named there so that "can this check fail?" has a place on the page rather than
being a thing somebody remembers.

**The two written longhand were held to invariant 30 the way the rest of this pass was**,
which for a check inside `check_invariants.php` means breaking the app rather than a
module: renaming `name="grants_accounts[]"` in the grant form fails it with *"never
declares grants_accounts as a hidden input, so the save cannot know which rows were on the
page"*; renaming the `$_POST` read fails it the other way; moving `crud.php`'s
`ensureSignageSchema($pdo)` below its `DisplayStore` fails the position check with the two
lines and the two line numbers. The two new file-set rules were checked the same way, by
putting a bare `STORE_TIMEZONE` and a `canvas_elements` query into `help.php` and watching
both go red. Each break was reverted; the point of doing them is that the alternative is
five more `ok` lines nobody has seen move.

#### And the HTML comments #44 left behind

`codeWithoutComments()` dropped PHP comments and not HTML ones, so an `<!-- … -->`
explaining why a line no longer calls `strtotime()` failed invariant 28 against the
sentence explaining why the rule holds. #44 hit it, wrote its note in the other syntax,
and left the fix here because what to do about PHP embedded inside an HTML comment
changes what every rule sees.

**The answer is that an HTML comment holding PHP is code and stays.** A comment is
dropped only when it opens and closes inside one `T_INLINE_HTML` token, which is exactly
the case where nothing in it executes. Write `<!-- the price is {{ Markup::text($p) }} -->`
and the `<!--` and `-->` land in two different tokens with a live call between them: that
call runs, its output reaches the page inside a comment a browser hides, and a rule about
it must still see it. Dropping the span between the two tokens would blind every rule to
whatever a page hid that way, which is the one outcome worse than the false positive this
fixes. The unterminated halves need no decision — the pattern does not match them, so
they stay as the HTML they are.

Measured rather than asserted: 93 HTML comments across four files now drop out, and all
of the rules match exactly the files they matched before. The repo has no HTML comment
today whose text collides with a rule. The fix is for the next one, and for the note #44
had to write in the other syntax to avoid it. Three checks in the checker pin all three
behaviours, because both halves fail silently and the function decides what every rule
can see.

#### What is left, named rather than left to be assumed

- **Sixteen of twenty-six `lib/` modules have not been swept**, and **the count is not
  written here any more** — `php tools/mutate.php --swept` prints it, names each swept
  module with the section it was written up in, and says what to do next. Ask the tool.
  The ten that are done were chosen by stake; the rest are a command each. This is the one
  place where "#50 is done" would be an overstatement: the *instrument* is done and the
  sweep is a standing activity, which is what makes it a rule (invariant 30) rather than a
  task.

  **This bullet is where the ledger came from, because this bullet was wrong.** It said
  eight modules and named `lib/layout_rules.php` as one of the four worth doing next —
  four paragraphs below the table reporting that module's 208 mutants and 53 survivors.
  Two other documents then quoted the number as six and nine. Three answers to one
  countable question, none of them ten, and each of them locally plausible: the table is
  the measurement and the sentence was the plan it was drafted beside, so neither half
  looks stale on its own. That is #50's complaint recurring inside #50's own bookkeeping,
  which is why the fix is the shape #50 chose for the original — a number nobody writes
  twice, printed by something that also checks the citations beside it.
- **The sweep is not a gate and should not become one.** `lib/layout_rules.php` alone
  generates 187 mutants, half an hour of runs. CI running that per push would buy less
  than the ten seconds the suite already costs.
- **`N → N+1` on a large constant is a weak finding.** `MAX_BYTES` moving by one byte and
  a canvas limit moving by one pixel survive because nothing pins those boundaries, which
  is true and nearly always fine. The boundary worth pinning is the small one —
  `Color::describe()`'s twenty characters, `openable()`'s count of one — and those are now
  pinned.
- **The harness cannot see a deprecation.** `error_reporting()` excludes `E_DEPRECATED`,
  so the `diagnostic` grade is blunter than it looks, and PHP 8.4 raises one in
  `lib/color_audit.php` that the 8.2 floor does not. Not #50's to fix — recorded because
  the tool's own grading depends on it.

### 4ar. The gate that could not fail on the thing it was for (#51)

`php -l <every touched .php>` is the first line of §5. It has been there since the
beginning, and its stated job is to stop a file that will not parse from reaching a
Screen. **It cannot do that job here, and never could.** The floor is PHP 8.2; the
container these sessions run in is PHP 8.4. A construct added in 8.3 or 8.4 lints clean
locally and is a parse error on the live host — and a parse error takes the whole file
down, so what the shop gets is a blank board rather than a message anybody reads.

Demonstrated rather than argued, because that distinction is the whole of #50:

```
$ php -l zz.php          # class Zz { const string N = 'x';
No syntax errors detected #   public string $h { get => 'x'; } ... }
```

Both of those are parse errors at 8.2. The gate says the file is fine.

**The fact was already written down. The consequence was not.** §4v's floor bullet ended
with the sentence *"This container has PHP 8.4 for `php -l` only"* — accurate, dated, and
followed by nothing. Somebody recorded the input to the inference and never drew it. That
is a distinct failure from not knowing, and worth naming as its own kind: a note that
tells a later reader everything except what it means. The line now reads as the hole it
describes.

**Why the check reads tokens and not text.** The hand sweep that cleared the tree on
2026-08-11 was a one-line grep, and on a clean tree it produced two false positives: a
`readonly` **HTML attribute** in `admin_panel.php`, and a JavaScript `.match(` in
`builder.php`. The names these constructs go by are ordinary English and ordinary
JavaScript, so a text search over a repo that inlines ~3100 lines of JS into a PHP file
is guaranteed to cry wolf. A rule that cries wolf on a clean tree is a rule somebody
turns off, and then the rule is worse than nothing. `aboveFloorUses()` runs
`token_get_all()` and works on real tokens, so a name inside a string, a comment, an HTML
attribute or a `->method()` call cannot be mistaken for the construct. Nine negative
fixtures pin that, and two of them are the exact false positives above.

**Seven constructs and twenty functions**, chosen as what somebody might plausibly reach
for here rather than as a complete grammar: typed class constants and `#[\Override]`
(8.3); property hooks, asymmetric visibility, `#[\Deprecated]` and bare-chained
`new Foo()->bar()` (8.4); plus `json_validate()`, `array_find()`, `mb_trim()` and
seventeen more. Syntax is the dangerous half — the file never starts. The functions are a
fatal at call time, which is a page that dies where it stood: still a dead sign, still
invisible to a lint at 8.4.

**Two things about it are load-bearing, and its own fixtures found both — not reasoning.**
This is the second time on this branch that a fixture has corrected the person writing the
detector, and it is the argument for invariant 30 in miniature:

- **`private(set)` has two lexings.** PHP 8.4 reads it as a *single* token,
  `T_PRIVATE_SET`. 8.2 has no such token and reads four: `T_PRIVATE`, `(`, `set`, `)`.
  The detector was written for the four-token shape, which is the shape that does not
  occur on the machine it was tested on — so the fixture went red immediately. Had the
  probe not existed, the natural fix (match the 8.4 token) would have gone **silently
  blind in CI**, which is the one runner pinned to the floor, and it would have looked
  exactly like a pass. Both shapes are matched now, and the 8.4 one by *text*, because
  naming `T_PRIVATE_SET` in the source would itself be a fatal at 8.2 — a floor checker
  that cannot run at the floor.
- **The floor is parsed out of `ServerReport::ASSUMED_PHP`, not restated.** The checker
  loads no app code — requiring `lib/server_report.php` pulls in five modules, and a
  static analyser that boots app code to learn a constant will eventually boot a side
  effect — so it reads the declaration and treats *failing to find it* as a failure. A
  checker holding its own copy of the number keeps passing after somebody lowers the
  floor, which is the exact moment it is supposed to speak.

**It is a denylist, and it prints that on every run.** It knows what 8.3 and 8.4 added;
it cannot know what 8.5 will add, and it reads syntax rather than semantics, so a method
that only exists above the floor or a changed default passes it. That limit is in the
"still by eye" list rather than left for a reader to infer, because a denylist matching
nothing is indistinguishable from a clean tree — the failure mode is silence, and silence
here reads as an all-clear. What makes it worth having anyway is the direction it fails
in: it cannot promise the tree is clean, but everything it names would have reached the
sign.

**Measured, and the measurement corrected two things I had written.** `php tools/mutate.php
--suite=tools/check_invariants.php tools/check_invariants.php` restricted to the detector's
own lines: **220 mutants, 80 killed, 139 survived** on the first pass. A 36% kill rate is
not a number to publish and move past, so the survivors were read rather than summarised,
and they fell into two clusters that were both real:

| Cluster | Why it survived | What was done |
|---|---|---|
| 39 index and line mutants | The probes asserted only *that* a construct was flagged, never what or where. `$ts[$i + 2]` could become `$ts[$i + 3]` with every fixture green. | Probes now assert exactly one hit, on the right line, naming the right construct, with the right `since`. A wrong version sends somebody to read up on the wrong release. |
| 21 inside the `private(set)` four-token branch | **The line cannot execute on this machine.** 8.4 lexes `private(set)` as one token, so the 8.2 branch is dead code here — and it is the branch CI depends on, CI being the only runner at the floor. | A seam, not a fixture: `aboveFloorUses()` split into `aboveFloorTokens()` and `aboveFloorInTokens()`, so the 8.2 lexing can be handed over as **data**. A recogniser that takes source only ever sees its own version's lexing. |

That took it to **90 killed, 130 survived**. Two corrections belong here, because both were
mistakes in this write-up's own earlier draft rather than in the code:

- **The line bookkeeping was not "uncovered". It was unobservable.** `$line +=
  substr_count($text, "\n")` survived, and the reason is not a missing probe: *no finding
  is anchored to a single-character token.* Every one reports the line of an array token,
  which `token_get_all()` supplies directly. The carry-forward matters only for the `)` and
  `]` entries in the list — for the next detector somebody writes — so it earned an
  assertion rather than a deletion, and the seam above is what made one possible: the
  tokeniser is now reachable on its own, so the token list can be read instead of inferred
  from a finding. Its sibling, `$line = 1` removed, **stays alive and is correct**: the
  first token of any source is an array token — `T_OPEN_TAG`, or `T_INLINE_HTML` for a file
  opening with text — so the initial value is overwritten before it can be read. Unkillable
  because PHP's tokeniser guarantees it, which is the docblock being right (§4am's
  `flock(LOCK_UN)` again).
- **The grades from this sweep do not exist, and the tool does not say so.** `runSuite()`
  grades by grepping the suite's output for `^KILLED by assertion` / `diagnostic` / `the
  count anchor`, and those lines are printed by `tools/test_fixture.php` — which
  `selftest_layout.php` uses and `check_invariants.php` does not. So through
  `--suite=tools/check_invariants.php`, every kill falls through to **`fatal`**, whatever it
  really was. The `$line +=` mutant above was reported `fatal` and, run by hand, produces a
  clean assertion failure. Survived-versus-killed is still sound, because that comes from
  the exit code; the *grade* dimension — the thing #50 added, and the one that separates a
  check knowing what a line was for from the harness noticing a crash — is silently lost
  for any suite but one. Worth knowing before quoting a grade from a `--suite=` run.

**The remaining 130 are dominated by comparisons that cannot differ**, and this is a class
claim rather than 130 individual proofs, which is the honest way to state it: 47 are
`===` → `==` and 9 `!==` → `!=`, almost all comparing a token id (an int) against a `T_`
constant (an int) or a token's text against a one-character string literal; 16 are
`||` → `&&` inside the scanners' break guards, where a single token cannot be both `';'`
and `','`, so the mutated guard is simply never taken and no lexically valid input
distinguishes it. A token-pattern matcher is mostly guards, and guards over a
finite alphabet are where equivalent mutants live. What that leaves is a real ceiling on
this file rather than a to-do list: the detector's *behaviour* is pinned by 20 fixtures, and
its defensive interior is not, because nothing a lexer can produce reaches it.

**The complete answer is the one CI already gives**, and this check does not replace it:
run the suite at the floor. CI pins 8.2 for exactly that reason, which is why the floor
check covers `tools/` as well as the app — a tool that only runs above the floor is a red
build. Note the drift runs both ways and the other direction is already recorded: §4aq's
last bullet has PHP 8.4 raising a deprecation in `lib/color_audit.php` that 8.2 does not,
which is harmless to the shop and noise to the mutation harness. Same root cause, opposite
sign, and neither is visible from the other machine.

---

### 4as. The room the closed door made nobody check

The first finding of the browser pass, and it is not a bug. It is a consequence
somebody wrote down, drew correctly for one container, and then generalised one step
too far — after which the document that named the danger became the reason nobody
looked for it.

ADR-0004 froze a Display's canvas dimensions at creation. Its Context states the
hazard exactly: shrinking a container that is `overflow: hidden` makes anything
outside it invisible on the Screen while the rows still exist in the database, and
with no version history an automatic repair would be unrecoverable. That reasoning is
right, and freezing the canvas is right. Then its Consequences said:

> Elements can never be orphaned outside their canvas, so neither the Builder nor the
> admin panel needs out-of-bounds warnings or repair tooling.

The first clause is true. The second does not follow, because the hazard was never a
property of the canvas — it is a property of *any* `overflow: hidden` container whose
children are absolutely positioned inside it. A **section** is exactly that, and a
section was never frozen: it resizes by dragging a handle.

Four facts, each checked rather than assumed:

| | |
|---|---|
| Does the Viewer clip too? | Yes — `.section-block` is `overflow: hidden` in `builder.php` and `viewer.php`. So the Builder is **honest**; the sign hides exactly what the Builder hides. This is the good half, and it is why this is a missing sentence rather than a WYSIWYG defect. |
| Does a clipped block still publish? | Yes. `collectElements()` walks the canvas by class with no visibility or bounds test. |
| Can it be clicked back? | No, once fully clipped. Clipping removes it from hit testing, and the inspector's X/Y and Layer controls act on the *selected* block. There is no layers panel. |
| Do Builder and sign agree on paint order? | Yes, and this was the phantom worth disproving before chasing it: `sort_order` is written from DOM index and read back `ORDER BY sort_order ASC, id ASC`, so ties break identically on both sides. |

So dragging one handle could retire a row of prices, with nothing on screen changing
except the row going away — which reads as a rendering fault rather than as something
you just did. The way back is Undo or growing the section again, and neither occurs to
somebody who thinks the app broke.

**What was added** is the warning that consequence said was unnecessary:
`applyClipWarning()` puts a badge on a section naming how many of its blocks it is
hiding. Live while the edge moves, on load for a layout that arrives already clipped,
after an Undo, after a delete, and after either inspector control that can change the
answer. Nothing is moved and nothing is repaired — on ADR-0004's own reasoning, since
it rejected auto-clamping for the canvas because a tuned layout comes back as a pile,
and Undo reaches five steps. A badge rather than a toast because the risk is not
missing it in the moment; it is publishing half an hour later having forgotten.

**Two details worth keeping.** The bound is the section's *border* box, not its
content box: a child's `data-x` is measured from the padding box while interact.js's
`restrictRect` holds it inside the border box, so comparing against the border box is
what gives a flush-dragged block the 2px of slack it needs. Get that wrong and every
layout in the shop reports false positives the moment it opens, which is worse than
saying nothing. And the measurement is in canvas pixels — `data-x` and `offsetWidth`,
never a screen rect — which is invariant 26's other half: a bound in screen pixels
would report a different count at 50% zoom than at 200%.

**Nine mutants, nine kills, and four of them were found by mutating rather than by
writing tests.** The function was covered first and the *wiring* was not, which is the
failure mode §4aq exists for: dropping the `handleResize`, `restoreCanvas`,
`deleteSelected`, `applyDim` and `applyPos` calls each left a green suite. Two of
those taught something beyond the missing line:

- Widening the child selector to `:scope > *` was killed only by a `TypeError`, because
  three assertions dereferenced `clipBadge(host).textContent` where the suite's own
  idiom guards it. A fatal is the harness noticing something moved, not a check knowing
  what the line was for; guarded, the same mutant dies to eleven named assertions.
- The first `restoreCanvas` test asserted that undoing *out* of a clipped state removes
  the badge — and passed with the hook deleted, because a rebuild makes fresh nodes and
  a fresh node has no badge. The half that needs the hook is undoing *into* a clipped
  state, where the warning must be recomputed from nothing. A check that holds for a
  reason other than the one you had in mind is the exact shape #50 was filed about.

One hook is held by reading the source rather than running it: no suite drives
`loadLayout()` over a real DOM tree — the suite that drives it stubs a page flat enough
to answer "did anything throw" and discards appended children, and the two suites with
a real tree never call it. Opening an already-clipped layout is the likeliest way
anybody meets this badge, so the call is held by a check that cannot execute it rather
than by nothing. That is the weaker grade and it is written down as such.

### 4at. A number that moves and a screen that does not

Browser pass step D, reported as: *"video z-index shows the number moving up and down but
the element is always front and never drops behind any other element"* — and then
diagnosed by the reporter in the next sentence, correctly: *"multiple items have the same
Z-index number causing elements to not move up and down the index order."*

`createBlock` and `createSection` both write `z_index: 1`, and nothing else ever moves it.
So the ordinary canvas is not a stack. It is a heap of blocks all on layer 1, whose paint
order comes entirely from the tie — which the browser breaks by document order, later on
top. The four buttons did arithmetic on the number and so could not see the heap at all:

| Button | Did | Result on a canvas where everything is 1 |
|---|---|---|
| Back | set 1 | nothing — it was already 1 |
| Backward | `max(1, z-1)` | nothing — the floor is where it started |
| Forward | `z+1` | worked, once: 2 beats the other 1s |
| Front | `max+1` | worked, always |

Every one of them updated `insp-zindex-val` regardless, which is why the number moved
while the screen did not. Front worked because `max+1` is the one step a tie cannot
absorb, and that is precisely the reported asymmetry: front always, back never.

**The fix renumbers the group rather than nudging one number.** `_stackingGroup()` sorts
the selection's `.editable-block` siblings by layer and then by document order — the same
tiebreak the browser uses, and the same one the sign uses, because `sort_order` is written
from the DOM index at publish and read back `ORDER BY sort_order`. `_moveInLayerOrder()`
splices the selection to its new place and assigns 1..n. Once no two blocks in a stacking
context share a layer, the readout is an answer rather than a coincidence and all four
buttons are the same operation.

Three things about it are deliberate:

- **It writes to the siblings, and those numbers are published.** That is the cost and it
  is real. What it buys is that the number on screen and the order on the sign cannot
  disagree — before, the number was decorative on any canvas nobody had reordered.
- **A no-op press still renumbers.** Pressing Back on a heap of 1s does not move the
  block *in the order* on the first press, but it does convert the heap into a stack, so
  the second press can. Without that, Back would need pressing twice for reasons nobody
  could see.
- **A press that genuinely cannot change anything records no undo step**, which broke an
  existing check. `selftest_builder_undo.js` drove `bringForward()` on the fixture's price
  block — the only block in its section, so there is nothing to bring it in front of. The
  old code recorded a step there by writing `z_index: 2` on a block with no siblings: a
  number nobody can see, and an undo step restoring a canvas identical to the one before
  it. The test now drives a root block with four siblings and asserts the other half
  separately, that a button with no room records nothing.

`.clip-badge` moved from `z-index: 6` to `19` in the same change. A section is a stacking
context and its children are now renumbered 1..n, so 6 meant the seventh block in a
section drew over the badge reporting it. 19 is just under `.rh`'s 20, because the bottom
resize handles are how you fix the clipping and the badge would hide them. Nineteen blocks
in one section is not a price sign, so that is the bound — stated rather than hoped for.

### 4au. Three doors, three ceilings, and the one that answered "security"

Browser pass step F, reported as: an over-limit pick that *"does not give a warning why and
that it was not selected"*, an under-limit pick with *"no upload button or progress bar or
any indicator"*, and no cancel control on a large upload.

The Builder's inspector uploads were already complete — `startUpload()` has XHR progress,
a refusal naming both the file's size and the server's limit, an empty-file refusal and a
double-pick guard. Three other doors were not, and each failed differently:

| Door | What it did | Why |
|---|---|---|
| Top-bar **Background** (`applyBgFile`) | silently nothing, over the limit or on a non-image | previewed with `FileReader`, no size check, no type check, no `onerror`; the only size refusal lived in `publishCanvas` and abandoned the whole publish |
| **Asset Library** (`crud.php`) | over `post_max_size`: a bare 403, *"Security token mismatch. Please go back and try again."* | no dropped-body guard before `verifyCsrf()`; its own `10 * 1024 * 1024` stated nowhere on the page |
| **Brand logo** (`admin_panel.php`) | same 403; and its label offered SVG, which the handler refuses | same missing guard; own `2 * 1024 * 1024`; `previewBrandLogo` previewed anything at all |

The Library's answer is the interesting one, because `UploadLimit`'s header describes that
exact bug — found in `api.php`, fixed there in 2026, and `bodyWasDropped()` was written for
it. Two of the three sinks never called it. Going back and trying again, which is the one
thing that sentence promises will help, reproduces it.

**A stated limit must be one that can be kept**, which is why the two inline numbers moved
into `UploadLimit` as `IMAGE_MAX_BYTES` and `LOGO_MAX_BYTES`, read through `cappedAt()` —
`min(the decision, what can actually arrive)`. A per-sink ceiling is still a real decision;
a page's *own* number is an opinion about a limit it cannot see, and on a host with
`post_max_size = 8M` the Library was promising 10 MB it could not deliver.

The pickers now refuse at pick time with a sentence naming the file, clear the input so the
next pick is an event the browser reports, and say what a surviving pick will do. Two of
those sentences are the ones the report asked for, in its own words: *file too big* and
*wrong file type*.

**Two things are deliberately not a progress bar.** The Background picker does not upload —
the image travels with the next Publish, which is why there is no progress to show; what
was missing is a note saying so, because an absent bar and a dead control look identical.
And `crud.php` is a plain form POST, which emits no progress events at all, so its
indicator is an indeterminate bar: it says *working*, which is true, rather than a
percentage, which would be invented. Turning that form into an XHR upload to earn a real
percentage is a larger change than a 10 MB image warrants, and it would move the save off
the one path that already redirects.

**Cancel was half-built.** `startUpload`'s `xhr.onabort` has been handled since it was
written, with its own sentence — and nothing could ever reach it, because there was no
control and nothing held a reference to the request. A 50 MB video has a ten-minute
timeout, so the missing button was ten minutes of a page you could not get on with. The
requests are now kept in `uploadsActive`, dropped in `done()` so every outcome including
the abort clears them, and the button lives inside `#upload-status` — which is
`display:none` until an upload starts, so it exists exactly while there is something to
cancel.

### 4av. Two doors, one lapse, and a lock that only stopped two of three things

Browser pass step G, reported as three things. **One is a defect, one is a narrowing of
what a feature means, and one is the feature working exactly as ADR-0007 designed it.**
Keeping them apart is the whole of this entry, because the fix for the third would be to
break the lock.

**A link to a page there is nothing to do on.** A basic account with no Display assigned
lands on builder.php's "no displays have been assigned to you yet" page, whose footer
offered *Asset Library* and *Sign Out*. The Library needs a sign to add to — `#33`,
invariant 29 — so that account can reach it, read it, and be refused by
`Actor::NO_SIGN_REFUSAL` on the only thing it might have gone there to do. The link is now
drawn under `$isAdmin || $theirs`, which is `Actor::holdsASign()` spelled with the
variables this page already has: the grant axis, not `openable()`, so an account whose
only Display is switched off for the afternoon keeps the link — it is still their sign and
they can still be asked to put an image in the library ready for it coming back.

The link is courtesy. `crud.php` still refuses the write, and still *lists* the library to
an account with no sign, for the reason its own comment gives: an account can be asked to
look something up, and a page that will not say what is in it cannot explain why it
refused.

That one-line change cost five failures in `selftest_builder_readonly`, over markup a
thousand lines away that had not been touched — worth writing down, because the mechanism
generalises. That suite proves the editing controls sit inside `if (!$readOnly)` by walking
this file's conditionals and counting depth, and its walker only recognises an `if` whose
tag opens straight onto the keyword. Writing the reasoning *inside* the tag left an `endif`
it could see and an `if` it could not, the depth went negative, and every later guarantee
collapsed. **A conditional that walker cannot parse breaks the guarantee for everything
after it.** The reasoning moved to a comment block above; the conditional is one line.

**A locked section took a new block without a word.** The lock refused to let a section be
dragged and refused to let it be resized, and adding to it was the third way of changing
it, which nothing checked. That is the way that matters most on a sign: the lock exists so
an everyday account editing prices cannot disturb a header or a background somebody
positioned. `setTargetSection()` now refuses to aim at a locked section and says why at the
click; `createBlock()` refuses on the parent it is about to use, which is not the same
check twice — a section can be locked *after* it was aimed at, by ticking Lock in the
inspector on the section you just clicked, and from there every block button still landed
inside it. Three hand-written mutants, three kills by assertion.

This **narrows what Lock means**, and that is a decision rather than a repair.
`help.php` documented it as preventing "accidental moves or resizes", and its tip said to
lock a section so users editing prices would not move it — which reads as leaving the
contents alone. So help.php gained a *Locking a section* subsection saying what it now
does: a locked section takes no new blocks, and blocks already inside it are unaffected,
because each one's own lock decides whether it can be moved. Prices inside a locked section
stay editable.

**And the third is the lock working.** The report: a basic account signing in more than
fifteen minutes after an admin can move unlocked blocks and publish. That is
`LockState::IDLE_LAPSE_SECONDS` — a lock is held by *activity*, not presence, and one whose
last real interaction is older than fifteen minutes is free, which is the same comparison
on every read and the reason nothing has to be scheduled to sweep locks on a host with no
cron. `browser-pass.md` step G states the fifteen minutes itself. The admin's open tab does
find out: the next heartbeat answers `held_by_other`, `applyLockAnswer()` sets `lockLost`,
and the publish is refused rather than the canvas being pulled apart. Nothing to fix — and
"the second account should have been read-only" would mean a Display left open on a
back-office monitor blocks the sign until somebody walks back to it.

The "but not add new elements" half of that report is the role, not the lock: a basic
account must aim at a section before it can add, and `createBlock()` says so
(`builder.php`'s "Please click on a section first to add content"). Worth checking against
the locked-section change above — if the only section on the sign is locked, a basic
account now has nowhere to add, which is correct and is a thing an admin has to know.

---

### 4aw. Six doors, three verbs, and a lock that was only ever asked by the mouse

Browser pass step G, second round: *"a locked block can be deleted. if it's locked it should
not be deleted."* One sentence, and it was true — but the report is one door and the defect
was **six**.

`data-locked` was read in exactly three places, and all three are on the way in from a
pointer: the `mousedown` that starts a drag, and interact.js's own `move` and `resize`
handlers. Everything else that changes a block ignored it:

| Door | What it did to a locked block |
|------|-------------------------------|
| drag, resize handles | refused — the three places that asked |
| **Delete Block** button | deleted it |
| **Delete** key | deleted it, after a confirm it did not need |
| Inspector **X / Y** boxes | moved it, by typing |
| Inspector **W / H** boxes | resized it, by typing |
| **Align** bar | moved it, one press |
| **Align to Parent** | moved it, one press |

So the 🔒 icon promised no moving, resizing or deleting and delivered it against the mouse
only. The two typed boxes are the quietest of them: no drag, no handle, no confirm, and the
block simply moves.

**The fix is one predicate, not seven guards.** `isLockedBlock()` answers, `refuseIfLocked()`
says the sentence, and every door asks. Six copies of `dataset.locked === '1'` is how five
doors came to be missing it, and a seventh copy would have been the same bet again. Three
properties are deliberate:

- **The refusal comes before the confirm.** A "Delete this block?" answered *Yes* and then
  quietly ignored teaches that the button is broken, not that the block is locked.
- **A refused box is put back.** `applyPos()`/`applyDim()` call `showGeometry()` on the way
  out, or the number somebody typed sits in the box claiming a position the block does not
  have — and publish reads the block, not the box, so the disagreement is silent and the box
  is the one that looks right. `showGeometry()` is also now the only place that fills those
  four boxes, which is what makes "put them back" a call rather than four more lines.
- **A refusal commits no undo step.** Every guard returns before `commitUndoStep()`. This is
  invariant 27 the other way round: a history holding an entry for something that never
  happened makes Ctrl+Z do nothing visible, once per refusal, which is indistinguishable
  from Undo being broken.

**Two things that had to be decided rather than looked up.**

*Deleting the section over the top of it.* A lock on a child was worth nothing if its section
could go, and by a route nobody had to unlock. So a section holding anything locked is not
deleted either — locked itself or not — and the refusal counts what it found ("This section
holds 2 locked blocks"), because *unlock them first* is only actionable if you know how many
there are. `lockedInside()` asks through `isLockedBlock()` rather than a
`[data-locked="1"]` selector: an attribute selector matches the stored string while the rest
of the file asks `dataset`, and two readers that agree only by coincidence is the shape half
of this section is about.

*A locked block inside a multi-selection.* Skipping it and aligning the other four is what
somebody pressing the button means, so `movableTargets()` returns the movable ones and says
what stayed ("1 locked block was left where it is; the other 4 moved") — doing less than was
asked without a word is #21 in another costume. But the **bounds** are still measured over
every selected block, locked ones included: a locked block's edge is exactly what the others
are being lined up against, and dropping it from the maths would align the group to a
rectangle that is not the one on the screen. It is only the moving that the lock stops. And
`alignBlocks()` branches on the size of the *selection*, not of what may move — two blocks
with one locked still means "align these two to each other", and filtering first would
silently turn it into "align the free one to its parent", which lands it somewhere nobody
asked for.

**What the lock still does not stop, on purpose:** editing a block's text or its colour,
which change what it says rather than where it is; the renumbering that a *sibling's* layer
change does, which preserves every block's order relative to every other so nothing a locked
block covers changes unless the moving block crosses it — unavoidable if layering is allowed
at all; and anybody unlocking it. **The lock is an accident-preventer, not a permission** —
the edit lock (ADR-0007) is the permission. Nothing here is enforced server-side, and it
should not be: `LayoutStore::publish()` takes the payload it is given, and everyone who can
reach that endpoint can also untick the box.

**Two lessons from verifying it, both about checks rather than code.**

The suites were all green when the six doors were broken, because not one of them had ever
locked a block before driving `applyPos`, `alignBlocks` or `deleteSelected`. Green over an
untested premise is the recurring failure of §4 and this is another instance: the fixture in
`selftest_builder_undo.js` has a `locked: 1` image and the checks that use it are about
*restoring* it, so nothing asked what a locked block refuses. The 34 new checks in
`selftest_builder_editing.js` are per **door**, not per verb, because the defect was never
the verb.

And the mutation pass (invariant 30) earned its minutes twice over. Eleven hand-written
mutants, one per guard: ten died immediately, and one survived — deleting the
`movable.indexOf()` skip in the group-align path changed nothing, because the locked block in
the fixture sat at the left edge and a *left*-align does not move it whether the guard runs
or not. The check was vacuous in exactly the way #50 is about. Centring is the direction that
moves both blocks, and it distinguishes the two ways of being wrong at once: with the locked
block inside the bounds the free one lands on 115, and with it dropped from them, on 30.

The harness had its own bug in the way of one check, and it read as the app being wrong.
`findAll()` in the editing and undo suites returned the node the call was made on when that
node matched the selector — something a real `querySelectorAll` never does — so a section
asking which locked blocks were inside it got *itself* back and answered "none". Fixed in
both files, and worth stating as a rule: when a new check fails, the stub is a suspect
alongside the code, and the way to tell them apart is to ask what a browser would answer.

---

### 4ax. Recorded for a year, shown nowhere anybody was standing

Browser pass step H.1: *"does not say who published or the time."* The publish toast read
**"Published to Drive-Thru (drive-thru). That screen updates within 30 seconds."** and that
was the whole of it.

The interesting part is that nothing was missing from the *model*. `displays` has carried
`last_published_at` and `last_published_by` since the multi-display build; every read of a
Display joins the publisher's username (`DisplayStore::rows()`); `Display::lastPublishDescription()`
turns the pair into "sky, Aug 12 at 3:42pm" through `StoreClock`; and
`selftest_layout.php` has asserted both columns for as long as they have existed. It was
being **recorded correctly and displayed in two places, neither of which is the Builder**:
the Admin Panel's Displays tab, and the message a *refused* publish prints. So you could
find out who last changed the sign by leaving the page, or by failing to publish.

That is a different kind of gap from the rest of §4 and worth naming as its own shape: not
a fact the app got wrong, and not a check that could not fail — a fact the app had, kept
right, and never put where the question gets asked.

**The fix is a line in the top bar, beside the canvas size, and one field on the publish
reply.** Three decisions in it:

- **The sentence is the server's, whole.** `api.php` re-reads the Display after the publish
  and sends `lastPublishDescription()` — it does not compose "by <current user> at
  <time()>" beside the write. Two copies of a stored value is precisely the shape #44 was,
  and the reply would have been the copy nobody could see was drifting. It also keeps the
  formatting on the server: a time rendered in JavaScript is in the *browser's* zone, which
  would be a fourth clock in a bug that already had three.
- **An empty sentence is not "never published".** `showPublishState()` leaves the old line
  standing when the reply carries nothing, because the publish just succeeded — falling
  back to *not published yet* is the one wording available at that moment that is certainly
  false. Out of date by one publish beats wrong about whether the sign has ever been
  published.
- **A refused publish does not touch the line.** Nothing was written, so the sign is still
  showing what it was showing. Checked, because the toast branch and the refusal branch sit
  four lines apart.

**And the step asked for something the app should not do.** `browser-pass.md` H.1 said the
publish "reports success with a revision". The stamp is opaque by design (ADR-0006 —
callers compare it, they don't interpret it), so putting a revision number on screen invites
somebody to quote it in a conversation where it means nothing. The step now asks for who and
when, and says why it no longer asks for a revision.

*On the grades of what verifies this (invariant 30):* seven hand-written mutants, all
killed. Five die on behaviour — the top-bar update, the toast wording, the fall-back to
"never", and the two page-load renders. Two die only on a **source read**, because there is
no HTTP in the suite to ask `api.php` a question: deleting the reply field, and swapping the
fresh re-read for the stale `$display` already in hand. That second one is the weaker grade
by some distance — a source check pins the line, not the behaviour — and it is recorded here
rather than papered over, the same way §4as's `loadLayout` hook is.

### 4ay. What the pass found, and what that says about everything that passed

The browser pass closed on 2026-08-13. Ten sections, seven defects, five commits, and
this section is the only one in §4 that is not about a defect — it is about the shape of
the seven, because that shape contradicts what this repo predicted it would be.

**The score.** Before the pass, this build was covered by 1,782 checks in
`selftest_layout.php` (1,805 against MySQL), 546 across six node suites, 58 invariant
greps and 6 numbering checks. All green, and green the whole way through. The pass found
seven defects anyway, and **not one of them was a wrong answer**. Every one was a missing
door or a missing sentence: a thing the app did not do and had never been asked to.

**What the exposure was predicted to be, and what it was.** `work-lanes.md` argued the
risk was `interact.js` — un-run by anything (§4al) — plus four commits of `builder.php`
that no browser had drawn, and it singled out Undo as the biggest stake, on the good
reasoning that Undo's whole purpose is to change the canvas under somebody. Half of that
paid and half of it did not:

| Predicted | What happened |
|---|---|
| `interact.js` | **Paid.** §4as is a resize handle over a real `overflow: hidden` box, and nothing in this repo could have held that handle. |
| Un-rendered CSS at 1080p | **Nothing.** The top bar wrapped tidily, no control hid behind another, and zoom scaled proportionally at every step. |
| Undo | **Clean.** So were the Viewer, its unattended 30-second pickup, all three notice cases, the read-only Builder, and both of #44's live-only questions. |
| — | **Five findings nobody predicted**, all of them about what the page *says*: §4au, §4av twice, §4aw, §4ax. |

**The category that produced five of seven has a name, and no harness here was pointed at
it.** The six node suites and the layout suite all ask the same kind of question: given
this input, does the function return the right thing. Every one of §4au–§4ax is a
different question — *does the page tell the person what just happened, and is what it
tells them true of everyone it tells.* A ceiling stated nowhere and a 403 saying
*security* for a size problem. A lock that refused a mouse and accepted a keystroke. An
Asset Library link offered to an account whose write would be refused. A publisher and a
time recorded correctly for a year and displayed in two places, neither of them where the
question is asked. **In two of those five, no logic changed at all** — §4ax added a
sentence over a fact the tests had been asserting all along, and half of §4au was a number
the page already knew. A suite cannot fail a sentence that was never written, and it will
not miss it either, because missing implies looking.

**The data mattered as much as the browser.** §4at is not visible on any fixture this
repo can build: every block on a layout copied from the shop is on `z_index` 1, so Back
and Backward were moving a number that could not break a tie, and a fixture that assigns
distinct layers because distinct layers make the check readable hides that forever. The
same is true of §4as, which appears the moment a layout with an already-too-small section
opens. **A fixture picks data that makes a check legible; a copy of the shop picks data
that has been accumulating for years.** That is the argument for `lbm-test/` being a copy
and not a seed, and it is worth re-reading before anybody proposes seeding it.

**One report was narrower than the defect, and reading it as filed would have shipped five
of six doors still open.** Step G's second round arrived as *"a locked block can be
deleted"*. It was true, and Delete was one of six controls that ignored the lock, because
the lock was read at three pointer seams and copied nowhere else (§4aw). The habit that
found the other five was asking *what else touches this fact* rather than fixing what was
pointed at. Two other reports went the other way and are worth recording as
non-defects: the 15-minute lock lapse letting a second account edit is the lapse working,
and a 1920-wide canvas overhanging a 1920-wide *window* at 100 % is what 1:1 means when the
top bar is in the window too. **A pass that produces no non-defects is a pass somebody was
being polite during.**

**What the pass has been converted into, and what it has not.** The seven fixes carried
the layout suite from 1,782 checks to 1,805 and the editing suite from 86 to 175, so a
browser-only finding is now a stub-checkable regression — which is the durable half of the
exercise, and the reason the next `builder.php` change cannot silently undo any of it.
What did **not** convert is worth naming in the same breath: `interact.js` is still driven
by nothing, §4as's `loadLayout` hook and §4ax's `api.php` reply are held by reading source
rather than running it, and no suite renders a pixel. **So this pass is repeatable and not
retired.** `browser-pass.md` carries its outcome at the top and stays as the list, because
the live sign is a second install with its own data, and every step applies there again.

### 4az. The publish had been writing the Brand into every branded block for a year

The first step of v2 ([`roadmap-v2-brands-and-themes.md`](roadmap-v2-brands-and-themes.md)),
and it is a bug fix that landed on its own, before the feature that would have made it
visible. Nobody reported it. Nothing was wrong on any sign, and nothing would have been
for as long as the store had one set of Brand Standards.

**What it was.** `applyTextStyles()` paints Brand Standards onto a branded text block's
inline style, because a price has to *look* like a price while somebody is laying it out.
`serializeBlock()` then read `block.style.fontFamily` and its five neighbours straight
back out and put them in the publish payload, and `LayoutStore::insertContent()` stored
them in the element's own `font_*` columns. So every publish, going back to the day those
columns existed, copied the shared standard onto every branded row. Both renderers ignore
those columns for a branded subtype — that is the whole point of the subtype — so the
values sat there unread, identical everywhere, indistinguishable from the defaults.

**Why it was invisible, and why it stopped being.** One set of standards reaching every
sign means the baked value and the live value are always the same value. ADR-0011 makes
Brands per-venue, and the moment there are two the copy is a *fossil*: change a block from
`price` to `free` a month later and it renders whatever venue's typography happened to be
selected at its last publish, with nothing on the block or in the Builder saying where
that came from. This is the same shape as several of §4ab's coercions — a value the app
was writing down without anybody having decided it should — and the same shape as §4at,
where a fixture could not show the fault because the fixture's data was too tidy. Here
the *installation* was too tidy.

**The second fault is the one that made this land first.** `snapshotCanvas()` serializes
through `serializeBlock()`. So the undo snapshot carried the Brand too, and picking a
different Brand — which changes no element, moves nothing, types nothing — would have
changed the string every undo step is measured against. The next real edit would push a
step recording a difference nobody made, and an Undo would hand somebody typography they
had never chosen. That is **invariant 27 the other way round**: 27 says a function that
changes the canvas must commit a step, and this is a step committed for something that
was not a change. It could not be fixed after Brands shipped without also unpicking
whatever histories had been built on top of it, and it cannot be fixed on the server at
all, because an undo history never leaves the tab.

**The fix is at both ends and they are not the same fix twice.** The browser stops
*sending* the six fields, which is what makes the snapshot stable. `LayoutStore` stops
*storing* them whatever it is sent, which is what makes the row right for a Builder tab
that was loaded before the deploy — an ordinary thing to happen on the afternoon of one.
That case is **ignored rather than refused**: the person publishing did not know they
were sending a field, and refusing their work over it would be this app inventing a
problem for them.

**One condition, asked in one place, because the two ways of getting it wrong point in
opposite directions.** Keeping the Brand's values is the fossil; stripping values a
renderer is still going to read is a blank price on a wall at 1080p. Those are not
symmetric — the second one is on a sign in a shop. So `BrandStyles::paints()` is the only
place the question is asked on the server, and in the browser `applyTextStyles()` decides
it once, at the moment the paint happens, and records the answer on the node for
`serializeBlock()` to read. A second copy of `sub !== 'free' && blockStyles[sub]`
somewhere else would be a second thing to keep in step, and the day the two disagreed the
sign would go blank. **The condition includes "and a standard is actually stored for this
type"**, which is not defensive padding: a half-seeded install has no row, both renderers
already fall back to the element's own columns for it, and `rehearse_phase1.php` exists
partly to look for exactly that.

**`copyLayout()` is the writer that looks like an exception and is not.** It copies rows
verbatim, and copying a pre-fix row faithfully would carry a fossil onto a sign that never
had one. It asks the same question, so invariant 32 holds for every row the module writes
rather than for the rows one of its two writers happened to write.

**One thing narrowed as a side effect, and it narrowed the right way.** #41's rule — a
colour nobody can read is published as stored rather than replaced with black — now only
applies to a block that owns its colour. An unreadable colour on a *branded* block is the
Brand's, one row in `block_styles`, which `ColorAudit` was already auditing directly.
Before, that one bad value was copied onto every price on every sign and reported as
though there were eleven separate faults; now it is reported once, where it can be fixed.

**What the suites had to be taught, and the check that was quietly proved wrong.** The
layout suite's own baseline layout uses a `price` block, and one check on it — *"the clamp
reaches the column, which is the half a pure function cannot prove"* — went red
immediately. It would have gone **green again on its own** if the fix had been written
slightly differently: the stored line height became 1.40, which is both the clamp's
default and the value a branded block now stores, so the check would have passed while
proving that the value never arrived rather than that it was clamped. It was moved onto a
`free` block. That is the failure mode invariant 30 is about, caught by arithmetic rather
than by a mutation run, and it is worth noticing that the check *did* fail first — a
version of this fix that stored the payload's clamped value instead of the default would
have left it silently vacuous.

Mutation over `lib/brand_styles.php` kills the new condition at the `assertion` grade in
both directions, with three equivalent survivors (`!==` → `!=` between two non-numeric
string literals, which cannot differ) and one honest `fatal`: the `is_string` guard is the
module's own door, `LayoutRules` refuses a non-word `block_subtype` long before publish
reaches it, and removing the guard throws on an array offset rather than answering wrongly.
That grade is recorded rather than engineered away.

### 4ba. The panel that looked like a window, and the arithmetic behind it

v2 step 2. No schema, no new data, no behaviour a server can see — the Builder's
chrome, rebuilt as option B. It is in §4 rather than in the roadmap alone because
the thing that started it was reported as a matter of taste and turned out to be a
number.

**The report.** *"The block properties window appears to overlay a top div. It gives
a false feeling of the capability to drag the properties box around the screen."*
That is a design complaint, and it was right, but the overlap it points at is not a
design decision anybody made. `#inspector` was `position: fixed; right: 16px; top:
100px`. Above it sat a stack of up to five full-width bars — nav, lock banner or one
of the holder's four, the turned-off notice, the control bar, the align bar — whose
combined height depended on which of them were showing, and on a 1080p window with
the control bar wrapped to two lines it exceeded 100px. So the panel landed on the
bar above it. **And the two things that made it worst arrived together**:
`showInspector()` calls `updateAlignBar()`, so selecting a block revealed the align
bar and the panel in the same event, and the panel's `top` had been chosen against a
page that did not have one.

**Why "looks draggable" follows from that, rather than being a separate problem.** A
panel that is the only positioned thing on the page, that overlaps its neighbours,
and that appears and disappears, has every property a window has except the one that
would make it usable. Every fix short of the structural one is a better number for
`top`, which is the same defect one window size later.

**The fix is that there is nothing positioned any more.** `#workbench` is a flex row
and the three columns are its children; a sibling cannot overlap a sibling. What that
bought beyond the report:

- **The rail stops disappearing.** `deselectAll()` used to set
  `inspector.style.display = 'none'`, and it runs on every click on empty canvas — so
  a 290px panel came off the screen and the canvas reflowed under the pointer,
  repeatedly. It now swaps between two states and never moves.
- **The resting state is not a placeholder.** For an admin it carries the canvas
  background, which is a property of the canvas rather than of any block and had no
  home once the control bar went. The left column is *what you can put on the sign*,
  and a background is not something you put on.
- **The stack of bars is gone as a category.** Four of the five were horizontal
  strips competing for the same vertical space; `#align-bar` is the one that retired
  outright, into an *Arrange* group in the rail beside the block it acts on.

**One control had no home at all and nobody had noticed.** The zoom buttons lived in
the control bar, and neither option B as pitched nor the nav sketch had a slot for
them — an omission in the mockup rather than a decision, found while moving the
publish line. That is what produced the canvas footer, which is where they belonged
anyway: zoom is a property of how you are looking at the canvas, so it sits with the
canvas. The publish line sits at the other end of the same strip, and it is drawn for
**read-only Builders too**, which is the case it was originally written for.

**The left column's rule is a boundary in the markup, not only a line.** Everything
below it is an editing control and is not emitted for a read-only Builder; the block
above it always is. `Switch display` has always sat outside the read-only branch —
somebody looking at a sign they cannot edit still has to be able to leave it — and
when the Brand control lands (step 4) the same will be true of knowing which venue
you are looking at. So a read-only Builder now keeps a left column carrying those two
things, which is more than it had.

**Three checks were about the old chrome, and rewriting a check is where #50 lives.**
Each was hand-mutated first and seen to fail before the new form was kept:

| Was | Now |
|---|---|
| `emittedOnlyWhenEditable('<div id="align-bar">')` | the rail's own gate, plus *the align bar is gone rather than hidden* — asked of `id="align-bar"` and the lookup, because the prose explaining the retirement mentions the name several times |
| `PRESENT` holding `control-bar` | the three ids a read-only page really gets: `palette`, `canvas-column`, `canvas-footer`, and `pub-state` inside it |
| `inspector.style.display === 'none'` | the rail is in exactly one of its two states, and **the rail itself is never shown or hidden by either** — which is the property the display check was standing in for, and one the old code could not have had |

The last row is the interesting one. The old assertion tested the mechanism; the new
one tests the intent, and it can fail in a way the old one could not: a rail saying
*"nothing selected"* above a populated set of block controls is a state the old code
had no way to reach and the new code does.

**What is still owed.** The browser pass describes every page this touched, and it
gets re-walked — five of its seven defects were things a page did not *say*, which is
the category no harness here is pointed at, and this step rewrote most of the saying.
`interact.js` is still driven by nothing, and a CSS rule that does not apply at 1080p
is invisible to all six suites. **A green gate is not a working screen**, and that
sentence is load-bearing for this step in particular: nothing in this repo can see a
three-column layout fail to be three columns.

---

### 4bb. The word Brand went back to what it means, and a key changed under a live table

v2 step 3, and the high-risk one: it re-keys a table on a database that is driving
signs. ADR-0011 reversed roadmap decision C — one set of Brand Standards for the whole
installation becomes one per **Brand**, a named reusable identity that several Displays
share. `block_styles` is keyed on `(brand_id, block_type)`, `displays.brand_id` is
`NOT NULL`, and a `brands` table carries the palette, the logo asset and the default
canvas background.

**The name had to be freed first, and that landed as its own commit.** `lib/brand.php`
held a class called `Brand` whose contents were the *navigation bar's* colours —
which `CONTEXT.md` calls a Workspace Theme, and which never reaches a Screen. The
vocabulary file has been unambiguous about this since it was written; the class simply
predated it. It is `SiteChrome`, in `lib/site_chrome.php`, and not `WorkspaceTheme`,
because a Workspace Theme is per-person and chosen and this is still one set of colours
for the whole install read out of a generated file — step 5 is what turns it into the
other, and it keeps these four method names when it does, so every call site survives
that change too. 65 references across 16 files, no behaviour change, gated on its own.
Doing it inside the schema commit would have made the risky diff unreadable.

**The gate on the re-key reads the key's columns, not whether a key exists.** This is
the only statement convergence issues that *replaces* structure rather than adding to
it, and the difference matters twice over. Every table has a `PRIMARY`, so a gate built
on `hasIndex()` would answer the same before and after — the statement would either
never run or run on every request. And unlike a duplicate `ADD COLUMN`, which fails
harmlessly, a second `DROP PRIMARY KEY, ADD PRIMARY KEY` does not fail at all: it
rebuilds the table. So `SchemaFacts` learned `indexColumns()` and `needsPrimaryKey()`,
and `readSchemaFacts()` orders by `SEQ_IN_INDEX`, because `(brand_id, block_type)` and
`(block_type, brand_id)` are different keys and a set comparison would call the re-key
already done. An index recorded without its columns answers **null** — "I did not
look" — and not an empty list, which is the same three-valued discipline the rest of
that file already had.

The order is add-nullable, backfill, tighten, re-key, and the tighten is written out
rather than left to MySQL's silent conversion of a nullable column into a `PRIMARY KEY`
member. Relying on that would work and would leave the `MODIFY` in the plan on the next
request as well, since both gates were decided from one catalogue read taken before
either ran — and an `ALTER` that "does nothing" takes the same metadata lock as one
that does (§4o).

**Two things the plan did not mention, found by building it.**

The first is that `seedBlockStyles()` had never run in a test. It was a single
`INSERT IGNORE`, which SQLite rejects — and the suite's default engine is SQLite, so a
`true` return could only ever mean "the count found all six and the statement was never
sent". The inserting half was unreachable from the one engine that runs by default, and
the SQLite check was *written that way on purpose*, using the rejection as its witness.
Re-keying forced the statement to name a `brand_id`, and the replacement computes the
absent `(Brand, type)` pairs and sends plain `INSERT`s, which both engines run. The
suite now watches it seed for real, including seeding a second Brand — which on the old
key would have been six duplicate-key failures. That is #11's rule collecting another
one: a statement only one engine can execute is a statement the fixture cannot test.

The second is not about this feature at all. The fixture's temp-directory cleanup was
one level deep, and the install-paths section writes `private/db_credentials.php` — a
file inside a subdirectory. `rmdir` on a non-empty `private/` failed, so `rmdir` on the
parent failed too, and **every run of the suite since had left a directory in `/tmp`**.
429 had accumulated. The directory name is keyed on the process id, a container reuses
those freely, and one run inherited an older run's credentials file whole and failed a
check that had nothing to do with the change under test. It is worth stating which way
that fails: this time it produced a false *failure*, which is the harmless direction. A
check that reads a file some earlier run wrote can pass for the wrong reason just as
easily, and nothing would have said so. Cleanup is recursive now and the directory is
emptied before use.

**Where the risk actually sits, and what does not cover it.** The re-key statement
itself is MySQL-only, so no suite in this container executes it — and this container has
no MySQL server, so the layout suite's MySQL arm did not run either and its expected
check count is derived rather than observed. `tools/rehearse_phase1.php` is what covers
the statement, and it grew the checks to do it: the primary key's columns read back out
of `SHOW KEYS`, both backfills asked as rows rather than as structure, `brand_id`
`NOT NULL` on both tables, and the `RESTRICT` rule on `displays_ibfk_3`. That tool runs
against a copy of live data, by a person, and **it has not been run** — the rehearsal is
step 3's stated gate and it is owed before this goes near the shop. So is the browser
pass, which §4ba already owed and which this step adds a rewritten Display Branding tab
to.

**A note on what the mutation runs found**, since invariant 30 is the reason to do them:
the first run over `lib/brands.php` survived on nearly every accessor, because the
module had no section in the suite at all — the Brand tests written at that point were
about `BrandStyles` and `DisplayStore`. Writing that section then found two real
defects that no gate had: `BrandAdmin::create()` refused a Brand created from a name
alone, because an absent background was being read as an unreadable one rather than as
"none supplied" (#21's line, in the direction that refuses a legitimate save); and the
duplicate-name refusal quoted the name that had just been *typed* rather than the name
of the Brand actually holding it — so somebody typing `salmon house` was told the clash
was with `salmon house`, a string that appears nowhere in the list they were about to
go and look at. `otherBrandNamed()` answers the Brand rather than a boolean for exactly
that reason: a predicate can only echo its input back.

Six more survivors turned into checks: `destroy()` carried a dead clause refusing an
empty typed name, which `strcasecmp` already refused (removed, not decorated —
§4az's dead clause was the one that could still be wrong); `wornBySentence()`
truncates at six signs and says "and N more", and no test had ever had more than one
wearer; `forId()`'s `isIdLike` guard, reached straight from `$_POST['b_id']` on the
save and delete forms; `NAME_MAX` and `PALETTE_SLOTS` asserted as literals, because a
check written as `str_repeat('a', NAME_MAX)` moves with the constant and passes just
as happily at a width the column does not have; and `otherBrandNamed()`'s default
`exceptId` and its `cleanName()` call, neither of which anything had exercised — the
second meaning untrimmed input was a way past the clash check.

The last one is the most useful and was the least obvious. Every literal in
`BrandStyles::STARTING_POINTS` survived, because nothing asserted what a new Brand
*starts as*. That list has three writers — `seedFor()`, convergence's step, and
`schema.sql`, which cannot call PHP and therefore holds a copy — and the docblock
claims they are one list. They now are: the suite parses `schema.sql`'s seed and
compares it field by field, and the check was hand-mutated (a `30` to a `31`) and seen
to fail before it was kept. A drift there is silent and permanent — a fresh install
would start its first Brand somewhere every later Brand does not, both sets render
fine, and only their disagreement is wrong.

**The final counts, and what they are not.** `brands` 64/100 killed, `brand_admin`
54/87, `brand_styles` 64/95. The remaining survivors are dominated by `===` → `==` on
comparisons where both sides are already strings, and by threshold literals inside
clamps whose *return* values are separately asserted — equivalent mutants, not gaps.
Two are worth naming rather than chasing: `forId()`'s `if ($id <= 0)` survives because
the query below it finds nothing for 0 anyway, so the guard saves a round trip rather
than changing an answer; and `logoAssetId()`'s `&&` survives because `SELECT *` always
returns the column, so the `isset()` half can never be the deciding one. Both are
worth keeping and neither is testable without making the code worse, which is what
invariant 30 means by a reason to write down.

### 4bc. A venue you can look at before you commit to it

v2 step 4. Step 3 gave a Display a Brand and gave an admin a page to edit Brands on;
this is the Builder learning to say which venue it is building for, and letting an admin
put the sign on another one. Four surfaces and one write: the control at the top of the
left column, the palette offered above every colour picker, the Venue Logo item, and
Publish.

**Picking a Brand writes nothing, and that is the feature.** The canvas repaints in the
browser and the choice rides out with the next Publish, on the path the canvas
background already takes (decision 6). A Brand assignment reaches every block on the
sign and there is no undo behind a publish, so the person gets to *look* at it before it
is true. The suite's first section proves this by counting requests: zero. A version
that saved as you picked would be indistinguishable on screen and would change every
board in the venue the moment somebody browsed the menu.

**Five things the plan got wrong or did not anticipate.**

The plan listed the colour controls the palette appears above as "marquee text and
background, section, free text, canvas background". **There is no section colour
control.** A section carries a background *image* — a path with a `|fit` suffix — and
nothing else; it has never had a colour of its own. So there are four, not five, and the
suite drives its list from the four ids rather than from the plan's sentence.

The plan said "repaints the canvas in the browser" and left the mechanism open. It has
to be **`restoreCanvas(snapshotCanvas())`**, the pair the undo history already uses, and
the reason is invariant 32: a branded block's own six typography fields are not on the
node, because publish deliberately stopped carrying them. So a repaint cannot be a walk
over the blocks re-applying styles — `applyTextStyles()` needs the *element* to fall back
to, and the only thing that can produce one is the serializer. Going through the pair
also keeps `renderBlock()` the one place that knows how an element becomes a node, which
is what that invariant's comment asks for in so many words. What it costs is the
selection: a switch puts the rail back to its resting state. That is arguably right —
the swatches under it have just changed — and it is the price of not having a second
renderer.

The plan did not mention that **the publish has to re-read the Display**.
`insertContent()` decides whether a column is the Brand's to paint by asking
`BrandStyles::paints()` with the standards of `$display->brandId()`, and on the one
publish that *changes* the Brand, the Display it was handed is wearing the old one. The
rows are about to be read under the new Brand, so that is the Brand that has to decide —
which is the rule `copyLayout()` already states one method over about a duplicate's
target, arriving by the door that had not needed it. Left alone it is invariant 32's
fossil with a new entrance, and the observable difference is exactly one field: a `price`
block publishing onto a Brand with no `price` standard keeps its own size, and onto one
that has it stores the documented default. The suite checks both directions, because a
check in one direction alone passes on a Brand nobody was painting with. Both were then
*seen* to fail: the fix is one identifier, so it was hand-mutated back —
`replaceWholeLayout($display, …)` in place of `$wearing` — and the two checks failed with
`expected 48, got 16` and `expected 16, got 48` before it was restored. That is invariant
30's grade `assertion` obtained by hand, on a line `tools/mutate.php` has no mutation for:
swapping one variable for another in scope is not on its list, and a check nothing can
break is #50's whole complaint.

The plan did not anticipate **the state where there is no Brand at all**. A database
whose convergence has not run has `displays.brand_id` at 0, and there the page draws no
control (an empty box captioned *Brand* reads as something that failed to load) and sends
**no `brand_id` at all**. Sending 0 would be an id naming nothing, `BrandChoice` would
rightly refuse it, and a lagging schema would become a sign nobody can publish to —
invariant 10 as a live consequence rather than a caution. An absent field means "leave
the Brand alone", which is exactly what that page has to say.

And the plan said nothing about **the Brand's default canvas background**, which the
Builder deliberately does not apply. It is what a *new* Display starts from; this
Display's background is its own. Left unsaid that is the first question somebody asks
when a venue's colours appear and the canvas behind them does not change, so the toast
says it, and so does `help.php`. Five of the browser pass's seven defects were things a
page did not *say*; this is the same category, caught before rather than after.

**The read-only guarantee needed the walker taught one more derived variable.**
`$canPickBrand = $isAdmin && !$readOnly && $wearing !== null`, and
`selftest_builder_readonly.js` decides what a read-only page can emit by substituting
`$readOnly` and `$isAdmin` and enumerating everything else — so a *derived* condition
would have been tried both ways, one of those ways would have said yes, and the walker
would have believed a read-only page can emit the Brand menu. Substituted to `false`,
with a check pinning the derivation in the source, exactly as `$undoSteps` was handled in
§4ba. The control itself is the one entry that *joins* `PRESENT` as a feature rather than
leaving it as a leftover: somebody who cannot edit still needs to know which venue they
are looking at. Its read-only branch carries no ids, so `#brand-name` cannot resolve on a
page that draws a different one — two copies would make every lookup of it depend on
which branch the page took.

**A swatch has to run the handler the picker would have run.** Setting `.value` from
script fires no `input` event, so a swatch that only filled the control would move the
control and nothing on the canvas. The four targets are a table naming the row, the
picker, what to run, and whether it counts as an undo step — the canvas background does
not, matching ADR-0010's decision that the history leaves the background alone. The
marquee background's entry also unticks **Transparent**, because a transparent marquee
ignores its background colour: without that line, picking the venue's red would do
nothing and say nothing, which is the same defect wearing a checkbox. And the colours are
read through `readHex()` again on this side before they become CSS: the server already
answered `Color::read()` for them, but a value the CSSOM discards is not a swatch, it is
a grey box that does nothing — #41's shape, one control along.

**A number this container cannot check, and it was wrong.** Found while raising it:
§4bb added two checks to the layout suite's MySQL-only section and moved that arm's
expected count by three, so from step 3 until this step the MySQL run expected one check
more than the suite contains. Nothing here could see it — there is no MySQL server in
this container and that arm never runs — and the comment directly above the line warns
about this exact mistake ("a sum is a prediction that can be checked in one command;
check it"). It is no longer a delta: the MySQL figure is the SQLite figure plus a *count*
of that section, which is straight-line code with no loop and no conditional, so both
halves can be verified by reading rather than by running.

**The seventh harness.** `tools/selftest_builder_brands.js`, 121 checks, under the
premise of an admin who may switch: the switch sends no request, repaints the branded
block and leaves the block that owns its typography alone, records no undo step, and
leaves nothing for the next commit to mistake for an edit. Two additions to the DOM stub
were needed and both are load-bearing: `classList.toggle`, which both of this page's
menus use, and kept `document` listeners, so a click somewhere else on the page can be
fired — a menu that opens and cannot be closed is a real defect and an invisible one to a
stub that drops the handler. The read-only and basic-account cases went to the suite that
owns that premise rather than growing a fourth premise here.

**What the mutation runs found.** `lib/brands.php` 86 of 127 killed, 62 of those by an
assertion; `lib/layout_store.php` 232 of 334, 148 by an assertion. Both figures are from a
run over the code as it was finally committed, which is not the same as the run that did
the work — the two that found something were over the version *before* the `isAdmin()` fix
above, and the fix is in these numbers rather than being what they found.
`BrandChoice`'s own survivors are the `===` → `==` family on comparisons where
both operands are already strings, plus one worth naming: the `intval($id) <= 0` half of
`brand()` survives on its own, because `isIdLike` has already refused everything that is
not a whole number and the only values left for it to catch are `0` and negatives — both
of which `problemWith()` then refuses again by the same test. Two agreements about the
same thing, which is deliberate here (the factory's filter and the reader's guard) and
therefore not a gap. No new invariant: step 4's guarantees are the behaviour of one page
and are held by a suite, not by a rule that spans files.

**Still owed, and this step does not close it.** The browser pass has no step for the
Builder's Brand control, which is now the fourth thing on its debt table — and it is the
category that table exists for. Nothing in this repo can see a swatch row land on top of
a picker at 1080p, or a venue's name truncate in a 178-pixel column.

---

### 4bd. The application gets colours of its own, and they never reach a sign

*(v2 roadmap step 5, 2026-08-14. It is invariant 34.)*

The last step of the v2 plan, and the second of `CONTEXT.md`'s two nouns: a **Brand** is
what a customer sees on a TV, a **Workspace Theme** is what an employee's screen is
painted in. `workspace_themes` holds thirteen colour columns and `users.workspace_theme_id`
says which one an account chose; the Builder's gear picks, the Admin Panel's Site Branding
tab makes them, and nothing anywhere reaches a Screen.

**Thirteen roles, not the twelve the plan named.** The six chrome roles it listed omit the
**navigation border**, which is one of the four colours a shop can already set from Site
Branding. A theme that could not hold it would repaint the live nav the moment anybody
chose one — decision 9's "no sign moves" has an application-side twin, and this is it.

**There is no seeded row, and this is the change from the plan that mattered most.** It
said today's `branding_config.php` values "become a seeded theme named Store default". A
seeded row is a *copy* of that file, and the first Site Branding edit makes the copy
disagree with it while still being called the default — the two-readers defect
`SiteChrome::load()`'s docblock already refuses for the file itself, one layer out. So the
store default is not a row at all: it is the file plus the documented defaults, answered
by `SiteChrome` when no theme is worn, and `users.workspace_theme_id IS NULL` is how an
account says it wants that. Convergence therefore inserts nothing, backfills nothing, and
cannot repaint anybody — which is what makes this the low-risk step the plan called it,
rather than a migration that touches every account row.

**The layered read had to split in two, and that one was a defect a day from shipping.**
`SiteChrome::role()` answers the worn theme first, then the config, then the documented
default — and three callers want the config *as configured* rather than as painted: `all()`,
`unreadable()`, and the Branding form, which fills its four `type=color` inputs from it.
Through the layered read, an admin wearing a theme would have opened the Branding tab,
been shown their own theme's colours as "what is there now", and saved them over the
shop's on the next click. #21's shape exactly: the wrong value, stored, with a green
message. `configColor()` is the door those three take.

**Why the roles are CSS custom properties.** Not a style preference. The picker lives in
the Builder's gear, on a page that can be holding an hour of unpublished layout, and the
obvious implementation — post the choice, let the page come back painted — throws that
work away. A setting about a menu bar must not be able to do that. So the thirteen are
declared once per page in a `:root` block and used as `var(--…)` everywhere, and switching
theme is thirteen `setProperty()` calls with the canvas, the undo history and the edit
lock untouched. Three other things fell out of it: one validated echo per page instead of
the hundred-odd the alternative needed, a live preview in the admin form that costs
nothing, and — the one that matters for invariant 34 — decision 11 became *checkable*,
because a `var(--…)` in a stylesheet is something a check can find and a hex literal is
not.

**The canvas check fails three ways and each was seen to.** A theme role inside a rule
that paints the canvas; `--selection` used anywhere that is not the selection outline or a
resize handle; and the canvas-selector list going stale so the rule silently checks
nothing. The third found a real defect in the check itself: the text before a rule's `{`
carries the comment above it, and the comments above those very rules name `.selected` and
`.rh` — so the check could have been satisfied by its own documentation. Comments are
stripped first now. Its limit is stated where the others are, at the bottom of
`check_invariants.php`: it classifies a rule by a *list* of canvas selectors, so a
genuinely new one has to be added by whoever writes it, and what stops the list rotting in
silence is its own count assertion.

**The picker renders un-themed, and so does the way to it.** Decision 14 says the control
for changing your theme must not be drawn in your theme, and every colour in `#theme-pick`
is a literal. But a picker you cannot reach is not legible either, and the way to it is a
grey glyph on a themed nav bar — so `$gearNeedsChip` asks `Color::hardToRead()` at render
and the gear wears a fixed chip exactly when it would otherwise vanish. Today's nav is
dark and the glyph is light, so nobody who has never made a theme sees any change. That is
`RequestScheme::isSecure()`'s shape: a protection that cannot apply is reported rather than
applied flat.

**A closed account had to let go of its theme.** Found by asking what
`accountsUsing()` would say about somebody who can never sign in again: a closed account
keeps its `workspace_theme_id`, so `users_ibfk_1` refuses that theme's deletion for ever
and the Admin Panel's refusal names a person who will never use it. That is the edit
lock's rule one table further out — *a change to what somebody may reach frees what they
are holding* — and `AccountAdmin::close()` now clears it inside the same transaction that
revokes the grants and releases the locks. Closure and not suspension: a suspended account
is coming back and keeps its choice, which is the distinction `markClosed()` exists to
draw.

**One rule is now written in two languages, deliberately.** The contrast warning has to
appear while somebody drags a colour picker, which cannot ask the server per frame. Only
the *arithmetic* is duplicated — WCAG's luminance formula — while the threshold and the
words are printed from `Color::READABLE_RATIO`. And the two copies are not checked against
each other: both are checked against the standard's fixed points, 21:1 for black on white
and 1:1 for a colour on itself, in `selftest_layout.php` and `selftest_builder_theme.js`.
A formula from a standard is a safer thing to write twice than a decision would be.

**Three visible changes on an install with no theme, all deliberate.** The Display
picker's notice was its own dark red beside the off banner's, two banners meaning the same
thing in two colours with no reason written down. The Help page was drawn in its own
slightly different blues, and a theme cannot paint "almost the work area". And **two pages'
navs ignored Site Branding entirely** — the Admin Panel's and the Asset Library's, both
hardcoded, so a shop that set its own navigation colour got it on the Builder, the Help
page and the sign-in page and a stock `#1a252f` on those two; they reach the roles now, so
those bars will change to match the rest of the app on a shop that has customised it. The
Asset Library was the one page this step almost missed: it is signed-in, reachable from the
gear, and decision 12 says a theme applies to every signed-in page, so a themed Builder
beside an unthemed library would have been the plan's own promise broken in the one place
nobody looks.

**What a theme does not paint, and why the Admin Panel wears fewer roles.** That page is a
light document — white cards on `#f0f2f5` — and only its nav bar and its buttons are
chrome in the sense the roles name; `--work-area` is the dark space behind a canvas, and
mapping the panel's paper onto it would turn the Admin Panel black. So the roles reach
every signed-in page and how much of a page they paint depends on how much of it is
chrome. The hairline borders and glows beside a themed surface stay literal too: a shadow
cannot be derived from a custom property without `color-mix()`, and thirteen roles is a
theme that paints surfaces, not a full restyle.

**The eighth harness.** `tools/selftest_builder_theme.js`, 110 checks, under a premise no
other suite holds: an unsaved layout on screen while somebody changes a setting about
themselves. It holds the canvas nodes by identity across a switch, watches the undo
baseline not move, and drives both failure paths — a reply that says no and a request that
never arrives — because the paint happens before the round trip, so a swallowed failure
leaves the screen showing a theme the account is not on. `selftest_builder_readonly.js`
gained the other half: the picker reaches a read-only Builder in full, which is the
distinction between the two nouns in one assertion.

**What the mutation runs found.** Over the code as finally committed: `lib/workspace_themes.php`
56 of 65 killed, 27 of those by an assertion; `lib/site_chrome.php` 26 of 36, 13 by an
assertion; `lib/color.php` 32 of 39, 22; `lib/picker_name.php` 22 of 27, 18; and
`lib/accounts.php` 156 of 241, 130 — that last file because this step put a method in it
and one line inside `close()`'s transaction, and that line is killed by an assertion
rather than by the harness noticing something moved. Measured, for the reason §4bc's
paragraph now says out loud.

The runs *during* the work found four things, and all four are fixed inside those figures
rather than being what they report. `WorkspaceTheme::colors()` was dead — five mutants
lived inside it because nothing called it, and it is deleted rather than covered, which is
why that file has 65 mutants and not 70. Two id guards were being asked about rows that
did not exist, so `forId('1abc')` and `forAccount('2abc')` answering null was not the
guard's doing and the checks could not have failed. The `array_key_exists` guard in
`colorFor()` had nothing asking it for a role the row has no column for, which is the
whole case it exists for. And `storeColors()` — the value behind "use the store default" —
had never been executed on the PHP side at all.

The runs *after* them asked for eleven more checks, which is the suite moving from 2257 to
2268. Five are contrast, and they are the sharpest thing the tool has found here: every
fixed point this step had written was black, white, grey, or a colour on itself, and a
grey is precisely the input a mix-up of channels cannot change — so both substring offsets
and both weightings in `luminance()` could be moved and the whole suite still passed. Red,
green and blue on white are 4.00, 1.37 and 8.59, three numbers that agree with only one
reading of the bytes, and two of them straddle the threshold in opposite directions. Three
are a blank-named row, inserted in SQL because no form can make one: `clashIn()`'s empty-name
guard exists so an unvalidated name does not match it, and until now nothing had put such a
row in front of it to match. One calls `clashIn()` with two arguments — both stores hand it
three, so its own default was reached from nowhere, and a default of 1 rather than 0 would
have excused the first row anybody ever made from every clash check in the app. Two tell
`SiteChrome::unreadable()` about a colour defined as blank rather than not defined, which is
`=== null` sitting one character from `== null`, and the difference is whether the person who
emptied that line is ever told.

What lived is four families, all of them named before this step. `===` → `==` and `!==` → `!=`
where PHP 8 already guarantees both operands' type. `$out = [];` deleted in front of an
append, which PHP auto-vivifies, so the line declares a type rather than doing anything.
`intval($id) <= 0` behind an `isIdLike()` that has already refused everything except zero
and negatives — §4bc's two agreements about the same thing. And three in `site_chrome.php`
— `load()`'s body, `logo()`'s `&&`, and `FIELDS[$key][0]` read as `[1]` — which all survived
because the process running the suite already has the branding constants defined: the
failure CLAUDE.md names as an absent-setting check running where the setting was present.
Killing those needs a subprocess, which this suite has machinery for and that paragraph
declined to spend on them. The paragraph below then spent it for a different reason and
took the third one with it: a process where `BRAND_NAV_BG` is a dark red is a process where
reading the *label* out of `FIELDS` instead of the constant's name paints the wrong colour.
One check answering two questions is the usual shape of this — the other two are still
alive, and still worth a subprocess apiece the day anything near them moves.

**And one survivor was a question rather than a family, which the owner then answered.**
As first built, `stored()` handed a worn theme's value back exactly as stored, so an
unreadable one was refused a layer later by `pick()` — which answers the **documented**
default, not the colour the shop put in `branding_config.php`. For the four config-backed
roles those are two different colours on any install that has branded its nav, so a theme
with one bad value took the shop's colour off the page rather than falling through to it.
The file argued for that at the time: an almost-right screen is harder to notice than an
obviously default one. The owner decided the other way, and the deciding argument is the
better one — "use the store default" means `branding_config.php` in every other sentence of
this step, and a phrase cannot mean two things one method apart.

So `SiteChrome::themeColor($role, $stored)` is now the seam: the theme's value when this app
can read it, otherwise whatever the store default paints for that role, which for the nine
roles with no config line *is* the documented default. `role()` calls it when a theme is
worn and `configColor()` when one is not, and the two other places that resolve a theme
that is **not** the one being worn go through the same method rather than a second copy of
the layering — `toClientArray()`, so a theme the picker switches to paints what wearing it
paints, and the panel's swatch row, so the square in the list is the colour the page will
take. The theme form's inputs come from it too, which means a new theme now starts from the
shop's colours instead of the shipped ones: the first thing an admin changes is one square
of their own shop rather than thirteen back to it. Nothing became a silent substitute —
`WorkspaceTheme::unreadable()` still names every value that could not be used, the table
still prints that list, and its sentence now says *store* default because that is what is
drawn.

**And it took three checks, two of them in a subprocess, because the suite could not see
the difference at all.** This container has no `branding_config.php` — it is server-side and
deliberately outside the repo — so the shop's colour and the shipped one are the same string
here and a fallback to either passed. That is exactly why the mutation run that moved this
line lived. The fix is `inFreshProcess()`, the machinery `StoreClock`'s absent-setting branch
already used: one process with `BRAND_NAV_BG` defined to a dark red and a theme storing
`darkblue`, which must paint the red; one with nothing defined at all, which must paint the
slate the app ships with. A layering no process here could distinguish is a layering the
next person can change without anything noticing — twice, now.

**No ADR, and that is a decision rather than an omission.** An ADR here would record
"typography and colour belong to a Brand" 's mirror image — the application's own colours
belong to a person — and ADR-0011 already draws the line this sits on the other side of.
What an ADR is *for* is the rejected alternatives, and the two that mattered are above:
the seeded "Store default" row, and the picker that posts and reloads. Both are recorded
with what they would have cost, which is the same service, in the file the roadmap's step
already points at. Invariant 34 is where the rule itself lives, because it is a rule that
spans files rather than a decision somebody might re-open.

**Still owed, and this step does not close it.** The browser pass now has *five* things on
its debt table: nothing in this repo can see whether a light theme leaves the Builder's
white-on-dark text unreadable, whether the picker card sits where the gear menu can show
it at 1080p, or whether the contrast warning wraps.

### 4be. The suite had only ever run on an install nobody has

§4bd ends on a fallback that no check in this repo could see, because this container has
no `branding_config.php` and so the shop's colour and the shipped one are the same string
here. Two `inFreshProcess()` checks closed that one line. The question worth asking next
was how big the hole around it was, and it is answerable in ten seconds: define the ten
names the generated file defines, then load the suite.

**Of 2271 checks, seven noticed — and all seven noticed by failing.** Two said the shop's
navigation is `#1a252f`, which is the colour the app ships with and not the colour any
branded install holds. Five said the clock is in `America/Los_Angeles`, which is the
setting's default and not the zone the live host was observed on (§4ap). Nothing else in
the file changed its answer at all. So the state of it was: one configuration exercised,
the app's other configuration — the one every shop is actually running — never, and
seven checks that would have failed on the live install, on the very run somebody makes
to convince themselves a deployment went well. `tools/` **is** uploaded (`DEPLOY-SKIP` §B
puts it behind an `.htaccess`, not off the server), so that run is a real one.

**None of the seven was wrong about the app.** Each was written as a literal where it
meant "the store's own", which is the same slip §4bd's fallback was: a phrase that means
`branding_config.php` everywhere else, spelled as `DEFAULTS` because on this machine
those agree. The fix is to say the thing — `SiteChrome::configColor()` rather than
`SiteChrome::DEFAULTS`, `StoreClock::zone()` rather than the zone this checkout defaults
to — and where that would have made a check compare a function with itself, to move the
literal into a process that has been *told* what the setting is. The clock arithmetic
keeps its fixed point (2:15pm, five lines up, where the zone is an argument); what the
caller checks now assert is that the caller went through the door at all, which a bare
`strtotime()` still fails by the hours the check above it measures. Two new checks carry
what the rewrites gave up: `apply()` in a process pinned to Auckland, and the Branding
form reading the shop's own colour while the page around it wears a theme — the save that
would otherwise overwrite the shop's file with somebody's night shift.

One more check changed for a different reason. `checkSame([], SiteChrome::unreadable())`
asserted an empty list, which is a property of *this checkout's* config being clean rather
than of the seam: it passed on a machine with nothing wrong, while the theme it was worn
with had nothing wrong either. It now wears a theme holding a colour this app cannot read
and asserts that value is not in what the audit reports. That is the sentence it was
always meant to be, and it holds on a damaged install too — which matters, because a
suite that goes red on the customer's data rather than on the code is a red line people
learn to skip.

**`tools/selftest_installed.php` is what keeps this from happening a third time.** It runs
the same suite three more times, one subprocess per configuration because a `define()`
cannot be undone: *branded* (all ten settings changed, a zone eleven hours away), *live-like*
(only the zone, because most installs have edited one setting and not ten), and *damaged*
(a colour in the config that this app cannot read). It asserts its own arms against
`BrandingConfig::DEFAULTS` before it runs anything, since an arm that accidentally *is* the
default configuration would prove nothing and report it in green. Put the two literals back
and it fails all three arms, naming the check and both colours — which is invariant 30's
discipline applied to a harness rather than to a line: it was watched failing before it was
believed.

What it does not cover is the shape of the same problem one level out. This suite has now
been run in four configurations and two engines, and every one of them is still a process
on a machine with no shop attached: `php -l` here is 8.4 against an 8.2 floor, the MySQL arm
needs a server this container has never had, and the five browser walks are still owed. A
configuration you can define is the cheap half.

### 4bf. And the node suites were running a page where every server value was zero

The same question, asked of the other eight harnesses. They cannot run PHP, so each one
stripped `<?= … ?>` to the literal `0` and then wrote a handful of the page constants
back by hand. `0` parses everywhere the page interpolates a value, which is what made
that work — and is exactly what made it silent. Twenty-one values reach `builder.php`'s
JavaScript and three reach `viewer.php`'s; what a suite did not think to write back was
zero, and nothing said so.

**Measured the same way: set all twenty-one to what a real page carries, re-run all
eight, and not one check changes.** So unlike §4be's seven, none of these was *wrong* —
but none was seen either, and two of the zeroes were doing damage that a passing run
could not show.

`LOCK_LAPSE_SECONDS` and `LOCK_WARN_SECONDS` were 0 in all eight. The idle warning is
drawn when `idle >= WARN && idle < LAPSE`, which with both at zero is false for every
idle there has ever been. The bar that tells somebody their edit lock is about to lapse,
and the countdown inside it, were not untested — untested is a thing a person can notice
— but **unreachable**, which nobody can. `selftest_builder_uploads.js` now drives all
three bands and the `Math.max(1, …)` floor, which is the only arithmetic on that line and
the one read at the worst moment: five seconds left rounds to zero minutes, and "0
minutes left" beside a lock that is still yours reads as too late to save anything.

`CANVAS_W` and `CANVAS_H` were 0 in `selftest_viewer.js`. `scaleToFit()` divides the
window by them, so the one piece of geometry a customer looks at computed `1920 / 0`,
set `scale(Infinity)` and NaN margins on every run of that suite, threw nothing, and was
asserted by nobody. It now has the two shapes that matter: a 4:3 Screen showing a 16:9
sign, where `Math.min` letterboxes and the band is split evenly, and a short wide one,
where it pillarboxes — both, because a `min` written as `max` gets one of them right.

**`tools/page_constants.js` is where the silence became a declaration.** One value per
constant, chosen as what the page really carries, with the empty ones empty *in type*
(`BRANDS` is `[]`, not `0`, because the page iterates it). The thirteen chrome-role names
are read out of `lib/site_chrome.php` rather than copied, so a fourteenth role appears
there the day it appears here. And it refuses two things that used to pass unnoticed: a
constant the page interpolates that nothing names — add one to `builder.php` and every
suite fails until this file says what it is — and an override for a constant the page
does not have. `selftest_builder_readonly.js` had already worried about the second, for
one name, with one line: `check(/var CAN_PICK_BRAND = false;/…)`. It is the same failure
as the `lock-holder` entry that sat in that suite's PRESENT list for months pointing at
nothing, and it is now checked for all eight, in that suite, because
`check_invariants.php` reads PHP and these are JavaScript.

**Four of the eight also had no count anchor**, which is the third of the three ways
`selftest_layout.php`'s own header says a suite reports "0 failed" while broken: delete
half of `selftest_viewer.js` and it printed a clean run and exited 0. All eight carry one
now.

What this did *not* find is a wrong answer, and that is worth saying plainly rather than
letting the section imply otherwise. The eight suites were right about everything they
asserted. The finding is narrower and duller: they were asserting it about a page nobody
would ever load, and two things a person does — running out of time on a lock, watching a
sign on a differently shaped television — were on the other side of that difference.

### 4bg. And the whole suite was running on one server, describing servers

The third time the same question was asked, and the one where the answer was already
written down in the file it was asked about.

§4be found the suite running in a `branding_config.php` no shop has. §4bf found the node
suites running a page where every server value was `0`. Both are the same shape: the
configuration the tests run in is not the configuration the app ships into. The dimension
neither pass touched is the **machine** — this container's PHP, its `php.ini`, and a
request that is never a request.

Which matters here more than it would in most apps, because this one has a Settings tab
whose entire job is to describe the machine it is running on.

**`ServerReport` already knew the rule and had applied it three times.** Each of these is
public, pure, and takes the value rather than reading it, and each says in its own
docblock why:

| seam | takes | because |
|---|---|---|
| `phpVersionNote($id)` | a version id | "two of the three cases are unreachable on whatever machine happens to run the test" |
| `storeZoneNoteFor($stored)` | the stored setting | "a `define()` cannot be undone and a running installation is not in that state" |
| `UploadLimit::smallestOf($values)` | ini values | "PHP_INI_PERDIR — they cannot be set at runtime, so the interesting cases are unreachable through `bytes()`" |

The reasoning is correct and it is stated three times. It was never carried to the
neighbours — and **the four places it was not applied are exactly the four with no
checks.** Not approximately: exactly.

- **The PHP time zone note.** Spelled inline as `ini_get('date.timezone') === ''`. The
  speaking branch fires on a host whose php.ini has no such line — and `php -d
  date.timezone=` does not reproduce that, PHP rejects the empty value at startup and
  substitutes UTC. So that sentence was unreachable by any means, on any machine, and the
  silent branch was the only one this suite could ever produce.
- **The upload ceiling note.** Inline against the two PHP_INI_PERDIR settings. It had one
  form here — the one naming `2M`, forever — and nothing asserted it. It is the sentence
  that tells an admin whose rule refused their video and which two settings to go and
  edit, so getting the names wrong in it would have been invisible.
- **`ErrorPolicy::status()` — the entire Settings-tab readout of what happens when
  something breaks — had no check of any kind.** `admin_panel.php` prints it through the
  same `[value, note]` loop as `ServerReport::runtime()`; `runtime()` has a check that
  every fact in it is a printable pair, so the panel cannot be handed an object.
  `status()` did not, and nothing had ever called it. That is the worst of the four, and
  the reason is in its own docblock: every part of what it reports fails *silently by
  design*. An unwritable directory means no log. No recipients means no alert. Both look
  exactly like nothing having gone wrong. The readout is the only way to see the
  difference, and it was the one thing here nobody had looked at.
- **The log's request tag**, which is the one that turned out to be an actual defect
  rather than an untested branch. `whichRequest()` read `$_SERVER['SCRIPT_NAME']` with a
  `?? 'cli'` fallback written for the command line — and never once reached it. PHP sets
  `SCRIPT_NAME` on the CLI too, to the script path, and under `php -r` to the literal
  string **"Standard input code"**. So every tool in this repo tagged its JSON-failure log
  lines with a filename, and the six arms of `selftest_installed.php` tagged theirs
  `json-reply|…|Standard input code` — PHP's own internal English, arriving in a log a
  person reads to find out which page broke. `PHP_SAPI` is what actually answers the
  question the fallback was asking.

**The fix is the two halves the last two passes each used one of.** The seams —
`phpZoneNoteFor()`, `uploadCeilingNoteFor()`, `requestNameFor()` — are what reaches cases
no flag can produce. The arms are what proves the *real* reads still work: three more in
`selftest_installed.php`, on a second axis, because an install is two things and only one
of them is in `branding_config.php`. A generous host (64M/128M, where the app's own 50 MB
ceiling binds and the note falls silent), a tight one (1M/1M, so the sentence is produced
twice with different numbers rather than once with the only pair this container can make),
and one showing errors. Both axes validate themselves before running: an arm set to what
this machine already holds is refused with a sentence, because it would agree with the
plain run and say so in green.

That the axis works was **watched, not assumed**. Three checks asserting this container —
that the largest file is 2 MB, that errors are off, that the process zone is UTC — pass
the plain run and are each caught by exactly the arm built for them.

**And the gate**, which is the PHP half of what `page_constants.js` does for the node
suites: `check_invariants.php` now holds the whole set of places `lib/` reads the machine
— four files, listed — and fails when a fifth appears. Not a ban; this app reports on its
own host and somebody has to read it. It is a rule that the list is short, named, and
grows on purpose, because a new read landing quietly is a branch the suite will assert
about this container for as long as it exists, in green.

**And the same count-anchor hole §4bf found in the node suites was here too**, which is
worth its own paragraph because it is now three passes in a row. `check_invariants.php`,
`check_doc_numbering.php` and `selftest_installed.php` all reported a number and anchored
none of it. Delete the rule that keeps `json_encode` in a single module — one of the most
load-bearing lines in the repo, the one standing between a bad byte and a sign keeping its
old layout for good — and the checker printed `60 consistency checks, 0 failed` and exited
0. A checker whose own coverage can shrink in silence is the failure it exists to prevent,
wearing its uniform. All three are anchored now, and each was watched failing.

`selftest_installed.php` got a second one of a different kind: each arm must print exactly
one summary line. Its filter reads two shapes out of somebody else's output, and "found
neither" and "found nothing wrong" print identically — a distinction that has already been
wrong once here, when the first version matched `" checks, "` as a substring and printed a
check's own sentence as a summary.

**A postscript that arrived four days later, and is the same finding wearing a worse face.**
The owner sent the server's information panel. Its MySQL row read `5.7.23-23` — which this
repo already knew: it had been read off this very card on 2026-08-11 and written into
`HANDOFF.md`, in a table whose own sentence calls itself "the first time anything in this
project has read these rather than assumed them". The rows either side of it each settled a
standing question and got follow-through: a declared PHP floor observed twice, a corrected
§4ap, an invariant. **The engine row got recorded and then nothing.** It printed the number
beside a hardcoded `''` for eight days while the row above it carried three bands and a
floor.

So it now has `mysqlVersionNote($driver, $version)`, and 5.7 is the declared database floor
for the same reason 8.2 is the PHP one: it is what the machine is, read rather than assumed.
The driver is a parameter and not an afterthought — the fixture is **SQLite**, whose
`ATTR_SERVER_VERSION` is `3.45.1`, which parsed as a MySQL version is far below any floor. A
note written without it would have fired on every SQLite run in the project and told the
reader the shop's engine was ancient.

Two things fell out of writing it. Extending invariant 35's regex to cover
`ATTR_SERVER_VERSION` and `ATTR_DRIVER_NAME` — engine facts are machine facts — immediately
failed on a **fifth** file the rule had not known about: `DisplayStore::limitPublishLockWait()`
branches on the driver to skip a MySQL-only `SET SESSION`, in the publish path. It turned out
to be the one read on that list already covered the right way before the rule existed, with
`checkSame(testIsMysql(), …)` — a check that asserts the answer *depends* on the engine and
is true in both arms rather than pinning either. And the first draft of the note said
`CURRENT_TIMESTAMP`, which `check_invariants.php` rejected: it holds the whole repo to one
place that may name the database's own clock, and unlike comments it cannot drop a string
literal. The rule was right and the copy was wrong anyway — an admin reading a Settings tab
does not need the SQL identifier.

The SQL itself was then audited against 5.7 statically, since nothing here has ever run
against any MySQL and it was the cheapest way to learn what the rehearsal is walking into.
It is clean: no 8.0-only construct anywhere, all three catalogue views the gates read behave
the same, and `schema.sql` says `DEFAULT CHARSET=utf8mb4` with no explicit collation — the
one spelling that survives both engines, where `utf8mb4_0900_ai_ci` would have failed every
`CREATE TABLE` outright. One residual needs a query rather than an argument and it is in
HANDOFF §7: `users.email` is a 1020-byte unique index, which is legal under `DYNAMIC` row
format and not under `COMPACT`.

47 checks in total, 2273 → 2320. What it cost to find the first 31: running the suite under
`php -d` and reading which checks cared. None did — which was the answer, not the absence of
one. What it cost to find the last 16 was somebody pasting a version number this repo had
already written down.

### 4bh. A deprecation the lint, the suite and the new CI legs all agreed to ignore

Found by looking sideways at another branch rather than at the code, which is worth
recording because it is not how any of the last three were found.

`claude/project-hosting-options-4goiyv` has PR #10 open — *"Make the tree 8.4-clean, and
test 8.3/8.4 in CI"* — and its own write-up makes an argument this repo had not: **PHP 8.2
leaves security support on 2026-12-31**, the host's system default is already 8.3, and this
domain is held off it by one explicit MultiPHP setting. So "the version moved" is a dropdown
away, and `phpVersionNote()` is silent at and above the floor *by design* — it fires when a
host goes backwards and never when it moves on.

Its five signature fixes turn out to be **already present on this branch**, from `362f9c7`.
What it could not fix is the one that mattered: `lib/site_chrome.php` does not exist on
`main` at all — it is step 5, unmerged — and `SiteChrome::wear(WorkspaceTheme $theme = null)`
is the implicit-nullable form 8.4 deprecates, called on **every signed-in page load**.

**Three things were unable to see it, and the third is the one worth keeping.**

- `php -l` is clean on both spellings, on every version. Already known (invariant 31).
- The deprecation is emitted when a file is *compiled*, not parsed — so it fires at
  `require` time, before any handler the self-test installs exists. The suite's "no PHP
  diagnostics during the run" check is downstream of the event.
- **This container's `error_reporting` is 22527, which excludes `E_DEPRECATED`** (`E_ALL`
  is 30719 here). So the suite runs green at 2320/0 **on PHP 8.4** while the notice is
  being emitted, and I watched both halves happen. `ErrorPolicy::install()` does set
  `E_ALL` — on a real request, which is the one place nobody is watching a console.

That third one also means **PR #10's new 8.3 and 8.4 legs would have gone green over this.**
Adding a CI leg for a version is not the same as adding a check for what that version says,
and the gap between those two is a whole class of defect: anything the newer engine only
*warns* about. On the shop's 8.2 none of this is live; the day the host moves, it is a line
in the error log on every request — the log that alerts admins and rotates at a size cap.

So invariant 36, and it is deliberately a separate rule from 31 rather than an extension of
it, because the consequences are not the same shape: 31's is a parse error and a blank sign,
36's is a silent per-request notice. The detector reads real tokens and **only inside
parameter lists**, which is the whole difficulty — a scan of every `$x = null` reports
`private static $bytes = null;`, and `UploadLimit` and `ServerReport` both have exactly
that. Eleven probes, four positive and seven negative, and the negative half carries the
weight: every one is a shape a naive scan really does hit, and the first two of them are
live code in this repo.

61 → 73 invariant checks. The fix is one character.

---

### 4bi. Eight days of a green suite over a gate that had already died

`selftest_layout.php` ends with `2320 checks, 0 failed` and always did. The MySQL arm of
CI had not reached the end of the file since **2026-08-11**, and nothing in this repo said
so — not the suite, not the consistency checks, not `HANDOFF.md`, which describes that arm
as the answer to *"does this work on MySQL"*.

Four writes, all the same shape. The suite proves a reader degrades gracefully when the
stored value is one it cannot use, and to do that it has to get the bad value into the
column first:

| Written | Column | MySQL |
|---|---|---|
| `bg_type = 'nonsense'` | `brands.bg_type` `ENUM('color','image')` | 1265, data truncated |
| `last_published_at = 'nonsense'` | `displays.last_published_at` `DATETIME` | 1292, incorrect datetime |
| `nav_bg = 'darkblue'` | `workspace_themes.nav_bg` `VARCHAR(7)` | 1406, too long |
| `status_warn = 'chartreuse'` | same, in the colour audit | 1406, too long |

SQLite stores all four. Every local gate is green over all four. On MySQL the first one
reached is an uncaught `PDOException`, so the job stops — **and the step under it is the
rehearsal against real MySQL**, the one thing that exercises the publish transaction's
`SELECT … FOR UPDATE` and convergence against a real catalogue. One bad literal in a test
took out the only coverage the app's most dangerous path has.

**The fix is not a guard.** A value the column cannot hold does not belong in a column.
Two of the four were about a *reader* — `Brand::backgroundType()` and
`Display::lastPublishDescription()` — and both readers take a row, so they are handed one.
The other two are genuinely about a stored value, and there the correction is smaller and
more interesting: **the value has to fit.** `'darkblue'` is eight characters in a
`VARCHAR(7)`; not even somebody in a database client — which is how that check's own
comment describes the state it builds — could have put it there. `'gold'` and `'tomato'`
are the same defect a person would actually meet.

**And the reader was wrong about the case that survives.** Ask which unreadable stamp MySQL
*can* hold, and the answer is the zero date: strict mode refuses a string that is not a
datetime, but a host running without it, or a dump from one, leaves `0000-00-00 00:00:00`
— and `strtotime()` reads that as a real moment in year zero rather than failing. So
`StoreClock::epochOf()`, the one place in the repo that reads a stamp, answered
`-62169984000`, and the canvas footer answered *"is what I'm looking at live?"* with
**`sky, 11/29/-1 4:07pm`** — printed by the suite while the guard was mutated out, which is
how that sentence is quoted here. That is the half-written sentence the whole seam exists
to prevent, in the one form the engine can actually produce. One line, matched on the zero
date rather than on an epoch floor: a stamp genuinely older than 1970 is not something this
app can write, and a floor would be a second rule to be wrong about.

So invariant 37, and a detector, because this class is invisible to everything else here:
`php -l` sees a string, the suite sees green, and the container has no MySQL server to
disagree — the same three-way blindness §4bh had, one layer down. It reads `schema.sql`
rather than `lib/schema.php`, because schema.sql is the file that builds the fixture the
engine will answer from. Eleven probes, and the negative half is again where the care is:
an ENUM match that respected lettercase would condemn `role = 'Admin'`, which MySQL has
accepted since it was written in August 2026 and stores as `admin` through the column's
case-insensitive collation. Its own first draft is worth recording too — `\b` after a
closing parenthesis is not a word boundary, so the ENUM and `VARCHAR` halves of the
schema-reading pattern matched **nothing at all** while the date half worked. It printed
`ok` over the two column kinds that had broken CI. Seen to fail is not a formality.

73 → 86 consistency checks; 2320 → 2323 suite checks. What this still cannot say is
whether the MySQL arm now *finishes*: it has been dead for eight days and roughly 500 of
the suite's checks — every one steps 1 to 5 added — have never run against that engine at
all. This container has no MySQL, so the run is the only place that answer exists.

### 4bj. The run answers, and there were four more of the same thing behind them

§4bi ends by saying the only place the answer exists is the run. The run came back: the
MySQL leg reached **check 1383** instead of dying in the first hundred, and then failed
four more times. Every one of them is the same defect as the four before it — the suite
asserting something that is only true where nothing enforces the schema — and every one
of them had been sitting behind a fatal that stopped the run before it got there. That is
the part worth keeping: fixing the visible four did not fix the class, it *revealed* the
class. A dead gate hides its own remaining work, and the count of what is wrong behind it
is not knowable until it runs.

**One was a fatal, and it is the reason there is a second detector.** A value MySQL
refuses is one statement failing; SQL in the wrong *dialect* throws where no check is
looking, so the suite ends without reporting and the rehearsal step under it never
starts. Three tables the suite builds by hand were written in SQLite:
`AUTOINCREMENT`, which MySQL spells `AUTO_INCREMENT`, and — found by reading rather than
by CI — `type TEXT NOT NULL DEFAULT 'text'`, which is valid SQLite and rejected outright
by MySQL, since InnoDB allows no default on a `TEXT` column. Both spellings now live in
`test_fixture.php` beside the rest of what that file knows about the difference between
the engines: `createNullableDisplayIdElements()` and `createLegacyCanvasSettings()`, so a
test says which state it wants and not how to spell it. A fourth — the deploy-day race —
moved to `newSqliteTestDb()`, because MySQL cannot express that state at all: a trigger
there may not write to the table it is defined on, and that write is the half which has to
survive the failure. What is under test is a `catch` block in PHP; only one engine can
build the interleaving that reaches it.

**Two were readouts asserted off the machine, which §4bg had already named as a class.**
`ServerReport`'s *Database time zone* row had its note spelled inline, so the two forms it
can take were the two engines — and the suite could only ever assert whichever one it was
started on. It is `dbZoneNoteFor($zone)` now, the fifth seam of exactly this shape on that
card. Writing it turned up a false alarm the row had been giving all along: `SYSTEM (UTC)`
means the `SET time_zone` did not take *and* the host was already on UTC, so the stamps are
in UTC regardless — and the note said dates may read hours out, which is the sentence
somebody acts on. A protection that turns out not to have been needed is not a problem to
report. The predicate unwraps `SYSTEM (x)` and asks again; an abbreviation like `GMT` is
left warning rather than added on the strength of what it looks like, because a wrong entry
there is silence about stamps that really are out.

**One was a state the shop's engine cannot hold, and the ENUM was right.** The suite wrote
`role = 'Admin'` and asserted the reader answered `basic`. MySQL matches an ENUM assignment
against its members through the column's collation, so `'Admin'` *stores as* `admin` — the
row the check was about cannot exist there, and the check built the opposite of what it
asserted. Handed to `LoginOutcome::ok()` as a row, it covers the normalisation in code,
which is what has to hold: a lagging install has its `canvas_elements` ENUMs widened at
runtime, so what type a column *is* is not something this app gets to assume. Note the
edge this sits on — §4bi's detector was deliberately built to *permit* `role = 'Admin'`,
because MySQL accepts it. That was correct. Accepting a write and storing what was written
are different questions, and only the second one a check can assert.

**And one found a defect that was not in the app at all.** The suite pooled a `carousel`
row into the asset library and asserted it was ordinary. `assets.type` is
`ENUM('text','image','video')` and refuses it — and the right answer turned out to be that
the schema is correct: `builder.php` marks `carousel`, `table` and `marquee` `pool: false`,
because what those carry is the block's own settings rather than a piece of content anybody
would reuse. Two comments said otherwise — `AssetLibrary::contentFor()`'s docblock ("the
JSON a pooled carousel, table or marquee row carries") and the Library edit form's third
branch in `crud.php`, which named all three. Both were describing a design the `pool: false`
decision had superseded, and the third branch they were explaining is real and reachable
and is for **`video`**: the one type in the library nobody can create by hand, since the add
form offers two and the third arrives when a publish pools a video block carrying its own
path. So the block now tests `video`, which is also the only way to assert that the image
allow-list is type-scoped — no `.mp4` could pass it. Two comments corrected, no code
changed, and the agreement they had drifted from is now read out of `schema.sql` and
`builder.php` rather than restated: a block type added to the Builder's poolable set and
not to the column is a publish whose "save to library" silently does nothing, and neither
side says a word.

86 → 94 consistency checks; 2323 → 2336 suite checks, 2348 → 2361 on MySQL. The dialect
detector reads the *handle* rather than only the SQL, which is what makes it usable — a
test that genuinely needs SQLite says `newSqliteTestDb()` and is believed. Seen to fail
against the real statement, not just its probes: restoring the `CREATE TABLE` that ended
the run names both problems on it, including the `TEXT DEFAULT` one CI never got far
enough to reach.

Whether the leg now reaches the end is, again, only answerable by the run. What has
changed since §4bi is the honest expectation: the first fix took it from ~100 checks to
1383, and there are roughly 950 past that point which have still never executed against
this engine.

---

## 5. Verification

There is no deploy pipeline — every change reaches the sign by hand — but as of
#48 and #51 CI runs everything below except the two things that need a browser or
a copy of live data. It runs on PHP 8.2, against two engines: SQLite and a real
MySQL 5.7 service. **"Runs" is a claim with a date on it, and this paragraph was
wrong about it for eight days**: the MySQL arm had not reached the end of the suite
since 2026-08-11, and neither it nor the rehearsal step underneath it had completed
a run (§4bi). Invariant 37 is what a local gate can say about that arm; whether it
finishes is only ever answered by the run. As of §4bj the leg reaches check 1383 rather
than the first hundred, four more defects of the same class have been fixed behind the
fatal that was hiding them, and it has still not been observed to complete. Read the run
before repeating the claim. That 8.2 is now also the repo's declared floor — the store owner
stated the host runs it (§4k) — so the pin enforces the target rather than merely
accepting everything the target forbids. As of 2026-08-11 it is **observed** rather than
stated: 8.2.33 on the runtime card and `ea-php82` pinned to `srcresort.com` in cPanel
(HANDOFF §7). **CI is therefore the only place the gate below runs at the floor**, and
that is not a detail — this container is 8.4, so `php -l` here cannot fail on syntax the
shop cannot parse (invariant 31). Run the suite locally before every push anyway; the
loop is faster than a push.

```
php -l <every touched .php>              # syntax — but at 8.4 here, so it CANNOT fail on
                                         # 8.3/8.4-only syntax that the 8.2 shop rejects.
                                         # CI's run is the one at the floor (invariant 31)
php tools/check_invariants.php           # the greps below, run rather than read —
                                         # comment-aware, so a module documenting a
                                         # rule does not fail it. Also the above-floor
                                         # syntax check, which is what covers the hole
                                         # `php -l` leaves on the line above
php tools/selftest_layout.php            # the real modules, in-memory SQLite
php tools/selftest_installed.php         # the same suite as a real install on a real
                                         # server, on two axes. What the shop chose —
                                         # branded, live-like, damaged — because the plain
                                         # run has only ever seen a checkout with no
                                         # `branding_config.php`, which is no shop (§4be).
                                         # And what the machine was set to — a generous
                                         # host, a tight one, one showing errors — because
                                         # the four readouts that describe a server had
                                         # one form here and the other on none (§4bg)
node tools/selftest_builder_readonly.js  # builder.php's own JS, run against a DOM
                                         # that has only what a read-only page emits
node tools/selftest_builder_uploads.js   # the same JS under the opposite premise — an
                                         # admin who can edit — driving a stubbed
                                         # XMLHttpRequest through every way an upload ends
node tools/selftest_builder_colors.js    # the same JS again, against a Display whose
                                         # stored data is already wrong, through a `style`
                                         # that discards and normalises as a browser does
node tools/selftest_builder_editing.js   # and under the third: an ordinary good day.
                                         # Zoom, resize floors, hide/unhide, slide fields
                                         # and the marquee, run over a DOM whose classList
                                         # and appendChild actually work
node tools/selftest_builder_undo.js      # and under the fourth: the last thing they did was
                                         # not what they meant. Round-trips the canvas through
                                         # snapshot and restore, and drives every mutating
                                         # control to prove each one leaves a step
node tools/selftest_builder_brands.js    # and under the fifth: an admin deciding which
                                         # venue this sign belongs to. The switch repaints
                                         # and sends nothing, the palette is offered and
                                         # enforced nowhere, and Publish is what writes it
node tools/selftest_builder_theme.js     # and under the sixth: somebody changing a setting
                                         # about *themselves* with unpublished work on the
                                         # canvas. Holds the canvas nodes by identity across
                                         # the switch, and drives the save that fails
node tools/selftest_viewer.js            # viewer.php's poll loop, against a fetch this
                                         # test controls: the sign must not blank for one
                                         # dropped packet, and must not stay up for an
                                         # hour of them (§4af)
```

And one that is not a gate, because it takes minutes rather than seconds — run it over
what you changed, before you decide the checks you wrote are worth their lines:

```
php tools/mutate.php lib/whatever.php    # break that file one way at a time and run the
                                         # suite each time. A mutant the suite still
                                         # passes is a line no test can fail on, which is
                                         # invariant 30 and §4aq. `--list` shows what it
                                         # would break without running anything
php tools/mutate.php --swept             # and this one is instant: which modules have
                                         # been swept, and which are still a command each
```

And, with a MySQL to point at — the same suite, with nothing stubbed:

```
SELFTEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=lbm_selftest;charset=utf8mb4' \
SELFTEST_MYSQL_USER=... SELFTEST_MYSQL_PASS=... php tools/selftest_layout.php
```

On MySQL the fixture is built by running `schema.sql`, the `FOR UPDATE` stub is
gone, and twenty-three further checks run that SQLite cannot be asked — see §4aa.
Eight of those are the publish collision (§4ab), which needs two database sessions
and so cannot exist on an in-memory fixture at all.

**The greps below are what `tools/check_invariants.php` automates.** They are kept here
because the annotations are the reasoning, not because anybody has to run them.

**Five of them used to be on a by-eye list at the bottom of that file, and four are
mechanised as of #50** — `canvas_elements` (one lookbehind apart from the API action of
the same name), `STORE_TIMEZONE` (two rules: the bare constant is read nowhere at all,
and the quoted name belongs to three files), the `grants_accounts` / `grants_displays`
pairing (written out longhand, since it is about a form's shape rather than which files
match), and the *position* of `ensureSignageSchema()` (before the first mention of any
store or use case, which is stricter than a line-number bound and is why `api.php`
converging at line 128 is correct). `ErrorPolicy::report` keeps the half that is a
judgement: the caller *set* is checked, so a fourth cannot land unnoticed, but whether a
new one can repeat on its own is a reading of that call site. `schema.sql` against
`lib/schema.php` came off the list earlier — the MySQL run asserts convergence has
nothing left to do against a database built from that file, which is the same property
mechanised. §4aq has how each was decided.

The list the checker prints on every run is now five entries of a different kind: things
no grep settles because the instrument does not exist here — a browser, a database that
lags the repo — plus the mutation runner, named there so that *can this check fail?* has
a place on the page.

The checker's own reading of a file gained a rule with #50: it drops **both** kinds of
comment, having dropped only PHP ones before. An `<!-- … -->` explaining why a line no
longer calls `strtotime()` used to fail invariant 28 against the sentence explaining why
the rule holds. An HTML comment holding PHP is code and stays — see §4aq for why that is
the only safe direction, and for the measurement that all the rules match the same files
afterwards.

```
grep -rn "canvas_elements" --include=*.php .   # lib/layout_store.php; plus schema.php's DDL,
                                              # the get_canvas_elements endpoint NAME, and
                                              # server_report.php's expected-column list
grep -rn "strip_tags(\|html_entity_decode(" --include=*.php .  # exactly TWO calls in app code,
                                              # both in lib/plain_text.php and adjacent, with the
                                              # escape of a non-tag "<" between them. strip_tags is not
                                              # a parser — it deletes from a "<" to the end of the
                                              # value — so a second caller is a second answer to "is
                                              # this markup?", and the first thing it will get wrong is
                                              # a price line reading "Kids <12 eat free" (§4am). Call
                                              # toPlainText() instead, for a label and a preview as
                                              # much as for what is stored. The self-test's own
                                              # html_entity_decode() calls run the other way, undoing
                                              # an escape to assert it happened
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
grep -rn "json_encode(" --include=*.php .      # lib/http_reply.php, which owns it; the one in
                                              # lib/error_policy.php, which is the last-resort
                                              # notice and so cannot route through it (and has
                                              # checked for false since it was written); and the
                                              # self-test. Anywhere else is a reply that can
                                              # leave as zero bytes behind a 200, or a
                                              # `var X = ;` that takes a whole script block
                                              # down — §4af
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
                                              # gmdate() matches too. Every *stored* moment is UTC,
                                              # written with gmdate() and read back through
                                              # StoreClock::epochOf(). Local wall-clock is not monotonic:
                                              # the autumn fall-back replays an hour, and both the edit
                                              # lock (§4t) and the login lockout (§4v) compare stored
                                              # moments as absolute
grep -rn "strtotime(" --include=*.php .        # lib/store_clock.php and the self-test, and nothing else —
                                              # invariant 28. A bare 'Y-m-d H:i:s' is read in the *process*
                                              # zone, so the ' UTC' suffix is the whole rule; it was written
                                              # out three times and the third copy left it off, and the two
                                              # that were right are what made the third invisible (§4ap).
                                              # The suite calls it both ways on purpose, the second to
                                              # assert reading a stamp as local time is still hours out
grep -rn "date_default_timezone_set(" --include=*.php .  # lib/store_clock.php and the self-test.
                                              # config.php calls StoreClock::apply() and does not name the
                                              # function, which is the shape of the rule; tools/ moves the
                                              # process clock about to prove that what is *stored* does not
                                              # depend on where it is set. A second file setting it is a
                                              # second answer to "what time is it in the shop", and the
                                              # pages that disagree are the ones nobody compares side by side
grep -rn "CURRENT_TIMESTAMP\|NOW()" --include=*.php .   # the created_at/updated_at column DEFAULTS in
                                              # lib/schema.php and the fixture, plus prose in four files.
                                              # **No statement may ask the database for the time**: that is
                                              # MySQL's session zone, a third clock beside PHP's and the
                                              # store's, and recordPublish() was the one that did — so a
                                              # refused publish named an hour off by the difference between
                                              # two zones nobody had set (§4ap). db_connect.php asks the
                                              # connection for +00:00 so the column defaults above land in
                                              # the frame everything else is written in
grep -rn "STORE_TIMEZONE" --include=*.php .   # lib/branding.php holds the name and its default;
                                              # lib/store_clock.php is the only *reader*, through
                                              # StoreClock::SETTING, so even that is one spelling;
                                              # admin_panel.php names it as the key of a save, not as a
                                              # value it draws with, which is exactly the distinction §4ai
                                              # draws for the BRAND_* names. A page *reading* it has its own
                                              # opinion about what an unusable stored zone means
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
grep -rn "holdsASign(\|NO_SIGN_REFUSAL" --include=*.php .  # lib/grants.php declares both — the
                                              # predicate and the one sentence its refusal is worded
                                              # in — and crud.php and api.php are the two doors, which
                                              # must BOTH appear and appear together. A door holding
                                              # the predicate and wording its own refusal is one rule
                                              # met twice in different English, which reads as two
                                              # different problems. These two writes are the only ones
                                              # in the app that no Display scopes, so they are the only
                                              # ones the resolution seam cannot cover — invariant 29
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
grep -rn "file_put_contents" --include=*.php . | grep -v "^./lib/\|^./tools/"
                                              # must be EMPTY. This is the membership test for group A of
                                              # docs/DEPLOY-SKIP.md — a repo-tracked file the running app
                                              # rewrites, so uploading the repo's copy reverts live state.
                                              # It was written expecting exactly one hit, admin_panel.php's
                                              # branding writer, from a branch cut before §4y moved that
                                              # write into lib/branding.php. There has been nothing to find
                                              # since, so the number was wrong the day it landed — a grep
                                              # whose stated answer cannot occur reads as covering
                                              # something (#50's shape). Empty is the real rule, and a
                                              # root-level hit now means a page has started writing a file
                                              # an upload would revert: put it on that list in the same
                                              # commit. The lib/ writers are excluded on purpose — they all
                                              # write into the state directory, which is not in the repo,
                                              # and lib/branding.php writes a temp file it renames (§4y, §4z)
grep -rn "publishInFlight\|endPublish" --include=*.php .  # builder.php only, and both must appear on
                                              # every ending: raised once after the last refusal that
                                              # sends nothing, dropped in the reply handler AND in the
                                              # catch. A raise with one release is Publish out of service
                                              # for the session, on the page whose whole job is
                                              # publishing — see invariant 5's second half for why the
                                              # guard cannot live on the server
grep -rn "restrictSize\|BLOCK_MIN\|blockMin" --include=*.php .  # builder.php only. The single
                                              # `restrictSize` hit is the comment saying why there
                                              # is no modifier: interact.js enforces a minimum in
                                              # SCREEN pixels, so one written there moves with the
                                              # zoom. A real `interact.modifiers.restrictSize(` is
                                              # the defect back. BLOCK_MIN is the canvas-pixel table
                                              # and it must keep BOTH readers — handleResize
                                              # (dragging) and applyDim (typing) — or the two
                                              # controls disagree about how small a section may
                                              # be — invariant 26
grep -rn "ZOOM_MIN\|zoomFloor\|fitZoom" --include=*.php .  # builder.php only, seven hits, and the one
                                              # to read is applyZoom's: its floor is `zoomFloor()`,
                                              # which is ZOOM_MIN *or the zoom Fit needs*, whichever
                                              # is smaller. A bare `Math.max(ZOOM_MIN` back in
                                              # applyZoom is the Fit button silently not fitting.
                                              # fitZoom's own ZOOM_MIN is the unlaid-out-frame
                                              # fallback and belongs there
grep -rn "applyHiddenLook\|hidden-badge" --include=*.php .  # builder.php only, and every place that
                                              # sets dataset.hidden must go through applyHiddenLook —
                                              # renderSection, renderBlock, toggleHidden. A
                                              # classList.add('hidden-block') on its own is the §4x
                                              # defect back: a section faded with nothing saying why.
                                              # The three preview builders name .hidden-badge only to
                                              # avoid clearing it when they redraw
grep -rn "BRAND_ID\|CAN_PICK_BRAND\|switchBrand\|PALETTE_TARGETS\|var BRANDS" --include=*.php .
                                              # builder.php only, 18 hits. The Brand a sign wears is
                                              # *staged* in that page and written by the publish that
                                              # carries it (§4bc), so these are page state and nothing
                                              # else may hold an opinion about them. A `BRAND_ID` on
                                              # another page would be a second answer to "which venue
                                              # is this", and the two would differ the moment somebody
                                              # picked one without publishing. `var BRANDS` and not
                                              # `BRANDS`, because lib/brands.php and lib/brand_admin.php
                                              # both open with the word as a banner — and `BRAND_*` with
                                              # the underscore is a different rule two entries above,
                                              # about the nav bar's colours (see CONTEXT.md)
grep -rn "fmtCmd\|savedRange\|trackSelection\|FONT_FAMILIES\|wysiwyg\|fmt-btn" --include=*.php .
                                              # must be empty. The remains of a format bar ADR-0002
                                              # settled against — including a `selectionchange`
                                              # listener firing on every caret move to fill a
                                              # variable nothing read
grep -rn "execCommand" --include=*.php .      # exactly two, both in admin_panel.php, both
                                              # `execCommand('copy')` — deprecated, and the only copy
                                              # that works without HTTPS. A hit in builder.php or
                                              # viewer.php is markup editing back in a text block,
                                              # against ADR-0002 and invariant 6
grep -rn "[^_]DISPLAY_TAG\|waDisplay()" --include=*.php .  # every request naming a Display must send
                                              # DISPLAY_ID / waDisplayId() with it (invariant 12), which
                                              # omission silently opts out of. viewer.php is the one
                                              # exception: a Screen sends the tag alone (ADR-0003)
grep -rn "htmlspecialchars(" --include=*.php . # lib/markup.php, which names both flags, and
                                              # lib/error_policy.php's last-resort notice, which cannot
                                              # depend on it and passes them in full. Anywhere else is a
                                              # call whose behaviour depends on the host's PHP version —
                                              # §4ah
grep -rn "BRAND_NAV_BG\|BRAND_NAV_BORDER\|BRAND_ACCENT\|BRAND_TEXT" --include=*.php .
                                              # branding_config.php is the file; admin_panel.php writes
                                              # it; lib/site_chrome.php is the only reader. A page naming one
                                              # is a page interpolating whatever the config holds into
                                              # its own <style> block, where escaping is not what makes
                                              # a value safe — §4ai
```

**Six of the checks are not greps and cannot be written as one**, so they live only in
`tools/check_invariants.php`: whether an escaped value lands inside a `<script>` (the
same call is right or wrong depending on the element, and a regex looking for `<script`
is fooled by `admin_panel.php` mentioning one in a PHP comment — only `T_INLINE_HTML`
may move that state); whether every echo on a page is one of the five shapes safe by
construction; whether a class constant's *declared value* is a number; whether the grant
matrix declares both of its axes and saves by the ones it declared; whether every entry
point converges before anything that could hold a transaction is so much as named; and
three that are about the checker's own reading of a file rather than about the app —
what `codeWithoutComments()` drops decides what all the other rules can see, so both
directions of that are asserted (§4aq).

`php -l` cannot see inline JavaScript, and `builder.php` is ~3100 lines of it inside a
4100-line file. Anything touching that file needs reading, not linting. `node --check`
over the extracted `<script>` body proves it parses; the **five** builder suites go
further and *run* it, each under a premise the others cannot hold.

- `selftest_builder_readonly.js` stubs a DOM holding only the ids a read-only page
  emits, which is the only automated way to catch a lookup reaching for a control the
  lock took away.
- `selftest_builder_uploads.js` takes the opposite premise — an admin who can edit
  everything — and drives a stubbed `XMLHttpRequest`, which is the only way to see a
  missing `.catch()`: the file parses perfectly without one.
- `selftest_builder_colors.js` takes an admin opening a Display whose stored data is
  already wrong, and is the one place where the *stub itself* is load-bearing: its
  `style` is a Proxy that discards an unparseable colour and normalises a parseable one,
  exactly as the CSSOM does. A stub that stored whatever it was given would make that
  whole suite pass against the defect it exists for, so the fidelity of the stub is
  asserted before anything is asserted through it.
- `selftest_builder_editing.js` takes the least dramatic premise — an ordinary good day
  — and is where a control that quietly does less than it says gets caught, because
  nothing about that shows up as an error anywhere (§4x). Its DOM is the one that has to
  genuinely work: `classList`, `appendChild` and `querySelector` are all real, since a
  no-op `classList` passes every check about a class without the code existing.
- `selftest_builder_undo.js` takes the fourth: the last thing they did was not what they
  meant. It round-trips the canvas through snapshot and restore and drives every
  mutating control to prove each one leaves a step (invariant 27).

`selftest_viewer.js` is the sixth suite and is not about the Builder at all — it is a
Screen whose server has stopped answering, or whose blocks have nothing in them.

`schema.sql` has no automated check at all — nothing reads it, so a column missing
from it fails silently on a future rebuild and nowhere else. Diff it against
`lib/schema.php` by eye whenever either changes (invariant 15), and use
`tools/rehearse_phase1.php` on a copy of live data to see what MySQL actually ends
up with.

On a server with a **copy** of live data — never the live database:

```
php tools/rehearse_phase1.php            # converge schema, prove scoping, publish twice
```

And on the **live** database, where this one is safe and its neighbour is not:

```
php tools/audit_colors.php               # every stored colour, read-only
```

Every statement it runs is a `SELECT`. It exists because #41 left a consequence
only live data can hold — see §4ac — and it is the one tool here with no
`--confirm-copy`, which is exactly why its header says so at length.

CI runs the rehearsal too, but against a database built from `schema.sql` rather
than a copy of live data, and the difference is the whole remaining point of doing
it by hand.
A schema.sql database is already converged, so the run proves the round trip —
both widened ENUMs, the cascade rules, publish-and-republish scoping, DDL
committing an open transaction — and cannot prove the one thing the tool was
written for: that the idempotent statements apply to the live table **as it
stands**. That still needs a copy, and is still a deploy-day step.

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

**If a change needs anything at deploy time, it ships with a row in `HANDOFF.md` §5
→ "Deploy notes since the multi-display checklist".** One line, naming its decision
and its section here. The numbered checklist in `docs/roadmap-multi-display.md` is
one specific visit and is not renumbered as work merges, so without that row the
instruction exists only in a write-up nobody reads standing at the server — which is
how §4y's folder permission nearly shipped as a surprise. "Nothing to do, but you
will notice X" is worth a row too: the point is that the person deploying meets it
here rather than on the sign.
