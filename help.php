<?php
require_once 'auth.php';
require_once 'db_connect.php';
requireLogin();
$me      = currentUser();
$isAdmin = isAdmin();

// Load branding
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
<title>Help &amp; User Guide — <?= htmlspecialchars(SITE_NAME) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

body { background: #1e2b38; color: #d0d8e0; min-height: 100vh; }

/* ── Nav ── */
#top-nav {
    background: <?= htmlspecialchars(BRAND_NAV_BG) ?>;
    padding: 0 20px; display: flex; align-items: center; gap: 14px;
    height: 46px; border-bottom: 1px solid <?= htmlspecialchars(BRAND_NAV_BORDER) ?>;
    position: sticky; top: 0; z-index: 100;
}
#top-nav .brand { font-weight: bold; font-size: 14px;
                  color: <?= htmlspecialchars(BRAND_TEXT) ?>; margin-right: auto; }
#top-nav a { color: #bdc3c7; text-decoration: none; font-size: 12px;
             padding: 5px 9px; border-radius: 3px; }
#top-nav a:hover { background: #2c3e50; color: #fff; }
#top-nav a.active { background: <?= htmlspecialchars(BRAND_ACCENT) ?>; color: #fff; }
.role-tag { background: <?= $isAdmin ? '#e74c3c' : '#3498db' ?>; color: #fff;
            font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 8px;
            text-transform: uppercase; margin-left: 4px; }

/* ── Layout ── */
#layout { display: flex; min-height: calc(100vh - 46px); }

/* ── Sidebar ── */
#sidebar {
    width: 240px; flex-shrink: 0; background: #1a252f;
    border-right: 1px solid #2c3e50; padding: 20px 0;
    position: sticky; top: 46px; height: calc(100vh - 46px); overflow-y: auto;
}
#sidebar h2 { font-size: 10px; text-transform: uppercase; letter-spacing: 1.5px;
              color: #7f8c8d; padding: 12px 20px 6px; margin-top: 8px; }
#sidebar h2:first-child { margin-top: 0; }
#sidebar a { display: block; padding: 6px 20px; font-size: 13px; color: #bdc3c7;
             text-decoration: none; border-left: 3px solid transparent;
             transition: all .15s; }
#sidebar a:hover { background: #22303f; color: #fff; border-left-color: #3498db; }
#sidebar a.section-link { font-weight: 600; color: #d0d8e0; }
#sidebar a.sub-link { padding-left: 32px; font-size: 12px; }

/* ── Content ── */
#content { flex: 1; padding: 40px 48px; max-width: 860px; }

h1.page-title { font-size: 26px; color: #fff; margin-bottom: 6px; }
.page-sub { color: #7f8c8d; font-size: 14px; margin-bottom: 36px; }

/* Sections */
.help-section { margin-bottom: 52px; }
.help-section h2 {
    font-size: 18px; color: #fff; border-bottom: 2px solid #2c3e50;
    padding-bottom: 10px; margin-bottom: 20px;
}
.help-section h3 { font-size: 14px; color: <?= htmlspecialchars(BRAND_ACCENT) ?>;
                   margin: 24px 0 8px; text-transform: uppercase; letter-spacing: .8px; }
.help-section p { font-size: 14px; line-height: 1.7; color: #c0cad4; margin-bottom: 10px; }
.help-section ul, .help-section ol { padding-left: 20px; margin-bottom: 10px; }
.help-section li { font-size: 14px; line-height: 1.7; color: #c0cad4; margin-bottom: 4px; }
.help-section li strong { color: #d0d8e0; }

/* Tip / note boxes */
.tip, .note, .admin-only {
    border-radius: 6px; padding: 12px 16px; margin: 12px 0; font-size: 13px; line-height: 1.6;
}
.tip   { background: #1a3244; border-left: 4px solid #3498db; color: #aaccee; }
.note  { background: #2b2204; border-left: 4px solid #f39c12; color: #e0c080; }
.admin-only { background: #2e1a1a; border-left: 4px solid #e74c3c; color: #e0a0a0; }
.tip strong, .note strong, .admin-only strong { color: #fff; }

/* Key badges */
kbd {
    display: inline-block; background: #2c3e50; border: 1px solid #4a6278;
    border-radius: 4px; padding: 1px 7px; font-size: 12px; font-family: monospace;
    color: #d0d8e0; white-space: nowrap;
}

/* Step lists */
.steps { counter-reset: step; list-style: none; padding: 0; }
.steps li { counter-increment: step; display: flex; gap: 12px; margin-bottom: 10px; }
.steps li::before {
    content: counter(step); min-width: 24px; height: 24px; border-radius: 50%;
    background: <?= htmlspecialchars(BRAND_ACCENT) ?>; color: #fff; font-size: 12px;
    font-weight: bold; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; margin-top: 2px;
}
.steps li span { font-size: 14px; color: #c0cad4; line-height: 1.6; }

/* Feature grid */
.feat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 14px 0; }
.feat-card {
    background: #1a2a38; border: 1px solid #2c3e50; border-radius: 8px;
    padding: 14px 16px;
}
.feat-card .icon { font-size: 20px; margin-bottom: 6px; }
.feat-card h4 { font-size: 13px; color: #fff; margin-bottom: 4px; }
.feat-card p { font-size: 12px; color: #8a9eb0; line-height: 1.5; margin: 0; }

/* Fit table */
table.fit-table { width: 100%; border-collapse: collapse; font-size: 13px; margin: 10px 0; }
table.fit-table th { text-align: left; color: #7f8c8d; font-size: 11px;
                     text-transform: uppercase; padding: 6px 10px;
                     border-bottom: 1px solid #2c3e50; }
table.fit-table td { padding: 8px 10px; border-bottom: 1px solid #1e2b38; color: #c0cad4; }
table.fit-table tr:last-child td { border-bottom: none; }
table.fit-table td:first-child { color: #fff; font-weight: 600; white-space: nowrap; }

/* Back to top */
.back-top { font-size: 12px; color: #3498db; text-decoration: none; float: right; }
.back-top:hover { text-decoration: underline; }
</style>
</head>
<body>

<!-- ── Nav ── -->
<div id="top-nav">
    <?php if (BRAND_LOGO): ?>
        <img src="<?= htmlspecialchars(BRAND_LOGO) ?>" alt="Logo"
             style="max-height:32px; max-width:120px; object-fit:contain;">
    <?php else: ?>
        <span class="brand"><?= htmlspecialchars(SITE_NAME) ?></span>
    <?php endif; ?>
    <a href="builder.php">Builder</a>
    <a href="crud.php">Assets</a>
    <?php if ($isAdmin): ?>
        <a href="admin_panel.php">Admin</a>
        <a href="setup_branding.php">Branding</a>
    <?php endif; ?>
    <a href="help.php" class="active">Help</a>
    <?php /* No "View Display" link here: every display has its own address, and this
             page is not about one of them. The Builder links to the display it is
             editing, and Admin Panel → Displays lists every address. */ ?>
    <span style="font-size:12px; color:#bdc3c7;">
        <?= htmlspecialchars($me['username']) ?>
        <span class="role-tag"><?= $isAdmin ? 'ADMIN' : 'USER' ?></span>
    </span>
    <a href="logout.php">Sign Out</a>
</div>

<div id="layout">

<!-- ── Sidebar ── -->
<nav id="sidebar">
    <h2>Getting Started</h2>
    <a href="#overview"      class="section-link">System Overview</a>
    <a href="#roles"         class="section-link">Roles &amp; Permissions</a>
    <a href="#signing-in"    class="section-link">Signing In / Out</a>

    <h2>The Builder</h2>
    <a href="#builder-intro" class="section-link">Builder Overview</a>
    <a href="#adding-blocks" class="sub-link">Adding Content</a>
    <a href="#moving"        class="sub-link">Moving &amp; Resizing</a>
    <a href="#inspector"     class="sub-link">Inspector Panel</a>
    <a href="#multiselect"   class="sub-link">Multi-Select &amp; Align</a>
    <a href="#locking"       class="sub-link">Locking Blocks</a>
    <a href="#publishing"    class="sub-link">Publishing</a>

    <h2>Content Types</h2>
    <a href="#sections"      class="section-link">Sections</a>
    <a href="#text-blocks"   class="section-link">Text Blocks</a>
    <a href="#image-blocks"  class="section-link">Image Blocks</a>
    <a href="#video-blocks"  class="section-link">Video Blocks</a>
    <a href="#carousel"      class="section-link">Carousel</a>
    <a href="#marquee"       class="section-link">Marquee Ticker</a>

    <h2>Other Pages</h2>
    <a href="#assets"        class="section-link">Asset Library</a>
    <a href="#branding"      class="section-link">Brand Standards</a>
    <a href="#setup-brand"   class="section-link">Store Branding</a>
    <?php if ($isAdmin): ?>
    <a href="#admin"         class="section-link">Admin Panel</a>
    <?php endif; ?>
    <a href="#viewer"        class="section-link">The Display Viewer</a>

    <h2>Tips</h2>
    <a href="#tips"          class="section-link">Quick Reference</a>
</nav>

<!-- ── Main content ── -->
<main id="content">
<h1 class="page-title">User Guide</h1>
<p class="page-sub">Everything you need to build and manage your store display system.</p>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="overview">
    <h2>System Overview</h2>
    <p>This system lets you design full-screen layouts for the TVs and kiosks at your store. You build a layout in the <strong>Builder</strong>, publish it with one click, and the <strong>Viewer</strong> page — open on the screen itself — shows the latest published layout within 30 seconds.</p>
    <p>Each screen you drive is a <strong>display</strong>, with its own size, its own layout, and its own list of people allowed to edit it. Sizes are set when a display is created and can be anything, including tall portrait screens — so what you see on the canvas is the shape of that particular screen, not a fixed 1920 × 1080.</p>
    <p>Every display also has its own <strong>address</strong> — the screen name tag in its Viewer link, like <code>viewer.php?display=drive-thru</code>. That address is what you point a TV or a signage widget at, and it is the one thing that decides which layout appears there.</p>
    <p>All content is stored in a database. Changes you publish are live within 30 seconds — no FTP, and nobody has to touch the screen.</p>

    <div class="feat-grid">
        <div class="feat-card"><div class="icon">🎨</div><h4>Builder</h4><p>Drag-and-drop canvas editor for designing one display's layout.</p></div>
        <div class="feat-card"><div class="icon">📺</div><h4>Viewer</h4><p>Full-screen page for one display, auto-refreshing every 30 seconds.</p></div>
        <div class="feat-card"><div class="icon">🗂️</div><h4>Asset Library</h4><p>Reusable pool of text, images, and video, shared by every display.</p></div>
        <div class="feat-card"><div class="icon">⚙️</div><h4>Admin Panel</h4><p>Add and remove displays, decide who may edit which, manage accounts.</p></div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="roles">
    <h2>Roles &amp; Permissions</h2>
    <p>Every account has one of two roles:</p>

    <h3>Admin</h3>
    <ul>
        <li>Create and move <strong>Sections</strong> (the purple-bordered layout containers)</li>
        <li>Add any block type: Image, Carousel, Marquee, Video</li>
        <li>Edit font styles, block dimensions, canvas background</li>
        <li>Manage Brand Standards via the Branding page (global font/color styles for branded blocks)</li>
        <li>Delete blocks, manage users, configure store branding</li>
        <li>Publish the layout to the display</li>
    </ul>

    <h3>Basic User</h3>
    <ul>
        <li>Must click a Section to target it, then add content blocks inside it</li>
        <li>Can add: Section Header, Item Title, Price, Description, Image</li>
        <li>Can edit text content, upload images, and resize blocks inside their section</li>
        <li>Cannot move sections, change canvas background, or modify brand styles</li>
        <li>Can publish their changes</li>
    </ul>

    <div class="admin-only"><strong>Admin-only features</strong> are marked with a red ADMIN tag throughout the builder interface.</div>

    <h3>Which displays you can edit</h3>
    <p>Your role decides <em>what</em> you can change; which <strong>displays</strong> you can change it
       on is separate. An admin can edit every display. A basic user is assigned displays one at a time,
       in Admin Panel → Displays → <em>Who can edit which display</em>.</p>
    <ul>
        <li>Assigned one display — the builder opens straight into it.</li>
        <li>Assigned more than one — you choose which to edit, and the builder returns you to the last
            one you had open.</li>
        <li>Assigned none — the builder says <em>"No displays have been assigned to you yet"</em>. Ask an
            admin which display is yours.</li>
    </ul>
    <p>Publishing is part of editing: if a display is yours, you can publish it to its screen. Being
       assigned a display is not permanent — an admin can take it back, and the next publish from a
       builder tab that has lost access is refused.</p>

    <h3>One person edits a display at a time</h3>
    <p>Opening a display in the builder claims it. While you have it, anyone else who opens the same
       display sees it <strong>read-only</strong> — a purple bar at the top names you, and none of the
       editing controls are there. Different displays can be worked on at the same time by different
       people; it is only the same display that is one at a time.</p>
    <ul>
        <li><strong>You keep it while you are working.</strong> Clicks, typing and dragging all count.
            Simply having the tab open does not.</li>
        <li><strong>After 15 minutes with nothing happening it is released</strong>, even with the tab
            open, so a builder left open on a back-office screen does not block anyone. You get a
            warning bar with a <em>Keep editing</em> button two minutes before that.</li>
        <li><strong>Coming back after a break is fine.</strong> Change anything and the display is
            yours again — unless somebody else started in the meantime, in which case a red bar says
            so and your publish is refused. Your work stays on the screen either way.</li>
        <li><strong>Leaving the builder releases it immediately</strong>, so the next person does not
            wait out the 15 minutes.</li>
    </ul>
    <div class="admin-only"><strong>Admins</strong> can take a display off whoever has it: open it in
       the builder and click <em>Take over editing</em>. The confirm says what it costs — the other
       person keeps what is on their screen but can no longer publish it.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="signing-in">
    <h2>Signing In / Out</h2>

    <h3>Sign In</h3>
    <ol class="steps">
        <li><span>Go to <code>login.php</code> in your browser.</span></li>
        <li><span>Enter your username and password.</span></li>
        <li><span>After 5 failed attempts you will be locked out for 5 minutes — wait and try again.</span></li>
    </ol>

    <h3>Forgot Password</h3>
    <ol class="steps">
        <li><span>Click <strong>Forgot password?</strong> on the login page.</span></li>
        <li><span>Enter your username. A 6-digit passcode is emailed to the address on file.</span></li>
        <li><span>Enter the passcode within 30 minutes (5 wrong attempts cancels the reset).</span></li>
        <li><span>Choose a new password (minimum 8 characters).</span></li>
    </ol>

    <h3>Sign Out</h3>
    <p>Click <strong>Sign Out</strong> in the top navigation bar. Your session is cleared immediately.</p>

    <div class="tip"><strong>Tip:</strong> Always sign out on shared computers. Sessions expire automatically after inactivity but signing out is instant and safe.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="builder-intro">
    <h2>Builder Overview</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>The Builder is the main design tool, and it edits <strong>one display at a time</strong>. The display's title, its screen name tag, and its size are shown in the top-left of the nav bar — for example <strong>Drive-Thru</strong> · <code>drive-thru</code> · 1920 × 1080. The canvas is that display's size, so what you arrange is exactly what its screen shows. Everything is positioned absolutely, using X and Y coordinates from the top-left corner of the canvas.</p>
    <p>The interface has three main areas:</p>
    <ul>
        <li><strong>Top control bar</strong> — buttons for adding content, changing the background, zoom, and publishing</li>
        <li><strong>Canvas (centre)</strong> — the design area where you place and arrange content blocks</li>
        <li><strong>Inspector panel (right)</strong> — appears when a block is selected; shows position, size, and content options</li>
    </ul>
    <p>If you can edit more than one display, <strong>Switch display ⇄</strong> in the nav bar lists the ones available to you; going straight to <code>builder.php</code> with no display named shows the same list.</p>
    <div class="tip"><strong>Tip:</strong> A canvas is usually bigger than the window it is being edited in — a portrait screen especially. Use the <strong>Zoom</strong> buttons in the control bar: <strong>Fit</strong> shows the whole canvas at once, <strong>100%</strong> is actual size, and <strong>−</strong> / <strong>+</strong> step between them. The percentage next to them is the current zoom. The Builder opens at Fit. Use these rather than your browser's own zoom, so the X and Y numbers in the Inspector keep matching what you see.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="adding-blocks">
    <h2>Adding Content</h2>
    <a href="#" class="back-top">↑ Top</a>

    <h3>For Admins — Add a Section first</h3>
    <p>Sections are the structural containers (purple border) that organise your layout. Content blocks live <em>inside</em> sections.</p>
    <ol class="steps">
        <li><span>Click <strong>+ Section</strong> in the control bar. A purple-bordered rectangle appears.</span></li>
        <li><span>Drag it to where you want it on the canvas.</span></li>
        <li><span>Resize it by dragging any edge or corner.</span></li>
        <li><span>Click the section to target it, then add blocks inside it.</span></li>
    </ol>

    <h3>For Basic Users — Click a Section first</h3>
    <p>You cannot create sections. An admin must create them. To add content:</p>
    <ol class="steps">
        <li><span>Click on a section (purple border) — its border turns orange when targeted.</span></li>
        <li><span>The banner at the top shows "Section selected — now add a block."</span></li>
        <li><span>Click any block type button to add it inside that section.</span></li>
    </ol>

    <h3>Block types available to all users</h3>
    <ul>
        <li><strong>+ Section Header</strong> — Large styled heading (uses brand font/colour)</li>
        <li><strong>+ Item Title</strong> — Product or menu item name (uses brand font/colour)</li>
        <li><strong>+ Price</strong> — Price display block (uses brand font/colour)</li>
        <li><strong>+ Description</strong> — Smaller description text (uses brand font/colour)</li>
        <li><strong>+ Image</strong> — Displays a still image (upload in the inspector)</li>
    </ul>

    <h3>Admin-only block types</h3>
    <ul>
        <li><strong>+ Image</strong> — Image block with fit-mode options</li>
        <li><strong>+ Video</strong> — Auto-playing looped video (MP4, WebM, OGV — max 50 MB)</li>
        <li><strong>+ Carousel</strong> — Slideshow of images with optional titles/prices</li>
        <li><strong>+ Marquee</strong> — Scrolling ticker text across the bottom or anywhere</li>
    </ul>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="moving">
    <h2>Moving &amp; Resizing</h2>
    <a href="#" class="back-top">↑ Top</a>

    <h3>Moving a block</h3>
    <p>Click and drag the <strong>centre area</strong> of any block to move it. The cursor changes to a move arrow (<span style="font-size:16px;">✥</span>) when dragging is available. The X and Y position fields in the Inspector update live as you drag.</p>

    <h3>Resizing a block</h3>
    <p>Hover near any <strong>edge or corner</strong> of a selected block. The cursor changes to a resize arrow. Drag to change the size. While resizing, a dark badge shows the current dimensions (e.g. <kbd>420 × 180 px</kbd>) floating in the centre of the block.</p>

    <div class="tip"><strong>Tip:</strong> Use the <strong>W</strong> and <strong>H</strong> fields in the Inspector to type an exact pixel size. This is the easiest way to make two blocks exactly the same width or height — read the size from one, type it into the other.</div>

    <h3>Typing exact position or size</h3>
    <p>With any block selected, the Inspector panel (right side) shows four editable number fields:</p>
    <ul>
        <li><strong>X</strong> — distance from the left edge of the canvas (in pixels)</li>
        <li><strong>Y</strong> — distance from the top edge of the canvas (in pixels)</li>
        <li><strong>W</strong> — block width in pixels</li>
        <li><strong>H</strong> — block height in pixels</li>
    </ul>
    <p>Click any field, type a number, and press <kbd>Tab</kbd> or <kbd>Enter</kbd> — the block moves or resizes instantly.</p>

    <div class="note"><strong>Note:</strong> Sections can only be moved and resized by Admins. Basic users can resize blocks within their targeted section but cannot reposition the section itself.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="inspector">
    <h2>Inspector Panel</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>Click any block to select it (red outline). The Inspector panel opens on the right side of the screen showing controls specific to that block type.</p>

    <h3>All blocks</h3>
    <ul>
        <li><strong>X / Y / W / H</strong> — exact position and size, all editable</li>
        <li><strong>Link DB Asset</strong> — link the block to a saved entry in the Asset Library so updates to the asset reflect everywhere it is used</li>
        <li><strong>Lock toggle</strong> — prevents accidental drags/resizes; locked blocks show a 🔒 icon</li>
        <li><strong>Delete Block</strong> — removes the block from the canvas (cannot be undone until the next Publish)</li>
    </ul>

    <h3>Branded text blocks (Section Header / Item Title / Price / Description)</h3>
    <ul>
        <li>Shows a purple "Brand Style Applied" badge — font and colour come from Brand Standards</li>
        <li>Double-click the block to edit the text content (all users can do this)</li>
        <li>To change the font or colour for ALL blocks of this type at once, use <strong>Brand Standards</strong> (admin only)</li>
    </ul>

    <h3>Image blocks</h3>
    <ul>
        <li><strong>Upload Image</strong> — JPG, PNG, GIF, WEBP. Max 10 MB.</li>
        <li><strong>Image Fit</strong> — controls how the image fills the block (see Image Blocks section below)</li>
    </ul>

    <h3>Video blocks (admin only)</h3>
    <ul>
        <li><strong>Upload Video</strong> — MP4, WebM, or OGV. Max 50 MB. Videos auto-play, loop, and are muted.</li>
    </ul>

    <h3>Carousel blocks (admin only)</h3>
    <ul>
        <li><strong>Interval</strong> — seconds per slide (1–60)</li>
        <li><strong>Edit Slides</strong> — opens the slide editor to add/remove/reorder slides</li>
    </ul>

    <h3>Marquee blocks (admin only)</h3>
    <ul>
        <li><strong>Marquee Text</strong> — the scrolling message</li>
        <li><strong>Scroll Speed</strong> — pixels per second (10–300)</li>
        <li><strong>Text Style</strong> — colour, size (px), weight</li>
        <li><strong>Background Color</strong> — the bar's background</li>
    </ul>

    <div class="tip"><strong>Alignment tip:</strong> The Inspector shows a reminder: Shift+click a second block to enter multi-select mode and reveal the alignment toolbar above the canvas.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="multiselect">
    <h2>Multi-Select &amp; Alignment</h2>
    <a href="#" class="back-top">↑ Top</a>

    <h3>Selecting multiple blocks</h3>
    <ol class="steps">
        <li><span>Click the first block (red outline — single select).</span></li>
        <li><span>Hold <kbd>Shift</kbd> and click a second block. Both turn orange — the alignment toolbar appears above the canvas.</span></li>
        <li><span>Continue <kbd>Shift</kbd>-clicking to add more blocks to the selection.</span></li>
        <li><span><kbd>Shift</kbd>-click an orange block again to remove it from the selection.</span></li>
        <li><span>Click any block <em>without</em> Shift (or click the canvas background) to clear multi-select and go back to single-select.</span></li>
    </ol>

    <div class="note"><strong>Note:</strong> Sections cannot be multi-selected. Only content blocks (text, image, video, etc.) can be shift-selected.</div>

    <h3>Alignment toolbar</h3>
    <p>When 2 or more blocks are selected the alignment bar appears between the top control bar and the canvas:</p>
    <ul>
        <li><strong>◀ Left</strong> — align all left edges to the leftmost block's left edge</li>
        <li><strong>Right ▶</strong> — align all right edges to the rightmost block's right edge</li>
        <li><strong>▲ Top</strong> — align all top edges to the topmost block's top edge</li>
        <li><strong>Bottom ▼</strong> — align all bottom edges to the bottommost block's bottom edge</li>
        <li><strong>↔ H-Center</strong> — centre all blocks horizontally within the selection's bounding box</li>
        <li><strong>↕ V-Center</strong> — centre all blocks vertically within the selection's bounding box</li>
    </ul>

    <div class="tip"><strong>Pro tip:</strong> To make a row of price labels line up perfectly — select them all with Shift+click, then click <strong>▲ Top</strong> to snap their tops to the same Y position.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="locking">
    <h2>Locking Blocks</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>Any block can be locked to prevent accidental moves or resizes during editing.</p>
    <ol class="steps">
        <li><span>Click the block to select it.</span></li>
        <li><span>In the Inspector, tick <strong>Lock this block</strong>.</span></li>
        <li><span>A 🔒 icon appears on the block. It can no longer be dragged or resized.</span></li>
        <li><span>To unlock, select the block and un-tick the checkbox.</span></li>
    </ol>
    <div class="tip"><strong>Tip:</strong> Lock section backgrounds and header blocks once they are positioned correctly, so everyday users editing prices won't accidentally move them.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="publishing">
    <h2>Publishing</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>Publishing saves the current canvas to the database and makes it live on the display.</p>
    <ol class="steps">
        <li><span>Finish making your changes on the canvas.</span></li>
        <li><span>Click the <strong>✓ Publish</strong> button (top-right of the control bar).</span></li>
        <li><span>A success message appears. The display viewer will show the new layout within 30 seconds.</span></li>
    </ol>
    <div class="note"><strong>Important:</strong> Unpublished changes exist only in your browser tab. If you close the tab or navigate away <em>without</em> publishing, your changes are lost. Always publish before leaving the builder.</div>
    <p>A publish can be <strong>refused</strong>, and nothing is saved when it is. There are two reasons,
       and the message says which:</p>
    <ul>
        <li><em>"This display changed since you opened it"</em> — somebody published, or an element was
            hidden or deleted, while your tab was open. Reload and re-apply your change, so you do not
            overwrite their work.</li>
        <li><em>"Someone else is editing this display now"</em> — the display was released while you were
            idle and somebody took it, or an admin took over. Your work is still on screen; publish again
            once they have finished.</li>
    </ul>
    <div class="tip"><strong>Tip:</strong> The viewer auto-polls every 30 seconds. You don't need to refresh the TV browser — just publish and wait.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="sections">
    <h2>Sections</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only:</strong> Only admins can create, move, or delete sections.</div>
    <p>Sections are structural layout containers shown with a <strong>purple border</strong>. They define areas of the screen (e.g. "Left column", "Price list", "Feature image"). Content blocks live inside sections and are positioned relative to the section's top-left corner.</p>
    <ul>
        <li>Drag the section's border or label to move it on the canvas</li>
        <li>Drag any edge to resize the section</li>
        <li>Sections can have a <strong>background image</strong> — set it in the Inspector when the section is selected</li>
        <li>Click a section to target it (orange border) before adding blocks to it</li>
        <li>Child blocks inside a section cannot be dragged outside the section's boundary</li>
    </ul>
    <div class="tip"><strong>Tip:</strong> Plan your sections before adding content. Common layouts: one large left section for a feature image + one right section for product details.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="text-blocks">
    <h2>Text Blocks</h2>
    <a href="#" class="back-top">↑ Top</a>

    <h3>Branded Text Blocks</h3>
    <p>These four types use store-wide font and colour settings defined in Brand Standards (set on the Branding page):</p>
    <ul>
        <li><strong>Section Header</strong> — Large heading. Default: Arial 36px bold white.</li>
        <li><strong>Item Title</strong> — Product name. Default: Arial 24px bold light-grey.</li>
        <li><strong>Price</strong> — Price. Default: Arial 30px bold orange (#f39c12).</li>
        <li><strong>Description</strong> — Detail text. Default: Arial 14px regular grey.</li>
    </ul>
    <p>To edit the text: double-click the block and type. To change the style for <em>all</em> blocks of that type, go to Brand Standards (admin only).</p>
    <div class="tip"><strong>Tip:</strong> Consistent use of branded text blocks means you can restyle your entire display (e.g. change all prices to green) in seconds from the Brand Standards screen.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="image-blocks">
    <h2>Image Blocks</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>Image blocks display a still image. After adding an image block to the canvas, select it and use the Inspector to upload a photo.</p>

    <h3>Uploading an image</h3>
    <ol class="steps">
        <li><span>Click <strong>+ Image</strong> in the control bar.</span></li>
        <li><span>Click the block to select it — the Inspector panel opens on the right.</span></li>
        <li><span>Under <strong>Upload Image</strong>, click the file picker and choose an image (JPG, PNG, GIF, WEBP — max 10 MB).</span></li>
        <li><span>The image appears in the block immediately.</span></li>
        <li><span>Choose an <strong>Image Fit</strong> mode (see below).</span></li>
        <li><span>Publish when ready.</span></li>
    </ol>

    <h3>Image Fit modes</h3>
    <table class="fit-table">
        <tr><th>Mode</th><th>What it does</th><th>Best used when…</th></tr>
        <tr>
            <td>Stretch to fill</td>
            <td>Stretches the image to exactly fill the block. May distort the image.</td>
            <td>The image aspect ratio already matches the block.</td>
        </tr>
        <tr>
            <td>Contain</td>
            <td>Scales the whole image to fit inside the block without cropping. Adds blank/transparent letterbox space on the short sides.</td>
            <td>You need to see the whole image, even if there's empty space around it.</td>
        </tr>
        <tr>
            <td>Cover</td>
            <td>Scales the image to fill the entire block. Crops the edges if the aspect ratios differ. No distortion.</td>
            <td>Product/hero images where you want the block fully filled and cropping is acceptable.</td>
        </tr>
        <tr>
            <td>Fit Width</td>
            <td>Image width equals block width. Height scales proportionally — any overflow at top/bottom is clipped.</td>
            <td>Wide banner images where width must fill exactly.</td>
        </tr>
        <tr>
            <td>Fit Height</td>
            <td>Image height equals block height. Width scales proportionally — any overflow left/right is clipped.</td>
            <td>Tall portrait images where height must fill exactly.</td>
        </tr>
    </table>
    <div class="tip"><strong>Tip:</strong> <strong>Cover</strong> is the best choice for most product images — it fills the block neatly without distortion. <strong>Contain</strong> is best for logos where you need the full image visible.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="video-blocks">
    <h2>Video Blocks</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only.</strong></div>
    <p>Video blocks auto-play, loop continuously, and are always muted (required for auto-play in modern browsers).</p>
    <ol class="steps">
        <li><span>Click <strong>+ Video</strong> in the control bar.</span></li>
        <li><span>Select the block and click <strong>Upload Video</strong> in the Inspector.</span></li>
        <li><span>Choose an MP4, WebM, or OGV file (max 50 MB).</span></li>
        <li><span>The video begins playing in the builder preview.</span></li>
        <li><span>Resize the block to fit your layout. Publish when ready.</span></li>
    </ol>
    <div class="tip"><strong>Tip:</strong> MP4 (H.264) has the broadest browser support. Keep videos short and loopable — 5–15 seconds works well for in-store displays.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="carousel">
    <h2>Carousel (Slideshow)</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only.</strong></div>
    <p>A carousel cycles through a sequence of slides, each with an optional image, title, and price. It is useful for featuring multiple products in one block.</p>

    <h3>Setting up a carousel</h3>
    <ol class="steps">
        <li><span>Click <strong>+ Carousel</strong> in the control bar.</span></li>
        <li><span>Select the block and set the <strong>Interval</strong> (seconds per slide) in the Inspector.</span></li>
        <li><span>Click <strong>Edit Slides</strong> to open the slide editor.</span></li>
        <li><span>For each slide: upload an image, enter an optional title and price.</span></li>
        <li><span>Use the <strong>Remove</strong> button to delete a slide. Add more with <strong>Add Slide</strong>.</span></li>
        <li><span>Click <strong>Save Slides</strong> when done. Publish when ready.</span></li>
    </ol>

    <div class="tip"><strong>Tip:</strong> Carousels are great for a "Featured Products" section — set a 5-second interval so customers have time to read each slide.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="marquee">
    <h2>Marquee Ticker</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only.</strong></div>
    <p>A marquee scrolls text continuously across the block — like a news ticker. Useful for announcements, promotions, or store hours.</p>

    <h3>Setting up a marquee</h3>
    <ol class="steps">
        <li><span>Click <strong>+ Marquee</strong> in the control bar.</span></li>
        <li><span>Select the block. In the Inspector, type the scrolling message in the <strong>Marquee Text</strong> box.</span></li>
        <li><span>Adjust the <strong>Scroll Speed</strong> slider (pixels per second — higher = faster).</span></li>
        <li><span>Set the text colour, size, weight, and background colour.</span></li>
        <li><span>Resize the block to span the width you want (commonly full-screen width).</span></li>
        <li><span>Publish when ready.</span></li>
    </ol>

    <div class="tip"><strong>Tip:</strong> A full-width marquee block placed at the bottom of the canvas is a classic in-store style. Set speed around 80–100 px/sec for comfortable reading.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="assets">
    <h2>Asset Library</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>The Asset Library (<a href="crud.php" style="color:#3498db;">Assets</a> in the nav) is a central pool of reusable content items — text snippets, images, and video paths. Linking a canvas block to an asset means the block's content comes from the asset and updates everywhere the asset is used.</p>

    <h3>Adding an asset</h3>
    <ol class="steps">
        <li><span>Go to <strong>Assets</strong> in the top navigation.</span></li>
        <li><span>Choose the asset type (Text, Image, or Video) and fill in the content and an optional label.</span></li>
        <li><span>Click <strong>Add Asset</strong>.</span></li>
    </ol>

    <h3>Linking an asset to a block</h3>
    <ol class="steps">
        <li><span>Select a block on the canvas.</span></li>
        <li><span>In the Inspector, find the <strong>Link DB Asset</strong> dropdown.</span></li>
        <li><span>Choose an asset from the list. The block's content is replaced by the asset's content.</span></li>
        <li><span>Publish. From now on, updating the asset in the Asset Library will update all linked blocks on the next Publish.</span></li>
    </ol>

    <div class="tip"><strong>Tip:</strong> Link your price blocks to price assets. When a price changes, update the asset once — then publish — and every block showing that price updates at the same time.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="branding">
    <h2>Brand Standards</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only.</strong></div>
    <p>Brand Standards define the default font family, size, colour, weight, and line height for each of the six branded text block types: Section Header, Item Title, Item Title 2, Price, Price 2, and Description.</p>

    <h3>Changing brand styles</h3>
    <ol class="steps">
        <li><span>Go to <strong>Branding</strong> (top nav → Branding) and scroll to the Brand Standards section.</span></li>
        <li><span>A table shows each block type's current settings, with a live preview. Edit any field.</span></li>
        <li><span>Click <strong>Save Brand Standards</strong>.</span></li>
    </ol>

    <div class="note"><strong>Note:</strong> Brand Standards only affect <em>branded</em> text blocks — not free text blocks, where you set the font yourself in the Inspector.</div>
    <div class="tip"><strong>These reach the screens on their own.</strong> Every screen reads this typography each time it polls, so a saved change appears within 30 seconds on <em>every</em> display, with no publishing needed. That also means it is the one change you can make without opening the Builder — and the one that can alter a screen while somebody else is mid-edit on it.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="setup-brand">
    <h2>Store Branding</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only.</strong></div>
    <p>Store Branding (<a href="setup_branding.php" style="color:#3498db;">Branding</a> in the nav) controls the visual theme of the builder itself — the nav bar colours and the store logo shown in the top-left.</p>
    <ul>
        <li><strong>Logo</strong> — PNG, JPG, GIF, or WEBP (max 2 MB). Displayed in the nav bar.</li>
        <li><strong>Nav Background</strong> — colour of the top navigation bar</li>
        <li><strong>Nav Border</strong> — the thin line below the nav bar</li>
        <li><strong>Accent Color</strong> — used for the Publish button and other highlights</li>
        <li><strong>Nav Text</strong> — colour of the store name / logo text in the nav</li>
    </ul>
    <p>Click <strong>Save Branding</strong> after making changes. The new colours take effect on the next page load.</p>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<?php if ($isAdmin): ?>
<div class="help-section" id="admin">
    <h2>Admin Panel</h2>
    <a href="#" class="back-top">↑ Top</a>
    <div class="admin-only"><strong>Admin only.</strong></div>
    <p>The <a href="admin_panel.php" style="color:#3498db;">Admin Panel</a> has tabs for user accounts, displays, branding, settings, and the work area.</p>

    <h3>Displays tab</h3>
    <p>Everything about the screens this system drives.</p>
    <ul>
        <li><strong>Add a display</strong> — you choose its size first (a preset, or type any width and height), then its title, its <strong>screen name tag</strong>, and an optional location. You can start it blank or as <em>a copy of an existing display's layout</em> — only displays of exactly the same size are offered as a source.</li>
        <li><strong>The size cannot be changed afterwards.</strong> Every block sits at a fixed position on that canvas, and there is no undo, so a smaller canvas would quietly hide blocks that still exist. A differently shaped screen means a new display built at that shape.</li>
        <li><strong>Each display's card</strong> shows its tag, size and shape, how many elements it holds, its location, when it was last published and by whom, which accounts it is assigned to, its full screen address ready to copy for the TV or signage widget, and whether someone has it open in the Builder right now.</li>
        <li><strong>Edit</strong> — title, tag, location, and background. Changing the tag changes the display's address, so any screen pointed at the old one must be re-pointed.</li>
        <li><strong>Turn off</strong> — the screen shows a "turned off" notice instead of the layout, and the layout is kept. Use this rather than deleting when a screen is out of service.</li>
        <li><strong>Delete</strong> — permanently removes the display <em>and its whole layout</em>, with no undo. You have to type its screen name tag exactly to confirm, and any screen pointed at it goes blank. Deleting the only display is allowed and leaves the Builder with nothing to open until you add one; turning a display off is almost always what you want instead.</li>
        <li><strong>Who can edit which display</strong> — the grant matrix, <em>Who can edit which display</em>, at the bottom of the tab. Tick a basic account against a display to let it edit that display. Admins are not listed: they already hold every display. An account with no ticks is told "No displays have been assigned to you yet" when it opens the Builder.</li>
    </ul>

    <h3>Work Area tab</h3>
    <p>Lists the published elements of one display — choose which with the <strong>Display</strong> selector at the top — so you can hide or delete a single block without opening the Builder. <strong>Hide</strong> takes it off that screen within 30 seconds while keeping it in the layout, ready to un-hide; <strong>Delete</strong> removes it for good. Neither needs a publish, and both mean that any Builder tab opened before the change has to reload before it can publish.</p>

    <h3>Users tab</h3>
    <ul>
        <li><strong>Create user</strong> — add a new account with a username, email, password, and role (Admin or Basic)</li>
        <li><strong>Edit user</strong> — change username, email, role, or active status. Leave password blank to keep it unchanged.</li>
        <li><strong>Deactivate / Activate</strong> — a deactivated user cannot sign in</li>
        <li><strong>Delete</strong> — permanently removes the account</li>
        <li><strong>Reset password</strong> — sends a password reset email to the user</li>
    </ul>

    <h3>Assets tab</h3>
    <p>Shows all entries in the Asset Library with the option to view, edit, or delete them. Deleting an asset that is linked to a canvas block sets those blocks to show no content until re-linked or manually filled.</p>

    <div class="note"><strong>Note:</strong> You cannot delete your own account from the Admin Panel. An admin must always exist.</div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="viewer">
    <h2>The Display Viewer</h2>
    <a href="#" class="back-top">↑ Top</a>
    <p>The Viewer is the page that runs on your TV or kiosk. It shows one display's published layout full-screen and refreshes every 30 seconds to pick up new publishes.</p>

    <p><strong>Which display it shows comes from the address</strong>, and only from the address:</p>
    <ul>
        <li><code>viewer.php?display=drive-thru</code> — the display whose screen name tag is <code>drive-thru</code></li>
        <li><code>viewer.php</code> with nothing after it — <em>no</em> layout, just a "no display specified" notice</li>
    </ul>
    <p>That is deliberate. A truncated or mistyped address shows a notice rather than falling back to some other screen's prices, which is the mistake worth preventing on a sign that customers read. Admins can copy the exact address for any display from Admin Panel → Displays.</p>

    <h3>Setting up a screen</h3>
    <ol class="steps">
        <li><span>Get the display's address from Admin Panel → Displays — the <strong>Screen address</strong> box, with its <strong>Copy</strong> button.</span></li>
        <li><span>Open that address in the TV or kiosk browser.</span></li>
        <li><span>Switch the browser to full-screen mode (<kbd>F11</kbd> on most systems).</span></li>
        <li><span>Leave it running — it polls automatically. No login is required on the viewer.</span></li>
    </ol>
    <p>The layout is scaled to fit whatever screen it lands on and centred, so a display designed at 1920 × 1080 fills a larger screen of the same shape with no changes at all. If the shapes differ you get plain dark bars on the mismatched edges rather than a stretched, distorted sign.</p>

    <h3>If the screen shows a notice instead of the layout</h3>
    <ul>
        <li><strong>"No display specified"</strong> — the address has no <code>?display=</code> part. Re-point the screen at the full address.</li>
        <li><strong>"Display not found"</strong> — the tag in the address doesn't match any display. Check for a typo, and check whether the tag was renamed or the display deleted.</li>
        <li><strong>"This display is turned off"</strong> — someone turned it off in Admin Panel → Displays. Turn it back on; the layout was kept.</li>
    </ul>

    <div class="tip"><strong>Tip:</strong> Use a dedicated browser profile or kiosk mode so the viewer page cannot be accidentally closed or navigated away from.</div>
    <div class="note"><strong>Note:</strong> Viewer addresses are public — no login is needed, so that any screen on your network can display one. Anyone who has an address can see that sign, so treat the addresses as public and keep prices you are not ready to show off the canvas. If you want a screen password-protected, discuss HTTP Basic Auth at the web server level with your server admin.</div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<div class="help-section" id="tips">
    <h2>Quick Reference</h2>
    <a href="#" class="back-top">↑ Top</a>

    <h3>Keyboard &amp; Mouse shortcuts</h3>
    <ul>
        <li><kbd>Shift</kbd> + click block — add to multi-selection (turns orange)</li>
        <li><kbd>Shift</kbd> + click orange block — remove from multi-selection</li>
        <li>Click canvas background — clears all selections</li>
        <li>Double-click text block — enters text editing mode</li>
        <li>Drag block centre — moves the block</li>
        <li>Drag block edge/corner — resizes the block</li>
        <li><kbd>Tab</kbd> / <kbd>Enter</kbd> in Inspector number field — applies the typed value</li>
        <li><kbd>F11</kbd> — full-screen browser (for viewer screen)</li>
    </ul>

    <h3>Common tasks at a glance</h3>
    <ul>
        <li><strong>Change a price</strong> → double-click the Price block, edit text, Publish</li>
        <li><strong>Swap an image</strong> → click the image block, Upload Image in Inspector, Publish</li>
        <li><strong>Make two boxes the same width</strong> → click first block, note W value in Inspector; click second block, type same W value, press Tab</li>
        <li><strong>Align a row of labels</strong> → Shift+click all blocks, click ▲ Top in alignment bar, Publish</li>
        <li><strong>Add a new product</strong> → click a section, add Item Title + Price + Description blocks, fill in text, Publish</li>
        <li><strong>Change all prices to a new colour</strong> → Brand Standards → change Price font colour → Save. No publishing needed; every screen picks it up within 30 seconds</li>
        <li><strong>Update a display remotely</strong> → make changes in the Builder, click Publish — that display's screen updates within 30 seconds</li>
        <li><strong>Point a new TV at a sign</strong> → Admin Panel → Displays → Copy the screen address → open it on the TV → full-screen</li>
    </ul>

    <h3>Troubleshooting</h3>
    <ul>
        <li><strong>Display hasn't updated</strong> — wait up to 30 seconds; if still not updated, check that Publish succeeded (look for the green toast message), and check you published the display that screen is actually pointed at</li>
        <li><strong>Screen shows "No display specified" or "Display not found"</strong> — the screen is pointed at the wrong address. Copy the display's screen address from Admin Panel → Displays and re-point it</li>
        <li><strong>Screen shows "This display is turned off"</strong> — turn it back on in Admin Panel → Displays; nothing was lost</li>
        <li><strong>The Builder won't let me change anything</strong> — read the bar at the top. A purple bar means somebody else has this display open, so you have it read-only; a red bar means they took over while you had it. Neither loses your work, but publishing will be refused</li>
        <li><strong>Publish was refused</strong> — either the display changed since you opened it (reload and re-apply your changes, or the message names who published) or somebody else holds it now. Nothing was saved either way, and what is on your screen is still there</li>
        <li><strong>Wrong display in the Builder</strong> — use <strong>Switch display ⇄</strong> in the nav bar</li>
        <li><strong>Block won't move</strong> — check if the 🔒 lock icon is showing; deselect and re-click the block, then untick Lock in the Inspector</li>
        <li><strong>Image looks stretched</strong> — select the image block, change Image Fit to <em>Cover</em> or <em>Contain</em> in the Inspector</li>
        <li><strong>Shift+click not working</strong> — make sure you click the block itself (not its text inner area); click a block normally first, then Shift+click the next</li>
        <li><strong>Can't add a block (basic user)</strong> — you must click a section (purple border) first to target it before adding any blocks</li>
        <li><strong>Forgot password</strong> — use the Forgot Password link on the login page; check spam/junk if the email doesn't arrive</li>
    </ul>
</div>

<p style="font-size:12px; color:#4a5f72; margin-top:40px; padding-top:20px; border-top:1px solid #2c3e50;">
    <?= htmlspecialchars(SITE_NAME) ?> Display System &mdash; Help Guide &mdash;
    Signed in as <strong style="color:#7f8c8d;"><?= htmlspecialchars($me['username']) ?></strong>
    &mdash; <a href="logout.php" style="color:#4a5f72;">Sign Out</a>
</p>

</main>
</div><!-- #layout -->

<script>
// Highlight active sidebar link based on scroll position
var sections = document.querySelectorAll('.help-section');
var links     = document.querySelectorAll('#sidebar a[href^="#"]');

function onScroll() {
    var scrollY = window.scrollY + 80;
    var active  = null;
    sections.forEach(function(s) {
        if (s.offsetTop <= scrollY) active = s.id;
    });
    links.forEach(function(a) {
        var target = a.getAttribute('href').slice(1);
        a.style.borderLeftColor = (target === active) ? '<?= htmlspecialchars(BRAND_ACCENT) ?>' : 'transparent';
        a.style.color = (target === active) ? '#fff' : '';
        a.style.background = (target === active) ? '#22303f' : '';
    });
}

window.addEventListener('scroll', onScroll, {passive: true});
onScroll();

// Smooth scroll for all anchor links
document.querySelectorAll('a[href^="#"]').forEach(function(a) {
    a.addEventListener('click', function(e) {
        var id = this.getAttribute('href').slice(1);
        if (!id) {
            e.preventDefault();
            window.scrollTo({top: 0, behavior: 'smooth'});
            return;
        }
        var target = document.getElementById(id);
        if (target) {
            e.preventDefault();
            target.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    });
});
</script>
</body>
</html>
