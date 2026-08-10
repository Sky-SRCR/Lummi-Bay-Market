// ============================================================
// SELF-TEST — a Builder upload always says what happened
// ============================================================
//   node tools/selftest_builder_uploads.js
//
// The defect this exists to keep out: three of the Builder's four upload handlers
// had `.then().then()` and no `.catch()`. An admin picking a 60 MB clip on the
// store's Wi-Fi got "Uploading video…", the request died, and *nothing else ever
// ran*. The toast faded after three and a half seconds and that was the last word
// on the subject — then they published, got a green "Published to Deli Board",
// and the sign showed an empty rectangle. The image and section-background
// handlers had no progress toast at all, so a failed upload and one still running
// looked identical.
//
// A missing `.catch()` is invisible to `php -l` and to `node --check`: the file
// parses perfectly. It is only visible by *running* the handler with a request
// that fails, which is what this does — one branch at a time, asserting that each
// one leaves words on the screen.
//
// Written as a second harness rather than folded into selftest_builder_readonly.js
// because the two need opposite premises: that one is a page where nothing can be
// edited, this one is a page where everything can. Both eval the real inline
// JavaScript out of builder.php, so neither can drift away from what ships.
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

function checkSame(expected, actual, label) {
    check(expected === actual, label + (expected === actual ? '' : ' — expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual)));
}

/** The message must mention this, whatever else it says. */
function checkMentions(haystack, needle, label) {
    check(String(haystack).indexOf(needle) >= 0,
          label + (String(haystack).indexOf(needle) >= 0 ? '' : ' — "' + haystack + '" does not mention "' + needle + '"'));
}

function section(title) { console.log('\n' + title); }

/**
 * Deliver the reply. A throw in here is a real failure and not merely a broken
 * test: in the browser `onload` is called by the event loop, so an exception
 * inside it is an uncaught error nobody sees, and the user is left with the
 * progress readout up and no message — which is the class of defect this whole
 * suite exists for.
 *
 * Recorded outside the check count so it cannot be mistaken for an assertion.
 */
function land(xhr) {
    try {
        xhr.onload();
    } catch (e) {
        fails.push('the reply handler threw instead of reporting — ' + e);
        console.log('  FAIL the reply handler threw instead of reporting — ' + e);
    }
}

// ---- A DOM where everything the Builder emits is present --------------------

function stubEl(id) {
    const el = {
        id, style: {}, dataset: {}, children: [], files: [],
        value: '', textContent: '', innerHTML: '', checked: false,
        offsetWidth: 100, offsetHeight: 100, clientWidth: 800, clientHeight: 600,
        scrollLeft: 0, scrollTop: 0, parentElement: null, parentNode: null,
        classList: { add() {}, remove() {}, contains() { return false; } },
        appendChild() {}, removeChild() {}, insertBefore() {}, remove() {},
        addEventListener() {}, focus() {}, blur() {}, load() {},
        querySelectorAll() { return []; },
        closest() { return null; }, getAttribute() { return null; }, setAttribute() {},
        getBoundingClientRect() { return { left: 0, top: 0, width: 100, height: 100 }; }
    };
    // Memoised per selector, so the progress readout's label and bar are the same
    // objects every call — which is what lets a check read back what was written.
    const inner = {};
    el.querySelector = function (sel) { return inner[sel] || (inner[sel] = stubEl(sel)); };
    return el;
}

const nodes = {};
global.document = {
    getElementById(id) { return nodes[id] || (nodes[id] = stubEl(id)); },
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
global.confirm     = () => true;
global.alert       = () => {};
global.FormData    = function () { this.fields = {}; this.append = function (k, v) { this.fields[k] = v; }; };
global.setTimeout  = () => 0;
global.setInterval = () => 0;
global.clearTimeout = () => {};

// ---- A request that goes exactly as badly as we say -------------------------

function FakeXhr() {
    this.upload = {};
    this.status = 200;
    this.responseText = '';
    this.timeout = 0;
    FakeXhr.last = this;
}
FakeXhr.prototype.open = function (method, url) { this.method = method; this.url = url; };
FakeXhr.prototype.send = function (body) { FakeXhr.sent++; this.body = body; };
FakeXhr.sent = 0;
global.XMLHttpRequest = FakeXhr;

/** A file input holding one chosen file, as the browser presents it. */
function inputWith(bytes, name) {
    const el = stubEl('file-input');
    el.files = [{ size: bytes, name: name || 'clip.mp4' }];
    el.value = 'C:\\fakepath\\' + (name || 'clip.mp4');
    return el;
}

// ---- The page's own JavaScript ----------------------------------------------

const php = fs.readFileSync(BUILDER, 'utf8');

let js = php.replace(/<\?(php|=)[\s\S]*?\?>/g, '0')
            .match(/<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)
            .map(function (b) { return b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''); })
            .join('\n');

// An admin on a Display nobody else holds — the page where uploading is possible
// at all. The limit is forced to a number a host really uses, so a check can
// state a size either side of it.
js = js.replace(/^var READ_ONLY\s*=.*$/m,        'var READ_ONLY = false;')
       .replace(/^var IS_ADMIN\s*=.*$/m,         'var IS_ADMIN = true;')
       .replace(/^var UPLOAD_MAX_BYTES\s*=.*$/m, 'var UPLOAD_MAX_BYTES = 8388608;')
       .replace(/^var UPLOAD_MAX_LABEL\s*=.*$/m, 'var UPLOAD_MAX_LABEL = "8 MB";');

check(/var UPLOAD_MAX_BYTES = 8388608;/.test(js), 'the page carries a server-set upload ceiling');
check(/var UPLOAD_MAX_LABEL = "8 MB";/.test(js),  'and a wording for it');

eval(js);   // eslint-disable-line no-eval — the point is to run the page's own code

const toast    = () => document.getElementById('toast').textContent;
const isErr    = () => document.getElementById('toast').className === 'err';
const showing  = () => document.getElementById('upload-status').style.display === 'block';
const progress = () => document.getElementById('upload-status').querySelector('.up-label').textContent;

function reset() {
    document.getElementById('toast').textContent = '';
    document.getElementById('toast').className   = '';
    FakeXhr.sent = 0;
    FakeXhr.last = null;
}

// ---- What each ending looks like --------------------------------------------

section('A file that cannot arrive is refused before it is sent');

reset();
let input = inputWith(20 * 1048576, 'promo.mp4');
startUpload(input, 'upload_video', 'video', function () {});
checkSame(0, FakeXhr.sent, 'a file over the limit is not uploaded at all');
check(isErr(), 'and the refusal reads as a refusal');
checkMentions(toast(), '20 MB', 'the message says how big the file is');
checkMentions(toast(), '8 MB',  'and what this server accepts');
checkSame('', input.value, 'the picker is cleared, so choosing the same file again is an event');

reset();
input = inputWith(0, 'empty.jpg');
startUpload(input, 'upload_file', 'image', function () {});
checkSame(0, FakeXhr.sent, 'an empty file is not uploaded either');
check(isErr(), 'and says so');

reset();
input = inputWith(8388608, 'exactly.jpg');
startUpload(input, 'upload_file', 'image', function () {});
checkSame(1, FakeXhr.sent, 'a file exactly at the limit is sent — the limit is inclusive');
checkMentions(progress(), 'Uploading image', 'and the readout says what is uploading');
check(showing(), 'and stays on screen while it does');
// Finished here, or this upload stays in flight for the rest of the file and the
// readout can never come down — which is the state every check below reads.
FakeXhr.last.responseText = JSON.stringify({ status: 'success', path: 'uploads/exactly.jpg' });
land(FakeXhr.last);
checkSame(false, showing(), 'and comes down when it lands');

section('Every way it can fail puts words on the screen');

// The one that used to be silent: the connection dies mid-request.
reset();
input = inputWith(4 * 1048576, 'clip.mp4');
let landed = null;
startUpload(input, 'upload_video', 'video', function (p) { landed = p; });
FakeXhr.last.onerror();
check(isErr(), 'a dropped connection is reported, not swallowed');
checkMentions(toast(), 'connection', 'and says what went wrong');
checkSame(null, landed, 'the success branch did not run');
checkSame(false, showing(), 'and the progress readout is gone');
checkSame('', input.value, 'with the picker cleared so it can be retried');

reset();
input = inputWith(4 * 1048576);
startUpload(input, 'upload_video', 'video', function () {});
FakeXhr.last.ontimeout();
check(isErr(), 'an upload the browser gave up on is reported');
checkMentions(toast(), 'Nothing was changed', 'and says nothing was changed');

// A 413 from api.php when PHP dropped the request body for exceeding
// post_max_size. Its message names the real limit; ours must not talk over it.
reset();
input = inputWith(4 * 1048576);
startUpload(input, 'upload_file', 'image', function () {});
FakeXhr.last.status = 413;
FakeXhr.last.responseText = JSON.stringify({ status: 'error', message: 'That file was too large to upload — this server accepts up to 8 MB. Nothing was changed.' });
land(FakeXhr.last);
checkMentions(toast(), 'too large to upload', 'the server\'s own explanation is what the user reads');

// A 500 whose body is not JSON at all — a crash above the payload.
reset();
input = inputWith(4 * 1048576);
startUpload(input, 'upload_file', 'image', function () {});
FakeXhr.last.status = 500;
FakeXhr.last.responseText = '<html><body>Internal Server Error</body></html>';
land(FakeXhr.last);
check(isErr(), 'a server error with no JSON to read is still reported');
checkMentions(toast(), '500', 'and names the status, so it can be looked up in the log');

// 200, but the body is not JSON. This is `r.json()` rejecting — the second silent
// failure on the old code's one line.
reset();
input = inputWith(4 * 1048576);
landed = null;
startUpload(input, 'upload_file', 'image', function (p) { landed = p; });
FakeXhr.last.status = 200;
FakeXhr.last.responseText = 'Warning: something printed above the JSON';
land(FakeXhr.last);
check(isErr(), 'a reply that is not JSON is reported rather than thrown away');
checkSame(null, landed, 'and nothing is applied to the block');

// 200 with a proper refusal in it: wrong file type, MIME mismatch, unwritable
// uploads directory.
reset();
input = inputWith(4 * 1048576);
startUpload(input, 'upload_file', 'image', function () {});
FakeXhr.last.responseText = JSON.stringify({ status: 'error', message: 'File type rejected.' });
land(FakeXhr.last);
checkSame('File type rejected.', toast(), 'a refusal the server explains is passed through verbatim');

// 200, success shape, but no path. Applying '' would blank the block.
reset();
input = inputWith(4 * 1048576);
landed = null;
startUpload(input, 'upload_file', 'image', function (p) { landed = p; });
FakeXhr.last.responseText = JSON.stringify({ status: 'success' });
land(FakeXhr.last);
checkSame(null, landed, 'a success with no file path is not treated as a success');
check(isErr(), 'and says so');

section('And a success is a success');

reset();
input = inputWith(4 * 1048576, 'sockeye.jpg');
landed = null;
startUpload(input, 'upload_file', 'image', function (p) { landed = p; });
if (FakeXhr.last.upload.onprogress) {
    FakeXhr.last.upload.onprogress({ lengthComputable: true, loaded: 2097152, total: 4194304 });
    checkMentions(progress(), '50%', 'progress is reported as a percentage while it uploads');
} else {
    check(false, 'progress is reported as a percentage while it uploads');
}
FakeXhr.last.responseText = JSON.stringify({ status: 'success', path: 'uploads/img_abc.jpg' });
land(FakeXhr.last);
checkSame('uploads/img_abc.jpg', landed, 'the saved path reaches the block');
checkSame(false, showing(), 'the progress readout is taken down');
checkSame('', input.value, 'and the picker is cleared for the next one');

section('One upload per picker at a time');

reset();
input = inputWith(4 * 1048576);
startUpload(input, 'upload_file', 'image', function () {});
const firstXhr = FakeXhr.last;
input.files = [{ size: 1048576, name: 'second.jpg' }];
startUpload(input, 'upload_file', 'image', function () {});
checkSame(1, FakeXhr.sent, 'a second file chosen mid-upload is not sent as well');
check(isErr(), 'and the user is told why');
firstXhr.responseText = JSON.stringify({ status: 'success', path: 'uploads/one.jpg' });
land(firstXhr);
// And the input is usable again once the first one ends.
reset();
input.files = [{ size: 1048576, name: 'third.jpg' }];
startUpload(input, 'upload_file', 'image', function () {});
checkSame(1, FakeXhr.sent, 'once it finishes the picker works again');

section('A block that moved on while the file was uploading');

// The upload outlives the selection: an admin picks a file, clicks somewhere else,
// and the reply arrives for a block that is no longer active. The handlers capture
// their block rather than reading activeBlock in the callback, so this must not
// throw and must not write to whatever happens to be selected now.
reset();
const videoBlock = stubEl('vb');
videoBlock.dataset.type = 'video';
activeBlock = videoBlock;
input = inputWith(2 * 1048576);
uploadBlockVideo(input);
activeBlock = null;
FakeXhr.last.responseText = JSON.stringify({ status: 'success', path: 'uploads/vid_x.mp4' });
land(FakeXhr.last);
check(true, 'a reply arriving after the block was deselected does not throw');
checkSame('uploads/vid_x.mp4', videoBlock.dataset.manualPath, 'and lands on the block that asked for it');

section('An admin who loses the display mid-edit');

// Not an upload, but the same premise this suite exists for — a page that *can*
// edit — and the same class of defect: a reply the page received and did nothing
// with. Losing the display makes every later heartbeat fail, so a page that swallows
// the refusal carries on letting somebody work on a sign they have already lost.

// Two bars would otherwise be true at once. Age this tab's last interaction past
// the lapse window first, so the check can see the access notice win rather than
// find it in a page where nothing else was showing anyway.
lastInteraction = Date.now() - (LOCK_LAPSE_SECONDS + 60) * 1000;
renderLockBars();
checkSame('flex', document.getElementById('lock-lapsed-bar').style.display,
          'a long quiet spell shows the lapsed notice');

let beats = 0;
global.fetch = function () { beats++; return Promise.resolve({ json: () => Promise.resolve({}) }); };

applyLockAnswer({ status: 'error', reason: 'forbidden',
                  message: 'That display has not been assigned to you.' });
checkSame('flex', document.getElementById('lock-access-bar').style.display,
          'a heartbeat refused as forbidden puts the access notice on screen');
checkSame('none', document.getElementById('lock-lapsed-bar').style.display,
          'and takes down the lapsed notice, which is no longer the thing to read');
checkSame('none', document.getElementById('lock-lost-bar').style.display,
          'and never shows the lost notice, which would name a holder there is not one of');

holdLock();
lockTick();
checkSame(0, beats, 'no further heartbeat is sent, because every one of them would be refused');

let beaconed = 0;
global.navigator = { sendBeacon: function () { beaconed++; return true; } };
releaseLockOnLeave();
checkSame(0, beaconed, 'and no release beacon on leaving — the revoke already freed the lock');

// ---- The other four ways it happens -----------------------------------------

// A revoked grant was one of five, and the other four were swallowed by this very
// branch. Each has its own sentence because what the person should do differs: ask an
// admin, copy your work, reload the page, sign in again. A single "you lost the
// display" would send somebody hunting an admin over a renamed tag.
function beatRefusedWith(reason, message) {
    accessLost = false;
    document.getElementById('lock-access-bar').style.display = 'none';
    document.getElementById('lock-access-text').innerHTML    = '';
    applyLockAnswer({ status: 'error', reason: reason, message: message });
}

beatRefusedWith('inactive', 'This display is turned off');
checkSame('flex', document.getElementById('lock-access-bar').style.display,
          'a display retired under an editor puts the notice on screen');
check(/turned off/i.test(document.getElementById('lock-access-text').innerHTML),
      'and says it was turned off rather than taken away from them');

beatRefusedWith('unknown', 'Display not found');
checkSame('flex', document.getElementById('lock-access-bar').style.display,
          'a renamed screen name tag does too');
check(/reload/i.test(document.getElementById('lock-access-text').innerHTML),
      'and asks for a reload, because a rename leaves the display theirs');

beatRefusedWith('mismatch', 'That screen name tag no longer belongs to this display.');
checkSame('flex', document.getElementById('lock-access-bar').style.display,
          'so does a tag that now names a different display');

beatRefusedWith('signed_out', 'Your account is no longer active. Sign in again.');
checkSame('flex', document.getElementById('lock-access-bar').style.display,
          'and so does the account itself being suspended');
check(/signed out/i.test(document.getElementById('lock-access-text').innerHTML),
      'which is the one of the five that is about them rather than the display');

// The first answer wins. A second refusal arriving a minute later is a consequence of
// the first, not news, and rewriting the sentence under somebody reading it would only
// make them doubt it.
applyLockAnswer({ status: 'error', reason: 'forbidden', message: 'Not yours.' });
check(/signed out/i.test(document.getElementById('lock-access-text').innerHTML),
      'and a later refusal does not rewrite the notice already on screen');

// An ordinary failed beat is still nothing to act on: the next one covers it, and a
// notice on every dropped connection would be a notice nobody reads. This is why the
// terminal reasons are a fixed list rather than "anything with a reason" — a reason
// added to the server later must not become fatal to an editor by accident.
beatRefusedWith('stale', 'That layout is out of date.');
checkSame('none', document.getElementById('lock-access-bar').style.display,
          'while a refusal that can be recovered from is left alone');
beatRefusedWith('', 'Something went wrong.');
checkSame('none', document.getElementById('lock-access-bar').style.display,
          'and so is a failure with no reason at all, which is what a dropped beat looks like');

// ---- The two opening reads, each reporting itself ---------------------------
//
// These exist because the defect they cover was invisible to every other kind of
// check. There was no missing `.catch()` — `Promise.all([loadAssets(), loadLayout()])`
// carried one for both — so nothing was ever an unhandled rejection and a message
// always appeared. A parse cannot see it and a grep for `.catch` finds it present.
// What was wrong was that one sentence stood for two unrelated failures, and it was
// the wrong sentence for one of them.
//
// Driving the real chains is the only way to read what a person would be told, which
// is why the tail of this suite is async.

async function openingReads() {
    section('Each opening read says what actually failed');

    // ---- The layout read: the one that matters -------------------------------
    // Three ways it can fail, two of which produce the same advice on purpose.
    reset();
    global.fetch = () => Promise.reject(new Error('no route to host'));
    await loadLayout();
    checkMentions(toast(), 'not what the sign is showing',
                  'a layout read that never answered says the canvas is not the sign');
    checkMentions(toast(), 'Nothing has been saved',
                  'and says nothing was saved, which ADR-0006 makes true rather than reassuring');
    checkMentions(toast(), 'Reload before editing', 'and says what to do about it');
    check(isErr(), 'and it is an error, not a note');
    check(toast().indexOf('asset library') === -1,
          'and does not mention the asset library, which had nothing to do with it');

    reset();
    global.fetch = () => Promise.resolve({ json: () => Promise.reject(new SyntaxError('<html>')) });
    await loadLayout();
    checkMentions(toast(), 'not what the sign is showing',
                  'a reply that arrived unreadable gets the same advice, because it is the same advice');
    check(isErr(), 'and is an error too');

    // ---- The library read: the lesser one ------------------------------------
    reset();
    global.fetch = () => Promise.reject(new Error('no route to host'));
    await loadAssets();
    checkMentions(toast(), 'asset library', 'a failed library read names the library');
    checkMentions(toast(), 'dropdown is empty', 'and says what is actually lost');
    checkMentions(toast(), 'layout itself is fine',
                  'and says the layout is fine, which is the half the shared handler got wrong');
    check(toast().indexOf('Nothing has been saved') === -1,
          'and does not tell an admin their layout failed to load, which was false half the time');
    check(toast().indexOf('Reload before editing') === -1,
          'and does not send them away from a page they can still safely use');

    // ---- The two are not the same sentence ----------------------------------
    // The whole defect in one check: if a future change routes both through one
    // handler again, these two strings become equal.
    reset();
    global.fetch = () => Promise.reject(new Error('down'));
    await loadLayout();
    const layoutSaid = toast();
    reset();
    await loadAssets();
    const assetsSaid = toast();
    check(layoutSaid !== assetsSaid,
          'the two reads do not print the same sentence, which is the whole of this fix');
    check(layoutSaid !== '' && assetsSaid !== '',
          'and neither of them fails silently');
}

// ---- Total ------------------------------------------------------------------

// The expected total, for the same reason the other two suites carry one: without
// it, deleting half this file still reports a clean run.
function finish() {
    const expected = 67;
    if (checks !== expected) {
        fails.push('the suite ran every check it is supposed to — expected ' + expected + ', ran ' + checks);
    }

    console.log('\n' + checks + ' checks, ' + fails.length + ' failed');
    fails.forEach(function (f) { console.log('  FAILED: ' + f); });
    process.exit(fails.length ? 1 : 0);
}

// A rejection in here would otherwise exit 0 with a short count, which is the
// failure mode the harness hardening in #50 was about.
openingReads().then(finish, function (e) {
    fails.push('the opening-reads section threw: ' + (e && e.message));
    finish();
});
