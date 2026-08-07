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

/**
 * Let a stubbed fetch chain finish.
 *
 * The page's handlers do not return their promises — nothing in a browser would want
 * them — so awaiting the call proves only that its synchronous part did not throw.
 * `setTimeout` is stubbed out to a no-op here, which leaves draining the microtask
 * queue as the way to reach a `.then` two links down.
 */
async function settle() { for (let i = 0; i < 10; i++) { await Promise.resolve(); } }

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
// `lock-access-bar` is here and the other three lock bars are not, which is the
// point of this list: those are emitted only for the holder, while losing *access*
// can happen to somebody who is only watching. A read-only page has the access bar
// and the banner above it, and nothing else the lock uses.
const PRESENT = new Set([
    'lock-banner', 'lock-access-bar', 'lock-access-text',
    'control-bar', 'zoom-readout', 'editor-frame', 'canvas-sizer', 'builder-canvas',
    'toast', 'resize-label', 'display-off-banner', 'top-nav'
]);

// ---- ...and the list above is checked against the page, not trusted ----------

// PRESENT is the whole basis of this suite, and it is the one part of it nothing
// was checking. A name listed here resolves to a stub; a name missing from it
// resolves to null, exactly as the browser would. So a name in this list that the
// page does not actually emit is worse than useless — it hands back an element
// where a browser hands back null, and the null-deref this file exists to catch
// becomes invisible to it.
//
// That had already happened: `lock-holder` sat in this list and never was an id at
// all. It is LOCK_HOLDER, a variable. Harmless, because nothing looked it up — but
// it got there because a hand-written mirror of the markup had nothing holding it
// to the markup, and the next such entry need not be harmless.

/**
 * Where each id sits in the file's PHP conditionals — first occurrence wins.
 *
 * Script blocks are stripped first, so an id in a JavaScript template string is not
 * mistaken for one the page emits. Safe to strip: no `<?php if:` opens or closes
 * inside a script block, so the conditional stack is untouched by their removal.
 */
function emitConditions() {
    const markup = php.replace(/<script\b(?![^>]*\bsrc=)[^>]*>[\s\S]*?<\/script>/gi, '');
    const stack = [], seen = Object.create(null);
    const re = /<\?php\s+(if\s*\((.*?)\)\s*:|else\s*:|elseif\s*\((.*?)\)\s*:|endif;)\s*\?>|id="([a-zA-Z0-9_-]+)"/g;
    let m;
    while ((m = re.exec(markup))) {
        if (m[4])                           { if (!(m[4] in seen)) { seen[m[4]] = stack.slice(); } }
        else if (m[1].startsWith('if'))     { stack.push(m[2].trim()); }
        else if (m[1].startsWith('elseif')) { if (stack.length) { stack[stack.length - 1] = '__unknown'; } }
        else if (m[1].startsWith('else'))   { if (stack.length) { stack[stack.length - 1] = '!(' + stack[stack.length - 1] + ')'; } }
        else if (m[1].startsWith('endif'))  { stack.pop(); }
    }
    return seen;
}

/**
 * Can the markup emit this node at all for a basic account on a read-only page?
 *
 * `$readOnly` and `$isAdmin` are the two this suite fixes. Anything else in a
 * condition — `!$display->isActive()` is the real case — is a runtime fact it has
 * no opinion about, so it is tried both ways and a single "yes" is enough. The
 * question being asked is whether the page *can* emit the node, not whether it
 * always does: only a node it can never emit makes a PRESENT entry a lie.
 */
function canEmitForReadOnlyBasic(conds) {
    let e = conds.map(function (c) { return '(' + c + ')'; }).join(' && ') || 'true';
    e = e.replace(/\$readOnly/g, 'true').replace(/\$isAdmin/g, 'false');

    let n = 0;
    e = e.replace(/\$[A-Za-z_][A-Za-z0-9_]*(?:->[A-Za-z_][A-Za-z0-9_]*\([^)]*\))?|__unknown/g,
                  function () { return 'U' + (n++) + '_'; });
    if (n > 8) { return true; }                     // too many to enumerate; do not fail on it

    for (let mask = 0; mask < (1 << n); mask++) {
        let candidate = e;
        for (let i = 0; i < n; i++) {
            candidate = candidate.split('U' + i + '_').join((mask & (1 << i)) ? 'true' : 'false');
        }
        try { if (eval(candidate)) { return true; } }   // eslint-disable-line no-eval
        catch (err) { return true; }                    // unparseable: assume it can appear
    }
    return false;
}

section('The stub DOM is what the page emits, not a wish list');

const EMITS = emitConditions();
const liars = [];
PRESENT.forEach(function (id) {
    if (!(id in EMITS)) {
        liars.push(id + ' — never an id in builder.php');
    } else if (!canEmitForReadOnlyBasic(EMITS[id])) {
        liars.push(id + ' — emitted only when ' + EMITS[id].join(' && '));
    }
});
check(liars.length === 0,
      'every id the stub answers to is one this page really emits'
      + (liars.length ? ' — ' + liars.join('; ') : ''));

// The control, and the reason the check above is not hollow: a walker that judged
// everything present would pass it while proving nothing. #inspector is the node
// this whole file was written about, and it must come back absent.
check(!canEmitForReadOnlyBasic(EMITS['inspector'] || []),
      'and the walker can still tell an editable-only node from an emitted one');

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

    // Revealing the section banner runs at init, not on a click, and it is the
    // other half of the same lookup: this page has no banner to reveal. It is
    // worth its own check because a throw here is more expensive than a throw on
    // a click — the two calls after it in DOMContentLoaded are the zoom fit and
    // setupLockWatch(), so a read-only page that threw would also never notice
    // it had lost the sign.
    await survives('revealing the section banner finds no banner to reveal', () => showSectionBanner());

    // Runs on every page load, read-only or not: the library is still needed to
    // render a block that points at an entry, even with no dropdown to fill.
    global.fetch = () => Promise.resolve({
        json: () => Promise.resolve([{ id: 1, type: 'text', content: 'Sockeye 18.99', label: 'Sockeye' }])
    });
    await survives('the asset library loads with no dropdown to put it in', () => loadAssets());

    // The other half of that page load, and the one with a control in it. loadLayout()
    // calls toggleBgInputs() and, for a colour background, applyBg() — both of which
    // reach for the background picker, which is an admin's and only while the page can
    // edit. They used to be guarded by restating that rule in JavaScript; they now ask
    // whether the control is there. Nothing exercised either until this, so the seam
    // was converted and then left unheld — which is how the first one broke.
    // `status: 'success'` matters — without it loadLayout() takes its early return and
    // never reaches the background at all, which is a test that passes while running
    // none of the code it names. And the promise is returned rather than dropped: a
    // throw inside a `.then` is an unhandled rejection, not something survives() can
    // see, so dropping it would swallow exactly the failure being tested for.
    function layoutReplying(display) {
        global.fetch = () => Promise.resolve({
            json: () => Promise.resolve({ status: 'success', display: display, elements: [],
                                          block_styles: {}, layout_stamp: 'stamp' })
        });
        return loadLayout().then(settle);
    }
    await survives('a layout with a colour background loads with no picker to set',
                   () => layoutReplying({ bg_type: 'color', bg_val: '#123456' }));
    await survives('and one with an image background does too',
                   () => layoutReplying({ bg_type: 'image', bg_val: 'bg.png' }));
    await survives('and choosing a background file finds no file input to read',
                   () => applyBgFile());

    // The rest of DOMContentLoaded. Both of these are safe by inspection — they
    // touch only ids the page always emits — but inspection is what this suite
    // exists to stop relying on, and they are the two calls the banner reveal sits
    // in front of: a throw up there costs the zoom fit and the lock watch, and a
    // read-only page with no lock watch never learns it has lost the sign at all.
    await survives('the zoom fit measures a frame with no controls around it', () => zoomToFit());
    await survives('and the lock watch starts, which is how this page hears anything', () => setupLockWatch());

    section('And what can no longer be reached');

    // Not reachable from the page — nothing on a read-only page can select a
    // block — but asserted anyway, because "unreachable" is a property of today's
    // markup and these are the functions a future control would call.
    const block = stubEl('b');
    block.dataset.type = 'text';

    // Why the ~90 unguarded derefs inside the inspector and the two modals are
    // allowed to stay unguarded. Every one sits behind `if (!activeBlock) return`,
    // and on this page activeBlock is permanently null — so they are unreachable
    // rather than safe. §4j calls that a property of today's call graph and not a
    // rule, which is fair, and these two checks are what make it a rule: the only
    // assignment that can make activeBlock non-null refuses on a read-only page,
    // and there is still only one such assignment. A second one appearing is the
    // change that would quietly put all ~90 back in reach.
    await survives('selecting a block is refused rather than half-done', () => selectBlock(block));
    check(activeBlock === null, 'so activeBlock stays null and the inspector derefs stay unreachable');
    const assigns = js.match(/activeBlock\s*=(?!=)\s*[A-Za-z_$][\w$]*/g) || [];
    check(assigns.filter(function (a) { return !/=\s*null$/.test(a); }).length === 1,
          'and activeBlock still has exactly one assignment that can make it non-null');

    await survives('showing an inspector that is not there does nothing', () => showInspector(block));
    await survives('multi-select does not reach for it either',           () => toggleMultiSel(stubEl('b2')));
    multiSel.length = 0;   // undo the block the line above pushed in

    await survives('a slide upload from a read-only page uploads nothing', () => uploadSlideImage(stubEl('i')));
    await survives('align actions find no targets',   () => { alignBlocks('left'); alignToParent('left'); });
    await survives('delete finds nothing to delete',  () => deleteSelected());
    await survives('publish refuses instead of posting', () => publishCanvas());

    section('Losing access to a display you could only look at');

    // The read-only page's one repeating call is the lock poll, and it used to return
    // silently on anything that was not a success. `forbidden` is the one answer that
    // never comes back: an admin has taken this display off this account, so the
    // banner's offer to reload once the lock frees up is now an offer to be refused.
    // Everything the notice needs is null on this page except the two ids below,
    // which is exactly why it is checked here rather than only on an editing page.
    const accessBar = document.getElementById('lock-access-bar');
    check(accessBar.style.display !== 'flex', 'the access notice starts hidden');

    global.fetch = () => Promise.resolve({
        json: () => Promise.resolve({ status: 'error', reason: 'forbidden',
                                      message: 'That display has not been assigned to you.' })
    });
    await survives('a lock poll refused as forbidden does not throw', async () => {
        pollLockState();
        await settle();
    });
    check(accessBar.style.display === 'flex', 'and the access notice is put on screen');
    check(document.getElementById('lock-banner').style.display === 'none',
          'while the banner offering a reload once it frees up is taken down');

    section('The other ways a watcher loses a display');

    // A revoked grant was only ever one of five. Retiring the display, renaming its
    // screen name tag, giving that tag to another sign, and suspending the account
    // itself all end the same way — this page will never get a useful answer again —
    // and all four used to return silently right here.
    //
    // The notice latches on purpose (first answer wins), so each case resets it. That
    // is a property of the page being asserted, not a convenience: without the reset
    // these checks would pass on the forbidden notice still being up from above.
    async function pollRefusedWith(reason, message) {
        accessLost = false;
        accessBar.style.display = 'none';
        global.fetch = () => Promise.resolve({
            json: () => Promise.resolve({ status: 'error', reason: reason, message: message })
        });
        pollLockState();
        await settle();
    }

    // The negative control, and the reason the terminal list is a fixed list: a
    // refusal nobody has thought about must stay ignorable. A page that treats every
    // failure as fatal tells somebody their sign is gone because a request timed out.
    await survives('a poll refused for some other reason does not throw', () =>
        pollRefusedWith('something_new', 'Try again later.'));
    check(accessBar.style.display !== 'flex',
          'and a reason that is not terminal leaves the notice alone');

    await survives('a poll refused because the display was turned off does not throw', () =>
        pollRefusedWith('inactive', 'This display is turned off'));
    check(accessBar.style.display === 'flex', 'and that puts the notice on screen too');
    check(/turned off/i.test(document.getElementById('lock-access-text').innerHTML),
          'saying the display was turned off, not that access was removed');

    await survives('a poll refused because the account was suspended does not throw', () =>
        pollRefusedWith('signed_out', 'Your account is no longer active. Sign in again.'));
    check(/signed out/i.test(document.getElementById('lock-access-text').innerHTML),
          'and that one says they have been signed out');

    // The expected total, for the same reason selftest_layout.php carries one:
    // without it, deleting half this file still reports a clean run.
    const expected = 38;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(fails.length ? 1 : 0);
})();
