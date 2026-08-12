// ============================================================
// SELF-TEST — the Builder when somebody wants their last change back
// ============================================================
//   node tools/selftest_builder_undo.js
//
// The fourth harness over builder.php's inline JavaScript, and the one for Undo
// (ADR-0010). The other three each run the page under a premise about who is at
// the keyboard — a page that may not edit, a request that goes wrong, an ordinary
// good day. This one runs it under a premise about what just happened: the last
// thing they did was not what they meant.
//
// Undo in an app with no undo anywhere else is worth being suspicious of, because
// the two ways it can be wrong are both quiet:
//
//   Restore is lossy    The canvas comes back with the geometry right and the
//                       content, the lock, the hidden flag or the library link
//                       gone. It looks like it worked. The defect reaches the sign
//                       on the next publish, as a blank block, and by then the
//                       thing that would have said so is hours in the past.
//                       Guarded here by round-tripping: snapshot, change, restore,
//                       snapshot again, and the two strings must be identical.
//                       Compared as whole strings on purpose — a check that names
//                       the fields it cares about passes the day a field is added.
//
//   A step is missed    A change nobody committed means Undo skips it and lands on
//                       a canvas two changes old. This suite drives the real
//                       mutating functions — create, delete, drag, resize, hide,
//                       lock, align, layer, link, fit, text — and asserts each one
//                       leaves a step behind.
//
// And the two ways it can be too eager: a control operated with no effect must not
// spend a step, and a restore must not record itself as one.
//
// The DOM below is the editing suite's, with three additions the round trip needs
// and it did not: offsetWidth/offsetHeight read back from style, innerText and
// textContent are the same text, and `:not()` and `[data-x="y"]` parse. Without
// the first of those every width in a snapshot would be 0 and the round trip would
// compare two canvases of nothing.
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

function describe(v) {
    if (v === null)      { return 'null'; }
    if (v === undefined) { return 'undefined'; }
    if (typeof v === 'object') {
        return v.tagName
            ? '<' + v.tagName.toLowerCase() + (v.className ? ' class="' + v.className + '"' : '') + '>'
            : Object.prototype.toString.call(v);
    }
    return JSON.stringify(v);
}

function checkSame(expected, actual, label) {
    check(expected === actual, label + (expected === actual ? '' : ' — expected ' + describe(expected) + ', got ' + describe(actual)));
}

function section(title) { console.log('\n' + title); }

// ---- A DOM small enough to read and real enough to run ----------------------

function classesOf(node) {
    return String(node.className || '').split(/\s+/).filter(Boolean);
}

function camel(attr) {
    return attr.replace(/-([a-z])/g, function (_, c) { return c.toUpperCase(); });
}

/** The subset of selector syntax builder.php uses on a single node. */
function matchesSel(node, sel) {
    sel = sel.trim();
    // `.editable-block:not(.section-block)` — the one publish collects blocks with.
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

/** Handles `a, b` lists, `:scope > .x`, `.x`, `tag`, `tag[type="x"]`, `#id .x`. */
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
        steps.forEach(function (step, i) {
            const next = [];
            scope.forEach(function (s) {
                (i === 0 && s === node && matchesSel(node, step) ? [node] : descendants(s))
                    .forEach(function (d) { if (matchesSel(d, step) && next.indexOf(d) < 0) next.push(d); });
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
        id: '', className: className || '', type: '',
        style: { fontFamily: '', fontSize: '', color: '', fontWeight: '',
                 fontStyle: '', lineHeight: '', textAlign: '', display: '',
                 width: '', height: '', transform: '' },
        // A browser's dataset holds strings and nothing else, and the round trip
        // depends on it: _setZIndex writes a number, renderSection writes a database
        // id, and a snapshot that recorded 7 where the page will later read '7' is a
        // snapshot that never compares equal to itself.
        dataset: new Proxy({}, { set(t, k, v) { t[k] = String(v); return true; } }),
        children: [], files: [],
        value: '', textContent: '', innerHTML: '', checked: false, disabled: false,
        clientWidth: 0, clientHeight: 0,
        scrollLeft: 0, scrollTop: 0, parentNode: null, parentElement: null,
        _attrs: {}, _on: {}
    };
    // A block's size is the size the renderer gave it. Plain properties here — as
    // the other suites have them — would make every serialized width 0, and a round
    // trip that compares 0 with 0 is a green light for a restore that lost the lot.
    Object.defineProperty(node, 'offsetWidth',  { get() { return pxOf(node.style.width); }, configurable: true });
    Object.defineProperty(node, 'offsetHeight', { get() { return pxOf(node.style.height); }, configurable: true });
    // One text, two names for it — as a browser has. publishCanvas and blockContent
    // read innerText; renderBlock and linkAsset write textContent.
    Object.defineProperty(node, 'innerText', {
        get() { return node.textContent; },
        set(v) { node.textContent = v; },
        configurable: true
    });
    node.classList = {
        add(c)      { if (classesOf(node).indexOf(c) < 0) { node.className = (node.className + ' ' + c).trim(); } },
        remove(c)   { node.className = classesOf(node).filter(function (x) { return x !== c; }).join(' '); },
        contains(c) { return classesOf(node).indexOf(c) >= 0; }
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
    node.getBoundingClientRect = function () {
        return { left: 0, top: 0, width: node.offsetWidth, height: node.offsetHeight };
    };
    node.addEventListener = function (type, fn) { (node._on[type] || (node._on[type] = [])).push(fn); };
    node.focus = function () {}; node.blur = function () { fire(node, 'blur', {}); };
    node.load = function () {};
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
    addEventListener() {},
    body: el('body'),
    activeElement: null,
    execCommand() {},
    caretRangeFromPoint: null
};
global.window       = { getSelection() { return null; }, addEventListener() {}, innerWidth: 1280, innerHeight: 800 };
global.navigator    = { sendBeacon() { return true; } };
global.fetch        = () => Promise.resolve({ json: () => Promise.resolve({}) });
// Fuller than the other suites' stub, because restoreCanvas() re-runs
// setupInteract() — the new nodes have to be made draggable again, and a restore
// that forgot to would leave a canvas nothing can be moved on.
global.interact     = function () {
    return { draggable() { return this; }, resizable() { return this; },
             on() { return this; }, unset() {} };
};
global.interact.modifiers = { restrictRect() { return {}; }, restrictSize() { return {}; } };
global.confirm      = () => true;
global.alert        = () => {};
global.FormData     = function () { this.fields = {}; this.append = function (k, v) { this.fields[k] = v; }; };
global.XMLHttpRequest = function () { this.upload = {}; this.open = function () {}; this.send = function () {}; };
global.setTimeout   = () => 0;
global.setInterval  = () => 0;
global.clearTimeout = () => {};

// ---- The page's own JavaScript ----------------------------------------------

const php = fs.readFileSync(BUILDER, 'utf8');

let js = php.replace(/<\?(php|=)[\s\S]*?\?>/g, '0')
            .match(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)
            .map(function (b) { return b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''); })
            .join('\n');

// An admin editing a display nobody else holds, with the setting at its default
// five. UNDO_LIMIT is the number the admin Settings page writes; the PHP that
// computes it is stripped with every other `<?= ?>` above, so it is put back here
// the way a real page would carry it.
js = js.replace(/^var READ_ONLY\s*=.*$/m,  'var READ_ONLY = false;')
       .replace(/^var IS_ADMIN\s*=.*$/m,   'var IS_ADMIN = true;')
       .replace(/^var CANVAS_W\s*=.*$/m,   'var CANVAS_W = 1920;')
       .replace(/^var CANVAS_H\s*=.*$/m,   'var CANVAS_H = 1080;')
       .replace(/^var UNDO_LIMIT\s*=.*$/m, 'var UNDO_LIMIT = 5;');

check(/var UNDO_LIMIT = 5;/.test(js), 'the page carries an undo depth set by an admin');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ---- A canvas with one of everything ----------------------------------------
//
// Every kind of element the round trip has to survive, and every flag that has
// ever been dropped by something that rebuilt a block: a section with a background
// and a fit, a child text block with a brand subtype and an alignment, a locked
// and hidden root image with a non-default fit, a text block linked to a library
// entry, a carousel carrying JSON, and a video pointing at an uploaded file.

const canvas = document.getElementById('builder-canvas');
canvas.style.width  = '1920px';
canvas.style.height = '1080px';

function resetCanvas() {
    canvas.children.slice().forEach(function (c) { c.remove(); });
}

function buildFixture() {
    resetCanvas();
    const sec = renderSection({
        type: 'section', temp_id: 'tmp-sec-1', id: 11,
        x_pos: 40, y_pos: 20, width: 600, height: 380,
        section_bg: 'uploads/wood.jpg|contain', locked: 0, z_index: 2, hidden: 0
    });
    renderBlock({
        type: 'text', block_subtype: 'price', x_pos: 24, y_pos: 30, width: 160, height: 60,
        manual_content: '3.99', asset_id: null, locked: 0, z_index: 1, hidden: 0,
        font_family: 'Arial', font_size: 16, font_color: '#000000',
        font_weight: 'normal', font_style: 'normal', line_height: 1.4, text_align: 'right'
    }, sec);
    const img = renderBlock({
        // A database id, because a root block is where losing one costs something:
        // a basic account may *return* root content and may not place any, so a
        // restore that dropped the id would turn "this block again" into "a new
        // block here" and the publish would be refused (§4aj).
        type: 'image', block_subtype: 'free', id: 42,
        x_pos: 700, y_pos: 40, width: 220, height: 160,
        manual_content: 'uploads/fish.png|contain', asset_id: null, locked: 1, z_index: 3, hidden: 1,
        font_family: 'Arial', font_size: 16, font_color: '#000000',
        font_weight: 'normal', font_style: 'normal', line_height: 1.4
    }, canvas);
    // What an upload in this session leaves behind, and the reason publish will
    // pool the file rather than assume the library already has it. renderBlock does
    // not set it for an image — only a restore that carries it does.
    img.dataset.manualPath = 'uploads/fish.png';
    renderBlock({
        type: 'text', block_subtype: 'free', x_pos: 700, y_pos: 250, width: 300, height: 50,
        asset_id: 7, db_content: 'HALIBUT', locked: 0, z_index: 1, hidden: 0,
        font_family: 'Georgia', font_size: 24, font_color: '#c0392b',
        font_weight: 'bold', font_style: 'normal', line_height: 1.2
    }, canvas);
    renderBlock({
        type: 'carousel', block_subtype: 'free', x_pos: 1100, y_pos: 40, width: 480, height: 320,
        manual_content: JSON.stringify({ interval: 7000, slides: [{ image: 'a.png', title: 'Crab' }] }),
        asset_id: null, locked: 0, z_index: 1, hidden: 0,
        font_family: 'Arial', font_size: 16, font_color: '#000000',
        font_weight: 'normal', font_style: 'normal', line_height: 1.4
    }, canvas);
    const vid = renderBlock({
        type: 'video', block_subtype: 'free', x_pos: 1100, y_pos: 400, width: 400, height: 225,
        manual_content: 'uploads/reel.mp4', asset_id: null, locked: 0, z_index: 1, hidden: 0,
        font_family: 'Arial', font_size: 16, font_color: '#000000',
        font_weight: 'normal', font_style: 'normal', line_height: 1.4
    }, canvas);
    resetUndoHistory();
    return { sec: sec, vid: vid };
}

/**
 * A fixture block, or a sentence saying which one has gone missing.
 *
 * A restore that puts a block somewhere this suite does not look — a child block
 * left loose on the canvas, say — is a real failure, and without this it arrives
 * as a TypeError from inside the DOM three frames down, which names nothing.
 */
function must(node, what) {
    if (!node) {
        throw new Error('the fixture has no ' + what + ' any more — something moved it or dropped it');
    }
    return node;
}
function byType(type) {
    return canvas.querySelectorAll('.editable-block')
        .filter(function (n) { return n.dataset.type === type; })[0];
}

/** The section's child price block. */
function priceBlock() { return must(canvas.querySelectorAll('.child-block')[0], 'child price block'); }
/** The locked, hidden image at the root. */
function imageBlock() { return must(byType('image'), 'image block'); }
/** The root text block linked to a library entry. */
function textAssetBlock() { return must(canvas.querySelectorAll('.root-block')
    .filter(function (n) { return n.dataset.type === 'text'; })[0], 'library-linked text block'); }
/** A second root-level block, so a multi-selection has two that share a parent. */
function carouselBlock() { return must(byType('carousel'), 'carousel block'); }

buildFixture();

// ============================================================
section('The fixture is a canvas, not an empty page');
// ============================================================
// Everything below compares one snapshot against another, and two snapshots of
// nothing are equal. So first: there is something here, and it measures.

checkSame(6, canvas.querySelectorAll('.editable-block').length, 'six elements on the canvas');
checkSame(1, canvas.querySelectorAll('.child-block').length,    'one of them inside the section');
checkSame(600, canvas.querySelectorAll(':scope > .section-block')[0].offsetWidth,
          'and a section that is as wide as it was rendered');
checkSame('3.99', priceBlock().querySelector('.text-inner').innerText,
          'the price block is showing its price');

// ============================================================
section('A snapshot describes what is really there');
// ============================================================

const first = JSON.parse(snapshotCanvas());
checkSame(6, first.length, 'a snapshot holds every element');
checkSame('section', first[0].type, 'in document order, the section first');
checkSame('11', first[0].db_id, 'carrying the database id it was loaded with');
checkSame('uploads/wood.jpg|contain', first[0].section_bg, 'and its background with the fit still on it');
checkSame('tmp-sec-1', first[1].parent_temp_id, 'the child block names the section it is in');
checkSame('3.99', first[1].snap_content, 'and what it is showing');

const linked = first.filter(function (e) { return e.asset_id === '7'; })[0];
check(!!linked, 'a block linked to a library entry is in the snapshot');
checkSame('', linked.manual_content, 'publish would send no content of its own for it');
checkSame('HALIBUT', linked.snap_content,
          'but the snapshot keeps what it is showing — restoring it from the payload alone is a blank block');

const hiddenImg = first.filter(function (e) { return e.type === 'image'; })[0];
checkSame(1, hiddenImg.hidden,  'a hidden block is recorded as hidden');
checkSame(1, hiddenImg.locked,  'a locked one as locked');
checkSame(3, hiddenImg.z_index, 'and its layer is kept');
checkSame('uploads/fish.png|contain', hiddenImg.manual_content, 'with its image fit riding on the path');

// The snapshot's own two fields must never reach the server. They double the size
// of a publish carrying text, and they are meaningless over there.
const payload = serializeCanvas();
check(payload.every(function (e) { return !('snap_content' in e) && !('snap_manual_path' in e); }),
      'and what publish sends carries neither of the snapshot-only fields');
checkSame(6, payload.length, 'while still describing the whole canvas');
checkSame('section', payload[0].type, 'sections first, as publish has always sent them');

// ============================================================
section('Snapshot, change it, put it back — and nothing is missing');
// ============================================================
// The check the whole feature rests on. Compared as whole strings: a restore that
// drops a field this suite never thought to name still fails here.

const before = snapshotCanvas();

// Move something, resize something, retype something, unhide something, unlock
// something, relayer something, unlink something — one of each kind of change.
moveBlock(priceBlock(), 300, 200);
priceBlock().style.width = '400px';
priceBlock().querySelector('.text-inner').innerText = '11.49';
imageBlock().dataset.hidden = '0';
imageBlock().dataset.locked = '0';
imageBlock().dataset.zIndex = '9';
textAssetBlock().dataset.assetId = '';
textAssetBlock().querySelector('.text-inner').innerText = 'SALMON';

const changed = snapshotCanvas();
check(changed !== before, 'the canvas really did change');

check(restoreCanvas(before), 'the earlier canvas can be restored');
checkSame(before, snapshotCanvas(), 'and a snapshot of it is identical to the one it came from');

// The same again, read off the page rather than off a string, because a snapshot
// comparing equal to itself would also pass if snapshotCanvas() had quietly
// started returning a constant.
checkSame(6,      canvas.querySelectorAll('.editable-block').length, 'six elements are back');
checkSame('3.99', priceBlock().querySelector('.text-inner').innerText, 'the price is the old price');
checkSame(160,    priceBlock().offsetWidth, 'at the width it had');
checkSame('1',    imageBlock().dataset.hidden, 'the hidden block is hidden again');
check(imageBlock().querySelector(':scope > .hidden-badge') !== null, 'and says so on the canvas');
checkSame('1',    imageBlock().dataset.locked, 'the locked one is locked again');
// The one field a restore has no obvious reason to carry and every reason to: the
// database id. renderBlock reads it as `id` and a snapshot spells it `db_id`, so
// putting a block back is exactly where the two names have to be reconciled — and
// they were not. Without it every restored block publishes as a new one, which for a
// basic account is a refusal naming a placement they never made (§4aj), and for an
// admin is a row number changing under whoever else is reading it.
checkSame('42', imageBlock().dataset.dbId, 'and it still knows which row it came out of');
checkSame('42', JSON.parse(snapshotCanvas())
                  .filter(function (e) { return e.type === 'image'; })[0].db_id,
          'so the next publish returns that row rather than asking for a new one');
check(imageBlock().querySelector('.lock-icon') !== null, 'and has its padlock back');
checkSame('7',    textAssetBlock().dataset.assetId, 'the library link is back');
checkSame('HALIBUT', textAssetBlock().querySelector('.text-inner').innerText, 'showing the entry it points at');
checkSame('tmp-sec-1', priceBlock().closest('.section-block').dataset.tempId,
          'and the child block is inside its own section, not loose on the canvas');
checkSame('uploads/reel.mp4', byType('video').dataset.manualPath,
          'an uploaded video still points at its file');
// An image is the one where this can be lost silently: renderBlock reconstructs an
// image from its path and fit and nothing else, so a restore that does not carry
// the upload path back gives a block that looks identical and publishes as one
// whose file the library was never told about.
checkSame('uploads/fish.png', imageBlock().dataset.manualPath,
          'and an image uploaded in this session still knows where its file is');
checkSame(true, JSON.parse(snapshotCanvas())
    .filter(function (e) { return e.type === 'image'; })[0].save_to_db_pool,
    'so publishing it would still pool the upload');

// ============================================================
section('A step is one finished change');
// ============================================================

buildFixture();
checkSame(0, undoStack.length, 'a freshly loaded canvas holds no steps');
checkSame(true, document.getElementById('undo-btn').disabled, 'and the button says so');

// A drag is dozens of pointer events. One step.
selectBlock(priceBlock());
for (let i = 0; i < 20; i++) {
    handleMove({ target: priceBlock(), dx: 3, dy: 1 });
}
checkSame(0, undoStack.length, 'a drag in progress has not spent a step yet');
endDragStep();
checkSame(1, undoStack.length, 'and the whole drag is one step when the pointer is let go');
checkSame(false, document.getElementById('undo-btn').disabled, 'the button is live');
check(/1 held/.test(document.getElementById('undo-btn').title), 'and says how much it is holding');

// A resize is the same shape.
handleResize({ target: priceBlock(), rect: { width: 300, height: 90 }, deltaRect: { left: 0, top: 0 } });
handleResize({ target: priceBlock(), rect: { width: 320, height: 95 }, deltaRect: { left: 0, top: 0 } });
checkSame(1, undoStack.length, 'a resize in progress has not spent one either');
endResizeStep();
checkSame(2, undoStack.length, 'and the finished resize is the second step');

// Typing is not a step until they click away — the rule the whole text path is
// built around, and the reason commitUndoStep is on blur and not on input.
const inner = priceBlock().querySelector('.text-inner');
'11.49'.split('').forEach(function (ch, i) {
    inner.innerText = '11.49'.slice(0, i + 1);
    fire(inner, 'input', {});
});
checkSame(2, undoStack.length, 'five keystrokes in a text block are not five steps');
fire(inner, 'blur', {});
checkSame(3, undoStack.length, 'clicking away from it is one');
checkSame('11.49', JSON.parse(snapshotCanvas())[1].snap_content, 'holding the whole edit, not the first character');

// ============================================================
section('Every control that changes the canvas leaves a step');
// ============================================================
// Driven one at a time from a fresh canvas, so a control that only works because
// the one before it did is caught. Each of these was a place a change could have
// gone unrecorded, and an Undo that skips a change lands on a canvas nobody was
// ever looking at.

function stepsFor(label, act) {
    buildFixture();
    act();
    check(undoStack.length === 1, label + ' is a step' +
          (undoStack.length === 1 ? '' : ' — recorded ' + undoStack.length));
}

stepsFor('adding a section',        function () { createSection(); });
stepsFor('adding a block',          function () { setTargetSection(canvas.querySelectorAll('.section-block')[0]);
                                                  createBlock('text', 'price'); });
stepsFor('deleting a block',        function () { selectBlock(priceBlock()); deleteSelected(); });
stepsFor('the Delete key',          function () { selectBlock(priceBlock());
                                                  handleBuilderKeydown({ key: 'Delete' }); });
stepsFor('hiding a block',          function () { selectBlock(priceBlock()); toggleHidden(true); });
stepsFor('locking a block',         function () { selectBlock(priceBlock()); toggleLock(true); });
stepsFor('bringing one forward',    function () { selectBlock(priceBlock()); bringForward(); });
stepsFor('typing a new width',      function () { selectBlock(priceBlock()); applyDim('w', 250); });
stepsFor('typing a new position',   function () { selectBlock(priceBlock()); applyPos('x', 90); });
stepsFor('aligning to the parent',  function () { selectBlock(priceBlock()); alignToParent('right'); });
stepsFor('the align bar',           function () { selectBlock(priceBlock()); alignBlocks('bottom'); });
stepsFor('changing text alignment', function () { selectBlock(priceBlock()); applyTextAlign('center'); });
stepsFor('changing an image fit',   function () { selectBlock(imageBlock()); changeImageFit('cover'); });
stepsFor('changing a section fit',  function () { selectBlock(canvas.querySelectorAll('.section-block')[0]);
                                                  changeSectionBgFit('tile'); });
stepsFor('clearing a section background', function () { selectBlock(canvas.querySelectorAll('.section-block')[0]);
                                                        clearSectionBg(); });
stepsFor('unlinking a library entry', function () { selectBlock(textAssetBlock()); linkAsset(''); });
stepsFor('a finished text edit',    function () { const b = priceBlock();
                                                  b.querySelector('.text-inner').innerText = '9.00';
                                                  fire(b.querySelector('.text-inner'), 'blur', {}); });

// ============================================================
section('A control that changed nothing does not spend a step');
// ============================================================
// Undo has one job and one way to lose people's trust: pressing it and watching
// nothing happen. That is what an empty step is.

buildFixture();
selectBlock(priceBlock());
alignToParent('left');
const afterFirst = undoStack.length;
alignToParent('left');
checkSame(afterFirst, undoStack.length, 'aligning an already-aligned block a second time is not a step');
checkSame(false, commitUndoStep(), 'and a commit with nothing to record says so');

selectBlock(imageBlock());
toggleHidden(true);          // it is already hidden
checkSame(afterFirst, undoStack.length, 'hiding a block that was already hidden is not a step either');

fire(priceBlock().querySelector('.text-inner'), 'blur', {});
checkSame(afterFirst, undoStack.length, 'nor is clicking into a text block and out again without typing');

// ============================================================
section('Undo walks back, one step at a time');
// ============================================================

buildFixture();
const start = snapshotCanvas();

selectBlock(priceBlock());
applyPos('x', 100);
const afterOne = snapshotCanvas();
applyPos('x', 200);
const afterTwo = snapshotCanvas();
applyPos('x', 300);

checkSame(3, undoStack.length, 'three moves, three steps');
undoStep();
checkSame(afterTwo, snapshotCanvas(), 'the first Undo gives back the canvas before the last move');
undoStep();
checkSame(afterOne, snapshotCanvas(), 'the second goes back one further');
undoStep();
checkSame(start, snapshotCanvas(), 'and the third reaches where it started');
checkSame(0, undoStack.length, 'with nothing left held');
checkSame(true, document.getElementById('undo-btn').disabled, 'and the button back out of service');

// The one that would be a disaster: Undo on an empty stack must not clear the sign.
undoStep();
checkSame(start, snapshotCanvas(), 'Undo with nothing to undo leaves the canvas exactly as it was');
checkSame(6, canvas.querySelectorAll('.editable-block').length, 'and does not empty it');
check(/nothing left to undo/i.test(document.getElementById('toast').textContent),
      'saying so rather than doing something');

// A restore is not itself a change somebody made.
buildFixture();
selectBlock(priceBlock());
applyPos('x', 400);
checkSame(1, undoStack.length, 'one step held');
undoStep();
checkSame(0, undoStack.length, 'undoing it spends the step rather than adding another');

// ============================================================
section('The depth is the admin\'s number, and 0 means off');
// ============================================================

UNDO_LIMIT = 3;
buildFixture();
selectBlock(priceBlock());
// Six positions that all fit inside the section — applyPos clamps to the parent,
// and two values that clamp to the same place would be five changes, not six.
const walked = [snapshotCanvas()];
[40, 80, 120, 160, 200, 240].forEach(function (x) {
    applyPos('x', x);
    walked.push(snapshotCanvas());
});
checkSame(240, JSON.parse(snapshotCanvas())[1].x_pos, 'six distinct moves, none of them clamped away');
checkSame(3, undoStack.length, 'a depth of 3 holds three steps out of six changes');
undoStep(); undoStep(); undoStep();
checkSame(walked[3], snapshotCanvas(), 'and walking all three back reaches the third change, not the first');
checkSame(0, undoStack.length, 'the oldest steps were dropped, not silently kept');

UNDO_LIMIT = 0;
buildFixture();
selectBlock(priceBlock());
const off = snapshotCanvas();
applyPos('x', 700);
checkSame(0, undoStack.length, 'at 0 no step is recorded at all');
checkSame(false, commitUndoStep(), 'and a commit is refused rather than kept for later');
const movedWithUndoOff = snapshotCanvas();
undoStep();
checkSame(movedWithUndoOff, snapshotCanvas(), 'Undo does nothing, and in particular does not undo');
check(off !== movedWithUndoOff, 'the move itself still happened — Undo being off is not editing being off');
UNDO_LIMIT = 5;

// ============================================================
section('Ctrl+Z belongs to whoever is typing');
// ============================================================
// Inside a text block the browser's own undo is the right one — a character at a
// time — and taking it away would make correcting a typo undo the whole price.

buildFixture();
selectBlock(priceBlock());
applyPos('x', 250);
const beforeKey = snapshotCanvas();

let prevented = 0;
function keyZ(overrides) {
    const e = { key: 'z', ctrlKey: true, shiftKey: false, preventDefault() { prevented++; } };
    Object.keys(overrides || {}).forEach(function (k) { e[k] = overrides[k]; });
    handleBuilderKeydown(e);
    return e;
}

document.activeElement = priceBlock().querySelector('.text-inner');
keyZ();
checkSame(beforeKey, snapshotCanvas(), 'Ctrl+Z with the caret in a text block does not touch the canvas');
checkSame(0, prevented, 'and does not take the shortcut off the browser');

document.activeElement = document.getElementById('insp-x');
document.activeElement.tagName = 'INPUT';
keyZ();
checkSame(beforeKey, snapshotCanvas(), 'nor does it in an inspector field');

document.activeElement = null;
keyZ();
check(snapshotCanvas() !== beforeKey, 'with the caret nowhere, Ctrl+Z undoes the last step');
checkSame(1, prevented, 'and this time the browser is told the key was handled');

// Shift+Ctrl+Z is redo in most editors, and there is no redo here. It must not
// quietly undo instead — an undo somebody asked to be a redo is the one press
// they will not check the canvas after.
buildFixture();
selectBlock(priceBlock());
applyPos('x', 260);
const beforeShift = snapshotCanvas();
keyZ({ shiftKey: true });
checkSame(beforeShift, snapshotCanvas(), 'Shift+Ctrl+Z is not a second undo');

// And Delete still reaches the canvas from outside a field, and not from inside.
buildFixture();
selectBlock(priceBlock());
document.activeElement = priceBlock().querySelector('.text-inner');
handleBuilderKeydown({ key: 'Delete' });
checkSame(6, canvas.querySelectorAll('.editable-block').length,
          'Delete while typing deletes a character, not the block');
document.activeElement = null;
handleBuilderKeydown({ key: 'Delete' });
checkSame(5, canvas.querySelectorAll('.editable-block').length, 'and outside a field it deletes the block');

// ============================================================
section('Nothing is left pointing at a canvas that has gone');
// ============================================================
// restoreCanvas replaces every node. A selection still holding one of the old ones
// is the shape of defect this file has had before: a lookup for something the page
// no longer has, thrown on the next click rather than here.

buildFixture();
selectBlock(priceBlock());
setTargetSection(canvas.querySelectorAll('.section-block')[0]);
applyPos('x', 320);
undoStep();
checkSame(null, activeBlock,    'the selection is dropped');
checkSame(null, targetSection,  'and the section that was targeted for new blocks');
checkSame('none', document.getElementById('inspector').style.display, 'the inspector is put away');

// A multi-selection is the other way nodes are held, and it is a separate run
// because the two are mutually exclusive: toggleMultiSel() gives up the single
// selection to make one, and selectBlock() gives up the group to make the other.
buildFixture();
selectBlock(imageBlock());
toggleMultiSel(carouselBlock());
checkSame(2, multiSel.length, 'two root blocks can be selected together');
alignBlocks('left');
checkSame(1, undoStack.length, 'aligning them is one step');
undoStep();
checkSame(0, multiSel.length, 'and the group is let go of with the nodes that were in it');

// The blur that happens *inside* a restore must not be recorded as a step of its
// own. Without the guard, one press of Undo takes back the typing and the move,
// pops the step it just created instead of the one it restored, and leaves the
// stack claiming a step that no longer describes anything.
buildFixture();
selectBlock(priceBlock());
applyPos('x', 330);
priceBlock().querySelector('.text-inner').innerText = 'typed, never clicked away from';
undoStep();
checkSame(0, undoStack.length, 'a restore does not record itself as a step on the way past');

// A snapshot naming a section that is not in it describes a block with nowhere to
// go. It is dropped rather than appended to the canvas as a loose block — a price
// that escapes its section is a price in the wrong place on a real sign.
const orphaned = JSON.stringify(JSON.parse(snapshotCanvas())
    .filter(function (e) { return e.type !== 'section'; }));
check(restoreCanvas(orphaned), 'a snapshot with its section missing still restores');
checkSame(0, canvas.querySelectorAll('.child-block').length, 'and the orphaned child is not brought back');

check(restoreCanvas('not json at all') === false, 'a snapshot that cannot be read is refused');

// ============================================================
section('What Undo does not claim to cover');
// ============================================================
// The canvas background is left alone on purpose (ADR-0010): an uploaded one lives
// in a file input no snapshot can put back, and restoring the colour but not the
// picture would be worse than saying plainly it is out of scope. Asserted so the
// day somebody adds it, they add it to the docs too.

buildFixture();
canvas.style.backgroundColor = '#123456';
const bgSnapshot = snapshotCanvas();
canvas.style.backgroundColor = '#654321';
checkSame(bgSnapshot, snapshotCanvas(), 'the background is not part of a snapshot');

check(/does not cover[\s\S]{0,200}background/i.test(php),
      'and builder.php says so where somebody adding a capture point would read it');

// ============================================================
// Result
// ============================================================
// The expected total, for the same reason the other three suites carry one:
// without it, deleting half this file still reports a clean run.
// ============================================================
section('Undo takes a clipped-block warning back along with the size');
// ============================================================
// A section is overflow:hidden here and in viewer.php, so shrinking one past a block
// inside it hides that block on the sign while the row lives on — the hazard ADR-0004
// closed for the canvas and left open one container down. The Builder now says so.
//
// The badge is drawn, never stored, so a restored canvas has none until something
// asks. If restoreCanvas() does not ask, an Undo that puts the size back leaves the
// warning standing on a section that no longer hides anything, and the next real one
// is read as the same stale badge nobody trusts.

buildFixture();
const clipSec   = must(canvas.querySelectorAll(':scope > .section-block')[0], 'section to clip in');
const clipChild = priceBlock();     // 160 wide at x 24, well inside a 600-wide section
checkSame(null, clipSec.querySelector(':scope > .clip-badge'),
          'the fixture section starts out hiding nothing');
checkSame(184, parseFloat(clipChild.getAttribute('data-x')) + clipChild.offsetWidth,
          'and its price block ends at 184, so 150 is past it and 600 is not');

const roomy = snapshotCanvas();
handleResize({ target: clipSec, rect: { width: 150, height: 380 }, deltaRect: { left: 0, top: 0 } });
check(clipSec.querySelector(':scope > .clip-badge') !== null,
      'dragging the section in past the price block raises the badge');
const tight = snapshotCanvas();

// Undoing *out* of it. This one would pass on its own: restoreCanvas() builds fresh
// nodes and a fresh node has no badge, so it says nothing about whether anything
// asked. It is here for the width, and the next block is the one that bites.
check(restoreCanvas(roomy), 'the roomier canvas restores');
let clipRestored = must(canvas.querySelectorAll(':scope > .section-block')[0], 'restored section');
checkSame(600, clipRestored.offsetWidth, 'the section is its old width again');
checkSame(null, clipRestored.querySelector(':scope > .clip-badge'), 'with no badge on it');

// Undoing *into* it, which is the half a rebuild cannot get right by accident. Every
// node here is new and none of them carries a badge from before, so the warning is
// either recomputed after the restore or it is silently gone — and a section that has
// stopped mentioning the block it is hiding is worse than one that never did.
check(restoreCanvas(tight), 'and the clipped canvas restores too');
clipRestored = must(canvas.querySelectorAll(':scope > .section-block')[0], 'restored narrow section');
checkSame(150, clipRestored.offsetWidth, 'at the narrow width it was snapshotted at');
check(clipRestored.querySelector(':scope > .clip-badge') !== null,
      'and it says again that it is hiding a block, having been rebuilt from nothing');

const expected = 119;
if (checks !== expected) {
    fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
}

console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
fails.forEach(function (f) { console.log('  FAILED: ' + f); });
process.exit(fails.length ? 1 : 0);
