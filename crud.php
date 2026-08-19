<?php
// ============================================================
// ASSET LIBRARY PAGE
// ============================================================
// A thin adapter. Every statement against `assets` lives in lib/assets.php, and
// the reference counting that decides whether a row is safe to remove lives in
// lib/layout_store.php, which owns `canvas_elements`. This page asks both and
// renders the answer — see the header of lib/assets.php for why the question has
// to be split that way.

require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/grants.php';
require_once __DIR__ . '/lib/layout_store.php';
require_once __DIR__ . '/lib/assets.php';
// Named explicitly, though this page's own upload ceiling is the only thing it asks
// for: a transitive include is not a dependency, and until now this page carried
// that ceiling as a number of its own instead of asking (see below).
require_once __DIR__ . '/lib/upload_limits.php';
// What this employee's screen is painted in (v2 step 5) — never a sign.
require_once __DIR__ . '/lib/workspace_themes.php';
requireCurrentAccount($pdo);   // all roles can access; delete is admin-only below
$me = currentUser();

// Signed-in page, so schema convergence is allowed here (invariant 7). This page
// is the only one that needs `assets.auto_pooled`, and an admin who never opens
// the admin panel would otherwise be tidying against the label prefix alone.
ensureSignageSchema($pdo);

// The Workspace Theme this account chose, after convergence because the column it
// reads is one the plan adds. This page is a light document with a chrome nav, like
// the Admin Panel, so what a theme paints here is the bar and the buttons — the roles
// reach every signed-in page and how much of a page they paint depends on how much of
// it is chrome.
SiteChrome::wear((new WorkspaceThemeStore($pdo))->forAccount($me['id']));

// The sentence this page prints, and which colour it prints in. Declared here rather
// than beside the CREATE branch below, because the guard on the next line is now the
// first thing that can set one.
$message  = '';
$msgClass = 'success';

// Answered before the CSRF gate, and that order is the whole of it. A POST whose body
// PHP dropped for exceeding `post_max_size` arrives with no fields at all — including
// no token — so verifyCsrf() below saw a missing token and did what it does: a bare 403
// reading **"Security token mismatch. Please go back and try again."** For an image
// over the host's limit that is a security failure reported for a size problem, and
// going back and trying again produces it a second time, which is the one thing the
// sentence promises will help. api.php has had this guard since the same bug was found
// there; this page and admin_panel.php were the two sinks that never got it.
//
// Rendered rather than sent as JSON, because this page is read by a person in a browser
// and `HttpReply::json()` here would be a payload nobody sees. The message itself is
// UploadLimit's, so all three doors say the same thing.
if (UploadLimit::bodyWasDropped($_SERVER, $_POST, $_FILES)) {
    http_response_code(413);
    $message  = UploadLimit::droppedBodyMessage();
    $msgClass = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
}

$library = new AssetLibrary($pdo);
$signs   = new DisplayStore($pdo);
$layouts = new LayoutStore($pdo, $signs);

// This library is shared by every sign, so adding to it needs a sign to add for
// (#33, invariant 29). One predicate, `Actor::holdsASign()`, answers that here and at
// api.php's upload — an account with no grant reached both, and the Builder had
// already told it there was nothing here to edit.
//
// The form is left out below when this is false, which is the §4j shape: a control
// somebody may not use is not sent, rather than sent and refused. That is not the
// check, though. The check is the refusal in the create branch, because a POST does
// not have to come from a form this page drew (invariant 8).
$actor  = Actor::signedIn($me, new GrantStore($pdo));
$mayAdd = $actor->holdsASign($signs->all());

// ---- File upload validation ----
// The extension allow-list belongs to the module that owns the table: an image
// entry's content is checked against it on every write, so a second copy here
// would be a second opinion about what an image entry may point at.
$allowedExtensions = AssetLibrary::IMAGE_EXTENSIONS;
$allowedMimeTypes  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

function validateImageFile(array $file, array $allowed_ext, array $allowed_mime): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => 'Upload error.'];
    }
    // Asked rather than stated. The number here used to be `10 * 1024 * 1024` with the
    // words "max 10 MB" beside it, which was wrong in two directions at once: on a host
    // whose post_max_size is 8M the promise could not be kept, and the form above never
    // printed the figure at all, so the first anybody heard of a limit was being refused
    // by it. UploadLimit::imageBytes() is 10 MB capped by what can actually arrive, and
    // it is the same call the form and the file picker now quote.
    if ($file['size'] > UploadLimit::imageBytes()) {
        return ['ok' => false, 'msg' => 'That image is ' . UploadLimit::describeBytes($file['size'])
                                      . '. The library accepts up to ' . UploadLimit::describeImage()
                                      . '. Nothing was changed.'];
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        return ['ok' => false, 'msg' => 'Only JPG, PNG, GIF and WEBP files are allowed.'];
    }
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed_mime, true)) {
        return ['ok' => false, 'msg' => 'File type rejected (MIME check failed).'];
    }
    return ['ok' => true, 'ext' => $ext];
}

function ensureUploadsDir(): void {
    if (!is_dir('uploads')) { mkdir('uploads', 0755, true); }
}

// An image referenced by URL/path (the "image URL" field) is checked against the
// same extension allow-list as uploads, so SVG and other non-image types can't be
// inserted by reference. That check is `AssetLibrary::isAllowedImageRef()` — it
// moved into the module when editing stopped being able to skip it, and the add
// form asks the same question so that both doors into the table use one list.

// $message / $msgClass are declared above the upload-size guard, which is the first
// thing on this page that can set them.

// ============================================================
// CREATE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
    // First, and above the file handling on purpose: move_uploaded_file() cannot be
    // rolled back, so a refusal that arrives after it has run leaves a file in
    // `uploads/` that nothing references and no sweep removes — the whole of what
    // this gate is meant to prevent, minus the row.
    if (!$mayAdd) {
        $message  = Actor::NO_SIGN_REFUSAL;
        $msgClass = 'error';
    } else {
    $type    = $_POST['type']    ?? '';
    $label   = trim($_POST['label']   ?? '');
    $content = '';

    if ($type === 'text') {
        $content = toPlainText($_POST['text_content'] ?? '');   // plain text only
    } elseif ($type === 'image') {
        if (!empty($_FILES['image_file']['name'])) {
            $check = validateImageFile($_FILES['image_file'], $allowedExtensions, $allowedMimeTypes);
            if ($check['ok']) {
                ensureUploadsDir();
                $fileName = 'crud_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $check['ext'];
                if (move_uploaded_file($_FILES['image_file']['tmp_name'], 'uploads/' . $fileName)) {
                    $content = 'uploads/' . $fileName;
                } else {
                    $message = 'Could not save the uploaded file.';
                    $msgClass = 'error';
                }
            } else {
                $message  = $check['msg'];
                $msgClass = 'error';
            }
        } else {
            $content = trim($_POST['image_url'] ?? '');
            if ($content !== '' && !AssetLibrary::isAllowedImageRef($content)) {
                $message  = 'Only JPG, PNG, GIF and WEBP images are allowed — SVG and other types are blocked.';
                $msgClass = 'error';
                $content  = '';
            }
        }
    }

    if (empty($message) && !empty($content)) {
        if ($library->create($type, $content, $label)) {
            $message = 'Asset saved successfully.';
        } else {
            $message  = 'That asset could not be saved. Nothing was changed.';
            $msgClass = 'error';
        }
    } elseif (empty($message)) {
        $message  = 'No content provided. Please enter text or choose an image.';
        $msgClass = 'error';
    }
    } // end $mayAdd check
}

// ============================================================
// UPDATE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update'])) {
    if (!isAdmin()) {
        $message  = 'Only admins can edit assets.';
        $msgClass = 'error';
    } else {
    // What kind of entry this is comes from the stored row, never from the form.
    // The type used to travel back in a hidden field, and it decided both of the
    // rules below — so `edit_type=image` on a text entry wrote markup where
    // ADR-0002 requires plain text, and `edit_type=text` on an image entry skipped
    // the allow-list and pointed every sign reading it at an .svg on any host.
    // Reading the row also answers the question the old code never asked: whether
    // the entry is still there at all.
    $id     = intval($_POST['edit_id'] ?? 0);
    $label  = trim($_POST['edit_label'] ?? '');
    $record = $id > 0 ? $library->forId($id) : null;

    if ($record === null) {
        $message  = 'That library entry no longer exists, so there was nothing to save.'
                  . ' Nothing was changed.';
        $msgClass = 'error';
    } else {
        $storedType = (string)$record['type'];
        $content    = $_POST['edit_content'] ?? '';

        if (!empty($_FILES['edit_image_file']['name'])) {
            if ($storedType !== AssetLibrary::TYPE_IMAGE) {
                // The edit form offers a file picker only for an image entry, so
                // this is a form that was not the one we rendered. Refused before
                // the file is moved: a text entry replaced by a path shows the
                // path, as words, on every sign reading it.
                $message  = 'That library entry holds ' . $storedType . ', not an image,'
                          . ' so a file cannot replace its content. Nothing was changed.';
                $msgClass = 'error';
            } else {
                $check = validateImageFile($_FILES['edit_image_file'], $allowedExtensions, $allowedMimeTypes);
                if (!$check['ok']) {
                    $message  = $check['msg'];
                    $msgClass = 'error';
                } else {
                    ensureUploadsDir();
                    $fileName = 'crud_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $check['ext'];
                    if (move_uploaded_file($_FILES['edit_image_file']['tmp_name'], 'uploads/' . $fileName)) {
                        $content = 'uploads/' . $fileName;
                    } else {
                        // Said nothing before, and fell through to save whatever was
                        // in the path field — so a failed upload silently kept the
                        // old image and reported the save as a success.
                        $message  = 'The uploaded file could not be saved. Nothing was changed.';
                        $msgClass = 'error';
                    }
                }
            }
        }

        // Plain-texting, the emptiness guard and the image allow-list are all
        // AssetLibrary's now — it is the only thing that knows what kind of row
        // this is without being told.
        $result = ($message === '') ? $library->update($id, $label, $content) : null;

        if ($result !== null && !$result->isOk()) {
            $message  = $result->message();
            $msgClass = 'error';
        } elseif ($result !== null) {
            // Editing a library entry changes every sign that draws on it, on the next
            // 30-second poll, with nobody publishing anything. Advance those Displays'
            // stamps so a Builder that is open on one is refused rather than quietly
            // republishing the text that was here a moment ago (ADR-0006).
            $usage = $layouts->assetUsage($id);
            foreach ($usage['displays'] as $displayId) {
                $d = $signs->forId($displayId);
                if ($d) { $signs->advanceLayoutRevision($d); }
            }

            $message = $usage['elements']
                ? 'Asset updated. It is used by ' . $usage['elements'] . ' block'
                  . ($usage['elements'] === 1 ? '' : 's') . ' on ' . count($usage['displays'])
                  . ' display' . (count($usage['displays']) === 1 ? '' : 's')
                  . ', which will show the new content within 30 seconds.'
                : $result->message();
        }
    }
    } // end isAdmin check
}

// ============================================================
// DELETE  (POST only – not a GET link, to prevent accidental deletion)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete'])) {
    if (!isAdmin()) {
        $message  = 'Only admins can delete assets.';
        $msgClass = 'error';
    } else {
        $id = intval($_POST['delete_id'] ?? 0);
        if ($id > 0) {
            // Refused while any Display uses it. `canvas_elements.asset_id` is
            // ON DELETE SET NULL and a pooled block keeps no copy of its own text,
            // so deleting a used entry blanked that line on every sign drawing on
            // it — within 30 seconds, with no warning, and permanently, because the
            // next publish from either Builder writes the emptiness back.
            //
            // Still worded for more than one Display: publishing no longer creates
            // a row two signs share, but rows it created before that change are
            // still in this table (lib/assets.php).
            $usage = $layouts->assetUsage($id);
            if ($usage['elements'] > 0) {
                $message  = 'That asset is still in use: ' . $usage['elements'] . ' block'
                          . ($usage['elements'] === 1 ? '' : 's') . ' on ' . count($usage['displays'])
                          . ' display' . (count($usage['displays']) === 1 ? '' : 's')
                          . ' would be left blank. Remove those blocks first, or edit this'
                          . ' asset instead of deleting it.';
                $msgClass = 'error';
            } elseif ($library->delete($id)) {
                $message = 'Asset deleted.';
            } else {
                $message  = 'That asset could not be deleted. Nothing was changed.';
                $msgClass = 'error';
            }
        }
    }
}

// ============================================================
// TIDY UP  (admin only)
// ============================================================
// Publishing copies a text block's text into this library and points the block at
// the copy, so ordinary editing leaves rows behind. A publish clears up after
// itself for the rows its own Display held; what it cannot reach is a block
// deleted from the admin Work Area, which releases a reference with no publish
// anywhere near it. Those collect here until somebody presses this.
//
// Only ever pooled rows nothing points at. An image uploaded to the library and
// not placed on a sign yet is somebody's next job, and AssetLibrary refuses to
// remove it however this page asks.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tidy'])) {
    if (!isAdmin()) {
        $message  = 'Only admins can tidy the library.';
        $msgClass = 'error';
    } else {
        $referenced = $layouts->referencedAssetIds();
        if ($referenced === null) {
            // Could not read the references. Sweeping now would treat every pooled
            // row as unused and blank the lines that use them, with no undo.
            $message  = 'The library could not be tidied: the list of blocks using these'
                      . ' entries could not be read. Nothing was changed.';
            $msgClass = 'error';
        } else {
            $gone = $library->discardPooled($library->pooledNotIn($referenced));
            $message = $gone
                ? 'Removed ' . $gone . ' auto-saved entr' . ($gone === 1 ? 'y' : 'ies')
                  . ' that no sign was using. Nothing on any sign changed.'
                : 'Nothing to tidy — every auto-saved entry is in use.';
        }
    }
}

// ============================================================
// READ
// ============================================================
$assets = $library->all();

// Rows the rules above would refuse or change if they were saved today. Nothing
// rewrites them — see AssetLibrary::contentIssue — but an admin cannot decide about
// a state nothing shows, and until now the first sign of one was a refusal while
// editing something else.
$flagged = 0;
foreach ($assets as $row) {
    if (AssetLibrary::contentIssue($row) !== null) { $flagged++; }
}

// How many auto-saved entries could be tidied away, for the button's label. Null
// references mean the question cannot be answered, so the button is not offered.
$referencedNow = isAdmin() ? $layouts->referencedAssetIds() : [];
$tidyCount     = ($referencedNow === null) ? null : count($library->pooledNotIn($referencedNow));

// Pre-fill edit form if an edit is requested via GET. Only for an admin, who is the
// only account the save below will accept — the Edit links were already admin-only,
// but the form itself was drawn for anybody who typed `?edit_id=`, which is a form
// that exists in order to be refused (§4j). It also decided which panel this page
// shows, so without this the "no sign assigned" notice was one query parameter away
// from being replaced by an editor.
$editAsset = (isAdmin() && isset($_GET['edit_id'])) ? $library->forId($_GET['edit_id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Library — <?= Markup::text(SITE_NAME) ?></title>
    <style>
        /* The Workspace Theme's thirteen roles (v2 step 5), one validated echo. This page's
           nav was a literal #1a252f, so like the Admin Panel's it was one of the two places
           in the app that ignored Site Branding entirely — a shop that set its own
           navigation colour got it everywhere but here. Reaching the roles fixes that as a
           side effect. (Naming the constant would fail the §5 grep that keeps every page
           away from it, and the grep is right: it reads text, and a comment is text.) */
        :root {
<?= SiteChrome::styleVariables() ?>
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        nav { background:var(--nav-bg); padding:0 20px; display:flex; align-items:center; gap:18px; height:50px; margin-bottom:24px; }
        nav .brand { color:var(--nav-text); font-weight:bold; font-size:15px; margin-right:auto; }
        nav a { color:#bdc3c7; text-decoration:none; font-size:13px; padding:6px 10px; border-radius:4px; }
        nav a:hover, nav a.active { background:var(--work-area); color:#fff; }
        .role-tag { background: <?= isAdmin() ? '#e74c3c' : '#3498db' ?>; color:#fff; font-size:10px;
                    font-weight:bold; padding:1px 6px; border-radius:8px; margin-left:4px; }

        body { background: #f0f2f5; padding: 24px; color: #333; }

        h1 { font-size: 22px; margin-bottom: 20px; color: #2c3e50; }

        .layout { display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap; }

        /* ---- Form panel ---- */
        .form-panel {
            background: #fff;
            border-radius: 8px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            width: 340px;
            flex-shrink: 0;
        }
        .form-panel h2 { font-size: 16px; margin-bottom: 16px; color: #2c3e50; }

        /* ---- What happened to the file I picked ---- */
        .file-note { font-size: 11px; margin-top: 4px; line-height: 1.5; }
        /* Indeterminate, and that is the honest shape: a plain form POST emits no
           progress events, so there is no percentage to show. It says "working". */
        .file-busy { margin-top: 6px; height: 4px; background: #e6e9ed; border-radius: 2px; overflow: hidden; }
        .file-busy-bar { width: 40%; height: 100%; background: var(--accent); border-radius: 2px;
                         animation: file-busy-slide 1.1s ease-in-out infinite; }
        @keyframes file-busy-slide {
            0%   { margin-left: -40%; }
            100% { margin-left: 100%; }
        }

        .form-group { margin-bottom: 14px; }
        label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 5px; color: #555; }
        input[type="text"], textarea, select {
            width: 100%; padding: 9px 11px;
            border: 1px solid #ccc; border-radius: 4px;
            font-size: 14px; color: #333;
        }
        input[type="file"] { font-size: 13px; }
        textarea { resize: vertical; }

        .msg { padding: 10px 14px; border-radius: 4px; margin-bottom: 14px; font-size: 14px; }
        .msg.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

        .btn {
            border: none; padding: 10px 18px; border-radius: 4px;
            cursor: pointer; font-weight: bold; font-size: 14px;
        }
        .btn-green  { background: #2ecc71; color: #fff; }
        .btn-green:hover  { background: #27ae60; }
        .btn-blue   { background: var(--accent); color: #fff; }
        .btn-blue:hover   { background: #2980b9; }
        .btn-red    { background: #e74c3c; color: #fff; }
        .btn-red:hover    { background: #c0392b; }
        .btn-gray   { background: #95a5a6; color: #fff; }
        .btn-gray:hover   { background: #7f8c8d; }

        /* ---- Asset table ---- */
        .table-panel {
            background: #fff;
            border-radius: 8px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            flex: 1;
            min-width: 300px;
        }
        .table-panel h2 { font-size: 16px; margin-bottom: 16px; color: #2c3e50; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 12px; text-align: left; border-bottom: 1px solid #ecf0f1; font-size: 13px; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        tr:hover td { background: #fafbfc; }

        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 11px; font-weight: bold; text-transform: uppercase;
        }
        .badge-text  { background: #d6eaf8; color: #1a5276; }
        .badge-image { background: #d5f5e3; color: #1e8449; }
        .badge-auto  { background: #fdebd0; color: #7e5109; }
        .badge-check { background: #fdecea; color: #c0392b; }

        .check-bar {
            background:#fdecea; border:1px solid #f5c6c1; border-radius:6px;
            padding:12px 14px; margin-bottom:16px; font-size:13px; color:#a5372b;
        }
        .check-bar p { margin:0; }

        .tidy-bar {
            background:#fdf6e3; border:1px solid #f5e0a3; border-radius:6px;
            padding:12px 14px; margin-bottom:16px; font-size:13px; color:#7e5109;
            display:flex; gap:12px; align-items:center; flex-wrap:wrap;
        }
        .tidy-bar p { margin:0; flex:1; min-width:220px; }

        .action-row { display: flex; gap: 6px; }

        .empty-state { text-align: center; padding: 40px; color: #95a5a6; font-size: 14px; }
    </style>
</head>
<body>
<nav>
    <span class="brand"><?= Markup::text(SITE_NAME) ?></span>
    <a href="builder.php">Builder</a>
    <a href="crud.php" class="active">Asset Library</a>
    <?php if (isAdmin()): ?><a href="admin_panel.php">Admin Panel</a><?php endif; ?>
    <span style="color:#bdc3c7; font-size:13px;">
        <?= Markup::text($me['username']) ?>
        <span class="role-tag"><?= isAdmin() ? 'ADMIN' : 'USER' ?></span>
    </span>
    <a href="logout.php">Sign Out</a>
</nav>

<h1 style="max-width:1040px; margin:0 auto 20px; padding:0 16px;">Asset Library</h1>

<div class="layout">

    <!-- ======================= ADD FORM ======================= -->
    <div class="form-panel">
        <h2><?php if ($editAsset): ?>&#9998; Edit Asset<?php elseif ($mayAdd): ?>&#10010; Add New Asset<?php else: ?>&#128274; Nothing to add to yet<?php endif; ?></h2>

        <?php if ($message): ?>
            <div class="msg <?= Markup::text($msgClass) ?>"><?= Markup::text($message) ?></div>
        <?php endif; ?>

        <?php if (!$editAsset && !$mayAdd): ?>
        <!-- No sign assigned, so no add form (#33). The whole library is still listed:
             this account can be asked to look something up, and a page that refuses to
             say what is in it cannot explain why it refused. What it cannot do is put
             anything into it, and the refusal in the create branch above is what
             enforces that — this panel only stops the form being offered. -->
        <p style="font-size:13px; color:#555; line-height:1.7;">
            This library is shared by every sign, and no display has been assigned to you
            yet — so there is nothing here for an entry of yours to go on. Ask an admin
            which display is yours; they assign it in the Admin Panel, under Displays.
        </p>
        <p style="font-size:13px; color:#7f8c8d; line-height:1.7; margin-top:12px;">
            Everything already saved is listed on the right, and you can keep reading it.
        </p>

        <?php elseif ($editAsset): ?>
        <!-- EDIT FORM -->
        <form method="POST" action="crud.php" enctype="multipart/form-data" onsubmit="return beginAssetSave(this)">
            <input type="hidden" name="action_update" value="1">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <!-- No hidden type field: what this entry is comes from the stored row
                 on the way in and on the way out, so the form cannot claim it is
                 something else and skip the rule that goes with it. #37 removed it;
                 this branch predates that and would have put it back. -->
            <input type="hidden" name="edit_id"   value="<?= intval($editAsset['id']) ?>">

            <div class="form-group">
                <label>Type</label>
                <input type="text" value="<?= Markup::text(strtoupper($editAsset['type'])) ?>" disabled>
            </div>
            <div class="form-group">
                <label>Label</label>
                <input type="text" name="edit_label" value="<?= Markup::text($editAsset['label'] ?? '') ?>" placeholder="e.g. Summer Promo Banner">
                <?php if (!empty($editAsset['auto_pooled'])): ?>
                <small style="display:block; margin-top:4px; color:#7e5109; font-size:11px;">
                    This was saved automatically when a sign was published. Saving it here
                    makes it yours — it will keep whatever name you give it and will never
                    be tidied away.
                </small>
                <?php endif; ?>
            </div>

            <?php if ($editAsset['type'] === AssetLibrary::TYPE_TEXT): ?>
            <div class="form-group">
                <label>Text Content</label>
                <textarea name="edit_content" rows="5"><?= Markup::text($editAsset['content']) ?></textarea>
            </div>
            <?php elseif ($editAsset['type'] !== AssetLibrary::TYPE_IMAGE): ?>
            <!-- Publishing pools a block's content under the *block's* type, and
                 the third type the column offers is `video`, which the add form
                 above does not: a video block that carries its own path arrives
                 here when the sign is published. The old form had two branches and
                 drew this one as an image, which put the path in an <img src> and
                 offered to replace it with a picture. Shown as what it is instead,
                 and stored as it arrives.

                 This comment named carousel, table and marquee until §4bl. Those
                 three are marked `pool: false` in the Builder and are not members of
                 `assets.type`, so no such entry has ever reached this branch. -->
            <div class="form-group">
                <label>Stored Content (<?= Markup::text(strtoupper($editAsset['type'])) ?>)</label>
                <textarea name="edit_content" rows="5"><?= Markup::text($editAsset['content']) ?></textarea>
                <small style="display:block; margin-top:4px; color:#7f8c8d; font-size:11px;">
                    This entry was saved automatically when a
                    <?= Markup::text($editAsset['type']) ?> block was published, and holds
                    that block's own settings. Change it only if you know what it should say.
                </small>
            </div>
            <?php else: ?>
            <div class="form-group">
                <label>Current Image</label>
                <img src="<?= Markup::text($editAsset['content']) ?>"
                     style="max-width:100%; max-height:80px; border-radius:4px; display:block; margin-bottom:8px;">
            </div>
            <div class="form-group">
                <label>Replace with new upload</label>
                <!-- The ceiling travels on the element rather than through a JavaScript
                     variable: it is the input's own property, both forms need it, and an
                     attribute read back with getAttribute() needs no second escaping
                     rule for the JS context (§4d). -->
                <input type="file" name="edit_image_file" accept="image/jpeg,image/png,image/gif,image/webp"
                       data-max-bytes="<?= intval(UploadLimit::imageBytes()) ?>"
                       data-max-label="<?= Markup::text(UploadLimit::describeImage()) ?>"
                       onchange="checkAssetFile(this)">
                <small style="display:block; margin-top:4px; color:#7f8c8d; font-size:11px;">JPG, PNG, GIF or WEBP, up to <?= Markup::text(UploadLimit::describeImage()) ?>.</small>
                <div class="file-note" style="display:none;"></div>
                <div class="file-busy" style="display:none;"><div class="file-busy-bar"></div></div>
            </div>
            <div class="form-group">
                <label>Or update path / URL</label>
                <input type="text" name="edit_content" value="<?= Markup::text($editAsset['content']) ?>" placeholder="uploads/image.jpg">
            </div>
            <?php endif; ?>

            <div style="display:flex; gap:8px; margin-top:4px;">
                <button type="submit" class="btn btn-blue">Save Changes</button>
                <a href="crud.php" class="btn btn-gray" style="text-decoration:none; display:inline-flex; align-items:center;">Cancel</a>
            </div>
        </form>

        <?php else: ?>
        <!-- ADD FORM -->
        <form method="POST" action="crud.php" enctype="multipart/form-data" onsubmit="return beginAssetSave(this)">
            <input type="hidden" name="action_create" value="1">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">

            <div class="form-group">
                <label>Type</label>
                <select name="type" id="asset-type" onchange="toggleFields()">
                    <option value="text">Text Block</option>
                    <option value="image">Image</option>
                </select>
            </div>
            <div class="form-group">
                <label>Label <span style="font-weight:normal; color:#888;">(so you can find it later)</span></label>
                <input type="text" name="label" placeholder="e.g. Summer Promo Headline">
                <small style="display:block; margin-top:4px; color:#7f8c8d; font-size:11px;">
                    Anything you save here is kept until you delete it, even if no sign uses it.
                    Avoid starting the name with &ldquo;Auto:&rdquo; — that is how the app marks its
                    own copies, which the tidy-up can remove.
                </small>
            </div>

            <div id="text-fields" class="form-group">
                <label>Text Content</label>
                <textarea name="text_content" rows="5" placeholder="Type your display text here…"></textarea>
            </div>

            <div id="image-fields" style="display:none;">
                <div class="form-group">
                    <label>Upload Image File</label>
                    <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp"
                           data-max-bytes="<?= intval(UploadLimit::imageBytes()) ?>"
                           data-max-label="<?= Markup::text(UploadLimit::describeImage()) ?>"
                           onchange="checkAssetFile(this)">
                    <!-- The size is stated, and it is stated as the number that will
                         actually be enforced. This line said "Accepted types: JPG, PNG,
                         GIF, WEBP" and nothing about size, so the whole of what a person
                         knew about the ceiling was whatever refused them. -->
                    <small style="display:block; margin-top:4px; color:#7f8c8d; font-size:11px;">Accepted types: JPG, PNG, GIF, WEBP &mdash; up to <?= Markup::text(UploadLimit::describeImage()) ?>.</small>
                    <div class="file-note" style="display:none;"></div>
                    <div class="file-busy" style="display:none;"><div class="file-busy-bar"></div></div>
                </div>
                <div class="form-group">
                    <label>Or Paste Image URL / Path</label>
                    <input type="text" name="image_url" placeholder="uploads/my-image.jpg">
                </div>
            </div>

            <button type="submit" class="btn btn-green">Save Library Asset</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- ======================= ASSET TABLE ======================= -->
    <div class="table-panel">
        <h2>All Saved Assets (<?= count($assets) ?>)</h2>

        <?php if ($flagged > 0): ?>
        <div class="check-bar">
            <p><strong><?= intval($flagged) ?></strong>
               entr<?= $flagged === 1 ? 'y is' : 'ies are' ?> marked <strong>check</strong> below.
               These were saved before the rules the Library applies today, so they hold
               something it would now refuse or change. <strong>Nothing has been altered</strong>
               and no sign has changed — hover the mark to see what is wrong with each, and fix
               them, or leave them, as you see fit.</p>
        </div>
        <?php endif; ?>

        <?php if (isAdmin() && $tidyCount === null): ?>
        <div class="tidy-bar">
            <p><strong>Cannot check for unused entries.</strong> The list of blocks
               using these entries could not be read, so nothing here can be tidied
               safely. Everything below still works as normal.</p>
        </div>
        <?php elseif (isAdmin() && $tidyCount > 0): ?>
        <div class="tidy-bar">
            <p><strong><?= intval($tidyCount) ?></strong>
               auto-saved entr<?= $tidyCount === 1 ? 'y is' : 'ies are' ?> no longer used by any
               sign. These are copies publishing made of text you edited, left behind when you
               published again. Removing them changes nothing on any sign.</p>
            <form method="POST" action="crud.php"
                  onsubmit="return confirm('Remove <?= intval($tidyCount) ?> unused auto-saved entr<?= $tidyCount === 1 ? 'y' : 'ies' ?>? Nothing on any sign will change.')">
                <input type="hidden" name="action_tidy" value="1">
                <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                <button type="submit" class="btn btn-blue" style="font-size:13px; padding:8px 14px;">Tidy up</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if (empty($assets)): ?>
            <div class="empty-state">No assets saved yet. Add one using the form on the left.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Label</th>
                    <th>Type</th>
                    <th>Preview</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assets as $row): ?>
                <tr>
                    <td style="color:#95a5a6; font-size:12px;">#<?= intval($row['id']) ?></td>
                    <td><strong><?= Markup::text($row['label'] ?: '—') ?></strong></td>
                    <td>
                        <span class="badge <?= $row['type'] === 'image' ? 'badge-image' : 'badge-text' ?>">
                            <?= Markup::text($row['type']) ?>
                        </span>
                        <?php if (!empty($row['auto_pooled'])): ?>
                        <span class="badge badge-auto"
                              title="Saved automatically when a sign was published. Renaming it makes it yours, and it will never be tidied away.">auto</span>
                        <?php endif; ?>
                        <?php $issue = AssetLibrary::contentIssue($row); ?>
                        <?php if ($issue !== null): ?>
                        <span class="badge badge-check"
                              title="Saved before the Library checked this: <?= Markup::text($issue) ?>. Nothing has been changed.">check</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['type'] === 'image'): ?>
                            <img src="<?= Markup::text($row['content']) ?>"
                                 style="max-width:70px; max-height:44px; border-radius:3px; object-fit:cover;"
                                 onerror="this.style.display='none'">
                        <?php else: ?>
                            <span style="font-size:12px; color:#555;">
                                <?php // toPlainText(), not strip_tags(): the latter deletes from a "<"
                                      // to the end of the value, so a stored "Kids <12 eat free"
                                      // previewed here as "Kids " while the sign showed the line. ?>
                                <?= Markup::text(mb_strimwidth(toPlainText($row['content']), 0, 40, '…')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-row">
                            <?php if (isAdmin()): ?>
                            <a href="crud.php?edit_id=<?= intval($row['id']) ?>" class="btn btn-blue" style="text-decoration:none; font-size:12px; padding:6px 12px;">Edit</a>

                            <form method="POST" action="crud.php" style="display:inline;"
                                  onsubmit="return confirm('Delete this asset? This cannot be undone.')">
                                <input type="hidden" name="action_delete" value="1">
                                <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                                <input type="hidden" name="delete_id" value="<?= intval($row['id']) ?>">
                                <button type="submit" class="btn btn-red" style="font-size:12px; padding:6px 12px;">Delete</button>
                            </form>
                            <?php else: ?>
                            <span style="font-size:12px; color:#95a5a6;">View only</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<script>
    function toggleFields() {
        var type = document.getElementById('asset-type').value;
        document.getElementById('text-fields').style.display  = type === 'text'  ? 'block' : 'none';
        document.getElementById('image-fields').style.display = type === 'image' ? 'block' : 'none';
    }

    // ============================================================
    // WHAT HAPPENED TO THE FILE I PICKED
    // ============================================================
    // Nothing on this page used to answer that. The picker took a file, the form sat
    // there looking ready, and the answer arrived after a full upload — or, over the
    // host's post_max_size, as a bare 403 about a security token (see the guard at the
    // top of this file). The Builder has had this since a 40 MB video was answered the
    // same way; the Asset Library is the door that never got it.
    //
    // Three sentences, all of them at pick time and all of them naming the file:
    // wrong type, too big, and — for a file that is neither — that it is ready and how
    // big it is. A refused file also has the input cleared, so picking it again is an
    // event the browser reports rather than a no-op it silently swallows.

    var ASSET_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Bytes as words, matching UploadLimit::describeBytes so both agree. */
    function describeAssetBytes(bytes) {
        if (bytes >= 1048576) { return Math.floor(bytes / 1048576) + ' MB'; }
        if (bytes >= 1024)    { return Math.floor(bytes / 1024) + ' KB'; }
        return bytes + ' bytes';
    }

    /** The note under a file input. Looked up from the input's own group, so one
     *  function serves the add form and the edit form without knowing which. */
    function sayAboutFile(input, text, bad) {
        var note = input.parentElement ? input.parentElement.querySelector('.file-note') : null;
        if (!note) { return; }
        note.textContent   = text;
        note.style.color   = bad ? '#c0392b' : '#7f8c8d';
        note.style.display = text ? 'block' : 'none';
    }

    /**
     * Is this pick usable? Says why if not, and clears the input if not.
     *
     * `accept=` on the input only filters the dialog — switch it to "All Files" and a
     * renamed .txt arrives — so the type is checked against what the browser reports
     * the file to be. The server checks the real bytes with mime_content_type() and
     * remains the check; this is the half that can answer immediately.
     */
    function checkAssetFile(input) {
        var f = input.files && input.files[0];
        if (!f) { sayAboutFile(input, '', false); return true; }

        if (ASSET_IMAGE_TYPES.indexOf(f.type) < 0) {
            sayAboutFile(input, 'Wrong file type (' + (f.type || 'type not recognised') + '). '
                              + 'Use a JPG, PNG, GIF or WEBP — this file was not selected.', true);
            input.value = '';
            return false;
        }
        if (f.size === 0) {
            sayAboutFile(input, 'That file is empty, so it was not selected.', true);
            input.value = '';
            return false;
        }
        // 0 would mean the attribute was missing or unreadable, and refusing every file
        // because a number could not be read is worse than letting the server refuse
        // this one: it has the same limit and the guard at the top of this file now
        // explains the dropped-body case in words.
        var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
        if (max > 0 && f.size > max) {
            sayAboutFile(input, 'File too big — ' + describeAssetBytes(f.size) + '. The library accepts up to '
                              + (input.getAttribute('data-max-label') || 'the server limit')
                              + '. This file was not selected.', true);
            input.value = '';
            return false;
        }

        sayAboutFile(input, f.name + ' — ' + describeAssetBytes(f.size) + ', ready to save.', false);
        return true;
    }

    /**
     * Called on submit: show that something is happening, or stop the submit.
     *
     * A plain form POST reports no progress — there are no events to listen to — so
     * the bar this shows is indeterminate on purpose. It says "working", which is
     * true, rather than a percentage, which would be invented. Turning this form into
     * an XHR upload to get a real percentage is a bigger change than the problem
     * warrants for a 10 MB image, and it would move the save off the one path that
     * already redirects.
     *
     * The pick is re-checked here rather than trusted: onchange did not run for a file
     * still in the input from a bfcache restore, and a form is not obliged to have been
     * through this page's JavaScript at all.
     */
    function beginAssetSave(form) {
        var input = form.querySelector('input[type=file]');
        if (input && !checkAssetFile(input)) { return false; }

        var file = input && input.files && input.files[0];
        var btn  = form.querySelector('button[type=submit]');
        // The action travels in a hidden field, so disabling the button loses no data.
        if (btn) { btn.disabled = true; btn.textContent = file ? 'Uploading…' : 'Saving…'; }
        if (file && input) {
            sayAboutFile(input, 'Uploading ' + file.name + ' (' + describeAssetBytes(file.size) + ')… '
                              + 'this can take a moment on shop Wi-Fi. Do not close this page.', false);
            var busy = input.parentElement ? input.parentElement.querySelector('.file-busy') : null;
            if (busy) { busy.style.display = 'block'; }
        }
        return true;
    }
</script>
</body>
</html>
