// ============================================================
// SELF-TEST — the read-only Builder, with its controls absent
// ============================================================
//   node tools/selftest_builder_readonly.js
//
// `php -l` cannot see inline JavaScript and builder.php is ~3000 lines of it, so
// the standing gate for that file is `node --check` over the extracted <script>
// body. That proves the file parses. It cannot prove the thing this test exists
// for: that the page still *works* when half its markup is deliberately not
// emitted.
//
// A read-only Builder — somebody else holds the edit lock (ADR-0007) — now ships
// without the inspector, the align bar or the two editor modals. Every
// `getElementById` of a node in one of them therefore returns null on that page,
// and some of those lookups sit on code that still runs: the canvas-area
// mousedown handler fires on every click whether or not anything can be selected.
// An unguarded one there is an uncaught TypeError on every click, which is
// exactly the defect this found in `clearTargetSection()` — a lookup guarded on
// the account's role while the node it wanted depended on the lock as well.
//
// So: strip the PHP, force the two page constants to what a read-only basic
// account gets, stub a DOM that has only the ids that page actually emits, and
// run the paths that survive. Anything reaching for a control that is not there
// throws, and a throw is a failure.
//
// CLI only. Nothing here touches a database or a network.

const fs   = require('fs');
const path = require('path');

const BUILDER = path.join(__dirname, '..', 'builder.php');

let checks = 0;
const fails = [];

function check(condition, label) {
    checks++;
    if (condition) { console.log('  ok   ' + label); }
    else { fails.push(label); console.log('  FAIL ' + label); }
}

/** Run something that must not throw. Awaits it, so a rejected fetch chain counts. */
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

// ---- The source, and what it promises ---------------------------------------

const php = fs.readFileSync(BUILDER, 'utf8');

section('The editing controls are not in a read-only page');

// Each of these must sit inside a `<?php if (!$readOnly): ?>` block. Checked by
// walking the conditionals rather than by eyeballing the file, because the whole
// point is that nobody has to remember.
function emittedOnlyWhenEditable(marker) {
    const at = php.indexOf(marker);
    if (at < 0) { return false; }
    let depth = 0, guarded = false;
    const re = /<\?php\s+(if\s*\((.*?)\)\s*:|else\s*:|elseif\s*\((.*?)\)\s*:|endif;)\s*\?>/g;
    let m;
    while ((m = re.exec(php)) && m.index < at) {
        if (m[1].startsWith('if')) {
            depth++;
            if (/^\s*!\s*\$readOnly\s*$/.test(m[2])) { guarded = true; }
        } else if (m[1].startsWith('endif')) {
            depth--;
            if (depth === 0) { guarded = false; }
        }
    }
    return guarded && depth > 0;
}

check(emittedOnlyWhenEditable('<div id="align-bar">'),              'the align bar is emitted only when the page can edit');
check(emittedOnlyWhenEditable('<div id="inspector">'),              'so is the inspector');
check(emittedOnlyWhenEditable('<div id="carousel-modal-overlay">'), 'so is the carousel editor');
check(emittedOnlyWhenEditable('<div id="table-modal-overlay">'),    'so is the table editor');

// ---- A DOM with only what that page emits -----------------------------------

// The ids builder.php still renders when $readOnly is true and the account is
// basic. Everything else resolves to null, which is what the browser will do.
const PRESENT = new Set([
    'lock-banner', 'lock-idle-bar', 'lock-lapsed-bar', 'lock-lost-bar', 'lock-holder',
    'control-bar', 'zoom-readout', 'editor-frame', 'canvas-sizer', 'builder-canvas',
    'toast', 'resize-label', 'display-off-banner', 'top-nav'
]);

function stubEl(id) {
    return {
        id, style: {}, dataset: {}, children: [], files: [],
        value: '', textContent: '', innerHTML: '', checked: false,
        offsetWidth: 100, offsetHeight: 100, clientWidth: 800, clientHeight: 600,
        scrollLeft: 0, scrollTop: 0, parentElement: null,
        classList: { add() {}, remove() {}, contains() { return false; } },
        appendChild() {}, removeChild() {}, insertBefore() {}, remove() {},
        addEventListener() {}, focus() {}, blur() {},
        querySelector() { return null; }, querySelectorAll() { return []; },
        closest() { return null; }, getAttribute() { return null; }, setAttribute() {},
        getBoundingClientRect() { return { left: 0, top: 0, width: 100, height: 100 }; }
    };
}

const nodes = {};
global.document = {
    getElementById(id) {
        if (!PRESENT.has(id)) { return null; }
        return nodes[id] || (nodes[id] = stubEl(id));
    },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    createElement(tag) { return stubEl(tag); },
    addEventListener() {},
    body: stubEl('body'),
    activeElement: null,
    execCommand() {},
    caretRangeFromPoint: null
};
global.window      = { getSelection() { return null; }, addEventListener() {}, innerWidth: 1280, innerHeight: 800 };
global.navigator   = { sendBeacon() { return true; } };
global.fetch       = () => Promise.resolve({ json: () => Promise.resolve({}) });
global.interact    = () => ({ draggable() { return this; }, resizable() { return this; },
                              on() { return this; }, unset() {} });
global.confirm     = () => false;
global.alert       = () => {};
global.FormData    = function () { this.append = function () {}; };
global.setTimeout  = () => 0;
global.setInterval = () => 0;
global.clearTimeout = () => {};

// ---- The page's own JavaScript ----------------------------------------------

// Strip PHP the way `node --check` already does for the standing gate, then take
// the inline <script> bodies. `0` is a valid expression everywhere the page
// interpolates a value, which is why every interpolation there is a number, a
// json_encode, or a bare true/false.
let js = php.replace(/<\?(php|=)[\s\S]*?\?>/g, '0')
            .match(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)
            .map(function (b) { return b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''); })
            .join('\n');

// What a basic account looking at somebody else's edit session gets.
js = js.replace(/^var READ_ONLY\s*=.*$/m, 'var READ_ONLY = true;')
       .replace(/^var IS_ADMIN\s*=.*$/m,  'var IS_ADMIN = false;');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ---- The paths that still run -----------------------------------------------

(async function () {
    section('What still runs when the controls are gone');

    // All three of these fire on one mousedown anywhere in the canvas area, which
    // is the most-travelled path on a page where nothing can be selected.
    await survives('a click in the canvas area deselects with no inspector to hide', () => deselectAll());
    await survives('and clears multi-select with no align bar to update',            () => clearMultiSel());
    await survives('and clears the target section with no banner to write to',       () => clearTargetSection());
    await survives('targeting a section is a no-op rather than a throw',             () => setTargetSection(stubEl('s')));
    await survives('the align bar update finds nothing to update',                   () => updateAlignBar());

    // Runs on every page load, read-only or not: the library is still needed to
    // render a block that points at an entry, even with no dropdown to fill.
    global.fetch = () => Promise.resolve({
        json: () => Promise.resolve([{ id: 1, type: 'text', content: 'Sockeye 18.99', label: 'Sockeye' }])
    });
    await survives('the asset library loads with no dropdown to put it in', () => loadAssets());

    section('And what can no longer be reached');

    // Not reachable from the page — nothing on a read-only page can select a
    // block — but asserted anyway, because "unreachable" is a property of today's
    // markup and these are the functions a future control would call.
    const block = stubEl('b');
    block.dataset.type = 'text';
    await survives('showing an inspector that is not there does nothing', () => showInspector(block));
    await survives('multi-select does not reach for it either',           () => toggleMultiSel(stubEl('b2')));
    multiSel.length = 0;   // undo the block the line above pushed in

    await survives('a slide upload from a read-only page uploads nothing', () => uploadSlideImage(stubEl('i')));
    await survives('align actions find no targets',   () => { alignBlocks('left'); alignToParent('left'); });
    await survives('delete finds nothing to delete',  () => deleteSelected());
    await survives('publish refuses instead of posting', () => publishCanvas());

    // The expected total, for the same reason selftest_layout.php carries one:
    // without it, deleting half this file still reports a clean run.
    const expected = 16;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(fails.length ? 1 : 0);
})();
