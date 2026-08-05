<?php
// ============================================================
// JSON API
// ============================================================
// A thin adapter: it reads the request, hands the work to a module in lib/, and
// encodes the answer. Every statement against `canvas_elements`, `displays` and
// `display_permissions` lives in lib/layout_store.php, lib/displays.php and
// lib/grants.php respectively — nothing in this file writes SQL against them.
//
// Nor does any endpoint here check whether the account may have the Display it
// named: DisplayRequest answers that once, for all of them (ADR-0005). An
// endpoint added later inherits the check by resolving its Display the same way.
// See docs/BUILD-REFERENCE.md.

require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/layout_store.php';
require_once __DIR__ . '/lib/grants.php';
require_once __DIR__ . '/lib/display_request.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// get_layout is publicly accessible so viewer.php (kiosk display) can fetch it without a session.
// All other endpoints require an authenticated session.
if ($action !== 'get_layout') {
    requireLogin();
}

header('Content-Type: application/json');
$isAdmin = isAdmin();

// CSRF protection: every state-changing (POST) request must carry a valid token.
// GET endpoints are read-only, and get_layout is intentionally public so the
// kiosk viewer can poll it without a session.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Security token mismatch. Please reload the page and try again.']);
    exit;
}

// Schema convergence runs only on authenticated requests: the public get_layout
// endpoint is polled every 30s by every Screen, so running DDL there would spam
// the database continuously. A genuinely absent schema is still recovered — see
// DisplayStore::healSchema().
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
define('MAX_BYTES', 50 * 1024 * 1024); // 50 MB

function validateFile(array $file, array $allowExt, array $allowMime): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Upload error (code ' . $file['error'] . ')'];
    }
    if ($file['size'] > MAX_BYTES) {
        return ['ok' => false, 'msg' => 'File exceeds 50 MB limit.'];
    }
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) {
        return ['ok' => false, 'msg' => 'File extension not allowed.'];
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowMime, true)) {
        return ['ok' => false, 'msg' => 'File type rejected.'];
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

    if (isset($_FILES['bg_file'])) {
        $check = validateFile($_FILES['bg_file'], IMG_EXT, IMG_MIME);
        if ($check['ok']) {
            ensureUploads();
            $name = 'bg_' . time() . '.' . $check['ext'];
            if (move_uploaded_file($_FILES['bg_file']['tmp_name'], 'uploads/' . $name)) {
                return Background::image('uploads/' . $name);
            }
        }
    }
    return Background::keepImage();
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
    echo json_encode([
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
        echo json_encode([
            'status'       => $resolution->kind(),
            'message'      => $resolution->message(),
            'display'      => null,
            'elements'     => [],
            'block_styles' => [],
        ]);
        exit;
    }

    $payload = $layouts->snapshot($resolution->display());
    $payload['status'] = 'success';
    echo json_encode($payload);
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
    $payload['status'] = 'success';
    echo json_encode($payload);
    exit;
}

// ============================================================
// GET: get_assets
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_assets') {
    $stmt = $pdo->query("SELECT * FROM assets ORDER BY id DESC");
    echo json_encode($stmt->fetchAll());
    exit;
}

// ============================================================
// POST: upload_file  (images – all roles)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_file') {
    if (!isset($_FILES['file'])) { echo json_encode(['status'=>'error','message'=>'No file.']); exit; }
    $check = validateFile($_FILES['file'], IMG_EXT, IMG_MIME);
    if (!$check['ok']) { echo json_encode(['status'=>'error','message'=>$check['msg']]); exit; }
    ensureUploads();
    $name = 'img_' . uniqid('',true) . '.' . $check['ext'];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], 'uploads/' . $name)) {
        echo json_encode(['status'=>'error','message'=>'Could not save uploaded file.']); exit;
    }
    echo json_encode(['status'=>'success','path'=>'uploads/'.$name]);
    exit;
}

// ============================================================
// POST: upload_video  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload_video') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    if (!isset($_FILES['file'])) { echo json_encode(['status'=>'error','message'=>'No file.']); exit; }
    $check = validateFile($_FILES['file'], VID_EXT, VID_MIME);
    if (!$check['ok']) { echo json_encode(['status'=>'error','message'=>$check['msg']]); exit; }
    ensureUploads();
    $name = 'vid_' . uniqid('',true) . '.' . $check['ext'];
    if (!move_uploaded_file($_FILES['file']['tmp_name'], 'uploads/' . $name)) {
        echo json_encode(['status'=>'error','message'=>'Could not save uploaded file.']); exit;
    }
    echo json_encode(['status'=>'success','path'=>'uploads/'.$name]);
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
        currentUser()['id'],
        $isAdmin,
        // The stamp the Builder captured when it loaded this Display. A publish
        // without one is refused: an old tab predating this deploy holds a
        // layout from before Display scoping, which is exactly the write the
        // check exists to stop.
        $_POST['layout_stamp'] ?? ''
    );
    if (!$request) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'That publish could not be read, so nothing was saved. Reload the display and try again.',
        ]);
        exit;
    }

    $result = $layouts->publish($display, $request);

    if ($result->isOk()) {
        echo json_encode([
            'status'       => 'success',
            'display'      => $display->tag(),
            'layout_stamp' => $result->stamp(),
        ]);
    } else {
        echo json_encode([
            'status'  => 'error',
            'reason'  => $result->kind(),      // 'stale' | 'failed'
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

    echo json_encode(lockPayload(
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

    echo json_encode(lockPayload($resolution->display(), $actor));
    exit;
}

// ---- POST: release_lock — leaving the Builder ----
// Sent by beacon as the page goes away, so the next person does not wait out the
// idle window for a Display nobody is looking at. Best effort by nature: a closed
// lid or a dead battery sends nothing, which is what the idle window is for.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'release_lock') {
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    echo json_encode(lockPayload($displays->releaseLock($resolution->display(), $actor->id()), $actor));
    exit;
}

// ---- POST: take_over_lock — an admin taking the Display (admin only) ----
// ADR-0007's force-unlock. It hands the lock over rather than freeing it, so the
// ousted Builder learns it lost the Display instead of quietly reclaiming it on its
// next heartbeat. The confirm that states the holder loses unsaved work is in the
// Builder; this endpoint is the deliberate act it confirms.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'take_over_lock') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    echo json_encode(lockPayload($displays->seizeLock($resolution->display(), $actor->id()), $actor));
    exit;
}

// ============================================================
// POST: save_brand_styles  (admin only)
// ============================================================
// Brand Standards typography is shared by every Display, so this endpoint is
// deliberately not Display-scoped.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_brand_styles') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $data   = json_decode($_POST['styles_data'] ?? '[]', true) ?: [];
    $allowed = ['section_header','item_title','item_title_2','price','price_2','description'];
    $stmt   = $pdo->prepare(
        "UPDATE block_styles SET font_family=?, font_size=?, font_color=?, font_weight=?, font_style=?, line_height=? WHERE block_type=?"
    );
    foreach ($allowed as $t) {
        if (!isset($data[$t])) continue;
        $s = $data[$t];
        $stmt->execute([
            $s['font_family'] ?? 'Arial',
            intval($s['font_size'] ?? 16),
            $s['font_color']  ?? '#000000',
            $s['font_weight'] ?? 'normal',
            $s['font_style']  ?? 'normal',
            number_format(floatval($s['line_height'] ?? 1.4), 2),
            $t,
        ]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// ============================================================
// GET: get_canvas_elements  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_canvas_elements') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $resolution = DisplayRequest::forEditing($displays, $_GET, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }
    // A bare array, as the Work Area list has always received.
    echo json_encode($layouts->elementIndex($resolution->display()));
    exit;
}

// ============================================================
// POST: set_element_hidden  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_element_hidden') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    $id = intval($_POST['element_id'] ?? 0);
    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing element_id.']); exit; }

    $done = $layouts->setElementHidden($resolution->display(), $id, intval($_POST['hidden'] ?? 0) === 1);
    echo json_encode($done
        ? ['status' => 'success']
        : ['status' => 'error', 'message' => 'That element is not on this display.']);
    exit;
}

// ============================================================
// POST: delete_canvas_element  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_canvas_element') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $resolution = DisplayRequest::forEditing($displays, $writeParams, $actor);
    if (!$resolution->isFound()) { failResolution($resolution); exit; }

    $id = intval($_POST['element_id'] ?? 0);
    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing element_id.']); exit; }

    $done = $layouts->deleteElement($resolution->display(), $id);
    echo json_encode($done
        ? ['status' => 'success']
        : ['status' => 'error', 'message' => 'That element is not on this display.']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
?>
