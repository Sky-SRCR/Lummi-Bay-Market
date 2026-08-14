<?php
// ============================================================
// JSON API
// ============================================================
// A thin adapter: it reads the request, hands the work to a module in lib/, and
// encodes the answer. Every statement against `canvas_elements`, `displays`,
// `display_permissions` and `assets` lives in lib/layout_store.php,
// lib/displays.php, lib/grants.php and lib/assets.php respectively — nothing in
// this file writes SQL against them.
//
// Nor does any endpoint here check whether the account may have the Display it
// named: DisplayRequest answers that once, for all of them (ADR-0005). An
// endpoint added later inherits the check by resolving its Display the same way.
// See docs/BUILD-REFERENCE.md.

// Every answer this file gives is JSON, including the ones it gives by failing.
// A PHP warning printed above the payload is what turns a working poll into
// `r.json()` rejecting on the Screen — so the policy goes on before anything else
// runs. See lib/error_policy.php.
require_once __DIR__ . '/lib/error_policy.php';
ErrorPolicy::install(ErrorPolicy::API);

// The public poll gets no session. auth.php opens one at include time, and this
// endpoint is fetched every 30 seconds by every Screen without ever reading
// $_SESSION — so on a framed Screen, which returns no cookie, every poll was
// creating a new session file. Read straight from $_GET: $action is not resolved
// until after the includes, and this decision has to be made before them.
if (($_GET['action'] ?? '') === 'get_layout' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    define('AUTH_NO_SESSION', true);
    // This one endpoint's caller is a Screen. Its reply is JSON either way, but
    // the Viewer prints `message` straight onto the sign, so the words have to be
    // the words a customer can read — not the ones the Builder needs.
    ErrorPolicy::sayOnFailure('This sign is temporarily unavailable.');
}

require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/layout_store.php';
require_once __DIR__ . '/lib/grants.php';
require_once __DIR__ . '/lib/brand_styles.php';
require_once __DIR__ . '/lib/brands.php';
require_once __DIR__ . '/lib/display_request.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/upload_limits.php';
// Every reply below leaves through HttpReply::json(), which owns the three things
// that travel with it: the status code (derived from the payload's own `reason`, so
// the two can never disagree), the caching rules, and the encode. `echo
// json_encode()` is what sent a zero-byte 200 for a payload holding one bad byte
// (#26), and answering "no such sign" with 200 is what #28 is about.
require_once __DIR__ . '/lib/http_reply.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// get_layout is publicly accessible so viewer.php (kiosk display) can fetch it without a session.
// All other endpoints require an authenticated session.
if ($action !== 'get_layout') {
    requireLogin();
    // And the account behind that session must still exist, still be active, and
    // still hold the role it held at login — see syncSessionAccount(). A page
    // redirects here; an endpoint says so in JSON, because the Builder polls this
    // every 60 seconds and would otherwise silently receive a login page.
    //
    // `reason` is here so the Builder can act on it. Without a name this refusal
    // arrived looking exactly like a dropped connection, which the Builder is right
    // to ignore and wrong to ignore here: no later request from this session will
    // ever succeed. Somebody suspended mid-edit carried on working and found out at
    // the publish. See builder.php's terminal lock answers.
    if (!syncSessionAccount($pdo)) {
        endSession();
        HttpReply::json([
            'status'  => 'error',
            'reason'  => 'signed_out',
            'message' => 'Your account is no longer active. Sign in again.',
        ]);
        exit;
    }
}

// Set here as well as in every HttpReply::json() below, so that a reply escaping by
// some path this file has not thought of still leaves with the right type and is
// still not cached. Cheap, and the case it covers is by definition one nobody
// predicted.
header('Content-Type: application/json; charset=utf-8');
HttpReply::noStore();

$isAdmin = isAdmin();

// A POST whose body PHP dropped for exceeding post_max_size arrives with no
// fields at all — including no CSRF token — so this has to be answered before the
// gate below, or a too-large file is reported as a security problem and the user
// is sent to reload a page that was never at fault. No endpoint here reads the
// raw body, so "a POST with a content length and nothing in it" has exactly one
// cause. See lib/upload_limits.php.
if (UploadLimit::bodyWasDropped($_SERVER, $_POST, $_FILES)) {
    // 413 comes from the `too_large` reason now, rather than being set beside it.
    HttpReply::json([
        'status'  => 'error',
        'reason'  => 'too_large',
        'message' => UploadLimit::droppedBodyMessage(),
    ]);
    exit;
}

// CSRF protection: every state-changing (POST) request must carry a valid token.
// GET endpoints are read-only, and get_layout is intentionally public so the
// kiosk viewer can poll it without a session.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfOk()) {
    // Given explicitly rather than named: `csrf` is not one of the app's `reason`
    // words, and inventing one here would put a new value in front of the Builder's
    // terminal-refusal list for no gain.
    HttpReply::json(
        ['status' => 'error', 'message' => 'Security token mismatch. Please reload the page and try again.'],
        403);
    exit;
}

// Schema convergence runs only on authenticated requests: the public get_layout
// endpoint is polled every 30s by every Screen, so running DDL there would spam
// the database continuously. A genuinely absent schema is still recovered from the
// public path, but through the guarded door — repairSchemaAfterFailure(), reached
// from DisplayStore::healSchema() only once a query has actually failed.
//
// Here, and not lower down, because this is the last point at which no transaction
// is open. DDL commits an open transaction in MySQL without saying so, and the
// publish endpoint below deletes a whole layout inside one.
if ($action !== 'get_layout') {
    ensureSignageSchema($pdo);
}

$displays = new DisplayStore($pdo);
$layouts  = new LayoutStore($pdo, $displays);

// Who is asking, and which Displays they hold (ADR-0005). Built on the
// authenticated path only: get_layout is public, has no account, and must not
// read grants — it exits before anything below needs an actor.
$actor = ($action === 'get_layout') ? null : Actor::signedIn(currentUser(), new GrantStore($pdo));

// Which Display a write is for. POST wins, so a query string cannot redirect a
// write that names its Display in the body.
$writeParams = array_merge($_GET, $_POST);

// ---- Upload whitelists ----
define('IMG_EXT',  ['jpg','jpeg','png','gif','webp']);
define('IMG_MIME', ['image/jpeg','image/png','image/gif','image/webp']);
define('VID_EXT',  ['mp4','webm','ogv','ogg']);
define('VID_MIME', ['video/mp4','video/webm','video/ogg']);
/**
 * PHP's upload error code as something the person holding the file can act on.
 *
 * "Upload error (code 6)" was the whole message for a host with no writable temp
 * directory — a server fault the user can do nothing about, indistinguishable
 * from the one they can (the file being too big). Each code says which it is, and
 * the ones that are ours to fix go to the error log as well.
 */
function uploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is too large to upload — this server accepts up to '
                 . UploadLimit::describe() . '.';
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was cut off before it finished. Nothing was changed — please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was received.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            ErrorPolicy::report('upload-server-fault',
                'PHP could not accept an upload: error code ' . $code, 'Uploads are failing');
            return 'This server could not accept the file. It has been written to the error log — '
                 . 'nothing you did caused this.';
    }
    return 'The upload failed (code ' . $code . '). Nothing was changed.';
}

/**
 * The status code for a rejected upload, beside the sentence that explains it.
 *
 * Carried in the result rather than decided at the call site, because the three
 * cases are genuinely different answers — too big, wrong kind, our fault — and the
 * Builder's upload path is the one place in this app that already reads the code
 * (`xhr.status >= 400`) rather than only the body.
 */
function uploadErrorCode(int $code): int {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 413;
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return 500;   // nothing the person holding the file can do
    }
    return 400;
}

function validateFile(array $file, array $allowExt, array $allowMime): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'code' => uploadErrorCode($file['error']),
                'msg' => uploadErrorMessage($file['error'])];
    }
    // Not a flat 50 MB any more: the binding limit is whichever of the app's
    // ceiling and PHP's two is smallest, and the message has to name the real one
    // or the person trims their file to a number that will be refused again.
    if ($file['size'] > UploadLimit::bytes()) {
        return ['ok' => false, 'code' => 413,
                'msg' => 'That file is ' . UploadLimit::describeBytes($file['size'])
                       . '. This server accepts up to ' . UploadLimit::describe() . '.'];
    }
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) {
        return ['ok' => false, 'code' => 415, 'msg' => 'File extension not allowed.'];
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowMime, true)) {
        return ['ok' => false, 'code' => 415, 'msg' => 'File type rejected.'];
    }
    return ['ok' => true, 'ext' => $ext];
}

function ensureUploads(): void {
    if (!is_dir('uploads')) mkdir('uploads', 0755, true);
}

/**
 * Turn the background half of a publish POST into a Background intent.
 *
 * Only an admin may change a Display's background, and "image" with no new file
 * means "switch back to the image already stored" — not "set the colour picker's
 * value as the image path", which is what the old code did when an upload was
 * rejected.
 */
function backgroundFromPost(bool $isAdmin): Background {
    if (!$isAdmin) {
        return Background::unchanged();
    }

    $type = ($_POST['bg_type'] ?? 'color') === 'image' ? 'image' : 'color';
    if ($type === 'color') {
        return Background::color($_POST['bg_val'] ?? '#1a1a2e');
    }

    $sentAFile = isset($_FILES['bg_file'])
              && ($_FILES['bg_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($sentAFile) {
        $check = validateFile($_FILES['bg_file'], IMG_EXT, IMG_MIME);
        if ($check['ok']) {
            ensureUploads();
            // uniqid, not time(): two admins publishing a background in the same
            // second produced the same path, and move_uploaded_file overwrites
            // without a word — so the second publish silently replaced the first
            // Display's background. The image and video uploads already do this.
            $name = 'bg_' . uniqid('', true) . '.' . $check['ext'];
            if (move_uploaded_file($_FILES['bg_file']['tmp_name'], 'uploads/' . $name)) {
                return Background::image('uploads/' . $name);
            }
        }
        // The file was rejected — too large for the host, wrong type, or the move
        // failed. That is emphatically not "keep the stored image": keepImage sets
        // bg_type to 'image' while leaving bg_val as whatever it was, so a Display
        // on a colour ended up with background-image: url('#1a1a2e'), which loads
        // nothing and turns the sign near-black. Change nothing instead.
        return Background::unchanged();
    }

    // No file offered at all: the documented "switch back to the stored image".
    return Background::keepImage();
}

/**
 * Turn the Brand half of a publish POST into a BrandChoice.
 *
 * Only an admin may change which Brand a sign wears — the Builder gives a basic account
 * and a read-only page the venue's name and logo and no control (decision 5 of the v2
 * roadmap, and the same shape as the background above). Not sending the field is the
 * courtesy; this is the check.
 *
 * An absent or blank field is `unchanged`, and that is load-bearing rather than
 * defensive. It is what a Builder on a database whose convergence has not run sends:
 * `displays.brand_id` is 0 there, no Brand control is drawn, and the page deliberately
 * omits the field rather than publishing an id naming nothing — which `BrandChoice`
 * would rightly refuse, turning a lagging schema into a sign nobody can publish to
 * (invariant 10).
 */
function brandFromPost(bool $isAdmin): BrandChoice
{
    if (!$isAdmin) {
        return BrandChoice::unchanged();
    }
    $raw = $_POST['brand_id'] ?? '';
    // Only a *blank string* is the absent field. `brand_id[]=1` arrives as an array,
    // which is emphatically not "the Builder sent nothing" — it goes to
    // `BrandChoice::brand()` to be refused by name, along with `7abc` and every other
    // thing that is not an id.
    if (is_string($raw) && $raw === '') {
        return BrandChoice::unchanged();
    }
    return BrandChoice::brand($raw);
}

/**
 * The edit lock as the Builder consumes it (ADR-0007).
 *
 * Answers only what that page needs to decide: do I hold this Display, and if not,
 * who does. The idle age is included because the server knows about every tab this
 * account has open on this Display and a single tab does not — without it, a second
 * tab left sitting on the same sign would warn its owner that the lock was about to
 * lapse while they were busy working in the first one.
 */
function lockPayload(?Display $display, Actor $actor): array {
    if (!$display) {
        return ['status' => 'error', 'reason' => 'unknown', 'message' => 'Display not found'];
    }
    $lock  = $display->lockState();
    $other = $lock->heldByOther($actor->id());
    return [
        'status'     => 'success',
        'held_by_me' => $lock->heldBy($actor->id()),
        // Both flags, rather than one and its negation: neither is true when the
        // Display is free, and the Builder does different things in all three cases.
        'held_by_other' => $other,
        'held_by'       => $other ? $lock->holderName() : '',
        'since'         => $other ? $lock->takenAtLabel() : '',
        'idle_seconds'  => $lock->idleSeconds(),
    ];
}

/** Emit the standard "which Display?" failure for an endpoint that needs one. */
function failResolution(DisplayResolution $resolution): void {
    // The code follows the reason: 400 for a request that named nothing, 404 for a
    // tag that is not here, 503 for a sign switched off, 403 for one that is not
    // this account's, 409 for a tag and an id that disagree (#28).
    HttpReply::json([
        'status'  => 'error',
        'reason'  => $resolution->kind(),
        'message' => $resolution->message(),
    ]);
}

// ============================================================
// GET: get_layout   (public — polled by every Screen)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_layout') {
    $resolution = DisplayRequest::forViewing($displays, $_GET);

    if (!$resolution->isFound()) {
        // Nothing to render. The notice is the payload: the Viewer shows this
        // wording on the Screen, so a Display turned off (or deleted) while a
        // Screen is running replaces the layout with the notice within one poll.
        //
        // This is the one reply in the file that puts the kind in `status` rather
        // than in `reason`, which is the shape the Viewer has read since Phase 2 —
        // so the code is passed rather than derived. Unlike the others, all three of
        // these used to be 200: a mistyped tag, a deleted sign and a retired one were
        // indistinguishable to anything that did not read the body (#28).
        HttpReply::json([
            'status'       => $resolution->kind(),
            'message'      => $resolution->message(),
            'display'      => null,
            'elements'     => [],
            'block_styles' => [],
        ], HttpReply::codeForResolution($resolution));
        exit;
    }

    // publicSnapshot, not snapshot: this endpoint needs no sign-in, so whatever it
    // returns is readable by anyone who knows a screen name tag. A hidden block is
    // hidden from them too, content included (#25).
    $payload = $layouts->publicSnapshot($resolution->display());
    $payload['status'] = 'success';
    HttpReply::json($payload);
    exit;
}

// ============================================================
// GET: get_editor_layout   (signed in — the Builder's read)
// ============================================================
// The same snapshot as get_layout, resolved for *editing*. The difference is the
// one that matters after Phase 3: a deactivated Display is a notice to a Screen
// but is still editable (CONTEXT.md), so the Builder cannot share the Viewer's
// read or retiring a sign would make it impossible to work on.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_editor_layout') {
    $resolution = DisplayRequest::forEditing($displays, $_GET, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    $payload = $layouts->snapshot($resolution->display());

    // The Brand this sign wears, for the Builder's Brand control — the name it prints,
    // the palette it offers as swatches, and the logo the Venue Logo item places.
    //
    // Added here rather than inside the snapshot both clients share, because the Screen's
    // read is `get_layout`: polled every thirty seconds by every TV in the building, for
    // a client that draws no logo (decision 5) and gets its typography under
    // `block_styles` already. A Brand read there would be a query per poll per sign for
    // a key nothing opens.
    //
    // `null` when the row's `brand_id` names nothing — a database whose convergence has
    // not run (invariant 10). The Builder draws no Brand control for that, which is the
    // honest answer: there is no Brand to name yet.
    $wearing = (new BrandStore($pdo))->forId($resolution->display()->brandId());
    $payload['brand']  = $wearing ? $wearing->toClientArray() : null;
    $payload['status'] = 'success';
    HttpReply::json($payload);
    exit;
}

// ============================================================
// GET: get_assets
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_assets') {
    // A bare list, as the asset picker has always received: no `status` to derive
    // a code from, so it is given.
    HttpReply::json((new AssetLibrary($pdo))->all(), 200);
    exit;
}

// ============================================================
// POST: upload_file  (images – all roles)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_file') {
    // All roles, but not all accounts: an upload is a file on the server for as long
    // as the server lives, and one from an account holding no sign can never appear on
    // one (#33, invariant 29). The same predicate gates crud.php's add form.
    //
    // This endpoint names no Display, so DisplayRequest — which answers every other
    // "may they?" on this page — has nothing to resolve and cannot cover it. Hence the
    // explicit `if`, which is the shape invariant 8 warns about; it is allowed here
    // because the question is about the account rather than about a sign. `forbidden`
    // is already a reason word, so HttpReply derives the 403 rather than being told.
    //
    // Above the $_FILES read, so a refusal happens before a temporary file is moved
    // anywhere: move_uploaded_file() cannot be undone.
    if (!$actor->holdsASign($displays->all())) {
        HttpReply::json(['status'=>'error','reason'=>'forbidden','message'=>Actor::NO_SIGN_REFUSAL]);
        exit;
    }
    if (!isset($_FILES['file'])) { HttpReply::json(['status'=>'error','message'=>'No file.'], 400); exit; }
    $check = validateFile($_FILES['file'], IMG_EXT, IMG_MIME);
    if (!$check['ok']) { HttpReply::json(['status'=>'error','message'=>$check['msg']], $check['code']); exit; }
    ensureUploads();
    $name = 'img_' . uniqid('',true) . '.' . $check['ext'];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], 'uploads/' . $name)) {
        HttpReply::json(['status'=>'error','message'=>'Could not save uploaded file.'], 500); exit;
    }
    HttpReply::json(['status'=>'success','path'=>'uploads/'.$name]);
    exit;
}

// ============================================================
// POST: upload_video  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_video') {
    if (!$isAdmin) { HttpReply::json(['status'=>'error','message'=>'Admins only.'], 403); exit; }
    if (!isset($_FILES['file'])) { HttpReply::json(['status'=>'error','message'=>'No file.'], 400); exit; }
    $check = validateFile($_FILES['file'], VID_EXT, VID_MIME);
    if (!$check['ok']) { HttpReply::json(['status'=>'error','message'=>$check['msg']], $check['code']); exit; }
    ensureUploads();
    $name = 'vid_' . uniqid('',true) . '.' . $check['ext'];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], 'uploads/' . $name)) {
        HttpReply::json(['status'=>'error','message'=>'Could not save uploaded file.'], 500); exit;
    }
    HttpReply::json(['status'=>'success','path'=>'uploads/'.$name]);
    exit;
}

// ============================================================
// POST: publish
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'publish') {
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }
    $display = $resolution->display();

    // A body that did not decode is not an empty layout — see
    // PublishRequest::fromPostedJson. Refused here rather than published as a
    // wipe that reports success.
    $request = PublishRequest::fromPostedJson(
        $_POST['layout_data'] ?? '[]',
        backgroundFromPost($isAdmin),
        brandFromPost($isAdmin),
        currentUser()['id'],
        $isAdmin,
        // The stamp the Builder captured when it loaded this Display. A publish
        // without one is refused: an old tab predating this deploy holds a
        // layout from before Display scoping, which is exactly the write the
        // check exists to stop.
        $_POST['layout_stamp'] ?? ''
    );
    if (!$request) {
        // `invalid` is the word PublishResult uses for the same outcome — read, and
        // refused — so this reply now carries it too, and gets the same 422.
        HttpReply::json([
            'status'  => 'error',
            'reason'  => 'invalid',
            'message' => 'That publish could not be read, so nothing was saved. Reload the display and try again.',
        ]);
        exit;
    }

    $result = $layouts->publish($display, $request);

    if ($result->isOk()) {
        // Re-read rather than compose the sentence here. What the Builder puts on
        // screen has to be what the row now says, and `lastPublishDescription()` is
        // where that sentence lives — the admin panel's Displays tab and a refused
        // publish both use it, and a second copy built out of `time()` and the
        // current username would be a second opinion about a stored value, which is
        // the shape #44 was. It also means the time is formatted by StoreClock on
        // the server: the browser's zone is the one clock nobody wants here.
        $justPublished = $displays->forId($display->id());
        HttpReply::json([
            'status'       => 'success',
            'display'      => $display->tag(),
            'layout_stamp' => $result->stamp(),
            'published'    => $justPublished ? $justPublished->lastPublishDescription() : '',
        ]);
    } else {
        HttpReply::json([
            'status'  => 'error',
            // 'stale' | 'locked' | 'invalid' | 'busy' | 'failed' — 409 for the three
            // that mean somebody else got there first, 422 for a layout that was read
            // and refused, 500 for ours.
            'reason'  => $result->kind(),
            'message' => $result->message(),
        ]);
    }
    exit;
}

// ============================================================
// The edit lock (ADR-0007)
// ============================================================
// One account edits a Display at a time. All four endpoints resolve their Display
// through DisplayRequest like everything else, so a lock cannot be taken on, or
// stolen from, a Display the account may not edit in the first place.

// ---- POST: hold_lock — the Builder's claim, and its heartbeat ----
// One endpoint for both, because they are one question: is this Display mine to
// edit? Taking it on open, keeping it while working, and taking it back after an
// idle lapse nobody else filled are the same statement (DisplayStore::claimLock).
//
// `idle_seconds` is how long ago the *last real interaction* was, not how long ago
// the last heartbeat was. The Builder sending the true age is what lets it beat on
// a lazy interval without ever extending a lock on work that stopped — and it means
// a forgotten tab loses the Display on time even though it is still beating.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'hold_lock') {
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    HttpReply::json(lockPayload(
        $displays->claimLock($resolution->display(), $actor->id(), intval($_POST['idle_seconds'] ?? 0)),
        $actor
    ));
    exit;
}

// ---- GET: lock_state — who holds it, without touching it ----
// What a read-only Builder polls. It must not claim: a second person watching a
// Display would otherwise take it the moment the holder went idle, which is the
// opposite of the "an active editor is never interrupted" rule.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'lock_state') {
    $resolution = DisplayRequest::forEditing($displays, $_GET, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    HttpReply::json(lockPayload($resolution->display(), $actor));
    exit;
}

// ---- POST: release_lock — leaving the Builder ----
// Sent by beacon as the page goes away, so the next person does not wait out the
// idle window for a Display nobody is looking at. Best effort by nature: a closed
// lid or a dead battery sends nothing, which is what the idle window is for.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'release_lock') {
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    HttpReply::json(lockPayload($displays->releaseLock($resolution->display(), $actor->id()), $actor));
    exit;
}

// ---- POST: take_over_lock — an admin taking the Display (admin only) ----
// ADR-0007's force-unlock. It hands the lock over rather than freeing it, so the
// ousted Builder learns it lost the Display instead of quietly reclaiming it on its
// next heartbeat. The confirm that states the holder loses unsaved work is in the
// Builder; this endpoint is the deliberate act it confirms.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'take_over_lock') {
    if (!$isAdmin) { HttpReply::json(['status'=>'error','message'=>'Admins only.'], 403); exit; }
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    HttpReply::json(lockPayload($displays->seizeLock($resolution->display(), $actor->id()), $actor));
    exit;
}

// ============================================================
// POST: save_brand_styles  (admin only)
// ============================================================
// Brand Standards typography belongs to a Brand rather than to a Display, so this
// endpoint is scoped to a Brand and deliberately not Display-scoped (ADR-0011).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_brand_styles') {
    if (!$isAdmin) { HttpReply::json(['status'=>'error','message'=>'Admins only.'], 403); exit; }

    // Which Brand's standards, asked before anything else: it is the scope every
    // check below is about. An id that names no Brand is refused rather than
    // defaulted — falling back to the first Brand would edit a venue nobody named.
    $brand = (new BrandStore($pdo))->forId($_POST['brand_id'] ?? 0);
    if (!$brand) {
        HttpReply::json(['status' => 'error', 'reason' => 'invalid',
                         'message' => 'That brand does not exist. Nothing was saved.']);
        exit;
    }

    // Editing a Brand reaches every sign wearing it without a publish, so the edit
    // lock covers it too — see DisplayStore::editedByAnyoneElseUsingBrand. Refused
    // while anybody else is mid-edit *on a sign wearing this Brand*, because the
    // typography they are sizing blocks against would change under them within 30
    // seconds and nothing would tell them. Narrowed from "anyone editing anything"
    // by ADR-0011: somebody working a casino floor board cannot be affected by the
    // Salmon House red changing.
    $busy = $displays->editedByAnyoneElseUsingBrand(currentUser()['id'], $brand->id());
    if ($busy) {
        HttpReply::json([
            'status'  => 'error',
            'reason'  => 'locked',
            'message' => $busy->editingSentence()
                       . ' That display wears the ' . $brand->name() . ' brand, and a brand'
                       . ' change reaches every screen wearing it within 30 seconds without a'
                       . ' publish, so it cannot change while somebody is editing one.'
                       . ' Try again once they are finished.',
        ]);
        exit;
    }

    $data  = json_decode($_POST['styles_data'] ?? '[]', true) ?: [];
    $saved = (new BrandStyles($pdo))->save($brand->id(), $data);

    // Reporting how many rows were written, rather than an unconditional success.
    // The UPDATE matches on block_type, so on a database missing a row it wrote
    // nothing and still answered success — the field reverted on reload and nothing
    // said why. That is the defect the six-row seed in lib/schema.php was added to
    // prevent, and schemaTry() swallows a seed that does not apply, so it is worth
    // saying out loud rather than assuming.
    HttpReply::json($saved
        ? ['status' => 'success', 'saved' => $saved]
        // `failed` because it is: the rows this app seeds are not in the database, and
        // there is nothing the admin at the keyboard did wrong or can do about it.
        : ['status' => 'error', 'reason' => 'failed',
           'message' => 'Nothing was saved — those block types are missing from the database.']);
    exit;
}

// ============================================================
// GET: get_canvas_elements  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_canvas_elements') {
    if (!$isAdmin) { HttpReply::json(['status'=>'error','message'=>'Admins only.'], 403); exit; }
    $resolution = DisplayRequest::forEditing($displays, $_GET, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }
    // A bare array, as the Work Area list has always received — so, like the asset
    // list, its code is given rather than derived.
    HttpReply::json($layouts->elementIndex($resolution->display()), 200);
    exit;
}

// ============================================================
// POST: set_element_hidden  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_element_hidden') {
    if (!$isAdmin) { HttpReply::json(['status'=>'error','message'=>'Admins only.'], 403); exit; }
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    $id = intval($_POST['element_id'] ?? 0);
    if (!$id) { HttpReply::json(['status'=>'error','message'=>'Missing element_id.'], 400); exit; }

    $res = $layouts->setElementHidden($resolution->display(), $id,
                                      intval($_POST['hidden'] ?? 0) === 1, currentUser()['id']);
    // ElementResult's kind — 'not_found' | 'locked' — carried through, so the code
    // says 404 or 409 rather than the 200 both used to leave as.
    HttpReply::json($res->isOk()
        ? ['status' => 'success']
        : ['status' => 'error', 'reason' => $res->kind(), 'message' => $res->message()]);
    exit;
}

// ============================================================
// POST: delete_canvas_element  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_canvas_element') {
    if (!$isAdmin) { HttpReply::json(['status'=>'error','message'=>'Admins only.'], 403); exit; }
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    $id = intval($_POST['element_id'] ?? 0);
    if (!$id) { HttpReply::json(['status'=>'error','message'=>'Missing element_id.'], 400); exit; }

    $res = $layouts->deleteElement($resolution->display(), $id, currentUser()['id']);
    HttpReply::json($res->isOk()
        ? ['status' => 'success']
        : ['status' => 'error', 'reason' => $res->kind(), 'message' => $res->message()]);
    exit;
}

HttpReply::json(['status' => 'error', 'message' => 'Unknown action.'], 400);
?>
