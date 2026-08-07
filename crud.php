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
require_once __DIR__ . '/lib/layout_store.php';
require_once __DIR__ . '/lib/assets.php';
requireCurrentAccount($pdo);   // all roles can access; delete is admin-only below
$me = currentUser();

// Signed-in page, so schema convergence is allowed here (invariant 7). This page
// is the only one that needs `assets.auto_pooled`, and an admin who never opens
// the admin panel would otherwise be tidying against the label prefix alone.
ensureSignageSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') { verifyCsrf(); }

$library = new AssetLibrary($pdo);
$signs   = new DisplayStore($pdo);
$layouts = new LayoutStore($pdo, $signs);

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
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'msg' => 'File is too large (max 10 MB).'];
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

$message  = '';
$msgClass = 'success';

// ============================================================
// CREATE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_create'])) {
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

// Pre-fill edit form if an edit is requested via GET
$editAsset = isset($_GET['edit_id']) ? $library->forId($_GET['edit_id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Asset Library — <?= htmlspecialchars(SITE_NAME) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        nav { background:#1a252f; padding:0 20px; display:flex; align-items:center; gap:18px; height:50px; margin-bottom:24px; }
        nav .brand { color:#fff; font-weight:bold; font-size:15px; margin-right:auto; }
        nav a { color:#bdc3c7; text-decoration:none; font-size:13px; padding:6px 10px; border-radius:4px; }
        nav a:hover, nav a.active { background:#2c3e50; color:#fff; }
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
        .btn-blue   { background: #3498db; color: #fff; }
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
    <span class="brand"><?= htmlspecialchars(SITE_NAME) ?></span>
    <a href="builder.php">Builder</a>
    <a href="crud.php" class="active">Asset Library</a>
    <?php if (isAdmin()): ?><a href="admin_panel.php">Admin Panel</a><?php endif; ?>
    <span style="color:#bdc3c7; font-size:13px;">
        <?= htmlspecialchars($me['username']) ?>
        <span class="role-tag"><?= isAdmin() ? 'ADMIN' : 'USER' ?></span>
    </span>
    <a href="logout.php">Sign Out</a>
</nav>

<h1 style="max-width:1040px; margin:0 auto 20px; padding:0 16px;">Asset Library</h1>

<div class="layout">

    <!-- ======================= ADD FORM ======================= -->
    <div class="form-panel">
        <h2><?= $editAsset ? '&#9998; Edit Asset' : '&#10010; Add New Asset' ?></h2>

        <?php if ($message): ?>
            <div class="msg <?= $msgClass ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($editAsset): ?>
        <!-- EDIT FORM -->
        <form method="POST" action="crud.php" enctype="multipart/form-data">
            <input type="hidden" name="action_update" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <!-- No hidden type field: what this entry is comes from the stored row
                 on the way in and on the way out, so the form cannot claim it is
                 something else and skip the rule that goes with it. -->
            <input type="hidden" name="edit_id"   value="<?= intval($editAsset['id']) ?>">

            <div class="form-group">
                <label>Type</label>
                <input type="text" value="<?= htmlspecialchars(strtoupper($editAsset['type'])) ?>" disabled>
            </div>
            <div class="form-group">
                <label>Label</label>
                <input type="text" name="edit_label" value="<?= htmlspecialchars($editAsset['label'] ?? '') ?>" placeholder="e.g. Summer Promo Banner">
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
                <textarea name="edit_content" rows="5"><?= htmlspecialchars($editAsset['content']) ?></textarea>
            </div>
            <?php elseif ($editAsset['type'] !== AssetLibrary::TYPE_IMAGE): ?>
            <!-- Publishing pools a block's content under the *block's* type, so a
                 carousel, table or marquee entry is ordinary here. Those hold JSON
                 or a media path; the old form had two branches and drew this one as
                 an image, which put the JSON in an <img src> and offered to replace
                 it with a file. Shown as what it is instead, and stored as it
                 arrives — stripping markup out of JSON leaves neither. -->
            <div class="form-group">
                <label>Stored Content (<?= htmlspecialchars(strtoupper($editAsset['type'])) ?>)</label>
                <textarea name="edit_content" rows="5"><?= htmlspecialchars($editAsset['content']) ?></textarea>
                <small style="display:block; margin-top:4px; color:#7f8c8d; font-size:11px;">
                    This entry was saved automatically when a
                    <?= htmlspecialchars($editAsset['type']) ?> block was published, and holds
                    that block's own settings. Change it only if you know what it should say.
                </small>
            </div>
            <?php else: ?>
            <div class="form-group">
                <label>Current Image</label>
                <img src="<?= htmlspecialchars($editAsset['content']) ?>"
                     style="max-width:100%; max-height:80px; border-radius:4px; display:block; margin-bottom:8px;">
            </div>
            <div class="form-group">
                <label>Replace with new upload</label>
                <input type="file" name="edit_image_file" accept="image/jpeg,image/png,image/gif,image/webp">
            </div>
            <div class="form-group">
                <label>Or update path / URL</label>
                <input type="text" name="edit_content" value="<?= htmlspecialchars($editAsset['content']) ?>" placeholder="uploads/image.jpg">
            </div>
            <?php endif; ?>

            <div style="display:flex; gap:8px; margin-top:4px;">
                <button type="submit" class="btn btn-blue">Save Changes</button>
                <a href="crud.php" class="btn btn-gray" style="text-decoration:none; display:inline-flex; align-items:center;">Cancel</a>
            </div>
        </form>

        <?php else: ?>
        <!-- ADD FORM -->
        <form method="POST" action="crud.php" enctype="multipart/form-data">
            <input type="hidden" name="action_create" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">

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
                    <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                    <small style="display:block; margin-top:4px; color:#7f8c8d; font-size:11px;">Accepted types: JPG, PNG, GIF, WEBP</small>
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
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
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
                    <td style="color:#95a5a6; font-size:12px;">#<?= $row['id'] ?></td>
                    <td><strong><?= htmlspecialchars($row['label'] ?: '—') ?></strong></td>
                    <td>
                        <span class="badge <?= $row['type'] === 'image' ? 'badge-image' : 'badge-text' ?>">
                            <?= htmlspecialchars($row['type']) ?>
                        </span>
                        <?php if (!empty($row['auto_pooled'])): ?>
                        <span class="badge badge-auto"
                              title="Saved automatically when a sign was published. Renaming it makes it yours, and it will never be tidied away.">auto</span>
                        <?php endif; ?>
                        <?php $issue = AssetLibrary::contentIssue($row); ?>
                        <?php if ($issue !== null): ?>
                        <span class="badge badge-check"
                              title="Saved before the Library checked this: <?= htmlspecialchars($issue) ?>. Nothing has been changed.">check</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['type'] === 'image'): ?>
                            <img src="<?= htmlspecialchars($row['content']) ?>"
                                 style="max-width:70px; max-height:44px; border-radius:3px; object-fit:cover;"
                                 onerror="this.style.display='none'">
                        <?php else: ?>
                            <span style="font-size:12px; color:#555;">
                                <?= htmlspecialchars(mb_strimwidth(strip_tags($row['content']), 0, 40, '…')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-row">
                            <?php if (isAdmin()): ?>
                            <a href="crud.php?edit_id=<?= $row['id'] ?>" class="btn btn-blue" style="text-decoration:none; font-size:12px; padding:6px 12px;">Edit</a>

                            <form method="POST" action="crud.php" style="display:inline;"
                                  onsubmit="return confirm('Delete this asset? This cannot be undone.')">
                                <input type="hidden" name="action_delete" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
                                <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
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
</script>
</body>
</html>
