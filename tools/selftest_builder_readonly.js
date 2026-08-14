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

check(emittedOnlyWhenEditable('<div id="inspector">'),              'the properties rail is emitted only when the page can edit');
// `#align-bar` retired with the horizontal bar stack; the same buttons are the
// Arrange group inside the rail, so they are covered by the line above. The check
// that they did not simply escape the gate on the way is below: nothing named
// align-bar is in the file at all any more, and the id would otherwise sit in
// PRESENT for ever pointing at nothing — which is exactly the failure the PRESENT
// audit further down exists to catch.
// The prose that explains the retirement mentions the name several times, so this
// asks for the node and the lookup rather than the string.
check(!/id="align-bar"/.test(php) && !/getElementById\('align-bar'\)/.test(php),
      'and the align bar it replaced is gone rather than hidden');
check(emittedOnlyWhenEditable('<div id="carousel-modal-overlay">'), 'so is the carousel editor');
check(emittedOnlyWhenEditable('<div id="table-modal-overlay">'),    'so is the table editor');
check(emittedOnlyWhenEditable('<button id="publish-btn"'),          'and so is the Publish button, which is why setPublishBusy has to cope without one');

// ---- A DOM with only what that page emits -----------------------------------

// The ids builder.php still renders when $readOnly is true and the account is
// basic. Everything else resolves to null, which is what the browser will do.
// `lock-access-bar` is here and the other three lock bars are not, which is the
// point of this list: those are emitted only for the holder, while losing *access*
// can happen to somebody who is only watching. A read-only page has the access bar
// and the banner above it, and nothing else the lock uses.
// `control-bar` left this list with the bar itself. What replaced it is three
// columns, and only two of them reach a read-only page: the palette, which carries
// Switch sign and the read-only sentence and no editing control, and the canvas
// column with its footer — the zoom controls and the publish line, which is a fact
// somebody who cannot edit still needs.
// `brand-control` joins them with v2 step 4, and it is the one entry here that is a
// *feature* on a read-only page rather than a leftover: somebody who cannot edit still
// needs to know which venue they are looking at. What is not here is everything inside
// it — the button, the logo, the name and the menu — because that branch of the markup
// is emitted only for an admin who holds the lock, and the plain version carries no ids
// at all. Two copies of `#brand-name` would make every lookup of it depend on which
// branch the page took.
// `theme-pick` and `theme-warn` join them with v2 step 5, and they are the second
// entry of that kind: a Workspace Theme is a fact about the *person*, not about the
// sign, so somebody who may not touch this layout may still want their own screen
// legible. Unlike the Brand control, the whole thing reaches this page — the picker,
// its items and the sentence it says when a choice could not be saved — because there
// is no half of it a read-only page should be refused.
const PRESENT = new Set([
    'lock-banner', 'lock-access-bar', 'lock-access-text',
    'workbench', 'palette', 'brand-control', 'canvas-column', 'canvas-footer', 'pub-state',
    'zoom-readout', 'editor-frame', 'canvas-sizer', 'builder-canvas',
    'toast', 'resize-label', 'display-off-banner', 'top-nav', 'gear-btn', 'gear-menu',
    'theme-pick', 'theme-warn'
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
    e = e.replace(/\$readOnly/g, 'true').replace(/\$isAdmin/g, 'false')
         // Not a third runtime fact but a consequence of the first: builder.php
         // computes `$undoSteps = $readOnly ? 0 : undoStepsSetting()`, so on this
         // page it is 0 whatever an admin set. Left to the enumeration below it
         // would be tried both ways, one of those ways would say yes, and a walker
         // that believes a read-only page can emit #undo-btn would report the
         // absent stub entry as its own bug. The check below holds the page to that
         // derivation, so this line is a reading of the source and not a guess.
         .replace(/\$undoSteps/g, '0')
         // The same shape, and the same reason (v2 step 4). builder.php computes
         // `$canPickBrand = $isAdmin && !$readOnly && $wearing !== null`, so on this
         // page it is false however the Brands are set up. Left to the enumeration
         // below it would be tried both ways, one of those ways would say yes, and the
         // walker would believe a read-only page can emit the Brand menu. The check
         // below holds the page to that derivation, so this is a reading of the source
         // rather than a guess.
         .replace(/\$canPickBrand/g, 'false');

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

// Undo (ADR-0010) has two ways to be absent — the admin setting at 0, and this
// page — and both take the button out of the markup rather than merely disabling
// it. That is why every lookup for it is guarded, and a guard is only worth having
// if something really does come back null.
check(EMITS['undo-btn'] !== undefined && EMITS['undo-btn'].length > 0,
      '#undo-btn is emitted conditionally, not unconditionally');
check(!canEmitForReadOnlyBasic(EMITS['undo-btn'] || []),
      'and a read-only page can never emit it, so the Undo lookup here really is null');

// What makes the line above a reading rather than an assumption: the page derives
// the depth from the lock, so there is no arrangement of admin settings that puts an
// Undo button on a Builder somebody else is editing.
check(/\$undoSteps\s*=\s*\$readOnly\s*\?\s*0\s*:\s*undoStepsSetting\(\)/.test(php),
      'and the depth is derived from $readOnly, not merely read beside it');

// The Brand control (v2 step 4) is the one thing this page gained rather than lost: a
// person who cannot edit still needs to know which venue they are looking at, and still
// must not be able to change it. So the control is emitted and the *picker* is not, and
// both halves are read off the markup rather than trusted.
check(/\$canPickBrand\s*=\s*\$isAdmin\s*&&\s*!\$readOnly\s*&&/.test(php),
      'whether the Brand may be changed is derived from the role and the lock together');
check(canEmitForReadOnlyBasic(EMITS['brand-control'] || []),
      'so the Brand control itself reaches a page that cannot edit');
check(!canEmitForReadOnlyBasic(EMITS['brand-menu'] || []),
      'while the menu that would change it never does');
check(!canEmitForReadOnlyBasic(EMITS['brand-btn'] || []),
      'nor the button that opens it');
// The palette swatches live in the rail, which this page does not get at all — asserted
// so that a row moved out of the rail some day is a failure here rather than a colour
// control appearing on a page that may not use one.
['sw-bg', 'sw-font', 'sw-marquee', 'sw-marquee-bg'].forEach(function (row) {
    check(!canEmitForReadOnlyBasic(EMITS[row] || []),
          'and the ' + row + ' palette row stays inside the rail, which is not sent here');
});

// The Workspace Theme picker (v2 step 5), which this page keeps in full. The Brand
// control above is emitted with its picker withheld; this one is not, and the difference
// is the whole distinction between the two nouns — one says what a sign wears and the
// other says what a screen is painted in, and only the first is somebody else's to lose.
check(canEmitForReadOnlyBasic(EMITS['theme-pick'] || []),
      'the theme picker reaches a page that cannot edit');
check(canEmitForReadOnlyBasic(EMITS['theme-warn'] || []),
      'and so does the sentence it says when a choice could not be saved');
check(/name="theme_id"/.test(php) === false,
      'and it is not a form that would take the page away from unpublished work');

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
    // Null for everything except the one lookup a page load genuinely depends on:
    // loadLayout() finds a block's parent section with this, and skips the block
    // entirely when it comes back null. Left null, a child block silently never
    // renders and the `isChildBlock` branch of renderBlock() is never taken — the
    // suite would report drawing a layout it had quietly dropped half of.
    querySelector(sel) {
        return /^\.section-block\[data-db-id=/.test(sel || '') ? stubEl('section') : null;
    },
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

// What a basic account looking at somebody else's edit session gets. The three Brand
// constants are written out rather than left as the stripped `0`, because that is what
// the page really carries here: one Brand — the one this sign wears, which the control
// names — and no permission to change it.
js = js.replace(/^var READ_ONLY\s*=.*$/m, 'var READ_ONLY = true;')
       .replace(/^var IS_ADMIN\s*=.*$/m,  'var IS_ADMIN = false;')
       .replace(/^var BRANDS\s*=.*$/m,
                "var BRANDS = [{id:3,name:'Salmon House',logo_asset_id:12,"
                + "logo_src:'uploads/salmon.png',palette:['#0b3d2e','#e67e22'],styles:{}}];")
       .replace(/^var BRAND_ID\s*=.*$/m,       'var BRAND_ID = 3;')
       .replace(/^var CAN_PICK_BRAND\s*=.*$/m, 'var CAN_PICK_BRAND = false;');

check(/var CAN_PICK_BRAND = false;/.test(js),
      'and the page constant saying so is one this suite really replaced');

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
    function layoutReplying(display, elements, brand) {
        global.fetch = () => Promise.resolve({
            json: () => Promise.resolve({ status: 'success', display: display,
                                          elements: elements || [], brand: brand || null,
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

    // The layout reply carries the Brand this sign wears (v2 step 4), and this page has
    // to take it without a control to draw it into. The reply is also the only thing
    // that can tell a watcher their sign changed venue while they were looking at it.
    await survives('a reply naming a Brand this page was never offered is taken anyway',
                   () => layoutReplying({ bg_type: 'color', bg_val: '#123456' }, [],
                                        { id: 8, name: 'Cedar Room', logo_asset_id: 0, palette: ['#3d2b1f'] }));
    check(BRAND_ID === 8, 'and the page moves onto it rather than naming the old one');

    // Both loads above carry no elements, which exercises the background and not one
    // line of the drawing — and drawing is what a read-only page is *for*. Somebody
    // watching a sign sees every block on it; renderBlock() and renderSection() run
    // for all of them, on a page with no inspector, no align bar and no modals to put
    // anything in. Each entry below takes a branch of its own: a section to be a
    // parent, a child inside it, a locked block, a hidden one, one drawn from a
    // library entry rather than typed, and one of every remaining type.
    const EVERY_TYPE = [
        { id: 1, type: 'section',  block_subtype: 'free', x_pos: 0,  y_pos: 0,  width: 600, height: 380, z_index: 1, locked: 0, hidden: 0, section_id: null, manual_content: '',              db_content: '' },
        { id: 2, type: 'text',     block_subtype: 'free', x_pos: 10, y_pos: 10, width: 200, height: 60,  z_index: 1, locked: 0, hidden: 0, section_id: 1,    manual_content: 'Sockeye 18.99', db_content: '' },
        { id: 3, type: 'text',     block_subtype: 'price',x_pos: 10, y_pos: 80, width: 200, height: 60,  z_index: 2, locked: 1, hidden: 1, section_id: null, asset_id: 7, manual_content: '', db_content: 'Halibut 24.99' },
        { id: 4, type: 'image',    block_subtype: 'free', x_pos: 20, y_pos: 20, width: 100, height: 100, z_index: 1, locked: 0, hidden: 0, section_id: null, manual_content: 'a.png',         db_content: '' },
        { id: 5, type: 'carousel', block_subtype: 'free', x_pos: 30, y_pos: 30, width: 300, height: 200, z_index: 1, locked: 0, hidden: 0, section_id: null, manual_content: '{"slides":[],"interval":5000}', db_content: '' },
        { id: 6, type: 'table',    block_subtype: 'free', x_pos: 40, y_pos: 40, width: 300, height: 200, z_index: 1, locked: 0, hidden: 0, section_id: null, manual_content: '{"rows":[["a"]]}', db_content: '' },
        { id: 7, type: 'marquee',  block_subtype: 'free', x_pos: 50, y_pos: 50, width: 300, height: 60,  z_index: 1, locked: 0, hidden: 0, section_id: null, manual_content: '{"text":"hi"}',  db_content: '' },
        { id: 8, type: 'video',    block_subtype: 'free', x_pos: 60, y_pos: 60, width: 300, height: 200, z_index: 1, locked: 0, hidden: 0, section_id: null, manual_content: 'v.mp4',         db_content: '' }
    ];
    await survives('every block type draws on a page that cannot edit any of them',
                   () => layoutReplying({ bg_type: 'color', bg_val: '#111' }, EVERY_TYPE));

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

    // The Brand (v2 step 4). This page draws the venue's name and logo out of the
    // markup and has nothing to change it with — no menu, no swatch row, no palette
    // item — so every one of these lookups comes back null and every one of these
    // functions has to do nothing rather than throw. The one that matters most is the
    // first: refreshBrandSurfaces() runs on every page load, read-only or not.
    await survives('redrawing the Brand surfaces finds none of them',   () => refreshBrandSurfaces());
    await survives('and the menu that is not there does not open',       () => toggleBrandMenu(null));
    await survives('or close',                                          () => closeBrandMenu());
    await survives('placing the venue logo is refused rather than half-done', () => createVenueLogo());

    // The refusal that is the belt to the markup's braces. A read-only page has no menu
    // to click, so this is the keyboard, the console, and anything else that finds the
    // function — and it must not repaint a canvas this account may not publish.
    // Whatever the reply above left the page showing — read rather than written down, so
    // this cannot pass by being wrong about both halves at once.
    const showing = BRAND_ID;
    await survives('switching Brand from a page that may not is a no-op', () => switchBrand(3));
    check(BRAND_ID === showing, 'and the Brand the page is showing has not moved');
    await survives('nor does an unknown one reach the canvas',            () => switchBrand(9999));
    check(BRAND_ID === showing, 'still the one the reply named');

    // A palette swatch, applied by hand. There is no row on this page to click, so this
    // is the same class of check: the guard inside applyPaletteColor, not the absence of
    // a button.
    await survives('applying a palette colour finds no picker to fill',
                   () => applyPaletteColor(PALETTE_TARGETS[1], '#0b3d2e'));

    await survives('a slide upload from a read-only page uploads nothing', () => uploadSlideImage(stubEl('i')));
    await survives('align actions find no targets',   () => { alignBlocks('left'); alignToParent('left'); });
    await survives('delete finds nothing to delete',  () => deleteSelected());
    await survives('publish refuses instead of posting', () => publishCanvas());

    // The in-flight guard added for decision #39 hangs off a button this page does
    // not have. An unguarded lookup there would be a TypeError on the one path a
    // read-only page still runs — the refusal above — leaving nothing on screen.
    await survives('taking a Publish button that is not there out of service does nothing',
                   () => { setPublishBusy(true); setPublishBusy(false); });
    check(publishInFlight === false, 'and a read-only page never has a publish in flight to release');

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
    const expected = 68;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(fails.length ? 1 : 0);
})();
