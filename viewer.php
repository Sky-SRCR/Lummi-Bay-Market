<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Display</title>
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

    /* 1920×1080 design canvas – scaled to fill any screen via JS */
    #viewer-canvas {
        width: 1920px; height: 1080px;
        position: absolute; top: 0; left: 0;
        transform-origin: top left;
        overflow: hidden;
        background-color: #1a1a2e;
        background-size: cover;
        background-position: center;
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
<script>
    // Scale 1920×1080 canvas to fill the actual screen
    function scaleToFit() {
        var c  = document.getElementById('viewer-canvas');
        var sx = window.innerWidth  / 1920;
        var sy = window.innerHeight / 1080;
        var s  = Math.min(sx, sy);
        c.style.transform  = 'scale(' + s + ')';
        c.style.marginLeft = ((window.innerWidth  - 1920 * s) / 2) + 'px';
        c.style.marginTop  = ((window.innerHeight - 1080 * s) / 2) + 'px';
    }
    window.addEventListener('resize', scaleToFit);
    scaleToFit();

    // Auto-refresh every 30s so published changes appear without manual reload
    setInterval(loadLayout, 30000);

    var _layoutHash = '';
    var _loading    = false;
    var _carouselTimers = [];
    var _marqueeStops   = [];   // array of cancel functions, one per marquee

    function stopAnimations() {
        _carouselTimers.forEach(function(t) { clearInterval(t); });
        _carouselTimers = [];
        _marqueeStops.forEach(function(cancel) { cancel(); });
        _marqueeStops = [];
    }

    function loadLayout() {
        if (_loading) return;
        _loading = true;
        fetch('api.php?action=get_layout')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                _loading = false;
                var hash = JSON.stringify(data);
                if (hash === _layoutHash) return; // nothing changed — leave videos running
                _layoutHash = hash;

                stopAnimations();

                var canvas = document.getElementById('viewer-canvas');
                canvas.innerHTML = '';

                var settings    = data.settings    || {};
                var elements    = data.elements    || [];
                var blockStyles = data.block_styles || {};

                // Background
                if (settings.bg_type === 'color') {
                    canvas.style.backgroundColor = settings.bg_val || '#1a1a2e';
                    canvas.style.backgroundImage = 'none';
                } else {
                    canvas.style.backgroundImage = "url('" + settings.bg_val + "')";
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
                        var _p   = (content || '').split('|');
                        var _src = _p[0];
                        var _fit = _p[1] || 'fill';
                        var img = document.createElement('img');
                        img.src = _src || '';
                        img.alt = '';
                        // Apply fit mode
                        if (_fit === 'contain' || _fit === 'cover') {
                            img.style.objectFit = _fit;
                        } else if (_fit === 'fit-w') {
                            img.style.height = 'auto';
                        } else if (_fit === 'fit-h') {
                            img.style.width = 'auto';
                        } else {
                            img.style.objectFit = 'fill';
                        }
                        block.appendChild(img);

                    } else if (el.type === 'video') {
                        var vid = document.createElement('video');
                        vid.autoplay   = true;
                        vid.loop       = true;
                        vid.muted      = true;
                        vid.playsInline = true;
                        if (content) {
                            var src = document.createElement('source');
                            src.src  = content;
                            var _ext = content.split('.').pop().toLowerCase();
                            var _mime = {mp4:'video/mp4',webm:'video/webm',ogv:'video/ogg',ogg:'video/ogg'};
                            if (_mime[_ext]) src.type = _mime[_ext];
                            vid.appendChild(src);
                        }
                        block.appendChild(vid);

                    } else if (el.type === 'carousel') {
                        renderCarousel(block, content, blockStyles);

                    } else if (el.type === 'table') {
                        renderTable(block, content, blockStyles);

                    } else if (el.type === 'marquee') {
                        renderMarquee(block, content);
                    }

                    parent.appendChild(block);
                });
            })
            .catch(function() {
                _loading = false; // allow retry on next interval
            });
    }

    // ── Carousel ────────────────────────────────────────────────
    function renderCarousel(block, content, blockStyles) {
        var data = {};
        try { data = JSON.parse(content || '{}'); } catch(e) {}
        var slides   = data.slides   || [];
        var interval = Math.max(500, data.interval || 5000);

        var wrap = document.createElement('div');
        wrap.className = 'carousel-wrap';

        if (slides.length === 0) {
            wrap.style.cssText = 'display:flex;align-items:center;justify-content:center;color:#aaa;font-family:Arial;font-size:18px;';
            wrap.textContent = 'Carousel — no slides added yet';
            block.appendChild(wrap);
            return;
        }

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
        slides.forEach(function(s) {
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
            } else {
                imgWrap.style.background = '#1a1a2e';
            }
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

        if (!headers.length || !rows.length) {
            block.style.cssText += 'display:flex;align-items:center;justify-content:center;color:#aaa;font-family:Arial;font-size:14px;background:rgba(0,0,0,0.3);';
            block.textContent = 'Table — no data';
            return;
        }

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

        var text   = data.text   || '';
        var speed  = Math.max(1, data.speed  || 80);  // px/sec; clamp to ≥1 to prevent idle loop
        var color  = data.color  || '#ffffff';
        var size   = data.size   || 28;
        var weight = data.weight || 'bold';
        var bg     = data.bg === 'transparent' ? 'transparent' : (data.bg || '#c0392b');

        block.style.background = bg;

        var wrap = document.createElement('div');
        wrap.className = 'marquee-wrap';

        var span = document.createElement('span');
        span.className        = 'marquee-text';
        span.textContent      = text || '';
        span.style.color      = color;
        span.style.fontSize   = size + 'px';
        span.style.fontWeight = weight;
        span.style.paddingLeft = '100%';

        wrap.appendChild(span);
        block.appendChild(wrap);

        if (!text) return;

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
