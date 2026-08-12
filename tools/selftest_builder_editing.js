// ============================================================
// SELF-TEST — the Builder as somebody editing actually meets it
// ============================================================
//   node tools/selftest_builder_editing.js
//
// The third harness over builder.php's inline JavaScript, and the one for
// decision #42: six small rough edges an admin hits while laying out a sign.
// Where the other two are about what a page *refuses* — selftest_builder_readonly
// runs a page that may not edit, selftest_builder_uploads runs a request that goes
// wrong — this one runs the page on an ordinary good day and asks whether the
// controls mean what they say.
//
// The five that are code, and what each got wrong:
//
//   Fit          A canvas far bigger than the window could not be fitted, because
//                applyZoom floored every zoom at 10% including the one Fit asked
//                for. The button did nothing and said nothing.
//   Resize       interact.js's restrictSize measures in *screen* pixels, so the
//                smallest a section could be dragged to moved with the zoom: 200
//                canvas px at 50%, 50 at 200%. Everything else in handleResize
//                divides by ZOOM; that one modifier did not.
//   Hidden       A hidden block got a HIDDEN badge; a hidden section got a 45%
//                fade and nothing else, which reads as a rendering quirk. Neither
//                could be brought back from the Builder at all.
//   Slide field  "Delete" on a slide's Title emptied the box, and the "+ Restore"
//                next to it handed back an empty one. In an app with no undo
//                anywhere, a control offering to put something back must have it.
//   Marquee      Ticking Transparent overwrote the chosen colour with the word
//                'transparent'. Reopening the block showed the factory red, and
//                unticking published the factory red.
//
// The sixth is dead code — the remains of a WYSIWYG format bar that ADR-0002
// settled against — and is checked by reading the file rather than running it.
//
// Two seams are worth naming. The slide rows are built by assigning a string to
// `innerHTML`, which this DOM does not parse; so the row markup is checked as the
// string addSlideRow really emits, and delete/restore/save are driven over nodes
// built here to match it. And the zoom checks set CANVAS_W/CANVAS_H directly,
// which is what the PHP does on a real page.
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

/**
 * A DOM node has a parent that has it back, so JSON.stringify throws on one —
 * and a failing check that cannot print its own failure takes the suite down
 * with a stack trace instead of naming what broke. Several of these compare a
 * node against null.
 */
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

/** Floating point: a zoom is compared to what the arithmetic really gives. */
function checkClose(expected, actual, label) {
    const ok = typeof actual === 'number' && Math.abs(expected - actual) < 1e-9;
    check(ok, label + (ok ? '' : ' — expected ' + expected + ', got ' + actual));
}

function section(title) { console.log('\n' + title); }

// ---- A DOM small enough to read and real enough to run ----------------------
//
// classList, children and querySelector are the three the code under test needs
// to actually work: applyHiddenLook adds a class and appends a badge and then
// looks for that badge again, and blockMin decides a section from its class. A
// no-op classList would make every one of those checks pass by accident.

function classesOf(node) {
    return String(node.className || '').split(/\s+/).filter(Boolean);
}

/** The subset of selector syntax builder.php uses on a single node. */
function matchesSel(node, sel) {
    sel = sel.trim();
    let m = sel.match(/^([a-zA-Z]+)?\[type="([^"]+)"\]$/);
    if (m) { return (!m[1] || node.tagName === m[1].toUpperCase()) && node.type === m[2]; }
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

function el(tag, className) {
    const node = {
        tagName: String(tag || 'div').toUpperCase(),
        id: '', className: className || '', type: '',
        // fontFamily and friends start as strings: showInspector calls .replace()
        // on one, which is a throw rather than a failed check if it is undefined.
        style: { fontFamily: '', fontSize: '', color: '', fontWeight: '',
                 fontStyle: '', lineHeight: '', textAlign: '', display: '' },
        dataset: {}, children: [], files: [],
        value: '', textContent: '', innerHTML: '', checked: false, disabled: false,
        offsetWidth: 0, offsetHeight: 0, clientWidth: 0, clientHeight: 0,
        scrollLeft: 0, scrollTop: 0, parentNode: null, parentElement: null,
        _attrs: {}
    };
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
    node.addEventListener = function () {};
    node.focus = function () {}; node.blur = function () {}; node.load = function () {};
    return node;
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
        return [];
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
global.interact     = () => ({ draggable() { return this; }, resizable() { return this; },
                               on() { return this; }, unset() {} });
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

// An admin on a Display nobody else holds — the only page on which any of these
// six controls exists at all.
js = js.replace(/^var READ_ONLY\s*=.*$/m, 'var READ_ONLY = false;')
       .replace(/^var IS_ADMIN\s*=.*$/m,  'var IS_ADMIN = true;')
       .replace(/^var CANVAS_W\s*=.*$/m,  'var CANVAS_W = 1920;')
       .replace(/^var CANVAS_H\s*=.*$/m,  'var CANVAS_H = 1080;');

check(/var CANVAS_W = 1920;/.test(js), 'the page carries a canvas size set by the Display');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ============================================================
section('The Fit button fits');
// ============================================================

// A 1000×700 editor frame. ZOOM_PAD is the frame's own 40px padding, both sides.
const frame = document.getElementById('editor-frame');
frame.clientWidth  = 1000;
frame.clientHeight = 700;

function withCanvas(w, h) { CANVAS_W = w; CANVAS_H = h; }

withCanvas(1920, 1080);
zoomToFit();
checkClose((1000 - ZOOM_PAD) / 1920, ZOOM, 'an ordinary canvas fits to the tighter of the two axes');
check(CANVAS_W * ZOOM <= frame.clientWidth,  'and the whole width really is inside the frame');
check(CANVAS_H * ZOOM <= frame.clientHeight, 'as is the whole height');

withCanvas(400, 300);
zoomToFit();
checkSame(1, ZOOM, 'a canvas smaller than the frame is not magnified past 100%');

// The defect: 920/20000 is 0.046, and applyZoom used to floor every zoom at 0.1.
withCanvas(20000, 12000);
zoomToFit();
check(ZOOM < ZOOM_MIN, 'a canvas that needs less than the 10% floor pushes past it');
checkClose((1000 - ZOOM_PAD) / 20000, ZOOM, 'reaching exactly the zoom that fits');
check(CANVAS_W * ZOOM <= frame.clientWidth, 'so the whole of a very large canvas is on screen');
checkSame('scale(0.046)', document.getElementById('builder-canvas').style.transform,
          'and the canvas is scaled to it');
checkSame('920px', document.getElementById('canvas-sizer').style.width,
          'with a scaled footprint the frame can scroll over');

// The floor gives way to the fit and to nothing else.
for (let i = 0; i < 40; i++) { nudgeZoom(-1); }
checkClose((1000 - ZOOM_PAD) / 20000, ZOOM, 'zooming out by hand stops where Fit stops, not below it');

withCanvas(1920, 1080);
for (let i = 0; i < 40; i++) { nudgeZoom(-1); }
checkSame(ZOOM_MIN, ZOOM, 'on a canvas Fit can already handle, the ordinary 10% floor still holds');
for (let i = 0; i < 40; i++) { nudgeZoom(1); }
checkSame(ZOOM_MAX, ZOOM, 'and the ceiling is unchanged');

// A frame measured before the browser has laid the page out. scale(0) is an
// invisible canvas and a negative scale is a mirrored one; neither says why.
frame.clientWidth  = 0;
frame.clientHeight = 0;
zoomToFit();
check(ZOOM > 0, 'an unlaid-out frame does not produce a zero or negative zoom');
checkSame(ZOOM_MIN, ZOOM, 'it falls back to the ordinary floor');
frame.clientWidth  = 1000;
frame.clientHeight = 700;

// ============================================================
section('How small a block may get is a canvas measurement, not a screen one');
// ============================================================

function resizable(cls) {
    const t = el('div', 'editable-block ' + cls);
    t.dataset.locked = '0';
    t.setAttribute('data-x', 200);
    t.setAttribute('data-y', 100);
    return t;
}

/** What interact.js hands handleResize: screen pixels, both fields. */
function drag(t, screenW, screenH, dLeft, dTop) {
    return { target: t, rect: { width: screenW, height: screenH },
             deltaRect: { left: dLeft || 0, top: dTop || 0 } };
}

applyZoom(1);
let sec = resizable('section-block');
handleResize(drag(sec, 80, 40));
checkSame('100px', sec.style.width,  'at 100% a section stops at its 100px minimum width');
checkSame('60px',  sec.style.height, 'and its 60px minimum height');
checkSame('100 × 60 px', document.getElementById('resize-label').textContent,
          'and the readout says the size it actually is');
checkSame(100, document.getElementById('insp-w').value, 'the inspector agrees about width');
checkSame(60,  document.getElementById('insp-h').value, 'and about height');

// 60 screen px at 50% zoom is 120 canvas px — above the minimum, so allowed. A
// minimum compared before the divide would have clamped this to 100.
applyZoom(0.5);
sec = resizable('section-block');
handleResize(drag(sec, 60, 40));
checkSame('120px', sec.style.width,  'zoomed out, a section may be dragged to 120 canvas px wide');
checkSame('80px',  sec.style.height, 'and 80 canvas px tall');

// 150 screen px at 200% is 75 canvas px — below the minimum, so refused. The old
// screen-pixel modifier allowed this one.
applyZoom(2);
sec = resizable('section-block');
handleResize(drag(sec, 150, 100));
checkSame('100px', sec.style.width,  'zoomed in, the same 100 canvas px floor applies');
checkSame('60px',  sec.style.height, 'on both axes');

applyZoom(1);
const child = resizable('child-block');
handleResize(drag(child, 10, 5));
checkSame('40px', child.style.width,  'a block that is not a section has its own smaller floor');
checkSame('24px', child.style.height, 'on both axes');

// An edge that has stopped shrinking must stop moving, or the pointer walks the
// block across the canvas while its width sits at the minimum.
sec = resizable('section-block');
handleResize(drag(sec, 80, 40, 25, 15));
checkSame('200', sec.getAttribute('data-x'), 'a clamped left edge does not drag the block sideways');
checkSame('100', sec.getAttribute('data-y'), 'nor a clamped top edge downwards');

sec = resizable('section-block');
handleResize(drag(sec, 300, 200, -20, -10));
checkSame('180', sec.getAttribute('data-x'), 'an edge still free to move still moves it');
checkSame('90',  sec.getAttribute('data-y'), 'on both axes');

// A locked block is not resized at all — the rule that was already there.
const locked = resizable('section-block');
locked.dataset.locked = '1';
handleResize(drag(locked, 300, 200));
checkSame(undefined, locked.style.width, 'a locked block ignores a resize entirely');

// Typing a size in must agree with dragging to one, or the two controls disagree
// about how small a section may be.
const parent = el('div');
parent.offsetWidth = 1920; parent.offsetHeight = 1080;
sec = resizable('section-block');
parent.appendChild(sec);
activeBlock = sec;
applyDim('w', 10);
checkSame('100px', sec.style.width, 'typing 10 into W lands on the same floor a drag stops at');
applyDim('h', 5);
checkSame('60px', sec.style.height, 'and so does typing 5 into H');

const typed = resizable('child-block');
parent.appendChild(typed);
activeBlock = typed;
applyDim('w', 1);
checkSame('40px', typed.style.width, 'a non-section keeps its own floor when typed at too');
activeBlock = null;

// ============================================================
section('A section smaller than its blocks says so');
// ============================================================

// ADR-0004 froze the canvas so nothing could be orphaned outside it, and then said
// the Builder needed no out-of-bounds warning. A section is not the canvas: it
// resizes by dragging a handle, it is overflow:hidden here and in viewer.php, and a
// child past its edge still publishes and can no longer be clicked. See the
// correction in that ADR.

function clipBadge(node) { return node.querySelector(':scope > .clip-badge'); }

/** A section with one child block, both measured in canvas pixels. */
function sectionWithChild(secW, secH, cx, cy, cw, ch) {
    const s = el('div', 'editable-block section-block');
    s.offsetWidth = secW; s.offsetHeight = secH;
    s.appendChild(childAt(cx, cy, cw, ch));
    return s;
}

function childAt(cx, cy, cw, ch) {
    const c = el('div', 'editable-block child-block');
    c.offsetWidth = cw; c.offsetHeight = ch;
    c.setAttribute('data-x', cx);
    c.setAttribute('data-y', cy);
    return c;
}

let host = sectionWithChild(600, 400, 10, 10, 100, 50);
checkSame(0, clippedChildCount(host), 'a block inside its section is not clipped');
applyClipWarning(host);
checkSame(null, clipBadge(host), 'so the section carries no badge');

// The bound is the border box, which is the slack a flush drag needs: restrictRect
// holds a child inside the border box while data-x is measured from the padding box.
// Get this wrong and every layout in the shop reports two false positives on open.
host = sectionWithChild(600, 400, 500, 350, 100, 50);
checkSame(0, clippedChildCount(host), 'nor is one pushed flush into the far corner');

host = sectionWithChild(600, 400, 501, 10, 100, 50);
checkSame(1, clippedChildCount(host), 'one pixel further out and it is clipped');
applyClipWarning(host);
check(clipBadge(host) !== null, 'and the section says so');
checkSame('⚠ 1 CLIPPED — NOT ON THE SIGN', clipBadge(host) && clipBadge(host).textContent,
          'with the count first, so a narrow section truncates the sentence and not the number');

// The badge is itself a child of the section. Counting it would add one every time
// the warning was refreshed, which is every pointer event of a resize.
applyClipWarning(host);
checkSame(1, host.querySelectorAll(':scope > .clip-badge').length,
          'refreshing does not stack badges');
checkSame('⚠ 1 CLIPPED — NOT ON THE SIGN', clipBadge(host) && clipBadge(host).textContent,
          'nor count the badge it added last time');

// A count of blocks, not of edges.
host = sectionWithChild(600, 400, 700, 700, 100, 50);
checkSame(1, clippedChildCount(host), 'a block past both edges counts once, not twice');
host.appendChild(childAt(10, 900, 100, 50));
checkSame(2, clippedChildCount(host), 'and a second clipped block makes it two');
applyClipWarning(host);
checkSame('⚠ 2 CLIPPED — NOT ON THE SIGN', clipBadge(host) && clipBadge(host).textContent,
          'which the badge pluralises');

// A badge that outlives its cause teaches people to ignore the next one.
host.offsetWidth = 2000; host.offsetHeight = 2000;
applyClipWarning(host);
checkSame(null, clipBadge(host), 'growing the section back takes the badge away again');

// Only the section's own children. A root block answers to the canvas, which
// ADR-0004 froze precisely so it could never be orphaned.
host = sectionWithChild(600, 400, 10, 10, 100, 50);
const strayRoot = el('div', 'editable-block root-block');
strayRoot.offsetWidth = 100; strayRoot.offsetHeight = 50;
strayRoot.setAttribute('data-x', 5000); strayRoot.setAttribute('data-y', 5000);
host.appendChild(strayRoot);
checkSame(0, clippedChildCount(host), 'a root block is not a child block and is not counted');

// Reached from either end: a child block can grow past the edge too, because
// handleResize applies BLOCK_MIN after restrictRect has had its say.
host = sectionWithChild(600, 400, 501, 10, 100, 50);
refreshClipWarningFor(host.children[0]);
check(clipBadge(host) !== null, 'a change to a child block refreshes the section it is in');
host = sectionWithChild(600, 400, 501, 10, 100, 50);
refreshClipWarningFor(host);
check(clipBadge(host) !== null, 'and a change to the section refreshes itself');

let threwClip = false;
try {
    refreshClipWarningFor(null);
    refreshClipWarningFor(el('div', 'editable-block root-block'));
} catch (e) { threwClip = true; }
checkSame(false, threwClip, 'and a root block, or nothing at all, is not an error');

// The wiring, not the function. offsetWidth reads back off style here so the page's
// own resize path feeds the measurement — without this the drag proves nothing.
const dragged = resizable('section-block');
Object.defineProperty(dragged, 'offsetWidth',  { get() { return parseFloat(dragged.style.width)  || 600; } });
Object.defineProperty(dragged, 'offsetHeight', { get() { return parseFloat(dragged.style.height) || 400; } });
dragged.appendChild(childAt(450, 10, 100, 50));
checkSame(null, clipBadge(dragged), 'a section wide enough for its block starts clean');
handleResize(drag(dragged, 500, 400));
check(clipBadge(dragged) !== null,
      'dragging its edge in past the block raises the badge, with nothing else called');
handleResize(drag(dragged, 600, 400));
checkSame(null, clipBadge(dragged), 'and dragging the edge back out takes it away');

// A block only *partly* over the edge is still clickable — it is the fully hidden
// one that drops out of hit testing — so typing it back inside is a real way out of
// this, and the only hook that ends a clip without changing a section's size.
host = sectionWithChild(600, 400, 550, 10, 100, 50);   // 50px of it past the right edge
applyClipWarning(host);
check(clipBadge(host) !== null, 'a block hanging half over the edge is clipped too');
activeBlock = host.children[0];
applyPos('x', 100);
checkSame(null, clipBadge(host), 'and typing it back inside takes the badge off');
activeBlock = null;

// Typing a width in has to raise the same badge dragging to one does. The two
// controls already agree about how small a section may get; if only one of them
// mentions what that costs, the quiet one is the way people will meet this.
const typedSec = resizable('section-block');
Object.defineProperty(typedSec, 'offsetWidth',  { get() { return parseFloat(typedSec.style.width)  || 600; } });
Object.defineProperty(typedSec, 'offsetHeight', { get() { return parseFloat(typedSec.style.height) || 400; } });
parent.appendChild(typedSec);
typedSec.appendChild(childAt(450, 10, 100, 50));
activeBlock = typedSec;
checkSame(null, clipBadge(typedSec), 'a section wide enough for its block has no badge to start');
applyDim('w', 150);
check(clipBadge(typedSec) !== null, 'typing 150 into W raises the badge, as dragging to it does');
applyDim('w', 600);
checkSame(null, clipBadge(typedSec), 'and typing the width back out takes it away again');
activeBlock = null;

// Deleting the clipped block is one of the two ways out of this, and the only one
// that leaves no other trace on the canvas — so the badge has to notice a removal it
// cannot see from the removed node, which no longer has a parent to ask.
host = sectionWithChild(600, 400, 501, 10, 100, 50);
applyClipWarning(host);
check(clipBadge(host) !== null, 'a section hiding a block says so before the delete');
activeBlock = host.children[0];
activeBlock.dataset.type = 'text';
deleteSelected();
checkSame(null, clipBadge(host), 'and stops saying it once that block is deleted');
activeBlock = null;

// The third hook, held in the source rather than run. No suite drives loadLayout()
// over a real DOM tree: the one that does drive it stubs a page flat enough to answer
// "did anything throw" and discards appended children, and the two suites with a real
// tree never call it. Opening a layout that was already clipped when it was published
// is the likeliest way anybody meets this badge at all, so the call is held by a check
// that cannot execute it rather than by nothing.
check(/setupInteract\(\);[\s\S]{0,800}refreshClipWarnings\(\);[\s\S]{0,800}resetUndoHistory\(\);/.test(js),
      'and the load path refreshes every section before the undo baseline is taken');

// ============================================================
section('No two blocks share a layer, which is what makes the layer buttons work');
// ============================================================

// createBlock and createSection both write z_index 1, and until one of the four layer
// buttons is pressed nothing moves it — so the ordinary canvas is not a stack, it is a
// heap of blocks all on layer 1 whose paint order comes from the tie, which the browser
// breaks by document order. The old arithmetic worked on the number and so could not
// see that: Back set 1 on a block already on 1, Backward floored at 1 immediately, and
// both still updated the readout. That is the reported symptom exactly — the number
// moves up and down and the element never leaves the front. Front was the one direction
// that worked, because max+1 is the only step a tie cannot absorb.
//
// The fix renumbers the group instead of nudging one number, so these checks are about
// the group: every block has a layer, no two share one, and the four buttons each move
// the selection one place, all the way, or nowhere.

/** n blocks in one parent, all on layer 1 — the state a new canvas is always in. */
function layerGroup(n) {
    const parent = el('div');
    for (let i = 0; i < n; i++) {
        const b = el('div', 'editable-block root-block');
        b.dataset.zIndex = 1;
        b.dataset.name   = String.fromCharCode(97 + i);   // a, b, c… so a failure is readable
        parent.appendChild(b);
    }
    return parent;
}

/** Every block's name and layer, in document order: "a2 b3 c4 d1". */
function layers(parent) {
    return parent.children
        .filter(function (n) { return n.classList.contains('editable-block'); })
        .map(function (n) { return n.dataset.name + n.dataset.zIndex; })
        .join(' ');
}

const stack = layerGroup(4);
checkSame('a1 b1 c1 d1', layers(stack), 'four blocks start life on the same layer');

// The headline: Back, once, from the state every canvas is actually in.
activeBlock = stack.children[3];
sendToBack();
checkSame('a2 b3 c4 d1', layers(stack), 'sending the top one back moves it, on the first press');
checkSame('1', String(document.getElementById('insp-zindex-val').textContent),
          'and the readout says the layer it is now on');
check(stack.children.every(function (n) { return String(n.style.zIndex) === String(n.dataset.zIndex); }),
      'with the painted layer and the published one agreeing on every block');

bringToFront();
checkSame('a1 b2 c3 d4', layers(stack), 'and Front brings it back to the top');

sendBackward();
checkSame('a1 b2 c4 d3', layers(stack), 'Backward swaps it with the one below, and only that one');
bringForward();
checkSame('a1 b2 c3 d4', layers(stack), 'Forward undoes that exactly');

// A button that has run out of room does nothing rather than drifting the numbers.
bringToFront();
checkSame('a1 b2 c3 d4', layers(stack), 'Front on the topmost block changes nothing');
bringForward();
checkSame('a1 b2 c3 d4', layers(stack), 'nor does Forward');
activeBlock = stack.children[0];
sendToBack();
checkSame('a1 b2 c3 d4', layers(stack), 'Back on the bottom one changes nothing');
sendBackward();
checkSame('a1 b2 c3 d4', layers(stack), 'and Backward cannot push it below 1');

// The property the whole fix rests on, asserted rather than inferred from the strings.
activeBlock = stack.children[2];
sendToBack();
const seen = stack.children.map(function (n) { return String(n.dataset.zIndex); });
checkSame(4, new Set(seen).size, 'after any of them, no two blocks share a layer');
check(seen.every(function (z) { return parseInt(z, 10) >= 1; }), 'and none is below 1');

// A section is its own stacking context, so a child moving inside it must not renumber
// anything on the canvas. Getting this wrong would reorder the whole sign to raise one
// price tag inside one section.
const outerStack = layerGroup(2);
const innerSec   = el('div', 'editable-block section-block');
innerSec.dataset.zIndex = 1;
innerSec.dataset.name   = 'sec';
outerStack.appendChild(innerSec);
const kidP = el('div', 'editable-block child-block');
const kidQ = el('div', 'editable-block child-block');
kidP.dataset.zIndex = 1; kidP.dataset.name = 'p';
kidQ.dataset.zIndex = 1; kidQ.dataset.name = 'q';
innerSec.appendChild(kidP);
innerSec.appendChild(kidQ);
activeBlock = kidQ;
sendToBack();
checkSame('p2 q1', layers(innerSec), 'a child sent back is renumbered against its section');
checkSame('a1 b1 sec1', layers(outerStack), 'and nothing outside that section is touched');

// The guard every other lookup in this file has. All four buttons are reachable by
// keyboard shortcut, and the canvas can be deselected between the keypress and the act.
activeBlock = null;
let threwLayer = false;
try { bringToFront(); sendToBack(); bringForward(); sendBackward(); } catch (e) { threwLayer = true; }
check(!threwLayer, 'with nothing selected, all four layer buttons do nothing rather than throw');

// A block that is not an .editable-block is not in any group — the selection can be
// one during a rebuild, and indexOf returning -1 has to mean "leave it alone".
activeBlock = el('div', 'root-block');
el('div').appendChild(activeBlock);
let threwStray = false;
try { sendToBack(); } catch (e) { threwStray = true; }
check(!threwStray, 'and a selection outside any group is left alone rather than renumbered');
activeBlock = null;

// ============================================================
section('A hidden section says it is hidden, and can be brought back');
// ============================================================

function badgeOf(node) { return node.querySelector(':scope > .hidden-badge'); }

const hiddenSec = el('div', 'editable-block section-block');
hiddenSec.dataset.hidden = '1';
applyHiddenLook(hiddenSec);
check(hiddenSec.classList.contains('hidden-block'), 'a hidden section is faded');
check(badgeOf(hiddenSec) !== null, 'and carries a badge — the fade alone reads as a rendering quirk');
checkSame('HIDDEN', badgeOf(hiddenSec) && badgeOf(hiddenSec).textContent, 'saying what it is');

applyHiddenLook(hiddenSec);
checkSame(1, hiddenSec.querySelectorAll(':scope > .hidden-badge').length,
          'applying the look twice does not stack badges');

activeBlock = hiddenSec;
toggleHidden(false);
checkSame('0', hiddenSec.dataset.hidden, 'unticking the box marks it visible');
checkSame(false, hiddenSec.classList.contains('hidden-block'), 'the fade comes off');
checkSame(null, badgeOf(hiddenSec), 'and so does the badge');

toggleHidden(true);
checkSame('1', hiddenSec.dataset.hidden, 'and it can be hidden again from the same box');
check(badgeOf(hiddenSec) !== null, 'badge and all');
activeBlock = null;

// A throw inside an onchange handler is an uncaught error nobody sees: the box
// moves, nothing happens, and there is no message either way.
let threw = false;
try { toggleHidden(false); } catch (e) { threw = true; }
check(!threw, 'with nothing selected the box does nothing rather than throwing');
checkSame('1', hiddenSec.dataset.hidden, 'and changes nothing');

// The inspector has to show the state the block is really in, or the box lies
// about which way it will move things.
const visBlock = el('div', 'editable-block root-block');
visBlock.dataset.type   = 'image';
visBlock.dataset.hidden = '0';
document.getElementById('hidden-toggle').checked = true;
showInspector(visBlock);
checkSame(false, document.getElementById('hidden-toggle').checked,
          'opening a visible block shows the box unticked');
checkSame('block', document.getElementById('insp-visibility').style.display,
          'and an admin is offered the control at all');

// Basic accounts do not get it. The Work Area's Show/Hide is admins-only, and two
// doors onto one column should not disagree about who may open them.
IS_ADMIN = false;
showInspector(visBlock);
checkSame('none', document.getElementById('insp-visibility').style.display,
          'a basic account is not offered it');
IS_ADMIN = true;

visBlock.dataset.hidden = '1';
showInspector(visBlock);
checkSame(true, document.getElementById('hidden-toggle').checked,
          'opening a hidden one shows it ticked');

// ============================================================
section('Restore puts the words back');
// ============================================================

// The row markup, as addSlideRow really emits it. The nodes below are built to
// match these three claims; if the markup moves, these fail first.
const rowHost = byId('carousel-slides-list');
rowHost.children.length = 0;
addSlideRow({ title: 'Coho fillet', price: '$18.99', description: 'Wild caught' });
const emitted = rowHost.children[0].innerHTML;
check(emitted.indexOf('class="slide-field"') >= 0, 'a slide row groups each field in a .slide-field');
check(/<input type="text" class="slide-title"/.test(emitted), 'with the title as a text input');
check(/<textarea class="slide-desc"/.test(emitted), 'and the description as a textarea');
check(emitted.indexOf('deleteSlideField(this,\'title\')') >= 0, 'and a Delete beside the title');

/** One .slide-field holding an input and the button that acts on it. */
function slideField(cls, tag, value) {
    const field = el('div', 'slide-field');
    const input = el(tag, cls);
    if (tag === 'input') { input.type = 'text'; }
    input.value = value;
    input.dataset.deleted = '0';
    const btn = el('button', 'btn danger');
    field.appendChild(input);
    field.appendChild(btn);
    return { field: field, input: input, btn: btn };
}

let f = slideField('slide-title', 'input', 'Coho fillet');
deleteSlideField(f.btn, 'title');
checkSame('', f.input.value, 'deleting a field empties the box');
checkSame(true, f.input.disabled, 'and disables it');
checkSame('1', f.input.dataset.deleted, 'and marks it deleted, which is what the sign reads');
checkSame('+ Restore', f.btn.innerHTML, 'the button now offers to put it back');

restoreSlideField(f.btn, 'title');
checkSame('Coho fillet', f.input.value, 'and restoring really does put it back');
checkSame(false, f.input.disabled, 'with the box usable again');
checkSame('0', f.input.dataset.deleted, 'and no longer marked deleted');

// Delete, restore, delete again: the second delete re-stashes rather than
// stashing the empty string the first one left behind.
deleteSlideField(f.btn, 'title');
restoreSlideField(f.btn, 'title');
checkSame('Coho fillet', f.input.value, 'a second round trip keeps the same words');

// A field that arrived deleted has nothing behind it — stored as null — and an
// empty box is the truth there rather than a loss.
const arrived = slideField('slide-price', 'input', '');
arrived.input.dataset.deleted = '1';
arrived.input.disabled = true;
restoreSlideField(arrived.btn, 'price');
checkSame('', arrived.input.value, 'a field that was already deleted restores empty, not undefined');

// A textarea takes the same path as an input.
const desc = slideField('slide-desc', 'textarea', 'Wild caught, never frozen');
deleteSlideField(desc.btn, 'desc');
restoreSlideField(desc.btn, 'desc');
checkSame('Wild caught, never frozen', desc.input.value, 'a description comes back too');

// End to end: the restored words are what a publish would carry.
const carouselBlock = el('div', 'editable-block root-block');
carouselBlock.dataset.type = 'carousel';
carouselBlock.dataset.carouselData = '{}';
activeBlock = carouselBlock;

rowHost.children.length = 0;
const row = el('div', 'slide-row');
const t2  = slideField('slide-title', 'input', 'Coho fillet');
const p2  = slideField('slide-price', 'input', '$18.99');
row.appendChild(t2.field);
row.appendChild(p2.field);
rowHost.appendChild(row);
deleteSlideField(t2.btn, 'title');
restoreSlideField(t2.btn, 'title');
deleteSlideField(p2.btn, 'price');
document.getElementById('carousel-interval').value = 5;
saveCarouselSlides();
const saved = JSON.parse(carouselBlock.dataset.carouselData).slides[0];
checkSame('Coho fillet', saved.title, 'the restored title is what gets saved');
checkSame(null, saved.price, 'and a field left deleted is still saved as deleted');
activeBlock = null;

// ============================================================
section('Transparent is a state, not a colour the block forgets');
// ============================================================

const marquee = el('div', 'editable-block root-block');
marquee.dataset.type = 'marquee';
marquee.dataset.marqueeData = JSON.stringify({ text: 'FRESH TODAY', bg: '#2e86de' });
activeBlock = marquee;

document.getElementById('marquee-color').value  = '#ffffff';
document.getElementById('marquee-size').value   = 28;
document.getElementById('marquee-weight').value = 'bold';
document.getElementById('marquee-bg').value     = '#2e86de';
document.getElementById('marquee-bg-transparent').checked = true;
updateMarqueeStyle();

let md = JSON.parse(marquee.dataset.marqueeData);
checkSame('transparent', md.bg, 'ticking Transparent is what the sign reads');
checkSame('#2e86de', md.bgColor, 'and the chosen colour is kept beside it');
checkSame('transparent', marquee.style.background, 'the preview goes transparent');
checkSame(true, document.getElementById('marquee-bg').disabled, 'and the picker is disabled');

// Reopening the block: the picker is refilled from the stored data, which is
// where the colour used to be lost.
document.getElementById('marquee-bg').value = '#000000';
showInspector(marquee);
checkSame('#2e86de', document.getElementById('marquee-bg').value,
          'reopening a transparent marquee shows the colour it would go back to');
checkSame(true, document.getElementById('marquee-bg-transparent').checked, 'still ticked');
checkSame(true, document.getElementById('marquee-bg').disabled, 'and still disabled');

document.getElementById('marquee-bg-transparent').checked = false;
updateMarqueeStyle();
md = JSON.parse(marquee.dataset.marqueeData);
checkSame('#2e86de', md.bg, 'unticking gives back the chosen colour, not the factory red');
checkSame('#2e86de', marquee.style.background, 'and the preview with it');
checkSame(false, document.getElementById('marquee-bg').disabled, 'the picker is usable again');

// A marquee stored before this change has no bgColor. It must still open.
const older = el('div', 'editable-block root-block');
older.dataset.type = 'marquee';
older.dataset.marqueeData = JSON.stringify({ text: 'OLD', bg: '#8e44ad' });
showInspector(older);
checkSame('#8e44ad', document.getElementById('marquee-bg').value,
          'a marquee saved before this change opens on its own colour');

const olderTrans = el('div', 'editable-block root-block');
olderTrans.dataset.type = 'marquee';
olderTrans.dataset.marqueeData = JSON.stringify({ text: 'OLD', bg: 'transparent' });
showInspector(olderTrans);
checkSame('#c0392b', document.getElementById('marquee-bg').value,
          'and one saved transparent before it falls back to the default, having nothing else');
activeBlock = null;

// ============================================================
section('The WYSIWYG bar is gone, not merely unreachable');
// ============================================================

// ADR-0002 settled that a text block is plain text. What was left of the format
// bar was a `selectionchange` listener firing on every caret move in the
// document, to fill a variable read by a function nothing called.
[
    ['fmtCmd',          'the format command handler'],
    ['savedRange',      'the selection it preserved'],
    ['trackSelection',  'the listener that filled it'],
    ['selectionchange', 'and the event it listened to'],
    ['FONT_FAMILIES',   'the unused font list'],
    ['wysiwyg-bar',     'the bar\'s own styling'],
    ['fmt-btn',         'and its buttons\''],
    ['execCommand',     'and the one call that edited markup']
].forEach(function (pair) {
    check(php.indexOf(pair[0]) < 0, pair[1] + ' is out of the file');
});

// The read moved out of publishCanvas() into blockContent() when Undo needed the
// same answer (ADR-0010). What it takes out of a text block has not moved: still
// innerText, still one place, now with two callers instead of one.
check(/function blockContent\(block\)[\s\S]{0,400}?inner\.innerText/.test(php),
      'what publish reads out of a text block is still its plain text');

// ============================================================
// Result
// ============================================================
// The expected total, for the same reason the other two suites carry one:
// without it, deleting half this file still reports a clean run.
const expected = 130;
if (checks !== expected) {
    fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
}

console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
fails.forEach(function (f) { console.log('  FAILED: ' + f); });
process.exit(fails.length ? 1 : 0);
