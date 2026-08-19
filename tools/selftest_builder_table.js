// ============================================================
// SELF-TEST — a table filled from a file somebody else made
// ============================================================
//   node tools/selftest_builder_table.js
//
// The sixth harness over builder.php's inline JavaScript, and the only one whose
// input did not come from this app: a .csv written in Excel, on another machine,
// by whoever keeps the price list. Everything the other five run is something the
// Builder itself produced — a layout it published, an upload it sent, a colour it
// wrote — so the failure modes here are the ones a page meets when the data is
// somebody else's: a quoted comma, a semicolon where a comma should be, a header
// row naming columns this app has no style for, and a file too big for the column
// it has to fit in.
//
// Three seams are worth naming, because each is a defect this file exists to stop
// rather than a nicety:
//
//   Navigation   A file dropped on a page that ignores it is not a no-op — the
//                browser opens the file and the Builder is gone, taking every
//                block moved since the last Publish with it. The page refuses
//                the default for any file drag anywhere, and that is checked
//                here on a page that may not even edit.
//   Padding      `readTablePads()` is the same function in builder.php and
//                viewer.php (invariant 32), and what it is really about is the
//                tables already on signs: a stored `row_padding: 0` has always
//                drawn 8px, because the Viewer it was written for skipped a zero.
//                Reading it as zero now would silently reformat them. There is a
//                check per stored shape, and the zero one is the load-bearing one.
//   Styles       `headers` is not a row of labels — it is which of the seven
//                column styles each column is drawn in — so a CSV's header row
//                cannot be copied into it. What is not matched becomes Plain and
//                is *said*, because a header row half-understood in silence is
//                #21 in another form.
//
// The DOM below is the editing suite's, plus a FileReader and a drag event, and
// with document.addEventListener recording rather than discarding — the drop
// handlers are the subject here, and a listener that is never run proves nothing.
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
            : JSON.stringify(v);
    }
    return JSON.stringify(v);
}

function checkSame(expected, actual, label) {
    check(expected === actual, label + (expected === actual ? '' : ' — expected ' + describe(expected) + ', got ' + describe(actual)));
}

function checkDeep(expected, actual, label) {
    const same = JSON.stringify(expected) === JSON.stringify(actual);
    check(same, label + (same ? '' : ' — expected ' + describe(expected) + ', got ' + describe(actual)));
}

function section(title) { console.log('\n' + title); }

// Every listener setupCanvas() adds, by event name. A drop handler that is
// never run proves nothing, and this suite is mostly about drop handlers.
const dropListeners = { dragover: [], dragleave: [], drop: [] };
// Which files really reached a FileReader — a refusal that happens after the
// read is a different thing from one that happens instead of it.
const readerCalls = [];

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
        steps.forEach(function (step) {
            const next = [];
            scope.forEach(function (s) {
                // Descendants only, never the node the call was made on. A real
                // querySelectorAll cannot return its own root, and this used to: a
                // section asked which locked blocks were inside it got itself back
                // and answered "none", so the check that found this read as the app
                // being wrong when it was the harness.
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
    addEventListener(kind, fn) {
        if (!dropListeners[kind]) { dropListeners[kind] = []; }
        dropListeners[kind].push(fn);
    },
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
global.FileReader   = function () {
    const self = this;
    self.readAsText = function (file) {
        readerCalls.push(file && file.name);
        if (self.onload) { self.onload({ target: { result: file ? file._text : '' } }); }
    };
};
global.setTimeout   = () => 0;
global.setInterval  = () => 0;
global.clearTimeout = () => {};

// ---- The page's own JavaScript ----------------------------------------------

// ---- The page's own JavaScript ----------------------------------------------

const php = fs.readFileSync(BUILDER, 'utf8');

let js = php.replace(/<\?(php|=)[\s\S]*?\?>/g, '0')
            .match(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)
            .map(function (b) { return b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''); })
            .join('\n');

js = js.replace(/^var READ_ONLY\s*=.*$/m, 'var READ_ONLY = false;')
       .replace(/^var IS_ADMIN\s*=.*$/m,  'var IS_ADMIN = true;')
       .replace(/^var LOCK_HOLDER\s*=.*$/m, "var LOCK_HOLDER = 'Dana';");

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

// ============================================================
section('A CSV is read the way a spreadsheet writes one');
// ============================================================

checkDeep([['a', 'b'], ['1', '2']], parseCsvGrid('a,b\n1,2'), 'a plain file is rows of fields');
checkDeep([['Sockeye, wild', '18.99']], parseCsvGrid('"Sockeye, wild",18.99'),
          'a comma inside quotes stays inside the cell');
checkDeep([['She said "hi"']], parseCsvGrid('"She said ""hi"""'),
          'a doubled quote is one quote');
checkDeep([['a', 'b'], ['1', '2']], parseCsvGrid('a,b\r\n1,2\r\n'),
          "Windows' line endings are line endings");
checkDeep([['a'], ['b']], parseCsvGrid('a\rb'), 'and so is a bare carriage return');
checkDeep([['a', 'b']], parseCsvGrid('\uFEFFa,b'), "Excel's byte-order mark is not part of the first heading");
checkDeep([['a']], parseCsvGrid('a\n'), 'the newline every spreadsheet ends its file with is not a row');
checkDeep([['a'], [''], ['b']], parseCsvGrid('a\n\nb'),
          'but a blank line between two groups of prices is a row somebody meant');
checkDeep([['two\nlines', 'x']], parseCsvGrid('"two\nlines",x'),
          'a line break inside quotes does not end the row');

checkSame(';', sniffCsvDelimiter('name;price;note'), 'a file written where the decimal mark is a comma is semicolons');
checkSame('\t', sniffCsvDelimiter('name\tprice\tnote'), 'a table copied out of a browser is tabs');
checkSame(',', sniffCsvDelimiter('name,price'), 'and an ordinary one is commas');
checkSame(',', sniffCsvDelimiter('a,b;c'), 'a tie goes to the comma, which is what the extension said');
checkDeep([['name', 'price'], ['Coho', '12,50']], parseCsvGrid('name;price\nCoho;12,50'),
          'so a semicolon file keeps the comma in its prices');

// ============================================================
section('A header row names a column style, or it does not');
// ============================================================

checkSame('item_title', csvStyleFor('Title'),        'the visible label of a style names it');
checkSame('item_title', csvStyleFor('item_title'),   'so does the stored value');
checkSame('item_title', csvStyleFor('  ITEM  TITLE  '), 'case and spacing are not the question being asked');
checkSame('price_2',    csvStyleFor('Sale Price'),   'and the words somebody actually writes on a price list');
checkSame('',           csvStyleFor('SKU'),          'a heading this app has no style for matches nothing');
checkSame('',           csvStyleFor(''),             'and neither does an empty heading');
checkSame('',           csvStyleFor(null),           'or a missing one');

const mapped = csvGridToTable(parseCsvGrid('Title,Price,SKU\nCoho,18.99,44-2\nSockeye,24.99,44-3'), true, {});
checkDeep(['item_title', 'price', 'free'], mapped.data.headers, 'the columns it knows are styled by name');
checkDeep(['Title', 'Price'], mapped.matched, 'and it says which ones those were');
checkDeep(['SKU'], mapped.plain, 'and names the one it could not, rather than styling it quietly');
checkSame(2, mapped.rowCount, 'the header row is not one of the rows');
checkDeep(['Coho', '18.99', '44-2'], mapped.data.rows[0], 'and the rows under it are the content');

const unnamed = csvGridToTable(parseCsvGrid('Title,,Price\nCoho,x,18.99'), true, {});
checkDeep(['column 2'], unnamed.plain, 'a column with no heading at all is named by where it is');

const noHeader = csvGridToTable(parseCsvGrid('Coho,18.99\nSockeye,24.99'), false, {});
checkSame(2, noHeader.rowCount, 'with the header tick off, the first line is content like any other');
checkDeep(['free', 'free'], noHeader.data.headers, 'and nothing is styled by a name that was never a heading');
checkDeep([], noHeader.plain, 'so there is nothing to report about headings either');

// ============================================================
section('What the import refuses, and what it leaves alone');
// ============================================================

function refusalFor(grid, hasHeader) { return csvGridToTable(grid, hasHeader === undefined ? true : hasHeader, {}); }

const headerOnly = refusalFor(parseCsvGrid('Title,Price'));
check(/nothing to import/.test(headerOnly.refusal || ''), 'a file that is a header row and nothing else is refused');
checkSame(undefined, headerOnly.data, 'and hands back no table to draw');

const tooTall = ['Title'];
for (let i = 0; i < CSV_MAX_ROWS + 1; i++) { tooTall.push('row ' + i); }
const tall = refusalFor(parseCsvGrid(tooTall.join('\n')));
check(/501 rows/.test(tall.refusal || ''), 'too many rows is refused with the number that was in the file');
check(new RegExp('at most ' + CSV_MAX_ROWS).test(tall.refusal || ''), 'and the number it would have had to be');

const wideLine = [];
for (let i = 0; i < CSV_MAX_COLS + 1; i++) { wideLine.push('h' + i); }
const wide = refusalFor(parseCsvGrid(wideLine.join(',') + '\n' + wideLine.join(',')));
check(new RegExp('' + (CSV_MAX_COLS + 1) + ' columns').test(wide.refusal || ''),
      'too many columns is refused, with the number that was in the file');
checkSame(undefined, wide.data, 'and that one draws nothing either');

// 65535 bytes is what lib/layout_rules.php will accept at Publish. A file that
// makes a bigger table is refused here, over the file, rather than an hour later
// on a page about publishing.
const fat = ['Description'];
for (let i = 0; i < 400; i++) { fat.push(new Array(200).join('x')); }
const fatOut = refusalFor(parseCsvGrid(fat.join('\n')));
check(/bytes/.test(fatOut.refusal || ''), 'a file that would not fit the column it is stored in is refused');
check(new RegExp('' + TABLE_CONTENT_MAX).test(fatOut.refusal || ''), 'and says what the limit is');
checkSame(65535, TABLE_CONTENT_MAX, 'and that limit is the one lib/layout_rules.php enforces');

checkSame(1, utf8Bytes('a'),        'a byte is a byte');
checkSame(2, utf8Bytes('é'),   'an accented letter is two');
checkSame(3, utf8Bytes('€'),   'a currency sign is three');
checkSame(4, utf8Bytes('😀'), 'and an emoji is four rather than the two characters JavaScript counts');

// ============================================================
section('What the import keeps, because somebody set it by hand');
// ============================================================

const standing = { valigns: ['middle', 'bottom'], haligns: ['right', 'center'], widths: [30, 70],
                   pad_top: 4, pad_right: 6, pad_bottom: 4, pad_left: 6 };
const again = csvGridToTable(parseCsvGrid('Title,Price\nCoho,18.99'), true, standing);
checkDeep(['middle', 'bottom'], again.data.valigns, 'a re-import keeps the vertical alignment of each column');
checkDeep(['right', 'center'],  again.data.haligns, 'and the horizontal');
checkDeep([30, 70],             again.data.widths,  'and the widths somebody measured');
checkSame(4, again.data.pad_top,  'and the cell padding, which an import has no opinion about');
checkSame(6, again.data.pad_left, 'on every side of it');

const grown = csvGridToTable(parseCsvGrid('Title,Price,Notes\nCoho,18.99,fresh'), true, standing);
checkDeep(['middle', 'bottom', 'top'], grown.data.valigns, 'a column the old table did not have gets the default');
checkDeep([30, 70, 0],                 grown.data.widths,  'and no width, which means auto');

const ragged = csvGridToTable(parseCsvGrid('Title,Price\nCoho,18.99,extra\nSockeye'), true, {});
checkSame(3, ragged.colCount, 'a row wider than the header row widens the table rather than being cut');
checkDeep(['Sockeye', '', ''], ragged.data.rows[1], 'and a short row is filled out to match');
checkDeep(['column 3'], ragged.plain, 'the column nobody named is reported, not quietly styled');

// ============================================================
section('Cell padding: four sides, and the signs already out there');
// ============================================================

checkDeep({ top: 8, right: 10, bottom: 8, left: 10 }, readTablePads({}),
          'a table that has never been given a padding reads as what the Viewer draws');
checkDeep({ top: 8, right: 10, bottom: 8, left: 10 }, readTablePads(null),
          'and so does one with no stored data at all');
checkDeep({ top: 20, right: 10, bottom: 20, left: 10 }, readTablePads({ row_padding: 20 }),
          'the single number that came before meant top and bottom');
// The one that would have reformatted every sign in the shop: the old Viewer
// skipped a stored 0 and drew its 8px, so 0 has never meant "no padding".
checkDeep({ top: 8, right: 10, bottom: 8, left: 10 }, readTablePads({ row_padding: 0 }),
          'and a stored zero means the default it has always drawn, not none');
checkDeep({ top: 0, right: 0, bottom: 0, left: 0 },
          readTablePads({ pad_top: 0, pad_right: 0, pad_bottom: 0, pad_left: 0 }),
          'a zero somebody typed into the new boxes is a real zero');
checkDeep({ top: 1, right: 2, bottom: 3, left: 4 },
          readTablePads({ pad_top: 1, pad_right: 2, pad_bottom: 3, pad_left: 4 }),
          'four sides are four answers');
checkDeep({ top: 8, right: 10, bottom: 8, left: 40 },
          readTablePads({ pad_left: 40, row_padding: 30 }),
          'one side named is an answer about that side, and the old number is not re-read over it');
checkDeep({ top: 120, right: 0, bottom: 8, left: 10 },
          readTablePads({ pad_top: 500, pad_right: -5 }),
          'values outside the range are clamped rather than refused');
checkDeep({ top: 8, right: 10, bottom: 8, left: 10 }, readTablePads({ pad_top: 'wide' }),
          'and a value that is not a number is not one');

// The modal's own four boxes.
document.getElementById('table-pad-top').value    = '';
document.getElementById('table-pad-right').value  = '';
document.getElementById('table-pad-bottom').value = '';
document.getElementById('table-pad-left').value   = '';
showTablePads(readTablePads({ row_padding: 12 }));
checkSame(12, document.getElementById('table-pad-top').value,    'opening a table fills the boxes from what is stored');
checkSame(10, document.getElementById('table-pad-right').value,  'including the sides the old number never had');
checkSame(false, document.getElementById('table-pad-link').checked,
          'and the "same on all four sides" tick is off when they are not the same');

showTablePads({ top: 5, right: 5, bottom: 5, left: 5 });
checkSame(true, document.getElementById('table-pad-link').checked, 'and on when they are');

document.getElementById('table-pad-link').checked = true;
document.getElementById('table-pad-top').value = 9;
padFieldChanged(document.getElementById('table-pad-top'));
checkSame(9, document.getElementById('table-pad-left').value, 'with it ticked, one box fills the other three');

document.getElementById('table-pad-link').checked = false;
document.getElementById('table-pad-top').value = 3;
padFieldChanged(document.getElementById('table-pad-top'));
checkSame(9, document.getElementById('table-pad-left').value, 'and with it clear, each side is its own');

document.getElementById('table-pad-right').value = '200';
document.getElementById('table-pad-bottom').value = 'x';
checkDeep({ top: 3, right: 120, bottom: 0, left: 9 }, readPadFields(),
          'what the boxes hold is clamped on the way out, the way the Viewer will clamp it');

// ============================================================
section('Dropping a file, including where it must not land');
// ============================================================

// setupCsvDrop() is wired by setupCanvas() on every page. The listeners it adds
// are what the rest of this section runs; the recording document above is what
// makes them reachable.
setupCanvas();
check(dropListeners.dragover.length > 0, 'the page listens for a file being dragged over it');
check(dropListeners.drop.length     > 0, 'and for one being dropped');

function fileDrag(kind, target, file) {
    const e = {
        target: target || document.body,
        prevented: false,
        preventDefault() { this.prevented = true; },
        dataTransfer: { types: ['Files'], files: file ? [file] : [] }
    };
    dropListeners[kind].forEach(function (fn) { fn(e); });
    return e;
}

function csvFile(name, text, size) {
    return { name: name, type: 'text/csv', size: size === undefined ? text.length : size, _text: text };
}

// A drag of text inside a block is not a file drag and must be left alone, or
// dragging a word inside a text block stops working.
const textDrag = { target: document.body, prevented: false,
                   preventDefault() { this.prevented = true; },
                   dataTransfer: { types: ['text/plain'], files: [] } };
dropListeners.dragover.forEach(function (fn) { fn(textDrag); });
checkSame(false, textDrag.prevented, 'dragging text about the page is not the page\'s business');

// The defect this is really about: a file dropped on a page that ignores it makes
// the browser open the file, and the canvas — with everything on it not yet
// published — is gone from this tab with no prompt and nothing to undo.
checkSame(true, fileDrag('dragover').prevented, 'a file dragged anywhere over the Builder is refused the default');
checkSame(true, fileDrag('drop', document.body, csvFile('x.csv', 'a,b')).prevented,
          'and so is one dropped anywhere, before anything decides whether it is wanted');

const strayToast = document.getElementById('toast');
check(/table block/.test(strayToast.textContent), 'a file dropped on no table says where it should have gone');

// ---- Onto a table block ------------------------------------------------------

function tableBlock(locked) {
    const b = el('div', 'editable-block root-block');
    b.dataset.type    = 'table';
    b.dataset.locked  = locked ? '1' : '0';
    b.dataset.tableData = JSON.stringify({ headers: ['item_title'], rows: [['old']] });
    b.dataset.zIndex  = '1';
    b.dataset.hidden  = '0';
    return b;
}

const locked = tableBlock(true);
fileDrag('drop', locked, csvFile('prices.csv', 'Title,Price\nCoho,18.99'));
check(/locked/.test(document.getElementById('toast').textContent),
      'a table somebody locked is not imported into');
checkSame('', String(readerCalls.join('')), 'and the file is not even read');

// A real import, end to end: the file is read here, the modal opens on the block
// it was dropped on, and the rows reach the editor.
let rebuilt = null;
const realRebuild = rebuildTableEditor;
rebuildTableEditor = function (data) { rebuilt = data; return realRebuild(data); };

const target = tableBlock(false);
document.getElementById('builder-canvas').appendChild(target);
fileDrag('drop', target, csvFile('prices.csv', 'Title,Price\nCoho,18.99\nSockeye,24.99'));

checkSame('table', activeBlock ? activeBlock.dataset.type : null, 'the table it was dropped on is the one selected');
check(document.getElementById('table-modal-overlay').classList.contains('open'),
      'the editor opens over it, so the import is something to look at before it is kept');
check(rebuilt !== null, 'and the rows out of the file reach the editor');
checkDeep(['item_title', 'price'], rebuilt.headers, 'styled by the header row');
checkDeep([['Coho', '18.99'], ['Sockeye', '24.99']], rebuilt.rows, 'with the content under it');
check(/prices\.csv/.test(document.getElementById('table-csv-note').textContent),
      'and the note beside the drop zone names the file it read');
check(/2 rows/.test(document.getElementById('table-csv-note').textContent), 'and how much of it arrived');

// Nothing is stored until Save Table: the block still holds what it held.
checkSame(JSON.stringify({ headers: ['item_title'], rows: [['old']] }), target.dataset.tableData,
          'and the block itself is unchanged until Save Table — Cancel is the way out');

// The header tick can be changed without asking for the file again, because the
// browser will not hand the same one back.
rebuilt = null;
document.getElementById('table-csv-has-header').checked = false;
csvHeaderRowToggled();
checkSame(3, rebuilt ? rebuilt.rows.length : 0, 'unticking the header row re-reads the file already in hand');
checkDeep(['Title', 'Price'], rebuilt.rows[0], 'and the heading line becomes content');

// ---- Files that are not a price list -----------------------------------------

readerCalls.length = 0;
fileDrag('drop', target, { name: 'logo.jpg', type: 'image/jpeg', size: 4000 });
check(/not a \.csv/.test(document.getElementById('toast').textContent), 'a picture dropped on a table is refused');
checkSame(0, readerCalls.length, 'and not read');

fileDrag('drop', target, csvFile('empty.csv', '', 0));
check(/empty/.test(document.getElementById('toast').textContent), 'an empty file says so');

fileDrag('drop', target, csvFile('huge.csv', 'a,b', CSV_FILE_MAX_BYTES + 1));
check(/was not read/.test(document.getElementById('toast').textContent),
      'and one far too big to fit a block is refused before it is read');
checkSame(0, readerCalls.length, 'none of the three reached the reader');

// ---- On a page that may not edit ---------------------------------------------
//
// A read-only Builder has no modal and no drop zone — the markup is inside
// `<?php if (!$readOnly)` — but it has a canvas full of somebody's work on
// screen, so the navigation still has to be refused.

READ_ONLY = true;
readerCalls.length = 0;
const heldDrag = fileDrag('dragover');
checkSame(true, heldDrag.prevented, 'a file dragged over a Builder that may not edit is still refused the default');
const heldDrop = fileDrag('drop', target, csvFile('prices.csv', 'Title\nCoho'));
checkSame(true, heldDrop.prevented, 'and so is the drop, which is what stops the tab navigating away');
checkSame(0, readerCalls.length, 'the file is not read');
check(/Dana/.test(document.getElementById('toast').textContent),
      'and the page says who is holding the sign rather than doing nothing');
READ_ONLY = false;

// ============================================================
// Result
// ============================================================
// The expected total, for the same reason the other five suites carry one:
// without it, deleting half this file still reports a clean run.
const expected = 96;
if (checks !== expected) {
    fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
}

console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
fails.forEach(function (f) { console.log('  FAILED: ' + f); });
process.exit(fails.length ? 1 : 0);
