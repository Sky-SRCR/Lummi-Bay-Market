// ============================================================
// SELF-TEST — a colour the Builder cannot read is not quietly made black
// ============================================================
//   node tools/selftest_builder_colors.js
//
// The defect this exists to keep out (#41), in the order it happened:
//
//   1. A `canvas_elements.font_color` holds something that is not `#rrggbb` — a
//      row edited by hand, or one written before the publish path checked colour
//      semantics at all (§4ab left them to this item on purpose).
//   2. The Builder loads the layout and assigns it: `block.style.color = value`.
//   3. **The CSSOM discards a value it cannot parse, and says nothing.** The
//      property is not set to the bad value and it is not set to a default; it is
//      left exactly as it was, which for a fresh block is the empty string.
//   4. The publish payload read that property back through `rgbToHex()`, which
//      answered `'#000000'` for anything falsy.
//   5. Publishing — changing nothing, touching nothing — wrote black over it. On a
//      canvas whose default background is #1a1a2e the block did not look
//      recoloured, it looked deleted. There is no undo anywhere in this app.
//
// Every step of that is invisible to `php -l` and to `node --check`: the file
// parses perfectly, and the sequence only exists when the code *runs*. It is also
// invisible to a DOM stub that stores whatever it is given — which is why the
// `style` object below is a Proxy that discards and normalises exactly as a
// browser does. A stub that kept `'puce'` would make this whole suite pass against
// the original bug.
//
// A third harness rather than a third premise bolted onto an existing one:
// selftest_builder_readonly.js is a page that may not edit and
// selftest_builder_uploads.js is one sending a file. This one is an admin opening
// a Display whose stored data is already wrong. All three eval the real inline
// JavaScript out of builder.php, so none of them can drift from what ships.
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

function checkSame(expected, actual, label) {
    const same = expected === actual;
    check(same, label + (same ? '' : ' — expected ' + JSON.stringify(expected)
                                 + ', got ' + JSON.stringify(actual)));
}

function checkMentions(haystack, needle, label) {
    const found = String(haystack).indexOf(needle) >= 0;
    check(found, label + (found ? '' : ' — "' + haystack + '" does not mention "' + needle + '"'));
}

function section(title) { console.log('\n' + title); }

// ---- A `style` that behaves like the browser's ------------------------------
// Two behaviours matter and both are load-bearing here:
//
//   · An unparseable colour is **discarded silently**. No throw, no warning, and
//     the property keeps whatever it had. This is the whole mechanism of #41.
//   · A parseable one is **normalised** on the way in. `#ff0000` does not read
//     back as `#ff0000`, it reads back as `rgb(255, 0, 0)`. That is why readHex()
//     has to understand the rgb() form at all — the round trip never returns the
//     notation it was given.

/** What the CSSOM would store for this colour, or '' if it would refuse it. */
function cssomColor(value) {
    if (typeof value !== 'string') { return ''; }
    const v = value.trim().toLowerCase();
    const named = { red: [255,0,0], black: [0,0,0], white: [255,255,255], transparent: null };
    if (Object.prototype.hasOwnProperty.call(named, v)) {
        return named[v] ? 'rgb(' + named[v].join(', ') + ')' : 'rgba(0, 0, 0, 0)';
    }
    let m = v.match(/^#([0-9a-f]{6})$/);
    if (m) {
        const n = parseInt(m[1], 16);
        return 'rgb(' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].join(', ') + ')';
    }
    m = v.match(/^#([0-9a-f]{3})$/);
    if (m) {
        const p = m[1].split('').map(c => parseInt(c + c, 16));
        return 'rgb(' + p.join(', ') + ')';
    }
    m = v.match(/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*([\d.]+)\s*)?\)$/);
    if (m) {
        const p = [m[1], m[2], m[3]].map(Number);
        if (p.some(n => n > 255)) { return ''; }
        return m[4] !== undefined && Number(m[4]) < 1
            ? 'rgba(' + p.join(', ') + ', ' + Number(m[4]) + ')'
            : 'rgb(' + p.join(', ') + ')';
    }
    return '';
}

function cssomStyle() {
    return new Proxy({}, {
        set(target, key, value) {
            if (key === 'color' || key === 'backgroundColor') {
                const normalised = cssomColor(value);
                if (normalised === '') { return true; }   // discarded, in silence
                target[key] = normalised;
                return true;
            }
            target[key] = value;
            return true;
        }
    });
}

// ---- A DOM with the controls an admin gets ----------------------------------

function stubEl(id) {
    const el = {
        id, style: cssomStyle(), dataset: {}, children: [], files: [],
        value: '', textContent: '', innerHTML: '', innerText: '', checked: false,
        offsetWidth: 100, offsetHeight: 100, clientWidth: 800, clientHeight: 600,
        scrollLeft: 0, scrollTop: 0, parentElement: null, parentNode: null,
        classList: { add() {}, remove() {}, contains() { return false; } },
        appendChild() {}, removeChild() {}, insertBefore() {}, remove() {},
        addEventListener() {}, focus() {}, blur() {}, load() {},
        querySelectorAll() { return []; },
        closest() { return null; }, getAttribute() { return null; }, setAttribute() {},
        getBoundingClientRect() { return { left: 0, top: 0, width: 100, height: 100 }; }
    };
    const inner = {};
    el.querySelector = function (sel) { return inner[sel] || (inner[sel] = stubEl(sel)); };
    return el;
}

/** One text block on the canvas, as a publish would find it. */
function textBlock(tempId) {
    const b = stubEl(tempId);
    b.dataset.type    = 'text';
    b.dataset.subtype = 'free';
    b.dataset.tempId  = tempId;
    return b;
}

const nodes = {};
const canvasBlocks = [];
global.document = {
    getElementById(id) {
        if (nodes[id]) { return nodes[id]; }
        const el = stubEl(id);
        if (id === 'builder-canvas') {
            el.querySelectorAll = function (sel) {
                return sel.indexOf('section-block') >= 0 && sel.indexOf(':scope') === 0
                    ? [] : canvasBlocks;
            };
        }
        nodes[id] = el;
        return el;
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
global.window    = { getSelection() { return null; }, addEventListener() {}, innerWidth: 1280, innerHeight: 800 };
global.navigator = { sendBeacon() { return true; } };
global.interact  = () => ({ draggable() { return this; }, resizable() { return this; },
                            on() { return this; }, unset() {} });
global.confirm   = () => true;
global.alert     = () => {};
global.setTimeout = () => 0;
global.setInterval = () => 0;
global.clearTimeout = () => {};

/** Captures what publishCanvas() actually put on the wire. */
global.FormData = function () {
    this.fields = {};
    this.append = function (k, v) { this.fields[k] = v; };
    FormData.last = this;
};
let published = null;
global.fetch = function (url, opts) {
    published = opts && opts.body ? opts.body.fields : null;
    // Never resolves. The payload is what this suite is about, and letting the
    // success path run would only exercise the toast.
    return new Promise(function () {});
};

// ---- The page's own JavaScript ----------------------------------------------

const php = fs.readFileSync(BUILDER, 'utf8');
// An admin, holding the lock, on a real Display.
let js = buildPageJs(BUILDER, {
    READ_ONLY:        false,
    IS_ADMIN:         true,
    DISPLAY_TAG:      'deli',
    DISPLAY_ID:       4,
    UPLOAD_MAX_BYTES: 8388608,
});

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ─────────────────────────────────────────────────────────────
section('What the Builder will call a colour');

// This has to agree with lib/color.php, because they are the two ends of one round
// trip: what PHP will store is what JavaScript must be able to read back, and a
// disagreement in either direction is a value that survives one side and is thrown
// away by the other. The PHP half is checked in tools/selftest_layout.php.
checkSame('#1a2b3c', readHex('#1a2b3c'), 'a stored colour reads back as itself');
checkSame('#1a2b3c', readHex('#1A2B3C'), 'and capitals normalise down, as they do in PHP');
checkSame('', readHex(''),        'blank is not a colour');
checkSame('', readHex('puce'),    'nor is a word');
checkSame('', readHex('#f00'),    'nor the shorthand, because that is not what gets stored');
checkSame('', readHex('#12345g'), 'nor six characters that are not all hexadecimal');
checkSame('', readHex(null),      'nor nothing at all');
checkSame('', readHex(undefined), 'nor an absent field');
checkSame('', readHex(0),         'nor a number, which would have been falsy anyway');
checkSame('', readHex('rgb(300, 0, 0)'), 'nor a channel outside the byte range');

// The rgb() forms exist here and not in the PHP because only this side ever sees
// them: they are what the CSSOM hands back, never what the column holds.
checkSame('#ff0000', readHex('rgb(255, 0, 0)'), 'the form a browser returns is read');
checkSame('#ff0000', readHex('rgb(255,0,0)'),   'with or without the spaces');
checkSame('#0a0b0c', readHex('rgb(10, 11, 12)'), 'and single digits are padded, not truncated');
checkSame('#ff0000', readHex('rgba(255, 0, 0, 0.5)'), 'and alpha is dropped, which is what #rrggbb means');

// ─────────────────────────────────────────────────────────────
section('The stub is faithful, or nothing below proves anything');

// Asserted rather than assumed. If the CSSOM stub stored bad values, every check
// in the next section would pass against the original defect.
const probe = textBlock('probe');
probe.style.color = 'puce';
checkSame(undefined, probe.style.color, 'a colour the browser cannot parse is discarded, silently');
probe.style.color = '#ff0000';
checkSame('rgb(255, 0, 0)', probe.style.color, 'and a real one comes back normalised, not as it went in');
probe.style.color = 'not a colour either';
checkSame('rgb(255, 0, 0)', probe.style.color, 'a later bad value leaves the good one alone');

// ─────────────────────────────────────────────────────────────
section('A stored colour nobody can read is kept, not replaced');

const bad = textBlock('b1');
applyStoredColor(bad, 'puce');
checkSame('puce', bad.dataset.colorUnread, 'the value that was actually stored is remembered on the block');
checkSame('rgb(0, 0, 0)', bad.style.color, 'while the block still renders, in the default');

const good = textBlock('b2');
applyStoredColor(good, '#00ff00');
checkSame(undefined, good.dataset.colorUnread, 'a readable colour leaves no marker');
checkSame('rgb(0, 255, 0)', good.style.color, 'and is what the block renders in');

const blank = textBlock('b3');
applyStoredColor(blank, '');
checkSame(undefined, blank.dataset.colorUnread,
          'blank is not unreadable — a block with no colour of its own is ordinary');
applyStoredColor(blank, null);
checkSame(undefined, blank.dataset.colorUnread, 'and neither is an absent field');

// Loading a layout twice must not leave the first load's marker behind, or a block
// somebody has just fixed goes on refusing to publish.
const fixed = textBlock('b4');
applyStoredColor(fixed, 'puce');
checkSame('puce', fixed.dataset.colorUnread, 'a block starts out marked');
applyStoredColor(fixed, '#123456');
checkSame(undefined, fixed.dataset.colorUnread, 'and reloading it with a real colour clears the marker');

// The same path the whole layout comes in through.
const viaStyles = textBlock('b5');
applyTextStyles(viaStyles, { block_subtype: 'free', font_color: 'chartreuse-ish' });
checkSame('chartreuse-ish', viaStyles.dataset.colorUnread,
          'and the marker is set by the function that loads every block, not only by hand');

// ─────────────────────────────────────────────────────────────
section('What a publish actually sends');

// The end of the chain, and the only check here that would have caught #41 on its
// own: everything above could be right and this line still wrong.
canvasBlocks.length = 0;
const unreadable = textBlock('p1');
applyStoredColor(unreadable, 'puce');
canvasBlocks.push(unreadable);

published = null;
endPublish();   // release the in-flight guard (§4ak): no reply is delivered here
publishCanvas();
check(published !== null, 'a publish is sent');
let sent = JSON.parse(published.layout_data);
checkSame(1, sent.length, 'carrying the one block on the canvas');
checkSame('puce', sent[0].font_color,
          'and the colour it publishes is the one that was stored, not black');
check(sent[0].font_color !== '#000000',
      'which is the whole of #41: pressing Publish changed nothing about this block');

// Choosing a colour is what retires the old value, and it has to be a *choice* —
// nothing automatic may do it, or the silent overwrite is back by another route.
activeBlock = unreadable;
updateStyle('color', '#336699');
checkSame(undefined, unreadable.dataset.colorUnread, 'picking a colour clears the marker');
published = null;
endPublish();   // release the in-flight guard (§4ak): no reply is delivered here
publishCanvas();
sent = JSON.parse(published.layout_data);
checkSame('#336699', sent[0].font_color, 'and the publish then carries the chosen colour');

// The case that must not have changed. A block that simply never had a colour
// still publishes #000000, exactly as it always did — this item is about values
// that could not be read, not about ones that were never set.
canvasBlocks.length = 0;
const plain = textBlock('p2');
canvasBlocks.push(plain);
published = null;
endPublish();   // release the in-flight guard (§4ak): no reply is delivered here
publishCanvas();
sent = JSON.parse(published.layout_data);
checkSame('#000000', sent[0].font_color, 'a block that never had a colour publishes black, as before');

// A readable stored colour round-trips through the CSSOM's own notation and comes
// back as the hex it went in as. This is the check that fails if readHex() ever
// stops understanding rgb().
canvasBlocks.length = 0;
const kept = textBlock('p3');
applyStoredColor(kept, '#abcdef');
canvasBlocks.push(kept);
published = null;
endPublish();   // release the in-flight guard (§4ak): no reply is delivered here
publishCanvas();
sent = JSON.parse(published.layout_data);
checkSame('#abcdef', sent[0].font_color, 'and a real stored colour survives the round trip unchanged');

// ─────────────────────────────────────────────────────────────
section('An unreadable colour on a branded block is the Brand\'s, and is reported once');
// ─────────────────────────────────────────────────────────────
// #41 is about a colour the *block* owns. On a branded block the colour comes from
// `block_styles`, and publish stopped carrying the six fields the Brand owns at all
// (invariant 34) — so a brand colour nobody can read is now one row, audited where
// it can be fixed, instead of the same fault copied onto every price on every sign
// and reported as though there were eleven of them.
//
// The block still has to *render*, and it still has to remember what it could not
// read, because the inspector's note is drawn from that. Only the publish changed.

canvasBlocks.length = 0;
blockStyles = {
    price: { font_family: 'Georgia', font_size: 48, font_color: 'puce',
             font_weight: 'bold', font_style: 'normal', line_height: 1.1 }
};
const branded = textBlock('br1');
branded.dataset.subtype = 'price';
applyTextStyles(branded, { block_subtype: 'price' });
canvasBlocks.push(branded);

checkSame('rgb(0, 0, 0)', branded.style.color, 'the block still renders, in the default');
checkSame('puce', branded.dataset.colorUnread, 'and still remembers the value nobody could read');

published = null;
endPublish();   // release the in-flight guard (§4ak): no reply is delivered here
publishCanvas();
sent = JSON.parse(published.layout_data);
checkSame(1, sent.length, 'the branded block is published');
checkSame(undefined, sent[0].font_color,
          'and carries no colour at all — the Brand\'s bad value is not copied onto it');

blockStyles = {};

// ─────────────────────────────────────────────────────────────
section('And the inspector says so, rather than showing black and looking deliberate');

const noteEl = document.getElementById('font-color-unread');

showUnreadableColor(bad);
checkSame('block', noteEl.style.display, 'a marked block puts the note on screen');
checkMentions(noteEl.textContent, 'puce', 'quoting what is actually stored');
checkMentions(noteEl.textContent, 'publishing is refused',
              'and saying what will happen, because the refusal comes from the server');

showUnreadableColor(good);
checkSame('none', noteEl.style.display, 'an ordinary block takes it away again');
checkSame('', noteEl.textContent, 'and leaves nothing behind to be shown by the next block');

// The value is quoted into textContent, never innerHTML: it came out of the
// database and #15 is still open.
const hostile = textBlock('h1');
applyStoredColor(hostile, '<img src=x onerror=alert(1)>');
showUnreadableColor(hostile);
checkSame('', noteEl.innerHTML, 'a stored value that looks like markup is not put into innerHTML');
checkMentions(noteEl.textContent, '<img', 'it goes in as text, where it cannot be anything else');

// Long values are cut, so one bad row cannot push the inspector open.
const longOne = textBlock('h2');
applyStoredColor(longOne, 'x'.repeat(300));
showUnreadableColor(longOne);
check(noteEl.textContent.length < 250, 'and a very long one is shortened rather than printed whole');

// The inspector is not sent to a page that may not edit (§4j), so every lookup in
// here has to survive the control being absent. This is the defect class
// selftest_builder_readonly.js exists for, checked at its own door as well.
delete nodes['font-color-unread'];
document.getElementById = function () { return null; };
let threw = false;
try { showUnreadableColor(bad); } catch (e) { threw = true; }
checkSame(false, threw, 'and with no inspector on the page at all, it does nothing rather than throw');

// ─────────────────────────────────────────────────────────────
// Anchored, for the reason `selftest_layout.php` anchors its own: without a number
// here, deleting half this file still reports a clean run. Four of the eight node
// suites carried one and four did not (§4bh).
const expected = 47;
if (checks !== expected) {
    fails.push('the suite ran every check it is supposed to — expected '
               + expected + ', ran ' + checks);
}

console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
if (fails.length) {
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(1);
}
