// ============================================================
// SELF-TEST — the Viewer's poll, and what it does with a bad answer
// ============================================================
//   node tools/selftest_viewer.js
//
// `viewer.php` is the one page in this app that runs unattended, on a TV, in front
// of customers, with nobody to reload it. `php -l` cannot see its inline
// JavaScript, and `node --check` over the extracted <script> proves only that it
// parses — which is exactly the wrong guarantee for the defect class this file
// exists for. Every real failure that page has had reads perfectly:
//
//   · the layout hash latched before the render it described, so one bad element
//     blanked the sign permanently and every later poll compared equal (§4g);
//   · a wedged request left `_loading` true forever, silently ending all polling;
//   · and the one this file was written for — an unreadable reply going down the
//     `.catch()` branch, which is the branch for "we never reached the server" and
//     therefore deliberately leaves the sign exactly as it is.
//
// That last one is what makes the status codes of decision #28 load-bearing rather
// than decorative. `api.php` answers 503 for a Display that was turned off, and the
// Viewer normally learns that from the JSON body. But this app is served through a
// CDN, and anything in the middle that answers *for* the endpoint with a page of its
// own — an error page, a captive portal, the host's own 500 — replaces that body
// while the status line survives. Before this, such a reply left last week's prices
// on a sign that should have been showing a notice, forever, with nothing said
// anywhere. The rule now is: **the server answered, so believe it** — and a non-2xx
// is an answer that was never a working sign, whoever composed it.
//
// So: strip the PHP, stub a DOM and a fetch, and run the page's own poll through
// every way an answer can arrive. CLI only. No database, no network.

const fs   = require('fs');
const path = require('path');

const VIEWER = path.join(__dirname, '..', 'viewer.php');

let checks = 0;
const fails = [];

function check(condition, label) {
    checks++;
    if (condition) { console.log('  ok   ' + label); }
    else { fails.push(label); console.log('  FAIL ' + label); }
}

/** Run something that must not throw. Awaited, so a rejected fetch chain counts. */
async function survives(label, fn) {
    checks++;
    try {
        await fn();
        console.log('  ok   ' + label);
    } catch (e) {
        fails.push(label + ' — ' + e);
        console.log('  FAIL ' + label + ' — ' + e);
    }
}

function section(title) { console.log('\n' + title); }

/**
 * Let the stubbed fetch chain finish.
 *
 * `loadLayout()` returns nothing — nothing in a browser would want it — so awaiting
 * the call proves only that its synchronous part ran. The chain is three `.then`s
 * deep now (text, parse, render), so the microtask queue needs draining properly.
 */
async function settle() { for (let i = 0; i < 30; i++) { await Promise.resolve(); } }

// ---- A DOM the Viewer can draw into -----------------------------------------

// Richer than the builder suites' stub in one way that matters here: appended
// children are kept, and assigning innerHTML = '' drops them, because "did the sign
// get emptied and not refilled" is the question half this file is asking.
function stubEl(tag) {
    const el = {
        tagName: tag, style: {}, dataset: {}, _kids: [],
        value: '', textContent: '', checked: false, src: '', alt: '',
        offsetWidth: 120, offsetHeight: 40, className: '',
        classList: {
            _on: new Set(),
            add(c) { this._on.add(c); }, remove(c) { this._on.delete(c); },
            contains(c) { return this._on.has(c); }
        },
        appendChild(child) { el._kids.push(child); return child; },
        removeChild() {}, remove() {}, addEventListener() {},
        querySelector() { return null; }, querySelectorAll() { return []; },
        setAttribute() {}, getAttribute() { return null; },
        getBoundingClientRect() { return { left: 0, top: 0, width: 120, height: 40 }; }
    };
    Object.defineProperty(el, 'innerHTML', {
        get() { return el._kids.length ? '<stub>' : ''; },
        set(v) { if (!v) { el._kids.length = 0; } }
    });
    Object.defineProperty(el, 'cssText', { get() { return ''; }, set() {} });
    return el;
}

const nodes = { 'viewer-canvas': stubEl('div'), 'viewer-notice': stubEl('div') };

global.document = {
    getElementById(id) { return nodes[id] || null; },
    createElement(tag) { return stubEl(tag); },
    addEventListener() {},
    body: { contains() { return false; } }
};
global.window = { addEventListener() {}, innerWidth: 1920, innerHeight: 1080 };
global.setInterval = () => 0;
global.setTimeout  = () => 0;
global.clearInterval = () => {};
global.clearTimeout  = () => {};
global.requestAnimationFrame = () => 0;

// ---- What the endpoint answered ---------------------------------------------

/** A reply that arrived: a status line and a body, exactly as fetch reports them. */
function answers(status, bodyText) {
    global.fetch = () => Promise.resolve({
        ok: status >= 200 && status < 300,
        status: status,
        text: () => Promise.resolve(bodyText)
    });
}

/** A reply that never arrived at all — DNS, TLS, a dropped connection. */
function neverArrives() {
    global.fetch = () => Promise.reject(new Error('offline'));
}

function layoutJson(priceText) {
    return JSON.stringify({
        status: 'success',
        display: { bg_type: 'color', bg_val: '#1a1a2e' },
        block_styles: {},
        elements: [
            { id: 1, type: 'section', temp_id: 's1', x_pos: 0, y_pos: 0, width: 900, height: 600, z_index: 1, hidden: 0 },
            { id: 2, type: 'text', section_id: 1, block_subtype: 'price', manual_content: priceText,
              x_pos: 10, y_pos: 10, width: 200, height: 60, z_index: 2, hidden: 0 }
        ]
    });
}

const canvas = nodes['viewer-canvas'];
const notice = nodes['viewer-notice'];
const onScreen = () => notice.style.display === 'flex';
const drawn    = () => canvas._kids.length;

// ---- The page's own JavaScript ----------------------------------------------

// Same treatment as the builder suites and the standing `node --check` gate: every
// PHP interpolation on this page is a number or a json_encode, so `0` is a valid
// expression in all of their places.
const php = fs.readFileSync(VIEWER, 'utf8');
let js = php.replace(/<\?(php|=)[\s\S]*?\?>/g, '0')
            .match(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)
            .map(b => b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''))
            .join('\n');

// The three page constants, as a real Screen gets them. DISPLAY_TAG is interpolated
// with json_encode, so the stripper leaves a 0 where a string belongs.
js = js.replace(/^\s*var CANVAS_W\s*=.*$/m, 'var CANVAS_W = 1920;')
       .replace(/^\s*var CANVAS_H\s*=.*$/m, 'var CANVAS_H = 1080;')
       .replace(/^\s*var DISPLAY_TAG\s*=.*$/m, 'var DISPLAY_TAG = "drive-thru";');

check(/var DISPLAY_TAG = "drive-thru";/.test(js), 'the page constants were replaced, so this runs what a Screen runs');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

(async function () {

    section('An answer that arrived and could be read');

    answers(200, layoutJson('Sockeye 18.99'));
    await survives('a successful poll renders', () => loadLayout());
    await settle();
    check(drawn() > 0, 'and the sign has something on it');
    check(!onScreen(), 'with no notice over the top');

    // The latch, which §4g found set before the render it described.
    const after = drawn();
    answers(200, layoutJson('Sockeye 18.99'));
    await survives('an identical answer is a no-op', () => loadLayout());
    await settle();
    check(drawn() === after, 'so an unchanged layout is not redrawn every 30 seconds');

    answers(200, layoutJson('Sockeye 21.49'));
    await survives('a changed answer re-renders', () => loadLayout());
    await settle();
    check(drawn() > 0, 'and the new price is on the sign');

    section('An answer that says it is not a working sign');

    // The ordinary case: api.php answers 503 for a Display that was turned off, and
    // the body says so. This is the path §4b's "flips to the notice within one poll"
    // promise rides on.
    answers(503, JSON.stringify({ status: 'inactive', message: 'This display is turned off',
                                  display: null, elements: [], block_styles: {} }));
    await survives('a display turned off does not throw', () => loadLayout());
    await settle();
    check(onScreen(), 'the notice is on the screen');
    check(notice.textContent === 'This display is turned off', 'in the words ADR-0003 chose');
    check(drawn() === 0, 'and the old layout is gone rather than left underneath');

    // A 200 body that says failure still has to be believed. The status line is a
    // second opinion, never a veto: an intermediary that rewrites a 503 to a 200
    // must not turn a notice back into a sign.
    answers(200, JSON.stringify({ status: 'unknown', message: 'Display not found' }));
    await survives('a failure reported in a 200 body is still a failure', () => loadLayout());
    await settle();
    check(onScreen() && notice.textContent === 'Display not found',
          'so a rewritten status line cannot put a dead display back on the air');

    section('An answer that arrived and could not be read');

    // The branch this file was written for. Something answered for the endpoint —
    // a CDN error page, a captive portal, the host's own 500 — so the JSON is gone
    // but the status line survived.
    answers(200, layoutJson('Sockeye 18.99'));
    await loadLayout(); await settle();
    check(drawn() > 0, 'starting from a sign with a layout on it');

    answers(503, '<html><body>Origin unreachable</body></html>');
    await survives('an unreadable non-2xx does not throw', () => loadLayout());
    await settle();
    check(onScreen(), 'and the sign says so rather than showing last week\'s prices');
    check(drawn() === 0, 'the stale layout is taken down');
    check(/temporarily unavailable/i.test(notice.textContent),
          'in words a customer can read, with no status code or host name in them');

    // The opposite premise, and it must not be treated the same way: a 200 we could
    // not parse is a truncated success, and blanking a working sign over one garbled
    // reply is the failure mode §4g's latch fix exists to avoid.
    answers(200, layoutJson('Sockeye 18.99'));
    await loadLayout(); await settle();
    check(drawn() > 0 && !onScreen(), 'back to a working sign');

    answers(200, 'not json at all');
    await survives('an unreadable 200 does not throw', () => loadLayout());
    await settle();
    check(!onScreen(), 'and does not blank a working sign over one garbled reply');
    check(_layoutHash === '', 'but drops the latch so the next good answer redraws from scratch');

    section('An answer that never arrived');

    answers(200, layoutJson('Sockeye 18.99'));
    await loadLayout(); await settle();
    check(drawn() > 0, 'from a working sign again');

    neverArrives();
    await survives('a dropped connection does not throw', () => loadLayout());
    await settle();
    check(!onScreen(), 'and never reaching the server leaves the sign exactly as it was');
    check(_loading === false, 'while still allowing the next poll to run');
    check(_layoutHash === '', 'and re-rendering from scratch when it comes back');

    // A reply that arrived and a reply that never did are the two halves of this,
    // and the whole point is that they are not the same. If they ever collapse into
    // one branch again, one of these two checks has to fail.
    answers(503, 'nothing readable');
    await loadLayout(); await settle();
    const noticedOnRefusal = onScreen();
    answers(200, layoutJson('Sockeye 18.99'));
    await loadLayout(); await settle();
    neverArrives();
    await loadLayout(); await settle();
    check(noticedOnRefusal && !onScreen(),
          'an unreadable refusal and an unreachable server are told apart');

    section('One bad element never takes the sign down');

    // Element content is deliberately unvalidated for the non-text types
    // (invariant 6), so a hand edit or an older Builder can put a table on the sign
    // whose `rows` is not an array.
    answers(200, JSON.stringify({
        status: 'success',
        display: { bg_type: 'color', bg_val: '#1a1a2e' },
        block_styles: {},
        elements: [
            { id: 1, type: 'table', manual_content: '{"headers":["price"],"rows":"not-an-array"}',
              x_pos: 0, y_pos: 0, width: 300, height: 200, z_index: 1, hidden: 0 },
            { id: 2, type: 'text', block_subtype: 'price', manual_content: 'Sockeye 18.99',
              x_pos: 0, y_pos: 220, width: 300, height: 60, z_index: 2, hidden: 0 }
        ]
    }));
    await survives('a malformed element does not throw', () => loadLayout());
    await settle();
    check(drawn() > 0, 'and the rest of the sign stays up');
    check(_layoutHash !== '', 'with the hash latched only after a render that finished');

    // The expected total, for the same reason the other suites carry one: without it,
    // deleting half this file still reports a clean run.
    const expected = 32;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    fails.forEach(f => console.log('  FAILED: ' + f));
    process.exit(fails.length ? 1 : 0);
})();
