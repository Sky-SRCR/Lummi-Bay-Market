<?php
require_once 'auth.php';
require_once 'db_connect.php';
requireLogin();   // all roles can access; delete is admin-only below
$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') { verifyCsrf(); }

// ---- File upload validation ----
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
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

// Validate an image referenced by URL/path (the "image URL" field) against the
// same extension allow-list as uploads, so SVG and other non-image types can't
// be inserted by reference — the file-upload path already blocks them.
function isAllowedImageRef(string $ref, array $allowed_ext): bool {
    $path = explode('|', $ref)[0];          // drop any |fit suffix (e.g. |contain)
    $path = strtok($path, '?#');            // drop query string / fragment
    $ext  = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    return in_array($ext, $allowed_ext, true);
}

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
            if ($content !== '' && !isAllowedImageRef($content, $allowedExtensions)) {
                $message  = 'Only JPG, PNG, GIF and WEBP images are allowed — SVG and other types are blocked.';
                $msgClass = 'error';
                $content  = '';
            }
        }
    }

    if (empty($message) && !empty($content)) {
        $stmt = $pdo->prepare("INSERT INTO assets (type, content, label) VALUES (?, ?, ?)");
        $stmt->execute([$type, $content, $label]);
        $message = 'Asset saved successfully.';
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
    $id      = intval($_POST['edit_id'] ?? 0);
    $type    = $_POST['edit_type']  ?? '';
    $label   = trim($_POST['edit_label']  ?? '');
    $content = ($type === 'text')
        ? toPlainText($_POST['edit_content'] ?? '')   // plain text only
        : trim($_POST['edit_content'] ?? '');

    if (!empty($_FILES['edit_image_file']['name'])) {
        $check = validateImageFile($_FILES['edit_image_file'], $allowedExtensions, $allowedMimeTypes);
        if ($check['ok']) {
            ensureUploadsDir();
            $fileName = 'crud_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $check['ext'];
            if (move_uploaded_file($_FILES['edit_image_file']['tmp_name'], 'uploads/' . $fileName)) {
                $content = 'uploads/' . $fileName;
            }
        } else {
            $message  = $check['msg'];
            $msgClass = 'error';
        }
    }

    // Block SVG / non-image references entered via the URL field on edit too.
    if (empty($message) && $type === 'image' && $content !== '' && !isAllowedImageRef($content, $allowedExtensions)) {
        $message  = 'Only JPG, PNG, GIF and WEBP images are allowed — SVG and other types are blocked.';
        $msgClass = 'error';
    }

    if (empty($message) && $id > 0 && !empty($content)) {
        $stmt = $pdo->prepare("UPDATE assets SET label = ?, content = ? WHERE id = ?");
        $stmt->execute([$label, $content, $id]);
        $message = 'Asset updated successfully.';
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
            $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
            $message = 'Asset deleted.';
        }
    }
}

// ============================================================
// READ
// ============================================================
$assets = $pdo->query("SELECT * FROM assets ORDER BY id DESC")->fetchAll();

// Pre-fill edit form if an edit is requested via GET
$editAsset = null;
if (isset($_GET['edit_id'])) {
    $s = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
    $s->execute([intval($_GET['edit_id'])]);
    $editAsset = $s->fetch();
}
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
            <input type="hidden" name="edit_id"   value="<?= intval($editAsset['id']) ?>">
            <input type="hidden" name="edit_type"  value="<?= htmlspecialchars($editAsset['type']) ?>">

            <div class="form-group">
                <label>Type</label>
                <input type="text" value="<?= htmlspecialchars(strtoupper($editAsset['type'])) ?>" disabled>
            </div>
            <div class="form-group">
                <label>Label</label>
                <input type="text" name="edit_label" value="<?= htmlspecialchars($editAsset['label'] ?? '') ?>" placeholder="e.g. Summer Promo Banner">
            </div>

            <?php if ($editAsset['type'] === 'text'): ?>
            <div class="form-group">
                <label>Text Content</label>
                <textarea name="edit_content" rows="5"><?= htmlspecialchars($editAsset['content']) ?></textarea>
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
            </div>

            <div id="text-fields" class="form-group">
                <label>Text Content</label>
                <textarea name="text_content" rows="5" placeholder="Type your display text here…"></textarea>
            </div>

            <div id="image-fields" style="display:none;">
                <div class="form-group">
                    <label>Upload Image File</label>
                    <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>
                <div class="form-group">
                    <label>Or Paste Image URL / Path</label>
                    <input type="text" name="image_url" placeholder="uploads/my-image.jpg">
                </div>
            </div>

            <button type="submit" class="btn btn-green">Save to Database</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- ======================= ASSET TABLE ======================= -->
    <div class="table-panel">
        <h2>All Saved Assets (<?= count($assets) ?>)</h2>
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
