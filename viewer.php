<?php
// ============================================================
// VIEWER — the public page a Screen shows
// ============================================================
// A thin adapter: it resolves which Display this URL names, sizes the canvas from
// that Display's record, and renders. Every `displays` statement lives in
// lib/displays.php; the notice wording lives in lib/display_request.php. See
// docs/BUILD-REFERENCE.md.
//
// Public and login-free by design, so any Screen on the network can show a sign.
// Deliberately does NOT include auth.php: no session is started here.
//
// Runs no DDL — this page is polled every 30s by every Screen forever
// (BUILD-REFERENCE §2 invariant 7). DisplayStore self-heals only if the schema is
// genuinely absent, and then at most once every five minutes across the whole
// installation, however many Screens are asking (see repairSchemaAfterFailure()).

// Declared before the database is opened, because a database that will not open is
// the failure this matters most for. A Screen has nobody in front of it: whatever
// goes wrong here has to become the same dark kiosk notice the rest of this file
// draws, re-checking every 30 seconds, and never a PHP error naming server paths
// on a board customers are reading. See lib/error_policy.php.
require_once __DIR__ . '/lib/error_policy.php';
ErrorPolicy::install(ErrorPolicy::SCREEN);

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/display_request.php';
require_once __DIR__ . '/lib/http_reply.php';

// Nothing this page serves may be held (#28). Two of the three notices below exist
// in order to stop being true — a sign gets turned back on, a tag gets corrected —
// and the meta refresh that re-checks them every 30 seconds is worth nothing if the
// browser or a proxy answers the refresh out of its own store. Before any output,
// because a header set after the first byte is a warning, not a header.
HttpReply::noStore();

$resolution = DisplayRequest::forViewing(new DisplayStore($pdo), $_GET);
$display    = $resolution->display();

// A Display this URL does not name, or one that is turned off, is a notice — never
// another Display's layout (ADR-0003).
//
// The notice re-checks every 30 seconds, matching the poll cadence, because two of
// the three reasons for it do go away: a Display that was turned off gets turned
// back on, and one that was renamed gets its tag corrected. Without this, a Screen
// that happened to boot during either — a TV powered on before opening while the
// sign was still retired — sat on the notice until somebody walked over and reloaded
// the browser, while the admin panel had already promised the screen would update
// within 30 seconds. A meta refresh rather than script, so it works on the least
// capable kiosk browser.
if (!$resolution->isFound()) {
    $notice = $resolution->message();
    // 400, 404 or 503 rather than the 200 all three used to leave as (#28). The
    // Screen renders the body either way — this is for everything that will never
    // read it: a proxy deciding whether it may keep the answer, an uptime check, an
    // admin running `curl` after typing a tag onto a new television.
    http_response_code(HttpReply::codeForResolution($resolution));
    if (!headers_sent() && $resolution->kind() === DisplayResolution::INACTIVE) {
        header('Retry-After: ' . HttpReply::RETRY_AFTER);
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="30">
<title><?= Markup::text($display ? $display->title() : 'Display') ?></title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
        width: 100%; height: 100%;
        overflow: hidden;
        background: #111;
        overscroll-behavior: none;
        touch-action: none;
    }
    body { display: flex; align-items: center; justify-content: center; }
    .notice {
        font-family: Arial, Helvetica, sans-serif;
        color: #8a8a8a;
        font-size: 2.2vw;
        letter-spacing: 0.04em;
        text-align: center;
        padding: 0 6vw;
    }
</style>
</head>
<body>
<p class="notice"><?= Markup::text($notice) ?></p>
</body>
</html><?php
    exit;
}

$canvasW = $display->canvasWidth();
$canvasH = $display->canvasHeight();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= Markup::text($display->title()) ?></title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    /* Kiosk / embedded display: never scroll in either direction. Lock the
       body to the viewport and kill scrollbars, overscroll rubber-banding,
       and touch-drag scrolling on both axes. */
    html {
        width: 100%; height: 100%;
        overflow: hidden;
        background: #111;
    }
    body {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        width: 100%; height: 100%;
        overflow: hidden;
        overflow-x: hidden;
        overflow-y: hidden;
        background: #111;
        overscroll-behavior: none;
        touch-action: none;
        -ms-touch-action: none;
    }

    /* The Display's canvas, at the dimensions fixed when it was created
       (ADR-0004) — scaled to fill any Screen via JS. */
    #viewer-canvas {
        width: <?= $canvasW ?>px; height: <?= $canvasH ?>px;
        position: absolute; top: 0; left: 0;
        transform-origin: top left;
        overflow: hidden;
        background-color: #1a1a2e;
        background-size: cover;
        background-position: center;
    }

    /* Shown if the Display is turned off while a Screen is running. */
    #viewer-notice {
        display: none;
        position: fixed;
        inset: 0;
        background: #111;
        align-items: center;
        justify-content: center;
        font-family: Arial, Helvetica, sans-serif;
        color: #8a8a8a;
        font-size: 2.2vw;
        letter-spacing: 0.04em;
        text-align: center;
        padding: 0 6vw;
    }

    /* Sections */
    .section-block {
        position: absolute;
        overflow: hidden;
        background-size: cover;
        background-position: center;
    }

    /* All content blocks */
    .element-block {
        position: absolute;
        overflow: hidden;
    }
    .element-block img, .element-block video {
        width: 100%; height: 100%;
        display: block;
    }
    .element-block video { object-fit: cover; }

    /* ── Carousel ── */
    .carousel-wrap {
        position: relative;
        width: 100%; height: 100%;
        overflow: hidden;
    }
    .carousel-slide {
        position: absolute;
        inset: 0;
        display: flex;
        opacity: 0;
        transition: opacity 0.8s ease-in-out;
        overflow: hidden;
    }
    .carousel-slide.active { opacity: 1; }
    /* Text position variants */
    .carousel-slide.pos-right  { flex-direction: row; }
    .carousel-slide.pos-left   { flex-direction: row-reverse; }
    .carousel-slide.pos-bottom { flex-direction: column; }
    .carousel-slide.pos-top    { flex-direction: column-reverse; }
    /* Image wrap — 40% of space */
    .carousel-img-wrap { flex-shrink: 0; overflow: hidden; }
    .carousel-slide.pos-right  .carousel-img-wrap,
    .carousel-slide.pos-left   .carousel-img-wrap { width: 40%; height: 100%; }
    .carousel-slide.pos-bottom .carousel-img-wrap,
    .carousel-slide.pos-top    .carousel-img-wrap { width: 100%; height: 40%; }
    .carousel-img-wrap img {
        width: 100%; height: 100%;
        display: block;
    }
    /* Text panel — 60% of space, transparent background */
    .carousel-text-panel {
        flex: 1;
        background: transparent;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 16px 22px;
        overflow: hidden;
    }
    .carousel-title {
        font-family: Arial, sans-serif;
        font-weight: bold;
        color: #f0f0f0;
        font-size: 1.4em;
        line-height: 1.2;
        margin-bottom: 4px;
    }
    .carousel-price {
        font-family: Arial, sans-serif;
        font-weight: bold;
        color: #f39c12;
        font-size: 1.6em;
        line-height: 1.2;
        margin-bottom: 6px;
    }
    .carousel-desc {
        font-family: Arial, sans-serif;
        color: #ccc;
        font-size: 0.88em;
        line-height: 1.4;
    }

    /* ── Table ── */
    .table-wrap { width:100%; height:100%; overflow:auto; }
    .table-wrap table { width:100%; border-collapse:collapse; table-layout:fixed; }
    .table-wrap td { padding:8px 10px; overflow:hidden; }

    /* ── Marquee ── */
    .marquee-wrap {
        width: 100%; height: 100%;
        overflow: hidden;
        display: flex;
        align-items: center;
    }
    .marquee-text {
        white-space: nowrap;
        will-change: transform;
        display: inline-block;
        padding-left: 100%;
        font-family: Arial, sans-serif;
    }
</style>
</head>
<body>
<div id="viewer-canvas"></div>
<div id="viewer-notice"></div>
<script>
    // This Display's canvas, fixed at creation (ADR-0004).
    var CANVAS_W = <?= $canvasW ?>;
    var CANVAS_H = <?= $canvasH ?>;
    // The screen name tag this Screen was pointed at. Every poll names it.
    //
    // Through HttpReply, not json_encode: a bare json_encode returning false here
    // emits `var DISPLAY_TAG = ;`, which is a parse error that takes the whole
    // script down and leaves a blank television with nothing in any log (#26).
    var DISPLAY_TAG = <?= HttpReply::jsValue($display->tag()) ?>;

    // Scale the canvas to fill the actual Screen. Letterboxed on a shape
    // mismatch — min() preserves proportions rather than distorting prices.
    function scaleToFit() {
        var c  = document.getElementById('viewer-canvas');
        var sx = window.innerWidth  / CANVAS_W;
        var sy = window.innerHeight / CANVAS_H;
        var s  = Math.min(sx, sy);
        c.style.transform  = 'scale(' + s + ')';
        c.style.marginLeft = ((window.innerWidth  - CANVAS_W * s) / 2) + 'px';
        c.style.marginTop  = ((window.innerHeight - CANVAS_H * s) / 2) + 'px';
    }
    window.addEventListener('resize', scaleToFit);
    scaleToFit();

    // Auto-refresh every 30s so published changes appear without manual reload
    setInterval(loadLayout, 30000);

    var _layoutHash = '';
    var _loading    = false;
    var _carouselTimers = [];
    var _marqueeStops   = [];   // array of cancel functions, one per marquee

    // How many polls in a row may fail before the sign stops showing what it last
    // knew (#26).
    //
    // Not one, deliberately. A single failed poll is a dropped packet, a Wi-Fi
    // roam, a PHP-FPM restart, somebody re-uploading a file over FTP — and blanking
    // a working price board for any of those would be a worse fault than the one
    // this fixes. Ten of them is five minutes, which no transient in this shop
    // reaches and which bounds how wrong a sign can be: a customer is never looking
    // at prices more than five minutes after the app stopped being able to confirm
    // them.
    //
    // Going dark is the right end state for a *price* sign specifically. Showing
    // stale prices is a promise the store then has to keep; showing nothing is not.
    var STALE_AFTER_FAILURES = 10;
    var _failedPolls = 0;

    function stopAnimations() {
        _carouselTimers.forEach(function(t) { clearInterval(t); });
        _carouselTimers = [];
        _marqueeStops.forEach(function(cancel) { cancel(); });
        _marqueeStops = [];
    }

    // A Display turned off (or deleted) while this Screen is running: show the
    // notice instead of the last layout, within one poll.
    function showNotice(message) {
        stopAnimations();
        var canvas = document.getElementById('viewer-canvas');
        var notice = document.getElementById('viewer-notice');
        canvas.innerHTML  = '';
        canvas.style.display = 'none';
        notice.textContent   = message || 'No display specified';
        notice.style.display = 'flex';
        _layoutHash = '';   // re-render from scratch if it comes back
    }

    function hideNotice() {
        document.getElementById('viewer-notice').style.display = 'none';
        document.getElementById('viewer-canvas').style.display = '';
    }

    function loadLayout() {
        if (_loading) return;
        _loading = true;

        // Whichever of the three below gets there first is the only one that counts.
        // Without it a wedged request that the watchdog freed and that then rejected
        // was both counted twice and able to clear `_loading` out from under the
        // poll that had already replaced it.
        var settled = false;

        // A request that never settles must not freeze the sign for good. fetch has
        // no timeout of its own, so one wedged request (captive portal, stalled
        // worker, a query blocked on a lock) would silently end all further polling.
        //
        // It counts as a failed poll, and that is the point: a request that never
        // answers is the exact shape of "the sign kept its old layout forever with
        // no notice", and a watchdog that only freed the flag was invisible to any
        // counter that lived in .catch.
        var _watchdog = setTimeout(function() { pollFailed(); }, 20000);

        /** A poll that got a reply, whatever the reply said. */
        function pollSucceeded() {
            if (settled) { return; }
            settled = true;
            clearTimeout(_watchdog);
            _loading     = false;
            _failedPolls = 0;
        }

        /**
         * A poll that did not. The layout stays up — until enough of them have
         * failed in a row that it can no longer be claimed to be current.
         */
        function pollFailed() {
            if (settled) { return; }
            settled = true;
            clearTimeout(_watchdog);
            _loading    = false;   // allow retry on next interval
            _layoutHash = '';      // and re-render from scratch when it comes back
            _failedPolls++;
            if (_failedPolls >= STALE_AFTER_FAILURES) {
                // The same sentence the server sends for its own failures, so a sign
                // says one thing whichever end stopped working.
                showNotice('This sign is temporarily unavailable.');
            }
        }

        fetch('api.php?action=get_layout&display=' + encodeURIComponent(DISPLAY_TAG))
            // A non-2xx reply is not a rejected fetch, and this endpoint answers 400,
            // 404 and 503 now (#28) — the body is the same JSON in every case, and
            // reaching the server at all is what `pollSucceeded` means here.
            .then(function(r) { return r.json(); })
            .then(function(data) {
                pollSucceeded();

                if (!data || data.status !== 'success') {
                    showNotice(data && data.message);
                    return;
                }

                var hash = JSON.stringify(data);
                if (hash === _layoutHash) return; // nothing changed — leave videos running

                stopAnimations();
                hideNotice();

                var canvas = document.getElementById('viewer-canvas');
                canvas.innerHTML = '';

                var display     = data.display     || {};
                var elements    = data.elements    || [];
                var blockStyles = data.block_styles || {};

                // Background — the Display owns it (canvas_settings is retired)
                if (display.bg_type === 'color') {
                    canvas.style.backgroundColor = display.bg_val || '#1a1a2e';
                    canvas.style.backgroundImage = 'none';
                } else {
                    canvas.style.backgroundImage = "url('" + display.bg_val + "')";
                    canvas.style.backgroundColor = '#111';
                }

                // Build set of hidden section IDs so their children are also skipped
                var hiddenSections = new Set(
                    elements.filter(function(e) { return e.type === 'section' && parseInt(e.hidden); })
                            .map(function(e) { return parseInt(e.id); })
                );

                // Render sections first, build id→element map
                var sectionMap = {};
                elements.filter(function(e) { return e.type === 'section' && !parseInt(e.hidden); }).forEach(function(el) {
                    var s = document.createElement('div');
                    s.className    = 'section-block';
                    s.style.left    = el.x_pos  + 'px';
                    s.style.top     = el.y_pos   + 'px';
                    s.style.width   = el.width   + 'px';
                    s.style.height  = el.height  + 'px';
                    s.style.zIndex  = Math.max(1, parseInt(el.z_index) || 1);
                    if (el.section_bg) {
                        var _vbgP = el.section_bg.split('|');
                        var _vbgPath = _vbgP[0];
                        var _vbgFit  = _vbgP[1] || 'cover';
                        s.style.backgroundImage = "url('" + _vbgPath + "')";
                        if (_vbgFit === 'contain') {
                            s.style.backgroundSize = 'contain'; s.style.backgroundRepeat = 'no-repeat'; s.style.backgroundPosition = 'center';
                        } else if (_vbgFit === 'fill') {
                            s.style.backgroundSize = '100% 100%'; s.style.backgroundRepeat = 'no-repeat'; s.style.backgroundPosition = 'center';
                        } else if (_vbgFit === 'tile') {
                            s.style.backgroundSize = 'auto'; s.style.backgroundRepeat = 'repeat'; s.style.backgroundPosition = 'top left';
                        } else if (_vbgFit === 'center') {
                            s.style.backgroundSize = 'auto'; s.style.backgroundRepeat = 'no-repeat'; s.style.backgroundPosition = 'center';
                        } else {
                            s.style.backgroundSize = 'cover'; s.style.backgroundRepeat = 'no-repeat'; s.style.backgroundPosition = 'center';
                        }
                    }
                    canvas.appendChild(s);
                    sectionMap[el.id] = s;
                });

                // Render non-section elements (skip hidden; skip children of hidden sections)
                elements.filter(function(e) {
                    return e.type !== 'section'
                        && !parseInt(e.hidden)
                        && !hiddenSections.has(parseInt(e.section_id));
                }).forEach(function(el) {
                  // One element must never take the sign down. An element's stored
                  // content is deliberately unvalidated for the non-text types
                  // (invariant 6), so a table whose `rows` is not an array — from a
                  // hand edit, an older Builder, or a crafted publish — used to throw
                  // mid-render, after the canvas had already been emptied. Skipping
                  // the bad block leaves the rest of the sign up, which is what a
                  // customer-facing board needs.
                  try {
                    var parent = el.section_id ? sectionMap[el.section_id] : canvas;
                    if (!parent) return;

                    var block = document.createElement('div');
                    block.className    = 'element-block';
                    block.style.left   = el.x_pos  + 'px';
                    block.style.top    = el.y_pos   + 'px';
                    block.style.width  = el.width   + 'px';
                    block.style.height = el.height  + 'px';
                    block.style.zIndex = Math.max(1, parseInt(el.z_index) || 1);

                    var content = el.asset_id ? el.db_content : el.manual_content;
                    var subtype = el.block_subtype || 'free';

                    if (el.type === 'text') {
                        // Apply brand styles for typed blocks; inline styles for free text
                        if (subtype !== 'free' && blockStyles[subtype]) {
                            var bs = blockStyles[subtype];
                            block.style.fontFamily  = bs.font_family;
                            block.style.fontSize    = bs.font_size + 'px';
                            block.style.color       = bs.font_color;
                            block.style.fontWeight  = bs.font_weight;
                            block.style.fontStyle   = bs.font_style;
                            block.style.lineHeight  = bs.line_height;
                        } else {
                            block.style.fontFamily  = el.font_family || 'Arial';
                            block.style.fontSize    = (el.font_size || 16) + 'px';
                            block.style.color       = el.font_color || '#000000';
                            block.style.fontWeight  = el.font_weight || 'normal';
                            block.style.fontStyle   = el.font_style  || 'normal';
                            block.style.lineHeight  = el.line_height || 1.4;
                        }
                        if (el.text_align) block.style.textAlign = el.text_align;
                        // Plain text only — textContent never executes markup.
                        // pre-wrap preserves author line breaks.
                        block.style.whiteSpace = 'pre-wrap';
                        block.textContent = content || '';

                    } else if (el.type === 'image') {
                        renderImage(block, content);

                    } else if (el.type === 'video') {
                        renderVideo(block, content);

                    } else if (el.type === 'carousel') {
                        renderCarousel(block, content, blockStyles);

                    } else if (el.type === 'table') {
                        renderTable(block, content, blockStyles);

                    } else if (el.type === 'marquee') {
                        renderMarquee(block, content);
                    }

                    parent.appendChild(block);
                  } catch (e) {
                    // Skip this element. The block is appended last, so a throw
                    // part-way through leaves nothing half-drawn on the canvas.
                  }
                });

                // Latched only now, and only on a render that finished. Latching it
                // before the canvas was rebuilt meant a render that threw left the
                // sign blank *and* every later poll comparing equal — so the sign
                // stayed blank until somebody walked over and reloaded the browser.
                _layoutHash = hash;
            })
            // Reached by a dropped connection, by a reply that is not JSON, and —
            // until #26 — by a 200 with a zero-length body, which is what an
            // unchecked encode sent whenever one stored character was not valid
            // UTF-8. That cause was permanent, so the sign sat on its last layout
            // and retried into the same failure every 30 seconds, for months,
            // silently. The server no longer produces it; this end no longer
            // swallows it either way.
            .catch(function() { pollFailed(); });
    }

    // ── Image ───────────────────────────────────────────────────
    //
    // Lifted out of the render loop to sit beside the other four, because the
    // question it now has to answer is the same one they answer and it is easier
    // to be sure of — and to test — in a function with a name.
    function renderImage(block, content) {
        // `path|fit`. The path is empty when nothing was ever chosen, and also
        // when the asset a block was linked to has since been deleted: `content`
        // is `db_content` for a linked block, and that comes back null.
        var parts = (typeof content === 'string') ? content.split('|') : [];
        var src   = (parts[0] || '').trim();
        var fit   = parts[1] || 'fill';

        // An image with no file draws nothing (#45). `img.src = ''` is not an
        // absent picture — it is a *broken* one, by definition: the empty string
        // puts the element straight into the broken state, and what a broken image
        // looks like is then the browser's decision. An icon on some, a blank box
        // on others, at 100% × 100% because that is what .element-block img says.
        //
        // A store's sign must not look different because of which browser the
        // television happens to ship with. Appending nothing is the one rendering
        // that is the same everywhere, and it is also the honest one: there is no
        // picture here.
        //
        // The author still sees the block. The Builder draws a placeholder in its
        // place — svgPlaceholder(w, h, 'Image') — with the asset picker beside it.
        if (src === '') { return; }

        var img = document.createElement('img');
        img.src = src;
        img.alt = '';
        // Apply fit mode
        if (fit === 'contain' || fit === 'cover') {
            img.style.objectFit = fit;
        } else if (fit === 'fit-w') {
            img.style.height = 'auto';
        } else if (fit === 'fit-h') {
            img.style.width = 'auto';
        } else {
            img.style.objectFit = 'fill';
        }
        block.appendChild(img);
    }

    // ── Video ───────────────────────────────────────────────────
    function renderVideo(block, content) {
        var path = (typeof content === 'string') ? content.trim() : '';

        // The same answer as the image, for the same reason (#45). An autoplaying
        // <video> with no source inside it never plays anything; it is a rectangle
        // whose colour the browser picks — black on some, transparent on others —
        // and the sign should not depend on which. The `if (content)` below used to
        // skip only the <source>, and appended the empty player regardless.
        //
        // This is the one of the five where the Builder had nothing to say either,
        // so it now draws the placeholder it already draws for an image.
        if (path === '') { return; }

        var vid = document.createElement('video');
        vid.autoplay    = true;
        vid.loop        = true;
        vid.muted       = true;
        vid.playsInline = true;

        var src  = document.createElement('source');
        src.src  = path;
        var ext  = path.split('.').pop().toLowerCase();
        var mime = {mp4:'video/mp4',webm:'video/webm',ogv:'video/ogg',ogg:'video/ogg'};
        if (mime[ext]) src.type = mime[ext];
        vid.appendChild(src);

        block.appendChild(vid);
    }

    // ── Carousel ────────────────────────────────────────────────

    /** The same three-way test the text panel below applies to each of its fields. */
    function slideFieldSet(v) { return v !== null && v !== undefined && v !== ''; }

    /**
     * Whether a slide holds anything a customer could read or look at.
     *
     * Element content is unvalidated for the non-text types (invariant 6), so a
     * slide can be null, a string, or an object with every field left blank — and
     * each of those used to draw. A blank slide still got its image well, and the
     * well painted itself #1a1a2e when there was no image to put in it: a navy
     * rectangle standing in for a picture nobody had chosen, rotating past the
     * customer every five seconds.
     */
    function slideShowsSomething(s) {
        if (!s || typeof s !== 'object') { return false; }
        if (s.image)     { return true;  }
        if (s.imageOnly) { return false; }   // the image is the whole slide, and there is none
        return slideFieldSet(s.title) || slideFieldSet(s.price) || slideFieldSet(s.description);
    }

    function renderCarousel(block, content, blockStyles) {
        var data = {};
        try { data = JSON.parse(content || '{}'); } catch(e) {}
        var slides   = data.slides   || [];
        var interval = Math.max(500, data.interval || 5000);

        // A carousel with no slides draws nothing at all (#45). It used to print
        // "Carousel — no slides added yet" in grey, on a board a customer is
        // reading to decide what to order. That sentence was written for whoever
        // was building the layout, and it never reached them: the person standing
        // in front of a price sign cannot add a slide to it.
        //
        // Saying nothing here costs the author nothing either, because the surface
        // they are actually looking at already tells them. The Builder labels the
        // same block "↻ Carousel — 0 slides" on its own canvas, whether or not this
        // page ever says a word.
        //
        // Returning is enough to draw nothing: the caller appends `block` either
        // way, and .element-block sets only position and overflow — no background,
        // no border, so an empty one is not ink.
        //
        // A `slides` that is not an array lands here rather than throwing further
        // down. Element content is deliberately unvalidated for the non-text types
        // (invariant 6), and "not a list of slides" is a block with nothing
        // showable in it, which is this case.
        if (!Array.isArray(slides)) { return; }

        // And a list of slides with nothing in them is the same block by a longer
        // route (#45, second pass). Filtering here rather than skipping inside the
        // loop is what makes the rotation right as well as the drawing: `slideEls`
        // then holds only slides that show something, so a carousel of three where
        // two are blank no longer spends ten seconds of every fifteen on nothing.
        var showable = slides.filter(slideShowsSomething);
        if (showable.length === 0) { return; }

        var wrap = document.createElement('div');
        wrap.className = 'carousel-wrap';

        // Helper: apply a blockStyles entry to an element, with CSS fallbacks
        function applyStyle(el, styleKey, fallback) {
            var bs = blockStyles && blockStyles[styleKey];
            if (bs) {
                el.style.fontFamily  = bs.font_family  || fallback.fontFamily  || 'Arial, sans-serif';
                el.style.fontSize    = (bs.font_size   || fallback.fontSize  || 16) + 'px';
                el.style.color       = bs.font_color   || fallback.color     || '#fff';
                el.style.fontWeight  = bs.font_weight  || fallback.fontWeight || 'normal';
                el.style.fontStyle   = bs.font_style   || fallback.fontStyle  || 'normal';
                el.style.lineHeight  = bs.line_height  || fallback.lineHeight || 1.4;
            } else {
                el.style.fontFamily  = fallback.fontFamily  || 'Arial, sans-serif';
                el.style.fontSize    = (fallback.fontSize  || 16) + 'px';
                el.style.color       = fallback.color       || '#fff';
                el.style.fontWeight  = fallback.fontWeight  || 'normal';
                el.style.lineHeight  = fallback.lineHeight  || 1.4;
            }
        }

        var slideEls = [];
        showable.forEach(function(s) {
            var pos   = s.textPosition || 'right';
            var slide = document.createElement('div');
            slide.className = s.imageOnly ? 'carousel-slide' : 'carousel-slide pos-' + pos;

            // Image wrap — full size when imageOnly, otherwise 40%
            var imgWrap = document.createElement('div');
            imgWrap.className = 'carousel-img-wrap';
            imgWrap.style.overflow = 'hidden';
            if (s.imageOnly) {
                imgWrap.style.width  = '100%';
                imgWrap.style.height = '100%';
            }
            if (s.image) {
                var img = document.createElement('img');
                img.src = s.image;
                img.alt = s.title || '';
                var fit = s.imageFit || 'contain';
                if (fit === 'fit-w') {
                    img.style.width  = '100%';
                    img.style.height = 'auto';
                } else if (fit === 'fit-h') {
                    img.style.width  = 'auto';
                    img.style.height = '100%';
                } else {
                    img.style.objectFit = fit; // contain / cover / fill
                }
                imgWrap.appendChild(img);
            }
            // No `else`. A slide that reaches here without an image has text, and
            // the well used to fill itself with #1a1a2e to mark the space a picture
            // would have taken — placeholder ink, hardcoded, drawn only because
            // something was missing. It is still appended, empty: the 40/60 split is
            // the layout the author arranged around their words, and taking the well
            // away as well would reflow a slide that is not the one at fault.
            slide.appendChild(imgWrap);

            if (!s.imageOnly) {
                // Text panel (60%) — transparent background, brand-styled text
                var panel = document.createElement('div');
                panel.className = 'carousel-text-panel';
                if (s.title !== null && s.title !== undefined && s.title !== '') {
                    var t = document.createElement('div');
                    t.className   = 'carousel-title';
                    t.textContent = s.title;
                    applyStyle(t, 'item_title', {fontFamily:'Arial,sans-serif', fontSize:26, color:'#f0f0f0', fontWeight:'bold', lineHeight:1.2});
                    panel.appendChild(t);
                }
                if (s.price !== null && s.price !== undefined && s.price !== '') {
                    var p = document.createElement('div');
                    p.className   = 'carousel-price';
                    p.textContent = s.price;
                    applyStyle(p, 'price', {fontFamily:'Arial,sans-serif', fontSize:28, color:'#f39c12', fontWeight:'bold', lineHeight:1.2});
                    panel.appendChild(p);
                }
                if (s.description !== null && s.description !== undefined && s.description !== '') {
                    var d = document.createElement('div');
                    d.className   = 'carousel-desc';
                    d.textContent = s.description;
                    applyStyle(d, 'description', {fontFamily:'Arial,sans-serif', fontSize:16, color:'#ccc', fontWeight:'normal', lineHeight:1.4});
                    panel.appendChild(d);
                }
                slide.appendChild(panel);
            }

            wrap.appendChild(slide);
            slideEls.push(slide);
        });

        block.appendChild(wrap);

        // Activate first slide immediately
        if (slideEls.length > 0) slideEls[0].classList.add('active');
        if (slideEls.length < 2) return;

        var current = 0;

        var timer = setInterval(function() {
            slideEls[current].classList.remove('active');
            current = (current + 1) % slideEls.length;
            slideEls[current].classList.add('active');
        }, interval);

        _carouselTimers.push(timer);
    }

    // ── Table ────────────────────────────────────────────────────
    function renderTable(block, content, blockStyles) {
        var data = {};
        try { data = JSON.parse(content || '{}'); } catch(e) {}
        var headers  = data.headers || [];
        var rows     = data.rows    || [];
        var valigns  = data.valigns || [];
        var haligns  = data.haligns || [];
        var widths   = data.widths  || [];
        var rowPad   = Math.max(0, parseInt(data.row_padding) || 0);

        // The same as the carousel above, and the same answer (#45). An empty table
        // drew "Table — no data" over a grey panel, so the sign carried both a
        // sentence meant for the author and a box drawn to hold it. The author is
        // in the Builder, where this block reads "⋞ Table — 0 cols, 0 rows".
        if (!Array.isArray(headers) || !Array.isArray(rows)
            || headers.length === 0 || rows.length === 0) { return; }

        var wrap = document.createElement('div');
        wrap.className = 'table-wrap';

        var tbl = document.createElement('table');

        // Apply column widths via colgroup when any column has an explicit width
        var hasWidths = widths.some(function(w) { return w > 0; });
        if (hasWidths) {
            var cg = document.createElement('colgroup');
            headers.forEach(function(_, ci) {
                var col = document.createElement('col');
                if (widths[ci] > 0) col.style.width = widths[ci] + '%';
                cg.appendChild(col);
            });
            tbl.appendChild(cg);
        }

        rows.forEach(function(row) {
            var tr = document.createElement('tr');
            headers.forEach(function(style, ci) {
                var td = document.createElement('td');
                if (style !== 'free' && blockStyles[style]) {
                    var bs = blockStyles[style];
                    td.style.fontFamily  = bs.font_family;
                    td.style.fontSize    = bs.font_size + 'px';
                    td.style.color       = bs.font_color;
                    td.style.fontWeight  = bs.font_weight;
                    td.style.fontStyle   = bs.font_style;
                    td.style.lineHeight  = bs.line_height;
                } else {
                    td.style.fontFamily = 'Arial, sans-serif';
                    td.style.fontSize   = '16px';
                    td.style.color      = '#fff';
                }
                td.style.verticalAlign = valigns[ci] || 'top';
                td.style.textAlign     = haligns[ci] || 'left';
                if (rowPad > 0) { td.style.paddingTop = rowPad + 'px'; td.style.paddingBottom = rowPad + 'px'; }
                td.textContent = (row[ci] !== undefined && row[ci] !== null) ? row[ci] : '';
                tr.appendChild(td);
            });
            tbl.appendChild(tr);
        });

        wrap.appendChild(tbl);
        block.appendChild(wrap);
    }

    // ── Marquee ─────────────────────────────────────────────────
    function renderMarquee(block, content) {
        var data = {};
        try { data = JSON.parse(content || '{}'); } catch(e) {}

        var speed  = Math.max(1, data.speed  || 80);  // px/sec; clamp to ≥1 to prevent idle loop
        var color  = data.color  || '#ffffff';
        var size   = data.size   || 28;
        var weight = data.weight || 'bold';
        var bg     = data.bg === 'transparent' ? 'transparent' : (data.bg || '#c0392b');

        // Only a string or a number is a message. Anything else — an object, a
        // list, a boolean — used to reach textContent all the same and scroll
        // "[object Object]" past the customer, because element content is
        // unvalidated for the non-text types (invariant 6) and this end never asked.
        var text = (typeof data.text === 'string' || typeof data.text === 'number')
                 ? String(data.text) : '';

        // A marquee with nothing to say draws nothing at all (#45, second pass).
        // This block already meant to do nothing here — `if (!text) return;` sat
        // four lines below — but the background was assigned before it, so an
        // unfinished marquee painted a solid #c0392b band across the sign and then
        // scrolled an empty span along it. A red bar with no message on a price
        // board is not a quieter version of the message; it is a different sign,
        // and one nobody chose.
        //
        // The author keeps the warning, as with the carousel and the table: the
        // Builder draws this same block as "▶ Marquee text — click to edit in
        // inspector", on that same bar, on the surface where it can be acted on.
        //
        // Spaces are not a message either. `'   '` is truthy, so it used to paint
        // the bar and animate an invisible span along it forever.
        if (text.trim() === '') { return; }

        block.style.background = bg;

        var wrap = document.createElement('div');
        wrap.className = 'marquee-wrap';

        var span = document.createElement('span');
        span.className        = 'marquee-text';
        span.textContent      = text;
        span.style.color      = color;
        span.style.fontSize   = size + 'px';
        span.style.fontWeight = weight;
        span.style.paddingLeft = '100%';

        wrap.appendChild(span);
        block.appendChild(wrap);

        var cancelled = false;
        var pos       = 0;
        var lastTime  = null;

        function step(ts) {
            if (cancelled) return;  // stopped by stopAnimations()
            if (!document.body.contains(span)) return;  // DOM removed on layout reload
            if (lastTime === null) lastTime = ts;
            var dt = (ts - lastTime) / 1000; // seconds
            lastTime = ts;
            pos -= speed * dt;
            // Reset when text has fully scrolled off the left edge
            var textW = span.offsetWidth;
            if (pos < -textW) pos = 0;
            span.style.transform = 'translateX(' + pos + 'px)';
            requestAnimationFrame(step);
        }

        requestAnimationFrame(step);
        _marqueeStops.push(function() { cancelled = true; });
    }

    document.addEventListener('DOMContentLoaded', loadLayout);
</script>
</body>
</html>
