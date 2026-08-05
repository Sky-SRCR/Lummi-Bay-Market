<?php
require_once 'auth.php';
require_once 'db_connect.php';
requireLogin();
$me      = currentUser();
$isAdmin = isAdmin();

// Load store branding (defaults if config not yet set)
if (!defined('BRAND_NAV_BG') && file_exists(__DIR__ . '/branding_config.php')) {
    require_once __DIR__ . '/branding_config.php';
}
if (!defined('BRAND_LOGO'))       define('BRAND_LOGO',       '');
if (!defined('BRAND_NAV_BG'))     define('BRAND_NAV_BG',     '#1a252f');
if (!defined('BRAND_NAV_BORDER')) define('BRAND_NAV_BORDER', '#0d1b24');
if (!defined('BRAND_ACCENT'))     define('BRAND_ACCENT',     '#3498db');
if (!defined('BRAND_TEXT'))       define('BRAND_TEXT',       '#ffffff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Builder — <?= htmlspecialchars(SITE_NAME) ?></title>
<script src="https://cdn.jsdelivr.net/npm/interactjs@1.10.27/dist/interact.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

body { background: #2c3e50; display: flex; flex-direction: column; height: 100vh; overflow: hidden; color: #fff; }

/* ── Nav ── */
#top-nav {
    background: <?= htmlspecialchars(BRAND_NAV_BG) ?>; padding: 0 16px; display: flex; align-items: center;
    gap: 14px; height: 46px; flex-shrink: 0; border-bottom: 1px solid <?= htmlspecialchars(BRAND_NAV_BORDER) ?>;
}
#top-nav .brand { font-weight: bold; font-size: 14px; color: <?= htmlspecialchars(BRAND_TEXT) ?>; }
#top-nav .user-badge { margin-left: 20px; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #bdc3c7; white-space: nowrap; flex-shrink: 0; }
#top-nav .nav-spacer { flex: 1; }
#top-nav a { color: #bdc3c7; text-decoration: none; font-size: 12px; padding: 5px 9px; border-radius: 3px; }
#top-nav a:hover { background: #2c3e50; color: #fff; }
.role-tag { background: <?= $isAdmin ? '#e74c3c' : '#3498db' ?>; color: #fff;
            font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 8px;
            text-transform: uppercase; }
.btn.publish-btn { background: <?= htmlspecialchars(BRAND_ACCENT) ?>; }

/* ── Control bar ── */
#control-bar {
    background: #1a252f; padding: 8px 14px; display: flex; gap: 8px;
    align-items: center; flex-wrap: wrap; flex-shrink: 0; border-bottom: 2px solid #34495e;
}
.btn { background: #3498db; border: none; color: #fff; padding: 6px 12px;
       border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 12px; white-space: nowrap; }
.btn:hover { filter: brightness(1.15); }
.btn.green  { background: #27ae60; }
.btn.purple { background: #8e44ad; }
.btn.orange { background: #e67e22; }
.btn.danger { background: #e74c3c; }
.btn.gray   { background: #7f8c8d; }
.btn:disabled { opacity: 0.4; cursor: not-allowed; filter: none; }
.sep { border-left: 1px solid #34495e; height: 24px; margin: 0 4px; }

/* Align toolbar – hidden until multi-select */
#align-bar {
    background: #1a252f; padding: 6px 14px; display: none; gap: 6px;
    align-items: center; flex-shrink: 0; border-bottom: 1px solid #34495e;
}
#align-bar span { font-size: 11px; color: #bdc3c7; margin-right: 4px; }
.align-btn { background: #2c3e50; border: 1px solid #4a6278; color: #fff;
             width: 32px; height: 28px; border-radius: 3px; cursor: pointer;
             font-size: 13px; display: inline-flex; align-items: center; justify-content: center; }
.align-btn:hover { background: #3d5166; }

/* Section target banner (basic users) */
#section-banner {
    background: #d35400; color: #fff; text-align: center; font-size: 12px;
    font-weight: 600; padding: 5px; display: none; flex-shrink: 0;
}

/* ── Canvas wrapper ── */
#editor-frame { flex: 1; overflow: auto; padding: 40px; display: flex;
                justify-content: flex-start; align-items: flex-start; user-select: none; }

#builder-canvas {
    width: 1920px; height: 1080px; background: #fff; position: relative;
    flex-shrink: 0; box-shadow: 0 10px 30px rgba(0,0,0,.5);
    background-size: cover; background-position: center;
}

/* ── Blocks ── */
.editable-block {
    position: absolute; min-width: 40px; min-height: 24px;
    cursor: default; touch-action: none;
    outline: 1px dashed rgba(255,255,255,0.28);
}
.editable-block:hover:not(.selected):not(.multi-sel) { outline: 1px dashed rgba(255,255,255,0.6); }
.editable-block.draggable-block { cursor: move; }
.editable-block.just-added { outline: 2px dashed #e0a400; outline-offset: -1px; background: rgba(255,212,0,.5); }
.editable-block.selected  { outline: 2px solid #e74c3c; box-shadow: 0 0 8px rgba(231,76,60,.5); }
.editable-block.multi-sel { outline: 2px solid #f39c12; box-shadow: 0 0 6px rgba(243,156,18,.4); }
.editable-block.locked-block { cursor: default; }
.editable-block.hidden-block { opacity: 0.45; }
.hidden-badge {
    position:absolute; top:0; left:0; right:0; z-index:50;
    background:rgba(192,57,43,0.82); color:#fff; font-size:9px; font-weight:bold;
    text-align:center; padding:2px 0; pointer-events:none; letter-spacing:1px;
}
/* ── Resize handles ── */
.rh {
    position: absolute; width: 10px; height: 10px;
    background: #fff; border: 2px solid #e74c3c; border-radius: 2px;
    z-index: 20; pointer-events: auto; touch-action: none;
    display: none; box-sizing: border-box;
}
.editable-block.selected .rh { display: block; }
.rh-nw { top: -5px; left: -5px; cursor: nw-resize; }
.rh-n  { top: -5px; left: calc(50% - 5px); cursor: n-resize; }
.rh-ne { top: -5px; right: -5px; cursor: ne-resize; }
.rh-e  { top: calc(50% - 5px); right: -5px; cursor: e-resize; }
.rh-se { bottom: -5px; right: -5px; cursor: se-resize; }
.rh-s  { bottom: -5px; left: calc(50% - 5px); cursor: s-resize; }
.rh-sw { bottom: -5px; left: -5px; cursor: sw-resize; }
.rh-w  { top: calc(50% - 5px); left: -5px; cursor: w-resize; }
/* Section blocks keep handles inside (overflow:hidden clips outside) */
.section-block .rh-nw { top: 2px; left: 2px; }
.section-block .rh-n  { top: 2px; }
.section-block .rh-ne { top: 2px; right: 2px; }
.section-block .rh-e  { right: 2px; }
.section-block .rh-se { bottom: 2px; right: 2px; }
.section-block .rh-s  { bottom: 2px; }
.section-block .rh-sw { bottom: 2px; left: 2px; }
.section-block .rh-w  { left: 2px; }
.lock-icon {
    position: absolute; top: 2px; right: 2px; font-size: 11px; color: rgba(255,255,255,.8);
    background: rgba(0,0,0,.4); border-radius: 2px; padding: 1px 3px; pointer-events: none; z-index: 5;
}

/* Section blocks */
.section-block {
    border: 2px solid #8e44ad; overflow: hidden;
    background-size: cover; background-position: center;
}
.section-block.targeted { border: 3px solid #e67e22; box-shadow: 0 0 12px rgba(230,126,34,.6); }
.section-label {
    position: absolute; top: 2px; left: 4px; font-size: 10px; color: rgba(255,255,255,.7);
    background: rgba(142,68,173,.7); padding: 1px 5px; border-radius: 2px;
    pointer-events: none; z-index: 5;
}

/* Text blocks */
.text-inner {
    width: 100%; height: 100%; padding: 4px; outline: none;
    word-break: break-word; overflow: hidden; user-select: none;
}
.text-inner[contenteditable="true"] { cursor: text; }

/* Image / video blocks */
.editable-block img, .editable-block video {
    width: 100%; height: 100%; display: block; pointer-events: none;
}

/* ── Inspector ── */
#inspector {
    position: fixed; right: 16px; top: 100px; width: 290px;
    background: #1a252f; border: 1px solid #34495e; border-radius: 6px;
    padding: 14px; display: none; flex-direction: column; gap: 10px;
    z-index: 300; box-shadow: 0 4px 20px rgba(0,0,0,.4);
    max-height: calc(100vh - 120px); overflow-y: auto;
}
#inspector h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 1px;
                color: #f39c12; border-bottom: 1px solid #34495e; padding-bottom: 6px; }
#inspector label { font-size: 11px; text-transform: uppercase; letter-spacing: .7px;
                   color: #bdc3c7; display: block; margin-bottom: 3px; }
#inspector input, #inspector select {
    width: 100%; padding: 6px 8px; border-radius: 4px; border: 1px solid #34495e;
    background: #2c3e50; color: #fff; font-size: 13px;
}
#inspector input[type="color"]  { height: 32px; padding: 2px; cursor: pointer; }
#inspector input[type="file"]   { font-size: 12px; color: #aaa; }
#inspector input[type="number"] { width: 80px; }
.insp-section { border-top: 1px solid #2c3e50; padding-top: 8px; }
.insp-row { display: flex; gap: 8px; align-items: flex-end; }
.insp-row > * { flex: 1; }

/* WYSIWYG bar */
#wysiwyg-bar { display: flex; gap: 3px; flex-wrap: wrap; }
.fmt-btn { background: #2c3e50; border: 1px solid #4a6278; color: #fff;
           width: 30px; height: 26px; border-radius: 3px; cursor: pointer; font-size: 12px;
           display: inline-flex; align-items: center; justify-content: center; }
.fmt-btn:hover { background: #3d5166; }

/* Brand lock badge */
.brand-lock { background: #8e44ad; color: #fff; font-size: 11px;
              padding: 3px 8px; border-radius: 10px; display: inline-block; margin-bottom: 4px; }

/* ── Carousel block preview ── */
.carousel-preview {
    width:100%; height:100%; background:#1a1a2e;
    display:flex; flex-direction:column; align-items:center;
    justify-content:center; gap:6px; pointer-events:none;
}
.carousel-preview img { max-width:90%; max-height:55%; object-fit:contain; }
.carousel-preview-lbl {
    background:rgba(52,73,94,.9); color:#fff; padding:3px 12px;
    border-radius:3px; font-size:12px; font-weight:600;
}

/* ── Marquee block preview ── */
.marquee-preview {
    height:100%; display:flex; align-items:center;
    padding:0 12px; white-space:nowrap; overflow:hidden;
}

/* ── Resize size tooltip ── */
#resize-label {
    display:none; position:fixed; z-index:9999;
    background:rgba(0,0,0,.82); color:#fff; font-size:13px; font-weight:bold;
    padding:4px 10px; border-radius:5px; pointer-events:none; white-space:nowrap;
    transform:translate(-50%,-50%); letter-spacing:.5px;
}

/* ── Carousel Slide Editor Modal ── */
#carousel-modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.75);
    z-index:500; align-items:center; justify-content:center;
}
#carousel-modal-overlay.open { display:flex; }
#carousel-modal {
    background:#1a252f; border-radius:8px; padding:24px;
    width:760px; max-width:95vw; max-height:90vh; overflow-y:auto;
    border:1px solid #34495e;
}
#carousel-modal h2  { font-size:16px; margin-bottom:4px; }
#carousel-modal > p { font-size:12px; color:#bdc3c7; margin-bottom:14px; }
.slide-row {
    background:#0d1b24; border:1px solid #2c3e50; border-radius:5px;
    padding:12px; margin-bottom:10px;
}
.slide-header {
    display:flex; justify-content:space-between; align-items:center;
    font-size:13px; font-weight:600; color:#f39c12; margin-bottom:8px;
}
.slide-fields { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.slide-field label { font-size:11px; color:#bdc3c7; display:block; margin-bottom:3px; }
.slide-field input[type="text"],
.slide-field textarea {
    width:100%; padding:6px 8px; background:#2c3e50; border:1px solid #34495e;
    color:#fff; border-radius:3px; font-size:13px;
}
.slide-field textarea { resize:vertical; }
.slide-img-preview {
    min-height:44px; background:#2c3e50; border:1px solid #34495e;
    border-radius:3px; display:flex; align-items:center; justify-content:center;
    padding:4px; margin-bottom:4px; font-size:11px; color:#7f8c8d;
}
.slide-img-preview img { max-width:100%; max-height:60px; object-fit:contain; }

/* ── Table block preview ── */
.table-preview {
    width:100%; height:100%; background:#1a1a2e;
    display:flex; flex-direction:column; align-items:center;
    justify-content:center; gap:6px; pointer-events:none;
}
.table-preview-lbl {
    background:rgba(52,73,94,.9); color:#fff; padding:3px 12px;
    border-radius:3px; font-size:12px; font-weight:600;
}

/* ── Table Editor Modal ── */
#table-modal-overlay {
    display:none; position:fixed; inset:0; background:rgba(0,0,0,.75);
    z-index:500; align-items:center; justify-content:center;
}
#table-modal-overlay.open { display:flex; }
#table-modal {
    background:#1a252f; border-radius:8px; padding:24px;
    width:860px; max-width:95vw; max-height:90vh; overflow-y:auto;
    border:1px solid #34495e;
}
#table-modal h2  { font-size:16px; margin-bottom:4px; }
#table-modal > p { font-size:12px; color:#bdc3c7; margin-bottom:14px; }
.table-editor-wrap { overflow-x:auto; margin-top:4px; }
.table-editor { border-collapse:collapse; width:100%; }
.table-editor th, .table-editor td {
    border:1px solid #2c3e50; padding:4px; vertical-align:top;
}
.table-editor thead th { background:#0d1b24; min-width:120px; }
.table-editor tbody td input[type="text"] {
    width:100%; padding:5px 7px; background:#2c3e50; border:1px solid #34495e;
    color:#fff; border-radius:3px; font-size:13px; box-sizing:border-box;
}
.col-style-sel {
    width:100%; padding:4px 6px; background:#2c3e50; color:#fff;
    border:1px solid #34495e; border-radius:3px; font-size:12px; margin-bottom:4px;
}
.col-align-row { display:flex; gap:3px; margin-bottom:4px; }
.col-width-row { display:flex; align-items:center; gap:3px; margin-bottom:4px; }
.col-width-inp { width:52px; background:#0d1b24; border:1px solid #2c3e50; color:#ecf0f1; border-radius:3px; padding:2px 4px; font-size:11px; }
.col-width-lbl { font-size:10px; color:#95a5a6; }
.col-align-sel { flex:1; padding:2px 4px; background:#2c3e50; color:#fff; border:1px solid #34495e; border-radius:3px; font-size:10px; }
.del-col-btn { width:100%; font-size:10px; padding:2px 4px; }
.del-row-td  { width:32px; text-align:center; background:#0d1b24; }

/* ── Toast ── */
#toast {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: #27ae60; color: #fff; padding: 10px 22px; border-radius: 4px;
    font-weight: bold; font-size: 13px; display: none; z-index: 9999;
    box-shadow: 0 4px 12px rgba(0,0,0,.3);
}
#toast.err { background: #e74c3c; }
</style>
</head>
<body>

<!-- ── Top Nav ── -->
<div id="top-nav">
    <?php if (BRAND_LOGO): ?>
        <img src="<?= htmlspecialchars(BRAND_LOGO) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>"
             style="max-height:32px; max-width:130px; object-fit:contain; flex-shrink:0;">
    <?php endif; ?>
    <span class="brand"><?= htmlspecialchars(SITE_NAME) ?></span>
    <span class="user-badge">
        <?= htmlspecialchars($me['username']) ?>
        <span class="role-tag"><?= $isAdmin ? 'ADMIN' : 'USER' ?></span>
    </span>
    <span class="nav-spacer"></span>
    <a href="crud.php">Asset Library</a>
    <?php if ($isAdmin): ?>
    <a href="admin_panel.php">Admin Panel</a>
    <?php endif; ?>
    <a href="help.php" target="_blank">Help</a>
    <a href="viewer.php" target="_blank">View Display ↗</a>
    <a href="logout.php">Sign Out</a>
</div>

<!-- ── Control bar ── -->
<div id="control-bar">
    <?php if ($isAdmin): ?>
        <button class="btn purple" onclick="createSection()">+ Section</button>
        <button class="btn"        onclick="createBlock('image',null)">+ Image</button>
        <button class="btn"        onclick="createBlock('carousel',null)">+ Carousel</button>
        <button class="btn"        onclick="createBlock('table',null)">+ Table</button>
        <button class="btn"        onclick="createBlock('marquee',null)">+ Marquee</button>
        <button class="btn"        onclick="createBlock('video',null)">+ Video</button>
        <div class="sep"></div>
    <?php endif; ?>

    <button class="btn orange" onclick="createBlock('text','section_header')">+ Section Header</button>
    <button class="btn orange" onclick="createBlock('text','item_title')">+ Item Title</button>
    <button class="btn orange" onclick="createBlock('text','price')">+ Price</button>
    <button class="btn orange" onclick="createBlock('text','description')">+ Description</button>

    <?php if ($isAdmin): ?>
    <div class="sep"></div>
    <label style="font-size:11px; color:#bdc3c7;">Background:</label>
    <select id="bg-type" onchange="toggleBgInputs()" style="padding:5px 7px; border-radius:3px; border:1px solid #34495e; background:#2c3e50; color:#fff; font-size:12px;">
        <option value="color">Color</option>
        <option value="image">Image</option>
    </select>
    <input type="color" id="bg-color" value="#1a1a2e" oninput="applyBg()"
           style="width:40px; height:30px; padding:2px; border:none; cursor:pointer; border-radius:3px;">
    <input type="file"  id="bg-file"  accept="image/*" onchange="applyBgFile()"
           style="display:none; font-size:11px; color:#aaa;">
    <?php endif; ?>

    <button class="btn publish-btn" style="margin-left:auto;" onclick="publishCanvas()">&#10003; Publish</button>
</div>

<!-- ── Align bar (shown on multi-select OR single select) ── -->
<div id="align-bar">
    <span style="font-size:11px;color:#bdc3c7;">Align Items:</span>
    <button class="align-btn" title="Align left edges (single: to parent left)"    onclick="alignBlocks('left')"     style="width:auto;padding:0 8px;font-size:11px;">&#9664; Left</button>
    <button class="align-btn" title="Align right edges (single: to parent right)"  onclick="alignBlocks('right')"    style="width:auto;padding:0 8px;font-size:11px;">Right &#9654;</button>
    <button class="align-btn" title="Align top edges (single: to parent top)"      onclick="alignBlocks('top')"      style="width:auto;padding:0 8px;font-size:11px;">&#9650; Top</button>
    <button class="align-btn" title="Align bottom edges (single: to parent bottom)" onclick="alignBlocks('bottom')"  style="width:auto;padding:0 8px;font-size:11px;">Bottom &#9660;</button>
    <button class="align-btn" title="Center horizontally (single: within parent)"  onclick="alignBlocks('center-h')" style="width:auto;padding:0 8px;font-size:11px;">&#8596; H-Center</button>
    <button class="align-btn" title="Center vertically (single: within parent)"    onclick="alignBlocks('center-v')" style="width:auto;padding:0 8px;font-size:11px;">&#8597; V-Center</button>
    <div class="sep"></div>
    <span style="font-size:11px;color:#bdc3c7;">Align to Parent:</span>
    <button class="align-btn" title="Snap left edge to parent left"   onclick="alignToParent('left')"     style="width:auto;padding:0 8px;font-size:11px;">&#9664; Left</button>
    <button class="align-btn" title="Center in parent horizontally"   onclick="alignToParent('center-h')" style="width:auto;padding:0 8px;font-size:11px;">&#8596; H-Center</button>
    <button class="align-btn" title="Snap right edge to parent right" onclick="alignToParent('right')"    style="width:auto;padding:0 8px;font-size:11px;">Right &#9654;</button>
    <button class="align-btn" title="Snap top edge to parent top"     onclick="alignToParent('top')"      style="width:auto;padding:0 8px;font-size:11px;">&#9650; Top</button>
    <button class="align-btn" title="Center in parent vertically"     onclick="alignToParent('center-v')" style="width:auto;padding:0 8px;font-size:11px;">&#8597; V-Center</button>
    <button class="align-btn" title="Snap bottom edge to parent bottom" onclick="alignToParent('bottom')" style="width:auto;padding:0 8px;font-size:11px;">Bottom &#9660;</button>
    <div class="sep"></div>
    <span id="sel-count" style="font-size:11px; color:#bdc3c7;"></span>
</div>

<!-- ── Section banner for basic users ── -->
<?php if (!$isAdmin): ?>
<div id="section-banner">
    Click on a <strong>section</strong> (purple border) to target it, then add your blocks.
</div>
<?php endif; ?>

<!-- ── Canvas ── -->
<div id="editor-frame">
    <div id="builder-canvas"></div>
</div>

<!-- ── Inspector panel ── -->
<div id="inspector">
    <h3 id="insp-title">Block</h3>

    <!-- Position + size (always visible when block selected) -->
    <div id="insp-dims">
        <div class="insp-row">
            <div>
                <label>X (px)</label>
                <input type="number" id="insp-x" min="-1920" max="1920" onchange="applyPos('x',this.value)">
            </div>
            <div>
                <label>Y (px)</label>
                <input type="number" id="insp-y" min="-1080" max="1080" onchange="applyPos('y',this.value)">
            </div>
        </div>
        <div class="insp-row" style="margin-top:4px;">
            <div>
                <label>W (px)</label>
                <input type="number" id="insp-w" min="40" max="1920" onchange="applyDim('w',this.value)">
            </div>
            <div>
                <label>H (px)</label>
                <input type="number" id="insp-h" min="24" max="1080" onchange="applyDim('h',this.value)">
            </div>
        </div>
    </div>

    <!-- Text align (all text blocks) -->
    <div id="insp-text-align" class="insp-section" style="display:none;">
        <label>Text Align</label>
        <div style="display:flex; gap:4px; margin-top:4px;">
            <button class="align-btn" id="ta-left"    onclick="applyTextAlign('left')"    title="Left"    style="width:auto;padding:0 8px;font-size:11px;">&#9664; Left</button>
            <button class="align-btn" id="ta-center"  onclick="applyTextAlign('center')"  title="Center"  style="width:auto;padding:0 8px;font-size:11px;">&#8660; Center</button>
            <button class="align-btn" id="ta-right"   onclick="applyTextAlign('right')"   title="Right"   style="width:auto;padding:0 8px;font-size:11px;">Right &#9654;</button>
            <button class="align-btn" id="ta-justify" onclick="applyTextAlign('justify')" title="Justify" style="width:auto;padding:0 8px;font-size:11px;">&#8644; Justify</button>
        </div>
    </div>

    <!-- Section controls (admin only) -->
    <div id="insp-section" class="insp-section" style="display:none;">
        <label>Section Background Image</label>
        <input type="file" id="section-bg-file" accept="image/*" onchange="uploadSectionBg(this)">
        <div id="section-bg-preview" style="margin-top:4px; font-size:11px; color:#bdc3c7;"></div>
        <label style="margin-top:8px;">Background Fit</label>
        <select id="section-bg-fit" onchange="changeSectionBgFit(this.value)"
                style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #34495e;background:#2c3e50;color:#fff;font-size:13px;margin-top:2px;">
            <option value="cover">Cover — crop to fill</option>
            <option value="contain">Contain — whole image</option>
            <option value="fill">Stretch to fill</option>
            <option value="tile">Tile (repeat)</option>
            <option value="center">Center (no scale)</option>
        </select>
        <button class="btn danger" style="width:100%; margin-top:8px; font-size:12px;"
                onclick="clearSectionBg()">Remove Background</button>
    </div>

    <!-- Font controls (admin only, free text) -->
    <div id="insp-font" class="insp-section" style="display:none;">
        <label>Font</label>
        <select id="font-family" onchange="updateStyle('fontFamily',this.value)">
            <option>Arial</option><option>Georgia</option><option>Verdana</option>
            <option>Tahoma</option><option value="'Trebuchet MS',sans-serif">Trebuchet MS</option>
            <option value="'Times New Roman',serif">Times New Roman</option>
            <option value="'Courier New',monospace">Courier New</option>
            <option>Impact</option>
        </select>
        <div class="insp-row" style="margin-top:6px;">
            <div>
                <label>Size (px)</label>
                <input type="number" id="font-size" min="8" max="300"
                       oninput="updateStyle('fontSize',this.value+'px')">
            </div>
            <div>
                <label>Line Height</label>
                <input type="number" id="line-height" min="0.8" max="4" step="0.1"
                       oninput="updateStyle('lineHeight',this.value)">
            </div>
        </div>
        <div class="insp-row" style="margin-top:6px;">
            <div>
                <label>Color</label>
                <input type="color" id="font-color" style="width:100%;"
                       oninput="updateStyle('color',this.value)">
            </div>
            <div>
                <label>Weight</label>
                <select id="font-weight" onchange="updateStyle('fontWeight',this.value)">
                    <option value="normal">Normal</option>
                    <option value="bold">Bold</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Brand lock info (typed text blocks) -->
    <div id="insp-brand-lock" class="insp-section" style="display:none;">
        <span class="brand-lock">&#128274; Brand Style Applied</span>
        <div id="insp-brand-name" style="font-size:11px; color:#bdc3c7; margin-top:4px;"></div>
    </div>

    <!-- Image upload + fit -->
    <div id="insp-image" class="insp-section" style="display:none;">
        <label>Upload Image</label>
        <input type="file" id="img-file" accept="image/*" onchange="uploadBlockImage(this)">
        <label style="margin-top:8px;">Image Fit</label>
        <select id="img-fit" onchange="changeImageFit(this.value)"
                style="width:100%;padding:6px 8px;border-radius:4px;border:1px solid #34495e;background:#2c3e50;color:#fff;font-size:13px;margin-top:2px;">
            <option value="fill">Stretch to fill (may distort)</option>
            <option value="contain">Contain — whole image, letterbox</option>
            <option value="cover">Cover — crop to fill, no distortion</option>
            <option value="fit-w">Fit Width — clip height</option>
            <option value="fit-h">Fit Height — clip width</option>
        </select>
    </div>

    <!-- Video upload (admin only) -->
    <div id="insp-video" class="insp-section" style="display:none;">
        <label>Upload Video</label>
        <input type="file" id="vid-file" accept="video/mp4,video/webm,video/ogg" onchange="uploadBlockVideo(this)">
        <div style="font-size:11px; color:#bdc3c7; margin-top:4px;">MP4, WebM, OGV — max 50 MB</div>
    </div>

    <!-- Carousel editor -->
    <div id="insp-carousel" class="insp-section" style="display:none;">
        <label>Carousel</label>
        <div class="insp-row">
            <div>
                <label>Interval (sec)</label>
                <input type="number" id="carousel-interval" min="1" max="60" value="5"
                       style="width:70px;" oninput="updateCarouselInterval(this.value)">
            </div>
            <div>
                <label>&nbsp;</label>
                <button class="btn" style="font-size:12px;padding:5px 10px;" onclick="openCarouselModal()">Edit Slides</button>
            </div>
        </div>
        <div id="carousel-slide-count" style="font-size:11px;color:#bdc3c7;margin-top:4px;"></div>
    </div>

    <!-- Table editor -->
    <div id="insp-table" class="insp-section" style="display:none;">
        <label>Table</label>
        <div id="table-info" style="font-size:11px;color:#bdc3c7;margin-top:2px;"></div>
        <button class="btn" style="font-size:12px;padding:5px 10px;margin-top:6px;width:100%;"
                onclick="openTableModal()">Edit Table</button>
    </div>

    <!-- Marquee editor -->
    <div id="insp-marquee" class="insp-section" style="display:none;">
        <label>Marquee Text</label>
        <textarea id="marquee-text" rows="3"
                  style="width:100%;padding:6px;background:#2c3e50;color:#fff;border:1px solid #4a6278;border-radius:3px;font-size:13px;resize:vertical;"
                  oninput="updateMarqueeText(this.value)"></textarea>
        <label style="margin-top:6px;">Scroll Speed</label>
        <input type="range" id="marquee-speed" min="10" max="300" value="80"
               style="width:100%;margin-top:4px;" oninput="updateMarqueeSpeed(this.value)">
        <div id="marquee-speed-label" style="font-size:11px;color:#bdc3c7;margin-top:2px;">80 px/sec</div>
        <label style="margin-top:6px;">Text Style</label>
        <div style="display:flex;gap:6px;align-items:center;margin-top:4px;">
            <input type="color" id="marquee-color" value="#ffffff"
                   style="width:36px;height:30px;flex-shrink:0;" oninput="updateMarqueeStyle()">
            <input type="number" id="marquee-size" value="28" min="10" max="120" placeholder="px"
                   style="width:60px;" oninput="updateMarqueeStyle()">
            <select id="marquee-weight" style="flex:1;" onchange="updateMarqueeStyle()">
                <option value="normal">Normal</option>
                <option value="bold" selected>Bold</option>
            </select>
        </div>
        <label style="margin-top:6px;">Background Color</label>
        <div style="display:flex;align-items:center;gap:8px;margin-top:4px;">
            <input type="color" id="marquee-bg" value="#c0392b"
                   style="width:60px;height:30px;" oninput="updateMarqueeStyle()">
            <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#bdc3c7;cursor:pointer;">
                <input type="checkbox" id="marquee-bg-transparent" onchange="updateMarqueeStyle()">
                Transparent
            </label>
        </div>
    </div>

    <!-- DB Asset link -->
    <div id="insp-asset" class="insp-section">
        <label>Link DB Asset</label>
        <select id="asset-link" onchange="linkAsset(this.value)">
            <option value="">— None (manual content) —</option>
        </select>
    </div>

    <!-- Align tip -->
    <div class="insp-section" style="font-size:11px;color:#7f8c8d;line-height:1.5;">
        &#128161; <strong style="color:#bdc3c7;">Alignment tools:</strong> Select one block to align it to its parent. Shift+click additional blocks (same parent only) to align them to each other.
    </div>

    <!-- Layer / Z-index -->
    <div class="insp-section" id="insp-zindex">
        <label>Layer Order</label>
        <div style="display:flex;gap:4px;margin-top:4px;">
            <button class="btn gray" style="flex:1;padding:4px 2px;font-size:11px;" onclick="sendToBack()"   title="Send to Back">&#8609; Back</button>
            <button class="btn gray" style="flex:1;padding:4px 2px;font-size:11px;" onclick="sendBackward()" title="Send Backward">&#8595; Bwd</button>
            <button class="btn gray" style="flex:1;padding:4px 2px;font-size:11px;" onclick="bringForward()" title="Bring Forward">&#8593; Fwd</button>
            <button class="btn gray" style="flex:1;padding:4px 2px;font-size:11px;" onclick="bringToFront()" title="Bring to Front">&#8607; Front</button>
        </div>
        <div style="font-size:11px;color:#bdc3c7;margin-top:4px;">Layer: <span id="insp-zindex-val">1</span></div>
    </div>

    <!-- Lock toggle -->
    <div class="insp-section">
        <label>
            <input type="checkbox" id="lock-toggle" onchange="toggleLock(this.checked)">
            Lock this block (prevent accidental moves)
        </label>
    </div>

    <!-- Delete -->
    <div class="insp-section">
        <button class="btn danger" style="width:100%; font-size:12px;" onclick="deleteSelected()">
            &#128465; Delete Block
        </button>
    </div>
</div>

<!-- ── Carousel Slide Editor Modal ── -->
<div id="carousel-modal-overlay">
    <div id="carousel-modal">
        <h2>Edit Carousel Slides</h2>
        <p>Add slides with an image, title, price, and description. They cycle automatically on the display.</p>
        <div id="carousel-slides-list"></div>
        <button class="btn" style="margin-top:10px;font-size:12px;" onclick="addSlideRow()">+ Add Slide</button>
        <div style="margin-top:16px;display:flex;gap:10px;">
            <button class="btn green" onclick="saveCarouselSlides()">Save Slides</button>
            <button class="btn gray"  onclick="closeCarouselModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Table Editor Modal ── -->
<div id="table-modal-overlay">
    <div id="table-modal">
        <h2>Edit Table</h2>
        <p>Set the column style using the dropdown, then enter cell content. The dropdowns are hidden on the display screen.</p>
        <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
            <button class="btn" style="font-size:12px;padding:5px 10px;" onclick="addTableRow()">+ Add Row</button>
            <button class="btn" style="font-size:12px;padding:5px 10px;" onclick="addTableCol()">+ Add Column</button>
            <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#bdc3c7;margin-left:10px;">
                Row padding
                <input type="number" id="table-row-padding" min="0" max="120" value="0"
                       style="width:52px;background:#0d1b24;border:1px solid #2c3e50;color:#ecf0f1;border-radius:3px;padding:2px 4px;font-size:12px;">
                px
            </label>
        </div>
        <div class="table-editor-wrap">
            <table class="table-editor">
                <thead id="table-editor-head"></thead>
                <tbody id="table-editor-body"></tbody>
            </table>
        </div>
        <div style="margin-top:16px;display:flex;gap:10px;">
            <button class="btn green" onclick="saveTable()">Save Table</button>
            <button class="btn gray"  onclick="closeTableModal()">Cancel</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
// ============================================================
// CONSTANTS (injected by PHP)
// ============================================================
var IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
var SITE_NAME = <?= json_encode(SITE_NAME, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
var CSRF_TOKEN = <?= json_encode(csrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Block default sizes
var BLOCK_DEFAULTS = {
    section_header: { w:420, h:60  },
    item_title:     { w:300, h:50  },
    price:          { w:160, h:60  },
    description:    { w:360, h:90  },
    image:          { w:220, h:160 },
    video:          { w:400, h:225 },
    free:           { w:320, h:80  },
    section:        { w:600, h:380 },
    carousel:       { w:480, h:320 },
    table:          { w:480, h:200 },
    marquee:        { w:1920, h:60  },
};

var FONT_FAMILIES = ['Arial','Georgia','Verdana','Tahoma',
    "'Trebuchet MS',sans-serif","'Times New Roman',serif",
    "'Courier New',monospace",'Impact'];

var TYPE_LABELS = {
    section_header:'Section Header', item_title:'Item Title',
    price:'Price', description:'Description'
};

// ============================================================
// STATE
// ============================================================
var activeBlock    = null;   // single selected block
var multiSel       = [];     // multi-selection array
var _shiftDown     = false;  // tracks Shift key for interact.js drag guard
var targetSection  = null;   // section targeted for adding (basic users + admin)
var savedRange     = null;   // preserved text selection for WYSIWYG
var assetsCache    = [];
var blockStyles    = {};     // brand standards cache

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    Promise.all([loadAssets(), loadLayout()]).catch(function() {
        showToast('Failed to load layout.', true);
    });
    setupCanvas();
    if (!IS_ADMIN) {
        document.getElementById('section-banner').style.display = 'block';
    }
});

// ============================================================
// LOAD
// ============================================================
function loadAssets() {
    return fetch('api.php?action=get_assets')
        .then(function(r){ return r.json(); })
        .then(function(list) {
            assetsCache = list;
            var sel = document.getElementById('asset-link');
            sel.innerHTML = '<option value="">— None (manual content) —</option>';
            list.forEach(function(a) {
                sel.innerHTML += '<option value="'+a.id+'">['+a.type.toUpperCase()+'] '+escHtml(a.label||a.content.substr(0,20))+'</option>';
            });
        });
}

// Populate the asset-link dropdown with only assets whose type matches the
// selected block, so e.g. a text asset can't be linked into an image block.
function populateAssetLinkOptions(blockType) {
    var sel = document.getElementById('asset-link');
    if (!sel) return;
    sel.innerHTML = '<option value="">— None (manual content) —</option>';
    assetsCache.forEach(function(a) {
        if (a.type !== blockType) return;
        sel.innerHTML += '<option value="'+a.id+'">['+a.type.toUpperCase()+'] '+escHtml(a.label||a.content.substr(0,20))+'</option>';
    });
}

function loadLayout() {
    return fetch('api.php?action=get_layout')
        .then(function(r){ return r.json(); })
        .then(function(data) {
            blockStyles = data.block_styles || {};
            var canvas  = document.getElementById('builder-canvas');

            if (data.settings) {
                var s = data.settings;
                document.getElementById('bg-type') && (document.getElementById('bg-type').value = s.bg_type);
                if (s.bg_type === 'color') {
                    document.getElementById('bg-color') && (document.getElementById('bg-color').value = s.bg_val);
                    canvas.style.backgroundColor = s.bg_val;
                    canvas.style.backgroundImage = 'none';
                    applyBg();
                } else {
                    canvas.style.backgroundImage = "url('"+s.bg_val+"')";
                }
                IS_ADMIN && toggleBgInputs();
            }

            var elements = data.elements || [];
            // Render sections first
            elements.filter(function(e){ return e.type==='section'; }).forEach(function(e){
                renderSection(e);
            });
            // Render children and root blocks
            elements.filter(function(e){ return e.type!=='section'; }).forEach(function(e){
                var parent = e.section_id
                    ? document.querySelector('.section-block[data-db-id="'+e.section_id+'"]')
                    : canvas;
                if (parent) renderBlock(e, parent);
            });

            setupInteract();
        });
}

// ============================================================
// BACKGROUND (admin)
// ============================================================
function toggleBgInputs() {
    if (!IS_ADMIN) return;
    var t = document.getElementById('bg-type').value;
    document.getElementById('bg-color').style.display = t==='color' ? 'inline-block' : 'none';
    document.getElementById('bg-file').style.display  = t==='image' ? 'inline-block' : 'none';
}
function applyBg() {
    if (!IS_ADMIN) return;
    var canvas = document.getElementById('builder-canvas');
    canvas.style.backgroundColor = document.getElementById('bg-color').value;
    canvas.style.backgroundImage = 'none';
}
function applyBgFile() {
    if (!IS_ADMIN) return;
    var f = document.getElementById('bg-file').files[0];
    if (!f) return;
    var r = new FileReader();
    r.onload = function(e){ document.getElementById('builder-canvas').style.backgroundImage = "url('"+e.target.result+"')"; };
    r.readAsDataURL(f);
}

// ============================================================
// CREATE SECTION (admin)
// ============================================================
function createSection() {
    if (!IS_ADMIN) return;
    var def    = BLOCK_DEFAULTS.section || {w:600, h:380};
    var center = getCanvasDropCenter(def.w, def.h, null);
    renderSection({
        type:'section', temp_id: tmpId(), db_id: null,
        x_pos: center.x, y_pos: center.y, width: def.w, height: def.h, section_bg: null, locked: 0, z_index: 1
    });
}

function renderSection(el) {
    var s = document.createElement('div');
    s.className = 'editable-block section-block';
    if (!el.locked && IS_ADMIN) s.classList.add('draggable-block');
    s.dataset.type    = 'section';
    s.dataset.tempId  = el.temp_id || tmpId();
    s.dataset.dbId    = el.id      || '';
    s.dataset.locked  = el.locked  || 0;
    s.dataset.zIndex  = Math.max(1, parseInt(el.z_index) || 1);
    s.style.zIndex    = s.dataset.zIndex;
    s.dataset.hidden  = parseInt(el.hidden) ? '1' : '0';
    if (parseInt(el.hidden)) { s.classList.add('hidden-block'); }

    // Parse path|fit format for section background
    var _bgRaw   = el.section_bg || '';
    var _bgParts = _bgRaw.split('|');
    var _bgPath  = _bgParts[0];
    var _bgFit   = _bgParts[1] || 'cover';
    s.dataset.sectionBg = _bgPath;
    s.dataset.bgFit     = _bgFit;

    s.style.width     = el.width  + 'px';
    s.style.height    = el.height + 'px';
    s.style.transform = 'translate('+el.x_pos+'px,'+el.y_pos+'px)';
    s.setAttribute('data-x', el.x_pos);
    s.setAttribute('data-y', el.y_pos);
    if (_bgPath) {
        s.style.backgroundImage = "url('"+_bgPath+"')";
        applySectionBgFit(s, _bgFit);
    }
    // Label
    var lbl = document.createElement('div');
    lbl.className = 'section-label';
    lbl.textContent = 'Section';
    s.appendChild(lbl);
    // Lock icon
    if (el.locked) appendLockIcon(s);

    s.addEventListener('mousedown', function(e) {
        if (e.target.closest('.child-block')) return;
        if (IS_ADMIN) {
            if (e.shiftKey) {
                e.preventDefault();
                toggleMultiSel(s);
            } else {
                selectBlock(s);
            }
        }
        if (!e.shiftKey) setTargetSection(s);
    });
    addResizeHandles(s);
    document.getElementById('builder-canvas').appendChild(s);
}

// ============================================================
// CREATE BLOCK
// ============================================================
function createBlock(type, subtype) {
    // Basic users must have a section targeted
    if (!IS_ADMIN && !targetSection) {
        showToast('Please click on a section first to add content.', true);
        return;
    }

    var key  = subtype || type;
    var def  = BLOCK_DEFAULTS[key] || {w:200,h:100};
    var parent = targetSection || document.getElementById('builder-canvas');

    // Carousel / Table width: 90% of section if inside one, otherwise 200px
    if (type === 'carousel' || type === 'table') {
        var _cw = (parent && parent.classList && parent.classList.contains('section-block'))
            ? Math.round(parent.offsetWidth * 0.9)
            : 200;
        def = { w: _cw, h: def.h };
    }

    // For basic users: check if block fits within targeted section
    if (!IS_ADMIN && targetSection) {
        var sw = targetSection.offsetWidth;
        var sh = targetSection.offsetHeight;
        if (def.w + 20 > sw || def.h + 20 > sh) {
            showToast('Block ('+def.w+'×'+def.h+'px) is too large for this section ('+sw+'×'+sh+'px).', true);
            return;
        }
    }

    var center = getCanvasDropCenter(def.w, def.h, parent);
    var el = {
        type: type, block_subtype: subtype || 'free',
        x_pos: center.x, y_pos: center.y, width: def.w, height: def.h,
        manual_content: type==='text' ? (subtype ? 'Enter text here' : 'Double-click to edit') : '',
        asset_id: null, locked: 0, z_index: 1,
        font_family: 'Arial', font_size: 16, font_color: '#000000',
        font_weight: 'normal', font_style: 'normal', line_height: 1.4
    };
    renderBlock(el, parent, true);
}

function renderBlock(el, parent, isNew) {
    var block = document.createElement('div');
    block.className = 'editable-block';
    var isChildBlock = parent !== document.getElementById('builder-canvas');
    block.classList.add(isChildBlock ? 'child-block' : 'root-block');
    if (!el.locked && (IS_ADMIN || isChildBlock)) block.classList.add('draggable-block');
    if (el.locked) block.classList.add('locked-block');

    block.dataset.type    = el.type;
    block.dataset.subtype = el.block_subtype || 'free';
    block.dataset.assetId = el.asset_id   || '';
    block.dataset.sectionBg = '';
    block.dataset.locked  = el.locked     ? '1' : '0';
    block.dataset.zIndex  = Math.max(1, parseInt(el.z_index) || 1);
    block.style.zIndex    = block.dataset.zIndex;
    block.dataset.hidden  = parseInt(el.hidden) ? '1' : '0';
    if (parseInt(el.hidden)) {
        block.classList.add('hidden-block');
        var _hb = document.createElement('div');
        _hb.className = 'hidden-badge';
        _hb.textContent = 'HIDDEN';
        block.appendChild(_hb);
    }
    block.style.width     = el.width  + 'px';
    block.style.height    = el.height + 'px';
    block.style.transform = 'translate('+el.x_pos+'px,'+el.y_pos+'px)';
    block.setAttribute('data-x', el.x_pos);
    block.setAttribute('data-y', el.y_pos);

    var content = el.asset_id ? el.db_content : el.manual_content;

    if (el.type === 'text') {
        applyTextStyles(block, el);
        if (el.text_align) { block.style.textAlign = el.text_align; block.dataset.textAlign = el.text_align; }
        var inner = document.createElement('div');
        inner.className = 'text-inner';
        inner.contentEditable = 'true';
        inner.style.pointerEvents = 'none'; // disabled until dblclick; lets drag/shift+click reach block div
        inner.style.whiteSpace = 'pre-wrap'; // preserve line breaks in plain text
        inner.textContent = content || (el.block_subtype !== 'free' ? 'Enter text here' : 'Double-click to edit');
        if (isNew) {
            // Newly added block: highlight it so it's easy to find on any
            // canvas. Builder-only; clears on first edit / move / text change.
            block.classList.add('just-added');
            inner.addEventListener('input', function(){ block.classList.remove('just-added'); }, { once: true });
        }
        inner.addEventListener('focus', function() {
            if (_shiftDown || multiSel.length > 0) { inner.blur(); return; }
            if (block !== activeBlock) selectBlock(block);
        });
        inner.addEventListener('blur',  function() {
            inner.style.pointerEvents = 'none';
            inner.style.userSelect = '';
            inner.style.webkitUserSelect = '';
        });
        block.addEventListener('dblclick', function(e) {
            if (block.dataset.locked === '1' || _shiftDown || e.target.closest('.rh')) return;
            block.classList.remove('just-added');   // first edit clears the highlight
            inner.style.pointerEvents = 'auto';
            inner.style.userSelect = 'text';
            inner.style.webkitUserSelect = 'text';
            inner.focus();
            if (document.caretRangeFromPoint) {
                var range = document.caretRangeFromPoint(e.clientX, e.clientY);
                if (range) { var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range); }
            }
        });
        block.appendChild(inner);
    } else if (el.type === 'image') {
        var _parts = (content || '').split('|');
        var _imgSrc = _parts[0];
        var _fit    = _parts[1] || 'fill';
        block.dataset.imgFit = _fit;
        block.dataset.imgSrc = _imgSrc;
        var img = document.createElement('img');
        img.src = _imgSrc || svgPlaceholder(el.width, el.height, 'Image');
        img.alt = '';
        block.appendChild(img);
        applyImageFit(block, _fit);
    } else if (el.type === 'video') {
        if (content) block.dataset.manualPath = content;
        var vid = document.createElement('video');
        vid.autoplay = true; vid.loop = true; vid.muted = true; vid.playsInline = true;
        if (content) { var src = document.createElement('source'); src.src = content; vid.appendChild(src); }
        block.appendChild(vid);
    } else if (el.type === 'carousel') {
        var cdata = {};
        try { cdata = JSON.parse(content || '{}'); } catch(e) {}
        block.dataset.carouselData = JSON.stringify(cdata);
        buildCarouselPreview(block, cdata);
    } else if (el.type === 'table') {
        var tdata = {};
        try { tdata = JSON.parse(content || '{}'); } catch(e) {}
        block.dataset.tableData = JSON.stringify(tdata);
        buildTablePreview(block, tdata);
    } else if (el.type === 'marquee') {
        var mdata = {};
        try { mdata = JSON.parse(content || '{}'); } catch(e) {}
        block.dataset.marqueeData = JSON.stringify(mdata);
        buildMarqueePreview(block, mdata);
    }

    if (el.locked) appendLockIcon(block);

    block.addEventListener('mousedown', function(e) {
        if (e.target.closest('.rh')) return; // resize handles handled by interact.js
        if (e.shiftKey) {
            e.preventDefault(); // prevent browser focus/text-selection changes during multi-select
            toggleMultiSel(block);
        } else {
            selectBlock(block);
        }
    });

    addResizeHandles(block);
    parent.appendChild(block);
}

// ============================================================
// APPLY TEXT STYLES
// ============================================================
function applyTextStyles(block, el) {
    var sub = el.block_subtype || 'free';
    if (sub !== 'free' && blockStyles[sub]) {
        var bs = blockStyles[sub];
        block.style.fontFamily  = bs.font_family;
        block.style.fontSize    = bs.font_size + 'px';
        block.style.color       = bs.font_color;
        block.style.fontWeight  = bs.font_weight;
        block.style.fontStyle   = bs.font_style;
        block.style.lineHeight  = bs.line_height;
    } else {
        block.style.fontFamily  = el.font_family  || 'Arial';
        block.style.fontSize    = (el.font_size||16) + 'px';
        block.style.color       = el.font_color   || '#000000';
        block.style.fontWeight  = el.font_weight  || 'normal';
        block.style.fontStyle   = el.font_style   || 'normal';
        block.style.lineHeight  = el.line_height  || 1.4;
    }
}

// ============================================================
// SELECTION (single)
// ============================================================
function selectBlock(block) {
    clearMultiSel();
    deselectAll();
    activeBlock = block;
    block.classList.add('selected');
    showInspector(block);
}

function deselectAll() {
    if (activeBlock) {
        activeBlock.classList.remove('selected');
        var _ti = activeBlock.querySelector('.text-inner');
        if (_ti) { _ti.style.pointerEvents = 'none'; _ti.blur(); }
    }
    activeBlock = null;
    document.getElementById('inspector').style.display = 'none';
    if (multiSel.length === 0) document.getElementById('align-bar').style.display = 'none';
}

function showInspector(block) {
    updateAlignBar(); // keep screen-align bar visible while a block is selected
    var insp = document.getElementById('inspector');
    var type    = block.dataset.type;
    var subtype = block.dataset.subtype || 'free';
    var isSection = type === 'section';

    insp.style.display = 'flex';
    document.getElementById('insp-x').value = Math.round(parseFloat(block.getAttribute('data-x')) || 0);
    document.getElementById('insp-y').value = Math.round(parseFloat(block.getAttribute('data-y')) || 0);
    document.getElementById('insp-w').value = Math.round(block.offsetWidth);
    document.getElementById('insp-h').value = Math.round(block.offsetHeight);
    document.getElementById('insp-title').textContent =
        isSection ? 'Section' :
        subtype !== 'free' ? (TYPE_LABELS[subtype]||subtype) :
        type.charAt(0).toUpperCase()+type.slice(1)+' Block';

    // Section-only controls
    document.getElementById('insp-section').style.display = (isSection && IS_ADMIN) ? 'block' : 'none';
    if (isSection && IS_ADMIN) {
        var bg = block.dataset.sectionBg || '';
        document.getElementById('section-bg-preview').textContent = bg || 'No background set';
        document.getElementById('section-bg-fit').value = block.dataset.bgFit || 'cover';
    }

    // Font controls – admin + free text only
    document.getElementById('insp-font').style.display = (IS_ADMIN && type==='text' && subtype==='free') ? 'block' : 'none';
    if (IS_ADMIN && type==='text' && subtype==='free') {
        document.getElementById('font-family').value  = block.style.fontFamily.replace(/['"]/g,'') || 'Arial';
        document.getElementById('font-size').value    = parseInt(block.style.fontSize) || 16;
        document.getElementById('font-color').value   = rgbToHex(block.style.color) || '#000000';
        document.getElementById('font-weight').value  = block.style.fontWeight || 'normal';
        document.getElementById('line-height').value  = parseFloat(block.style.lineHeight) || 1.4;
    }

    // Brand lock badge – typed text blocks
    var showBrand = type==='text' && subtype!=='free';
    document.getElementById('insp-brand-lock').style.display = showBrand ? 'block' : 'none';
    if (showBrand) document.getElementById('insp-brand-name').textContent = TYPE_LABELS[subtype] || subtype;

    // Text align – all text blocks
    var showTextAlign = (type === 'text');
    document.getElementById('insp-text-align').style.display = showTextAlign ? 'block' : 'none';
    if (showTextAlign) {
        var _ta = block.style.textAlign || 'left';
        ['left','center','right','justify'].forEach(function(a) {
            var btn = document.getElementById('ta-' + a);
            if (btn) btn.style.background = (a === _ta) ? '#3498db' : '';
        });
    }

    // Image upload + fit – all users, image blocks
    document.getElementById('insp-image').style.display = (type==='image' && !isSection) ? 'block' : 'none';
    if (type === 'image') {
        document.getElementById('img-fit').value = block.dataset.imgFit || 'fill';
    }

    // Video upload – admin only
    document.getElementById('insp-video').style.display = (IS_ADMIN && type==='video') ? 'block' : 'none';

    // Carousel inspector
    document.getElementById('insp-carousel').style.display = (type==='carousel') ? 'block' : 'none';
    if (type === 'carousel') {
        var cd = {};
        try { cd = JSON.parse(block.dataset.carouselData || '{}'); } catch(e) {}
        document.getElementById('carousel-interval').value = ((cd.interval || 5000) / 1000);
        var sl = (cd.slides || []).length;
        document.getElementById('carousel-slide-count').textContent =
            sl + ' slide' + (sl !== 1 ? 's' : '') + ' — click Edit Slides to manage';
    }

    // Table inspector
    document.getElementById('insp-table').style.display = (type==='table') ? 'block' : 'none';
    if (type === 'table') {
        var tdinsp = {};
        try { tdinsp = JSON.parse(block.dataset.tableData || '{}'); } catch(e) {}
        var tcols = (tdinsp.headers || []).length;
        var trows = (tdinsp.rows    || []).length;
        document.getElementById('table-info').textContent =
            tcols + ' col' + (tcols !== 1 ? 's' : '') + ', ' + trows + ' row' + (trows !== 1 ? 's' : '');
    }

    // Marquee inspector
    document.getElementById('insp-marquee').style.display = (type==='marquee') ? 'block' : 'none';
    if (type === 'marquee') {
        var md = {};
        try { md = JSON.parse(block.dataset.marqueeData || '{}'); } catch(e) {}
        document.getElementById('marquee-text').value          = md.text   || '';
        document.getElementById('marquee-speed').value         = md.speed  || 80;
        document.getElementById('marquee-speed-label').textContent = (md.speed || 80) + ' px/sec';
        document.getElementById('marquee-color').value         = md.color  || '#ffffff';
        document.getElementById('marquee-size').value          = md.size   || 28;
        document.getElementById('marquee-weight').value        = md.weight || 'bold';
        var isTrans = (md.bg === 'transparent');
        document.getElementById('marquee-bg-transparent').checked = isTrans;
        document.getElementById('marquee-bg').value            = isTrans ? '#c0392b' : (md.bg || '#c0392b');
        document.getElementById('marquee-bg').disabled         = isTrans;
    }

    // Asset link – non-section, non-carousel, non-marquee, non-table
    var hideAsset = isSection || type === 'carousel' || type === 'marquee' || type === 'table';
    document.getElementById('insp-asset').style.display = hideAsset ? 'none' : 'block';
    if (!hideAsset) populateAssetLinkOptions(type);
    document.getElementById('asset-link').value = block.dataset.assetId || '';

    // Z-index / layer order
    document.getElementById('insp-zindex-val').textContent = parseInt(block.dataset.zIndex) || 1;

    // Lock toggle
    document.getElementById('lock-toggle').checked = block.dataset.locked === '1';
}

// ============================================================
// MULTI-SELECT
// ============================================================
function toggleMultiSel(block) {
    // Determine the anchor scope (parent of the first element in the group)
    var anchorParent = multiSel.length > 0 ? multiSel[0].parentElement
                     : activeBlock          ? activeBlock.parentElement
                     : null;

    // Enforce same-scope: block being added must share the same parent container
    if (anchorParent && block.parentElement !== anchorParent && multiSel.indexOf(block) < 0) {
        showToast('Multi-select is limited to elements within the same parent.', true);
        return;
    }

    // Absorb the single-selected block into multiSel before toggling the new one
    if (activeBlock && multiSel.indexOf(activeBlock) < 0) {
        activeBlock.classList.remove('selected');
        activeBlock.classList.add('multi-sel');
        multiSel.push(activeBlock);
    }
    activeBlock = null;
    document.getElementById('inspector').style.display = 'none';

    var idx = multiSel.indexOf(block);
    if (idx >= 0) {
        block.classList.remove('multi-sel');
        multiSel.splice(idx, 1);
    } else {
        block.classList.add('multi-sel');
        multiSel.push(block);
    }
    updateAlignBar();
}

function clearMultiSel() {
    multiSel.forEach(function(b){ b.classList.remove('multi-sel'); });
    multiSel = [];
    updateAlignBar();
}

function updateAlignBar() {
    var bar  = document.getElementById('align-bar');
    var cnt  = document.getElementById('sel-count');
    var total = multiSel.length + (activeBlock ? 1 : 0);
    if (total > 0) {
        bar.style.display = 'flex';
        if (multiSel.length >= 2) {
            cnt.textContent = multiSel.length + ' blocks — aligning to each other';
        } else {
            cnt.textContent = '1 block — aligning to parent';
        }
    } else {
        bar.style.display = 'none';
    }
}

// ============================================================
// ALIGNMENT
// ============================================================

// Returns the parent container's usable dimensions for a block
function _parentContainer(block) {
    var canvas = document.getElementById('builder-canvas');
    var p = block.parentElement;
    return {
        el: p,
        w: (p === canvas) ? 1920 : p.offsetWidth,
        h: (p === canvas) ? 1080 : p.offsetHeight
    };
}

// Move block and hard-clamp to its parent bounds (no element can exceed parent)
function moveBlock(block, nx, ny) {
    var pc = _parentContainer(block);
    nx = Math.max(0, Math.min(nx, Math.max(0, pc.w - block.offsetWidth)));
    ny = Math.max(0, Math.min(ny, Math.max(0, pc.h - block.offsetHeight)));
    block.style.transform = 'translate(' + nx + 'px,' + ny + 'px)';
    block.setAttribute('data-x', nx);
    block.setAttribute('data-y', ny);
}

function alignBlocks(direction) {
    var targets = multiSel.length > 0 ? multiSel.slice() : (activeBlock ? [activeBlock] : []);
    if (targets.length === 0) return;

    if (targets.length === 1) {
        // Single element: align to its parent container
        var block = targets[0];
        var pc = _parentContainer(block);
        var x  = parseFloat(block.getAttribute('data-x')) || 0;
        var y  = parseFloat(block.getAttribute('data-y')) || 0;
        var w  = block.offsetWidth;
        var h  = block.offsetHeight;
        if      (direction === 'left')     x = 0;
        else if (direction === 'right')    x = pc.w - w;
        else if (direction === 'top')      y = 0;
        else if (direction === 'bottom')   y = pc.h - h;
        else if (direction === 'center-h') x = (pc.w - w) / 2;
        else if (direction === 'center-v') y = (pc.h - h) / 2;
        moveBlock(block, x, y);
    } else {
        // Multi-select: align relative to each other (scope already enforced at selection time)
        var bounds = targets.map(function(b) {
            return {
                el: b,
                x: parseFloat(b.getAttribute('data-x')) || 0,
                y: parseFloat(b.getAttribute('data-y')) || 0,
                w: b.offsetWidth,
                h: b.offsetHeight
            };
        });
        var minX = Math.min.apply(null, bounds.map(function(b){ return b.x; }));
        var minY = Math.min.apply(null, bounds.map(function(b){ return b.y; }));
        var maxR = Math.max.apply(null, bounds.map(function(b){ return b.x + b.w; }));
        var maxB = Math.max.apply(null, bounds.map(function(b){ return b.y + b.h; }));
        var ctrX = minX + (maxR - minX) / 2;
        var ctrY = minY + (maxB - minY) / 2;
        bounds.forEach(function(b) {
            var nx = b.x, ny = b.y;
            if      (direction === 'left')     nx = minX;
            else if (direction === 'right')    nx = maxR - b.w;
            else if (direction === 'top')      ny = minY;
            else if (direction === 'bottom')   ny = maxB - b.h;
            else if (direction === 'center-h') nx = ctrX - b.w / 2;
            else if (direction === 'center-v') ny = ctrY - b.h / 2;
            moveBlock(b.el, nx, ny);
        });
    }

    // Sync inspector if activeBlock is in the target set
    if (activeBlock && targets.indexOf(activeBlock) >= 0) {
        document.getElementById('insp-x').value = Math.round(parseFloat(activeBlock.getAttribute('data-x')) || 0);
        document.getElementById('insp-y').value = Math.round(parseFloat(activeBlock.getAttribute('data-y')) || 0);
    }
}

// ============================================================
// LOCK / UNLOCK
// ============================================================
// ============================================================
// LAYER / Z-INDEX
// ============================================================
function _setZIndex(val) {
    if (!activeBlock) return;
    val = Math.max(1, parseInt(val) || 1); // min = 1; 0 is background
    activeBlock.style.zIndex   = val;
    activeBlock.dataset.zIndex = val;
    document.getElementById('insp-zindex-val').textContent = val;
}
function _siblingZValues() {
    if (!activeBlock) return [];
    return Array.from(activeBlock.parentElement.children)
        .filter(function(el) { return el !== activeBlock && el.classList.contains('editable-block'); })
        .map(function(el) { return parseInt(el.style.zIndex) || 1; });
}
function bringToFront() {
    var maxZ = Math.max.apply(null, _siblingZValues().concat([1]));
    _setZIndex(maxZ + 1);
}
function bringForward() {
    _setZIndex((parseInt(activeBlock && activeBlock.dataset.zIndex) || 1) + 1);
}
function sendBackward() {
    _setZIndex(Math.max(1, (parseInt(activeBlock && activeBlock.dataset.zIndex) || 1) - 1));
}
function sendToBack() {
    _setZIndex(1); // 1 is the minimum; background is 0
}

function toggleLock(locked) {
    if (!activeBlock) return;
    activeBlock.dataset.locked = locked ? '1' : '0';
    if (locked) {
        activeBlock.classList.add('locked-block');
        activeBlock.classList.remove('draggable-block');
        appendLockIcon(activeBlock);
    } else {
        activeBlock.classList.remove('locked-block');
        var isChild = activeBlock.classList.contains('child-block');
        if (IS_ADMIN || isChild) activeBlock.classList.add('draggable-block');
        var li = activeBlock.querySelector('.lock-icon');
        if (li) li.remove();
    }
}

function appendLockIcon(el) {
    if (!el.querySelector('.lock-icon')) {
        var li = document.createElement('span');
        li.className = 'lock-icon'; li.textContent = '🔒';
        el.appendChild(li);
    }
}

// ============================================================
// SECTION TARGET (for adding children)
// ============================================================
function setTargetSection(sectionEl) {
    if (targetSection) targetSection.classList.remove('targeted');
    targetSection = sectionEl;
    if (targetSection) {
        targetSection.classList.add('targeted');
        if (!IS_ADMIN) {
            document.getElementById('section-banner').textContent =
                'Section selected — now add a block from the bar above.';
        }
    }
}

function clearTargetSection() {
    if (targetSection) targetSection.classList.remove('targeted');
    targetSection = null;
    if (!IS_ADMIN) {
        document.getElementById('section-banner').textContent =
            'Click on a section (purple border) to target it, then add your blocks.';
    }
}

// ============================================================
// CANVAS CLICK HANDLER
// ============================================================
function setupCanvas() {
    document.getElementById('editor-frame').addEventListener('mousedown', function(e) {
        if (e.target.closest('.editable-block')) return;
        if (!e.shiftKey) { deselectAll(); clearMultiSel(); }
        clearTargetSection();
    });
    document.addEventListener('selectionchange', trackSelection);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Shift') _shiftDown = true;
        if (e.key === 'Delete') {
            var ae = document.activeElement;
            if (ae && (ae.classList.contains('text-inner') || ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.tagName === 'SELECT')) return;
            if (activeBlock) {
                var msg = activeBlock.dataset.type === 'section'
                    ? 'Delete this section and ALL blocks inside it?'
                    : 'Delete this block?';
                if (confirm(msg)) {
                    activeBlock.remove();
                    deselectAll();
                }
            }
        }
    });
    document.addEventListener('keyup',   function(e) { if (e.key === 'Shift') _shiftDown = false; });
}

// ============================================================
// WYSIWYG
// ============================================================
function trackSelection() {
    if (!activeBlock || activeBlock.dataset.type !== 'text' || activeBlock.dataset.subtype !== 'free') return;
    var sel = window.getSelection();
    if (!sel || sel.rangeCount === 0) return;
    try {
        var inner = activeBlock.querySelector('.text-inner');
        if (inner && inner.contains(sel.getRangeAt(0).commonAncestorContainer)) {
            savedRange = sel.getRangeAt(0).cloneRange();
        }
    } catch(e) {}
}

function fmtCmd(evt, cmd) {
    evt.preventDefault();
    if (savedRange) {
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(savedRange);
    }
    document.execCommand(cmd, false, null);
    if (activeBlock) {
        var _ti = activeBlock.querySelector('.text-inner');
        if (_ti) _ti.focus();
    }
}

// ============================================================
// STYLE UPDATES
// ============================================================
function updateStyle(prop, val) {
    if (!activeBlock) return;
    activeBlock.style[prop] = val;
}

// ============================================================
// SECTION BACKGROUND
// ============================================================
function applySectionBgFit(block, fit) {
    if (fit === 'contain') {
        block.style.backgroundSize     = 'contain';
        block.style.backgroundRepeat   = 'no-repeat';
        block.style.backgroundPosition = 'center';
    } else if (fit === 'fill') {
        block.style.backgroundSize     = '100% 100%';
        block.style.backgroundRepeat   = 'no-repeat';
        block.style.backgroundPosition = 'center';
    } else if (fit === 'tile') {
        block.style.backgroundSize     = 'auto';
        block.style.backgroundRepeat   = 'repeat';
        block.style.backgroundPosition = 'top left';
    } else if (fit === 'center') {
        block.style.backgroundSize     = 'auto';
        block.style.backgroundRepeat   = 'no-repeat';
        block.style.backgroundPosition = 'center';
    } else { // cover (default)
        block.style.backgroundSize     = 'cover';
        block.style.backgroundRepeat   = 'no-repeat';
        block.style.backgroundPosition = 'center';
    }
}

function changeSectionBgFit(fit) {
    if (!IS_ADMIN || !activeBlock || activeBlock.dataset.type !== 'section') return;
    activeBlock.dataset.bgFit = fit;
    applySectionBgFit(activeBlock, fit);
}

function uploadSectionBg(input) {
    if (!IS_ADMIN || !activeBlock || !input.files[0]) return;
    var fd = new FormData();
    fd.append('file', input.files[0]);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=upload_file', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.status==='success') {
                var _fit = (document.getElementById('section-bg-fit') || {}).value || 'cover';
                activeBlock.style.backgroundImage = "url('"+res.path+"')";
                activeBlock.dataset.sectionBg = res.path;
                activeBlock.dataset.bgFit = _fit;
                applySectionBgFit(activeBlock, _fit);
                document.getElementById('section-bg-preview').textContent = res.path;
            } else { showToast(res.message||'Upload failed.', true); }
        });
}

function clearSectionBg() {
    if (!IS_ADMIN || !activeBlock) return;
    activeBlock.style.backgroundImage = 'none';
    activeBlock.dataset.sectionBg = '';
    activeBlock.dataset.bgFit = 'cover';
    document.getElementById('section-bg-preview').textContent = 'No background set';
    var fitSel = document.getElementById('section-bg-fit');
    if (fitSel) fitSel.value = 'cover';
}

// ============================================================
// IMAGE / VIDEO UPLOADS
// ============================================================
function uploadBlockImage(input) {
    if (!input.files[0] || !activeBlock) return;
    var fd = new FormData();
    fd.append('file', input.files[0]);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=upload_file', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.status==='success') {
                var _img = activeBlock.querySelector('img');
                if (_img) _img.src = res.path;
                activeBlock.dataset.manualPath = res.path;
                activeBlock.dataset.assetId    = '';
                document.getElementById('asset-link').value = '';
            } else { showToast(res.message||'Upload failed.', true); }
        });
}

function uploadBlockVideo(input) {
    if (!IS_ADMIN || !input.files[0] || !activeBlock) return;
    showToast('Uploading video…');
    var fd = new FormData();
    fd.append('file', input.files[0]);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=upload_video', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.status==='success') {
                var vid = activeBlock.querySelector('video');
                if (!vid) { showToast('Video element not found.', true); return; }
                vid.innerHTML = '';
                var src = document.createElement('source');
                src.src = res.path; vid.appendChild(src); vid.load();
                activeBlock.dataset.manualPath = res.path;
                activeBlock.dataset.assetId    = '';
                document.getElementById('asset-link').value = '';
                showToast('Video uploaded.');
            } else { showToast(res.message||'Upload failed.', true); }
        });
}

// ============================================================
// ASSET LINK
// ============================================================
function linkAsset(assetId) {
    if (!activeBlock) return;
    activeBlock.dataset.assetId = assetId;
    if (!assetId) return;
    var match = assetsCache.find(function(a){ return a.id == assetId; });
    if (!match) return;
    if (activeBlock.dataset.type === 'text') {
        activeBlock.querySelector('.text-inner').textContent = match.content;
    } else if (activeBlock.dataset.type === 'image') {
        activeBlock.querySelector('img').src = match.content;
        activeBlock.dataset.imgSrc = match.content;
    } else if (activeBlock.dataset.type === 'video') {
        var vid = activeBlock.querySelector('video');
        vid.innerHTML = '';
        var src = document.createElement('source');
        src.src = match.content; vid.appendChild(src); vid.load();
    }
}

// ============================================================
// DELETE
// ============================================================
function deleteSelected() {
    if (activeBlock) {
        if (activeBlock.dataset.type === 'section') {
            if (!confirm('Delete this section and ALL blocks inside it?')) return;
        }
        activeBlock.remove();
        deselectAll();
    }
}

// ============================================================
// PUBLISH
// ============================================================
function publishCanvas() {
    var canvas   = document.getElementById('builder-canvas');
    var elements = [];

    // Collect sections (admin only publishes section data)
    canvas.querySelectorAll(':scope > .section-block').forEach(function(s) {
        var _sbPath = s.dataset.sectionBg || '';
        var _sbFit  = s.dataset.bgFit     || 'cover';
        elements.push({
            type:       'section',
            temp_id:    s.dataset.tempId,
            db_id:      s.dataset.dbId  || null,
            x_pos:      Math.round(parseFloat(s.getAttribute('data-x'))||0),
            y_pos:      Math.round(parseFloat(s.getAttribute('data-y'))||0),
            width:      Math.round(s.offsetWidth),
            height:     Math.round(s.offsetHeight),
            section_bg: _sbPath ? (_sbPath + '|' + _sbFit) : null,
            locked:     s.dataset.locked === '1' ? 1 : 0,
            sort_order: 0,
            z_index:    Math.max(1, parseInt(s.dataset.zIndex) || 1),
            hidden:     s.dataset.hidden === '1' ? 1 : 0,
        });
    });

    // Collect all non-section blocks
    canvas.querySelectorAll('.editable-block:not(.section-block)').forEach(function(block, i) {
        var type    = block.dataset.type;
        var subtype = block.dataset.subtype || 'free';
        var assetId = block.dataset.assetId || '';
        var sectionEl = block.closest('.section-block');
        var manual  = '';
        var savePool = false;

        if (!assetId) {
            if (type === 'text') {
                var _inner = block.querySelector('.text-inner');
                // Plain text only — innerText yields visible text with line
                // breaks; the server strips any markup on save as well.
                manual   = _inner ? _inner.innerText : '';
                savePool = true;
            } else if (type === 'carousel') {
                manual   = block.dataset.carouselData || '{}';
                savePool = false;
            } else if (type === 'table') {
                manual   = block.dataset.tableData || '{}';
                savePool = false;
            } else if (type === 'marquee') {
                manual   = block.dataset.marqueeData || '{}';
                savePool = false;
            } else if (type === 'image') {
                var _src = block.dataset.manualPath || block.dataset.imgSrc || '';
                var _fit = block.dataset.imgFit || 'fill';
                manual   = _fit !== 'fill' ? _src + '|' + _fit : _src;
                savePool = !!block.dataset.manualPath;
            } else {
                manual   = block.dataset.manualPath || (block.querySelector('video source') || {}).src || '';
                savePool = !!block.dataset.manualPath;
            }
        }

        elements.push({
            type:           type,
            block_subtype:  subtype,
            parent_temp_id: sectionEl ? sectionEl.dataset.tempId : null,
            x_pos:          Math.round(parseFloat(block.getAttribute('data-x'))||0),
            y_pos:          Math.round(parseFloat(block.getAttribute('data-y'))||0),
            width:          Math.round(block.offsetWidth),
            height:         Math.round(block.offsetHeight),
            asset_id:       assetId,
            manual_content: manual,
            save_to_db_pool: savePool,
            font_family:    block.style.fontFamily  || 'Arial',
            font_size:      parseInt(block.style.fontSize) || 16,
            font_color:     rgbToHex(block.style.color) || '#000000',
            font_weight:    block.style.fontWeight  || 'normal',
            font_style:     block.style.fontStyle   || 'normal',
            line_height:    parseFloat(block.style.lineHeight) || 1.4,
            text_align:     block.dataset.textAlign || block.style.textAlign || '',
            locked:         block.dataset.locked === '1' ? 1 : 0,
            sort_order:     i,
            z_index:        Math.max(1, parseInt(block.dataset.zIndex) || 1),
            hidden:         block.dataset.hidden === '1' ? 1 : 0,
        });
    });

    var fd = new FormData();
    fd.append('layout_data', JSON.stringify(elements));
    fd.append('csrf_token', CSRF_TOKEN);

    if (IS_ADMIN) {
        fd.append('bg_type', document.getElementById('bg-type').value);
        fd.append('bg_val',  document.getElementById('bg-color').value);
        var bgFile = document.getElementById('bg-file').files[0];
        if (bgFile) fd.append('bg_file', bgFile);
    }

    fetch('api.php?action=publish', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.status === 'success') {
                showToast('Published! Display screen will update in 30 seconds.');
                loadAssets();
            } else { showToast(res.message||'Publish failed.', true); }
        })
        .catch(function(){ showToast('Network error.', true); });
}

// ============================================================
// INTERACT.JS – drag, resize, bounds
// ============================================================
function setupInteract() {
    var canvas = document.getElementById('builder-canvas');

    // Handle-based resize edges (corners + sides)
    var EDGES = {
        top:    '.rh-nw, .rh-n, .rh-ne',
        right:  '.rh-ne, .rh-e, .rh-se',
        bottom: '.rh-se, .rh-s, .rh-sw',
        left:   '.rh-sw, .rh-w, .rh-nw',
    };

    if (IS_ADMIN) {
        // Sections: drag + resize, constrained to canvas
        interact('.section-block').draggable({
            listeners: { start: function(e) { if (_shiftDown) e.interaction.stop(); }, move: handleMove },
            modifiers: [interact.modifiers.restrictRect({restriction: canvas})],
            ignoreFrom: '.child-block',  // let child blocks handle their own drag
        }).resizable({
            edges: EDGES,
            listeners: { move: handleResize, end: hideResizeLabel },
            modifiers: [interact.modifiers.restrictSize({min:{width:100,height:60}})]
        });

        // Root blocks: drag + resize, constrained to canvas
        interact('.root-block').draggable({
            listeners: { start: function(e) { if (_shiftDown) e.interaction.stop(); }, move: handleMove },
            modifiers: [interact.modifiers.restrictRect({restriction: canvas})]
        }).resizable({
            edges: EDGES,
            listeners: { move: handleResize, end: hideResizeLabel },
        });
    }

    // Child blocks: drag constrained to parent section; resize for all users
    interact('.child-block').draggable({
        listeners: {
            start: function(e) { if (_shiftDown) e.interaction.stop(); },
            move: function(event) {
                if (event.target.dataset.locked === '1') return;
                handleMove(event);
            }
        },
        modifiers: [interact.modifiers.restrictRect({restriction: 'parent', endOnly: false})]
    }).resizable({
        edges: EDGES,
        listeners: { move: handleResize, end: hideResizeLabel },
        modifiers: [interact.modifiers.restrictRect({restriction: 'parent'})]
    });
}

function handleMove(event) {
    var t = event.target;
    if (t && t.classList) t.classList.remove('just-added');  // first move clears the new-block highlight
    if (t.dataset.locked === '1') return;
    var x = (parseFloat(t.getAttribute('data-x'))||0) + event.dx;
    var y = (parseFloat(t.getAttribute('data-y'))||0) + event.dy;
    t.style.transform = 'translate('+x+'px,'+y+'px)';
    t.setAttribute('data-x', x);
    t.setAttribute('data-y', y);
    if (t === activeBlock) {
        document.getElementById('insp-x').value = Math.round(x);
        document.getElementById('insp-y').value = Math.round(y);
    }
}

function handleResize(event) {
    var t = event.target;
    if (t.dataset.locked === '1') return;
    var x = (parseFloat(t.getAttribute('data-x'))||0) + event.deltaRect.left;
    var y = (parseFloat(t.getAttribute('data-y'))||0) + event.deltaRect.top;
    t.style.width  = event.rect.width  + 'px';
    t.style.height = event.rect.height + 'px';
    t.style.transform = 'translate('+x+'px,'+y+'px)';
    t.setAttribute('data-x', x);
    t.setAttribute('data-y', y);

    var w = Math.round(event.rect.width);
    var h = Math.round(event.rect.height);
    var lbl = document.getElementById('resize-label');
    lbl.textContent = w + ' × ' + h + ' px';
    var r = t.getBoundingClientRect();
    lbl.style.left = (r.left + r.width  / 2) + 'px';
    lbl.style.top  = (r.top  + r.height / 2) + 'px';
    lbl.style.display = 'block';
    document.getElementById('insp-w').value = w;
    document.getElementById('insp-h').value = h;
}

function hideResizeLabel() {
    document.getElementById('resize-label').style.display = 'none';
}

// ============================================================
// UTILITIES
// ============================================================
function _parentBounds() {
    // Returns {w, h} of the parent container (section or canvas)
    var p = activeBlock && activeBlock.parentElement;
    if (!p) return { w: 1920, h: 1080 };
    return { w: p.offsetWidth, h: p.offsetHeight };
}

function applyDim(which, val) {
    if (!activeBlock) return;
    val = parseInt(val) || 0;
    var pb = _parentBounds();
    if (which === 'w') {
        val = Math.max(40, Math.min(val, pb.w));
        activeBlock.style.width = val + 'px';
        document.getElementById('insp-w').value = val;
    } else {
        val = Math.max(24, Math.min(val, pb.h));
        activeBlock.style.height = val + 'px';
        document.getElementById('insp-h').value = val;
    }
}

function applyPos(which, val) {
    if (!activeBlock) return;
    val = parseInt(val) || 0;
    var pb  = _parentBounds();
    var bw  = activeBlock.offsetWidth;
    var bh  = activeBlock.offsetHeight;
    var x   = parseFloat(activeBlock.getAttribute('data-x')) || 0;
    var y   = parseFloat(activeBlock.getAttribute('data-y')) || 0;
    // Clamp to parent bounds for child blocks; canvas bounds for root/section blocks
    var isChild = activeBlock.classList.contains('child-block');
    if (which === 'x') x = isChild ? Math.max(0, Math.min(val, pb.w - bw)) : Math.max(0, Math.min(val, 1920 - bw));
    else               y = isChild ? Math.max(0, Math.min(val, pb.h - bh)) : Math.max(0, Math.min(val, 1080 - bh));
    activeBlock.style.transform = 'translate('+x+'px,'+y+'px)';
    activeBlock.setAttribute('data-x', x);
    activeBlock.setAttribute('data-y', y);
    document.getElementById('insp-x').value = Math.round(x);
    document.getElementById('insp-y').value = Math.round(y);
}

function applyImageFit(block, fit) {
    var img = block.querySelector('img');
    if (!img) return;
    img.style.width = '100%';
    img.style.height = '100%';
    img.style.objectFit = 'fill';
    block.style.overflow = '';
    if (fit === 'contain') {
        img.style.objectFit = 'contain';
    } else if (fit === 'cover') {
        img.style.objectFit = 'cover';
    } else if (fit === 'fit-w') {
        img.style.height = 'auto';
        img.style.objectFit = '';
        block.style.overflow = 'hidden';
    } else if (fit === 'fit-h') {
        img.style.width = 'auto';
        img.style.objectFit = '';
        block.style.overflow = 'hidden';
    }
}

function changeImageFit(fit) {
    if (!activeBlock || activeBlock.dataset.type !== 'image') return;
    // Break asset-pool link so the fit mode gets saved alongside the path
    if (activeBlock.dataset.assetId) {
        var _s = activeBlock.dataset.imgSrc || '';
        if (_s) activeBlock.dataset.manualPath = _s;
        activeBlock.dataset.assetId = '';
        document.getElementById('asset-link').value = '';
    }
    activeBlock.dataset.imgFit = fit;
    applyImageFit(activeBlock, fit);
}

function tmpId() { return 'tmp-' + Math.random().toString(36).substr(2,9); }

function getCanvasDropCenter(defW, defH, parent) {
    var frame  = document.getElementById('editor-frame');
    var canvas = document.getElementById('builder-canvas');
    var PAD    = 40;
    if (parent && parent.classList && parent.classList.contains('section-block')) {
        var sw = parent.offsetWidth;
        var sh = parent.offsetHeight;
        return {
            x: Math.max(0, Math.round((sw - defW) / 2)),
            y: Math.max(0, Math.round((sh - defH) / 2))
        };
    }
    var cx = Math.round(frame.scrollLeft + frame.clientWidth  / 2 - PAD - defW / 2);
    var cy = Math.round(frame.scrollTop  + frame.clientHeight / 2 - PAD - defH / 2);
    cx = Math.max(0, Math.min(cx, canvas.offsetWidth  - defW));
    cy = Math.max(0, Math.min(cy, canvas.offsetHeight - defH));
    return { x: cx, y: cy };
}

function rgbToHex(rgb) {
    if (!rgb || rgb.startsWith('#')) return rgb||'#000000';
    var m = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
    if (!m) return '#000000';
    return '#'+[m[1],m[2],m[3]].map(function(n){return ('0'+parseInt(n,10).toString(16)).slice(-2);}).join('');
}

function escHtml(s) {
    return (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function svgPlaceholder(w, h, label) {
    return 'data:image/svg+xml,'+encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="'+w+'" height="'+h+'">' +
        '<rect width="'+w+'" height="'+h+'" fill="#dde3ea"/>' +
        '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" ' +
        'font-family="Arial" font-size="14" fill="#7f8c8d">'+label+'</text></svg>'
    );
}

function showToast(msg, isErr) {
    var t = document.getElementById('toast');
    t.textContent   = msg;
    t.className     = isErr ? 'err' : '';
    t.style.display = 'block';
    clearTimeout(t._tid);
    t._tid = setTimeout(function(){ t.style.display='none'; }, 3500);
}

// ============================================================
// CAROUSEL PREVIEW + MODAL
// ============================================================
function buildCarouselPreview(block, data) {
    Array.from(block.children).forEach(function(child) {
        if (!child.classList.contains('rh') && !child.classList.contains('lock-icon') && !child.classList.contains('hidden-badge')) child.remove();
    });
    var slides   = (data && data.slides) || [];
    var preview  = document.createElement('div');
    preview.className = 'carousel-preview';
    if (slides.length > 0 && slides[0].image) {
        var img = document.createElement('img');
        img.src = slides[0].image;
        preview.appendChild(img);
    }
    var lbl = document.createElement('div');
    lbl.className = 'carousel-preview-lbl';
    lbl.textContent = '↻ Carousel — ' + slides.length + ' slide' + (slides.length !== 1 ? 's' : '');
    preview.appendChild(lbl);
    block.appendChild(preview);
}

function openCarouselModal() {
    if (!activeBlock || activeBlock.dataset.type !== 'carousel') return;
    var cd = {};
    try { cd = JSON.parse(activeBlock.dataset.carouselData || '{}'); } catch(e) {}
    var list = document.getElementById('carousel-slides-list');
    list.innerHTML = '';
    var slides = cd.slides || [];
    if (slides.length === 0) { addSlideRow(); } else { slides.forEach(function(s){ addSlideRow(s); }); }
    document.getElementById('carousel-modal-overlay').classList.add('open');
}

function closeCarouselModal() {
    document.getElementById('carousel-modal-overlay').classList.remove('open');
}

function addSlideRow(data) {
    data = data || {};
    var list = document.getElementById('carousel-slides-list');
    var n    = list.children.length + 1;
    var div  = document.createElement('div');
    div.className = 'slide-row';

    var titleDel = data.title === null;
    var priceDel = data.price === null;
    var descDel  = data.description === null;
    var titleVal = titleDel ? '' : escHtml(data.title || '');
    var priceVal = priceDel ? '' : escHtml(data.price || '');
    var descVal  = descDel  ? '' : escHtml(data.description || '');
    var textPos   = data.textPosition || 'right';
    var imgFit    = data.imageFit    || 'contain';
    var imageOnly = data.imageOnly   || false;

    var imgHtml = data.image
        ? '<img src="'+escHtml(data.image)+'" style="max-width:100%;max-height:60px;object-fit:contain;">'
        : 'No image';

    div.innerHTML =
        '<div class="slide-header">Slide ' + n +
            ' <button class="btn danger" style="font-size:11px;padding:3px 8px;" onclick="removeSlideRow(this)">Remove Slide</button>' +
        '</div>' +
        '<div class="slide-fields">' +
            '<div class="slide-field" style="grid-column:1/-1;">' +
                '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:#bdc3c7;">' +
                    '<input type="checkbox" class="slide-img-only" onchange="toggleSlideImgOnly(this)"' + (imageOnly ? ' checked' : '') + '>' +
                    'Image Only — fills entire slide, no text' +
                '</label>' +
            '</div>' +
            '<div class="slide-field">' +
                '<label>Image</label>' +
                '<div class="slide-img-preview">' + imgHtml + '</div>' +
                '<input type="file" accept="image/*" onchange="uploadSlideImage(this)" style="font-size:12px;color:#aaa;">' +
                '<input type="hidden" class="slide-img-path" value="' + escHtml(data.image || '') + '">' +
                '<label style="margin-top:5px;">Image Fit</label>' +
                '<select class="slide-img-fit" style="width:100%;padding:5px;background:#2c3e50;color:#fff;border:1px solid #34495e;border-radius:3px;font-size:12px;margin-top:2px;">' +
                    '<option value="contain"' + (imgFit==='contain' ?' selected':'') + '>Contain — whole image</option>' +
                    '<option value="cover"'   + (imgFit==='cover'   ?' selected':'') + '>Cover — crop to fill</option>' +
                    '<option value="fill"'    + (imgFit==='fill'    ?' selected':'') + '>Stretch to fill</option>' +
                    '<option value="fit-w"'   + (imgFit==='fit-w'   ?' selected':'') + '>Fit Width</option>' +
                    '<option value="fit-h"'   + (imgFit==='fit-h'   ?' selected':'') + '>Fit Height</option>' +
                '</select>' +
            '</div>' +
            '<div class="slide-field"' + (imageOnly ? ' style="display:none;"' : '') + '>' +
                '<label>Text Position</label>' +
                '<select class="slide-text-pos" style="width:100%;padding:6px;background:#2c3e50;color:#fff;border:1px solid #34495e;border-radius:3px;font-size:13px;">' +
                    '<option value="right"'  + (textPos==='right'  ?' selected':'') + '>Right of image</option>' +
                    '<option value="left"'   + (textPos==='left'   ?' selected':'') + '>Left of image</option>' +
                    '<option value="bottom"' + (textPos==='bottom' ?' selected':'') + '>Below image</option>' +
                    '<option value="top"'    + (textPos==='top'    ?' selected':'') + '>Above image</option>' +
                '</select>' +
            '</div>' +
            '<div class="slide-field" style="grid-column:1/-1;' + (imageOnly ? 'display:none;' : '') + '">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">' +
                    '<label style="margin:0;">Title</label>' +
                    (titleDel
                        ? '<button class="btn gray" style="font-size:10px;padding:2px 7px;" onclick="restoreSlideField(this,\'title\')">+ Restore</button>'
                        : '<button class="btn danger" style="font-size:10px;padding:2px 7px;" onclick="deleteSlideField(this,\'title\')">&#10005; Delete</button>') +
                '</div>' +
                '<input type="text" class="slide-title" value="' + titleVal + '"' +
                    (titleDel ? ' disabled style="opacity:0.3;"' : '') +
                    ' data-deleted="' + (titleDel ? '1' : '0') + '">' +
            '</div>' +
            '<div class="slide-field" style="grid-column:1/-1;' + (imageOnly ? 'display:none;' : '') + '">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">' +
                    '<label style="margin:0;">Price</label>' +
                    (priceDel
                        ? '<button class="btn gray" style="font-size:10px;padding:2px 7px;" onclick="restoreSlideField(this,\'price\')">+ Restore</button>'
                        : '<button class="btn danger" style="font-size:10px;padding:2px 7px;" onclick="deleteSlideField(this,\'price\')">&#10005; Delete</button>') +
                '</div>' +
                '<input type="text" class="slide-price" value="' + priceVal + '"' +
                    (priceDel ? ' disabled style="opacity:0.3;"' : '') +
                    ' data-deleted="' + (priceDel ? '1' : '0') + '">' +
            '</div>' +
            '<div class="slide-field" style="grid-column:1/-1;' + (imageOnly ? 'display:none;' : '') + '">' +
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:3px;">' +
                    '<label style="margin:0;">Description</label>' +
                    (descDel
                        ? '<button class="btn gray" style="font-size:10px;padding:2px 7px;" onclick="restoreSlideField(this,\'desc\')">+ Restore</button>'
                        : '<button class="btn danger" style="font-size:10px;padding:2px 7px;" onclick="deleteSlideField(this,\'desc\')">&#10005; Delete</button>') +
                '</div>' +
                '<textarea class="slide-desc" rows="2"' +
                    (descDel ? ' disabled style="opacity:0.3;"' : '') +
                    ' data-deleted="' + (descDel ? '1' : '0') + '">' + descVal + '</textarea>' +
            '</div>' +
        '</div>';
    list.appendChild(div);
}

function deleteSlideField(btn, field) {
    var sf  = btn.closest('.slide-field');
    var inp = sf.querySelector('input[type="text"], textarea');
    if (inp) { inp.disabled = true; inp.style.opacity = '0.3'; inp.dataset.deleted = '1'; inp.value = ''; }
    btn.innerHTML = '+ Restore';
    btn.classList.remove('danger'); btn.classList.add('gray');
    btn.setAttribute('onclick', "restoreSlideField(this,'" + field + "')");
}

function restoreSlideField(btn, field) {
    var sf  = btn.closest('.slide-field');
    var inp = sf.querySelector('input[type="text"], textarea');
    if (inp) { inp.disabled = false; inp.style.opacity = ''; inp.dataset.deleted = '0'; }
    btn.innerHTML = '&#10005; Delete';
    btn.classList.remove('gray'); btn.classList.add('danger');
    btn.setAttribute('onclick', "deleteSlideField(this,'" + field + "')");
}

function toggleSlideImgOnly(cb) {
    var row    = cb.closest('.slide-row');
    var isOnly = cb.checked;
    row.querySelectorAll('.slide-field').forEach(function(f) {
        // Keep the Image field and the Image Only checkbox field always visible
        if (f.querySelector('.slide-img-path') || f.querySelector('.slide-img-only')) return;
        f.style.display = isOnly ? 'none' : '';
    });
}

function removeSlideRow(btn) {
    var row = btn.closest('.slide-row');
    if (row) row.remove();
    document.querySelectorAll('#carousel-slides-list .slide-row').forEach(function(r, i) {
        var h = r.querySelector('.slide-header');
        if (h) h.firstChild.textContent = 'Slide ' + (i + 1) + ' ';
    });
}

function uploadSlideImage(input) {
    if (!input.files[0]) return;
    var row = input.closest('.slide-row');
    var fd  = new FormData();
    fd.append('file', input.files[0]);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('api.php?action=upload_file', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.status === 'success') {
                var pi = row.querySelector('.slide-img-path');
                if (pi) pi.value = res.path;
                var pv = row.querySelector('.slide-img-preview');
                if (pv) pv.innerHTML = '<img src="'+escHtml(res.path)+'" style="max-width:100%;max-height:60px;object-fit:contain;">';
            } else { showToast(res.message || 'Upload failed.', true); }
        }).catch(function(){ showToast('Upload failed.', true); });
}

function saveCarouselSlides() {
    if (!activeBlock) return;
    var rows   = document.querySelectorAll('#carousel-slides-list .slide-row');
    var slides = [];
    rows.forEach(function(row) {
        var titleInp = row.querySelector('.slide-title');
        var priceInp = row.querySelector('.slide-price');
        var descInp  = row.querySelector('.slide-desc');
        var imgOnlyCb = row.querySelector('.slide-img-only');
        slides.push({
            image:        (row.querySelector('.slide-img-path') || {}).value || '',
            imageFit:     (row.querySelector('.slide-img-fit')  || {}).value || 'contain',
            imageOnly:    !!(imgOnlyCb && imgOnlyCb.checked),
            textPosition: (row.querySelector('.slide-text-pos') || {}).value || 'right',
            title:        titleInp && titleInp.dataset.deleted === '1' ? null : (titleInp ? titleInp.value : ''),
            price:        priceInp && priceInp.dataset.deleted === '1' ? null : (priceInp ? priceInp.value : ''),
            description:  descInp  && descInp.dataset.deleted  === '1' ? null : (descInp  ? descInp.value  : ''),
        });
    });
    var interval = Math.max(1000, (parseFloat(document.getElementById('carousel-interval').value) || 5) * 1000);
    var cd = { interval: interval, slides: slides };
    activeBlock.dataset.carouselData = JSON.stringify(cd);
    buildCarouselPreview(activeBlock, cd);
    var sl = slides.length;
    document.getElementById('carousel-slide-count').textContent =
        sl + ' slide' + (sl !== 1 ? 's' : '') + ' — click Edit Slides to manage';
    closeCarouselModal();
    showToast('Slides saved. Remember to Publish.');
}

function updateCarouselInterval(val) {
    if (!activeBlock || activeBlock.dataset.type !== 'carousel') return;
    var cd = {};
    try { cd = JSON.parse(activeBlock.dataset.carouselData || '{}'); } catch(e) {}
    cd.interval = Math.max(1, parseFloat(val || 5)) * 1000;
    activeBlock.dataset.carouselData = JSON.stringify(cd);
}

// ============================================================
// TABLE PREVIEW + MODAL
// ============================================================
function buildTablePreview(block, data) {
    Array.from(block.children).forEach(function(child) {
        if (!child.classList.contains('rh') && !child.classList.contains('lock-icon') && !child.classList.contains('hidden-badge')) child.remove();
    });
    var headers = (data && data.headers) || [];
    var rows    = (data && data.rows)    || [];
    var preview = document.createElement('div');
    preview.className = 'table-preview';
    var lbl = document.createElement('div');
    lbl.className = 'table-preview-lbl';
    lbl.textContent = '⋞ Table — ' + headers.length + ' col' + (headers.length !== 1 ? 's' : '') +
                      ', ' + rows.length + ' row' + (rows.length !== 1 ? 's' : '');
    preview.appendChild(lbl);
    block.appendChild(preview);
}

function openTableModal() {
    if (!activeBlock || activeBlock.dataset.type !== 'table') return;
    var td = {};
    try { td = JSON.parse(activeBlock.dataset.tableData || '{}'); } catch(e) {}
    var headers = (td.headers && td.headers.length) ? td.headers : ['item_title', 'price', 'description'];
    var rows    = (td.rows    && td.rows.length)    ? td.rows    : [['', '', ''], ['', '', '']];
    var valigns = (td.valigns && td.valigns.length === headers.length) ? td.valigns : headers.map(function() { return 'top'; });
    var haligns = (td.haligns && td.haligns.length === headers.length) ? td.haligns : headers.map(function() { return 'left'; });
    var widths  = (td.widths  && td.widths.length  === headers.length) ? td.widths  : headers.map(function() { return 0; });
    var rowPad  = parseInt(td.row_padding) || 0;
    rows = rows.map(function(r) {
        while (r.length < headers.length) r.push('');
        return r.slice(0, headers.length);
    });
    document.getElementById('table-row-padding').value = rowPad;
    rebuildTableEditor({ headers: headers, rows: rows, valigns: valigns, haligns: haligns, widths: widths });
    document.getElementById('table-modal-overlay').classList.add('open');
}

function closeTableModal() {
    document.getElementById('table-modal-overlay').classList.remove('open');
}

function rebuildTableEditor(data) {
    var headers = data.headers || [];
    var rows    = data.rows    || [];
    var valigns = (data.valigns && data.valigns.length === headers.length) ? data.valigns : headers.map(function() { return 'top'; });
    var haligns = (data.haligns && data.haligns.length === headers.length) ? data.haligns : headers.map(function() { return 'left'; });
    var widths  = (data.widths  && data.widths.length  === headers.length) ? data.widths  : headers.map(function() { return 0; });

    var head = document.getElementById('table-editor-head');
    var body = document.getElementById('table-editor-body');
    head.innerHTML = '';
    body.innerHTML = '';

    var STYLES = [
        { value: 'item_title',    label: 'Title' },
        { value: 'item_title_2',  label: 'Title 2' },
        { value: 'price',         label: 'Price' },
        { value: 'price_2',       label: 'Price 2' },
        { value: 'description',   label: 'Description' },
        { value: 'section_header',label: 'Section Header' },
        { value: 'free',          label: 'Plain' },
    ];
    var VALIGNS = [{value:'top',label:'Top'},{value:'middle',label:'Mid'},{value:'bottom',label:'Bot'}];
    var HALIGNS = [{value:'left',label:'Left'},{value:'center',label:'Ctr'},{value:'right',label:'Right'}];

    var htr = document.createElement('tr');
    headers.forEach(function(style, ci) {
        var va = valigns[ci] || 'top';
        var ha = haligns[ci] || 'left';
        var th = document.createElement('th');
        var wval = widths[ci] || 0;
        var styleOpts  = STYLES.map(function(s)  { return '<option value="'+s.value+'"'+(style===s.value?' selected':'')+'>'+s.label+'</option>'; }).join('');
        var valignOpts = VALIGNS.map(function(v) { return '<option value="'+v.value+'"'+(va===v.value?' selected':'')+'>'+v.label+'</option>'; }).join('');
        var halignOpts = HALIGNS.map(function(h) { return '<option value="'+h.value+'"'+(ha===h.value?' selected':'')+'>'+h.label+'</option>'; }).join('');
        th.innerHTML =
            '<select class="col-style-sel">' + styleOpts + '</select>' +
            '<div class="col-align-row">' +
            '<select class="col-align-sel col-valign-sel" title="Vertical align">' + valignOpts + '</select>' +
            '<select class="col-align-sel col-halign-sel" title="Horizontal align">' + halignOpts + '</select>' +
            '</div>' +
            '<div class="col-width-row"><input type="number" class="col-width-inp" min="0" max="100" value="' + wval + '" placeholder="auto" title="Column width %"><span class="col-width-lbl">%</span></div>' +
            '<button class="btn danger del-col-btn" onclick="deleteTableCol(' + ci + ')">&#10005; Col</button>';
        htr.appendChild(th);
    });
    var thEmpty = document.createElement('th');
    thEmpty.style.cssText = 'width:34px;background:#0d1b24;';
    htr.appendChild(thEmpty);
    head.appendChild(htr);

    rows.forEach(function(row, ri) {
        var tr = document.createElement('tr');
        headers.forEach(function(_, ci) {
            var td = document.createElement('td');
            var inp = document.createElement('input');
            inp.type  = 'text';
            inp.value = (row[ci] !== undefined && row[ci] !== null) ? row[ci] : '';
            td.appendChild(inp);
            tr.appendChild(td);
        });
        var tdDel = document.createElement('td');
        tdDel.className = 'del-row-td';
        tdDel.innerHTML = '<button class="btn danger" style="font-size:10px;padding:2px 4px;width:100%;" onclick="deleteTableRow(' + ri + ')">&#10005;</button>';
        tr.appendChild(tdDel);
        body.appendChild(tr);
    });
}

function getTableEditorData() {
    var head = document.getElementById('table-editor-head');
    var headers = Array.from(head.querySelectorAll('.col-style-sel')).map(function(s) { return s.value; });
    var valigns = Array.from(head.querySelectorAll('.col-valign-sel')).map(function(s) { return s.value; });
    var haligns = Array.from(head.querySelectorAll('.col-halign-sel')).map(function(s) { return s.value; });
    var widths  = Array.from(head.querySelectorAll('.col-width-inp')).map(function(i) { return Math.min(100, Math.max(0, parseInt(i.value) || 0)); });
    var rowPad  = Math.min(120, Math.max(0, parseInt(document.getElementById('table-row-padding').value) || 0));
    var rows = [];
    document.getElementById('table-editor-body').querySelectorAll('tr').forEach(function(tr) {
        rows.push(Array.from(tr.querySelectorAll('td input[type="text"]')).map(function(inp) { return inp.value; }));
    });
    return { headers: headers, valigns: valigns, haligns: haligns, widths: widths, row_padding: rowPad, rows: rows };
}

function addTableRow() {
    var td = getTableEditorData();
    td.rows.push(td.headers.map(function() { return ''; }));
    rebuildTableEditor(td);
}

function addTableCol() {
    var td = getTableEditorData();
    td.headers.push('item_title');
    td.valigns.push('top');
    td.haligns.push('left');
    td.widths.push(0);
    td.rows.forEach(function(r) { r.push(''); });
    rebuildTableEditor(td);
}

function deleteTableCol(ci) {
    var td = getTableEditorData();
    if (td.headers.length <= 1) { showToast('Table must have at least 1 column.', true); return; }
    td.headers.splice(ci, 1);
    td.valigns.splice(ci, 1);
    td.haligns.splice(ci, 1);
    td.widths.splice(ci, 1);
    td.rows.forEach(function(r) { r.splice(ci, 1); });
    rebuildTableEditor(td);
}

function deleteTableRow(ri) {
    var td = getTableEditorData();
    if (td.rows.length <= 1) { showToast('Table must have at least 1 row.', true); return; }
    td.rows.splice(ri, 1);
    rebuildTableEditor(td);
}

function saveTable() {
    if (!activeBlock) return;
    var td = getTableEditorData();
    activeBlock.dataset.tableData = JSON.stringify(td);
    buildTablePreview(activeBlock, td);
    document.getElementById('table-info').textContent =
        td.headers.length + ' col' + (td.headers.length !== 1 ? 's' : '') +
        ', ' + td.rows.length + ' row' + (td.rows.length !== 1 ? 's' : '');
    closeTableModal();
    showToast('Table saved. Remember to Publish.');
}

// ============================================================
// MARQUEE PREVIEW + INSPECTOR UPDATES
// ============================================================
function buildMarqueePreview(block, data) {
    Array.from(block.children).forEach(function(child) {
        if (!child.classList.contains('rh') && !child.classList.contains('lock-icon') && !child.classList.contains('hidden-badge')) child.remove();
    });
    var d      = data || {};
    var text   = d.text   || 'Marquee text — click to edit in inspector';
    var color  = d.color  || '#ffffff';
    var size   = d.size   || 28;
    var weight = d.weight || 'bold';
    var bg     = d.bg === 'transparent' ? 'transparent' : (d.bg || '#c0392b');
    block.style.background = bg;
    var inner = document.createElement('div');
    inner.className  = 'marquee-preview';
    inner.style.color      = color;
    inner.style.fontSize   = size + 'px';
    inner.style.fontWeight = weight;
    inner.textContent = '▶ ' + text;
    block.appendChild(inner);
}

function updateMarqueeText(val) {
    if (!activeBlock || activeBlock.dataset.type !== 'marquee') return;
    var md = {}; try { md = JSON.parse(activeBlock.dataset.marqueeData || '{}'); } catch(e) {}
    md.text = val;
    activeBlock.dataset.marqueeData = JSON.stringify(md);
    buildMarqueePreview(activeBlock, md);
}

function updateMarqueeSpeed(val) {
    if (!activeBlock || activeBlock.dataset.type !== 'marquee') return;
    document.getElementById('marquee-speed-label').textContent = val + ' px/sec';
    var md = {}; try { md = JSON.parse(activeBlock.dataset.marqueeData || '{}'); } catch(e) {}
    md.speed = parseInt(val);
    activeBlock.dataset.marqueeData = JSON.stringify(md);
}

function updateMarqueeStyle() {
    if (!activeBlock || activeBlock.dataset.type !== 'marquee') return;
    var md = {}; try { md = JSON.parse(activeBlock.dataset.marqueeData || '{}'); } catch(e) {}
    var isTrans = document.getElementById('marquee-bg-transparent').checked;
    document.getElementById('marquee-bg').disabled = isTrans;
    md.color  = document.getElementById('marquee-color').value;
    md.size   = parseInt(document.getElementById('marquee-size').value)   || 28;
    md.weight = document.getElementById('marquee-weight').value;
    md.bg     = isTrans ? 'transparent' : document.getElementById('marquee-bg').value;
    activeBlock.dataset.marqueeData = JSON.stringify(md);
    buildMarqueePreview(activeBlock, md);
}

// ============================================================
// RESIZE HANDLES
// ============================================================
function addResizeHandles(block) {
    ['nw','n','ne','e','se','s','sw','w'].forEach(function(pos) {
        var h = document.createElement('div');
        h.className = 'rh rh-' + pos;
        block.appendChild(h);
    });
}

// ============================================================
// TEXT ALIGN
// ============================================================
function applyTextAlign(align) {
    if (!activeBlock || activeBlock.dataset.type !== 'text') return;
    activeBlock.style.textAlign = align;
    activeBlock.dataset.textAlign = align;
    ['left','center','right','justify'].forEach(function(a) {
        var btn = document.getElementById('ta-' + a);
        if (btn) btn.style.background = (a === align) ? '#3498db' : '';
    });
}

// ============================================================
// ALIGN TO SCREEN (1920 × 1080 canvas)
// ============================================================
// "Align to Parent" — snaps each element to a position within its own parent container.
// For child blocks in a section, the section is the parent.
// For root-level blocks and sections, the canvas (1920×1080) is the parent.
function alignToParent(direction) {
    var targets = multiSel.length > 0 ? multiSel : (activeBlock ? [activeBlock] : []);
    if (targets.length === 0) return;
    targets.forEach(function(block) {
        var pc = _parentContainer(block);
        var x  = parseFloat(block.getAttribute('data-x')) || 0;
        var y  = parseFloat(block.getAttribute('data-y')) || 0;
        var w  = block.offsetWidth;
        var h  = block.offsetHeight;
        if      (direction === 'left')     x = 0;
        else if (direction === 'right')    x = pc.w - w;
        else if (direction === 'center-h') x = (pc.w - w) / 2;
        else if (direction === 'top')      y = 0;
        else if (direction === 'bottom')   y = pc.h - h;
        else if (direction === 'center-v') y = (pc.h - h) / 2;
        moveBlock(block, x, y);
    });
    if (activeBlock && targets.indexOf(activeBlock) >= 0) {
        document.getElementById('insp-x').value = Math.round(parseFloat(activeBlock.getAttribute('data-x')) || 0);
        document.getElementById('insp-y').value = Math.round(parseFloat(activeBlock.getAttribute('data-y')) || 0);
    }
}
</script>
<div id="resize-label"></div>
</body>
</html>
