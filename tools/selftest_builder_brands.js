// ============================================================
// SELF-TEST — the Builder wearing a Brand, and the switch that writes nothing
// ============================================================
//   node tools/selftest_builder_brands.js
//
// The seventh harness over builder.php's inline JavaScript, and the one for step 4 of
// the v2 roadmap: a Display wears a Brand (ADR-0011), the Builder says which, and an
// admin may put it on another one.
//
// The premise is an admin holding the edit lock on a sign that could wear either of two
// venues' identities. What that premise makes visible, and nothing else here can:
//
//   The switch writes nothing      Picking a Brand repaints what is on screen and
//                                  stages the choice for the next Publish (decision 6).
//                                  A version of this that saved as you picked would
//                                  look identical on screen and change every sign in
//                                  the venue the moment somebody browsed the menu.
//                                  Asserted by counting requests: zero.
//
//   The repaint is real            The six branded block types are the Brand's to
//                                  paint, so a price has to *change* when the venue
//                                  does — and a block that owns its typography must
//                                  not. Both directions, because a repaint that moved
//                                  everything would pass a check that only watched the
//                                  price.
//
//   And it costs no undo step      Cycling Brands must not fill the history with steps
//                                  recording changes nobody made — invariant 27 the
//                                  other way round. That this holds at all is step 1's
//                                  doing (publish stopped carrying brand-owned
//                                  typography), so it is checked here rather than
//                                  assumed: the two features are one property.
//
//   A swatch is an offer           The palette is offered above every colour control
//                                  and enforced nowhere (decision 4). A swatch fills
//                                  the picker under it and runs what that picker's own
//                                  handler would have run — because setting `.value`
//                                  from script fires no event, and a swatch that moved
//                                  the control and nothing on the canvas is a control
//                                  that lies.
//
// What is deliberately *not* here: the read-only and basic-account cases, which are a
// different premise and live in selftest_builder_readonly.js — the suite that already
// runs the page with its editing controls absent, and the one that owns the walker over
// this file's conditionals. And the server's half — what a publish is allowed to write,
// and what happens when the Brand it names has been deleted — is in
// tools/selftest_layout.php, because none of it is JavaScript.
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
//
// The undo suite's, because this one drives the same machinery: a Brand switch
// repaints by way of snapshotCanvas() and restoreCanvas(), so the canvas here has to
// survive a rebuild — nodes that measure, a dataset that stringifies, and selectors
// that parse. Two things are added that no other suite needed:
//
//   classList.toggle   both menus in this page use it, and a stub without it turns
//                      opening the Brand menu into a TypeError.
//   document listeners are kept, so a click somewhere else on the page can be fired.
//                      A menu that opens and cannot be closed is a real defect and an
//                      invisible one to a stub that drops the handler.

function classesOf(node) {
    return String(node.className || '').split(/\s+/).filter(Boolean);
}

function camel(attr) {
    return attr.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
}

/** The subset of selector syntax builder.php uses on a single node. */
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

/** px out of a CSS length, so a node laid out by the renderer can be measured. */
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
        // Two arguments, as both of this page's menus call it. The one-argument form is
        // not implemented on purpose: nothing here uses it, and a stub that guessed at
        // it would be a second opinion about the DOM.
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
    // What "a click inside this menu" means. A node contains itself, as a browser's
    // does — the click that opened the menu lands on the button, and a version that
    // said no to that would close the menu on the way to opening it.
    node.contains = function (other) {
        return other === node || descendants(node).indexOf(other) >= 0;
    };
    node.getBoundingClientRect = function () {
        return { left: 0, top: 0, width: node.offsetWidth, height: node.offsetHeight };
    };
    node.addEventListener = function (type, fn) { (node._on[type] || (node._on[type] = [])).push(fn); };
    node.focus = function () {}; node.blur = function () {};
    node.load = function () {};
    // An `innerHTML = ''` has to actually empty the node, or every redraw of a swatch
    // row would stack another six on the end and the counts below would all pass while
    // the rail filled up.
    Object.defineProperty(node, 'innerHTML', {
        get() { return node._html || ''; },
        set(v) { node._html = String(v); if (String(v) === '') { node.children.length = 0; } },
        configurable: true
    });
    return node;
}

/** Fire every handler wired to `node` for `type`, as the browser would. */
function fire(node, type, event) {
    (node._on[type] || []).slice().forEach(function (fn) { fn(event || {}); });
}

const nodes = {};
function byId(id) {
    if (!nodes[id]) { nodes[id] = el('div'); nodes[id].id = id; }
    return nodes[id];
}

const docHandlers = {};
global.document = {
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
global.window       = { getSelection() { return null; }, addEventListener() {}, innerWidth: 1280, innerHeight: 800 };
global.navigator    = { sendBeacon() { return true; } };

// Every request this page could make, counted rather than answered. The whole claim of
// this suite's first section is that picking a Brand makes none.
let requests = 0;
global.fetch = function () {
    requests++;
    return new Promise(function () {});     // never resolves: no reply is being tested
};
global.XMLHttpRequest = function () {
    requests++;
    this.upload = {}; this.open = function () {}; this.send = function () {};
};

global.interact = function () {
    return { draggable() { return this; }, resizable() { return this; },
             on() { return this; }, unset() {} };
};
global.interact.modifiers = { restrictRect() { return {}; }, restrictSize() { return {}; } };
global.confirm      = () => true;
global.alert        = () => {};
// Captures what publishCanvas() actually put on the wire — the last one built.
global.FormData = function () {
    this.fields = {};
    this.append = function (k, v) { this.fields[k] = v; };
    global.FormData.last = this;
};
function lastFormData() { return (global.FormData.last || { fields: {} }).fields; }
global.setTimeout   = () => 0;
global.setInterval  = () => 0;
global.clearTimeout = () => {};

// ---- The two venues, as the page would carry them ---------------------------
//
// Shaped exactly like `Brand::toClientArray()` plus the two keys builder.php adds to
// it: `styles` (the six standards, keyed as the layout reply's `block_styles` is) and
// `logo_src` (the file behind the library row, or '' when there is nothing to draw).
//
// `Salmon House` deliberately carries a palette with a hole in it and a value no
// browser can read. The server sends these already read through `Color::read()`, so
// neither should be there — which is why they are: the page validates them again on the
// way into a `style`, and a check that only fed it good colours could not tell.

const BRAND_HOME = {
    id: 1, name: 'Lummi Bay Market', logo_asset_id: 0, bg_type: 'color', bg_val: '#1a1a2e',
    palette: ['#c0392b', '#2ecc71'], logo_src: '',
    styles: {
        price:          { font_family: 'Arial', font_size: 48, font_color: '#ffffff',
                          font_weight: 'bold', font_style: 'normal', line_height: 1.1 },
        section_header: { font_family: 'Arial', font_size: 30, font_color: '#ffffff',
                          font_weight: 'bold', font_style: 'normal', line_height: 1.2 }
    }
};

const BRAND_SALMON = {
    id: 7, name: 'Salmon House', logo_asset_id: 12, bg_type: 'color', bg_val: '#0b3d2e',
    palette: ['#0b3d2e', 'puce', '', '#e67e22'], logo_src: 'uploads/salmon-house.png',
    styles: {
        price:          { font_family: 'Georgia', font_size: 64, font_color: '#f1c40f',
                          font_weight: 'bold', font_style: 'italic', line_height: 1.25 },
        section_header: { font_family: 'Georgia', font_size: 34, font_color: '#f1c40f',
                          font_weight: 'bold', font_style: 'normal', line_height: 1.2 }
    }
};

// A Brand pointing at a logo the library no longer has, and with no palette at all.
// Both are states the rail has to say something about rather than merely not draw.
const BRAND_GHOST = {
    id: 9, name: 'Tap Room', logo_asset_id: 44, bg_type: 'color', bg_val: '#222222',
    palette: [], logo_src: '',
    styles: BRAND_HOME.styles
};

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
    BRANDS:         [BRAND_HOME, BRAND_SALMON, BRAND_GHOST],
    BRAND_ID:       1,
    CAN_PICK_BRAND: true,
});

// Each of the five above has to have *replaced* something. A page constant renamed and
// a suite still substituting the old name is a suite quietly testing the default — and
// for CAN_PICK_BRAND the default is `0`, which is falsy, so every check below would
// pass by never running anything.
check(/var BRANDS = \[/.test(js),          'the page carries the Brands it may switch to');
check(/var BRAND_ID = 1;/.test(js),        'and which of them is on the canvas');
check(/var CAN_PICK_BRAND = true;/.test(js), 'and whether this page may change it');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ---- The markup this harness mirrors ----------------------------------------
//
// The Brand menu is server-rendered, so the nodes below stand in for it. A hand-written
// mirror with nothing holding it to the page is the mistake `PRESENT` made in the
// read-only suite — an entry that was never an id at all — so each thing this mirror
// relies on is asserted against builder.php first.

check(php.indexOf('id="brand-menu"') >= 0,      'the page emits a Brand menu');
check(/data-brand-id="<\?= intval\(\$b->id\(\)\) \?>"/.test(php),
      'each item carries its Brand id as a number, where nothing can escape from it');
check(/onclick="switchBrand\(<\?= intval\(\$b->id\(\)\) \?>\)"/.test(php),
      'and calls switchBrand with that number');
check(/<span class="tick">/.test(php),          'and holds a tick for the one that is on');
check(php.indexOf('id="brand-name"') >= 0,      'the control has a name to fill in');
check(php.indexOf('id="brand-logo"') >= 0,      'and a logo to draw');

// The four colour controls, each with the palette above it — above, not beside, and
// checked by position in the file rather than by eye.
[['sw-bg', 'bg-color'], ['sw-font', 'font-color'],
 ['sw-marquee', 'marquee-color'], ['sw-marquee-bg', 'marquee-bg']].forEach(function (pair) {
    const row   = php.indexOf('id="' + pair[0] + '"');
    const input = php.indexOf('id="' + pair[1] + '"');
    check(row >= 0 && input >= 0 && row < input,
          'the palette row for ' + pair[1] + ' is on the page, above the picker it fills');
});

const menu = byId('brand-menu');
[BRAND_HOME, BRAND_SALMON, BRAND_GHOST].forEach(function (brand) {
    const item = el('button', 'brand-item' + (brand.id === 1 ? ' on' : ''));
    item.dataset.brandId = brand.id;
    const tick = el('span', 'tick');
    tick.textContent = brand.id === 1 ? '✓' : '';
    item.appendChild(tick);
    const name = el('span', 'brand-name');
    name.textContent = brand.name;
    item.appendChild(name);
    menu.appendChild(item);
});

function menuItem(id) {
    return menu.querySelectorAll('.brand-item').filter(function (i) {
        return parseInt(i.dataset.brandId, 10) === id;
    })[0];
}

// ---- A canvas with one of each kind of typography ---------------------------

const canvas = byId('builder-canvas');
canvas.style.width  = '1920px';
canvas.style.height = '1080px';

function buildFixture() {
    canvas.children.slice().forEach(function (c) { c.remove(); });
    const sec = renderSection({
        type: 'section', temp_id: 'tmp-sec-1', id: 11,
        x_pos: 40, y_pos: 20, width: 900, height: 500, section_bg: null, locked: 0, z_index: 1
    });
    // Branded: its six typography fields are the Brand's to paint (invariant 32).
    renderBlock({
        type: 'text', block_subtype: 'price', x_pos: 20, y_pos: 20, width: 160, height: 60,
        manual_content: '18.99', asset_id: null, locked: 0, z_index: 1, hidden: 0
    }, sec);
    // Its own: `free` text owns its typography, and no Brand may touch it.
    renderBlock({
        type: 'text', block_subtype: 'free', x_pos: 20, y_pos: 200, width: 320, height: 80,
        manual_content: 'Ask about today’s catch', asset_id: null, locked: 0, z_index: 1, hidden: 0,
        font_family: 'Verdana', font_size: 22, font_color: '#123456',
        font_weight: 'normal', font_style: 'normal', line_height: 1.4
    }, sec);
    renderBlock({
        type: 'marquee', block_subtype: 'free', x_pos: 0, y_pos: 900, width: 1920, height: 60,
        manual_content: JSON.stringify({ text: 'Fresh today', speed: 80, color: '#ffffff',
                                         size: 28, weight: 'bold', bg: 'transparent', bgColor: '#c0392b' }),
        asset_id: null, locked: 0, z_index: 1, hidden: 0
    }, canvas);
    resetUndoHistory();
    return sec;
}

function blockOf(subtypeOrType) {
    return canvas.querySelectorAll('.editable-block').filter(function (n) {
        return n.dataset.subtype === subtypeOrType || n.dataset.type === subtypeOrType;
    })[0];
}
function priceBlock()   { return blockOf('price'); }
function freeBlock()    { return canvas.querySelectorAll('.editable-block').filter(function (n) {
                              return n.dataset.type === 'text' && n.dataset.subtype === 'free'; })[0]; }
function marqueeBlock() { return blockOf('marquee'); }

blockStyles = BRAND_HOME.styles;
buildFixture();
refreshBrandSurfaces();

// ─────────────────────────────────────────────────────────────
section('The page opens wearing one Brand, and says which');

checkSame(1, BRAND_ID, 'the canvas is wearing the Brand the server said it was');
checkSame('Lummi Bay Market', byId('brand-name').textContent, 'the control names that venue');
checkSame('none', byId('brand-logo').style.display,
          'and draws no logo, because this Brand has none — not a broken picture');
check(menuItem(1).classList.contains('on'), 'the menu ticks the one that is on');
check(!menuItem(7).classList.contains('on'), 'and only that one');

checkSame('48px', priceBlock().style.fontSize, 'the price is painted at the Brand\'s size');
checkSame('1',    priceBlock().dataset.brandTypography, 'and is marked as wearing it');
checkSame('22px', freeBlock().style.fontSize, 'while free text keeps its own');
checkSame(undefined, freeBlock().dataset.brandTypography, 'and carries no marker');

// ─────────────────────────────────────────────────────────────
section('Picking another Brand repaints the canvas and writes nothing');

const requestsBefore = requests;
const priceBefore    = priceBlock();
const stepsBefore    = undoStack.length;

switchBrand(7);

checkSame(0, requests - requestsBefore, 'switching Brand sends no request at all');
checkSame(7, BRAND_ID, 'the page is now staging the other venue');
checkSame('Georgia', blockStyles.price.font_family, 'and the standards on hand are that venue\'s');

check(priceBlock() !== priceBefore, 'the canvas was rebuilt, not left alone');
checkSame('64px', priceBlock().style.fontSize, 'the price is repainted at the new Brand\'s size');
checkSame('Georgia', priceBlock().style.fontFamily, 'in its font');
checkSame('italic', priceBlock().style.fontStyle, 'and its style');
checkSame('#f1c40f', priceBlock().style.color, 'and its colour');
checkSame('18.99', priceBlock().querySelector('.text-inner').innerText,
          'while still showing the price it showed — a repaint is not a reload');

// The other half, and the one a check that only watched the price would miss.
checkSame('22px', freeBlock().style.fontSize, 'a block that owns its typography is untouched');
checkSame('Verdana', freeBlock().style.fontFamily, 'font and all');
checkSame('#123456', freeBlock().style.color, 'colour included — the palette is an offer, not a rule');

// invariant 27 the other way round. This holds only because publish stopped carrying
// brand-owned typography (step 1); the two are one property, so it is checked and not
// assumed.
checkSame(stepsBefore, undoStack.length, 'the switch records no undo step');
checkSame(false, commitUndoStep(),
          'and there is nothing left over for the next commit to mistake for an edit');

// ─────────────────────────────────────────────────────────────
section('The control follows the canvas');

checkSame('Salmon House', byId('brand-name').textContent, 'the control names the venue now showing');
checkSame('uploads/salmon-house.png', byId('brand-logo').src, 'draws its logo');
checkSame('inline-block', byId('brand-logo').style.display, 'and shows it');
check(menuItem(7).classList.contains('on'), 'the tick has moved');
check(!menuItem(1).classList.contains('on'), 'and left the Brand that is no longer on');
checkSame('✓', menuItem(7).querySelector('.tick').textContent,
          'and it is a tick rather than only a highlight, which is also what hover does');
checkSame('', menuItem(1).querySelector('.tick').textContent, 'the other item has none');

// ─────────────────────────────────────────────────────────────
section('The menu opens, and closes when somebody looks elsewhere');

const brandMenu = byId('brand-menu');
const brandBtn  = byId('brand-btn');

toggleBrandMenu({ stopPropagation() {} });
check(brandMenu.classList.contains('open'), 'the menu opens');
checkSame('true', brandBtn.getAttribute('aria-expanded'), 'and says so to a screen reader');

// A click anywhere else on the page. Fired through the real listener the page installed,
// because a menu that opens and cannot be closed is a defect a stub that dropped the
// handler could never see.
(docHandlers.click || []).forEach(function (fn) { fn({ target: byId('builder-canvas') }); });
check(!brandMenu.classList.contains('open'), 'a click elsewhere closes it');
checkSame('false', brandBtn.getAttribute('aria-expanded'), 'and says that too');

toggleBrandMenu({ stopPropagation() {} });
(docHandlers.click || []).forEach(function (fn) { fn({ target: menuItem(1) }); });
check(brandMenu.classList.contains('open'),
      'while a click on one of its own items leaves it alone — that is somebody using it');

// And choosing puts it away, so the page does not need a second click to tidy up.
switchBrand(1);
check(!brandMenu.classList.contains('open'), 'choosing a Brand closes the menu');
checkSame(1, BRAND_ID, 'and switches back');
checkSame('48px', priceBlock().style.fontSize, 'repainting the price again');

// ─────────────────────────────────────────────────────────────
section('An id this page was never offered changes nothing');

const beforeUnknown = BRAND_ID;
switchBrand(4242);
checkSame(beforeUnknown, BRAND_ID, 'a Brand that is not in the list is not switched to');
checkMentions(byId('toast').textContent, 'not one this page was offered',
              'and the refusal says what happened');
checkSame('err', byId('toast').className, 'as a refusal rather than as news');

// Choosing the one already on is not a refusal and not a repaint — it is nothing.
const samePrice = priceBlock();
switchBrand(1);
checkSame(samePrice, priceBlock(), 'choosing the Brand already on rebuilds nothing');

// ─────────────────────────────────────────────────────────────
section('The palette is offered above every colour control, and enforced nowhere');

switchBrand(7);   // the venue with a palette worth drawing

const swFont = byId('sw-font');
const drawn  = swFont.querySelectorAll('.sw');
checkSame(2, drawn.length,
          'only the palette colours a browser can actually paint become swatches');
checkSame('#0b3d2e', drawn[0].style.background, 'the first is the venue\'s own green');
checkSame('#e67e22', drawn[1].style.background, 'and the readable one after the gap');
checkSame('flex', swFont.style.display, 'the row is on screen');
checkMentions(swFont.querySelectorAll('.sw-cap')[0].textContent, 'Brand',
              'captioned, so a row of colours is not mistaken for the block\'s own');
checkMentions(drawn[0].title, '#0b3d2e', 'and each swatch says which colour it is');

// Every one of the four, from one list — so a picker added later without a row is a
// failure here rather than a control somebody notices has no palette.
['sw-bg', 'sw-font', 'sw-marquee', 'sw-marquee-bg'].forEach(function (row) {
    checkSame(2, byId(row).querySelectorAll('.sw').length,
              row + ' offers the same palette');
    checkSame('flex', byId(row).style.display, row + ' is shown');
});

// A Brand with no palette is an ordinary Brand, not a broken one.
switchBrand(9);
['sw-bg', 'sw-font', 'sw-marquee', 'sw-marquee-bg'].forEach(function (row) {
    checkSame(0, byId(row).querySelectorAll('.sw').length, row + ' offers nothing for a Brand with no palette');
    checkSame('none', byId(row).style.display, 'and ' + row + ' is not drawn at all');
});
switchBrand(7);

// Rendering the swatches changes nothing on the canvas. This is the whole of decision 4
// and the easiest thing in the feature to get wrong: a palette that applied itself
// would look like a working feature and would overwrite every colour on every sign the
// first time somebody opened the Builder.
checkSame('#123456', freeBlock().style.color, 'drawing the palette recolours nothing');
checkSame('#f1c40f', priceBlock().style.color, 'branded or otherwise');

// ---- A swatch fills the picker under it, and does what that picker does ----------
const target = freeBlock();
selectBlock(target);
const beforeSwatchSteps = undoStack.length;
fire(byId('sw-font').querySelectorAll('.sw')[1], 'click', {});

checkSame('#e67e22', byId('font-color').value, 'clicking a swatch fills the colour picker');
checkSame('#e67e22', target.style.color, 'and puts that colour on the selected block');
checkSame(beforeSwatchSteps + 1, undoStack.length,
          'as one undo step, because a swatch is a finished choice rather than a drag');

// The marquee background is the one with a checkbox in front of it. A transparent
// marquee ignores its background colour, so a swatch that left the box ticked would be
// a control that did nothing and said nothing.
selectBlock(marqueeBlock());
checkSame(true, byId('marquee-bg-transparent').checked, 'the marquee starts out transparent');
fire(byId('sw-marquee-bg').querySelectorAll('.sw')[0], 'click', {});
checkSame(false, byId('marquee-bg-transparent').checked, 'a background swatch unticks Transparent');
checkSame('#0b3d2e', JSON.parse(marqueeBlock().dataset.marqueeData).bg,
          'so the colour it picked is what the sign will show');

// ─────────────────────────────────────────────────────────────
section('Venue Logo places the venue\'s logo, and is absent when there is none');

switchBrand(7);
checkSame('block', byId('brand-assets').style.display, 'a Brand with a logo offers the item');
checkSame('none', byId('brand-logo-warn').style.display, 'and warns about nothing');

const blocksBefore = canvas.querySelectorAll('.editable-block').length;
const logoSteps    = undoStack.length;
clearTargetSection();
createVenueLogo();

const placed = canvas.querySelectorAll('.editable-block').filter(function (n) {
    return n.dataset.type === 'image';
})[0];
check(!!placed, 'the item drops a block');
checkSame(blocksBefore + 1, canvas.querySelectorAll('.editable-block').length, 'exactly one');
checkSame('image', placed.dataset.type, 'an image block');
checkSame('12', placed.dataset.assetId, 'already linked to the library row the Brand points at');
checkSame('uploads/salmon-house.png', placed.querySelector('img').src, 'and showing that file');
checkSame(logoSteps + 1, undoStack.length,
          'as one undo step — created linked, rather than created and then linked');

// What the publish then carries for it: the link, and no content of its own. The same
// shape the asset dropdown produces, which is the point of going through createBlock().
const logoPayload = serializeCanvas().filter(function (e) { return e.type === 'image'; })[0];
checkSame('12', logoPayload.asset_id, 'the publish sends the library link');
checkSame('', logoPayload.manual_content, 'and no copy of the file path beside it');
checkSame(false, logoPayload.save_to_db_pool, 'and does not ask the library to pool it again');

placed.remove();

// A Brand with no logo at all: no item, no warning, and the function refuses if
// something reaches it anyway.
switchBrand(1);
checkSame('none', byId('brand-assets').style.display, 'a Brand with no logo offers no item');
checkSame('none', byId('brand-logo-warn').style.display, 'and there is nothing to warn about');
const noneBefore = canvas.querySelectorAll('.editable-block').length;
createVenueLogo();
checkSame(noneBefore, canvas.querySelectorAll('.editable-block').length,
          'and calling it anyway places nothing');
checkMentions(byId('toast').textContent, 'no logo to place', 'saying why');

// A Brand pointing at a library row that has been deleted. The absent button is
// indistinguishable from the feature not existing, so this one gets a sentence (#21).
switchBrand(9);
checkSame('none', byId('brand-assets').style.display, 'a Brand whose logo asset is gone offers no item');
checkSame('block', byId('brand-logo-warn').style.display, 'and says so, rather than saying nothing');
// Drawn as a warning rather than as a hint, which is a fact about the markup and is
// asked of the markup: the stub's node carries whatever this harness gave it, so a
// check on its className here would be this file agreeing with itself.
check(/id="brand-logo-warn" class="brand-warn"/.test(php),
      'and is drawn as a warning rather than as a hint');
checkMentions(php.slice(php.indexOf('id="brand-logo-warn"')), 'Display Branding',
              'naming the page somebody fixes it on');

// ─────────────────────────────────────────────────────────────
section('The layout reply has the last word on which Brand this is');

// The page was rendered one request before the layout arrived, so a colleague who moved
// this sign onto another venue — or renamed one — in between has the newer answer, and
// the standards the canvas is about to be painted from arrive with it. Left to the
// page's own copy, the control would name a venue the canvas is not wearing.
switchBrand(1);
blockStyles = BRAND_SALMON.styles;
adoptBrand({ id: 7, name: 'Salmon House & Bar', palette: ['#111111'], logo_asset_id: 12 });
refreshBrandSurfaces();
checkSame(7, BRAND_ID, 'the reply moves the page onto the Brand the sign really wears');
checkSame('Salmon House & Bar', byId('brand-name').textContent, 'under the name it really has');
checkSame(1, byId('sw-font').querySelectorAll('.sw').length, 'offering the palette it really has');
checkSame(BRAND_SALMON.styles.price.font_family, brandById(7).styles.price.font_family,
          'and the standards the canvas was painted from are the ones the switch will use again');
checkSame('uploads/salmon-house.png', brandById(7).logo_src,
          'while the logo file the page already knew about is kept — the reply does not carry one');

// A sign moved onto a Brand this page was never offered. A basic account is sent one
// Brand, so this is the ordinary shape of it rather than an edge case.
adoptBrand({ id: 31, name: 'Cedar Room', palette: ['#3d2b1f', '#c0392b'], logo_asset_id: 0 });
refreshBrandSurfaces();
checkSame(31, BRAND_ID, 'a Brand nobody offered is adopted rather than ignored');
checkSame('Cedar Room', byId('brand-name').textContent, 'and named');
checkSame(2, byId('sw-font').querySelectorAll('.sw').length, 'with its palette offered');
checkSame('', brandById(31).logo_src,
          'and no logo, because inventing a path would draw a broken picture');
checkSame('none', byId('brand-assets').style.display, 'so Venue Logo is not offered for it');
checkSame('none', byId('brand-logo-warn').style.display,
          'and nothing is warned about, because it points at no logo to be missing');

// A reply with no Brand at all — a database whose convergence has not run. The page
// keeps saying what it was saying, which is the honest answer.
const keptId = BRAND_ID;
adoptBrand(null);
checkSame(keptId, BRAND_ID, 'a reply carrying no Brand changes nothing');

// ─────────────────────────────────────────────────────────────
section('What a publish carries, and what it leaves out');

switchBrand(7);
endPublish();          // release the in-flight guard (§4ak): no reply is delivered here
publishCanvas();
const fd = lastFormData();
checkSame(7, fd.brand_id, 'a publish carries the Brand that was staged, not the one the page loaded with');
check('layout_data' in fd, 'beside the layout');
check('bg_val' in fd, 'and the background, which travels the same way');

// The state a database whose convergence has not run leaves the page in: no Brand
// control, and no `brand_id` in the publish. Sending 0 would be an id naming nothing,
// the endpoint would refuse it, and a lagging schema would become a sign nobody can
// publish to (invariant 10).
BRAND_ID = 0;
endPublish();
publishCanvas();
check(!('brand_id' in lastFormData()), 'a page with no Brand at all sends no brand_id');
check('layout_data' in lastFormData(), 'and still publishes the layout');

// ─────────────────────────────────────────────────────────────
// Anchored, for the reason `selftest_layout.php` anchors its own: without a number
// here, deleting half this file still reports a clean run. Four of the eight node
// suites carried one and four did not (§4bf).
const expected = 121;
if (checks !== expected) {
    fails.push('the suite ran every check it is supposed to — expected '
               + expected + ', ran ' + checks);
}

console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
if (fails.length) {
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(1);
}
