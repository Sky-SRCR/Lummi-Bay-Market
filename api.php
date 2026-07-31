<?php
require_once 'auth.php';
require_once 'db_connect.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// get_layout is publicly accessible so viewer.php (kiosk display) can fetch it without a session.
// All other endpoints require an authenticated session.
if ($action !== 'get_layout') {
    requireLogin();
}

header('Content-Type: application/json');
$isAdmin = isAdmin();

// Auto-migrate: add text_align column if not yet present
try { $pdo->exec("ALTER TABLE canvas_elements ADD COLUMN text_align VARCHAR(16) NOT NULL DEFAULT ''"); } catch(Exception $e) {}
// Auto-migrate: add 'table' to type ENUM if not already present
try { $pdo->exec("ALTER TABLE canvas_elements MODIFY COLUMN type ENUM('section','text','image','video','carousel','marquee','table') NOT NULL"); } catch(Exception $e) {}
// Auto-migrate: seed item_title_2 and price_2 block styles
try { $pdo->exec("INSERT IGNORE INTO block_styles (block_type,font_family,font_size,font_color,font_weight,font_style,line_height) VALUES ('item_title_2','Arial',24,'#27ae60','bold','normal',1.30),('price_2','Arial',30,'#e74c3c','bold','normal',1.20)"); } catch(Exception $e) {}
// Auto-migrate: add z_index column for layer ordering
try { $pdo->exec("ALTER TABLE canvas_elements ADD COLUMN z_index INT NOT NULL DEFAULT 1"); } catch(Exception $e) {}
// Auto-migrate: add hidden column for admin visibility control
try { $pdo->exec("ALTER TABLE canvas_elements ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e) {}

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

// ============================================================
// GET: get_layout
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_layout') {
    $settings = $pdo->query("SELECT * FROM canvas_settings WHERE id = 1")->fetch();

    // All elements; sections first so client can build DOM tree
    $elements = $pdo->query(
        "SELECT ce.*, a.content AS db_content
         FROM canvas_elements ce
         LEFT JOIN assets a ON ce.asset_id = a.id
         ORDER BY CASE WHEN ce.type='section' THEN 0 ELSE 1 END, ce.sort_order ASC, ce.id ASC"
    )->fetchAll();

    $styleRows = $pdo->query("SELECT * FROM block_styles")->fetchAll();
    $styles    = [];
    foreach ($styleRows as $s) $styles[$s['block_type']] = $s;

    echo json_encode(['settings' => $settings, 'elements' => $elements, 'block_styles' => $styles]);
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
    $data   = json_decode($_POST['layout_data'] ?? '[]', true) ?: [];
    $bgType = $_POST['bg_type'] ?? 'color';
    $bgVal  = $_POST['bg_val']  ?? '#1a1a2e';

    // Background image upload – admin only
    if ($isAdmin && $bgType === 'image' && isset($_FILES['bg_file'])) {
        $check = validateFile($_FILES['bg_file'], IMG_EXT, IMG_MIME);
        if ($check['ok']) {
            ensureUploads();
            $name = 'bg_' . time() . '.' . $check['ext'];
            if (move_uploaded_file($_FILES['bg_file']['tmp_name'], 'uploads/' . $name)) {
                $bgVal = 'uploads/' . $name;
            }
        }
    }

    $pdo->beginTransaction();
    try {
        // Only admin updates canvas background
        if ($isAdmin) {
            if ($bgType === 'image' && !isset($_FILES['bg_file'])) {
                // No new file uploaded — preserve existing image path, only update type
                $pdo->prepare("UPDATE canvas_settings SET bg_type=? WHERE id=1")->execute([$bgType]);
            } else {
                $pdo->prepare("UPDATE canvas_settings SET bg_type=?, bg_val=? WHERE id=1")->execute([$bgType, $bgVal]);
            }
        }

        // Phase 1: sections and temp_id → real_id map
        $tempMap = [];

        if ($isAdmin) {
            // Admin: wipe everything and re-insert all sections from submitted data
            $pdo->exec("DELETE FROM canvas_elements WHERE section_id IS NOT NULL");
            $pdo->exec("DELETE FROM canvas_elements");

            foreach ($data as $el) {
                if (($el['type'] ?? '') !== 'section') continue;

                $pdo->prepare(
                    "INSERT INTO canvas_elements
                     (type, x_pos, y_pos, width, height, section_bg, locked, sort_order, z_index)
                     VALUES ('section', ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    intval($el['x_pos'] ?? 0),
                    intval($el['y_pos'] ?? 0),
                    intval($el['width'] ?? 400),
                    intval($el['height'] ?? 300),
                    $el['section_bg'] ?? null,
                    intval($el['locked'] ?? 0),
                    intval($el['sort_order'] ?? 0),
                    max(1, intval($el['z_index'] ?? 1)),
                ]);
                $realId = $pdo->lastInsertId();
                if (!empty($el['temp_id'])) {
                    $tempMap[$el['temp_id']] = $realId;
                }
            }
        } else {
            // Basic user: preserve existing sections; only wipe non-section elements.
            // Build tempMap from the real DB IDs sent by the builder (db_id field).
            $pdo->exec("DELETE FROM canvas_elements WHERE type != 'section'");

            foreach ($data as $el) {
                if (($el['type'] ?? '') !== 'section') continue;
                if (!empty($el['temp_id']) && !empty($el['db_id'])) {
                    $tempMap[$el['temp_id']] = intval($el['db_id']);
                }
            }
        }

        // Phase 2: insert non-section elements
        $order = 0;
        foreach ($data as $el) {
            if (($el['type'] ?? '') === 'section') continue;

            $type       = $el['type'] ?? 'text';
            $subtype    = $el['block_subtype'] ?? 'free';
            $parentTmp  = $el['parent_temp_id'] ?? null;
            $sectionId  = $parentTmp ? ($tempMap[$parentTmp] ?? null) : null;
            $assetId    = !empty($el['asset_id']) ? intval($el['asset_id']) : null;
            $manual     = $el['manual_content'] ?? '';

            // Auto-save new standalone text/image content to asset pool
            if (!$assetId && !empty($manual) && !empty($el['save_to_db_pool'])) {
                $dup = $pdo->prepare("SELECT id FROM assets WHERE type=? AND content=? LIMIT 1");
                $dup->execute([$type, $manual]);
                $ex = $dup->fetch();
                if ($ex) {
                    $assetId = $ex['id'];
                    $manual  = null;
                } else {
                    $ins = $pdo->prepare("INSERT INTO assets (type,content,label) VALUES (?,?,?)");
                    $ins->execute([$type, $manual, 'Auto: '.substr(strip_tags($manual),0,20)]);
                    $assetId = $pdo->lastInsertId();
                    $manual  = null;
                }
            }

            $pdo->prepare(
                "INSERT INTO canvas_elements
                 (section_id, type, block_subtype, x_pos, y_pos, width, height,
                  manual_content, asset_id,
                  font_family, font_size, font_color, font_weight, font_style, line_height,
                  text_align, locked, sort_order, z_index)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $sectionId,
                $type,
                $subtype,
                intval($el['x_pos']   ?? 0),
                intval($el['y_pos']   ?? 0),
                intval($el['width']   ?? 200),
                intval($el['height']  ?? 100),
                $manual ?: null,
                $assetId,
                $el['font_family']  ?? 'Arial',
                intval($el['font_size'] ?? 16),
                $el['font_color']   ?? '#000000',
                $el['font_weight']  ?? 'normal',
                $el['font_style']   ?? 'normal',
                number_format(floatval($el['line_height'] ?? 1.4), 2),
                $el['text_align']   ?? '',
                intval($el['locked'] ?? 0),
                $order++,
                max(1, intval($el['z_index'] ?? 1)),
            ]);
        }

        $pdo->commit();
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Publish failed. Nothing was saved.']);
    }
    exit;
}

// ============================================================
// POST: save_brand_styles  (admin only)
// ============================================================
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
    $elements = $pdo->query(
        "SELECT id, section_id, type, block_subtype, manual_content, hidden, z_index, width, height, sort_order
         FROM canvas_elements
         ORDER BY CASE WHEN type='section' THEN 0 ELSE 1 END, sort_order ASC, id ASC"
    )->fetchAll();
    echo json_encode($elements);
    exit;
}

// ============================================================
// POST: set_element_hidden  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_element_hidden') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $id     = intval($_POST['element_id'] ?? 0);
    $hidden = intval($_POST['hidden'] ?? 0) ? 1 : 0;
    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing element_id.']); exit; }
    $pdo->prepare("UPDATE canvas_elements SET hidden=? WHERE id=?")->execute([$hidden, $id]);
    echo json_encode(['status'=>'success']);
    exit;
}

// ============================================================
// POST: delete_canvas_element  (admin only)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_canvas_element') {
    if (!$isAdmin) { echo json_encode(['status'=>'error','message'=>'Admins only.']); exit; }
    $id = intval($_POST['element_id'] ?? 0);
    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing element_id.']); exit; }
    // Children of sections are removed automatically via FK ON DELETE CASCADE
    $pdo->prepare("DELETE FROM canvas_elements WHERE id=?")->execute([$id]);
    echo json_encode(['status'=>'success']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
?>
