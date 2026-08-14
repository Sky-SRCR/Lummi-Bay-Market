// ============================================================
// SELF-TEST — one person's Workspace Theme, changed with work on the canvas
// ============================================================
//   node tools/selftest_builder_theme.js
//
// The eighth harness over builder.php's inline JavaScript, and the one for step 5 of the
// v2 roadmap: what the *application* is painted in, as opposed to what a sign is.
//
// The premise nothing else here holds is **an unsaved layout on screen while somebody
// changes a setting about themselves.** Every other suite drives a page that is either
// editing a sign or refusing to; this one drives the one control on the page that has
// nothing to do with the sign, on a page that is one careless reload away from losing an
// hour of work. What that premise makes visible:
//
//   The repaint costs nothing      Choosing a theme sets thirteen custom properties and
//                                  touches nothing else. The original design of this
//                                  control was a form that posted and let the page come
//                                  back painted — which works perfectly and throws away
//                                  every unpublished block on the canvas. Asserted by
//                                  holding the canvas nodes by identity across the
//                                  switch, and by watching the undo stack not move.
//
//   A failed save does not lie     The paint happens first so the click feels live, so
//                                  a save that fails leaves the screen showing a theme
//                                  the account is not on. That is #21's shape — the
//                                  wrong state, reported as success — so the failure
//                                  path puts the page back and says so. A `.catch()`
//                                  that swallowed this would look identical while it
//                                  was working (§4au).
//
//   The way out always works       Decision 14: a preference you cannot reverse is not a
//                                  preference. "Store default" is on the menu from every
//                                  state, including when it is the one already ticked,
//                                  and it puts back a set of colours the page was sent
//                                  rather than removing properties — which would fall
//                                  back to the stylesheet, and the stylesheet was
//                                  rendered wearing the theme being escaped from.
//
//   The picker is not themed       Every colour inside `#theme-pick` is a literal, and
//                                  the gear that opens it gets a fixed chip when the nav
//                                  colour would have hidden it. A control drawn in the
//                                  thing it controls is how one bad theme becomes
//                                  permanent.
//
// What is deliberately not here: whether a theme *resolves* to the right colour — three
// layers, per role, with an unreadable value falling back — which is PHP and lives in
// `tools/selftest_layout.php`. And the read-only Builder's version of this page, which
// keeps its picker for a reason and is asserted in selftest_builder_readonly.js, the
// suite that owns that premise.
//
// CLI only. Nothing here touches a database or a network.

const fs   = require('fs');
const path = require('path');
const { buildPageJs } = require('./page_constants');

const BUILDER = path.join(__dirname, '..', 'builder.php');

let checks = 0;
const fails = [];

function check(condition, label) {
    checks++;
    if (condition) { console.log('  ok   ' + label); }
    else { fails.push(label); console.log('  FAIL ' + label); }
}

function describe(v) {
    if (v === null)      { return 'null'; }
    if (v === undefined) { return 'undefined'; }
    if (typeof v === 'object') {
        return v.tagName
            ? '<' + v.tagName.toLowerCase() + (v.className ? ' class="' + v.className + '"' : '') + '>'
            : JSON.stringify(v);
    }
    return JSON.stringify(v);
}

function checkSame(expected, actual, label) {
    const same = expected === actual;
    check(same, label + (same ? '' : ' — expected ' + describe(expected) + ', got ' + describe(actual)));
}

function checkMentions(haystack, needle, label) {
    const found = String(haystack).indexOf(needle) >= 0;
    check(found, label + (found ? '' : ' — "' + haystack + '" does not mention "' + needle + '"'));
}

function section(title) { console.log('\n' + title); }

// ---- A DOM small enough to read and real enough to run ----------------------
// The Brand suite's, with one addition this premise needs: a `documentElement` whose
// `style.setProperty` records what was set. That is where a custom property lands, and a
// stub without it would turn the whole repaint into a TypeError — or worse, into a
// silent no-op that every check below would pass.

function classesOf(node) {
    return String(node.className || '').split(/\s+/).filter(Boolean);
}

function camel(attr) {
    return attr.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
}

function matchesSel(node, sel) {
    sel = sel.trim();
    const not = sel.match(/^(.*):not\(([^)]+)\)$/);
    if (not) { return matchesSel(node, not[1]) && !matchesSel(node, not[2]); }
    let m = sel.match(/^([a-zA-Z]+)?\[type="([^"]+)"\]$/);
    if (m) { return (!m[1] || node.tagName === m[1].toUpperCase()) && node.type === m[2]; }
    m = sel.match(/^([a-zA-Z.\-_]*)\[data-([a-z-]+)="([^"]*)"\]$/);
    if (m) {
        return (!m[1] || matchesSel(node, m[1])) && String(node.dataset[camel(m[2])]) === m[3];
    }
    if (sel.charAt(0) === '.') { return classesOf(node).indexOf(sel.slice(1)) >= 0; }
    if (sel.charAt(0) === '#') { return node.id === sel.slice(1); }
    return node.tagName === sel.toUpperCase();
}

function descendants(node, out) {
    out = out || [];
    node.children.forEach(function (c) { out.push(c); descendants(c, out); });
    return out;
}

function findAll(node, sel) {
    const hits = [];
    String(sel).split(',').forEach(function (part) {
        part = part.trim();
        if (part === '*') { descendants(node).forEach(function (d) { hits.push(d); }); return; }
        if (part.indexOf(':scope >') === 0) {
            const inner = part.slice(8).trim();
            node.children.forEach(function (c) { if (matchesSel(c, inner) && hits.indexOf(c) < 0) hits.push(c); });
            return;
        }
        const steps = part.split(/\s+/);
        let scope = [node];
        steps.forEach(function (step) {
            const next = [];
            scope.forEach(function (s) {
                descendants(s).forEach(function (d) {
                    if (matchesSel(d, step) && next.indexOf(d) < 0) next.push(d);
                });
            });
            scope = next;
        });
        scope.forEach(function (s) { if (hits.indexOf(s) < 0) hits.push(s); });
    });
    return hits;
}

function pxOf(v) {
    const n = parseFloat(v);
    return isNaN(n) ? 0 : Math.round(n);
}

function el(tag, className) {
    const node = {
        tagName: String(tag || 'div').toUpperCase(),
        id: '', className: className || '', type: '', src: '', title: '', alt: '',
        style: { fontFamily: '', fontSize: '', color: '', fontWeight: '',
                 fontStyle: '', lineHeight: '', textAlign: '', display: '',
                 width: '', height: '', transform: '', background: '' },
        dataset: new Proxy({}, { set(t, k, v) { t[k] = String(v); return true; } }),
        children: [], files: [],
        value: '', textContent: '', innerHTML: '', checked: false, disabled: false,
        clientWidth: 0, clientHeight: 0,
        scrollLeft: 0, scrollTop: 0, parentNode: null, parentElement: null,
        _attrs: {}, _on: {}
    };
    Object.defineProperty(node, 'offsetWidth',  { get() { return pxOf(node.style.width); }, configurable: true });
    Object.defineProperty(node, 'offsetHeight', { get() { return pxOf(node.style.height); }, configurable: true });
    Object.defineProperty(node, 'innerText', {
        get() { return node.textContent; },
        set(v) { node.textContent = v; },
        configurable: true
    });
    node.classList = {
        add(c)      { if (classesOf(node).indexOf(c) < 0) { node.className = (node.className + ' ' + c).trim(); } },
        remove(c)   { node.className = classesOf(node).filter(function (x) { return x !== c; }).join(' '); },
        contains(c) { return classesOf(node).indexOf(c) >= 0; },
        toggle(c, on) { if (on) { this.add(c); } else { this.remove(c); } }
    };
    node.appendChild = function (child) {
        child.parentNode = node; child.parentElement = node; node.children.push(child); return child;
    };
    node.insertBefore = function (child) { return node.appendChild(child); };
    node.removeChild  = function (child) {
        node.children = node.children.filter(function (c) { return c !== child; });
        child.parentNode = null; child.parentElement = null;
    };
    node.remove = function () { if (node.parentNode) { node.parentNode.removeChild(node); } };
    node.getAttribute = function (k) { return Object.prototype.hasOwnProperty.call(node._attrs, k) ? node._attrs[k] : null; };
    node.setAttribute = function (k, v) { node._attrs[k] = String(v); };
    node.querySelector    = function (sel) { return findAll(node, sel)[0] || null; };
    node.querySelectorAll = function (sel) { return findAll(node, sel); };
    node.closest = function (sel) {
        let n = node;
        while (n) { if (matchesSel(n, sel)) return n; n = n.parentNode; }
        return null;
    };
    node.contains = function (other) {
        return other === node || descendants(node).indexOf(other) >= 0;
    };
    node.getBoundingClientRect = function () {
        return { left: 0, top: 0, width: node.offsetWidth, height: node.offsetHeight };
    };
    node.addEventListener = function (type, fn) { (node._on[type] || (node._on[type] = [])).push(fn); };
    node.focus = function () {}; node.blur = function () {};
    node.load = function () {};
    Object.defineProperty(node, 'innerHTML', {
        get() { return node._html || ''; },
        set(v) { node._html = String(v); if (String(v) === '') { node.children.length = 0; } },
        configurable: true
    });
    return node;
}

const nodes = {};
function byId(id) {
    if (!nodes[id]) { nodes[id] = el('div'); nodes[id].id = id; }
    return nodes[id];
}

// Where a custom property actually lands. Recorded rather than merely accepted, because
// the whole first claim of this suite is about *which* properties were set.
const rootProps = {};
const docElement = el('html');
docElement.style.setProperty = function (name, value) { rootProps[name] = String(value); };
docElement.style.removeProperty = function (name) { delete rootProps[name]; };

const docHandlers = {};
global.document = {
    documentElement: docElement,
    getElementById: byId,
    createElement(tag) { return el(tag); },
    querySelector(sel)    { return global.document.querySelectorAll(sel)[0] || null; },
    querySelectorAll(sel) {
        const steps = String(sel).trim().split(/\s+/);
        if (steps[0].charAt(0) === '#') {
            const root = nodes[steps[0].slice(1)];
            return root ? findAll(root, steps.slice(1).join(' ') || '*') : [];
        }
        return findAll(byId('builder-canvas'), sel);
    },
    addEventListener(type, fn) { (docHandlers[type] || (docHandlers[type] = [])).push(fn); },
    body: el('body'),
    activeElement: null,
    execCommand() {},
    caretRangeFromPoint: null
};
global.window    = { getSelection() { return null; }, addEventListener() {}, innerWidth: 1280, innerHeight: 800 };
global.navigator = { sendBeacon() { return true; } };

// Every request, held rather than answered, so the reply can be decided per test. A
// promise that never settles is what the Brand suite wanted; here the settling *is* the
// subject, so each call parks its resolver.
const sent = [];
global.fetch = function (url, opts) {
    let settle;
    const promise = new Promise(function (resolve, reject) { settle = { resolve: resolve, reject: reject }; });
    sent.push({ url: url, opts: opts, settle: settle,
                fields: (opts && opts.body && opts.body.fields) || {} });
    return promise;
};
global.XMLHttpRequest = function () {
    this.upload = {}; this.open = function () {}; this.send = function () {};
};

global.interact = function () {
    return { draggable() { return this; }, resizable() { return this; },
             on() { return this; }, unset() {} };
};
global.interact.modifiers = { restrictRect() { return {}; }, restrictSize() { return {}; } };
global.confirm = () => true;
global.alert   = () => {};
global.FormData = function () {
    this.fields = {};
    this.append = function (k, v) { this.fields[k] = v; };
};
global.setTimeout   = () => 0;
global.setInterval  = () => 0;
global.clearTimeout = () => {};

/** Let the promise callbacks the page queued actually run. */
function settled() { return new Promise(function (r) { process.nextTick(r); }); }

// ---- The two themes and the store default, as the page carries them ---------
//
// Shaped exactly as builder.php builds them: keyed by *custom property name*, because
// `SiteChrome::varName()` is the one place that turns a role into `--nav-bg` and a
// script doing its own conversion would be the second opinion.

const THIRTEEN = ['--nav-bg', '--nav-border', '--nav-text', '--accent', '--work-area',
                  '--panel', '--panel-border', '--status-good', '--status-warn',
                  '--status-bad', '--status-busy', '--status-note', '--selection'];

function varsFrom(base, over) {
    const out = {};
    THIRTEEN.forEach(function (name, i) { out[name] = base + (i < 10 ? '0' : '') + i; });
    Object.keys(over || {}).forEach(function (k) { out[k] = over[k]; });
    return out;
}

// Distinguishable by construction: every value in a set shares a prefix, so a check can
// say which theme a property came from rather than only that it changed.
const NIGHT = { id: 3, name: 'Night shift', vars: varsFrom('#aa00') };
const DAY   = { id: 8, name: 'Daylight',    vars: varsFrom('#bb00') };
const STORE = varsFrom('#cc00');

// ---- The page's own JavaScript ----------------------------------------------

const php = fs.readFileSync(BUILDER, 'utf8');

let js = buildPageJs(BUILDER, {
    READ_ONLY:      false,
    IS_ADMIN:       true,
    UNDO_LIMIT:     5,
    DISPLAY_TAG:    'salmon-a',
    DISPLAY_TITLE:  'Salmon House A',
    CSRF_TOKEN:     'tok',
    LAYOUT_STAMP:   '4',
    THEMES:         [NIGHT, DAY],
    THEME_ID:       3,
    THEME_STORE:    STORE,
});

// Each of the three theme constants has to have *replaced* something. A page constant
// renamed and a suite still substituting the old name is a suite testing the default —
// and the default here is `0`, which would make `THEMES.length` throw and `THEME_ID`
// zero, so a whole section would pass by never doing anything.
check(/var THEMES = \[\{/.test(js),      'the page carries the themes this account may choose');
check(/var THEME_ID = 3;/.test(js),      'and which one it is wearing');
check(/var THEME_STORE = \{/.test(js),   'and the thirteen colours the store default puts back');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ---- The markup this harness mirrors ----------------------------------------
// The picker is server-rendered, so the nodes below stand in for it — and each thing the
// mirror relies on is asserted against builder.php first, or the suite is only agreeing
// with itself.

section('The picker the server draws');

check(php.indexOf('id="theme-pick"') >= 0, 'the page emits a Workspace Theme picker');
check(php.indexOf('id="theme-warn"') >= 0, 'and somewhere to say a choice was not saved');
check(/data-theme-id="0"/.test(php),       'the store default is an item like any other');
check(/onclick="chooseTheme\(0\)"/.test(php),
      'and choosing it goes through the same function as a theme');
check(/data-theme-id="<\?= intval\(\$tEntry\['id'\]\) \?>"/.test(php),
      'each theme carries its id as a number, where nothing can escape from it');
check(/onclick="chooseTheme\(<\?= intval\(\$tEntry\['id'\]\) \?>\)"/.test(php),
      'and calls chooseTheme with that number');
check(/<span class="tp-tick">/.test(php), 'and holds a tick for the one in use');

// It is in the gear, and it is *outside* the read-only branch: a person who may not edit
// this sign may still want their own screen legible. Position in the file, because that
// is what "in the gear menu" means in a server-rendered page.
const gearAt   = php.indexOf('<div id="gear-menu">');
const pickAt   = php.indexOf('id="theme-pick"');
// Searched forward from the gear, because the Display picker page earlier in this file
// has an Asset Library link of its own — and the first version of this check found that
// one and reported the picker as being in the wrong place.
const libAt    = php.indexOf('<a href="crud.php">Asset Library</a>', gearAt);
check(gearAt >= 0 && pickAt > gearAt && libAt > pickAt,
      'it sits inside the gear menu, above the destinations');
check(php.indexOf('$readOnly') < gearAt || php.indexOf('id="theme-pick"') < php.indexOf('if ($readOnly):'),
      'and is not inside a read-only branch — a theme is about the person, not the sign');

const pick = byId('theme-pick');
[{ id: 0, name: 'Store default' }, NIGHT, DAY].forEach(function (entry) {
    const item = el('button', 'tp-item' + (entry.id === 3 ? ' on' : ''));
    item.dataset.themeId = entry.id;
    const tick = el('span', 'tp-tick');
    tick.innerHTML = entry.id === 3 ? '&#10003;' : '';
    item.appendChild(tick);
    pick.appendChild(item);
});
const warnBox = byId('theme-warn');
pick.appendChild(warnBox);

function pickItem(id) {
    return pick.querySelectorAll('.tp-item').filter(function (i) {
        return parseInt(i.dataset.themeId, 10) === id;
    })[0];
}

function tickedIds() {
    return pick.querySelectorAll('.tp-item')
               .filter(function (i) { return i.classList.contains('on'); })
               .map(function (i) { return parseInt(i.dataset.themeId, 10); });
}

// ---- A canvas holding work nobody has published -----------------------------

section('A canvas with unpublished work on it');

const canvas = byId('builder-canvas');
canvas.style.width  = '1920px';
canvas.style.height = '1080px';
renderBlock({
    type: 'text', block_subtype: 'free', x_pos: 20, y_pos: 20, width: 200, height: 60,
    manual_content: 'Dungeness crab', asset_id: null, locked: 0, z_index: 1, hidden: 0
}, canvas);
// Twice, with an edit between, because the first commit only establishes the baseline —
// there is nothing behind the first state to go back to. A single call here would have
// left the stack empty and made every "the history has not moved" check below true by
// having nothing to move.
commitUndoStep();
renderBlock({
    type: 'text', block_subtype: 'free', x_pos: 260, y_pos: 20, width: 200, height: 60,
    manual_content: 'Sockeye fillet', asset_id: null, locked: 0, z_index: 2, hidden: 0
}, canvas);
commitUndoStep();

const blockBefore    = canvas.children[0];
const stepsBefore    = undoStack.length;
const baselineBefore = undoBaseline;
checkSame(2, canvas.children.length, 'there are two blocks on the canvas');
check(stepsBefore > 0, 'and a step in the history behind them');
check(typeof baselineBefore === 'string' && baselineBefore.length > 0,
      'and a baseline the next edit would be measured against');
checkSame(0, sent.length, 'and nothing has been sent to the server yet');

// ---- Choosing another theme -------------------------------------------------

section('Choosing another theme repaints the chrome and nothing else');

chooseTheme(DAY.id);

checkSame(13, Object.keys(rootProps).length, 'thirteen custom properties are set, one per role');
THIRTEEN.forEach(function (name) {
    checkSame(DAY.vars[name], rootProps[name], name + ' is the chosen theme\'s colour');
});
checkSame(DAY.id, THEME_ID, 'the page knows which theme it is on');
checkSame(1, tickedIds().length, 'exactly one item is ticked');
checkSame(DAY.id, tickedIds()[0], 'and it is the one that was chosen');
checkMentions(pickItem(DAY.id).querySelector('.tp-tick').innerHTML, '10003',
              'the tick is drawn on it');
checkSame('', pickItem(NIGHT.id).querySelector('.tp-tick').innerHTML,
          'and taken off the one it left');

// The point of the whole design: the work is still there.
check(canvas.children[0] === blockBefore,
      'the block on the canvas is the same node — nothing was rebuilt');
checkSame('Dungeness crab', blockBefore.querySelector('.text-inner').textContent,
          'still holding what was typed into it');
checkSame(stepsBefore, undoStack.length,
          'and the undo history has not moved — a theme is not a change to a layout');
checkSame(baselineBefore, undoBaseline,
          'nor has the baseline the next edit will be measured against');

// ---- What it sent -----------------------------------------------------------

section('And it records the choice, once');

checkSame(1, sent.length, 'one request went out');
checkSame('api.php', sent[0].url, 'to the endpoint that owns this write');
checkSame('POST', sent[0].opts.method, 'as a POST, because it changes something');
checkSame('choose_theme', sent[0].fields.action, 'naming the action');
checkSame(String(DAY.id), sent[0].fields.theme_id, 'and the theme');
checkSame('tok', sent[0].fields.csrf_token, 'with the session\'s token');

// Choosing the one already on does nothing at all — no paint, no request. A control that
// re-sent on every click would turn an idle menu into traffic.
chooseTheme(DAY.id);
checkSame(1, sent.length, 'choosing the theme already in use sends nothing');
checkSame(DAY.id, THEME_ID, 'and leaves the page where it was');

(async function () {

    // ---- The save that succeeds ---------------------------------------------

    section('A save that lands leaves the page as it is');

    sent[0].settle.resolve({ json: function () { return Promise.resolve({ status: 'success', theme_id: DAY.id }); } });
    await settled(); await settled();

    checkSame(DAY.id, THEME_ID, 'the chosen theme is still the chosen theme');
    checkSame(DAY.vars['--nav-bg'], rootProps['--nav-bg'], 'and the page is still painted in it');
    checkSame('none', warnBox.style.display, 'and nothing is being warned about');

    // ---- The save that does not ---------------------------------------------
    //
    // The paint happens before the round trip, so this is the state where the screen and
    // the database disagree. Left alone it is a preference that looks set and is not —
    // and the person finds out next time they sign in, with no way to connect the two.

    section('A save that fails puts the page back and says so');

    chooseTheme(NIGHT.id);
    checkSame(NIGHT.vars['--accent'], rootProps['--accent'], 'the new theme is applied straight away');
    checkSame(2, sent.length, 'and a second request goes out');

    sent[1].settle.resolve({ json: function () {
        return Promise.resolve({ status: 'error', reason: 'invalid',
                                 message: 'That theme does not exist any more.' });
    } });
    await settled(); await settled(); await settled();

    checkSame(DAY.id, THEME_ID, 'the page is back on the theme it was actually wearing');
    THIRTEEN.forEach(function (name) {
        checkSame(DAY.vars[name], rootProps[name], name + ' is painted from that theme again');
    });
    checkSame(1, tickedIds().length, 'one item is ticked');
    checkSame(DAY.id, tickedIds()[0], 'and it is the one the account is really on');
    checkSame('block', warnBox.style.display, 'the card says something went wrong');
    checkMentions(warnBox.textContent, 'not saved', 'in those words');
    checkMentions(warnBox.textContent, 'still on the theme you had',
                  'and says what is true now rather than only what failed');
    checkMentions(warnBox.textContent, 'does not exist any more',
                  'carrying the server\'s own reason with it');

    // A dropped connection is the same story told by a rejection rather than a reply.
    // The `.catch()` has to cover both, and a version that only handled `!success`
    // would leave the page painted in a theme nobody chose (§4au).
    section('And so does a request that never arrives');

    chooseTheme(NIGHT.id);
    checkSame(NIGHT.vars['--panel'], rootProps['--panel'], 'the switch is applied');
    sent[2].settle.reject(new Error('Failed to fetch'));
    await settled(); await settled(); await settled();

    checkSame(DAY.id, THEME_ID, 'a network failure reverts it too');
    checkSame(DAY.vars['--panel'], rootProps['--panel'], 'right down to the panel colour');
    checkMentions(warnBox.textContent, 'not saved', 'and says so in the same words');

    // ---- The way back to the store default ----------------------------------

    section('And "use the store default" works from any state (decision 14)');

    chooseTheme(0);
    checkSame(0, THEME_ID, 'the account is back on the store default');
    THIRTEEN.forEach(function (name) {
        checkSame(STORE[name], rootProps[name], name + ' is the store\'s own colour');
    });
    checkSame(0, tickedIds()[0], 'and the tick is on the store default');
    checkSame(String(0), sent[3].fields.theme_id, 'sent as 0, which is what the endpoint reads as "the default"');
    // Every property is still *set*, rather than removed. Removing them would fall back
    // to the `:root` block, and that block was rendered wearing the theme being escaped.
    checkSame(13, Object.keys(rootProps).length,
              'the thirteen are set to the store\'s values, not removed and left to the stylesheet');
    sent[3].settle.resolve({ json: function () { return Promise.resolve({ status: 'success', theme_id: 0 }); } });
    await settled(); await settled();
    checkSame(0, THEME_ID, 'and it stays there once the save lands');

    // Still nothing has happened to the layout, after five switches.
    check(canvas.children[0] === blockBefore, 'the canvas block is still the same node');
    checkSame(2, canvas.children.length, 'both blocks are still there');
    checkSame(stepsBefore, undoStack.length, 'and the undo history still has not moved');
    checkSame(baselineBefore, undoBaseline, 'nor the baseline behind it');

    // ---- The picker is not drawn in the thing it controls --------------------

    section('The picker renders un-themed, and the gear stays findable');

    // Every rule for the card, out of the stylesheet, checked for a `var(--…)`. A themed
    // picker is how one bad theme becomes permanent: set panel and its text to the same
    // colour and the menu that would have fixed it is a blank rectangle.
    const styleBlocks = (php.match(/<style>[\s\S]*?<\/style>/g) || []).join('\n')
                            .replace(/\/\*[\s\S]*?\*\//g, ' ');
    const pickRules = (styleBlocks.match(/#theme-pick[^{}]*\{[^{}]*\}/g) || []);
    check(pickRules.length >= 5, 'the card has rules of its own — ' + pickRules.length + ' of them');
    check(pickRules.join('\n').indexOf('var(--') < 0,
          'and not one of them reaches a theme role');
    check(/#theme-pick\s*\{[^}]*background:\s*#[0-9a-f]{6}/i.test(styleBlocks),
          'its background is a literal colour');
    check(/\.tp-item[^{}]*\{[^}]*color:\s*#[0-9a-f]{6}/i.test(styleBlocks),
          'and so is the text on its items');
    check(/\.tp-warn[^{}]*\{[^}]*(background|color):\s*#[0-9a-f]{6}/i.test(styleBlocks),
          'and the sentence about a failed save, which must not be drawn in what failed');

    // The route to it. `$gearNeedsChip` asks the contrast rule at render time, so the
    // chip appears exactly when the nav colour would have hidden the glyph — and today's
    // dark nav means nobody who has never made a theme sees any change.
    check(/\$gearNeedsChip\s*=\s*Color::hardToRead\(/.test(php),
          'the gear asks whether it would be readable on this nav colour');
    check(/class="nav-icon<\?= \$gearNeedsChip \? ' gear-safe' : '' \?>"/.test(php),
          'and wears a fixed chip when it would not be');
    check(/\.nav-icon\.gear-safe\s*\{[^}]*background:\s*#[0-9a-f]{6}/i.test(styleBlocks),
          'a chip whose colours are literals, so it cannot be hidden by the theme it escapes');

    // ---- What the page hands the script -------------------------------------

    section('The names three separate things agree about');

    check(/SiteChrome::varName\(\$role\)/.test(php),
          'the payload is keyed by SiteChrome::varName(), not by a replace() in script');
    check(js.indexOf("replace(/_/g, '-')") < 0 && js.indexOf('replace(/_/g,"-")') < 0,
          'and the script never turns a role name into a property name itself');
    check(/root\.style\.setProperty\(name, vars\[name\]\)/.test(js),
          'the repaint sets custom properties rather than rewriting a stylesheet');
    check(js.indexOf('location.reload') < 0 || !/chooseTheme[\s\S]{0,600}location\.reload/.test(js),
          'and nothing in the theme path reloads the page');

    // ---- Report -------------------------------------------------------------

    // Anchored, for the reason `selftest_layout.php` anchors its own: without a
    // number here, deleting half this file still reports a clean run. Four of the
    // eight node suites carried one and four did not (§4bf).
    const expected = 110;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected '
                   + expected + ', ran ' + checks);
    }

    console.log('');
    if (fails.length) {
        console.log(checks + ' checks, ' + fails.length + ' failed');
        fails.forEach(function (f) { console.log('  FAILED: ' + f); });
        process.exit(1);
    }
    console.log(checks + ' checks, 0 failed');
})();
