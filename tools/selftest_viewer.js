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
global.requestAnimationFrame = () => 0;
global.clearInterval = () => {};

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

let js = php.replace(/<\?(php|=)[\s\S]*?\?>/g, '0')
            .match(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)
            .map(b => b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''))
            .join('\n');

// The one interpolation that is not a number.
js = js.replace(/^\s*var DISPLAY_TAG\s*=.*$/m, 'var DISPLAY_TAG = "drive-thru";');

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

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    if (fails.length) {
        fails.forEach(f => console.log('  FAILED: ' + f));
        process.exit(1);
    }
    process.exit(0);
})();
