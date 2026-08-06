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
// **That DOM is derived from builder.php's own conditionals, not written out by
// hand.** It was a hand-kept list until decision #40, and by then it had drifted in
// four places — including one id that exists nowhere in the file and two the page
// really does emit. A list that has drifted generous is the worst possible state
// for this suite: it stubs a node the browser will not have, so the lookup passes
// here and throws in the shop. `emittedIds()` reads the page instead, and the rules
// it follows when it meets a condition it does not understand are checked before
// anything is trusted to it.
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
check(emittedOnlyWhenEditable('<button id="publish-btn"'),          'and so is the Publish button, which is why setPublishBusy has to cope without one');

// ---- A DOM with only what that page emits -----------------------------------

// This list used to be written out by hand, and by the time decision #40 was picked
// up it had drifted in four places: it named `lock-holder`, which is not an id
// anywhere in the file; it claimed `display-off-banner`, which is emitted only when
// the Display is turned off; and it was missing `lock-free-hint` and
// `upload-status`, both of which a read-only page really does get. Three of those
// err strict and one errs generous, and the generous direction is the dangerous
// one: an id the browser will not have, stubbed present here, is a lookup this
// suite blesses and the shop floor throws on.
//
// So it is derived from the markup instead. `emittedIds()` walks builder.php's own
// `<?php if:/else:/endif;` conditionals over the Builder document and answers which
// ids survive for a given page — here: somebody else holds the lock, the account is
// basic, the Display is on.
//
// Two rules make the derivation safe to trust, and they are the same two invariant
// 19 puts on reading the database catalogue. A condition it cannot evaluate is
// **unknown, never false** — it is reported by name and fails a check, because a
// guard nobody taught it about is work, not a default. And unknown ids are left
// *out* of the DOM, so the untaught case makes this suite stricter rather than
// blinder: the lookup returns null, and null is what makes a missing guard throw.

/**
 * Which ids the Builder document emits for a given page.
 *
 * Only the last document in the file: builder.php renders a display picker first
 * and `exit`s, and that page shares no markup with this one.
 *
 * Returns { ids, unknown, unbalanced } — the caller checks the last two rather than
 * trusting the first.
 */
function emittedIds(page, source) {
    const doc = (source === undefined
                    ? php.slice(php.lastIndexOf('<!DOCTYPE html>'))
                    : source)
                   // <script> bodies emit no markup; ids inside them are JavaScript
                   // asking for a node, which is the thing under test, not a node.
                   .replace(/<script\b(?![^>]*\bsrc=)[^>]*>[\s\S]*?<\/script>/gi, '');

    const unknown = new Set();
    const ids     = new Set();
    const stack   = [];

    const atom = (text) => {
        let a = text.trim(), negated = false;
        while (a.startsWith('!')) { negated = !negated; a = a.slice(1).trim(); }
        while (a.startsWith('(') && a.endsWith(')')) { a = a.slice(1, -1).trim(); }
        let v;
        if      (a === '$readOnly')             { v = page.readOnly; }
        else if (a === '$isAdmin')              { v = page.isAdmin; }
        else if (a === '$display->isActive()')  { v = page.active; }
        else { unknown.add(a); return null; }
        return negated ? !v : v;
    };
    const truth = (cond) => {
        if (cond === null) { return null; }
        if (cond.indexOf('||') >= 0) { unknown.add(cond); return null; }   // never seen; say so
        let out = true;
        for (const part of cond.split('&&')) {
            const t = atom(part);
            if (t === null) { return null; }
            out = out && t;
        }
        return out;
    };

    const tok = /<\?php\s+if\s*\(([\s\S]*?)\)\s*:\s*\?>|<\?php\s+elseif\s*\(([\s\S]*?)\)\s*:\s*\?>|<\?php\s+else\s*:\s*\?>|<\?php\s+endif;?\s*\?>|\sid="([A-Za-z0-9_-]+)"/g;
    let m;
    while ((m = tok.exec(doc))) {
        if (m[3] !== undefined) {
            let visible = true;
            for (const cond of stack) {
                const t = truth(cond);
                if (t === null)  { visible = null;  break; }   // cannot tell → left out
                if (t === false) { visible = false; break; }
            }
            if (visible === true) { ids.add(m[3]); }
        } else if (m[1] !== undefined) {
            stack.push(m[1]);
        } else if (m[2] !== undefined) {
            stack[stack.length - 1] = null;                    // elseif: not taught yet
        } else if (m[0].indexOf('else') >= 0) {
            const top = stack[stack.length - 1];
            stack[stack.length - 1] = (top === null) ? null : '!(' + top + ')';
        } else {
            stack.pop();
        }
    }
    return { ids, unknown: [...unknown], unbalanced: stack.length };
}

const emitted = emittedIds({ readOnly: true, isAdmin: false, active: true });
const PRESENT = emitted.ids;

section('The derivation, before anything is trusted to it');

// Driven on markup written here rather than only on builder.php, because the rule
// that matters most is about a conditional builder.php does not contain *yet*. Read
// off the real file, "unknown" is an empty set and every claim about it is vacuous;
// the day somebody adds a guard on a new variable, this is what decides whether the
// suite says so or quietly stubs the node present and blesses the lookup.
const RO = { readOnly: true, isAdmin: false, active: true };

const plain = emittedIds(RO, '<div id="always"></div>');
check(plain.ids.has('always'), 'an id under no condition at all is emitted');

const branches = emittedIds(RO,
    '<?php if ($readOnly): ?><div id="watching"></div>'
  + '<?php else: ?><div id="editing"></div><?php endif; ?>');
check(branches.ids.has('watching') && !branches.ids.has('editing'),
      'an if/else picks the branch this page is on, and only that branch');

const both = emittedIds(RO, '<?php if (!$isAdmin && !$readOnly): ?><div id="basic-editing"></div><?php endif; ?>');
check(!both.ids.has('basic-editing'),
      'a condition on the role AND the lock needs both to hold — the #40 shape exactly');

const strange = emittedIds(RO, '<?php if ($somethingNew): ?><div id="mystery"></div><?php endif; ?>');
check(!strange.ids.has('mystery'),
      'a condition this suite has never met leaves its id OUT of the page');
check(strange.unknown.indexOf('$somethingNew') >= 0,
      'and names it, so an unteachable guard is work rather than a silent guess');

const ragged = emittedIds(RO, '<?php if ($readOnly): ?><div id="dangling"></div>');
check(ragged.unbalanced > 0, 'and markup whose conditionals do not close is reported, not guessed at');

section('What a read-only page actually emits, read off the page');

check(emitted.unbalanced === 0,
      'the markup\'s conditionals balance, so the walk knows where each block ends');
check(emitted.unknown.length === 0,
      'and every condition guarding an id is one this suite understands'
      + (emitted.unknown.length ? ' — not: ' + emitted.unknown.join(', ') : ''));

// Anchors. Without them a derivation that returned everything — or nothing — would
// turn this whole suite green, which is decision #50's complaint in the one place
// it would be least visible.
['builder-canvas', 'editor-frame', 'toast', 'lock-banner', 'upload-status'].forEach(function (id) {
    check(PRESENT.has(id), 'a read-only page still has #' + id);
});
['inspector', 'align-bar', 'section-banner', 'insp-x', 'carousel-modal-overlay', 'publish-btn'].forEach(function (id) {
    check(!PRESENT.has(id), 'and does not have #' + id);
});
// #section-banner is the node decision #40 is about, and the derivation is what
// makes its shape statable: it depends on the role *and* the lock, so a lookup
// guarded on the role alone is right on three pages out of four and throws on the
// fourth — a basic account watching somebody else edit.
const editableBasic = emittedIds({ readOnly: false, isAdmin: false, active: true }).ids;
const editableAdmin = emittedIds({ readOnly: false, isAdmin: true,  active: true }).ids;
check(editableBasic.has('section-banner'),
      'a basic account that can edit does have #section-banner');
check(!editableAdmin.has('section-banner'),
      'an admin never has it, editing or not — so "not an admin" does not mean "the banner is there"');

// The mirror image, and the reason pollLockState's `if (hint)` can never be false:
// the hint is emitted on exactly the pages that poll for it. That is a coupling
// between two files' worth of conditions, and this is the only thing asserting it.
check(PRESENT.has('lock-free-hint') && !editableBasic.has('lock-free-hint'),
      'the "it is free now" hint is on exactly the pages that poll for it — read-only ones');

function stubEl(id) {
    return {
        id, style: {}, dataset: {}, children: [], files: [],
        value: '', textContent: '', innerHTML: '', checked: false,
        offsetWidth: 100, offsetHeight: 100, clientWidth: 800, clientHeight: 600,
        scrollLeft: 0, scrollTop: 0, parentElement: null,
        classList: { add() {}, remove() {}, contains() { return false; } },
        appendChild() {}, removeChild() {}, insertBefore() {}, remove() {},
        // Recorded rather than discarded, so a handler can be fired the way the
        // browser fires it. Calling the three functions a click reaches proves those
        // three are safe; it does not prove the handler only calls those three.
        _on: {},
        addEventListener(type, fn) { (this._on[type] || (this._on[type] = [])).push(fn); },
        focus() {}, blur() {},
        querySelector() { return null; }, querySelectorAll() { return []; },
        closest() { return null; }, getAttribute() { return null; }, setAttribute() {},
        getBoundingClientRect() { return { left: 0, top: 0, width: 100, height: 100 }; }
    };
}

/** Fire every handler wired to `el` for `type`, as the browser would. */
function fire(el, type, event) {
    (el._on[type] || []).forEach(function (fn) { fn(event); });
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
    _on: {},
    addEventListener(type, fn) { (this._on[type] || (this._on[type] = [])).push(fn); },
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

    // …and the same click again, through the handler the page actually registers.
    // Decision #40 is a click on this page throwing, and calling the three functions
    // it reaches today only proves those three: a fourth call added to the handler
    // would be covered by nothing. `setupCanvas()` wires it, `fire()` sends it.
    setupCanvas();
    const frame = document.getElementById('editor-frame');
    check((frame._on.mousedown || []).length === 1, 'the canvas area has its mousedown handler wired');
    await survives('a real mousedown on the canvas area does not throw',
                   () => fire(frame, 'mousedown', { target: { closest: () => null }, shiftKey: false }));
    await survives('nor does a shift-click, which takes the other branch',
                   () => fire(frame, 'mousedown', { target: { closest: () => null }, shiftKey: true }));
    await survives('nor one that lands on a block, which returns early',
                   () => fire(frame, 'mousedown', { target: { closest: () => stubEl('blk') }, shiftKey: false }));

    // The only controls a basic read-only account can actually press: the control
    // bar keeps its zoom buttons, because looking at a sign you may not edit is
    // exactly when you want to zoom around it.
    await survives('Fit works on a page with no editing controls', () => zoomToFit());
    await survives('so does 100%',                                 () => applyZoom(1));
    await survives('and so does nudging the zoom',                 () => { nudgeZoom(1); nudgeZoom(-1); });

    // Delete is wired to the document, not to a control, so it arrives on a
    // read-only page too. READ_ONLY is what stops it, and nothing else does.
    await survives('pressing Delete on a read-only page removes nothing',
                   () => fire(global.document, 'keydown', { key: 'Delete' }));
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

    // The in-flight guard added for decision #39 hangs off a button this page does
    // not have. An unguarded lookup there would be a TypeError on the one path a
    // read-only page still runs — the refusal above — leaving nothing on screen.
    await survives('taking a Publish button that is not there out of service does nothing',
                   () => { setPublishBusy(true); setPublishBusy(false); });
    check(publishInFlight === false, 'and a read-only page never has a publish in flight to release');

    section('The one thing a read-only page waits for');

    // #lock-free-hint is a read-only page's own node — it is inside the `$readOnly`
    // branch, so the editing page never has it. The hand-written DOM this suite used
    // to carry left it out, which meant the only branch of the lock poll that runs
    // on a good day had never once been executed here: `if (hint)` was false every
    // time. Derived, it is present, and the branch runs.
    const freeHint = document.getElementById('lock-free-hint');
    check(freeHint !== null, 'a read-only page has the "it is free now" hint to fill in');

    global.fetch = () => Promise.resolve({
        json: () => Promise.resolve({ status: 'success', held_by_other: true })
    });
    await survives('a poll saying somebody still holds it does not throw', async () => {
        pollLockState();
        await settle();
    });
    check(freeHint.style.display === 'none', 'and the offer to reload stays hidden while they do');

    global.fetch = () => Promise.resolve({
        json: () => Promise.resolve({ status: 'success', held_by_other: false })
    });
    await survives('a poll saying it has freed up does not throw either', async () => {
        pollLockState();
        await settle();
    });
    check(freeHint.style.display === 'inline', 'and the offer to reload appears the moment it has');

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
    const expected = 65;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(fails.length ? 1 : 0);
})();
