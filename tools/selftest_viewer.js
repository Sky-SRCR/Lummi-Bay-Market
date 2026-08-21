// ============================================================
// SELF-TEST — the Viewer's poll, and when a sign stops claiming
// ============================================================
//   node tools/selftest_viewer.js
//
// `php -l` cannot see inline JavaScript, so the standing gate for viewer.php has
// been `node --check` over the extracted <script> body. That proves the file
// parses. It cannot prove the thing #26 is about, because the defect was not a
// throw — it was a `.catch` doing exactly what a `.catch` should do for a dropped
// packet, on a failure that was never going to stop.
//
// The chain was: one stored byte that is not valid UTF-8 → json_encode returns
// false → `echo false` prints nothing → 200, Content-Type: application/json, zero
// bytes → `r.json()` rejects → `.catch` clears the flag and returns → the sign
// keeps last week's prices and tries again in 30 seconds, into the same byte,
// forever, with nothing written down anywhere.
//
// Both ends are fixed and both ends are asserted, but the interesting half is
// here, because it is a judgement rather than a rule: a sign must not go dark for
// one dropped packet, and must not stay up for an hour of them. So this file runs
// the real poll loop against a fetch it controls and pins where that line is.
//
// It also holds the interaction between the two items in this pair. #28 made
// api.php answer 400, 404 and 503 where it used to answer 200 — and a non-2xx
// reply is not a rejected fetch. If the Viewer ever came to treat those as "the
// server is unreachable", a sign an admin had deliberately switched off would
// start counting down to a *different* notice. It does not, and that is checked.
//
// CLI only. Nothing here touches a database or a network.

const fs   = require('fs');
const path = require('path');
const { buildPageJs, PAGE_DEFAULTS } = require('./page_constants');

/** A PAGE_DEFAULTS entry, resolved — some of them read a file when they are asked. */
function pageDefault(name) {
    const v = PAGE_DEFAULTS[name];
    return (typeof v === 'function') ? v() : v;
}

const VIEWER = path.join(__dirname, '..', 'viewer.php');
const POLICY = path.join(__dirname, '..', 'lib', 'error_policy.php');

let checks = 0;
const fails = [];

function check(condition, label) {
    checks++;
    if (condition) { console.log('  ok   ' + label); }
    else { fails.push(label); console.log('  FAIL ' + label); }
}

function checkSame(expected, actual, label) {
    check(expected === actual, label
        + (expected === actual ? '' : ' — expected ' + JSON.stringify(expected)
                                    + ', got ' + JSON.stringify(actual)));
}

function section(title) { console.log('\n' + title); }

/** Let a stubbed fetch chain reach its last .then. The page returns no promises. */
async function settle() { for (let i = 0; i < 20; i++) { await Promise.resolve(); } }

// ---- A DOM with the two nodes this page emits, and nothing else -------------

function stubEl(tag) {
    const el = {
        tagName: tag, style: {}, dataset: {}, children: [],
        textContent: '', alt: '', src: '', type: '', className: '',
        autoplay: false, loop: false, muted: false, playsInline: false,
        offsetWidth: 200, offsetHeight: 40,
        classList: {
            _set: new Set(),
            add(c)      { this._set.add(c); },
            remove(c)   { this._set.delete(c); },
            contains(c) { return this._set.has(c); }
        },
        appendChild(child) { el.children.push(child); return child; },
        addEventListener() {}
    };
    // innerHTML is only ever written, and only ever with '' — the page empties the
    // canvas rather than building markup, which is what keeps textContent safe.
    Object.defineProperty(el, 'innerHTML', {
        get() { return ''; },
        set(v) { if (v === '') { el.children.length = 0; } }
    });
    return el;
}

const nodes = { 'viewer-canvas': stubEl('div'), 'viewer-notice': stubEl('div') };

global.document = {
    getElementById(id) { return Object.prototype.hasOwnProperty.call(nodes, id) ? nodes[id] : null; },
    createElement(tag) { return stubEl(tag); },
    addEventListener() {},
    body: { contains() { return true; } }
};
global.window = { innerWidth: 1920, innerHeight: 1080, addEventListener() {} };

// Timers the test drives. setInterval is the 30-second poll and must not run on its
// own; setTimeout is the watchdog and has to be *firable*, because a request that
// never settles is one of the shapes #26 came in and the only one no .catch sees.
let pendingTimeouts = [];
global.setInterval  = () => 0;
global.setTimeout   = (fn) => { pendingTimeouts.push(fn); return pendingTimeouts.length; };
global.clearTimeout = (id) => { if (id) { pendingTimeouts[id - 1] = null; } };
// requestAnimationFrame is captured rather than dropped. The marquee does all of its
// arithmetic inside the frame callback — it has to, because a span that has not been laid
// out has no width — so a stub that returns 0 and forgets can only ever see the DOM as it
// was before the first frame, which is one span and no transform (§4bz).
let pendingFrames = [];
global.requestAnimationFrame = (fn) => { pendingFrames.push(fn); return pendingFrames.length; };
global.clearInterval = () => {};

/** Run every frame that is due, at `ts` milliseconds. What they schedule waits for the
 *  next call, so a caller advances one frame at a time and chooses the clock. */
function frame(ts) {
    const due = pendingFrames;
    pendingFrames = [];
    due.forEach(function (fn) { if (fn) { fn(ts); } });
    return due.length;
}

/** Fire the watchdog armed by the poll in flight, as a stalled request would. */
function fireWatchdogs() {
    const due = pendingTimeouts;
    pendingTimeouts = [];
    due.forEach(function (fn) { if (fn) { fn(); } });
}

// ---- Fetches this test hands out --------------------------------------------

const A_LAYOUT = {
    status: 'success',
    display: { tag: 'drive-thru', bg_type: 'color', bg_val: '#101010' },
    elements: [
        { id: 1, type: 'text', x_pos: 10, y_pos: 10, width: 300, height: 60,
          z_index: 2, hidden: 0, section_id: null, manual_content: 'Sockeye 18.99' },
        { id: 2, type: 'text', x_pos: 10, y_pos: 90, width: 300, height: 60,
          z_index: 2, hidden: 0, section_id: null, manual_content: 'Halibut 24.99' }
    ],
    block_styles: {}
};

/** A reply that arrived and parsed — whatever the HTTP code beside it was. */
function replying(body, status) {
    return () => Promise.resolve({ ok: (status || 200) < 400, status: status || 200,
                                   json: () => Promise.resolve(body) });
}
/** The #26 shape exactly: 200, application/json, and nothing in the body. */
function zeroLength() {
    return () => Promise.resolve({ ok: true, status: 200,
        json: () => Promise.reject(new SyntaxError('Unexpected end of JSON input')) });
}
/** A dropped connection. */
function dropped() { return () => Promise.reject(new TypeError('Failed to fetch')); }
/** A request that never answers at all. */
function wedged() { return () => new Promise(function () {}); }

global.fetch = replying(A_LAYOUT);

// ---- The page's own JavaScript ----------------------------------------------

const php = fs.readFileSync(VIEWER, 'utf8');

// The one interpolation that is not a number.
let js = buildPageJs(VIEWER, {
    DISPLAY_TAG: 'drive-thru',
});

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

const canvas = document.getElementById('viewer-canvas');
const notice = document.getElementById('viewer-notice');

const showing = () => notice.style.display === 'flex';

// ---- One number, in two files ------------------------------------------------

section('The sign says one thing however it stopped working');

// ErrorPolicy hands the Screen this sentence when the server fails; the page uses
// the same one when the server stops answering. Two files, one sentence, checked
// rather than remembered — a sign that says two different things about one outage
// sends somebody looking for two faults.
const SCREEN_SENTENCE = 'This sign is temporarily unavailable.';
check(fs.readFileSync(POLICY, 'utf8').indexOf("'" + SCREEN_SENTENCE + "'") > -1,
      'the server\'s sentence for a Screen is the one this test expects');
check(php.indexOf("'" + SCREEN_SENTENCE + "'") > -1,
      'and the Viewer reaches for the same words when the server stops answering');

(async function () {

    section('A working sign');

    await (async () => { loadLayout(); await settle(); })();
    checkSame(2, canvas.children.length, 'a layout that arrives is drawn');
    check(!showing(),                    'with no notice over it');
    checkSame(0, _failedPolls,           'and nothing counted against it');

    section('One failed poll does not blank a price board (#26)');

    // The line this file exists to pin. Blanking a working sign for a dropped
    // packet would be a worse fault than the one #26 reports, so a single failure
    // has to change nothing a customer can see.
    global.fetch = dropped();
    loadLayout(); await settle();
    checkSame(1, _failedPolls,           'a failed poll is counted');
    check(!showing(),                    'and the sign keeps showing what it last knew');
    checkSame(2, canvas.children.length, 'with the prices still on it');

    for (let i = 2; i <= STALE_AFTER_FAILURES - 1; i++) {
        loadLayout(); await settle();
    }
    checkSame(STALE_AFTER_FAILURES - 1, _failedPolls, 'nine in a row are still tolerated');
    check(!showing(), 'because four and a half minutes of bad Wi-Fi is not a broken sign');

    section('But it does not claim to be current forever');

    loadLayout(); await settle();
    checkSame(STALE_AFTER_FAILURES, _failedPolls, 'the tenth reaches the limit');
    check(showing(),                              'and the sign stops showing prices it cannot confirm');
    checkSame(SCREEN_SENTENCE, notice.textContent, 'saying so in the words the server would have used');
    checkSame(0, canvas.children.length,          'with the stale layout taken down, not merely covered');

    section('And it comes back on its own');

    global.fetch = replying(A_LAYOUT);
    loadLayout(); await settle();
    checkSame(0, _failedPolls,           'a poll that answers clears the count');
    check(!showing(),                    'the notice comes off');
    checkSame(2, canvas.children.length, 'and the prices are drawn again');

    section('The shapes #26 actually arrived in');

    // The original defect, exactly: HTTP 200, Content-Type: application/json, and
    // zero bytes. Before this pair it was indistinguishable from a dropped packet
    // and, unlike one, it was never going to stop.
    global.fetch = zeroLength();
    for (let i = 0; i < STALE_AFTER_FAILURES; i++) { loadLayout(); await settle(); }
    check(showing(), 'a 200 with an empty body is a failure, and enough of them are noticed');

    global.fetch = replying(A_LAYOUT);
    loadLayout(); await settle();
    check(!showing(), 'and recovers the same way');

    // The shape no .catch could ever see. fetch has no timeout of its own, so a
    // request into a captive portal or a query blocked on a row lock simply never
    // settles — the poll before this one freed the flag and returned, which left
    // the count at zero however long the sign had been stranded.
    global.fetch = wedged();
    for (let i = 0; i < STALE_AFTER_FAILURES; i++) {
        loadLayout();
        fireWatchdogs();
        await settle();
    }
    check(showing(), 'a request that never answers is counted by the watchdog, not ignored');

    // ...and the late reply to a request already given up on must not resurrect it,
    // nor free the flag out from under the poll that replaced it.
    let releaseLate;
    global.fetch = () => new Promise(function (_, reject) { releaseLate = reject; });
    loadLayout();
    fireWatchdogs();
    await settle();
    const afterWatchdog = _failedPolls;
    releaseLate(new TypeError('Failed to fetch'));
    await settle();
    checkSame(afterWatchdog, _failedPolls,
              'a wedged request that rejects afterwards is not counted a second time');

    section('A real error code is an answer, not an outage (#28)');

    // api.php answers 400, 404 and 503 now where it used to answer 200. A non-2xx
    // reply is not a rejected fetch — the body is the same JSON either way — and
    // treating one as unreachable would count down a sign that an admin switched
    // off deliberately, towards a notice saying something else entirely.
    global.fetch = replying(A_LAYOUT);
    loadLayout(); await settle();
    checkSame(0, _failedPolls, 'starting from a working sign');

    global.fetch = replying({ status: 'inactive', message: 'This display is turned off',
                              display: null, elements: [], block_styles: [] }, 503);
    loadLayout(); await settle();
    check(showing(), 'a sign turned off shows the notice the server sent');
    checkSame('This display is turned off', notice.textContent, 'in the server\'s own words');
    checkSame(0, _failedPolls, 'and 503 is not counted as the sign being unreachable');

    global.fetch = replying({ status: 'unknown', message: 'Display not found',
                              display: null, elements: [], block_styles: [] }, 404);
    loadLayout(); await settle();
    checkSame('Display not found', notice.textContent, '404 likewise says what it means');
    checkSame(0, _failedPolls,                         'and likewise is not an outage');

    global.fetch = replying({ status: 'no_tag', message: 'No display specified',
                              display: null, elements: [], block_styles: [] }, 400);
    loadLayout(); await settle();
    checkSame('No display specified', notice.textContent, 'and so does 400');
    checkSame(0, _failedPolls,                           'nor is that one');

    // The distinction the whole section rests on: the *same* four hundred with no
    // body at all is a failure, because nothing answered the question.
    global.fetch = zeroLength();
    loadLayout(); await settle();
    checkSame(1, _failedPolls, 'while a reply that could not be read is still a failed poll');

    section('A block with nothing in it draws nothing (#45)');

    // Two block types used to explain themselves to the customer. An empty carousel
    // printed "Carousel — no slides added yet"; an empty table printed "Table — no
    // data" over a grey panel drawn to hold it. Both sentences were addressed to
    // whoever was building the layout, and neither could ever reach them — the
    // person reading a price board cannot add a slide to it.
    //
    // These run the renderers directly, because the interesting question is not
    // whether the page still parses but what ends up on the sign.

    /** Every word this block would put in front of a customer. */
    function inkIn(el) {
        var text = el.textContent || '';
        el.children.forEach(function (c) { text += inkIn(c); });
        return text;
    }
    /** Anything it would paint behind them. */
    function paintOn(el) {
        var s = el.style || {};
        return [s.cssText, s.background, s.backgroundColor, s.border].filter(Boolean).join(' ');
    }
    function drewNothing(content, render, label) {
        const block = stubEl('div');
        // Reaching "there is nothing to draw" has to be an answer, not a throw the
        // caller's catch turns into the same outcome by accident. The two differ
        // the moment anybody moves this call, and only one of them is a decision.
        let threw = null;
        try { render(block, content, {}); } catch (e) { threw = e; }
        check(threw === null, label + ' decides that without throwing'
                              + (threw ? ' — ' + threw.message : ''));
        checkSame(0,  block.children.length, label + ' appends nothing');
        checkSame('', inkIn(block),          label + ' writes no words onto the sign');
        checkSame('', paintOn(block),        label + ' paints no panel either');
    }

    drewNothing('{"slides":[],"interval":5000}', renderCarousel, 'a carousel with no slides');
    drewNothing('',                              renderCarousel, 'a carousel never configured');
    drewNothing('{"slides":',                    renderCarousel, 'a carousel whose content will not parse');
    // Element content is unvalidated for the non-text types, so `slides` can be
    // anything at all. "Not a list of slides" is a block with nothing showable in
    // it, which is this case — and reaching it by answer rather than by throwing
    // means the outcome does not depend on the caller's catch.
    drewNothing('{"slides":"three"}',            renderCarousel, 'a carousel whose slides are not a list');

    drewNothing('{"headers":[],"rows":[]}',      renderTable,    'a table with no columns and no rows');
    drewNothing('{"headers":["price"],"rows":[]}', renderTable,  'a table with columns but no rows');
    drewNothing('',                              renderTable,    'a table never configured');
    drewNothing('{"headers":{},"rows":{}}',      renderTable,    'a table whose columns are not a list');

    // The strings themselves, so neither can come back by a route these renderer
    // calls do not go through.
    //
    // Read with whole-line comments dropped, because viewer.php quotes both
    // sentences in its own comments in order to record what was removed and why,
    // and a check that fails on its own explanation gets deleted rather than
    // heeded — the same trap check_invariants.php had to be made comment-aware
    // for. A trailing comment on a line of code is *not* dropped, which errs
    // towards failing loudly: the right way round for a guard.
    const codeOnly = js.split('\n')
                       .filter(line => !/^\s*(\/\/|\/\*|\*)/.test(line))
                       .join('\n');
    // Matched on the ASCII half of each sentence on purpose. Both began with an em
    // dash, and a JavaScript source can spell that '—', '—' or '&mdash;' and
    // put the same character on the sign each way — so a guard that looked for the
    // dash would have been walked past by whichever spelling came next.
    check(codeOnly.indexOf('no slides added yet') === -1,
          'the sentence #45 names is no longer anything the page can draw');
    check(codeOnly.indexOf('no data') === -1,
          'nor is the one that sat beside it');

    section('Nor does a marquee with nothing to say (#45, second pass)');

    // Not a sentence this time — a colour. An unfinished marquee painted a solid
    // #c0392b band across the sign and scrolled an empty span along it, because
    // `block.style.background = bg` was assigned four lines above the block's own
    // `if (!text) return;`. The code already meant to draw nothing and drew a red
    // bar anyway, which is why only running it could show this.
    drewNothing('{"text":""}',                 renderMarquee, 'a marquee with no text');
    drewNothing('',                            renderMarquee, 'a marquee never configured');
    drewNothing('{"text":"   "}',              renderMarquee, 'a marquee holding only spaces');
    drewNothing('{"text":',                    renderMarquee, 'a marquee whose content will not parse');
    drewNothing('{"text":{}}',                 renderMarquee, 'a marquee whose text is not a message');
    drewNothing('{"text":"","bg":"#e67e22"}',  renderMarquee, 'a marquee with a colour picked but nothing to say');

    section('Nor a carousel whose slides are empty (#45, second pass)');

    // The other half of the same pass, one layer further in. A slide with no image
    // filled its image well with #1a1a2e — a navy rectangle standing in for a
    // picture nobody had chosen — so a carousel of blank slides rotated coloured
    // panels past the customer every five seconds without ever saying anything.
    drewNothing('{"slides":[{}]}',                        renderCarousel, 'a carousel whose one slide is empty');
    drewNothing('{"slides":[{},{},{}]}',                  renderCarousel, 'a carousel of three empty slides');
    drewNothing('{"slides":[{"imageOnly":true}]}',        renderCarousel, 'an image-only slide with no image');
    drewNothing('{"slides":[{"title":"","price":null}]}', renderCarousel, 'a slide with every field left blank');
    drewNothing('{"slides":[null,"three",7]}',            renderCarousel, 'a carousel whose slides are not slides');

    section('Nor a picture or a film nobody chose (#45, third pass)');

    // Not the page's own ink this time but the browser's. `img.src = ''` is a
    // *broken* image by definition, and an autoplaying <video> with no <source>
    // never plays anything — what either one looks like is then the browser's
    // decision, and a store's sign must not look different because of which
    // browser the television shipped with. Drawing nothing is the one rendering
    // that is the same everywhere, and the true one: there is no picture here.
    drewNothing('',        renderImage, 'an image block with no file');
    drewNothing('|cover',  renderImage, 'an image block with a fit but no file');
    drewNothing('   ',     renderImage, 'an image block whose path is blank');
    drewNothing(null,      renderImage, 'an image whose linked asset has been deleted');
    drewNothing({},        renderImage, 'an image whose content is not a path at all');
    drewNothing('',        renderVideo, 'a video block with no file');
    drewNothing('   ',     renderVideo, 'a video block whose path is blank');
    drewNothing(null,      renderVideo, 'a video whose linked asset has been deleted');

    // Guarding the other way. "Draw nothing" is about blocks with nothing in them,
    // and a fix that quietened a carousel which does have slides would be a far
    // worse fault than the one #45 reports.
    const filled = stubEl('div');
    renderCarousel(filled, JSON.stringify({ slides: [{ title: 'Dungeness', price: '$14/lb' }] }), {});
    check(filled.children.length > 0,             'a carousel that has a slide still draws it');
    check(inkIn(filled).indexOf('Dungeness') > -1, 'with the words the author wrote');
    check(inkIn(filled).indexOf('$14/lb')    > -1, 'and the price beside them');

    const table = stubEl('div');
    renderTable(table, JSON.stringify({ headers: ['free'], rows: [['Sockeye 18.99']] }), {});
    check(table.children.length > 0,                    'a table that has rows still draws them');
    check(inkIn(table).indexOf('Sockeye 18.99') > -1,   'with the prices in them');

    const running = stubEl('div');
    renderMarquee(running, JSON.stringify({ text: 'Fresh sockeye landed this morning', bg: '#c0392b' }));
    check(inkIn(running).indexOf('Fresh sockeye landed this morning') > -1,
          'a marquee that has something to say still says it');
    checkSame('#c0392b', running.style.background, 'on the bar the author picked for it');

    /** Every colour this block would paint, at any depth. */
    function paintUnder(el) {
        return [paintOn(el)].concat(el.children.map(paintUnder)).filter(Boolean).join(' ');
    }
    /** Every picture it would put on the sign. */
    function picturesUnder(el) {
        return (el.tagName === 'img' && el.src ? [el.src] : [])
               .concat(el.children.map(picturesUnder).reduce((a, b) => a.concat(b), []));
    }

    // A slide can be a picture and no words at all — that is what `imageOnly` is
    // for — so "has something in it" must count an image as something. Without
    // this, deciding a slide is empty whenever it has no text passes every check
    // above and quietly takes every photograph off every sign in the store.
    const picture = stubEl('div');
    renderCarousel(picture, JSON.stringify({ slides: [{ image: 'assets/crab.jpg', imageOnly: true }] }), {});
    check(picture.children.length > 0, 'a slide that is only a photograph is still drawn');
    check(picturesUnder(picture).indexOf('assets/crab.jpg') > -1, 'with the photograph on it');

    const photo = stubEl('div');
    renderImage(photo, 'uploads/salmon.jpg|cover');
    checkSame('uploads/salmon.jpg', picturesUnder(photo)[0], 'an image block that has a file still shows it');
    checkSame('cover', photo.children[0].style.objectFit,    'fitted the way the author asked');

    const film = stubEl('div');
    renderVideo(film, 'uploads/boat.webm');
    checkSame(1, film.children.length,                     'a video block that has a file still plays it');
    checkSame('uploads/boat.webm', film.children[0].children[0].src, 'from the path it was given');
    checkSame('video/webm',        film.children[0].children[0].type, 'with the type the browser needs to decide it can');

    // The case in between, and the one that says most about what this pass is: a
    // carousel that is part empty draws the part that isn't, and nothing for the
    // rest. Skipping the blanks in the same place the slides are built is what
    // keeps the rotation honest too — three slides, one showable, no timer.
    const mixed = stubEl('div');
    renderCarousel(mixed, JSON.stringify({ slides: [
        {}, { title: 'Dungeness', price: '$14/lb' }, { imageOnly: true }
    ] }), {});
    check(inkIn(mixed).indexOf('Dungeness') > -1,   'a real slide among empty ones is still drawn');
    checkSame(1, mixed.children[0].children.length, 'and the empty ones are not drawn beside it');
    check(paintUnder(mixed).indexOf('#1a1a2e') === -1,
          'no navy panel stands in for a picture nobody chose');

    // Drawing nothing here is only safe because the warning still exists where it
    // can be acted on. The Builder labels the same two blocks on its own canvas,
    // and that is the surface the author is looking at while they forget to add
    // the slides. Checked rather than assumed, because deleting that label would
    // turn this decision into a block nobody can see is empty.
    const builder = fs.readFileSync(path.join(__dirname, '..', 'builder.php'), 'utf8');
    check(builder.indexOf("'↻ Carousel — ' + slides.length") > -1,
          'the Builder still tells the author how many slides the carousel has');
    check(builder.indexOf("'⋞ Table — ' + headers.length") > -1,
          'and how many columns the table has');
    // The marquee's is not a count but the instruction itself, which is the whole
    // argument for the Viewer saying nothing: the words the customer used to get a
    // red bar instead of are sitting on the author's screen, next to the box that
    // fixes it. Matched on the ASCII half, for the reason given above.
    check(builder.indexOf('click to edit in inspector') > -1,
          'and tells the author outright when a marquee has no text yet');
    check(builder.indexOf("svgPlaceholder(el.width, el.height, 'Image')") > -1,
          'it draws a placeholder where an image block has no file');
    // The video was the one block with nothing on either surface. The Viewer went
    // silent for it, so the Builder had to start speaking: without this the block
    // would exist in the database and be drawn by neither page.
    check(builder.indexOf("svgPlaceholder(el.width, el.height, 'Video')") > -1,
          'and one where a video block has none either');

    // End to end, through the real poll: an empty carousel and an empty table
    // beside a price. The blocks are still appended — .element-block paints
    // nothing on its own — and the only words on the sign are the ones the store
    // meant to put there.
    const el = (id, type, content) => ({
        id: id, type: type, x_pos: 10, y_pos: id * 100, width: 300, height: 80,
        z_index: 2, hidden: 0, section_id: null, manual_content: content
    });
    global.fetch = replying({
        status: 'success',
        display: { tag: 'drive-thru', bg_type: 'color', bg_val: '#101010' },
        elements: [
            el(1, 'text',     'Sockeye 18.99'),
            el(2, 'carousel', '{"slides":[]}'),
            el(3, 'table',    '{"headers":[],"rows":[]}'),
            el(4, 'marquee',  '{"text":""}'),
            el(5, 'carousel', '{"slides":[{},{"imageOnly":true}]}'),
            el(6, 'image',    ''),
            el(7, 'video',    '')
        ],
        block_styles: {}
    });
    loadLayout(); await settle();
    checkSame(7, canvas.children.length, 'all seven blocks are laid out');
    checkSame('Sockeye 18.99', inkIn(canvas),
              'and a customer reads the price, and nothing addressed to the author');
    // The blocks only — the canvas paints its own colour, which is the Display's
    // background and nothing to do with what is drawn on top of it.
    const onTheBlocks = canvas.children.map(paintUnder).join(' ');
    check(onTheBlocks.indexOf('#c0392b') === -1, 'with no red bar over an unwritten marquee');
    check(onTheBlocks.indexOf('#1a1a2e') === -1, 'and no navy panel over an unchosen picture');
    checkSame(0, canvas.children.map(c => c.children.length).reduce((a, b) => a + b, 0),
              'and not one of the six empty blocks put anything inside itself');

    section('The canvas fills the Screen without distorting a price');

    // The one piece of geometry on the page a customer looks at, and until §4bh nothing
    // here could assert it: CANVAS_W and CANVAS_H are interpolated by PHP, this suite
    // stripped that to the literal `0`, and every scale it computed was
    // `1920 / 0` — Infinity, with NaN margins. Nothing threw, so nothing noticed.
    function fitInto(w, h) {
        window.innerWidth  = w;
        window.innerHeight = h;
        scaleToFit();
        return canvas.style;
    }

    let fit = fitInto(1920, 1080);
    checkSame('scale(1)', fit.transform, 'a Screen the size of the canvas draws it at its own size');
    checkSame('0px', fit.marginLeft, 'with nothing to centre horizontally');
    checkSame('0px', fit.marginTop,  'and nothing vertically');

    fit = fitInto(1280, 720);
    checkSame('scale(0.6666666666666666)', fit.transform,
              'a smaller Screen of the same shape scales the whole canvas down');
    checkSame('0px', fit.marginLeft, 'and still fills it edge to edge');

    // The letterbox, which is what `Math.min` is for: a 4:3 Screen showing a 16:9 sign
    // takes the width it can use and leaves a band above and below, rather than
    // stretching every price 33% taller than it was designed.
    fit = fitInto(1600, 1200);
    checkSame('scale(0.8333333333333334)', fit.transform,
              'a Screen of a different shape takes the smaller of the two ratios');
    checkSame('0px',   fit.marginLeft, 'so the width is what fills');
    checkSame('150px', fit.marginTop,  'and the letterbox is split evenly above and below');

    // The other way round, because a min() written as max() letterboxes one axis
    // correctly and overflows the other — and only one of the two shapes shows it.
    fit = fitInto(1920, 600);
    checkSame('scale(0.5555555555555556)', fit.transform,
              'and on a short wide Screen it is the height that decides');
    // Rounded, because the exact float is what the browser would set and asserting
    // its last digit is asserting IEEE 754 rather than the centring.
    checkSame(427, Math.round(parseFloat(fit.marginLeft)),
              'with the pillarbox split evenly either side');

    section('One poll at a time');

    // _loading is what stops the 30-second interval stacking requests on a slow
    // link. The guard belongs to the poll that set it, and only the first of the
    // three ways a poll can end may clear it.
    global.fetch = wedged();
    loadLayout();
    const before = canvas.children.length;
    global.fetch = () => { throw new Error('a second poll started while the first was in flight'); };
    check((function () { try { loadLayout(); return true; } catch (e) { return false; } })(),
          'a second poll while one is in flight does not start');
    checkSame(before, canvas.children.length, 'and changes nothing');
    fireWatchdogs();

    // ---- The marquee's loop is a clock, not the width of the sign (§4bz) --------

    section('A marquee comes round on a clock, not on the width of the sign (§4bz)');

    // What it used to be: the next pass began when the tail of the message had cleared the
    // far edge, so the interval was `(blockWidth + messageWidth) / speed`. On a 1920px board
    // at the default speed that is twenty-four seconds for a message four seconds long, and
    // there was no setting anywhere that could shorten it — the sign's own width was the
    // wait. What it is now: one message plus one gap, and the gap is a number.

    checkSame(pageDefault('MARQUEE_GAP_DEFAULT'), marqueeGapSeconds(undefined),
              'a marquee published before this setting existed gets the default gap');
    checkSame(pageDefault('MARQUEE_GAP_DEFAULT'), marqueeGapSeconds(null),
              'so does one whose gap is null');
    checkSame(pageDefault('MARQUEE_GAP_DEFAULT'), marqueeGapSeconds({}),
              'and one whose gap is an object, which is the shape unvalidated JSON comes in');
    checkSame(pageDefault('MARQUEE_GAP_DEFAULT'), marqueeGapSeconds('now and then'),
              'and one whose gap is a sentence');
    checkSame(pageDefault('MARQUEE_GAP_DEFAULT'), marqueeGapSeconds(NaN),
              'and one whose gap will not compare with anything');
    checkSame(pageDefault('MARQUEE_GAP_DEFAULT'), marqueeGapSeconds(Infinity),
              'and one whose gap has no end');
    checkSame(3.5, marqueeGapSeconds(3.5), 'a number is the number');
    checkSame(3.5, marqueeGapSeconds('3.5'),
              'and so is a number that arrived from a form as a string');
    // The one that separates a clamp from a default: zero is a *choice* — copies nose to
    // tail — and `|| DEFAULT` would have quietly turned it into two seconds.
    checkSame(0, marqueeGapSeconds(0), 'zero is a gap somebody chose, not a gap nobody set');
    checkSame(pageDefault('MARQUEE_GAP_MIN'), marqueeGapSeconds(-5),
              'a negative gap is floored rather than reversed');
    checkSame(pageDefault('MARQUEE_GAP_MAX'), marqueeGapSeconds(900),
              'and a quarter of an hour is capped');
    check(pageDefault('MARQUEE_GAP_DEFAULT') > pageDefault('MARQUEE_GAP_MIN'),
          'the default is a real gap, so nothing already published repeats nose to tail');

    checkSame(1, marqueeCopies(0, 1920),
              'a message with no measurable width asks for one copy and no arithmetic');
    checkSame(1, marqueeCopies(-5, 1920),   'and so does a negative one');
    checkSame(12, marqueeCopies(200, 1920), 'a 200px unit across a 1920px block wants twelve');
    checkSame(3,  marqueeCopies(2000, 1920),
              'a unit wider than the block wants three — the one showing, the one leaving, '
              + 'the one arriving');
    checkSame(2,  marqueeCopies(200, 0),
              'a block with no width still wants two, because one cannot cover its own snap');
    checkSame(256, marqueeCopies(1, 1920),
              'and a one-pixel unit is bounded rather than asking for two thousand spans');

    /** A marquee rendered and laid out: block width and message width, as a browser would. */
    function marqueeOn(data, blockW, copyW) {
        pendingFrames = [];
        const block = stubEl('div');
        renderMarquee(block, JSON.stringify(data));
        const wrap  = block.children[0];
        const strip = wrap.children[0];
        wrap.offsetWidth = blockW;
        strip.children[0].offsetWidth = copyW;
        return { block, wrap, strip };
    }
    /** The strip's translateX, in pixels. */
    function atX(strip) {
        const m = /translateX\((-?[0-9.]+)px\)/.exec(strip.style.transform || '');
        return m ? parseFloat(m[1]) : null;
    }

    const wide = marqueeOn({ text: 'Fresh sockeye landed this morning', speed: 100, gap: 2 },
                           1920, 400);
    checkSame('marquee-strip', wide.strip.className,
              'the transform is carried by one strip, so every copy keeps its spacing');
    checkSame(1, wide.strip.children.length, 'which holds one copy before the first frame');
    checkSame(null, atX(wide.strip), 'and has not moved, because nothing has been measured');

    frame(0);
    // unit = 400 + 2*100 = 600. ceil(1920/600) + 2 = 6.
    checkSame(6, wide.strip.children.length, 'the first frame measures the message and fills the strip');
    checkSame('200px', wide.strip.children[0].style.marginRight,
              'two seconds at a hundred pixels a second is two hundred pixels of blank');
    checkSame('200px', wide.strip.children[5].style.marginRight, 'on every copy, not just the first');
    checkSame('Fresh sockeye landed this morning', wide.strip.children[3].textContent,
              'and every copy says the same thing');
    checkSame(1920, atX(wide.strip),
              'the message still enters from the far edge, so a sign that has just reloaded '
              + 'does not open halfway through a sentence');

    frame(1000);
    checkSame(1820, atX(wide.strip), 'a second later it has moved one second of travel');
    frame(4000);
    checkSame(1520, atX(wide.strip), 'and keeps moving at the speed it was given');

    // The whole point, stated as arithmetic: the same message at the same speed loops in the
    // same time on a narrow block and a wide one. Before this, the wide one waited five times
    // as long, and nothing on any form could change that.
    function loopLength(blockW) {
        const m = marqueeOn({ text: 'Fresh sockeye landed this morning', speed: 100, gap: 2 },
                            blockW, 400);
        frame(0);
        let t = 0, seen = atX(m.strip), jumped = null;
        while (t < 100000 && jumped === null) {
            t += 100;
            frame(t);
            const now = atX(m.strip);
            if (now > seen) { jumped = t; }      // the snap back — one loop has gone by
            seen = now;
        }
        return jumped;
    }
    const narrowLoop = loopLength(400);
    const wideLoop   = loopLength(1920);
    check(narrowLoop !== null && wideLoop !== null, 'the strip snaps back rather than running away');
    checkSame(narrowLoop === null ? null : narrowLoop - 4000, wideLoop === null ? null : wideLoop - 19200,
              'and the loop after the first pass is the same length on a 400px block and a '
              + '1920px one — the gap decides it, not the sign');

    // A gap somebody set to nothing, and the difference from a gap nobody set.
    const tight = marqueeOn({ text: 'Fresh sockeye', speed: 80, gap: 0 }, 800, 300);
    frame(0);
    checkSame('0px', tight.strip.children[0].style.marginRight,
              'a gap of zero puts no blank between the copies');
    checkSame(5, tight.strip.children.length, 'and the strip is filled from the message alone');

    // A block that never gets laid out. The old loop read the width every frame and simply
    // reset to zero; this one has to decide once, and deciding "keep asking" would be a
    // frame loop on a television that nothing here would ever report.
    const unmeasurable = marqueeOn({ text: 'Fresh sockeye', speed: 80, gap: 0 }, 0, 0);
    frame(0);
    checkSame(0, pendingFrames.length,
              'a message with no width at all stops rather than spinning frames for ever');
    checkSame(1, unmeasurable.strip.children.length, 'leaving the one copy standing and readable');

    // A television that was asleep, or a tab the browser throttled: one `dt` of a whole
    // minute, which is six units of travel at once. A single subtraction of `unit` would
    // have parked the strip six screens off to the left and left the sign blank until it
    // crawled back — so the wrap is a `while`, and a minute is what makes the two spellings
    // tell different stories. Thirty seconds does not: it is one unit, and `if` handles it.
    const woken = marqueeOn({ text: 'Fresh sockeye landed this morning', speed: 100, gap: 2 },
                            1920, 400);
    frame(0);
    frame(1000);
    frame(61000);
    const x = atX(woken.strip);
    check(x !== null && x > -600 && x <= 0,
          'a sign that was asleep for a minute comes back inside one loop, not six screens '
          + 'to the left');

    // And the marquee is still cancellable: `stopAnimations` runs on every re-render and a
    // frame that arrives after it must not schedule another.
    const stopped = marqueeOn({ text: 'Fresh sockeye', speed: 80, gap: 2 }, 800, 300);
    frame(0);
    stopAnimations();
    pendingFrames = [];
    frame(1000);
    checkSame(0, pendingFrames.length, 'a cancelled marquee schedules no further frames');

    // Anchored, for the reason `selftest_layout.php` anchors its own: without a
    // number here, deleting half this file still reports a clean run. Four of the
    // eight node suites carried one and four did not (§4bh).
    const expected = 215;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected '
                   + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    if (fails.length) {
        fails.forEach(f => console.log('  FAILED: ' + f));
        process.exit(1);
    }
    process.exit(0);
})();
