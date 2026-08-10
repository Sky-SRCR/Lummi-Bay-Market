<?php
require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/grants.php';
require_once __DIR__ . '/lib/display_request.php';
require_once __DIR__ . '/lib/upload_limits.php';
requireCurrentAccount($pdo);
$me      = currentUser();
$isAdmin = isAdmin();

// Which Display is being edited, and at what canvas size. Authenticated, so schema
// convergence is safe here (BUILD-REFERENCE §2 invariant 7).
ensureSignageSchema($pdo);

$displayStore = new DisplayStore($pdo);
// Who is asking, and which Displays they hold (ADR-0005). The same object decides
// what this page may open and what the picker below offers, so the two cannot
// disagree about what belongs to this account.
$actor      = Actor::signedIn($me, new GrantStore($pdo));
$resolution = DisplayRequest::forEditing($displayStore, $_GET, $actor);

// One Display to work on and no tag goes straight in. Beyond that, a `basic`
// account returns to whatever it was last editing rather than being asked again —
// the roadmap's Builder entry decision. It is remembered for the session only, and
// it lives here rather than in lib/ because nothing in lib/ touches $_SESSION.
// Admins are asked every time: they hold every Display, and choosing is the point.
// `?switch=1` is how the top bar's Switch display link asks for the picker anyway.
if (!$resolution->isFound() && $resolution->kind() === DisplayResolution::NO_TAG
    && !$isAdmin && empty($_GET['switch']) && !empty($_SESSION['last_display'])) {
    $again = DisplayRequest::forEditing($displayStore, ['display' => $_SESSION['last_display']], $actor);
    // Silently ignored if that Display was deleted, retired, or is no longer
    // granted — a remembered choice must never override a refusal.
    if ($again->isFound()) { $resolution = $again; }
}

// Anything that names no Display, names one that does not exist, or names one this
// account may not open lands here and picks from what is actually theirs.
if (!$resolution->isFound()) {
    $notice  = '';
    if ($resolution->kind() === DisplayResolution::UNKNOWN) {
        $notice = 'That display does not exist. It may have been deleted, or its screen name tag renamed.';
    } elseif ($resolution->kind() === DisplayResolution::INACTIVE) {
        // Only a basic account lands here: a retired Display opens normally for an
        // admin, banner and all.
        $notice = 'That display is turned off, so it is not yours to edit while it is out of service. '
                . 'An admin can turn it back on.';
    } elseif ($resolution->kind() === DisplayResolution::FORBIDDEN
           || $resolution->kind() === DisplayResolution::MISMATCH) {
        $notice = $resolution->message();
    }

    // Only what this account may open — a Display it has not been granted is not
    // offered, and neither is a retired one it could not work on anyway (ADR-0005).
    $allDisplays = $displayStore->all();
    $choices     = $actor->openable($allDisplays);
    // Theirs by grant, turned off ones included: the difference between these two
    // is what tells "you have not been given a sign yet" from "your sign is off".
    $theirs      = $actor->granted($allDisplays);
    ?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Choose a display — Builder</title>
<style>
* { box-sizing:border-box; margin:0; padding:0;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
body { background:#2c3e50; color:#fff; min-height:100vh; padding:40px 20px; }
.wrap { max-width:640px; margin:0 auto; }
h1 { font-size:19px; margin-bottom:6px; }
.sub { font-size:13px; color:#bdc3c7; margin-bottom:22px; line-height:1.6; }
.notice { background:#5d3a3a; border:1px solid #8c5252; border-radius:5px; padding:10px 14px;
          font-size:13px; margin-bottom:18px; }
a.pick { display:block; background:#34495e; border:1px solid #415b76; border-radius:6px;
         padding:13px 16px; margin-bottom:9px; text-decoration:none; color:#fff; }
a.pick:hover { background:#3d566e; }
.pick .row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.pick .title { font-size:15px; font-weight:600; }
.pick .tag { font-family:"SF Mono",Menlo,Consolas,monospace; font-size:12px; background:#2c3e50;
             border:1px solid #4a6480; border-radius:3px; padding:1px 7px; color:#aed6f1; }
.pick .off { font-size:11px; font-weight:700; background:#7b3f3f; border-radius:9px; padding:1px 8px; }
.pick .facts { font-size:12px; color:#bdc3c7; margin-top:5px; }
.foot { margin-top:24px; font-size:13px; }
.foot a { color:#aed6f1; }
</style>
</head>
<body>
<div class="wrap">
  <?php if ($notice !== ''): ?><div class="notice"><?= Markup::text($notice) ?></div><?php endif; ?>

  <?php if ($choices): ?>
    <h1>Which display do you want to edit?</h1>
    <p class="sub">Each one is a separate sign with its own layout. Publishing sends only the
       display you are in to its screen.<?= $isAdmin
         ? ' As an admin you hold all of them.'
         : ' These are the displays assigned to you.' ?></p>
    <?php foreach ($choices as $d): ?>
      <a class="pick" href="builder.php?display=<?= urlencode($d->tag()) ?>">
        <span class="row">
          <span class="title"><?= Markup::text($d->title()) ?></span>
          <span class="tag"><?= Markup::text($d->tag()) ?></span>
          <?php if (!$d->isActive()): ?><span class="off">TURNED OFF</span><?php endif; ?>
        </span>
        <span class="facts"><?= Markup::text($d->dimensionsLabel()) ?> <?= Markup::text($d->orientation()) ?><?php
          if ($d->location() !== '') { echo ' · ' . Markup::text($d->location()); } ?></span>
      </a>
    <?php endforeach; ?>
  <?php elseif (!$allDisplays): ?>
    <h1>There are no displays yet</h1>
    <p class="sub">A display is one sign: its screen name tag, its canvas size and its layout.
       <?= $isAdmin ? 'Add the first one in the Admin Panel, under Displays.'
                    : 'Ask an admin to add one, and to give you access to it.' ?></p>
  <?php elseif (!$theirs): ?>
    <h1>No displays have been assigned to you yet</h1>
    <p class="sub">Editing a sign is given out one display at a time, so that nobody changes a
       screen they were not asked to. Ask an admin which display is yours — they assign it in the
       Admin Panel, under Displays.</p>
  <?php else: ?>
    <h1>Nothing to edit right now</h1>
    <p class="sub">Every display assigned to you is turned off. A display that is out of service is
       not editable by a basic account — ask an admin to turn one back on.</p>
  <?php endif; ?>

  <p class="foot">
    <?php if ($isAdmin): ?><a href="admin_panel.php?tab=displays">Manage displays</a> &nbsp;·&nbsp; <?php endif; ?>
    <a href="crud.php">Asset Library</a> &nbsp;·&nbsp;
    <a href="logout.php">Sign Out</a>
  </p>
</div>
</body>
</html><?php
    exit;
}

$display = $resolution->display();
$canvasW = $display->canvasWidth();
$canvasH = $display->canvasHeight();

// The edit lock (ADR-0007). Claimed here, on the request that opens the Display,
// because the answer decides how this page is *built*: an account that cannot have
// the lock gets a Builder with no editing controls in the HTML at all, rather than a
// whole editor that script disables afterwards. Read-only is therefore a mode of
// this page rather than a state to keep in sync, and it never changes while the page
// is open.
//
// It is a GET that writes, which is normally worth avoiding. The alternative is to
// render the editor and let script claim a moment later — which means either a
// flicker or, worse, somebody starting to drag a block that is about to turn
// read-only. Claiming during the render is also what makes two people opening the
// same sign in the same second resolve to one holder. What a crafted link could
// achieve is making this account hold a Display it may already edit, for at most one
// idle window; see BUILD-REFERENCE §4e.
$claimed = $displayStore->claimLock($display, $me['id']);
if ($claimed) { $display = $claimed; }

$lock     = $display->lockState();
$readOnly = $lock->heldByOther($me['id']);
// "Someone else" rather than an empty name: the account could have been deleted
// between taking the lock and this page load.
$lockHolder = $lock->holderName() !== '' ? $lock->holderName() : 'Someone else';

// Where a `basic` account comes back to next time it opens the Builder without
// naming a display. Read only by the entry rule above.
$_SESSION['last_display'] = $display->tag();

// There is somewhere to switch to only if this account may open a second Display —
// offering the choice to someone holding one grant would be a link to a dead end.
$switchable = count($actor->openable($displayStore->all()));

// The BRAND_* constants this page's CSS reads are defined by config.php, which
// auth.php requires above — one list of eight names and defaults, in lib/branding.php.
// The four that are colours are then read back through Brand::, not escaped: they
// land in the <style> block below, where there is no delimiter for an entity to
// neutralise and a value that is not a colour is CSS.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Builder — <?= Markup::text(SITE_NAME) ?></title>
<script src="https://cdn.jsdelivr.net/npm/interactjs@1.10.27/dist/interact.min.js"></script>
<style>
* { box-sizing: border-box; margin: 0; padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

body { background: #2c3e50; display: flex; flex-direction: column; height: 100vh; overflow: hidden; color: #fff; }

/* ── Nav ── */
#top-nav {
    background: <?= Brand::navBg() ?>; padding: 0 16px; display: flex; align-items: center;
    gap: 14px; height: 46px; flex-shrink: 0; border-bottom: 1px solid <?= Brand::navBorder() ?>;
}
#top-nav .brand { font-weight: bold; font-size: 14px; color: <?= Brand::text() ?>; }
#top-nav .user-badge { margin-left: 20px; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #bdc3c7; white-space: nowrap; flex-shrink: 0; }
#top-nav .nav-spacer { flex: 1; }
#top-nav a { color: #bdc3c7; text-decoration: none; font-size: 12px; padding: 5px 9px; border-radius: 3px; }
#top-nav a:hover { background: #2c3e50; color: #fff; }
.role-tag { background: <?= $isAdmin ? '#e74c3c' : '#3498db' ?>; color: #fff;
            font-size: 10px; font-weight: bold; padding: 1px 6px; border-radius: 8px;
            text-transform: uppercase; }
.btn.publish-btn { background: <?= Brand::accent() ?>; }

/* Which sign am I editing? Never left to be inferred from the canvas shape. */
#top-nav .display-badge { margin-left: 18px; display: flex; align-items: center; gap: 7px;
                          font-size: 12px; white-space: nowrap; }
#top-nav .display-badge .d-title { font-weight: 600; color: #fff; }
#top-nav .display-badge .d-tag { font-family: "SF Mono", Menlo, Consolas, monospace; font-size: 11px;
                                 background: #2c3e50; border: 1px solid #4a6480; border-radius: 3px;
                                 padding: 1px 6px; color: #aed6f1; }
#top-nav .display-badge .d-dims { color: #8fa6bb; font-size: 11px; }
#top-nav .display-badge .d-off { background: #c0392b; color: #fff; font-size: 10px; font-weight: bold;
                                 padding: 1px 6px; border-radius: 8px; text-transform: uppercase; }

/* Editing a retired Display is allowed on purpose — but never by accident. */
#display-off-banner {
    display: none; background: #7b3f3f; color: #fff; font-size: 13px; padding: 8px 14px;
    flex-shrink: 0; border-bottom: 1px solid #9b5252;
}

/* Somebody else is editing this Display (ADR-0007). One editor at a time, so this
   page is read-only — and the bar has to be the first thing read, because every
   control that would have changed something is simply not on the page. */
#lock-banner {
    background: #4b3869; color: #fff; font-size: 13px; padding: 9px 14px; flex-shrink: 0;
    border-bottom: 1px solid #6b5291; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
}
#lock-banner .who { font-weight: 700; }
#lock-banner .btn { padding: 4px 10px; }

/* The holder's own bars: idle warning, lapsed, lost-to-somebody-else, and access
   taken away. Each is an offer or a fact, never a modal — interrupting an editor is
   the thing the idle window exists to avoid. */
#lock-idle-bar, #lock-lapsed-bar, #lock-lost-bar, #lock-access-bar {
    display: none; font-size: 13px; padding: 8px 14px; flex-shrink: 0;
    align-items: center; gap: 10px; flex-wrap: wrap;
}
#lock-idle-bar   { background: #7d6608; border-bottom: 1px solid #9e8109; }
#lock-lapsed-bar { background: #4b3869; border-bottom: 1px solid #6b5291; }
#lock-lost-bar   { background: #7b3f3f; border-bottom: 1px solid #9b5252; }
/* Not the lock: the reach. Whatever this bar says, nothing on this page works again
   until somebody does something about it — so it is the one bar that never turns off
   by itself. Its sentence depends on which way the display stopped being this page's
   to edit; see LOCK_TERMINAL. */
#lock-access-bar { background: #7b3f3f; border-bottom: 1px solid #9b5252; }
#lock-idle-bar .btn { padding: 4px 10px; }

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

/* This Display's canvas, at the dimensions fixed when it was created (ADR-0004).
   Scaled by the zoom control — transform-origin keeps the top-left anchored so
   scroll position and pointer maths stay predictable. */
#builder-canvas {
    width: <?= intval($canvasW) ?>px; height: <?= intval($canvasH) ?>px; background: #fff; position: relative;
    flex-shrink: 0; box-shadow: 0 10px 30px rgba(0,0,0,.5);
    background-size: cover; background-position: center;
    transform-origin: top left;
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

/* ── Upload progress ──
   Not a toast: a toast fades, and the whole defect being fixed here is that a
   failed upload was indistinguishable from one still running. This stays on
   screen for as long as the upload does, and is removed by the code that knows
   the upload ended — one way or the other. */
#upload-status {
    position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
    background: #2c3e50; color: #fff; padding: 10px 18px; border-radius: 4px;
    font-size: 13px; display: none; z-index: 10000; min-width: 260px;
    box-shadow: 0 4px 12px rgba(0,0,0,.4);
}
#upload-status .up-label { font-weight: bold; margin-bottom: 6px; }
#upload-status .up-track { background: #1a252f; border-radius: 3px; height: 6px; overflow: hidden; }
#upload-status .up-fill  { background: #3498db; height: 100%; width: 0; transition: width .15s linear; }
</style>
</head>
<body>

<!-- ── Top Nav ── -->
<div id="top-nav">
    <?php if (Brand::logo()): ?>
        <img src="<?= Markup::text(Brand::logo()) ?>" alt="<?= Markup::text(SITE_NAME) ?>"
             style="max-height:32px; max-width:130px; object-fit:contain; flex-shrink:0;">
    <?php endif; ?>
    <span class="brand"><?= Markup::text(SITE_NAME) ?></span>
    <span class="user-badge">
        <?= Markup::text($me['username']) ?>
        <span class="role-tag"><?= $isAdmin ? 'ADMIN' : 'USER' ?></span>
    </span>
    <span class="display-badge" title="The display you are editing. Publishing sends only this one to its screen.">
        <span class="d-title"><?= Markup::text($display->title()) ?></span>
        <span class="d-tag"><?= Markup::text($display->tag()) ?></span>
        <span class="d-dims"><?= Markup::text($display->dimensionsLabel()) ?></span>
        <?php if (!$display->isActive()): ?><span class="d-off">off</span><?php endif; ?>
    </span>
    <?php if ($switchable > 1): ?>
        <a href="builder.php?switch=1" title="Edit a different display">Switch display ⇄</a>
    <?php endif; ?>
    <span class="nav-spacer"></span>
    <a href="crud.php">Asset Library</a>
    <?php if ($isAdmin): ?>
    <a href="admin_panel.php">Admin Panel</a>
    <?php endif; ?>
    <a href="help.php" target="_blank">Help</a>
    <a href="viewer.php?display=<?= urlencode($display->tag()) ?>" target="_blank">View Display ↗</a>
    <a href="logout.php">Sign Out</a>
</div>

<!-- ── Edit lock (ADR-0007) ── -->
<?php if ($readOnly): ?>
<div id="lock-banner">
    <span>
        <span class="who"><?= Markup::text($lockHolder) ?></span> is editing this display<?php
            if ($lock->takenAtLabel() !== ''): ?> (since <?= Markup::text($lock->takenAtLabel()) ?>)<?php
            endif; ?>. You are looking at it read-only — one person edits a display at a time, so
        nothing here can be moved, changed or published. It frees up on its own
        <?= intval(LockState::IDLE_LAPSE_SECONDS / 60) ?> minutes after they stop working.
        <span id="lock-free-hint" style="display:none;"><strong>It is free now</strong> —
            <a href="builder.php?display=<?= urlencode($display->tag()) ?>" style="color:#d6c9f5;">reload to edit it</a>.</span>
    </span>
    <?php if ($isAdmin): ?>
        <button class="btn danger" onclick="takeOverEditing()"
                title="Take the edit lock from <?= Markup::text($lockHolder) ?>">Take over editing</button>
    <?php endif; ?>
</div>
<?php else: ?>
<!-- Filled in by script from the idle age it is already tracking. Written out as
     markup rather than built in JavaScript so the wording is reviewable and no
     holder's name is ever assembled into HTML. -->
<div id="lock-idle-bar">
    <span><strong>Still working?</strong> Nothing has been touched here for a while, so this display
        will be released for other people to edit in about <span id="lock-idle-mins">2</span> minutes.</span>
    <button class="btn green" onclick="keepEditing()">Keep editing</button>
</div>
<div id="lock-lapsed-bar">
    <span><strong>The edit lock was released</strong> after
        <?= intval(LockState::IDLE_LAPSE_SECONDS / 60) ?> minutes with nothing happening, so somebody
        else can take this display. Carry on — changing anything takes it straight back, unless
        somebody has started in the meantime.</span>
</div>
<div id="lock-lost-bar">
    <span><strong><span id="lock-lost-who">Someone else</span> is editing this display now.</strong>
        Everything you have done is still on screen, but publishing is refused while they have it.
        Publish once they are finished, or reload to start again from what is on the screen.</span>
</div>
<?php endif; ?>
<!-- Outside the read-only branch on purpose: access can be taken away from somebody
     who was editing *and* from somebody who was only looking, and both need telling.
     An admin revoking a grant frees the edit lock in the same write, so this page has
     already stopped holding the display by the time it reads this — and if it said
     nothing, the person would carry on working and find out at the publish. -->
<div id="lock-access-bar">
    <span id="lock-access-text"><strong>Your access to this display has been removed.</strong>
        An admin has taken it off your list, so nothing here can be published any more and the
        display has been released for somebody else. What you have done is still on this screen —
        copy anything you need before you leave the page. Ask an admin if this was not expected.</span>
</div>

<!-- ── Control bar ── -->
<div id="control-bar">
    <?php if ($isAdmin && !$readOnly): ?>
        <button class="btn purple" onclick="createSection()">+ Section</button>
        <button class="btn"        onclick="createBlock('image',null)">+ Image</button>
        <button class="btn"        onclick="createBlock('carousel',null)">+ Carousel</button>
        <button class="btn"        onclick="createBlock('table',null)">+ Table</button>
        <button class="btn"        onclick="createBlock('marquee',null)">+ Marquee</button>
        <button class="btn"        onclick="createBlock('video',null)">+ Video</button>
        <div class="sep"></div>
    <?php endif; ?>

    <?php if (!$readOnly): ?>
    <button class="btn orange" onclick="createBlock('text','section_header')">+ Section Header</button>
    <button class="btn orange" onclick="createBlock('text','item_title')">+ Item Title</button>
    <button class="btn orange" onclick="createBlock('text','price')">+ Price</button>
    <button class="btn orange" onclick="createBlock('text','description')">+ Description</button>
    <?php else: ?>
    <span style="font-size:12px; color:#bdc3c7;">Read-only — <?= Markup::text($lockHolder) ?> has this display open.</span>
    <?php endif; ?>

    <?php if ($isAdmin && !$readOnly): ?>
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

    <div class="sep" style="margin-left:auto;"></div>
    <label style="font-size:11px; color:#bdc3c7;">Zoom:</label>
    <button class="btn gray" onclick="zoomToFit()" title="Fit the whole canvas in the window">Fit</button>
    <button class="btn gray" onclick="applyZoom(1)" title="Actual size">100%</button>
    <button class="btn gray" onclick="nudgeZoom(-1)" title="Zoom out">&minus;</button>
    <button class="btn gray" onclick="nudgeZoom(1)" title="Zoom in">+</button>
    <span id="zoom-readout" style="font-size:11px; color:#bdc3c7; min-width:34px; text-align:right;">100%</span>

    <?php if (!$readOnly): ?>
    <button class="btn publish-btn" style="margin-left:12px;" onclick="publishCanvas()">&#10003; Publish</button>
    <?php endif; ?>
</div>

<!-- ── Align bar (shown on multi-select OR single select) ──
     Everything from here to the end of the editor modals is an editing control,
     and a read-only Builder does not get any of it in the page. See the note
     above #inspector for what that costs and why it is worth it. -->
<?php if (!$readOnly): ?>
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
<?php endif; ?>

<!-- ── Turned-off notice ── -->
<?php if (!$display->isActive()): ?>
<div id="display-off-banner" style="display:block;">
    <strong>This display is turned off.</strong>
    No screen is showing it — anything pointed at it says so instead. You can still edit and publish;
    the layout is kept until someone turns it back on
    <?php if ($isAdmin): ?>
        (<a href="admin_panel.php?tab=displays" style="color:#ffd9d9;">Displays</a> in the Admin Panel).
    <?php else: ?>
        — ask an admin to turn it on.
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Section banner for basic users ── -->
<?php if (!$isAdmin && !$readOnly): ?>
<div id="section-banner">
    Click on a <strong>section</strong> (purple border) to target it, then add your blocks.
</div>
<?php endif; ?>

<!-- ── Canvas ── -->
<div id="editor-frame">
    <!-- #canvas-sizer carries the ZOOMED footprint. A CSS transform does not
         change layout size, so without this the frame could not scroll to the
         far edge of a canvas zoomed past the viewport. -->
    <div id="canvas-sizer" style="flex-shrink:0;">
        <div id="builder-canvas"></div>
    </div>
</div>

<!-- ── Inspector panel ──
     Not emitted when this Builder is read-only. The file used to claim, twice,
     that "every control that would have changed something is simply not on the
     page"; the control bar honoured that and this did not, so a read-only page
     still shipped the whole inspector, both editor modals and the write handlers
     behind them. Nothing could reach them — a read-only page cannot select a
     block, so `activeBlock` stays null and every one of those handlers returns —
     but that is the braces, and this comment was promising a belt.

     Two things follow, and the JavaScript is written to expect both: any lookup
     of a node in here can come back null, and any function that only exists to
     drive one of these controls is now unreachable rather than merely inert. -->
<?php if (!$readOnly): ?>
<div id="inspector">
    <h3 id="insp-title">Block</h3>

    <!-- Position + size (always visible when block selected) -->
    <div id="insp-dims">
        <div class="insp-row">
            <div>
                <label>X (px)</label>
                <input type="number" id="insp-x" min="-<?= intval($canvasW) ?>" max="<?= intval($canvasW) ?>" onchange="applyPos('x',this.value)">
            </div>
            <div>
                <label>Y (px)</label>
                <input type="number" id="insp-y" min="-<?= intval($canvasH) ?>" max="<?= intval($canvasH) ?>" onchange="applyPos('y',this.value)">
            </div>
        </div>
        <div class="insp-row" style="margin-top:4px;">
            <div>
                <label>W (px)</label>
                <input type="number" id="insp-w" min="40" max="<?= intval($canvasW) ?>" onchange="applyDim('w',this.value)">
            </div>
            <div>
                <label>H (px)</label>
                <input type="number" id="insp-h" min="24" max="<?= intval($canvasH) ?>" onchange="applyDim('h',this.value)">
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
            <!-- Shown only for a stored colour the browser could not read (#41). A
                 colour input has no way to display one — it falls back to black and
                 looks like a deliberate choice — so the swatch is not evidence of
                 anything here and the sentence has to say so. -->
            <div id="font-color-unread" style="display:none; grid-column:1 / -1;
                 font-size:11px; line-height:1.4; color:#e67e22; margin-top:4px;">
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
<?php endif; ?>

<div id="toast"></div>

<div id="upload-status">
    <div class="up-label"></div>
    <div class="up-track"><div class="up-fill"></div></div>
</div>

<script>
// ============================================================
// CONSTANTS (injected by PHP)
// ============================================================
var IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
var SITE_NAME = <?= HttpReply::jsValue(SITE_NAME) ?>;
var CSRF_TOKEN = <?= HttpReply::jsValue(csrfToken()) ?>;

// The Display being edited. Its canvas size was fixed at creation (ADR-0004), so
// these are constants for the life of the page — every bound, clamp and default
// below is derived from them rather than from a hardcoded 1920×1080.
var DISPLAY_TAG   = <?= HttpReply::jsValue($display->tag()) ?>;
// The record this page was actually opened on. The tag above addresses it, but an
// admin may rename a tag and hand the old one to another sign, so every call below
// sends both and the server refuses any that disagree (DisplayRequest::ID_PARAM).
var DISPLAY_ID    = <?= intval($display->id()) ?>;
var DISPLAY_TITLE = <?= HttpReply::jsValue($display->title()) ?>;
var CANVAS_W      = <?= intval($canvasW) ?>;
var CANVAS_H      = <?= intval($canvasH) ?>;

// Whether somebody else holds this Display's edit lock (ADR-0007). Decided by the
// server before this page was built, and constant for its life: every control that
// would change something is absent from the HTML rather than disabled here, and the
// guards below are the belt to that braces — for the keyboard, and for anything
// reachable without a button.
var READ_ONLY   = <?= $readOnly ? 'true' : 'false' ?>;
var LOCK_HOLDER = <?= HttpReply::jsValue($lockHolder) ?>;

// The idle window and its warning, from the one place they are defined
// (LockState) rather than a second copy that could drift away from it.
var LOCK_LAPSE_SECONDS = <?= LockState::IDLE_LAPSE_SECONDS ?>;
var LOCK_WARN_SECONDS  = <?= LockState::WARN_AFTER_SECONDS ?>;

// The largest file that can actually reach this server, from UploadLimit — which
// is the smallest of the app's own 50 MB ceiling and PHP's two. The browser knows
// how big a chosen file is before sending a byte, so a file that cannot arrive is
// refused in the file picker rather than after two minutes of uploading it. The
// server checks the same number again; this only saves the wait.
var UPLOAD_MAX_BYTES = <?= intval(UploadLimit::bytes()) ?>;
var UPLOAD_MAX_LABEL = <?= HttpReply::jsValue(UploadLimit::describe()) ?>;

// Editor zoom. The canvas is CSS-scaled, so interact.js deltas — which arrive in
// screen pixels — are divided by ZOOM before becoming canvas coordinates. Miss one
// of those divisions and dragging drifts at any zoom but 100%.
var ZOOM = 1;

// The layout stamp this editor loaded (docs/adr/0006). Publish submits it back;
// if the display has changed since — someone else published, or an element was
// hidden or deleted in the admin panel — the publish is refused instead of
// overwriting their work. There is no undo, so refusing is the safety net.
// Empty until loadLayout() runs, and a publish without it is refused by design.
var LAYOUT_STAMP = '';

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
    marquee:        { w:CANVAS_W, h:60  },
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
    // No role test here on purpose: this ran on every page load with the emit
    // condition spelled out a second time, and a lookup that survives only while
    // two copies of a rule agree is the one this page has already been bitten by.
    // A throw on this line would also cost the two calls below it — the fit, and
    // the lock watch that is how a read-only page ever learns it lost the sign.
    showSectionBanner();
    // After the banner, so the fit measures the frame at its final height.
    zoomToFit();
    setupLockWatch();
});

// ============================================================
// ZOOM
// ============================================================
// A Display's canvas can be larger or taller than the editor window — a portrait
// 1080×1920 does not fit at all — so the canvas is CSS-scaled and #canvas-sizer
// carries the scaled footprint so the frame can still scroll.
//
// Every place a screen-pixel measurement becomes a canvas coordinate divides by
// ZOOM: handleMove, handleResize, getCanvasDropCenter. Nothing else needs to know.

var ZOOM_MIN = 0.1;
var ZOOM_MAX = 3;
var ZOOM_PAD = 80;   // #editor-frame's 40px padding, both sides

function applyZoom(z) {
    ZOOM = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, z));
    var canvas = document.getElementById('builder-canvas');
    var sizer  = document.getElementById('canvas-sizer');
    canvas.style.transform = (ZOOM === 1) ? 'none' : 'scale(' + ZOOM + ')';
    sizer.style.width  = Math.round(CANVAS_W * ZOOM) + 'px';
    sizer.style.height = Math.round(CANVAS_H * ZOOM) + 'px';
    var readout = document.getElementById('zoom-readout');
    if (readout) readout.textContent = Math.round(ZOOM * 100) + '%';
}

/** Largest zoom that shows the whole canvas, never magnifying past 100%. */
function zoomToFit() {
    var frame = document.getElementById('editor-frame');
    var z = Math.min(
        (frame.clientWidth  - ZOOM_PAD) / CANVAS_W,
        (frame.clientHeight - ZOOM_PAD) / CANVAS_H
    );
    applyZoom(Math.min(1, z));
}

function nudgeZoom(direction) {
    applyZoom(ZOOM * (direction > 0 ? 1.25 : 0.8));
}

// ============================================================
// LOAD
// ============================================================
function loadAssets() {
    return fetch('api.php?action=get_assets')
        .then(function(r){ return r.json(); })
        .then(function(list) {
            assetsCache = list;
            var sel = document.getElementById('asset-link');
            // The dropdown lives in the inspector, which a read-only page does
            // not have. The cache is still worth filling: a block that points at
            // a library entry renders from it.
            if (!sel) { return; }
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
    // The editing read, not the Viewer's: a Display that has been turned off is a
    // notice on a Screen but must still open here (CONTEXT.md), and get_layout
    // deliberately returns nothing for one.
    return fetch('api.php?action=get_editor_layout&display=' + encodeURIComponent(DISPLAY_TAG)
                 + '&display_id=' + DISPLAY_ID)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data || data.status !== 'success') {
                // The Display was deleted or renamed while this tab sat open. Its
                // layout is gone; publishing what is on screen would recreate it
                // under a Display that no longer exists.
                showToast(((data && data.message) || 'That display could not be loaded.')
                          + ' Reload to choose a display.', true);
                return;
            }
            blockStyles  = data.block_styles || {};
            LAYOUT_STAMP = data.layout_stamp || '';
            var canvas   = document.getElementById('builder-canvas');

            if (data.display) {
                var s = data.display;
                document.getElementById('bg-type') && (document.getElementById('bg-type').value = s.bg_type);
                if (s.bg_type === 'color') {
                    document.getElementById('bg-color') && (document.getElementById('bg-color').value = s.bg_val);
                    canvas.style.backgroundColor = s.bg_val;
                    canvas.style.backgroundImage = 'none';
                    applyBg();
                } else {
                    canvas.style.backgroundImage = "url('"+s.bg_val+"')";
                }
                toggleBgInputs();   // a no-op unless this page has the controls
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
// The background controls are an admin's, and only while the page can edit: the
// markup emits them on `$isAdmin && !$readOnly` and on nothing else. These three
// used to say that back in JavaScript — `if (!IS_ADMIN || READ_ONLY) return` — and
// two of them are called by loadLayout(), so that copy of the rule ran on every
// page load. It was right, and being right is not the point: the same shape one
// storey up is what threw on every canvas click for a read-only basic account.
// So each one asks for its control and gives up if it is not there. The markup
// decides who gets a background picker; nothing here needs to know.
function toggleBgInputs() {
    var type  = document.getElementById('bg-type');
    var color = document.getElementById('bg-color');
    var file  = document.getElementById('bg-file');
    if (!type || !color || !file) return;
    color.style.display = type.value==='color' ? 'inline-block' : 'none';
    file.style.display  = type.value==='image' ? 'inline-block' : 'none';
}
function applyBg() {
    var color = document.getElementById('bg-color');
    if (!color) return;
    var canvas = document.getElementById('builder-canvas');
    canvas.style.backgroundColor = color.value;
    canvas.style.backgroundImage = 'none';
}
function applyBgFile() {
    var input = document.getElementById('bg-file');
    if (!input) return;
    var f = input.files[0];
    if (!f) return;
    var r = new FileReader();
    r.onload = function(e){ document.getElementById('builder-canvas').style.backgroundImage = "url('"+e.target.result+"')"; };
    r.readAsDataURL(f);
}

// ============================================================
// CREATE SECTION (admin)
// ============================================================
function createSection() {
    if (!IS_ADMIN || READ_ONLY) return;
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
    if (!el.locked && IS_ADMIN && !READ_ONLY) s.classList.add('draggable-block');
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
        if (READ_ONLY) return;   // no selecting, no targeting, no inspector
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
    if (READ_ONLY) return;
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
    if (!el.locked && (IS_ADMIN || isChildBlock) && !READ_ONLY) block.classList.add('draggable-block');
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
        // Not even editable in a read-only Builder: the dblclick that turns pointer
        // events on is guarded too, but a contenteditable node is one stray focus
        // away from accepting typing that could never be published.
        inner.contentEditable = READ_ONLY ? 'false' : 'true';
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
        // Typing breaks the link to the library entry, exactly as uploadBlockImage,
        // uploadBlockVideo and changeImageFit already do for their own content.
        //
        // This is load-bearing, not tidiness. Publishing pools a text block's
        // content into `assets` and nulls the element's own copy, so after one
        // publish every text block comes back asset-linked — and publishCanvas
        // only collects content for a block with no asset. Without this line the
        // second edit of a price is dropped in the browser, the toast still says
        // Published, and the sign keeps the old number. It also means editing one
        // sign never rewrites a library entry other Displays are sharing.
        inner.addEventListener('input', function() {
            if (!block.dataset.assetId) return;
            block.dataset.assetId = '';
            if (block === activeBlock) {
                var _link = document.getElementById('asset-link');
                if (_link) _link.value = '';
            }
        });
        block.addEventListener('dblclick', function(e) {
            if (READ_ONLY || block.dataset.locked === '1' || _shiftDown || e.target.closest('.rh')) return;
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
        // A video with no file gets the drawn placeholder an image already gets,
        // and for a reason that is now load-bearing: the Viewer draws nothing at
        // all for it (#45), so this is the only surface left that shows the block
        // exists. An empty <video> is a rectangle the author has to remember.
        else { vid.poster = svgPlaceholder(el.width, el.height, 'Video'); }
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
        if (READ_ONLY) return;               // no selecting, so no inspector to reach
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
        applyStoredColor(block, bs.font_color);
        block.style.fontWeight  = bs.font_weight;
        block.style.fontStyle   = bs.font_style;
        block.style.lineHeight  = bs.line_height;
    } else {
        block.style.fontFamily  = el.font_family  || 'Arial';
        block.style.fontSize    = (el.font_size||16) + 'px';
        applyStoredColor(block, el.font_color);
        block.style.fontWeight  = el.font_weight  || 'normal';
        block.style.fontStyle   = el.font_style   || 'normal';
        block.style.lineHeight  = el.line_height  || 1.4;
    }
}

// Put a stored colour on a block without losing it if it cannot be read (#41).
//
// The block has to render, so an unreadable colour still gets the default on
// screen — but the value that was actually stored is kept on the element, and
// collectElements() publishes *that* rather than what the swatch ended up showing.
// The publish path then refuses it and names the block (LayoutRules), which is how
// an admin finds out at all. Anything else is this app quietly deciding that an
// unreadable colour means black.
//
// A readable colour clears the marker, so a block whose colour was fixed stops
// carrying the old bad value the moment the fix is loaded back.
function applyStoredColor(block, stored) {
    var raw = (stored === null || stored === undefined) ? '' : String(stored);
    var hex = readHex(raw);
    if (raw !== '' && hex === '') {
        block.dataset.colorUnread = raw;
    } else {
        delete block.dataset.colorUnread;
    }
    block.style.color = hex || '#000000';
}

// Say so in the inspector when the selected block carries a colour nobody can read.
//
// Without this the only way to find out is to press Publish and read the refusal,
// and the swatch actively misleads in the meantime: a colour input given a value it
// cannot parse shows #000000, which is indistinguishable from somebody having
// chosen black.
//
// Null-guarded because the inspector is not sent at all to a page that may not edit
// (§4j) — a lookup for a control that isn't there is exactly the defect
// tools/selftest_builder_readonly.js exists to catch (#40's subject too).
//
// `textContent`, never innerHTML: the value being quoted came out of the database
// and is under nobody's control here.
function showUnreadableColor(block) {
    var note = document.getElementById('font-color-unread');
    if (!note) return;
    var stored = (block && block.dataset) ? (block.dataset.colorUnread || '') : '';
    if (!stored) {
        note.style.display = 'none';
        note.textContent = '';
        return;
    }
    if (stored.length > 40) { stored = stored.slice(0, 40) + '…'; }
    note.textContent = 'The saved colour for this block (' + stored + ') is not one this app can '
                     + 'read, so the swatch is showing black rather than what is saved. Choose a '
                     + 'colour to replace it — publishing is refused until you do.';
    note.style.display = 'block';
}

// ============================================================
// SELECTION (single)
// ============================================================
function selectBlock(block) {
    if (READ_ONLY) return;
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
    // Both panels are absent on a read-only page, and this runs on every click in
    // the canvas area — including there, where there is nothing to deselect but
    // the handler still fires.
    var insp = document.getElementById('inspector');
    if (insp) { insp.style.display = 'none'; }
    var bar = document.getElementById('align-bar');
    if (bar && multiSel.length === 0) { bar.style.display = 'none'; }
}

function showInspector(block) {
    var insp = document.getElementById('inspector');
    if (!insp) { return; }              // read-only: nothing to show it in
    updateAlignBar(); // keep screen-align bar visible while a block is selected
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
        document.getElementById('font-color').value   = readHex(block.style.color) || '#000000';
        showUnreadableColor(block);
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
    var _insp = document.getElementById('inspector');
    if (_insp) { _insp.style.display = 'none'; }

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
    if (!bar || !cnt) { return; }       // read-only: the align bar is not in the page
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
        w: (p === canvas) ? CANVAS_W : p.offsetWidth,
        h: (p === canvas) ? CANVAS_H : p.offsetHeight
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
// The banner is a basic account's instruction for adding blocks, so it is in the
// page only when the account is basic AND the page can edit. These functions
// tested the role and not the lock — and clearTargetSection() runs on every click
// in the canvas area, so a read-only basic account threw an uncaught TypeError on
// every click, from exactly the unguarded lookup this file claims cannot exist.
//
// So both reaches for that node — the write below and the reveal at page load —
// ask whether it is there rather than re-deriving the emit condition. A second
// copy of that rule in JavaScript is what broke here, not the role test being the
// wrong half of it. The markup decides; these only have to survive the answer.
function setSectionBanner(text) {
    var el = document.getElementById('section-banner');
    if (el) { el.textContent = text; }
}

function showSectionBanner() {
    var el = document.getElementById('section-banner');
    if (el) { el.style.display = 'block'; }
}

function setTargetSection(sectionEl) {
    if (targetSection) targetSection.classList.remove('targeted');
    targetSection = sectionEl;
    if (targetSection) {
        targetSection.classList.add('targeted');
        setSectionBanner('Section selected — now add a block from the bar above.');
    }
}

function clearTargetSection() {
    if (targetSection) targetSection.classList.remove('targeted');
    targetSection = null;
    setSectionBanner('Click on a section (purple border) to target it, then add your blocks.');
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
        if (e.key === 'Delete' && !READ_ONLY) {
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
    // Choosing a colour is the deliberate act that retires an unreadable stored one
    // (#41). Until it happens the block keeps publishing the value it came with, so
    // the refusal keeps coming back — which is the point: something has to change,
    // and it has to be somebody's decision rather than this function's.
    if (prop === 'color') { delete activeBlock.dataset.colorUnread; }
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
    if (!IS_ADMIN || !activeBlock) return;
    var target = activeBlock;
    startUpload(input, 'upload_file', 'section background', function (path) {
        // Read the fit *now*, not when the upload started: the inspector may have
        // been touched during the upload, and the block may no longer be selected
        // at all — which is why the block is captured above rather than read from
        // activeBlock in here.
        var fit = (document.getElementById('section-bg-fit') || {}).value || 'cover';
        target.style.backgroundImage = "url('" + path + "')";
        target.dataset.sectionBg = path;
        target.dataset.bgFit = fit;
        applySectionBgFit(target, fit);
        var preview = document.getElementById('section-bg-preview');
        if (preview) preview.textContent = path;
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
// UPLOADS — one path, and it always says what happened
// ============================================================
// There were four of these, each with its own fetch chain, and three of them had
// no `.catch()` at all. What that meant on the shop floor: an admin picks a 60 MB
// clip on the store's Wi-Fi, the request dies, and *nothing else ever runs*. The
// "Uploading video…" toast fades after three and a half seconds and that is the
// last word on the subject. They publish, get a green "Published to Deli Board",
// and the sign shows an empty rectangle where the video should be. The image and
// section-background handlers were worse in one respect — no toast at all, so a
// failed upload looked exactly like one still in progress.
//
// `r.json()` was a second silent failure on the same line: it rejects on any reply
// that is not JSON, which is what a file over the server's post_max_size produced.
//
// So: one function, XMLHttpRequest rather than fetch (fetch cannot report upload
// progress, and progress is half of what was missing), and every way this can end
// has a branch that puts words on the screen:
//
//   · too big to send      — refused in the picker, before a byte leaves
//   · network dies         — onerror
//   · browser gives up     — ontimeout
//   · server says no       — status ≥ 400, or a JSON error message
//   · reply is not JSON    — the post_max_size case, and any PHP output above it
//   · saved                — the caller's success branch
//
// Two details that are not decoration. The file input is cleared at the end of
// every attempt: without that, choosing the *same* file again fires no change
// event, so the obvious response to a failed upload — try it again — did nothing
// whatsoever. And a second upload on the same input while one is in flight is
// refused, because both would write to the same block and the slower one wins.

/** The upload currently in flight per input, so a second pick cannot race it. */
var uploadsInFlight = 0;

function showUploadProgress(label, percent) {
    // Guarded the same way every other lookup in this file is: the readout is
    // markup, and an upload must still run — and still report — on a page where
    // that markup is missing. Progress is the nicety; the message is not.
    var box = document.getElementById('upload-status');
    if (!box) return;
    var text = box.querySelector('.up-label');
    var fill = box.querySelector('.up-fill');
    if (text) text.textContent = label;
    if (fill) fill.style.width = (percent === null ? 100 : percent) + '%';
    box.style.display = 'block';
}

function hideUploadProgress() {
    var box = document.getElementById('upload-status');
    if (box) box.style.display = 'none';
}

/**
 * Send the file chosen in `input` to `action`, and call onSuccess(path) if and
 * only if the server saved it. Every other outcome is reported to the user here.
 *
 * `what` is the noun for the messages ("video", "slide image").
 */
function startUpload(input, action, what, onSuccess) {
    if (READ_ONLY) return;

    var file = input.files && input.files[0];
    if (!file) return;

    // Refused before sending. The browser knows the size; the server would only
    // find out after receiving all of it, and on a host whose post_max_size is
    // smaller than the file the request arrives with its body thrown away and no
    // way to tell that apart from a missing security token.
    if (file.size > UPLOAD_MAX_BYTES) {
        showToast('That ' + what + ' is too large (' + describeBytes(file.size) + '). '
                + 'This server accepts up to ' + UPLOAD_MAX_LABEL + '.', true);
        input.value = '';
        return;
    }
    if (file.size === 0) {
        showToast('That file is empty — nothing was uploaded.', true);
        input.value = '';
        return;
    }

    if (input._uploading) {
        showToast('That ' + what + ' is still uploading. Wait for it to finish.', true);
        return;
    }
    input._uploading = true;
    uploadsInFlight++;

    var fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', CSRF_TOKEN);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?action=' + action, true);
    xhr.timeout = 600000;   // ten minutes: a 50 MB video on shop Wi-Fi is slow, not broken

    var finished = false;
    function done(message, isError) {
        if (finished) return;      // ontimeout and onerror can both arrive
        finished = true;
        input._uploading = false;
        uploadsInFlight--;
        // Cleared whatever happened, so picking the same file again is an action
        // the browser will actually report.
        input.value = '';
        if (uploadsInFlight <= 0) { uploadsInFlight = 0; hideUploadProgress(); }
        if (message) showToast(message, !!isError);
    }

    if (xhr.upload) {
        xhr.upload.onprogress = function (e) {
            if (finished) return;
            var pct = e.lengthComputable ? Math.round((e.loaded / e.total) * 100) : null;
            showUploadProgress('Uploading ' + what + '… ' + (pct === null ? '' : pct + '%'), pct);
        };
    }
    showUploadProgress('Uploading ' + what + '… 0%', 0);

    xhr.onload = function () {
        if (xhr.status === 0 || xhr.status >= 400) {
            // A JSON body is still the most useful thing here — api.php answers a
            // dropped request body with 413 and an explanation.
            var said = readJsonMessage(xhr.responseText);
            done(said || ('The server refused the ' + what + ' (error ' + xhr.status + '). Nothing was changed.'), true);
            return;
        }
        var res = null;
        try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }
        if (!res) {
            done('The server\'s reply to that ' + what + ' could not be read, so nothing was changed. '
               + 'It may be larger than this server accepts (' + UPLOAD_MAX_LABEL + ').', true);
            return;
        }
        if (res.status !== 'success' || !res.path) {
            done(res.message || ('That ' + what + ' was not saved.'), true);
            return;
        }
        done('', false);
        onSuccess(res.path);
    };

    xhr.onerror = function () {
        done('The ' + what + ' did not upload — the connection dropped. Nothing was changed; try again.', true);
    };
    xhr.ontimeout = function () {
        done('The ' + what + ' was still uploading after ten minutes and was given up on. Nothing was changed.', true);
    };
    xhr.onabort = function () {
        done('That ' + what + ' upload was cancelled. Nothing was changed.', true);
    };

    xhr.send(fd);
}

/** A JSON error message out of a response body, or '' if there isn't one. */
function readJsonMessage(text) {
    try {
        var res = JSON.parse(text);
        return (res && res.message) ? res.message : '';
    } catch (e) {
        return '';
    }
}

/** Bytes as words, matching UploadLimit::describeBytes so both agree. */
function describeBytes(bytes) {
    if (bytes >= 1048576) return Math.floor(bytes / 1048576) + ' MB';
    if (bytes >= 1024)    return Math.floor(bytes / 1024) + ' KB';
    return bytes + ' bytes';
}

function uploadBlockImage(input) {
    if (!activeBlock) return;
    var target = activeBlock;
    startUpload(input, 'upload_file', 'image', function (path) {
        var img = target.querySelector('img');
        if (img) img.src = path;
        target.dataset.manualPath = path;
        target.dataset.assetId    = '';
        var link = document.getElementById('asset-link');
        if (link) link.value = '';
        showToast('Image uploaded. Publish to put it on the sign.');
    });
}

function uploadBlockVideo(input) {
    if (!IS_ADMIN || !activeBlock) return;
    var target = activeBlock;
    startUpload(input, 'upload_video', 'video', function (path) {
        var vid = target.querySelector('video');
        if (!vid) { showToast('That block is no longer a video block. The file uploaded but was not used.', true); return; }
        vid.innerHTML = '';
        vid.poster = '';   // there is a file now; the "Video" placeholder comes off
        var src = document.createElement('source');
        src.src = path; vid.appendChild(src); vid.load();
        target.dataset.manualPath = path;
        target.dataset.assetId    = '';
        var link = document.getElementById('asset-link');
        if (link) link.value = '';
        showToast('Video uploaded. Publish to put it on the sign.');
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
        vid.poster = '';   // as above: a linked asset is a file
        var src = document.createElement('source');
        src.src = match.content; vid.appendChild(src); vid.load();
    }
}

// ============================================================
// DELETE
// ============================================================
function deleteSelected() {
    if (READ_ONLY) return;
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
    // There is no Publish button on a read-only page. The server refuses this
    // anyway (LayoutStore checks the lock inside the publish transaction) — this is
    // just not making the round trip to be told so.
    if (READ_ONLY) { showToast(LOCK_HOLDER + ' is editing this display — nothing can be published from here.', true); return; }

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
            // The stored value wins while it is still unreadable (#41), so a publish
            // cannot quietly replace a colour nobody could read with black. It clears
            // the moment somebody picks a colour — see updateStyle(). A block that
            // simply never had a colour still publishes '#000000', exactly as before.
            font_color:     block.dataset.colorUnread || readHex(block.style.color) || '#000000',
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
    fd.append('display', DISPLAY_TAG);
    fd.append('display_id', DISPLAY_ID);
    fd.append('layout_stamp', LAYOUT_STAMP);

    if (IS_ADMIN) {
        fd.append('bg_type', document.getElementById('bg-type').value);
        fd.append('bg_val',  document.getElementById('bg-color').value);
        var bgFile = document.getElementById('bg-file').files[0];
        // Checked before sending, and the publish is abandoned rather than
        // attempted: a background file over the server's post_max_size takes the
        // whole request body with it, so this would not be one rejected image, it
        // would be the entire layout not saved.
        if (bgFile && bgFile.size > UPLOAD_MAX_BYTES) {
            showToast('That background image is too large (' + describeBytes(bgFile.size) + '). '
                    + 'This server accepts up to ' + UPLOAD_MAX_LABEL + '. Nothing was published — '
                    + 'choose a smaller image, or clear it and publish again.', true);
            return;
        }
        if (bgFile) fd.append('bg_file', bgFile);
    }

    fetch('api.php?action=publish', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.status === 'success') {
                // Adopt the stamp this publish created, so a second publish from
                // this same tab is not mistaken for a stale one.
                LAYOUT_STAMP = res.layout_stamp || LAYOUT_STAMP;
                // Named, because there is more than one sign now and "published!"
                // does not tell you which one you just changed.
                showToast('Published to ' + DISPLAY_TITLE + ' (' + DISPLAY_TAG + '). '
                          + 'That screen updates within 30 seconds.');
                loadAssets();
            } else if (res.reason === 'stale' || res.reason === 'locked'
                       || res.reason === 'invalid' || res.reason === 'busy'
                       || isTerminalLockReason(res.reason)) {
                // Nothing was saved, and none of these refusals may be glimpsed and
                // missed: the layout on screen is still the editor's, and what to do
                // next differs — reload for a stale stamp, wait for somebody else's
                // edit lock, fix the named block for a layout the server would not
                // store, publish again in a moment for a collision, reload for a
                // screen name tag that moved, ask an admin for a display that is no
                // longer yours, sign in again for an account that no longer may. The
                // message says which; a toast would not be read.
                //
                // 'invalid' and 'busy' are here rather than in the toast branch for
                // the same reason as the rest: a publish that was refused looks
                // exactly like one that worked if the only trace is a toast that
                // has already faded, and the next thing somebody does with a sign
                // they believe they published is walk away from it.
                //
                // The terminal ones also raise the bar, so it is still on screen after
                // the alert is dismissed. Reaching one here rather than from a beat
                // means the publish arrived inside the same minute as the change —
                // otherwise the bar is already up and this branch is the second telling.
                if (res.reason === 'locked') { lockLost = true; renderLockBars(); }
                if (isTerminalLockReason(res.reason)) { noteAccessLost(res.reason); }
                alert(res.message);
            } else { showToast(res.message||'Publish failed.', true); }
        })
        .catch(function(){ showToast('Network error.', true); });
}

// ============================================================
// EDIT LOCK (ADR-0007)
// ============================================================
// One account edits a Display at a time, and the lock is held by *work* rather than
// by this tab being open. So the only thing tracked here is when something real last
// happened, and every heartbeat sends that *age* rather than "now": a tab forgotten
// on a back-office monitor keeps beating and still loses the display on time, while
// somebody reading, thinking or typing slowly keeps it.
//
// Read-only is not managed here at all — the server decided it before this page was
// built and READ_ONLY never changes. What can change is losing a lock that was held:
// an admin took over, or it lapsed and somebody else claimed it. ADR-0007 keeps the
// unsaved edits on screen for that case and lets the publish be refused, rather than
// pulling the editor apart underneath the person using it.

var LOCK_BEAT_MS = 60000;   // the most often the server hears from us
var LOCK_TICK_MS = 15000;   // how often the bars are re-decided from the local clock
var LOCK_POLL_MS = 30000;   // read-only: how often we check whether it has freed up

var lastInteraction = Date.now();
var lastBeatAt      = Date.now();
var lockLost        = false;   // somebody else holds it — never claim again
// Not the same thing, and kept apart on purpose: a lock can come back on its own, and
// losing the display cannot. There are five ways to get here and none of them mends
// itself — see LOCK_TERMINAL. In four of them the server has already stopped believing
// this page holds the sign; in the fifth, a renamed tag, the lock is deliberately still
// this account's and a reload picks it straight back up.
var accessLost      = false;

/**
 * The refusals that never succeed later, and what to tell somebody mid-edit.
 *
 * A failed request is normally nothing to act on: the next one covers it, which is
 * exactly right for a dropped connection. These five are the opposite — the display
 * has stopped being this page's to edit, and no amount of waiting changes that. Each
 * one used to be swallowed, so the person kept working on a sign they had already
 * lost and heard about it at the publish.
 *
 * A fixed list rather than "anything with a reason": a reason added to the server
 * later must not silently become fatal to an editor mid-work. Anything not named here
 * is still ignored.
 *
 * The wording is the editor's, not the Screen's. The server sends a sentence for a
 * sign in a shop window — "This display is turned off" — and somebody who has been
 * laying out prices for twenty minutes needs to know what happened to their work.
 */
var LOCK_TERMINAL = {
    forbidden: '<strong>Your access to this display has been removed.</strong> '
             + 'An admin has taken it off your list, so nothing here can be published any more and '
             + 'the display has been released for somebody else. What you have done is still on this '
             + 'screen — copy anything you need before you leave the page. Ask an admin if this was '
             + 'not expected.',
    inactive:  '<strong>This display has been turned off.</strong> '
             + 'An admin has retired it, so it is no longer yours to edit and nothing here can be '
             + 'published. What you have done is still on this screen — copy anything you need '
             + 'before you leave the page. Nothing you had not published reached the screen.',
    // One message for two causes, because "not found at this address" cannot tell them
    // apart: a renamed screen name tag and a deleted display answer identically. So it
    // says both and sends them to the one action that distinguishes them.
    unknown:   '<strong>This display is no longer at this address.</strong> '
             + 'Its screen name tag has been renamed, or the display has been deleted. Reload the '
             + 'page to find out which — if it was renamed it is still yours, and still where you '
             + 'left it. Copy anything you cannot afford to lose first. Nothing you had not '
             + 'published reached a screen.',
    mismatch:  '<strong>This page\'s address now belongs to a different display.</strong> '
             + 'A screen name tag was renamed and given to another sign, so nothing here can be '
             + 'saved — publishing would write over somebody else\'s layout. Copy anything you need, '
             + 'then reload and open your display again.',
    signed_out:'<strong>You have been signed out.</strong> '
             + 'This account can no longer sign in — an admin may have suspended it. Nothing here '
             + 'can be published. What you have done is still on this screen, so copy anything you '
             + 'need before you leave the page, and ask an admin if this was not expected.'
};

/** Is this refusal one there is no point waiting out? */
function isTerminalLockReason(reason) {
    return !!reason && Object.prototype.hasOwnProperty.call(LOCK_TERMINAL, reason);
}

// The server's own idea of how idle this lock is, and when it said so. It knows
// about every tab this account has open on this Display, which this tab does not:
// without it, a second tab left sitting on the same sign would show its owner an
// idle warning while they were busy working in the first one.
var serverIdle       = 0;
var serverAnsweredAt = 0;

/**
 * How long since the last real interaction, in seconds — the figure the bars are
 * drawn from and the figure a heartbeat carries.
 *
 * The lower of this tab's own clock and the server's, aged forward since it
 * answered. Whichever of the two saw work most recently is the one telling the
 * truth about whether anybody is editing this Display.
 */
function lockIdleSeconds() {
    var local = Math.round((Date.now() - lastInteraction) / 1000);
    if (serverAnsweredAt === 0) { return local; }
    return Math.min(local, serverIdle + Math.round((Date.now() - serverAnsweredAt) / 1000));
}

function setupLockWatch() {
    if (READ_ONLY) {
        // Watch for the display freeing up, so the offer to reload is a fact rather
        // than a guess. Never claims it: taking a lock the moment its holder pauses
        // is exactly what "an active editor is never interrupted" rules out.
        setInterval(pollLockState, LOCK_POLL_MS);
        return;
    }
    // A click, a key, an edit — the interactions ADR-0007 counts as work. Captured on
    // the document so nothing downstream can stop propagation and starve the lock,
    // and deliberately not `mousemove`: presence is not work, and mouse drift would
    // hold a sign for as long as somebody left a cat on the desk.
    document.addEventListener('pointerdown', noteInteraction, true);
    document.addEventListener('keydown',     noteInteraction, true);
    document.addEventListener('input',       noteInteraction, true);
    window.addEventListener('pagehide', releaseLockOnLeave);
    setInterval(lockTick, LOCK_TICK_MS);
}

function noteInteraction() {
    var wasQuiet = (Date.now() - lastInteraction) > LOCK_BEAT_MS;
    lastInteraction = Date.now();
    // Coming back from a quiet spell is the one moment worth an immediate beat: the
    // lock may have lapsed, and taking it back now is what stops a colleague
    // starting on a display somebody is working on again. Any bar on screen implies
    // a long quiet spell, so this is also the only time they need re-deciding.
    if (wasQuiet) { holdLock(); renderLockBars(); }
}

function lockTick() {
    renderLockBars();
    if (!lockLost && (Date.now() - lastBeatAt) >= LOCK_BEAT_MS) { holdLock(); }
}

/** Take the lock, keep it, or take it back — one endpoint, as one question. */
function holdLock() {
    if (READ_ONLY || lockLost || accessLost) { return; }
    lastBeatAt = Date.now();
    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('display', DISPLAY_TAG);
    fd.append('display_id', DISPLAY_ID);
    // The true age of the last interaction, so a beat can never quietly extend a
    // lock on a display nobody has touched.
    fd.append('idle_seconds', lockIdleSeconds());
    fetch('api.php?action=hold_lock', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(applyLockAnswer)
        .catch(function(){});   // a missed beat is covered by the next one
}

function applyLockAnswer(res) {
    if (!res) { return; }
    if (res.status !== 'success') {
        // A failed beat is normally nothing to act on — the next one covers it. The
        // terminal refusals are not: the display has stopped being this page's to
        // edit and no later beat will ever succeed. Swallowing them left somebody
        // editing a sign they had already lost, with the first word of it coming at
        // the publish.
        if (isTerminalLockReason(res.reason)) { noteAccessLost(res.reason); }
        return;
    }

    if (res.held_by_me) {
        serverIdle       = res.idle_seconds || 0;
        serverAnsweredAt = Date.now();
    }
    if (!res.held_by_other) { return; }
    // Lost it: somebody took over, or it lapsed and somebody else claimed it. The
    // canvas is left exactly as it is — publishing is what gets refused.
    lockLost = true;
    var who = document.getElementById('lock-lost-who');
    if (who) { who.textContent = res.held_by || 'Someone else'; }
    renderLockBars();
}

/**
 * This display is no longer this page's to edit. Say which way, and stop asking.
 *
 * The canvas is left alone, exactly as ADR-0007 leaves it when the lock moves on:
 * pulling the editor apart under somebody would lose work that is still on screen and
 * still theirs to copy out. What stops is the claiming — every beat from here would be
 * refused, and a page that keeps politely asking is a page that never says anything.
 *
 * First answer wins. Once one of these has been shown, a later beat's refusal is a
 * consequence of it rather than news, and rewriting the sentence underneath somebody
 * reading it would only make them doubt the first one.
 *
 * @param {string} reason a key of LOCK_TERMINAL. Anything else leaves the bar's
 *                        server-rendered wording alone rather than blanking it.
 */
function noteAccessLost(reason) {
    if (accessLost) { return; }
    accessLost = true;
    var text = document.getElementById('lock-access-text');
    if (text && LOCK_TERMINAL[reason]) { text.innerHTML = LOCK_TERMINAL[reason]; }
    // On a read-only page the banner above says somebody else is editing and offers a
    // reload once it frees up. Both were true a moment ago and neither is now, so it
    // goes: an offer to reload into a refusal is worse than no offer.
    var banner = document.getElementById('lock-banner');
    if (banner) { banner.style.display = 'none'; }
    renderLockBars();
}

/** The one click that keeps the lock, from the idle warning. */
function keepEditing() {
    lastInteraction = Date.now();
    holdLock();
    renderLockBars();
}

/** Which of the four bars belongs on screen, decided from the local idle age. */
function renderLockBars() {
    var idle = lockIdleSeconds();
    // Access first, and it hides the other three: "you have lost this display" and
    // "it will be released in two minutes" are both true once the display has stopped
    // being this page's, and only one of them is worth reading. The lost bar in
    // particular would name a holder there is not one of.
    showLockBar('lock-access-bar', accessLost);
    showLockBar('lock-lost-bar',   !accessLost && lockLost);
    showLockBar('lock-lapsed-bar', !accessLost && !lockLost && idle >= LOCK_LAPSE_SECONDS);
    showLockBar('lock-idle-bar',   !accessLost && !lockLost && idle >= LOCK_WARN_SECONDS && idle < LOCK_LAPSE_SECONDS);
    var mins = document.getElementById('lock-idle-mins');
    if (mins) { mins.textContent = Math.max(1, Math.round((LOCK_LAPSE_SECONDS - idle) / 60)); }
}

function showLockBar(id, on) {
    var el = document.getElementById(id);
    if (el) { el.style.display = on ? 'flex' : 'none'; }
}

/** Read-only: has the display freed up while we were watching? */
function pollLockState() {
    fetch('api.php?action=lock_state&display=' + encodeURIComponent(DISPLAY_TAG)
          + '&display_id=' + DISPLAY_ID)
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (!res) { return; }
            if (res.status !== 'success') {
                // Somebody watching a display can lose it as easily as somebody
                // editing one — retired, renamed, their own account suspended — and
                // the offer to reload would then be an offer to be refused. Same bar,
                // same sentences.
                if (isTerminalLockReason(res.reason)) { noteAccessLost(res.reason); }
                return;
            }
            // Shown when free and taken away again if somebody else starts, so the
            // offer to reload means what it says at the moment it is read.
            var hint = document.getElementById('lock-free-hint');
            if (hint) { hint.style.display = res.held_by_other ? 'none' : 'inline'; }
        })
        .catch(function(){});
}

/**
 * Hand the lock back as the page goes away, so the next person is not waiting out
 * the idle window for a display nobody is looking at.
 *
 * `pagehide` and `sendBeacon` because this is the one send that survives a page
 * being torn down. Best effort by nature — a closed lid or a flat battery sends
 * nothing at all, which is what the idle window is there for.
 */
function releaseLockOnLeave() {
    // accessLost included, whichever way it happened: either the change released the
    // lock already, or — a renamed tag — the lock is still this account's and the
    // address this beacon would name no longer resolves. Both make the send pointless,
    // and it would be refused by the same seam that refused the beat.
    if (READ_ONLY || lockLost || accessLost || !navigator.sendBeacon) { return; }
    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('display', DISPLAY_TAG);
    fd.append('display_id', DISPLAY_ID);
    navigator.sendBeacon('api.php?action=release_lock', fd);
}

/** An admin taking the display off whoever has it — ADR-0007's force-unlock. */
function takeOverEditing() {
    if (!IS_ADMIN) { return; }
    if (!confirm('Take over editing ' + DISPLAY_TITLE + '?\n\n'
        + LOCK_HOLDER + ' is editing it right now. Anything they have not published yet will be '
        + 'lost — they keep it on screen but will not be able to publish it once you take over.')) { return; }

    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('display', DISPLAY_TAG);
    fd.append('display_id', DISPLAY_ID);
    fetch('api.php?action=take_over_lock', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(res) {
            // Reload rather than enable the controls in place: read-only is a mode
            // this page was built in, so the way out of it is to build it again.
            if (res && res.held_by_me) { location.reload(); return; }
            showToast((res && res.message) || 'Could not take over editing.', true);
        })
        .catch(function(){ showToast('Network error.', true); });
}

// ============================================================
// INTERACT.JS – drag, resize, bounds
// ============================================================
function setupInteract() {
    // Nothing on the canvas moves or resizes in a read-only Builder. One return
    // covers sections, root blocks and child blocks — and covers a fourth
    // interactable added here later, which is the point of putting it here.
    if (READ_ONLY) { return; }

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
    // event.dx/dy are screen pixels; ZOOM converts them to canvas pixels.
    var x = (parseFloat(t.getAttribute('data-x'))||0) + event.dx / ZOOM;
    var y = (parseFloat(t.getAttribute('data-y'))||0) + event.dy / ZOOM;
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
    // event.deltaRect and event.rect are screen pixels; ZOOM converts to canvas.
    var x = (parseFloat(t.getAttribute('data-x'))||0) + event.deltaRect.left / ZOOM;
    var y = (parseFloat(t.getAttribute('data-y'))||0) + event.deltaRect.top  / ZOOM;
    t.style.width  = (event.rect.width  / ZOOM) + 'px';
    t.style.height = (event.rect.height / ZOOM) + 'px';
    t.style.transform = 'translate('+x+'px,'+y+'px)';
    t.setAttribute('data-x', x);
    t.setAttribute('data-y', y);

    var w = Math.round(event.rect.width  / ZOOM);
    var h = Math.round(event.rect.height / ZOOM);
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
    if (!p) return { w: CANVAS_W, h: CANVAS_H };
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
    if (which === 'x') x = isChild ? Math.max(0, Math.min(val, pb.w - bw)) : Math.max(0, Math.min(val, CANVAS_W - bw));
    else               y = isChild ? Math.max(0, Math.min(val, pb.h - bh)) : Math.max(0, Math.min(val, CANVAS_H - bh));
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
    // The frame's visual centre, expressed in canvas coordinates.
    var cx = Math.round((frame.scrollLeft + frame.clientWidth  / 2 - PAD) / ZOOM - defW / 2);
    var cy = Math.round((frame.scrollTop  + frame.clientHeight / 2 - PAD) / ZOOM - defH / 2);
    cx = Math.max(0, Math.min(cx, canvas.offsetWidth  - defW));
    cy = Math.max(0, Math.min(cy, canvas.offsetHeight - defH));
    return { x: cx, y: cy };
}

// A colour as `#rrggbb`, or '' when it is not one (#41).
//
// The old rgbToHex() answered '#000000' for anything it could not parse, and two
// callers believed it: the inspector swatch and the publish payload. That is the
// whole of #41. A stored colour the browser cannot read — from a hand-edited row,
// or from before the publish path checked (§4ab left colour semantics to this item)
// — is assigned to `block.style.color`, where the CSSOM **discards it silently**
// and leaves the property empty. rgbToHex('') then returned black, and the next
// publish wrote black over it. Nobody typed it, nothing reported it, and there is
// no undo. On a #1a1a2e canvas the block did not look recoloured; it looked gone.
//
// So this one refuses to invent. '' means "not a colour", and every caller decides
// what to do with that for itself — see applyTextStyles() and collectElements().
//
// It reads the two notations the CSSOM hands back as well as the one we store,
// because `block.style.color` is normalised by the browser and never comes back in
// the `#rrggbb` form it went in as. `rgba()` is included for the alpha the marquee
// controls can set; the alpha itself is dropped, which is what storing `#rrggbb`
// has always meant.
function readHex(value) {
    if (typeof value !== 'string') return '';
    var v = value.trim();
    if (/^#[0-9a-fA-F]{6}$/.test(v)) return v.toLowerCase();

    var m = v.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,[^)]*)?\)$/);
    if (!m) return '';
    var parts = [m[1], m[2], m[3]].map(function (n) { return parseInt(n, 10); });
    for (var i = 0; i < 3; i++) { if (parts[i] > 255) return ''; }
    return '#' + parts.map(function (n) { return ('0' + n.toString(16)).slice(-2); }).join('');
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
    // The only handler in the file that writes to the server without first
    // needing a selected block — so it is the one that could not be left to the
    // "nothing is selected, so nothing happens" argument. Its slide row is inside
    // the carousel modal, which a read-only page no longer has; the guard is what
    // makes that a rule rather than a consequence of the markup.
    if (READ_ONLY) return;
    var row = input.closest('.slide-row');
    startUpload(input, 'upload_file', 'slide image', function (path) {
        // The row can be removed while its image is uploading, so this is checked
        // here rather than assumed from the click that started it.
        if (!row || !row.parentNode) { showToast('That slide was removed. The file uploaded but was not used.', true); return; }
        var pi = row.querySelector('.slide-img-path');
        if (pi) pi.value = path;
        var pv = row.querySelector('.slide-img-preview');
        if (pv) pv.innerHTML = '<img src="'+escHtml(path)+'" style="max-width:100%;max-height:60px;object-fit:contain;">';
    });
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
// ALIGN TO SCREEN (this Display's canvas)
// ============================================================
// "Align to Parent" — snaps each element to a position within its own parent container.
// For child blocks in a section, the section is the parent.
// For root-level blocks and sections, the canvas is the parent.
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
