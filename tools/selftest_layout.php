<?php
// ============================================================
// SELF-TEST — Display scoping, publish, staleness
// ============================================================
//   php tools/selftest_layout.php
//
// Runs the real lib/ modules against the in-memory fixture. Everything asserted
// here is a rule the app must not lose, so treat a failure as a release blocker
// rather than a broken test. Add to this file as later phases add rules.

require_once __DIR__ . '/test_fixture.php';
// auth.php starts a session, so it has to be included before this file prints
// anything — PHP cannot set a session cookie once output has begun. The session
// gate checks themselves live at the bottom, with the rest of the assertions.
require_once __DIR__ . '/../auth.php';

// ─────────────────────────────────────────────────────────────
section('Screen name tag rules');

checkSame(true,  DisplayStore::isValidTag('drive-thru'), 'lowercase and hyphens are valid');
checkSame(true,  DisplayStore::isValidTag('lobby2'),     'digits are valid');
checkSame(false, DisplayStore::isValidTag('a'),          'one character is too short');
checkSame(false, DisplayStore::isValidTag(''),           'empty is invalid');
checkSame(false, DisplayStore::isValidTag(str_repeat('x', 33)), '33 characters is too long');
checkSame(false, DisplayStore::isValidTag('lobby_1'),    'underscores are not allowed');
checkSame(false, DisplayStore::isValidTag('Drive-Thru'), 'uppercase is not a valid stored tag');
checkSame('drive-thru', DisplayStore::normalizeTag('  DRIVE-THRU '), 'input is trimmed and lowercased');

// Folding stops at things that are not strings (#27). The cast that used to be here
// answered "Array" for a list — a valid tag, so every caller went on to act on a
// name nobody had sent — and raised a warning on the way past.
checkSame('', DisplayStore::normalizeTag(['drive-thru']),
          'a list is not a tag folded badly, it is not a tag');
checkSame('', DisplayStore::normalizeTag(null),  'and neither is nothing');
checkSame('', DisplayStore::normalizeTag(true),  'nor true, which used to fold to "1"');
checkSame(false, DisplayStore::isValidTag(DisplayStore::normalizeTag(['x'])),
          'so nothing downstream can be handed a tag it would accept');

// ─────────────────────────────────────────────────────────────
section('Which Display does a request mean?');

$pdo    = newTestDb();
$store  = new DisplayStore($pdo);
$driveT = makeTestDisplay($pdo, 'drive-thru', 'Drive-Thru');
// Account 1 is the admin, account 2 the basic clerk (see the fixture).
$actor  = newTestActor($pdo, 1, 'admin');

// Viewing is strict as of Phase 2 (ADR-0003): the Screens send their tag, so a
// URL that names nothing gets a notice rather than a guess — even when only one
// Display exists and the guess would have been right.
$r = DisplayRequest::forViewing($store, []);
checkSame(DisplayResolution::NO_TAG, $r->kind(), 'viewing with no tag is refused even with a sole Display');

// The editing entry rule: one Display to work on and no tag goes straight in.
$r = DisplayRequest::forEditing($store, [], $actor);
check($r->isFound() && $r->display()->tag() === 'drive-thru', 'editing with no tag resolves to the sole Display');

// ---- A parameter that is not a tag names no sign (#27) -------------------------
// Checked here, while drive-thru is still the only Display, because this is the
// state where getting it wrong costs the most: the entry rule directly above would
// otherwise hand a malformed parameter the sign it declines to ask about.
$listed = ['display' => ['drive-thru']];

$r = DisplayRequest::forViewing($store, $listed);
checkSame(DisplayResolution::NO_TAG, $r->kind(), '?display[]=x names no sign');
checkSame('No display specified', $r->message(),
          'and the Screen says so, rather than "Display not found" — nothing was named');

$r = DisplayRequest::forEditing($store, $listed, $actor);
checkSame(DisplayResolution::NO_TAG, $r->kind(),
          'and a write is refused rather than routed to the sole Display the entry rule would have picked');

// Nothing is cast, so nothing warns. Since §4m that warning is a line in a 2 MB
// rotating log rather than text above the document — and a Screen hung on the wall
// with a malformed address writes one every 30 seconds for as long as it is up.
$warned = null;
set_error_handler(function ($sev, $msg) use (&$warned) { $warned = $msg; return true; });
DisplayRequest::forViewing($store, $listed);
DisplayStore::normalizeTag(['drive-thru']);
restore_error_handler();
checkSame(null, $warned, 'and no "Array to string conversion" is raised on the way');

$r = DisplayRequest::forViewing($store, ['display' => 'array']);
checkSame(DisplayResolution::UNKNOWN, $r->kind(),
          'while the word the old cast produced is only ever an ordinary unknown tag');

// The sharp end of it, and not a hypothetical: "array" is a perfectly ordinary
// screen name tag, and with one in the table `?display[]=x` rendered that sign.
makeTestDisplay($pdo, 'array', 'Array');
$r = DisplayRequest::forViewing($store, $listed);
checkSame(DisplayResolution::NO_TAG, $r->kind(),
          'even with a Display genuinely tagged "array", a list still reaches nothing');
$pdo->exec("DELETE FROM displays WHERE tag = 'array'");

$r = DisplayRequest::forViewing($store, ['display' => 'DRIVE-THRU']);
check($r->isFound(), 'a tag is matched case-insensitively');

$r = DisplayRequest::forViewing($store, ['display' => 'nope']);
checkSame(DisplayResolution::UNKNOWN, $r->kind(), 'an unknown tag is UNKNOWN');
checkSame('Display not found', $r->message(), 'unknown tag notice wording (ADR-0003)');

$r = DisplayRequest::forViewing($store, ['display' => 'drive thru!']);
checkSame(DisplayResolution::UNKNOWN, $r->kind(), 'an invalid tag is rejected, not crashed on');

$lobby = makeTestDisplay($pdo, 'lobby', 'Lobby', 1080, 1920);
$r = DisplayRequest::forViewing($store, []);
checkSame(DisplayResolution::NO_TAG, $r->kind(), 'with two Displays, no tag still resolves to nothing');
checkSame('No display specified', $r->message(), 'no-tag notice wording (ADR-0003)');

$r = DisplayRequest::forEditing($store, [], $actor);
checkSame(DisplayResolution::NO_TAG, $r->kind(), 'a write with no tag fails once a second Display exists');

$pdo->exec("UPDATE displays SET is_active = 0 WHERE tag = 'lobby'");
$r = DisplayRequest::forViewing($store, ['display' => 'lobby']);
checkSame(DisplayResolution::INACTIVE, $r->kind(), 'a deactivated Display does not render');
checkSame('This display is turned off', $r->message(), 'deactivated notice wording (ADR-0003)');
check($r->display() !== null, 'an inactive resolution still carries the Display');

$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], $actor);
check($r->isFound(), 'a deactivated Display is still editable by an admin');

// ADR-0005: the role decides how much power. A sign out of service is not a basic
// account's to work on, and that is decided in the seam, not in a page. The clerk
// is granted both Displays here so that the refusal can only be about the role.
grantTestAccess($pdo, $driveT->id(), 2);
grantTestAccess($pdo, $lobby->id(), 2);
$clerk = newTestActor($pdo, 2, 'basic');
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], $clerk);
checkSame(DisplayResolution::INACTIVE, $r->kind(), 'but not by a basic account, even a granted one');
$r = DisplayRequest::forEditing($store, ['display' => 'drive-thru'], $clerk);
check($r->isFound(), 'which does not stop a basic account editing an active Display');

$pdo->exec("UPDATE displays SET is_active = 1 WHERE tag = 'lobby'");

// ─────────────────────────────────────────────────────────────
section('The tag addresses, the id confirms');

// A whole database of its own, because these checks rename tags and hand them to
// other Displays — the exact sequence being defended against, and not something to
// leave behind for the sections below.
$idPdo   = newTestDb();
$idStore = new DisplayStore($idPdo);
$idAdmin = newTestActor($idPdo, 1, 'admin');
$signA   = makeTestDisplay($idPdo, 'drive-thru', 'Drive-Thru');
$signB   = makeTestDisplay($idPdo, 'lobby', 'Lobby', 1080, 1920);

$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby']);
check($r->isFound(), 'a request with no id claim resolves as it always has');

$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby', 'display_id' => $signB->id()]);
check($r->isFound(), 'and one whose id agrees with the tag resolves too');

$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby', 'display_id' => $signA->id()]);
checkSame(DisplayResolution::MISMATCH, $r->kind(), 'an id naming a different Display than the tag is refused');
check($r->display() !== null && $r->display()->id() === $signB->id(),
      'and the refusal carries the Display the tag named — the one that was about to be written');

// The sequence the id exists for: a tag is renamed, and the name it vacated is
// given to another sign. A Builder open on the first one still addresses
// 'drive-thru', which now resolves — cleanly, and to the wrong screen.
$idPdo->exec("UPDATE displays SET tag = 'drive-thru-old' WHERE id = " . $signA->id());
$idPdo->exec("UPDATE displays SET tag = 'drive-thru' WHERE id = " . $signB->id());
$r = DisplayRequest::forEditing($idStore, ['display' => 'drive-thru'], $idAdmin);
check($r->isFound() && $r->display()->id() === $signB->id(),
      'a recycled tag resolves to its new Display, which is why the tag alone is not enough');
$r = DisplayRequest::forEditing($idStore, ['display' => 'drive-thru', 'display_id' => $signA->id()], $idAdmin);
checkSame(DisplayResolution::MISMATCH, $r->kind(),
          'so a page that was opened on the old one is refused rather than published to the new one');

// A claim that cannot be a Display id is a disagreement, not something to guess at:
// nothing that knows which Display it is on sends one of these.
$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby-2', 'display_id' => ['1']]);
checkSame(DisplayResolution::UNKNOWN, $r->kind(), 'an unknown tag is still unknown, id claim or not');
$idPdo->exec("UPDATE displays SET tag = 'lobby' WHERE id = " . $signA->id());
$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby', 'display_id' => [(string)$signA->id()]]);
checkSame(DisplayResolution::MISMATCH, $r->kind(), 'an array id claim is refused rather than cast to a number');
$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby', 'display_id' => '1abc']);
checkSame(DisplayResolution::MISMATCH, $r->kind(), 'and so is one that is not a whole number');
$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby', 'display_id' => '0' . $signA->id()]);
checkSame(DisplayResolution::MISMATCH, $r->kind(), 'including a padded one that would have compared equal');

// The check cannot be the thing that stops a Screen rendering: a Viewer URL on a TV
// carries the tag and nothing else (ADR-0003), and a form field left blank is a
// page that failed to fill it in, not a page claiming Display zero.
$r = DisplayRequest::forViewing($idStore, ['display' => 'lobby', 'display_id' => '']);
check($r->isFound(), 'an empty id claim is no claim, and renders');

// The entry rule resolves a Display nobody named, so the claim is checked there too
// — otherwise a write with a stale id and no tag would slip past.
$soloPdo  = newTestDb();
$soloStore = new DisplayStore($soloPdo);
$solo      = makeTestDisplay($soloPdo, 'only-sign', 'Only Sign');
$soloAdmin = newTestActor($soloPdo, 1, 'admin');
$r = DisplayRequest::forEditing($soloStore, [], $soloAdmin);
check($r->isFound(), 'no tag still resolves to the sole Display');
$r = DisplayRequest::forEditing($soloStore, ['display_id' => $solo->id()], $soloAdmin);
check($r->isFound(), 'and agrees with an id claim naming it');
$r = DisplayRequest::forEditing($soloStore, ['display_id' => $solo->id() + 1], $soloAdmin);
checkSame(DisplayResolution::MISMATCH, $r->kind(), 'but not with one naming a Display it is not');

// ─────────────────────────────────────────────────────────────
section('Publishing is scoped to one Display');

$layouts = newTestLayoutStore($pdo);

/** A minimal admin layout: one section with one price block inside it. */
function layoutWith($text, $tempId = 's1')
{
    return [
        ['type' => 'section', 'temp_id' => $tempId, 'x_pos' => 10, 'y_pos' => 20, 'width' => 600, 'height' => 380],
        ['type' => 'text', 'block_subtype' => 'price', 'parent_temp_id' => $tempId,
         'manual_content' => $text, 'x_pos' => 5, 'y_pos' => 5, 'width' => 160, 'height' => 60],
    ];
}

/**
 * The same layout as a *basic* account's Builder would really send it.
 *
 * The difference is one field and it is the whole of ADR-0005 on this path: a clerk
 * does not create sections, so the section in their payload is one that already
 * exists and carries its `db_id`. Without it the parent temp-id resolves to nothing,
 * the block lands at root level, and the publish is refused for placing layout —
 * correctly. `layoutWith()` is the admin shape and stays that way.
 */
function basicLayoutFor(PDO $pdo, Display $display, $text)
{
    $sectionId = 0;
    foreach (elementsOf($pdo, $display->id()) as $row) {
        if ($row['type'] === 'section' && !$sectionId) { $sectionId = intval($row['id']); }
    }
    $layout = layoutWith($text);
    $layout[0]['db_id'] = $sectionId;
    return $layout;
}

/**
 * One publish, as one visit to the Builder: publish, then leave.
 *
 * The leaving matters. A publish keeps the publisher's edit lock alive (ADR-0007),
 * so a Display that changes hands between two checks would otherwise refuse the
 * second account for a reason that has nothing to do with what is being tested.
 * Releasing here models what actually happens — the tab closes — and keeps every
 * lock assertion in the one section that is about locks.
 */
function publishAs(LayoutStore $layouts, Display $display, array $elements, $stamp, $isAdmin = true, $actorId = 1, ?Background $bg = null)
{
    global $pdo;
    $result = $layouts->publish($display, new PublishRequest(
        $elements, $bg ?: Background::unchanged(), BrandChoice::unchanged(), $actorId, $isAdmin, $stamp
    ));
    (new DisplayStore($pdo))->releaseLock($display, $actorId);
    return $result;
}

$res = publishAs($layouts, $driveT, layoutWith('Drive-thru $9.99'), '0');
check($res->isOk(), 'first publish to drive-thru succeeds with the loaded stamp');
checkSame('1', $res->stamp(), 'the stamp advances to 1');

$lobby = loadTestDisplay($pdo, $lobby->id());
$res = publishAs($layouts, $lobby, layoutWith('Lobby $1.00', 's9'), '0');
check($res->isOk(), 'first publish to lobby succeeds');

checkSame(2, count(elementsOf($pdo, $driveT->id())), 'drive-thru has its two elements');
checkSame(2, count(elementsOf($pdo, $lobby->id())),  'lobby has its two elements');

// The whole point of Phase 1: republishing one Display must not touch the other.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWith('Drive-thru $12.49'), $driveT->layoutStamp());
check($res->isOk(), 'republishing drive-thru succeeds');

$lobbyRows = elementsOf($pdo, $lobby->id());
checkSame(2, count($lobbyRows), 'lobby still has its elements after drive-thru was republished');
$lobbyText = '';
foreach ($lobbyRows as $row) { if ($row['type'] === 'text') { $lobbyText = $row['manual_content']; } }
checkSame('Lobby $1.00', $lobbyText, 'lobby content is untouched');

$driveText = '';
foreach (elementsOf($pdo, $driveT->id()) as $row) { if ($row['type'] === 'text') { $driveText = $row['manual_content']; } }
checkSame('Drive-thru $12.49', $driveText, 'drive-thru content was replaced');

checkSame(4, count(allElements($pdo)), 'no element belongs to no Display');

// ─────────────────────────────────────────────────────────────
section('A stale publish is refused and writes nothing');

$driveT = loadTestDisplay($pdo, $driveT->id());
$before = count(elementsOf($pdo, $driveT->id()));

$res = publishAs($layouts, $driveT, layoutWith('Overwrite attempt'), '');
checkSame('stale', $res->kind(), 'a publish with no stamp is refused');

$res = publishAs($layouts, $driveT, layoutWith('Overwrite attempt'), '0');
checkSame('stale', $res->kind(), 'a publish holding an old stamp is refused');
check(strpos($res->message(), 'changed since you opened it') !== false, 'the refusal explains why');
check(strpos($res->message(), 'sky') !== false, 'the refusal names who published last');

$after = elementsOf($pdo, $driveT->id());
checkSame($before, count($after), 'a refused publish leaves the layout alone');
$stillThere = '';
foreach ($after as $row) { if ($row['type'] === 'text') { $stillThere = $row['manual_content']; } }
checkSame('Drive-thru $12.49', $stillThere, 'a refused publish changes no content');

$res = publishAs($layouts, $driveT, layoutWith('Accepted'), $driveT->layoutStamp());
check($res->isOk(), 'the same publish succeeds with the current stamp');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame($res->stamp(), $driveT->layoutStamp(), 'the returned stamp is the Display\'s new stamp');
checkSame('sky', $driveT->lastPublishedByName(), 'the Display records who published');
check($driveT->lastPublishedAt() !== null, 'the Display records when it was published');

// …and a read straight after the publish can turn both into the sentence a page
// shows, which is what the publish reply now sends back to the Builder. Recorded and
// shown are two different claims: this was recorded for a year and shown nowhere in
// the Builder, which is browser pass step H.1 (§4ax).
$freshlyPublished = $store->forId($driveT->id());
check($freshlyPublished !== null, 'the Display can be read back straight after a publish');
checkMentions($freshlyPublished->lastPublishDescription(), 'sky',
              'and the sentence for the page names who published');
check($freshlyPublished->lastPublishDescription() !== '',
      'so the publish reply has something to send, rather than an empty line');

// ─────────────────────────────────────────────────────────────
section('A basic account keeps sections and cannot reach another Display');

// Admin lays out one section with content; a basic account then republishes.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWith('Admin layout'), $driveT->layoutStamp());
check($res->isOk(), 'admin sets up a section to work inside');

$sectionId = 0;
foreach (elementsOf($pdo, $driveT->id()) as $row) { if ($row['type'] === 'section') { $sectionId = intval($row['id']); } }
check($sectionId > 0, 'the section exists');

$driveT = loadTestDisplay($pdo, $driveT->id());
$basicLayout = [
    ['type' => 'section', 'temp_id' => 's1', 'db_id' => $sectionId, 'x_pos' => 999, 'y_pos' => 999, 'width' => 10, 'height' => 10],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => 'Basic user price', 'width' => 160, 'height' => 60],
];
$res = publishAs($layouts, $driveT, $basicLayout, $driveT->layoutStamp(), false, 2);
check($res->isOk(), 'a basic account may publish content');

$rows = elementsOf($pdo, $driveT->id());
$sections = array_values(array_filter($rows, function ($r) { return $r['type'] === 'section'; }));
checkSame(1, count($sections), 'the section was preserved, not replaced');
checkSame($sectionId, intval($sections[0]['id']), 'it is the same section row');
checkSame(10, intval($sections[0]['x_pos']), 'a basic account cannot move the section');
$texts = array_values(array_filter($rows, function ($r) { return $r['type'] === 'text'; }));
checkSame(1, count($texts), 'content was replaced');
checkSame('Basic user price', $texts[0]['manual_content'], 'the new content is there');
checkSame($sectionId, intval($texts[0]['section_id']), 'the content stayed inside the section');

// A forged db_id naming another Display's section must not parent content there.
$lobby = loadTestDisplay($pdo, $lobby->id());
$lobbySectionId = 0;
foreach (elementsOf($pdo, $lobby->id()) as $row) { if ($row['type'] === 'section') { $lobbySectionId = intval($row['id']); } }
$lobbyBefore = count(elementsOf($pdo, $lobby->id()));

$driveT = loadTestDisplay($pdo, $driveT->id());
$forged = [
    ['type' => 'section', 'temp_id' => 's1', 'db_id' => $lobbySectionId],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => 'Injected'],
];
$res = publishAs($layouts, $driveT, $forged, $driveT->layoutStamp(), false, 2);
// This used to be *accepted*, with the block landing at root level on the publisher's
// own Display. Scoping held — which is all that check ever proved — but a basic account
// had placed layout. Both halves are one refusal now: the forged id resolves to no
// section *of this Display*, so the content it claims a parent for is content outside
// every section, which this role may not add.
checkSame('invalid', $res->kind(), 'a forged db_id naming another Display\'s section is refused');
checkMentions($res->message(), 'not inside any section', 'and says why in terms of what was sent');

checkSame($lobbyBefore, count(elementsOf($pdo, $lobby->id())), 'lobby gained nothing from the forged publish');
$injected = null;
foreach (elementsOf($pdo, $driveT->id()) as $row) {
    if ($row['manual_content'] === 'Injected') { $injected = $row; }
}
checkSame(null, $injected, 'and the publisher\'s own Display gained nothing either');

// ─────────────────────────────────────────────────────────────
section('Content sanitising');

$driveT = loadTestDisplay($pdo, $driveT->id());
$mixed = [
    ['type' => 'section', 'temp_id' => 's1'],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => '<script>alert(1)</script>Hello'],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => '0'],
    ['type' => 'carousel', 'parent_temp_id' => 's1', 'manual_content' => '{"images":["uploads/a.png"],"ms":3000}'],
];
$res = publishAs($layouts, $driveT, $mixed, $driveT->layoutStamp());
check($res->isOk(), 'a mixed layout publishes');

$byContent = [];
foreach (elementsOf($pdo, $driveT->id()) as $row) { $byContent[] = $row; }
$textValues = [];
$carousel   = '';
foreach ($byContent as $row) {
    if ($row['type'] === 'text')     { $textValues[] = $row['manual_content']; }
    if ($row['type'] === 'carousel') { $carousel = $row['manual_content']; }
}
check(in_array('alert(1)Hello', $textValues, true), 'markup is stripped from text (ADR-0002)');
check(in_array('0', $textValues, true), 'a text block reading "0" survives as "0"');
checkSame('{"images":["uploads/a.png"],"ms":3000}', $carousel, 'carousel JSON is stored verbatim');

// ─────────────────────────────────────────────────────────────
section('The sanitiser itself, one line at a time (decision #49)');

// The check above proves a publish goes through toPlainText(). It was also the only
// thing standing over the function, and it exercises one of its six lines: seventeen
// mutations of lib/plain_text.php left fifteen alive, including deleting whole
// statements. Every line here changes what a customer reads, so each gets a case.
//
// Called directly rather than through a publish because that is the honest shape of
// it: crud.php, AssetLibrary::saveEdit() and LayoutStore all funnel into this one
// function, and what it does is not any of their business.

// ---- Markup goes; the words it framed stay ------------------------------------
checkSame('OPEN 7 DAYS', toPlainText('<b>OPEN</b> <i>7 DAYS</i>'),
          'tags are stripped and what they wrapped is kept');
checkSame('alert(1)', toPlainText('<script>alert(1)</script>'),
          'and a script tag is just a tag — this is the belt to textContent\'s braces');

// ---- The line breaks somebody meant to keep -----------------------------------
// Rewritten to newlines *before* the strip, because strip_tags would take the break
// away with the tag and run two prices together on the sign.
checkSame("Wings\n\$8.99", toPlainText('Wings<br>$8.99'), 'a <br> is the line break it looks like');
checkSame("Wings\n\$8.99", toPlainText('Wings<BR />$8.99'), 'however it is spelled and cased');
checkSame("Wings\n\$8.99", toPlainText('Wings< br/>$8.99'), 'and however it is spaced');
checkSame("Half rack\nFull rack", toPlainText('<div>Half rack</div><div>Full rack</div>'),
          'a closing block element ends a line too');
checkSame("Ribs\nWings\nFries", toPlainText('<ul><li>Ribs</li><li>Wings</li><li>Fries</li></ul>'),
          'which is what makes a pasted list arrive as a list');
checkSame("Specials\nToday only", toPlainText('<h2>Specials</h2><p>Today only</p>'),
          'headings and paragraphs both count');

// ---- Entities become the characters they stand for ----------------------------
checkSame("Tom's & Co", toPlainText('Tom&#39;s &amp; Co'),
          'entities are decoded, so a sign shows the character and not the code for it');
checkSame('café', toPlainText('caf&eacute;'), 'named ones as well as numeric');

// ---- …and the decode is *last*, which is load-bearing in both directions -------
// A browser sends a typed "<" back as `&lt;`, and strip_tags eats from a "<" to the
// end of the string when nothing closes it. Decoding first would therefore delete
// the rest of the line — on the sign, silently, for a price nobody mistyped.
checkSame('Wings <10 pieces', toPlainText('Wings &lt;10 pieces'),
          'an encoded "<" survives as a character, because the decode runs after the strip');

// The cost of that order, stated rather than left to be discovered: markup that
// arrives encoded decodes into text that *looks* like markup, and is stored that
// way. It is inert because every renderer draws stored text with textContent
// (ADR-0002, and viewer.php:502 / builder.php:1495 are where) — never innerHTML.
// A future renderer that forgets makes this line a stored-XSS hole, so it is
// written down as the thing that has to stay true.
checkSame('<script>alert(1)</script>', toPlainText('&lt;script&gt;alert(1)&lt;/script&gt;'),
          'encoded markup decodes to literal text and is never re-read as markup');

// ---- Tidying, so a paste does not arrive full of holes ------------------------
checkSame("Sale\nNow on", toPlainText("Sale   \nNow on"), 'trailing spaces on a line go');
checkSame("Sale\nNow on", toPlainText("Sale\t\nNow on"), 'tabs count as trailing space');
checkSame("a\n\nb", toPlainText("a\n\n\n\n\nb"), 'a run of blank lines collapses to one');
checkSame("a\n\nb", toPlainText("a\n\n\nb"), 'three newlines is already a run');
checkSame("a\n\nb", toPlainText("a\n\nb"), 'and one deliberate blank line is left alone');
checkSame('OPEN', toPlainText("\n  OPEN  \n"), 'the whole value is trimmed at the ends');
checkSame('', toPlainText('   '),
          'so a value that is only whitespace comes back empty — which is what refuses a blank save');
checkSame('0', toPlainText('0'), 'but "0" is content and survives, as it does everywhere else here');

// ---- All of it at once, which is what a real paste looks like -----------------
checkSame("Wings & Fries\n\n<10 pieces\n\n\$8.99",
          toPlainText("<div>Wings &amp; Fries</div>\n\n\n<div>&lt;10 pieces</div><br><b>\$8.99</b>   \n"),
          'a paste out of a browser arrives as the lines somebody typed');

// ---- A "<" somebody typed is a character, not the start of a deletion ---------
// strip_tags is not a parser. It enters tag mode at any "<" not followed by
// whitespace and, with nothing to close it, deletes the rest of the value — so
// "Kids <12 eat free" reached the sign as "Kids", on the way into the database,
// with nothing to see in the Builder and no error anywhere. The Builder reads a
// text block with innerText, so a typed "<" arrives literal and this is the whole
// path it takes.
checkSame('Kids <12 eat free', toPlainText('Kids <12 eat free'),
          'a "<" before a digit is a character somebody typed');
checkSame('Pints <16oz', toPlainText('Pints <16oz'), 'with or without a space after it');
checkSame('Beer <$4', toPlainText('Beer <$4'), 'and before punctuation');
checkSame('5 < 10 < 20', toPlainText('5 < 10 < 20'), 'more than one of them in a line');
checkSame('Sale <best value', toPlainText('Sale <best value'),
          'and even before a letter, when nothing ever closes it — no tag can span the end of the value');
checkSame('<', toPlainText('<'), 'a "<" on its own is still a "<"');

// The other side of that boundary, which is what makes it a boundary: everything a
// browser would read as a tag is still taken away. The rule is one regex used in
// one place, because two opinions about "is this markup?" is how this comes back.
checkSame('OPEN', toPlainText('<b>OPEN</b>'), 'a real tag is still stripped');
checkSame('alert(1)', toPlainText('<script>alert(1)</script>'), 'and so is a script');
checkSame('', toPlainText('<img src=x onerror="steal()">'),
          'including one whose attributes are full of quotes and brackets');
checkSame('', toPlainText('<!-- a comment -->'), 'comments go');
checkSame('', toPlainText('<?php echo 1; ?>'), 'and so does anything shaped like PHP');
checkSame('acd', toPlainText('a<b>c</b>d'), 'a tag mid-sentence takes only itself');
checkSame('OPEN', toPlainText('<B>OPEN</B>'),
          'a tag shouted in capitals is the same tag — HTML does not care and neither does this');

// The two live together in one value, which is where the boundary has to hold: the
// "<" that opens nothing is kept, the one that opens something is taken, and the
// search for the closing ">" does not run past the next "<" looking for one.
checkSame('Sale <best and bold', toPlainText('Sale <best and <b>bold</b>'),
          'an unclosed "<" and a real tag in the same line are told apart');
checkSame("Half rack\nFull rack", toPlainText('<div>Half rack</div><div>Full rack</div>'),
          'and the break rewrites still see the tags they are looking for');

// ─────────────────────────────────────────────────────────────
section('A basic publish may return root content, never invent it');

// The residual §4ab named and deferred, saying it needed a payload change rather than
// a check. A `text` block with no parent_temp_id lands with section_id NULL, which is
// layout — and placing layout is not what this role does (ADR-0005). The type
// allowlist closed the forged-`type` route in; a plain text block with no parent still
// walked through. The payload now says which root blocks it is *returning*: content
// carries db_id the way sections always have.

// Two more Displays on the same fixture — the sections after this one still expect
// drive-thru and lobby as they left them.
$sign  = makeTestDisplay($pdo, 'butcher', 'Butcher Counter');
$other = makeTestDisplay($pdo, 'bakery', 'Bakery');

// An admin lays down a section and one block outside it — the logo-on-the-canvas case
// that makes refusing every root block the wrong fix.
$withRoot = [
    ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 10, 'y_pos' => 20, 'width' => 600, 'height' => 380],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => 'Inside', 'width' => 160, 'height' => 60],
    ['type' => 'image', 'manual_content' => 'uploads/logo.png', 'width' => 200, 'height' => 80],
];
$res = publishAs($layouts, $sign, $withRoot, $sign->layoutStamp(), true, 1);
check($res->isOk(), 'an admin may place content outside every section');

$rootId = 0;
$sectionId = 0;
foreach (elementsOf($pdo, $sign->id()) as $row) {
    if ($row['type'] === 'section') { $sectionId = intval($row['id']); }
    if ($row['type'] === 'image' && $row['section_id'] === null) { $rootId = intval($row['id']); }
}
check($rootId > 0, 'and it is stored at root level, as layout');

/** What a basic account's Builder sends: real ids on the section and on root blocks. */
function basicWithRoot($sectionId, $rootId, $extra = [])
{
    return [
        ['type' => 'section', 'temp_id' => 's1', 'db_id' => $sectionId],
        ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => 'Clerk price'],
        array_merge(['type' => 'image', 'db_id' => $rootId ?: null,
                     'manual_content' => 'uploads/logo.png'], $extra),
    ];
}

$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, basicWithRoot($sectionId, $rootId), $sign->layoutStamp(), false, 2);
check($res->isOk(), 'a basic account may send that root block back');
$stillThere = 0;
foreach (elementsOf($pdo, $sign->id()) as $row) {
    if ($row['section_id'] === null && $row['type'] === 'image') { $stillThere = intval($row['id']); }
}
checkSame($rootId, $stillThere, 'and it keeps the id it had, so the next publish can name it too');

// Which is the point of keeping it: publishing twice from one tab, without reloading,
// is ordinary work — and would be refused if every id went stale on success.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, basicWithRoot($sectionId, $rootId), $sign->layoutStamp(), false, 2);
check($res->isOk(), 'so the same tab can publish again without reloading');

// The hole itself.
$sign = loadTestDisplay($pdo, $sign->id());
$invented = [
    ['type' => 'section', 'temp_id' => 's1', 'db_id' => $sectionId],
    ['type' => 'text', 'manual_content' => 'Invented at root'],
];
$before = count(elementsOf($pdo, $sign->id()));
$beforeStamp = $sign->layoutStamp();
$res = publishAs($layouts, $sign, $invented, $beforeStamp, false, 2);
checkSame('invalid', $res->kind(), 'a root block this account invented is refused');
checkMentions($res->message(), 'not inside any section', 'and the refusal says what is wrong with it');
checkMentions($res->message(), 'admin', 'and who can do it instead');
$sign = loadTestDisplay($pdo, $sign->id());
checkSame($before, count(elementsOf($pdo, $sign->id())), 'the refusal deleted nothing');
checkSame($beforeStamp, $sign->layoutStamp(), 'and did not advance the stamp');

// One row cannot be two blocks: returning the same id twice is inventing one of them.
$twice   = basicWithRoot($sectionId, $rootId);
$twice[] = ['type' => 'image', 'db_id' => $rootId, 'manual_content' => 'uploads/logo.png'];
$res = publishAs($layouts, $sign, $twice, $sign->layoutStamp(), false, 2);
checkSame('invalid', $res->kind(), 'and the same root id returned twice is refused');

// A root id belonging to another Display is not this Display's to return.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $other, [
    ['type' => 'image', 'db_id' => $rootId, 'manual_content' => 'uploads/logo.png'],
], loadTestDisplay($pdo, $other->id())->layoutStamp(), false, 2);
checkSame('invalid', $res->kind(), 'a root id from another Display is refused');

// Deleting still deletes. The alternative fix — preserve every root row a basic
// publish does not mention — would have made this a silent no-op reporting success.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => 's1', 'db_id' => $sectionId],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => 'Only this'],
], $sign->layoutStamp(), false, 2);
check($res->isOk(), 'a basic publish that leaves the root block out is accepted');
$rootRows = 0;
foreach (elementsOf($pdo, $sign->id()) as $row) {
    if ($row['section_id'] === null && $row['type'] !== 'section') { $rootRows++; }
}
checkSame(0, $rootRows, 'and the root block is gone — a delete, not a no-op that says success');

// ─────────────────────────────────────────────────────────────
section('Hide and delete cannot cross Displays');

$driveT = loadTestDisplay($pdo, $driveT->id());
$lobby  = loadTestDisplay($pdo, $lobby->id());
$lobbyElement = elementsOf($pdo, $lobby->id())[0];

checkSame('not_found', $layouts->setElementHidden($driveT, $lobbyElement['id'], true, 1)->kind(),
    'hiding another Display\'s element is refused');
$reread = $pdo->query("SELECT hidden FROM canvas_elements WHERE id = " . intval($lobbyElement['id']))->fetchColumn();
checkSame(0, intval($reread), 'and it stays visible');

checkSame('not_found', $layouts->deleteElement($driveT, $lobbyElement['id'], 1)->kind(),
    'deleting another Display\'s element is refused');
$reread = $pdo->query("SELECT COUNT(*) FROM canvas_elements WHERE id = " . intval($lobbyElement['id']))->fetchColumn();
checkSame(1, intval($reread), 'and it still exists');

$ownElement = elementsOf($pdo, $driveT->id())[1];
$stampBefore = loadTestDisplay($pdo, $driveT->id())->layoutStamp();
checkSame(true, $layouts->setElementHidden($driveT, $ownElement['id'], true, 1)->isOk(), 'hiding an own element works');
$stampAfter = loadTestDisplay($pdo, $driveT->id())->layoutStamp();
check($stampAfter !== $stampBefore, 'hiding an element advances the stamp, so an open Builder cannot undo it');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWith('After hide'), $stampBefore);
checkSame('stale', $res->kind(), 'a Builder holding the pre-hide stamp is refused');

// Decision #42. The Builder's own visibility box writes nothing when it is ticked
// — the change rides out on the next publish, like everything else on that canvas.
// So a publish has to carry it, on a section as well as on a block, and it has to
// carry the way back: unticking is the only route out of hidden the Builder has.
$driveT = loadTestDisplay($pdo, $driveT->id());
$withHidden = layoutWith('Sunday only', 's-hide');
$withHidden[0]['hidden'] = 1;
$withHidden[1]['hidden'] = 1;
$res = publishAs($layouts, $driveT, $withHidden, $driveT->layoutStamp());
check($res->isOk(), 'a layout with a hidden section publishes');
$hiddenByType = [];
foreach (elementsOf($pdo, $driveT->id()) as $row) { $hiddenByType[$row['type']] = intval($row['hidden']); }
checkSame(1, $hiddenByType['section'], 'the section is stored hidden');
checkSame(1, $hiddenByType['text'],    'and so is the block inside it');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWith('Sunday only', 's-hide'), $driveT->layoutStamp());
check($res->isOk(), 'publishing the same layout with the box unticked works');
$hiddenByType = [];
foreach (elementsOf($pdo, $driveT->id()) as $row) { $hiddenByType[$row['type']] = intval($row['hidden']); }
checkSame(0, $hiddenByType['section'], 'the section is on the screens again');
checkSame(0, $hiddenByType['text'],    'and so is the block — the way back the Builder never had');

// Deleting a section takes its children with it, within one Display only.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWith('Cascade check'), $driveT->layoutStamp());
check($res->isOk(), 'republished for the cascade check');
$sectionId = 0;
foreach (elementsOf($pdo, $driveT->id()) as $row) { if ($row['type'] === 'section') { $sectionId = intval($row['id']); } }
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame(true, $layouts->deleteElement($driveT, $sectionId, 1)->isOk(), 'deleting a section works');
checkSame(0, count(elementsOf($pdo, $driveT->id())), 'its children went with it');
checkSame(2, count(elementsOf($pdo, $lobby->id())), 'lobby is still intact');

// ─────────────────────────────────────────────────────────────
section('Background intents');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWith('bg'), $driveT->layoutStamp(), true, 1, Background::color('#123456'));
check($res->isOk(), 'admin publishes a colour background');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame('color', $driveT->backgroundType(), 'background type is colour');
checkSame('#123456', $driveT->backgroundValue(), 'background colour is stored');

$res = publishAs($layouts, $driveT, layoutWith('bg2'), $driveT->layoutStamp(), true, 1, Background::image('uploads/bg_1.png'));
check($res->isOk(), 'admin publishes an image background');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame('image', $driveT->backgroundType(), 'background type is image');
checkSame('uploads/bg_1.png', $driveT->backgroundValue(), 'the image path is stored');

$res = publishAs($layouts, $driveT, layoutWith('bg3'), $driveT->layoutStamp(), true, 1, Background::keepImage());
check($res->isOk(), 'admin publishes with no new image file');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame('uploads/bg_1.png', $driveT->backgroundValue(), 'the existing image path is preserved');

$res = publishAs($layouts, $driveT, basicLayoutFor($pdo, $driveT, 'bg4'), $driveT->layoutStamp(), false, 2, Background::color('#ffffff'));
check($res->isOk(), 'a basic account publishes');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame('uploads/bg_1.png', $driveT->backgroundValue(), 'a basic account cannot change the background');

// ─────────────────────────────────────────────────────────────
section('A colour that is not a colour is not stored');

// Decision #24. The admin panel put every colour through a `#rrggbb` test and the
// publish endpoint put none of them through anything, so `bg_val` — a column the
// Viewer, the Builder and the panel's colour picker all assume is six hex digits
// — could be set to any string at all by a publish.
checkSame(true,  Background::isValidColor('#1a2b3c'), 'six hex digits behind a hash is a colour');
checkSame(true,  Background::isValidColor('#AABBCC'), 'in either case');
checkSame(false, Background::isValidColor('#fff'),    'three digits is not the form this column holds');
checkSame(false, Background::isValidColor('red'),     'nor is a name a browser would accept');
checkSame(false, Background::isValidColor('url(//elsewhere.example/x.svg)'), 'nor anything that is not a colour at all');
checkSame(false, Background::isValidColor(''),        'nor nothing');

checkSame('color', Background::color('#AABBCC')->kind(), 'a readable colour builds a colour intent');
checkSame('#aabbcc', Background::color('#AABBCC')->value(), 'stored one way, so two spellings cannot look different');
checkSame(Background::INVALID, Background::color('red')->kind(),
          'and an unreadable one builds an intent that names no colour');
checkSame(false, Background::color('red')->isUsable(), 'which is not usable');
checkSame(true,  Background::unchanged()->isUsable(), 'leaving the background alone always is');
checkSame(true,  Background::keepImage()->isUsable(), 'so is switching back to the stored image');
checkSame(true,  Background::image('uploads/bg_1.png')->isUsable(), 'and so is an image path');

// The whole publish is refused, not just the background: dropping the one change
// the admin made and reporting success is the merge invariant 5 forbids.
$driveT   = loadTestDisplay($pdo, $driveT->id());
$before   = $driveT->backgroundValue();
$stampWas = $driveT->layoutStamp();
$res = publishAs($layouts, $driveT, layoutWith('junk bg'), $stampWas, true, 1,
                 Background::color('url(//elsewhere.example/x.svg)'));
checkSame(false, $res->isOk(), 'a publish carrying an unreadable colour is refused');
checkSame('invalid', $res->kind(), 'as something no reload and no waiting will fix');
checkMentions($res->message(), 'still on screen', 'and the editor is told their work is not lost');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame($before,   $driveT->backgroundValue(), 'the background it would have overwritten is untouched');
checkSame($stampWas, $driveT->layoutStamp(),     'and nothing was published, so no Builder is invalidated');

// The write side agrees with the checker, so a caller that skipped the check still
// cannot store one.
$store->applyBackground($driveT, Background::color('transparent'));
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame($before, $driveT->backgroundValue(),
          'and the store declines to write one even when asked directly');

// The other door now refuses too (#21). It used to coerce — store the default and
// report success — and this block asserted that, so that the difference between the
// two doors was deliberate and visible rather than assumed. There is no difference
// left to assert, so what it checks is the refusal: nothing stored, and said so.
$panelSign = makeTestDisplay($pdo, 'panel-bg', 'Panel Colour');
$admin     = new DisplayAdmin($pdo, $store, $layouts, new GrantStore($pdo), new BrandStore($pdo));
$panelWas  = loadTestDisplay($pdo, $panelSign->id())->backgroundValue();
$res = $admin->setBackgroundColor($panelSign, 'nonsense');
checkSame(false, $res->isOk(), 'the admin panel refuses a colour it cannot read');
checkSame($panelWas, loadTestDisplay($pdo, $panelSign->id())->backgroundValue(),
          'and leaves the background exactly as it was, rather than substituting a default');
checkMentions($res->message(), 'Nothing was changed',
              'and says so, because a save that silently stored something else was the defect');

$admin->setBackgroundColor(loadTestDisplay($pdo, $panelSign->id()), '#ABCDEF');
checkSame('#abcdef', loadTestDisplay($pdo, $panelSign->id())->backgroundValue(),
          'a readable one is stored lowercased, by the same rule the publish path uses');

// And this is what "the same rule" is worth. Should this door's idea of a colour
// ever drift from Background's — a second regex that accepts three-digit hex, say
// — the panel would accept the value, the store would decline to write it, and the
// admin would be told it saved. Asking rather than restating is what stops that,
// and this is the check that notices if it stops being asked.
$admin->setBackgroundColor(loadTestDisplay($pdo, $panelSign->id()), '#fff');
checkSame('#abcdef', loadTestDisplay($pdo, $panelSign->id())->backgroundValue(),
          'a spelling the module refuses is refused here too, leaving what was already stored');

// ─────────────────────────────────────────────────────────────
section('The snapshot a Screen renders');

$driveT = loadTestDisplay($pdo, $driveT->id());
$snapshot = $layouts->snapshot($driveT);

checkSame($driveT->layoutStamp(), $snapshot['layout_stamp'], 'the snapshot carries the stamp the Builder must hold');
checkSame(1920, $snapshot['display']['canvas_width'], 'the snapshot carries the canvas size the Viewer and Builder size themselves from');
checkSame('image', $snapshot['display']['bg_type'], 'the Display carries the background');
check(!isset($snapshot['settings']), 'the transitional `settings` alias is gone (Phase 2)');
check(isset($snapshot['block_styles']['price']), 'shared Brand Standards typography is included');

$onlyMine = true;
foreach ($snapshot['elements'] as $row) {
    if (intval($row['display_id']) !== $driveT->id()) { $onlyMine = false; }
}
check($onlyMine, 'the snapshot contains only this Display\'s elements');

$lobbySnapshot = $layouts->snapshot(loadTestDisplay($pdo, $lobby->id()));
checkSame(1080, $lobbySnapshot['display']['canvas_width'], 'a portrait Display reports its own dimensions');
check(count($lobbySnapshot['elements']) === 2, 'and its own elements');

// ─────────────────────────────────────────────────────────────
section('Suggesting a screen name tag from a title');

checkSame('lobby-screen', DisplayStore::suggestTag('Lobby Screen'), 'spaces become hyphens');
checkSame('lobby-screen-2', DisplayStore::suggestTag('  Lobby Screen #2! '), 'punctuation collapses to one hyphen');
checkSame('drive-thru', DisplayStore::suggestTag('Drive-Thru'), 'an existing hyphen survives');
checkSame('', DisplayStore::suggestTag('!!!'), 'a title with nothing usable suggests nothing, rather than inventing a tag');
checkSame(32, strlen(DisplayStore::suggestTag(str_repeat('long title ', 8))), 'a long title is cut to the tag limit');
check(DisplayStore::isValidTag(DisplayStore::suggestTag(str_repeat('long title ', 8))), 'and what it cuts to is still valid');

checkSame(true,  DisplayStore::isValidCanvasSize(1920, 1080), '1920×1080 is a valid canvas');
checkSame(true,  DisplayStore::isValidCanvasSize('1080', '1920'), 'digits as strings are accepted');
checkSame(false, DisplayStore::isValidCanvasSize(0, 1080),    'zero is not');
checkSame(false, DisplayStore::isValidCanvasSize(1920, 99999),'nor is a typo of an extra digit');
checkSame(false, DisplayStore::isValidCanvasSize('wide', 1080), 'nor is a word');

// ─────────────────────────────────────────────────────────────
section('Adding, editing, retiring and destroying a Display');

$pdo    = newTestDb();
$store  = new DisplayStore($pdo);
$admin  = newTestDisplayAdmin($pdo);
$layouts = newTestLayoutStore($pdo);

$res = $admin->create(['brand_id' => 1, 'title' => 'Drive-Thru', 'canvas_width' => 1920, 'canvas_height' => 1080]);
check($res->isOk(), 'a Display is created from a title and a canvas size alone');
$driveT = $res->display();
checkSame('drive-thru', $driveT->tag(), 'its tag was suggested from its title');
checkSame(1920, $driveT->canvasWidth(), 'it has the width it was given');
checkSame(true, $driveT->isActive(), 'it is active from the moment it exists');
check(strpos($res->message(), 'viewer.php?display=drive-thru') !== false,
      'and the confirmation gives the address to point a Screen at');

$res = $admin->create(['brand_id' => 1, 'title' => 'Second Drive-Thru', 'tag' => 'drive-thru',
                       'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(DisplayResult::CONFLICT, $res->kind(), 'a tag already in use is refused');
checkSame('tag', $res->field(), 'and the refusal names the field to fix');
checkSame(1, $store->count(), 'nothing was created');

$res = $admin->create(['brand_id' => 1, 'title' => '', 'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(DisplayResult::INVALID, $res->kind(), 'a Display with no title is refused');
$res = $admin->create(['brand_id' => 1, 'title' => 'Bad Tag', 'tag' => 'Lobby_1', 'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(DisplayResult::INVALID, $res->kind(), 'a tag with an underscore is refused');
$res = $admin->create(['brand_id' => 1, 'title' => 'No Size', 'canvas_width' => 0, 'canvas_height' => 0]);
checkSame(DisplayResult::INVALID, $res->kind(), 'a Display with no canvas size is refused');
checkSame('canvas_width', $res->field(), 'and the refusal points at the size');
checkSame(1, $store->count(), 'still nothing created');

// Give the drive-thru a layout worth duplicating: a section with a block inside.
$res = publishAs($layouts, $driveT, layoutWith('Drive-thru $9.99'), '0');
check($res->isOk(), 'the drive-thru gets a layout to duplicate');
$driveT = loadTestDisplay($pdo, $driveT->id());

$res = $admin->create(['brand_id' => 1, 'title' => 'Portrait Board', 'canvas_width' => 1080, 'canvas_height' => 1920,
                       'duplicate_from' => 'drive-thru']);
checkSame(DisplayResult::INVALID, $res->kind(), 'duplicating into a different shape is refused (ADR-0004)');
check(strpos($res->message(), '1920 × 1080') !== false, 'and the refusal states the shape it would have copied');
checkSame(1, $store->count(), 'the Display was not created either');

$res = $admin->create(['brand_id' => 1, 'title' => 'Lobby', 'canvas_width' => 1920, 'canvas_height' => 1080,
                       'duplicate_from' => 'drive-thru']);
check($res->isOk(), 'duplicating from an identically sized Display works');
$lobby = $res->display();
check(strpos($res->message(), '2 elements copied') !== false, 'and the confirmation says how much was copied');

$lobbyRows = elementsOf($pdo, $lobby->id());
checkSame(2, count($lobbyRows), 'the copy has the same number of elements');
checkSame(2, count(elementsOf($pdo, $driveT->id())), 'and the original still has its own');

$copiedSection = null; $copiedText = null;
foreach ($lobbyRows as $row) {
    if ($row['type'] === 'section') { $copiedSection = $row; } else { $copiedText = $row; }
}
check($copiedSection && $copiedText, 'the copy has both the section and the block');
checkSame(intval($copiedSection['id']), intval($copiedText['section_id']),
          'the block is parented into the copy\'s own section, not the original\'s');
checkSame('Drive-thru $9.99', $copiedText['manual_content'], 'the content came across');
checkSame(10, intval($copiedSection['x_pos']), 'and so did the positions');

$res = $admin->create(['brand_id' => 1, 'title' => 'Third', 'canvas_width' => 1920, 'canvas_height' => 1080,
                       'duplicate_from' => 'no-such-display']);
checkSame(DisplayResult::INVALID, $res->kind(), 'duplicating from a Display that does not exist is refused');

checkSame(false, $layouts->copyLayout($driveT, loadTestDisplay($pdo, $lobby->id())),
          'a layout is never copied over a Display that already has one');

// ---- Editing -----------------------------------------------------------------
checkSame('lobby', $lobby->tag(), 'the duplicate was tagged from its own title, not the original\'s');

$res = $admin->updateDetails($lobby, ['brand_id' => 1, 'title' => 'Lobby Screen', 'tag' => 'lobby-screen',
                                      'location' => 'Front entrance']);
check($res->isOk(), 'title, tag and location can be edited');
$lobby = $res->display();
checkSame('lobby-screen', $lobby->tag(), 'the tag changed');
checkSame('Front entrance', $lobby->location(), 'the location is stored');
check(strpos($res->message(), 'viewer.php?display=lobby-screen') !== false,
      'a rename says what address the Screen must be pointed at now');

$res = $admin->updateDetails($lobby, ['brand_id' => 1, 'title' => 'Lobby Screen', 'tag' => 'lobby-screen']);
check($res->isOk() && strpos($res->message(), 'address changed') === false,
      'saving without changing the tag does not claim the address changed');

$res = $admin->updateDetails($lobby, ['brand_id' => 1, 'title' => 'Lobby Screen', 'tag' => 'drive-thru']);
checkSame(DisplayResult::CONFLICT, $res->kind(), 'renaming onto another Display\'s tag is refused');
checkSame('lobby-screen', $store->forId($lobby->id())->tag(), 'and the tag is unchanged');

$res = $admin->updateDetails($lobby, ['brand_id' => 1, 'title' => 'Lobby', 'tag' => '']);
check($res->isOk() && $res->display()->tag() === 'lobby',
      'clearing the tag re-suggests it from the title rather than failing');
$lobby = $res->display();

// ---- Retiring ----------------------------------------------------------------
$res = $admin->setActive($lobby, false);
check($res->isOk(), 'a Display can be turned off');
$lobby = $res->display();
checkSame(false, $lobby->isActive(), 'and reports itself off');
checkSame(2, count(elementsOf($pdo, $lobby->id())), 'its layout is kept');
checkSame(2, count($layouts->snapshot($lobby)['elements']),
          'and the editing read still hands the Builder that layout — get_editor_layout');

$r = DisplayRequest::forViewing($store, ['display' => 'lobby']);
checkSame(DisplayResolution::INACTIVE, $r->kind(), 'a Screen showing it gets the notice');
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], newTestActor($pdo, 1, 'admin'));
check($r->isFound(), 'and it is still editable — which is why the Builder needs the editing read');

$lobby = $admin->setActive($lobby, true)->display();
checkSame(true, $lobby->isActive(), 'and it can be turned back on');

// ---- Destroying --------------------------------------------------------------
// Both Displays are granted to the clerk, so destroying one must take its grant
// with it: a row left pointing at a deleted Display is invisible and one id reuse
// away from granting a sign nobody assigned.
grantTestAccess($pdo, $lobby->id(), 2);
grantTestAccess($pdo, $driveT->id(), 2);
checkSame(2, count(allGrants($pdo)), 'the clerk has been assigned both Displays');

$res = $admin->destroy($lobby, 'lobbi', 1);
checkSame(DisplayResult::INVALID, $res->kind(), 'a mistyped tag does not delete a Display');
checkSame(2, $store->count(), 'both Displays are still there');
checkSame(2, count(elementsOf($pdo, $lobby->id())), 'and its layout is untouched');

// The confirm box reached by the same fold as the URL (#27). It always refused a
// list, because "Array" is not "lobby" — what it also did was raise a warning above
// the admin panel on the way to refusing.
$warned = null;
set_error_handler(function ($sev, $msg) use (&$warned) { $warned = $msg; return true; });
$res = $admin->destroy($lobby, ['lobby'], 1);
restore_error_handler();
checkSame(DisplayResult::INVALID, $res->kind(), 'a list typed into the delete confirm deletes nothing');
checkSame(null, $warned, 'and refuses quietly, without a cast warning above the page');
checkSame(2, $store->count(), 'both Displays are still there after that too');

$res = $admin->destroy($lobby, ' LOBBY ', 1);
check($res->isOk(), 'the typed tag is matched after trimming and lowercasing');
check(strpos($res->message(), '2 elements were deleted') !== false, 'and the confirmation says what was lost');
check(strpos($res->message(), '1 account\'s access') !== false,
      'and says the assignment went with it — the element count was never the whole cost (#19)');
checkSame(1, $store->count(), 'the Display is gone');
checkSame(0, count(elementsOf($pdo, $lobby->id())), 'its elements went with it');
checkSame(2, count(elementsOf($pdo, $driveT->id())), 'and the other Display kept every one of its own');
checkSame(2, count(allElements($pdo)), 'nothing was orphaned');

$grants = allGrants($pdo);
checkSame(1, count($grants), 'the grant on the deleted Display went with it');
checkSame($driveT->id(), intval($grants[0]['display_id']), 'and the surviving Display kept its own');

// The roadmap decided there is no "last Display" rule: an installation may have
// none, and the Builder says so rather than the panel refusing.
$res = $admin->destroy($store->forTag('drive-thru'), 'drive-thru', 1);
check($res->isOk(), 'the last Display can be deleted too');
checkSame(0, $store->count(), 'leaving none');
checkSame(0, count(allElements($pdo)), 'and no elements behind');
checkSame(0, count(allGrants($pdo)), 'and no grant pointing at a Display that is gone');

// ─────────────────────────────────────────────────────────────
section('Deleting a Display asks who is using it first (#19)');

// Deletion is the one change of reach that cannot free a lock and tell its holder:
// afterwards there is no row to free and no Display for their page to ask about. So
// it is the one that has to refuse instead. Everything below is about that refusal
// being real rather than advisory — checked at the module, because the panel's
// greyed-out button is a courtesy and a POST can arrive without it.

$pdo    = newTestDb();
$store  = newTestDisplayStore($pdo);
$admin  = newTestDisplayAdmin($pdo);
$layouts = newTestLayoutStore($pdo);

/**
 * A drive-thru with two elements and one assignment, whatever the last block left.
 *
 * Every block below starts from this rather than from the one before it. Not
 * tidiness: half of what is being tested here is a *refusal*, so a regression
 * leaves the Display standing where the test expected it gone — and the next
 * `makeTestDisplay('drive-thru')` then dies on the unique tag, or is handed a null
 * and throws a TypeError. Either way the suite reports the crash instead of the
 * cause, three blocks away from the line that actually broke.
 */
function freshDriveThru(PDO $pdo, LayoutStore $layouts)
{
    $pdo->exec("DELETE FROM displays WHERE tag = 'drive-thru'");
    $d = makeTestDisplay($pdo, 'drive-thru', 'Drive-Thru');
    publishAs($layouts, $d, layoutWith('Sockeye 18.99'), '0');
    grantTestAccess($pdo, $d->id(), 2);
    return loadTestDisplay($pdo, $d->id());
}

/** Everything a refused delete must have left exactly where it was. */
function stillThereAfterRefusal(DisplayStore $store, PDO $pdo, $displayId, $what)
{
    check($store->forId($displayId) !== null,      $what . ' — the Display is still there');
    checkSame(2, count(elementsOf($pdo, $displayId)), $what . ' — and its layout');
    checkSame(1, count(allGrants($pdo)),             $what . ' — and the assignment on it');
}

$driveT = freshDriveThru($pdo, $layouts);
checkSame(2, count(elementsOf($pdo, $driveT->id())), 'the Display starts with a layout to lose');

// ---- Somebody else is editing --------------------------------------------------
$driveT = $store->claimLock($driveT, 2);
checkSame(true, $driveT->lockState()->heldByOther(1), 'the clerk has it open');

$res = $admin->destroy($driveT, 'drive-thru', 1);
checkSame(DisplayResult::CONFLICT, $res->kind(),
          'an admin cannot delete a Display somebody else is editing, correct tag and all');
stillThereAfterRefusal($store, $pdo, $driveT->id(), 'a refused delete writes nothing');
check(strpos($res->message(), 'clerk') !== false, 'the refusal names who is editing');
check(strpos($res->message(), 'not published') !== false, 'and what deleting would cost them');
check(strpos($res->message(), '15 minutes') !== false,
      'and the way out that needs nobody — the idle window, quoted from LockState');
checkSame('', $res->field(),
          'it points at no input, because there is no input to fix');

// The order of the two gates, which is the whole of what an admin's next minute
// looks like: told who is editing now, or sent away to retype a tag and told then.
$res = $admin->destroy($driveT, 'wrong-tag', 1);
checkSame(DisplayResult::CONFLICT, $res->kind(),
          'the lock is answered before the typed tag, so a mistyped tag does not hide it');

// ---- Whose lock it is ----------------------------------------------------------
$driveT = $store->claimLock(freshDriveThru($pdo, $layouts), 2);
$res = $admin->destroy($driveT, 'drive-thru', 2);
check($res->isOk(), 'the holder deleting the Display they have open is not stopped — it is their own work');
checkSame(0, $store->count(), 'and it went');

// ---- A lock that has lapsed is nobody -------------------------------------------
$driveT = freshDriveThru($pdo, $layouts);
$driveT = $store->claimLock($driveT, 2);
ageTestLock($pdo, $driveT->id(), LockState::IDLE_LAPSE_SECONDS + 1);
$driveT = $store->forId($driveT->id());
$res = $admin->destroy($driveT, 'drive-thru', 1);
check($res->isOk(),
      'a Builder left open on a back-office monitor past the idle window does not block a deletion');

// ---- A holder who cannot sign in is nobody either --------------------------------
// The same rule as #22, reached from a third direction: a lock nobody can release
// must not be able to keep a Display alive forever either.
$driveT = freshDriveThru($pdo, $layouts);
$driveT = $store->claimLock($driveT, 2);
checkSame(true, $driveT->lockState()->heldByOther(1), 'the clerk holds it again');
$pdo->exec("UPDATE users SET is_active = 0 WHERE id = 2");
$res = $admin->destroy($store->forId($driveT->id()), 'drive-thru', 1);
check($res->isOk(), 'a lock held by an account that can no longer sign in does not block one either');
$pdo->exec("UPDATE users SET is_active = 1 WHERE id = 2");

// ---- The check inside the transaction --------------------------------------------
// The argument is deliberately stale here: read while the Display was free, passed
// after somebody took it. That is the state a form submitted a minute ago is in, and
// it is the reason destroy() re-reads rather than trusting what it was handed.
$stale = freshDriveThru($pdo, $layouts);
checkSame(false, $stale->lockState()->isHeld(), 'the Display was free when this copy of it was read');

$store->claimLock($stale, 2);
$res = $admin->destroy($stale, 'drive-thru', 1);
checkSame(DisplayResult::CONFLICT, $res->kind(),
          'a lock taken after the form was drawn still refuses — the row is re-read inside the transaction');
stillThereAfterRefusal($store, $pdo, $stale->id(), 'and that refusal writes nothing either');

// ---- Re-read finds it already gone -----------------------------------------------
// Two admins on the delete button at once. The second one is told, rather than
// running three deletes against a row that is not there and reporting success.
$pdo->exec("DELETE FROM displays WHERE tag = 'drive-thru'");
$twice = makeTestDisplay($pdo, 'drive-thru', 'Drive-Thru');
check($admin->destroy($twice, 'drive-thru', 1)->isOk(), 'the first admin deletes it');
$res = $admin->destroy($twice, 'drive-thru', 1);
checkSame(DisplayResult::FAILED, $res->kind(), 'the second is told it no longer exists');
check(strpos($res->message(), 'Nothing was changed') !== false, 'and that nothing happened');

// ─────────────────────────────────────────────────────────────
section('Grants decide which Displays are an account\'s');

$pdo    = newTestDb();
$store  = new DisplayStore($pdo);
$admin  = newTestDisplayAdmin($pdo);
$grants = new GrantStore($pdo);
$layouts = newTestLayoutStore($pdo);

$driveT = makeTestDisplay($pdo, 'drive-thru', 'Drive-Thru');
$lobby  = makeTestDisplay($pdo, 'lobby', 'Lobby');
$deli   = makeTestDisplay($pdo, 'deli', 'Deli Case');

// Accounts 1 (admin) and 2 (clerk) come from the fixture; jane is a second basic
// account, so that a write covering one account can be shown not to touch another.
$janeId = makeTestAccount($pdo, 'jane', 'basic');

$asAdmin = newTestActor($pdo, 1, 'admin');
$asClerk = newTestActor($pdo, 2, 'basic');

// ADR-0005: admins hold every Display by role, and are never granted one.
checkSame(true, $asAdmin->mayEdit($lobby), 'an admin holds a Display with no grant at all');
checkSame(3, count($asAdmin->openable($store->all())), 'and may open every one of them');
checkSame(0, count(allGrants($pdo)), 'without a single grant row existing');

// A basic account starts with nothing. Not "everything by default, minus" — the
// empty table is the safe state.
checkSame(0, count($asClerk->openable($store->all())), 'a basic account with no grants holds nothing');
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], $asClerk);
checkSame(DisplayResolution::FORBIDDEN, $r->kind(), 'and naming one is refused');
check(strpos($r->message(), 'has not been assigned to you') !== false,
      'with a message that sends them to an admin rather than hunting for a typo');
check($r->display() !== null, 'the refusal still carries the Display it was about');

$grants->grant($lobby->id(), 2);
$asClerk = newTestActor($pdo, 2, 'basic');
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], $asClerk);
check($r->isFound(), 'a granted Display opens');
$r = DisplayRequest::forEditing($store, ['display' => 'deli'], $asClerk);
checkSame(DisplayResolution::FORBIDDEN, $r->kind(), 'one grant is one Display, not a role change');

// The done-when of this phase: a publish forged to name a Display the account was
// never given is refused in the seam, before any layout code runs — every endpoint
// resolves this way, so none of them needs its own check.
$before = count(elementsOf($pdo, $deli->id()));
$forged = DisplayRequest::forEditing(
    $store,
    ['display' => 'deli', 'layout_data' => '[]', 'layout_stamp' => $deli->layoutStamp()],
    $asClerk
);
checkSame(DisplayResolution::FORBIDDEN, $forged->kind(), 'a forged publish naming another Display is refused');
checkSame($before, count(elementsOf($pdo, $deli->id())), 'and that Display is untouched');

// A grant is permission to publish, too (ADR-0005): one that cannot reach a Screen
// would be no permission at all. An admin lays the section down first, because a
// clerk fills sections rather than creating them — which is the same rule the refusal
// below enforces, seen from the working side.
$lobby = loadTestDisplay($pdo, $lobby->id());
$res = publishAs($layouts, $lobby, layoutWith('Admin section'), $lobby->layoutStamp(), true, 1);
check($res->isOk(), 'an admin publishes the section a clerk will fill');

$lobby = loadTestDisplay($pdo, $lobby->id());
$res = publishAs($layouts, $lobby, basicLayoutFor($pdo, $lobby, 'Granted publish'), $lobby->layoutStamp(), false, 2);
check($res->isOk(), 'and a granted basic account may publish to it');

// The entry rule, generalised: the one Display *this account* may open. A clerk
// with a single grant never sees a picker, whatever else exists.
$r = DisplayRequest::forEditing($store, [], $asClerk);
check($r->isFound() && $r->display()->tag() === 'lobby',
      'no tag resolves to the account\'s only openable Display, not the installation\'s');
checkSame(1, count($asClerk->openable($store->all())),
          'which is exactly what the picker would have offered — one list, one rule');

$grants->grant($deli->id(), 2);
$asClerk = newTestActor($pdo, 2, 'basic');
$r = DisplayRequest::forEditing($store, [], $asClerk);
checkSame(DisplayResolution::NO_TAG, $r->kind(),
          'a second grant means a write with no tag is refused rather than guessed');

// Retiring and granting are independent axes, and both are checked here.
$pdo->exec("UPDATE displays SET is_active = 0 WHERE tag = 'lobby'");
$asClerk = newTestActor($pdo, 2, 'basic');
$openable = $asClerk->openable($store->all());
checkSame(1, count($openable), 'a retired Display drops off a basic account\'s list');
checkSame('deli', $openable[0]->tag(), 'leaving the one still in service');
checkSame(2, count($asClerk->granted($store->all())),
          'though it is still theirs — which is how the Builder tells "none assigned" from "yours is off"');
checkSame(3, count($asAdmin->openable($store->all())), 'an admin can still open the retired one');
$pdo->exec("UPDATE displays SET is_active = 1 WHERE tag = 'lobby'");

// ---- The matrix ---------------------------------------------------------------
// The third argument is the form's columns — the Displays it rendered. Every check
// here submits all three, which is what the panel does when nothing changed under it.
$allColumns = [$driveT->id(), $lobby->id(), $deli->id()];
$grants->grant($driveT->id(), $janeId);

$res = $admin->setAccess([2], $allColumns, [2 => [$lobby->id(), $driveT->id()]]);
check($res->isOk(), 'the access matrix saves');
checkSame([$driveT->id(), $lobby->id()], (new GrantStore($pdo))->displayIdsFor(2),
          'the account ends up holding exactly what was ticked');
checkSame([$driveT->id()], (new GrantStore($pdo))->displayIdsFor($janeId),
          'and an account the save did not cover keeps what it had');

$res = $admin->setAccess([2], $allColumns, [2 => [$lobby->id(), $driveT->id()]]);
check($res->isOk() && strpos($res->message(), 'unchanged') !== false,
      'saving the same matrix again says nothing changed');
checkSame(3, count(allGrants($pdo)), 'and does not duplicate a grant');

$res = $admin->setAccess([2], $allColumns, [2 => [$lobby->id(), 99999]]);
check($res->isOk(), 'an id naming no Display is dropped rather than failing the whole save');
checkSame([$lobby->id()], (new GrantStore($pdo))->displayIdsFor(2), 'the real one is kept');
check(strpos($res->message(), 'no longer open that display') !== false,
      'and a revoke says that whoever lost access can no longer open that display');

$res = $admin->setAccess([2], $allColumns, []);
check($res->isOk(), 'an account with nothing ticked is allowed');
checkSame([], (new GrantStore($pdo))->displayIdsFor(2), 'and holds nothing');
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], newTestActor($pdo, 2, 'basic'));
checkSame(DisplayResolution::FORBIDDEN, $r->kind(), 'so the Display it was editing is refused again');

// An admin can never be granted anything through this path — the panel passes
// `basic` accounts only, and nothing here would make a difference if it did.
$res = $admin->setAccess([1], $allColumns, [1 => [$lobby->id()]]);
check($res->isOk(), 'granting an admin is accepted');
checkSame(true, newTestActor($pdo, 1, 'admin')->mayEdit($deli),
          'and changes nothing: an admin already held every Display');

// An account being deleted takes its grants with it, the same way a Display does.
checkSame(1, $grants->revokeAllForAccount($janeId), 'closing an account revokes its grants');
checkSame([], (new GrantStore($pdo))->displayIdsFor($janeId), 'leaving it holding nothing');

// ─────────────────────────────────────────────────────────────
section('The same grants read three ways, and the actor built from them (#50)');

// Everything above this line is about whether an account may reach a sign, and it is
// thorough about it. What `tools/mutate.php` found is that the module answering that
// question has ten lines the suite could not fail on, and they are not the obscure
// ones: both grouped reads could be emptied out, granting twice could stop being
// idempotent, and a session arriving with no id could become **account 1** — which is
// the admin. Each check here was written against a mutant and verified to fail on it,
// which is the whole distinction #50 is about (§4aq).
//
// A database of its own, because the first four checks need a grant table with
// nothing in it, and every section above has left rows in the one it was using.
$gPdo    = newTestDb();
$gStore  = new DisplayStore($gPdo);
$gGrants = new GrantStore($gPdo);
$gLobby  = makeTestDisplay($gPdo, 'lobby', 'Lobby');
$gDeli   = makeTestDisplay($gPdo, 'deli', 'Deli Case');
$gJane   = makeTestAccount($gPdo, 'jane', 'basic');

// The two grouped reads on an installation with no grants. This is a fresh install
// with the matrix page open, and it is the state where the *shape* of the answer
// matters most: the panel iterates whatever comes back, so `null` and `[]` are the
// difference between an empty table and a diagnostic above the document.
checkSame([], $gGrants->displayIdsByAccount(), 'no grants groups by account as an empty list, not as nothing');
checkSame([], $gGrants->accountIdsByDisplay(), 'and by Display the same way');

$gGrants->grant($gLobby->id(), 2);
$gGrants->grant($gDeli->id(), 2);
$gGrants->grant($gLobby->id(), $gJane);

// Grouped by row and by column — the two axes of the matrix the panel draws. Nothing
// had ever asserted the contents of either, so the whole body of both loops could be
// deleted and the suite still passed: the admin panel would have drawn an empty
// matrix over a full table, and a save from that page reads its own checkboxes.
$byAccount = $gGrants->displayIdsByAccount();
checkSame([2, $gJane], array_keys($byAccount), 'every account holding a grant is a row, in account order');
checkSame([$gLobby->id(), $gDeli->id()], $byAccount[2], 'with the Displays it holds under it');
checkSame([$gLobby->id()], $byAccount[$gJane], 'and an account holding one holds exactly one');

$byDisplay = $gGrants->accountIdsByDisplay();
checkSame([$gLobby->id(), $gDeli->id()], array_keys($byDisplay), 'and every granted Display is a column');
checkSame([2, $gJane], $byDisplay[$gLobby->id()], 'listing everyone who may edit that sign');
checkSame([2], $byDisplay[$gDeli->id()], 'which is not everyone who may edit another');

// The docblock on grant() says already-granted is success rather than an error, and
// until now nothing had ever granted the same pair twice. Removing that guard leaves
// two rows for one permission — which revoke() then removes both of, so the bug is
// invisible from every direction except the matrix's own count.
$gGrants->grant($gLobby->id(), 2);
checkSame(3, count(allGrants($gPdo)), 'granting a Display an account already holds adds no second row');
checkSame([$gLobby->id(), $gDeli->id()], $gGrants->displayIdsFor(2), 'and does not list it twice either');

// ---- The actor's own fields ---------------------------------------------------
// Who is asking, which the lock then names on a colleague's screen. `mayEdit()` and
// `openable()` are exercised hard above; the three lines that *build* the object were
// not exercised at all.
$gClerk = Actor::signedIn(['id' => '2', 'username' => 'sam', 'role' => 'basic'], $gGrants);
checkSame(2, $gClerk->id(), 'the actor carries the account id, as an int');
checkSame('sam', $gClerk->username(), 'and the username, which is what a lock names on somebody else\'s screen');
checkSame(false, $gClerk->isAdmin(), 'and the role it was given');

// The one that would have hurt. A session missing its id falls back to a literal, and
// the literal is 0 — no account has that number. One digit up is the id the installer
// creates the first admin with, so the fallback would have handed a malformed session
// the account that holds every Display by role.
$gNoId = Actor::signedIn(['username' => 'sam', 'role' => 'basic'], $gGrants);
checkSame(0, $gNoId->id(), 'a session with no id is account 0, which is nobody');
checkSame(false, $gNoId->isAdmin(), 'and is not an admin');
checkSame([], $gNoId->granted($gStore->all()), 'and holds nothing, because no grant names account 0');
checkSame(false, $gNoId->holdsASign($gStore->all()), 'so the shared writes refuse it (#33)');

// withGrants() with an empty list — the shape #33 is about, built the other way. The
// list is normalised into a fresh array, and on an empty input that line is the only
// thing standing between this constructor and a TypeError.
$gEmpty = Actor::withGrants(7, 'nobody', false, []);
checkSame([], $gEmpty->openable($gStore->all()), 'an actor built with no grants opens nothing');
checkSame(false, $gEmpty->holdsASign($gStore->all()), 'and holds no sign');
$gTyped = Actor::withGrants(7, 'nobody', false, [(string)$gLobby->id()]);
checkSame(1, count($gTyped->granted($gStore->all())),
          'and a grant list arriving as strings is folded to ints, which is what makes the strict in_array safe');

// ─────────────────────────────────────────────────────────────
section('One editor per Display: the edit lock');

// Note: this section calls $layouts->publish() directly rather than publishAs(),
// because that helper releases the lock afterwards to model leaving the Builder —
// which is exactly the behaviour under test here.

$pdo     = newTestDb();
$store   = new DisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$driveT  = makeTestDisplay($pdo, 'drive-thru', 'Drive-Thru');
$lobby   = makeTestDisplay($pdo, 'lobby', 'Lobby');

check(LockState::WARN_AFTER_SECONDS < LockState::IDLE_LAPSE_SECONDS,
      'the holder is warned before the lock is released, not after');

// Nobody has opened it: free, and free means free for everyone.
$lock = $driveT->lockState();
checkSame(false, $lock->isHeld(), 'a Display nobody has opened is not locked');
checkSame(true,  $lock->isFree(), 'which is the same thing said the other way');
checkSame(false, $lock->heldBy(1), 'and is nobody\'s in particular');
checkSame(false, $lock->heldByOther(1), 'nor anybody else\'s');

// ---- Taking it ----------------------------------------------------------------
$d    = $store->claimLock($driveT, 1);
$lock = $d->lockState();
checkSame(true,  $lock->heldBy(1), 'opening a Display claims its lock');
checkSame(true,  $lock->heldByOther(2), 'which is what makes a second account read-only');
checkSame(false, $lock->heldByOther(1), 'the holder is never "somebody else"');
checkSame('sky', $lock->holderName(), 'the lock knows whose it is by name, for the banner');
check($lock->takenAtLabel() !== '', 'and since when');
check($lock->idleSeconds() < 5, 'a lock just taken is not idle');

$since = $lock->takenAtLabel();
$d     = $store->claimLock($driveT, 2);
checkSame(true, $d->lockState()->heldBy(1), 'a second account cannot take a lock that is being held');
checkSame(1,    $d->lockState()->holderId(), 'the holder is unchanged');

// A heartbeat is the same call, and keeps "editing since" where it was: it is when
// they started, not when they last clicked.
$d = $store->claimLock($driveT, 1);
checkSame(true,  $d->lockState()->heldBy(1), 'a heartbeat from the holder keeps the lock');
checkSame($since, $d->lockState()->takenAtLabel(), 'and does not restart "editing since"');

// ---- Held by work, not by presence --------------------------------------------
// The Builder reports the age of the last real interaction, so a tab that is still
// beating but nobody is touching still loses the Display on time.
$d = $store->claimLock($driveT, 1, 800);
check($d->lockState()->idleSeconds() >= 795,
      'a heartbeat records when the last interaction was, not when the beat arrived');
checkSame(true, $d->lockState()->isHeld(), 'inside the window it is still held');

$d = $store->claimLock($driveT, 1, LockState::IDLE_LAPSE_SECONDS + 60);
check($d->lockState()->idleSeconds() >= 795,
      'a beat from a tab idle past the window does not renew the lock');

// ---- Lapsing ------------------------------------------------------------------
ageTestLock($pdo, $driveT->id(), LockState::IDLE_LAPSE_SECONDS + 1);
$d = $store->forId($driveT->id());
checkSame(false, $d->lockState()->isHeld(), 'a lock idle past the window has lapsed');
checkSame(false, $d->lockState()->heldByOther(2), 'so nobody is blocked by it');
checkSame('',    $d->lockState()->holderName(), 'and it names nobody, though the row still does');

$d = $store->claimLock($driveT, 2);
checkSame(true, $d->lockState()->heldBy(2), 'a lapsed lock can be claimed by somebody else');

// Returning to a lapsed tab takes it back, if nobody filled the gap (ADR-0007).
$d = $store->claimLock($lobby, 1);
checkSame(true, $d->lockState()->heldBy(1), 'the other Display is claimed independently');
ageTestLock($pdo, $lobby->id(), LockState::IDLE_LAPSE_SECONDS + 1);
$d = $store->claimLock($lobby, 1);
checkSame(true, $d->lockState()->heldBy(1), 'and a lapsed lock is silently re-taken by its own holder');

// ---- Releasing ----------------------------------------------------------------
$d = $store->releaseLock($lobby, 1);
checkSame(false, $d->lockState()->isHeld(), 'leaving the Builder releases the lock');

$d = $store->claimLock($lobby, 1);
$d = $store->releaseLock($lobby, 2);
checkSame(true, $d->lockState()->heldBy(1),
          'a release from an account that is not the holder does nothing — a tab closing late '
          . 'must not free somebody else\'s lock');
$store->releaseLock($lobby, 1);

// ---- A publish and the lock ---------------------------------------------------
// drive-thru is account 2's at this point. Account 1 publishing is the collision
// the lock exists to catch: refused, and nothing written.
$driveT = $store->forId($driveT->id());
$before = count(elementsOf($pdo, $driveT->id()));
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('Published over somebody'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $driveT->layoutStamp()
));
checkSame('locked', $res->kind(), 'a publish is refused while somebody else holds the lock');
check(strpos($res->message(), 'clerk') !== false, 'the refusal names who is editing');
check(strpos($res->message(), 'still on screen') !== false,
      'and says the work is not lost, because it is not');
checkSame($before, count(elementsOf($pdo, $driveT->id())), 'and nothing was written');

// The holder's own publish goes through, and keeps the lock alive.
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('The holder publishes'), Background::unchanged(), BrandChoice::unchanged(), 2, true, $driveT->layoutStamp()
));
check($res->isOk(), 'the account holding the lock may publish');
$d = $store->forId($driveT->id());
checkSame(true, $d->lockState()->heldBy(2), 'and publishing keeps the lock — it is a real interaction');

// The lock refusal comes first: "reload and re-apply" is bad advice while somebody
// else is mid-edit, because re-applying would only be refused again.
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('Stale and locked out'), Background::unchanged(), BrandChoice::unchanged(), 1, true, 'nonsense-stamp'
));
checkSame('locked', $res->kind(), 'a publish that is both stale and locked out reports the lock');

// A lapsed lock nobody claimed does not stop the person who let it lapse.
ageTestLock($pdo, $driveT->id(), LockState::IDLE_LAPSE_SECONDS + 1);
$driveT = $store->forId($driveT->id());
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('Back after a break'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $driveT->layoutStamp()
));
check($res->isOk(), 'a publish onto a lapsed lock succeeds');
$d = $store->forId($driveT->id());
checkSame(true, $d->lockState()->heldBy(1), 'and takes the lock for whoever published');

// ---- An admin taking over -----------------------------------------------------
$d = $store->seizeLock($driveT, 2);
checkSame(true,  $d->lockState()->heldBy(2), 'an admin taking over gets the lock whoever held it');
checkSame(false, $d->lockState()->heldBy(1), 'and the previous holder no longer has it');

// The reason a takeover hands the lock over instead of clearing it: the ousted tab
// heartbeats, and a free lock would be claimed straight back within the minute.
$d = $store->claimLock($driveT, 1);
checkSame(true, $d->lockState()->heldBy(2), 'the ousted tab\'s next heartbeat cannot reclaim it');

$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('Publishing after being ousted'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $driveT->layoutStamp()
));
checkSame('locked', $res->kind(), 'and its publish is refused, which is how it finds out');

// ---- An account being deleted --------------------------------------------------
$store->claimLock($lobby, 2);
checkSame(2, $store->releaseLocksHeldBy(2), 'closing an account releases every lock it held');
checkSame(false, $store->forId($driveT->id())->lockState()->isHeld(), 'drive-thru is free again');
checkSame(false, $store->forId($lobby->id())->lockState()->isHeld(),  'and so is lobby');

// ---- The lock is not the Screens' business -------------------------------------
$store->claimLock($driveT, 1);
$snapshot = $layouts->snapshot($store->forId($driveT->id()));
checkSame(false, array_key_exists('lock_holder_id', $snapshot['display']),
          'a layout snapshot carries no lock holder — get_layout is public');
checkSame(false, array_key_exists('lock_activity_at', $snapshot['display']),
          'nor when they last touched it');

// ─────────────────────────────────────────────────────────────
section('A publish that cannot be read is refused, not treated as "delete everything"');

// The adapter line this replaces was `json_decode($raw, true) ?: []`, which reads
// "an unreadable request is an empty layout" — and publishing an empty layout
// deletes every element on the Display, advances the stamp, and returns ok, so the
// Builder said "Published" over a sign that had just gone blank. No undo.
$bg = Background::unchanged();
foreach ([
    ['{"broken": ',                'a truncated body'],
    ['[{"type":"text"',            'a body cut off mid-element'],
    ['["\ud83d"]',                 'an unpaired surrogate — text truncated mid-emoji'],
    ['5',                          'a bare number'],
    ['"a string"',                 'a bare string'],
    ['true',                       'a bare boolean'],
    ['null',                       'a literal null'],
    ['',                           'an empty body'],
] as $case) {
    checkSame(null, PublishRequest::fromPostedJson($case[0], $bg, BrandChoice::unchanged(), 1, true, '0'),
              $case[1] . ' is not a layout');
}

// The other half: an empty array really is a layout. Somebody who deleted every
// block and published meant it, and must not be refused.
$emptyReq = PublishRequest::fromPostedJson('[]', $bg, BrandChoice::unchanged(), 1, true, '0');
check($emptyReq !== null,               'an empty array is still a publish');
checkSame([], $emptyReq->elements(),    'and it carries no elements');

// End to end, on a Display that has something to lose.
$pdo     = newTestDb();
$store   = newTestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$victim  = makeTestDisplay($pdo, 'victim', 'Victim');
$layouts->publish($victim, new PublishRequest(
    layoutWith('Sockeye 18.99'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $victim->layoutStamp()
));
$victim = $store->forId($victim->id());
checkSame(2, count(elementsOf($pdo, $victim->id())), 'the Display starts with a published layout');

checkSame(null, PublishRequest::fromPostedJson('[{"type":', $bg, BrandChoice::unchanged(), 1, true, $victim->layoutStamp()),
          'and an unreadable publish for it never reaches the store');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'so its elements are still there');

// ─────────────────────────────────────────────────────────────
section('A hostile publish payload is a refusal, never an escaping error');

// toPlainText() is typed `string`, so manual_content arriving as an object raises a
// TypeError — which extends Error, not Exception. `catch (Exception)` let it escape
// *after* both DELETEs had run: no rollback of the module's own, no result object,
// and the Builder reported "Network error." for a rejected publish.
//
// Both of these now stop at LayoutRules instead, before the transaction opens, so
// the refusal is 'invalid' rather than 'failed' and it names the block. The
// surviving-layout checks are the ones that matter and they are unchanged: the
// point was never which kind came back, it was that two DELETEs did not run.
$victim = $store->forId($victim->id());
$res = $layouts->publish($victim, new PublishRequest(
    [['type' => 'text', 'manual_content' => ['not' => 'a string'], 'temp_id' => 't1']],
    Background::unchanged(), BrandChoice::unchanged(), 1, true, $victim->layoutStamp()
));
checkSame('invalid', $res->kind(), 'manual_content as an object is a refusal, not a fatal');
checkMentions($res->message(), 'Block 1 (text)', 'and the refusal says which block');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'and the layout it would have replaced survives');
checkSame(false, $pdo->inTransaction(), 'with no transaction left open behind it');

$victim = $store->forId($victim->id());
$res = $layouts->publish($victim, new PublishRequest(
    [['type' => 'section', 'temp_id' => ['an', 'array']]],
    Background::unchanged(), BrandChoice::unchanged(), 1, true, $victim->layoutStamp()
));
checkSame('invalid', $res->kind(), 'an array where a temp_id belongs is refused the same way');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'and again nothing was lost');

// ─────────────────────────────────────────────────────────────
section('The edit lock covers every element write, not just publishing');

$pdo     = newTestDb();
$store   = newTestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$sign    = makeTestDisplay($pdo, 'deli', 'Deli Case');
$layouts->publish($sign, new PublishRequest(
    layoutWith('Chowder 6.50'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $sign->layoutStamp()
));
$store->releaseLock($sign, 1);

// Account 2 is mid-edit. Account 1 is an admin in the Work Area.
$store->claimLock($store->forId($sign->id()), 2);
$sign    = $store->forId($sign->id());
$element = elementsOf($pdo, $sign->id())[1];
$stampBefore = $sign->layoutStamp();

$res = $layouts->setElementHidden($sign, $element['id'], true, 1);
checkSame('locked', $res->kind(), 'hiding an element is refused while someone else is editing');
check(strpos($res->message(), 'editing') !== false, 'and the refusal says who is editing it');
$reread = $pdo->query("SELECT hidden FROM canvas_elements WHERE id = " . intval($element['id']))->fetchColumn();
checkSame(0, intval($reread), 'the element is untouched');

$res = $layouts->deleteElement($sign, $element['id'], 1);
checkSame('locked', $res->kind(), 'and so is deleting one');
checkSame(2, count(elementsOf($pdo, $sign->id())), 'nothing was deleted');
checkSame($stampBefore, $store->forId($sign->id())->layoutStamp(),
          'a refused element write does not advance the stamp, so the holder can still publish');

// The holder themselves is not blocked by their own lock.
$res = $layouts->setElementHidden($store->forId($sign->id()), $element['id'], true, 2);
checkSame(true, $res->isOk(), 'the account holding the lock can still hide its own element');

// A lapsed lock is free, here as everywhere else.
ageTestLock($pdo, $sign->id(), LockState::IDLE_LAPSE_SECONDS + 60);
$res = $layouts->deleteElement($store->forId($sign->id()), $element['id'], 1);
checkSame(true, $res->isOk(), 'once the lock has lapsed the Work Area can delete again');

// Deleting a section takes its children even with no cascade behind it — the FK
// that would do it is the one lib/schema.php never converges.
$sign = $store->forId($sign->id());
$layouts->publish($sign, new PublishRequest(
    layoutWith('Cascade, explicitly'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $sign->layoutStamp()
));
setTestForeignKeys($pdo, false);
$sectionId = 0;
foreach (elementsOf($pdo, $sign->id()) as $row) { if ($row['type'] === 'section') { $sectionId = intval($row['id']); } }
checkSame(true, $layouts->deleteElement($store->forId($sign->id()), $sectionId, 1)->isOk(),
          'a section is deleted with foreign keys switched off');
checkSame(0, count(elementsOf($pdo, $sign->id())),
          'and its children go with it without relying on ON DELETE CASCADE');
setTestForeignKeys($pdo, true);

// ─────────────────────────────────────────────────────────────
section('Brand Standards: a Brand\'s typography, and what may change it');

$pdo   = newTestDb();
$store = newTestDisplayStore($pdo);
$brand = new BrandStyles($pdo);
$one   = makeTestDisplay($pdo, 'one', 'Sign One');
$two   = makeTestDisplay($pdo, 'two', 'Sign Two');

// A second Brand, and a sign wearing it. This is what the narrowed refusal needs to
// be *shown* rather than asserted: with one Brand, "refuse while anyone is editing
// anything" and "refuse while anyone is editing a sign wearing this Brand" are the
// same rule and no test could tell them apart.
$brandB = makeTestBrand($pdo, 'Salmon House');
$three  = makeTestDisplay($pdo, 'three', 'Sign Three', 1920, 1080, $brandB);

checkSame(null, $store->editedByAnyoneElseUsingBrand(1, 1), 'nobody is editing anything to begin with');
$store->claimLock($two, 2);
$busy = $store->editedByAnyoneElseUsingBrand(1, 1);
check($busy !== null,        'a lock held by another account on a sign wearing this Brand is visible');
checkSame('two', $busy ? $busy->tag() : '', 'and it names which Display');
checkSame(null, $store->editedByAnyoneElseUsingBrand(2, 1), 'the holder is not blocked by their own lock');

// The narrowing itself (ADR-0011). Sign Two is being edited and wears Brand 1;
// editing Brand 2 is nobody's business but the Salmon House's.
checkSame(null, $store->editedByAnyoneElseUsingBrand(1, $brandB),
          'somebody editing a sign wearing another Brand does not block this one');
$store->claimLock($three, 2);
$busyB = $store->editedByAnyoneElseUsingBrand(1, $brandB);
checkSame('three', $busyB ? $busyB->tag() : '', 'and the sign wearing it does block it');

ageTestLock($pdo, $two->id(), LockState::IDLE_LAPSE_SECONDS + 60);
checkSame(null, $store->editedByAnyoneElseUsingBrand(1, 1), 'a lapsed lock does not block a brand change');

checkSame([$one->id(), $two->id()], array_map(function ($d) { return $d->id(); }, $store->usingBrand(1)),
          'a Brand knows which signs wear it, which is what makes a delete refusal name them');
checkSame(1, count($store->usingBrand($brandB)), 'and the other Brand has its own');

// Absent means untouched — the defect that reset every sign to black Arial 16.
$before = $brand->all(1);
checkSame(0, $brand->save(1, []), 'a save carrying no typography writes nothing');
checkSame($before, $brand->all(1), 'and leaves every stored style exactly as it was');

checkSame(1, $brand->save(1, ['price' => ['font_family' => 'Georgia', 'font_size' => 44,
                                          'font_color' => '#00FF00', 'font_weight' => 'bold',
                                          'font_style' => 'normal', 'line_height' => 1.25]]),
          'a save carrying one type writes one row');
$after = $brand->all(1);
checkSame('Georgia', $after['price']['font_family'],   'the submitted family is stored');
checkSame('#00ff00', $after['price']['font_color'],    'a colour is normalised to lowercase hex');
checkSame($before['item_title'], $after['item_title'], 'and the five types it did not carry are untouched');

// The whole point of the re-key: two Brands' rows for one block type are two rows.
// On the old single-column key this save would have overwritten the row above.
checkSame('Arial', $brand->all($brandB)['price']['font_family'],
          'a second Brand keeps its own price typography when the first one changes');
checkSame(1, $brand->save($brandB, ['price' => ['font_family' => 'Verdana', 'font_size' => 30,
                                                'font_color' => '#123456', 'font_weight' => 'bold',
                                                'font_style' => 'normal', 'line_height' => 1.2]]),
          'and saving the second Brand writes its own row');
checkSame('Verdana', $brand->all($brandB)['price']['font_family'], 'which really changed');
checkSame('Georgia',  $brand->all(1)['price']['font_family'],
          'and left the first Brand exactly as it was — the re-key, proved');

// ---- schema.sql's seed and STARTING_POINTS are the same six rows -------------
// `BrandStyles::STARTING_POINTS` is where a new Brand starts, and it has two readers
// in PHP — `BrandStyles::seedFor()` for a Brand an admin creates, and
// `signageSchemaPlan()`'s step for the one convergence makes. schema.sql is the third
// writer and the one that cannot call PHP, so it holds a *copy*. The docblock claims
// three writers of one list; this is what makes that true rather than aspirational.
//
// A drift here is silent and lasting: a fresh install built from schema.sql would
// start its first Brand somewhere every later Brand does not, and nothing would ever
// say so — the values are only wrong relative to each other, and both render.
$ssSql = file_get_contents(__DIR__ . '/../schema.sql');
$ssPos = strpos($ssSql, 'INSERT IGNORE INTO block_styles');
check($ssPos !== false, 'schema.sql seeds the branded block types');
$ssRows = [];
if ($ssPos !== false) {
    $ssChunk = substr($ssSql, $ssPos, strpos($ssSql, ';', $ssPos) - $ssPos);
    if (preg_match_all(
            "/\(\s*1\s*,\s*'([a-z_0-9]+)'\s*,\s*'([^']*)'\s*,\s*(\d+)\s*,\s*'([^']*)'\s*,"
            . "\s*'([^']*)'\s*,\s*'([^']*)'\s*,\s*([0-9.]+)\s*\)/",
            $ssChunk, $ssM, PREG_SET_ORDER)) {
        foreach ($ssM as $ssOne) {
            $ssRows[$ssOne[1]] = [$ssOne[2], intval($ssOne[3]), $ssOne[4], $ssOne[5], $ssOne[6],
                                  floatval($ssOne[7])];
        }
    }
}
checkSame(array_keys(BrandStyles::STARTING_POINTS), array_keys($ssRows),
          'schema.sql seeds exactly the branded types BrandStyles starts a Brand with');
foreach (BrandStyles::STARTING_POINTS as $ssType => $ssWant) {
    $ssHave = $ssRows[$ssType] ?? null;
    // Compared as values rather than as text: the line height is 1.30 in the file and
    // 1.3 in PHP, and a difference of spelling is not a drift to report.
    $ssSame = is_array($ssHave)
           && $ssHave[0] === $ssWant[0] && $ssHave[1] === $ssWant[1]
           && $ssHave[2] === $ssWant[2] && $ssHave[3] === $ssWant[3]
           && $ssHave[4] === $ssWant[4] && floatval($ssHave[5]) === floatval($ssWant[5]);
    checkSame(true, $ssSame, 'and starts ' . $ssType . ' at the same values PHP does');
}

checkSame(0, $brand->seedFor($brandB), 'seeding a Brand that already has its six writes nothing');
checkSame(6, $brand->seedFor(makeTestBrandRow($pdo, 'Casino Floor')),
          'and a Brand with none gets all six');

$brandGone = makeTestBrand($pdo, 'Doomed');
checkSame(6, $brand->deleteFor($brandGone), 'destroying a Brand takes its six standards with it');
checkSame([], $brand->all($brandGone),      'leaving none behind for an id that is never reused');

// Every one of these reaches every sign within 30 seconds with no publish, so a
// value that cannot render must never be stored in the first place.
checkSame(8,      BrandStyles::cleanSize(0),        'size 0 would make every price invisible');
checkSame(8,      BrandStyles::cleanSize(-40),      'and so would a negative one');
checkSame(400,    BrandStyles::cleanSize(99999),    'an absurd size is clamped, not stored');
checkSame(16,     BrandStyles::cleanSize('16abc'),  'a numeric-ish string is read as its number');
checkSame('#ffffff', BrandStyles::cleanColor('transparent'), 'a non-hex colour falls back');
checkSame('#ffffff', BrandStyles::cleanColor('#fff'),        'and so does three-digit hex, which the column cannot hold');
checkSame('normal',  BrandStyles::cleanWeight('javascript:alert(1)'), 'weight is one of two words');
checkSame('normal',  BrandStyles::cleanStyle('oblique'),     'and style is one of two words');
checkSame('Arial',   BrandStyles::cleanFamily('Arial;position:fixed;top:0;width:100vw'),
          'a family carrying CSS is stripped to the name');
checkSame('5.00',    BrandStyles::formatLineHeight(9999),
          'a line height beyond the column is clamped, not written as "1,000.00"');
checkSame('1.40',    BrandStyles::formatLineHeight('nonsense'), 'and nonsense falls back to the default');

// ─────────────────────────────────────────────────────────────
section('A Brand: its name, its palette, and what may destroy it');
// ─────────────────────────────────────────────────────────────
// ADR-0011 makes a Brand the thing several signs read their typography, palette and
// logo from. Two properties matter more than the rest, and both are #21's line: a
// palette colour that cannot be read is *named*, never substituted, and a Brand a
// sign still wears is refused rather than reassigned.

$nPdo    = newTestDb();
$nBrands = new BrandStore($nPdo);
$nStyles = new BrandStyles($nPdo);
$nDisp   = newTestDisplayStore($nPdo);
$nAdmin  = new BrandAdmin($nPdo, $nBrands, $nStyles, $nDisp);
$nAdminD = newTestDisplayAdmin($nPdo);

// ---- The name rules ---------------------------------------------------------
checkSame('Salmon House', BrandStore::cleanName('  Salmon   House  '),
          'a name is trimmed and its whitespace collapsed');
checkSame('', BrandStore::cleanName(['Salmon House']),
          'and a value that is not a string is not a name folded badly — it is not a name (#27)');
checkSame(false, BrandStore::isValidName(''), 'an empty name is refused');
checkSame(false, BrandStore::isValidName(str_repeat('a', BrandStore::NAME_MAX + 1)),
          'and one longer than the column, rather than being truncated to something nobody chose');
checkSame(true,  BrandStore::isValidName(str_repeat('a', BrandStore::NAME_MAX)),
          'exactly the column width is fine');
checkSame(false, BrandStore::isValidName("Salmon\tHouse"),
          'a control character is refused, because two names that look identical must not be two rows');
checkSame(true,  BrandStore::isValidName('Tavern & Grill'),
          'but an ampersand is an ordinary venue name');

// The two numbers that have to agree with `schema.sql`, asserted as literals rather
// than through the constants. A check written as `str_repeat('a', NAME_MAX)` moves
// with the constant and would pass just as happily at 81, where the column would
// truncate silently; PALETTE_SLOTS at 7 would build `palette_7 = ?` against a table
// that has six. Both are the constant agreeing with the database, so both are stated.
checkSame(80, BrandStore::NAME_MAX, 'the name limit is the width of brands.name in schema.sql');
checkSame(6,  BrandStore::PALETTE_SLOTS, 'and there are exactly as many palette slots as columns');
checkSame(['palette_1','palette_2','palette_3','palette_4','palette_5','palette_6'],
          BrandStore::paletteFields(), 'spelled the way the columns are');

// ---- Which value names a Brand ----------------------------------------------
// Reached straight from `$_POST['b_id']` on the panel's save and delete forms, so
// this is the same hazard `DisplayStore::forId()` was fixed for (#21): `intval("7abc")`
// is 7, so a mangled id would not fail — it would silently name a *different Brand*,
// and the delete form would act on it.
$nSeeded = $nBrands->all()[0];
checkSame($nSeeded->id(), $nBrands->forId((string)$nSeeded->id())->id(),
          'an id written as a whole number names its Brand');
checkSame(null, $nBrands->forId((string)$nSeeded->id() . 'abc'),
          'but a number with rubbish after it names no Brand, rather than that one');
checkSame(null, $nBrands->forId([]),      'and neither does an array, which intval() reads as 1');
checkSame(null, $nBrands->forId(true),    'nor a boolean, which intval() also reads as 1');
checkSame(null, $nBrands->forId('1.9'),   'nor a fraction, which would round down onto Brand 1');
checkSame(null, $nBrands->forId(0),       'zero names nothing');
checkSame(null, $nBrands->forId(-1),      'and so does a negative id');
checkSame(null, $nBrands->forId(99999),   'and an id no Brand has answers null rather than throwing');

// ---- Creating one -----------------------------------------------------------
$nRes = $nAdmin->create(['name' => 'Salmon House']);
checkSame(true, $nRes->isOk(), 'a Brand is created from a name alone');
$nSalmon = $nRes->brand();
checkSame(6, count($nStyles->all($nSalmon->id())),
          'and comes with its six sets of standards, or its typography form would save nothing');
checkSame('#e74c3c', $nStyles->all($nSalmon->id())['price']['font_color'],
          'started from BrandStyles::STARTING_POINTS');

$nDup = $nAdmin->create(['name' => 'salmon house']);
checkSame(BrandResult::CONFLICT, $nDup->kind(),
          'a name another Brand already has is refused, compared the way a person reads it');
checkSame('name', $nDup->field(), 'and the refusal names the field to point at');
checkMentions($nDup->message(), 'Salmon House', 'and quotes the Brand already using it');

checkSame(BrandResult::INVALID, $nAdmin->create(['name' => ''])->kind(), 'a nameless Brand is refused');

// An empty name matches nothing rather than the first Brand whose name is falsy —
// the guard that makes `otherBrandNamed('')` safe to call before the name has been
// validated, which is the order `checkFields()` puts them in.
checkSame(null, $nBrands->otherBrandNamed(''), 'an empty name clashes with no Brand');
checkSame(null, $nBrands->otherBrandNamed('   '), 'and neither does one that is only spaces');
checkSame($nSalmon->id(), $nBrands->otherBrandNamed('SALMON HOUSE')->id(),
          'but case is not a difference, because MySQL\'s collation does not think so either');
checkSame(null, $nBrands->otherBrandNamed('Salmon House', $nSalmon->id()),
          'and a Brand does not clash with itself, or it could never be re-saved');
checkSame($nSeeded->id(), $nBrands->otherBrandNamed($nSeeded->name())->id(),
          'the very first Brand is checked like any other — the default exceptId excludes nothing');
checkSame($nSalmon->id(), $nBrands->otherBrandNamed('  Salmon   House  ')->id(),
          'and the name being compared is the folded one, so spacing is not a way past the check');

// ---- The logo, and the two background kinds ---------------------------------
$nLogoId = intval((new AssetLibrary($nPdo))->pool('image', 'uploads/salmon-logo.png'));
$nAdmin->updateDetails($nSalmon, ['name' => 'Salmon House', 'logo_asset_id' => $nLogoId,
                                  'bg_type' => 'color', 'bg_val' => '#1a1a2e']);
checkSame($nLogoId, $nBrands->forId($nSalmon->id())->logoAssetId(), 'a Brand remembers its logo');
checkSame($nLogoId, $nBrands->forId($nSalmon->id())->toClientArray()['logo_asset_id'],
          'and hands it to a client as an id');
$nAdmin->updateDetails($nBrands->forId($nSalmon->id()),
                       ['name' => 'Salmon House', 'logo_asset_id' => '',
                        'bg_type' => 'color', 'bg_val' => '#1a1a2e']);
checkSame(0, $nBrands->forId($nSalmon->id())->logoAssetId(),
          'and clearing it answers 0 rather than null, so no caller has to test for both');

$nPdo->prepare("UPDATE brands SET bg_type = 'image', bg_val = ? WHERE id = ?")
     ->execute(['uploads/salmon-bg.png', $nSalmon->id()]);
checkSame('image', $nBrands->forId($nSalmon->id())->backgroundType(), 'a Brand can default to an image background');
// The third kind is handed to the reader as a row rather than written to the column,
// because on the engine the shop runs the column cannot hold it: `bg_type` is an ENUM,
// strict mode has been MySQL's default since 5.7, and that UPDATE is error 1265 — a
// thrown PDOException rather than a stored value, which took the whole MySQL leg down
// and the rehearsal step under it with it (§4bk). What this check is about is the
// reader, and the reader takes a row.
checkSame('color', (new Brand(['bg_type' => 'nonsense']))->backgroundType(),
          'and anything that is not the word image reads as a colour, never as a third kind');

// ---- The palette: offered, and never substituted ----------------------------
$nRes = $nAdmin->updateDetails($nSalmon, [
    'name' => 'Salmon House', 'bg_type' => 'color', 'bg_val' => '#102030',
    'palette_1' => '#AABBCC', 'palette_2' => '', 'palette_3' => '#ddeeff',
    'palette_4' => '', 'palette_5' => '', 'palette_6' => '',
]);
checkSame(true, $nRes->isOk(), 'a Brand\'s palette and background are saved');
$nSalmon = $nBrands->forId($nSalmon->id());
checkSame(['#aabbcc', '#ddeeff'], $nSalmon->palette(),
          'the filled slots come back normalised, in slot order, with the empty ones left out');
checkSame('#102030', $nSalmon->backgroundValue(), 'and the default canvas background is stored');
checkSame([], $nSalmon->unreadablePalette(), 'nothing is unreadable about a palette this app wrote');

$nBad = $nAdmin->updateDetails($nSalmon, [
    'name' => 'Salmon House', 'bg_type' => 'color', 'bg_val' => '#102030',
    'palette_1' => '#aabbcc', 'palette_2' => 'puce', 'palette_3' => '',
    'palette_4' => '', 'palette_5' => '', 'palette_6' => '',
]);
checkSame(BrandResult::INVALID, $nBad->kind(), 'a palette colour that is not a colour is refused');
checkSame('palette_2', $nBad->field(), 'naming the slot rather than the whole form');
checkMentions($nBad->message(), 'Palette colour 2', 'in the words the form puts on it');
checkSame(['#aabbcc', '#ddeeff'], $nBrands->forId($nSalmon->id())->palette(),
          'and nothing was saved — a refusal is whole, never a partial write');

// The default background is the same question `Background` answers for a Display, so
// it gets the same answer: refused, not replaced. Blank is the *other* case and has
// to stay distinguishable from it — a Brand created from a name alone carries no
// background at all, and refusing that would make the ordinary create impossible.
$nBadBg = $nAdmin->updateDetails($nBrands->forId($nSalmon->id()),
    ['name' => 'Salmon House', 'bg_type' => 'color', 'bg_val' => 'darkish blue']);
checkSame(BrandResult::INVALID, $nBadBg->kind(), 'a default background that is not a colour is refused');
checkSame('bg_val', $nBadBg->field(), 'naming the field');
checkSame('#102030', $nBrands->forId($nSalmon->id())->backgroundValue(),
          'and the stored one is untouched, never replaced with a substitute (#21)');
checkSame(true, $nAdmin->create(['name' => 'No Background Given'])->isOk(),
          'while supplying none at all is the ordinary create, not a refusal');
checkSame(Background::DEFAULT_COLOR,
          $nBrands->otherBrandNamed('No Background Given')->backgroundValue(),
          'and it lands on the app\'s documented default');
// Cleared again, so the count the destroy checks below rest on stays what they say.
$nAdmin->destroy($nBrands->otherBrandNamed('No Background Given'), 'No Background Given');

// A row that got past this app — hand-edited, or written before the rule. The
// swatch is not offered, and the tab says which value it could not use rather than
// leaving a palette one colour short with no explanation (#21).
$nPdo->prepare("UPDATE brands SET palette_2 = 'puce' WHERE id = ?")->execute([$nSalmon->id()]);
$nStored = $nBrands->forId($nSalmon->id());
checkSame(['#aabbcc', '#ddeeff'], $nStored->palette(), 'an unreadable stored slot is not offered as a swatch');
checkSame(1, count($nStored->unreadablePalette()), 'but it is reported rather than silently dropped');
checkSame('puce', $nStored->unreadablePalette()[0]['value'], 'quoting what is actually stored');
checkSame('Palette colour 2', $nStored->unreadablePalette()[0]['label'], 'and naming which slot');
checkSame('puce', $nStored->paletteSlot(1), 'the form redraws the stored value, not a substitute');
checkSame('', $nStored->paletteSlot(99), 'and a slot that does not exist is empty rather than an error');

// ---- Destroying one ---------------------------------------------------------
$nCasino = $nAdmin->create(['name' => 'Casino Floor'])->brand();
$nSign   = makeTestDisplay($nPdo, 'salmon-board', 'Salmon House Board', 1920, 1080, $nSalmon->id());

checkSame(BrandResult::INVALID, $nAdmin->destroy($nSalmon, 'wrong name')->kind(),
          'destroying a Brand needs its name typed back');
checkSame(BrandResult::INVALID, $nAdmin->destroy($nSalmon, '')->kind(),
          'and a blank confirm is not a match either');

checkSame(BrandResult::INVALID, $nAdmin->destroy($nSalmon, ['Salmon House'])->kind(),
          'and a value that is not a string is not a confirmation');
checkSame(true, $nAdmin->destroy($nSalmon, '  salmon   house  ')->kind() !== BrandResult::INVALID,
          'but the confirm is folded the same way the name was, so spacing and case are not traps');

$nWorn = $nAdmin->destroy($nSalmon, 'Salmon House');
checkSame(BrandResult::CONFLICT, $nWorn->kind(), 'a Brand a sign still wears cannot be destroyed');
checkMentions($nWorn->message(), 'Salmon House Board',
              'and the refusal names the sign, because "it is in use" is not something to act on');
checkMentions($nWorn->message(), 'no undo', 'and says why it is not done automatically');
checkSame(6, count($nStyles->all($nSalmon->id())), 'its standards are still there');

// A venue with more boards than the sentence will list. The refusal has to stay a
// sentence rather than becoming a wall of names, and it has to say how many it did
// not print — "and 2 more" is the difference between a list and a list that lies by
// omission. Nothing exercised this branch until a surviving mutant pointed at it.
for ($nI = 2; $nI <= 8; $nI++) {
    makeTestDisplay($nPdo, 'salmon-' . $nI, 'Salmon Board ' . $nI, 1920, 1080, $nSalmon->id());
}
$nMany = $nAdmin->destroy($nSalmon, 'Salmon House');
checkSame(BrandResult::CONFLICT, $nMany->kind(), 'eight signs still refuse the delete');
checkMentions($nMany->message(), '8 displays still wear', 'the count is the number of signs, not the number listed');
checkMentions($nMany->message(), 'and 2 more', 'and the two it did not name are accounted for');
check(strpos($nMany->message(), 'Salmon Board 8') === false,
      'the eighth is genuinely not printed, so the truncation is doing something');
for ($nI = 2; $nI <= 8; $nI++) {
    $nAdminD->destroy(loadTestDisplay($nPdo, $nDisp->forTag('salmon-' . $nI)->id()), 'salmon-' . $nI, 1);
}
checkSame(1, count($nDisp->usingBrand($nSalmon->id())), 'and with them gone one wearer is left');

// Moved off it, and now it goes — with its standards.
$nAdminD->updateDetails(loadTestDisplay($nPdo, $nSign->id()),
                        ['title' => 'Salmon House Board', 'tag' => 'salmon-board',
                         'location' => '', 'brand_id' => $nCasino->id()]);
checkSame($nCasino->id(), loadTestDisplay($nPdo, $nSign->id())->brandId(),
          'a sign can be moved to another Brand from the panel');
$nGone = $nAdmin->destroy($nBrands->forId($nSalmon->id()), 'Salmon House');
checkSame(true, $nGone->isOk(), 'and then the Brand nothing wears is destroyed');
checkSame(null, $nBrands->forId($nSalmon->id()), 'the row is gone');
checkSame([], $nStyles->all($nSalmon->id()), 'and its six sets of standards went with it');

// The last Brand cannot go: `displays.brand_id` is NOT NULL, so an install with no
// Brands is one where no sign can be created at all. The sign goes first, or the
// refusal under test is never the one that fires — the wearer check comes before it,
// which is the right order and is why this needs saying.
$nStillWorn = $nAdmin->destroy($nBrands->forId($nCasino->id()), 'Casino Floor');
checkSame(BrandResult::CONFLICT, $nStillWorn->kind(), 'the Brand the sign moved to is now the one in use');
checkMentions($nStillWorn->message(), 'Salmon House Board', 'and it is that sign standing in the way');

$nAdminD->destroy(loadTestDisplay($nPdo, $nSign->id()), 'salmon-board', 1);
checkSame(0, count($nDisp->usingBrand($nCasino->id())), 'with the sign gone, nothing wears it');
checkSame(true, $nAdmin->destroy($nBrands->forId($nCasino->id()), 'Casino Floor')->isOk(),
          'and it can go too');

// Down to the one the fixture seeds, which nothing wears and which still cannot be
// destroyed: `displays.brand_id` is NOT NULL, so an install with no Brands is one
// where no sign can be created at all.
$nOnly = $nBrands->all()[0];
checkSame(1, $nBrands->count(), 'one Brand is left');
$nLast = $nAdmin->destroy($nOnly, $nOnly->name());
checkSame(BrandResult::CONFLICT, $nLast->kind(), 'and the only Brand left cannot be destroyed');
checkMentions($nLast->message(), 'Rename it instead', 'the message says what to do instead');
checkSame(1, $nBrands->count(), 'and it really is still there');

// ---- What a client is handed -------------------------------------------------
$nClient = $nOnly->toClientArray();
checkSame($nOnly->id(), $nClient['id'],     'the client array carries the id');
checkSame($nOnly->name(), $nClient['name'], 'and the name');
checkSame([], $nClient['palette'],          'and a palette that is read rather than raw');
checkSame(0, $nClient['logo_asset_id'],     'and 0 for a Brand with no logo, never null');

// ─────────────────────────────────────────────────────────────
section('Publish never writes what a Brand paints (invariant 34)');
// ─────────────────────────────────────────────────────────────
// The Builder paints the shared standard onto a branded block's inline style so it
// looks like what it will become, and serialising read that straight back out — so
// every publish baked the standard into the element's own row. Harmless while one
// set of standards reaches every sign; a stale-brand fossil the moment several do
// (ADR-0011).
//
// The browser stopped sending them, and this is the other half: the module that
// owns the table decides, so a Builder tab loaded before the fix — an ordinary
// thing on the afternoon of a deploy — cannot write one either.

// Who is painted and who is not, asked of the standards this install really has.
$stored = $brand->all(1);
check(BrandStyles::paints('text', 'price', $stored),       'a price block is painted by Brand Standards');
check(!BrandStyles::paints('text', 'free', $stored),       'a free text block owns its own typography');
check(!BrandStyles::paints('image', 'price', $stored),     'and only a text block reads these columns at all');
check(!BrandStyles::paints('text', 'price', []),           'no standard for a type means the block\'s own values are load-bearing');
check(!BrandStyles::paints('text', ['price'], $stored),    'a subtype that is not a string names no standard');

$bPdo     = newTestDb();
$bStore   = newTestDisplayStore($bPdo);
$bLayouts = newTestLayoutStore($bPdo);
$bSign    = makeTestDisplay($bPdo, 'brandcheck', 'Brand Check');

/** One section holding a branded block and a free one, both shouting the same font. */
function brandBakingLayout()
{
    $shout = ['font_family' => 'Comic Sans MS', 'font_size' => 99, 'font_color' => '#ff00ff',
              'font_weight' => 'bold', 'font_style' => 'italic', 'line_height' => 3.0];
    return [
        ['type' => 'section', 'temp_id' => 'bs', 'x_pos' => 0, 'y_pos' => 0,
         'width' => 600, 'height' => 380],
        array_merge(['type' => 'text', 'block_subtype' => 'price', 'parent_temp_id' => 'bs',
                     'manual_content' => '18.99', 'x_pos' => 5, 'y_pos' => 5,
                     'width' => 160, 'height' => 60], $shout),
        array_merge(['type' => 'text', 'block_subtype' => 'free', 'parent_temp_id' => 'bs',
                     'manual_content' => 'Fresh today', 'x_pos' => 5, 'y_pos' => 90,
                     'width' => 300, 'height' => 60], $shout),
    ];
}

check(publishAs($bLayouts, $bSign, brandBakingLayout(), '0')->isOk(),
      'a publish carrying typography for a branded block is accepted, not refused');

$bRows = [];
foreach (elementsOf($bPdo, $bSign->id()) as $row) {
    if ($row['type'] === 'text') { $bRows[$row['block_subtype']] = $row; }
}
checkSame(2, count($bRows), 'both text blocks landed');

// The branded one: every field is the documented default, none of them the payload's.
checkSame('Arial',    $bRows['price']['font_family'], 'a branded block stores no font family of its own');
checkSame(16,         intval($bRows['price']['font_size']),  'no size');
checkSame('#000000',  $bRows['price']['font_color'],  'no colour');
checkSame('normal',   $bRows['price']['font_weight'], 'no weight');
checkSame('normal',   $bRows['price']['font_style'],  'no style');
checkSame('1.40', number_format(floatval($bRows['price']['line_height']), 2), 'and no line height');

// The free one, same payload, and it keeps every field — which is what stops the
// check above passing because publish dropped typography altogether.
checkSame('Comic Sans MS', $bRows['free']['font_family'], 'a free block keeps the family it was sent');
checkSame(99,        intval($bRows['free']['font_size']), 'and the size');
checkSame('#ff00ff', $bRows['free']['font_color'],        'and the colour');
checkSame('bold',    $bRows['free']['font_weight'],       'and the weight');
checkSame('italic',  $bRows['free']['font_style'],        'and the style');
checkSame('3.00', number_format(floatval($bRows['free']['line_height']), 2), 'and the line height');

// A copy is a write of the same columns by the same module, so it answers the same
// question. Otherwise the invariant would hold for the rows one of the two writers
// wrote, which is not an invariant.
$bTarget = makeTestDisplay($bPdo, 'brandcopy', 'Brand Copy', 1920, 1080);
$bPdo->prepare("UPDATE canvas_elements SET font_family = ?, font_size = ?, font_color = ?
                 WHERE display_id = ? AND block_subtype = 'price'")
     ->execute(['Georgia', 44, '#c0392b', $bSign->id()]);
check($bLayouts->copyLayout($bStore->forId($bSign->id()), $bStore->forId($bTarget->id())) > 0,
      'a layout carrying a fossil from before this landed can still be copied');

$copied = [];
foreach (elementsOf($bPdo, $bTarget->id()) as $row) {
    if ($row['type'] === 'text') { $copied[$row['block_subtype']] = $row; }
}
checkSame('Arial',   $copied['price']['font_family'], 'and the copy does not carry the fossil forward');
checkSame('#000000', $copied['price']['font_color'],  'in any of its columns');
checkSame('Comic Sans MS', $copied['free']['font_family'],
          'while a free block\'s own typography is copied exactly as before');

// The half that is a *sign* rather than a row: with no standard stored for a type,
// both renderers fall back to the element's own columns, so publish must keep them.
// Stripping on the strength of a row that is not there is a blank price on a wall.
$bPdo->exec("DELETE FROM block_styles WHERE block_type = 'price'");
$bSign = $bStore->forId($bSign->id());
check(publishAs($bLayouts, $bSign, brandBakingLayout(), $bSign->layoutStamp())->isOk(),
      'a publish still succeeds when no standard is stored for the type');
$bare = [];
foreach (elementsOf($bPdo, $bSign->id()) as $row) {
    if ($row['type'] === 'text') { $bare[$row['block_subtype']] = $row; }
}
checkSame('Comic Sans MS', $bare['price']['font_family'],
          'and a block no standard paints keeps the typography it was sent');

// ─────────────────────────────────────────────────────────────
section('The session gates: a token that is really checked, and a role that is re-read');

$_SESSION = [];
$_POST    = [];
checkSame(false, csrfOk(), 'a POST with no token, to a session with no token, is refused');

$_POST['csrf_token'] = 'anything';
checkSame(false, csrfOk(), 'and so is one carrying a token the session never issued');

$_SESSION['csrf_token'] = csrfToken();
$_POST['csrf_token']    = '';
checkSame(false, csrfOk(), 'an empty submitted token never matches');

$_POST['csrf_token'] = $_SESSION['csrf_token'];
checkSame(true,  csrfOk(), 'the session\'s own token is accepted');

$_POST['csrf_token'] = strtoupper($_SESSION['csrf_token']);
checkSame(false, csrfOk(), 'a near-miss is refused');

// hash_equals('', '') is true, which is what made the empty case dangerous: an
// admin lands on the Builder's Display picker, which exits before minting a
// token, so this state was reachable on every single login.
check(hash_equals('', ''), 'the reason: hash_equals of two empty strings is true');

$_POST = [];
$_SESSION = [];

// ---- The role is re-read, not remembered -------------------------------------
$pdo = newTestDb();
$_SESSION['user_id'] = 2;
$_SESSION['role']    = 'admin';   // as if promoted, then demoted in another tab
checkSame(true,   syncSessionAccount($pdo), 'a live account keeps its session');
checkSame('basic', $_SESSION['role'],       'and the session takes the role the database says, not the one it cached');

$pdo->exec("UPDATE users SET role = 'admin' WHERE id = 2");
syncSessionAccount($pdo);
checkSame('admin', $_SESSION['role'], 'a promotion is picked up on the next request too');

$pdo->exec("UPDATE users SET is_active = 0 WHERE id = 2");
checkSame(false, syncSessionAccount($pdo), 'a deactivated account\'s session is refused');

$pdo->exec("UPDATE users SET is_active = 1 WHERE id = 2");
$pdo->exec("UPDATE users SET closed_at = '2026-01-01 00:00:00' WHERE id = 2");
checkSame(false, syncSessionAccount($pdo), 'and so is a closed one, even with is_active still set');

// The app no longer deletes an account (invariant 14), but a hand-edited database
// or a partial restore can still leave a session pointing at a row that is gone.
$pdo->exec("DELETE FROM users WHERE id = 2");
checkSame(false, syncSessionAccount($pdo), 'and a session whose account row vanished entirely');

$_SESSION['user_id'] = 999;
checkSame(false, syncSessionAccount($pdo), 'an account that never existed is refused');

$_SESSION = [];
checkSame(false, syncSessionAccount($pdo), 'and a session with no account at all is refused');

// ─────────────────────────────────────────────────────────────
section('Signing in: what is refused, and what the refusal is allowed to say');

// The defect these exist for is an ordering, and an ordering is invisible in a
// diff: put the account-state checks back below password_verify() and every
// message in the app is still correct, still helpful, still the same words — and
// a guesser can once again tell a right password from a wrong one on an account
// that was supposed to be worthless to them. So what is asserted here is not the
// wording. It is that the wording does not *move* when the password changes.

$pdo    = newTestDb();
$hash   = password_hash('right-password', PASSWORD_DEFAULT);
$pdo->prepare("UPDATE users SET password_hash = ?")->execute([$hash]);
$signIn = new LoginAttempt(new AccountStore($pdo));

// Account 1 is the admin `sky`, account 2 the basic clerk `clerk` (the fixture).
$in = $signIn->attempt('sky', 'right-password');
checkSame(true,    $in->isOk(),       'a live account with its own password signs in');
checkSame(1,       $in->accountId(),  'and the outcome carries which account it was');
checkSame('sky',   $in->username(),   'and the name to put in the session');
checkSame('admin', $in->role(),       'and the role the row says, normalised the way every later request reads it');

checkSame('', $in->message(), 'a sign-in that worked has nothing to print');

$wrong   = $signIn->attempt('sky', 'not-it');
$unknown = $signIn->attempt('nobody-at-all', 'not-it');
checkSame(LoginOutcome::REFUSED, $wrong->kind(),   'a wrong password is refused');
checkSame(LoginOutcome::REFUSED, $unknown->kind(), 'and so is a username nobody has');
checkSame($wrong->message(), $unknown->message(),  'in exactly the same words, so the form is not an account list (ADR-0001)');
checkSame(0, intval($pdo->query("SELECT COUNT(*) FROM users WHERE username = 'nobody-at-all'")->fetchColumn()),
          'and a username nobody has stays a username nobody has');

// ---- The oracle, and the ordering that closes it -----------------------------
$pdo->exec("UPDATE users SET is_active = 0 WHERE id = 2");
$suspendedRight = $signIn->attempt('clerk', 'right-password');
$suspendedWrong = $signIn->attempt('clerk', 'still-not-it');
checkSame(LoginOutcome::SUSPENDED, $suspendedRight->kind(), 'a suspended account is refused as suspended');
checkSame(LoginOutcome::SUSPENDED, $suspendedWrong->kind(), 'whether or not the password was the right one');
checkSame($suspendedRight->message(), $suspendedWrong->message(),
          'and says the same sentence either way — which is the whole of the fix');
checkSame(0, intval($pdo->query("SELECT failed_attempts FROM users WHERE id = 2")->fetchColumn()),
          'the counter cannot answer the question either: a suspended account accrues no failures');
checkMentions($suspendedRight->message(), 'manager', 'the suspended sentence still says who to ask');

$pdo->exec("UPDATE users SET closed_at = '2026-01-01 00:00:00' WHERE id = 2");
$closedRight = $signIn->attempt('clerk', 'right-password');
$closedWrong = $signIn->attempt('clerk', 'nope');
checkSame(LoginOutcome::CLOSED, $closedRight->kind(), 'a closed account is refused as closed');
checkSame($closedRight->message(), $closedWrong->message(), 'in the same words whichever password was typed');
checkSame(LoginOutcome::CLOSED, $signIn->attempt('clerk', 'right-password')->kind(),
          'and closed is asked before suspended, because closing clears is_active too (invariant 14)');
check(strpos($closedRight->message(), 'deactivated') === false,
      'a retired employee is never told they are deactivated, which would mean asking to be switched back on');
checkMentions($closedRight->message(), 'new one', 'they are told the thing that can actually happen');

// ---- And the same oracle in the clock ----------------------------------------
// ADR-0008 made the *sentence* independent of the password. It stayed dependent in
// the timing: returning before password_verify() made these refusals come back a
// bcrypt sooner than a wrong password on a live account, and a stopwatch is no
// harder to reach for than reading the wording. Every account that exists now pays
// for the hash, whether or not its answer will be looked at.
//
// Checked structurally rather than by measuring, deliberately. A wall-clock
// assertion on a hash cost is exactly the flaky check #50 is about, and it would
// have to pass on a machine whose bcrypt cost nobody here chose. This asserts the
// property the module documents instead, which is what a change would break.
// Structural: it proves where the call sits, not that a refusal was slow.
$gateSrc = file_get_contents(__DIR__ . '/../lib/login_attempt.php');
$spendAt = strpos($gateSrc, '$passwordOk = password_verify(');
$closedAt = strpos($gateSrc, 'isClosed($accountId)');
$readAt  = strpos($gateSrc, 'if (!$passwordOk)');
check($spendAt !== false, 'the password is spent into a variable rather than checked inline');
check($readAt !== false, 'and read back once, at the end');
check($spendAt < $closedAt,
      'the hash is spent before the state checks, so a suspended refusal costs what a wrong password costs');
check($closedAt < $readAt,
      'and what the password said is not consulted until every state check has had its turn');
// The three state branches must decide on state alone. If any of them started
// reading $passwordOk, the wording would depend on the password again — ADR-0008
// from the other end.
$betweenStates = substr($gateSrc, $closedAt, $readAt - $closedAt);
check(strpos($betweenStates, '$passwordOk') === false,
      'and no state branch between them consults it, which would put the message oracle back');

// ---- The lockout still works, and still comes second --------------------------
$pdo->exec("UPDATE users SET is_active = 1, closed_at = NULL WHERE id = 2");
for ($i = 1; $i < LoginAttempt::MAX_ATTEMPTS; $i++) {
    $signIn->attempt('clerk', 'wrong-' . $i);
}
checkSame(LoginAttempt::MAX_ATTEMPTS - 1,
          intval($pdo->query("SELECT failed_attempts FROM users WHERE id = 2")->fetchColumn()),
          'four wrong passwords on a live account are four counted failures');

$fifth = $signIn->attempt('clerk', 'wrong-5');
checkSame(LoginOutcome::LOCKED, $fifth->kind(), 'the fifth trips the lockout');
checkMentions($fifth->message(), '15 minute', 'and says how long the wait is');
checkSame(LoginOutcome::LOCKED, $signIn->attempt('clerk', 'right-password')->kind(),
          'and the correct password waits it out like every other one (ADR-0001)');

$later = time() + LoginAttempt::WINDOW_SECONDS + 1;
checkSame(true, $signIn->attempt('clerk', 'right-password', $later)->isOk(),
          'once the window has passed the right password is taken again');
checkSame(0, intval($pdo->query("SELECT failed_attempts FROM users WHERE id = 2")->fetchColumn()),
          'and signing in clears what the failures left behind');

// A failure older than the window does not stack onto a new one — the age-out
// half of the single window, which is the half with no other way to reach it.
$pdo->prepare("UPDATE users SET failed_attempts = 4, last_failed_at = ?, locked_until = NULL WHERE id = 2")
    ->execute([gmdate('Y-m-d H:i:s', time() - LoginAttempt::WINDOW_SECONDS - 60)]);
checkSame(LoginOutcome::REFUSED, $signIn->attempt('clerk', 'wrong-again')->kind(),
          'a stale run of failures does not lock the account on the next single mistake');
checkSame(1, intval($pdo->query("SELECT failed_attempts FROM users WHERE id = 2")->fetchColumn()),
          'the count starts again at one');

// A suspension while the lockout is live: still the suspended sentence, because
// "wait 15 minutes" is advice that would never come true.
$pdo->prepare("UPDATE users SET is_active = 0, failed_attempts = 5, locked_until = ? WHERE id = 2")
    ->execute([gmdate('Y-m-d H:i:s', time() + 600)]);
checkSame(LoginOutcome::SUSPENDED, $signIn->attempt('clerk', 'right-password')->kind(),
          'a locked-out account that is also suspended is told the thing that will not expire');

// ---- An empty form is not a guess ---------------------------------------------
checkSame(LoginOutcome::INCOMPLETE, $signIn->attempt('', 'anything')->kind(), 'no username is not an attempt');
checkSame(LoginOutcome::INCOMPLETE, $signIn->attempt('sky', '')->kind(),      'and neither is no password');
checkSame(LoginOutcome::INCOMPLETE, $signIn->attempt('   ', 'anything')->kind(), 'nor a username of spaces');

// ---- The lockout's clock ------------------------------------------------------
// Same defect the edit lock had before §4t, in a different table: written with
// date() and compared with a bare strtotime(), which agrees with itself and is
// still wrong, because local wall-clock is not monotonic. The autumn fall-back
// replays an hour; for that hour a stamp from the second pass sorts below one from
// the first and strtotime resolves the repeat to its first occurrence. There is no
// way to move this process's clock into November and no need to — the bug is
// entirely "wrote local, compared as if absolute", so what is asserted is that the
// storage is absolute.
$tzWas = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');   // the store's own zone, 7-8h off UTC

$pdo->exec("UPDATE users SET is_active = 1, closed_at = NULL, failed_attempts = 0,
                             last_failed_at = NULL, locked_until = NULL WHERE id = 2");
$signIn->attempt('clerk', 'wrong-once');
$stored = $pdo->query("SELECT last_failed_at FROM users WHERE id = 2")->fetchColumn();
check(abs(strtotime($stored . ' UTC') - time()) <= 5,
      'a failed attempt is stamped in UTC even when the server is not on it');
check(abs(strtotime($stored) - time()) > 3600,
      'read as local time that same string is hours out — which is what used to be stored');

// And the round trip: what was just written has to be read back as the same moment,
// or the age-out compares a stamp against a clock seven hours from it.
$signIn->attempt('clerk', 'wrong-twice');
checkSame(2, intval($pdo->query("SELECT failed_attempts FROM users WHERE id = 2")->fetchColumn()),
          'and the failure written in UTC is read back as recent, so the second one stacks on it');

// Every locked_until already on the live database was written in local time, and on
// a server east of UTC reading one as UTC puts it further into the future — a
// fifteen-minute lockout lasting the rest of the shift, on the page nobody can work
// around. A stamp further out than one window was not written by this code.
$pdo->prepare("UPDATE users SET failed_attempts = 5, last_failed_at = ?, locked_until = ? WHERE id = 2")
    ->execute([gmdate('Y-m-d H:i:s', time()), gmdate('Y-m-d H:i:s', time() + 600)]);
checkSame(LoginOutcome::LOCKED, $signIn->attempt('clerk', 'right-password')->kind(),
          'a lockout inside the window holds, correct password and all');

$staleLockout = function () use ($pdo) {
    $pdo->prepare("UPDATE users SET failed_attempts = 5, last_failed_at = ?, locked_until = ? WHERE id = 2")
        ->execute([gmdate('Y-m-d H:i:s', time()), gmdate('Y-m-d H:i:s', time() + 86400)]);
};

$staleLockout();
checkSame(true, $signIn->attempt('clerk', 'right-password')->isOk(),
          'one stamped a day out is not honoured — ADR-0001 says a lockout is one window long');

// And not honouring it does not hand the account to a guesser. The counter is left
// alone, so their one recovered guess counts as the sixth and locks it straight
// back — with a stamp this code wrote, in the format it can read.
$staleLockout();
checkSame(LoginOutcome::LOCKED, $signIn->attempt('clerk', 'wrong-password')->kind(),
          'a wrong password against that same stale row locks the account again at once');
$reLocked = $pdo->query("SELECT locked_until, failed_attempts FROM users WHERE id = 2")->fetch();
checkSame(6, intval($reLocked['failed_attempts']), 'the guess it cost still counted');
check(strtotime($reLocked['locked_until'] . ' UTC') <= time() + LoginAttempt::WINDOW_SECONDS,
      'and the stamp it was locked with is one this code will honour');
checkSame(LoginOutcome::LOCKED, $signIn->attempt('clerk', 'right-password')->kind(),
          'so the hole a legacy row opens is one guess wide, and it closes itself');

date_default_timezone_set($tzWas);

// ---- The role that goes into the session -------------------------------------
// syncSessionAccount() normalises this on every request after the first, so a
// login that stored the column verbatim gave a row spelling it any other way one
// meaning for one request and another from then on.
//
// The odd spelling is handed to the reader as a row rather than written to the
// column, because the column will not hold it: `role` is an ENUM, and MySQL matches
// an assignment against its members through the column's collation — `'Admin'` is
// stored as `admin`, so the row this check is about cannot exist on the shop's
// engine, and the version that wrote it there asserted the opposite of what it
// built (§4bl). That folding is a second defence and deliberately not the one under
// test: a lagging install has its `canvas_elements` ENUMs widened at runtime, so
// what type a column *is* is not something this app gets to assume, and the
// normalisation in code is what has to hold either way.
checkSame('basic', LoginOutcome::ok(['id' => 1, 'username' => 'sky', 'role' => 'Admin'])->role(),
          'a role the reader is handed that is not spelled exactly "admin" is not admin');
checkSame('basic', LoginOutcome::ok(['id' => 1, 'username' => 'sky', 'role' => 'root'])->role(),
          'and neither is a word the column never offered');
$pdo->exec("UPDATE users SET role = 'admin' WHERE id = 1");
checkSame('admin', $signIn->attempt('sky', 'right-password')->role(),
          'while the spelling it does hold is admin — the same answer every later request will reach');

// ---- The database where the runtime ALTER never applied ------------------------
// login.php used to carry a second SELECT and a try/catch for exactly this, and
// nothing ever ran it. The three lockout columns are added at runtime, so a host
// without ALTER, or with a full disk, has a `users` table that predates them —
// and signing in has to keep working there, without a counter, rather than
// throwing "unknown column" at everybody on every account.
$noCounters = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$noCounters->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT 'basic',
    is_active INTEGER NOT NULL DEFAULT 1
)");
$noCounters->prepare("INSERT INTO users (username, password_hash, role) VALUES ('sky', ?, 'admin')")
           ->execute([$hash]);
$bareStore = new AccountStore($noCounters);
$bareIn    = new LoginAttempt($bareStore);
checkSame(true, $bareIn->attempt('sky', 'right-password')->isOk(),
          'a database with no lockout columns still signs people in');
checkSame(LoginOutcome::REFUSED, $bareIn->attempt('sky', 'wrong')->kind(),
          'and still refuses a wrong password, with nowhere to write the failure down');
checkSame(false, $bareStore->registerFailedLogin(1, 3, '2026-01-01 00:00:00', null),
          'the store says plainly that it could not count it');
checkSame(true, $bareStore->clearLoginLockout(1),
          'and clearing a lockout that cannot exist is not a failure');

$found = $bareStore->findForSignIn('sky');
checkSame(0,    intval($found['failed_attempts']), 'the row it hands back fills the missing counters in');
checkSame(null, $found['locked_until'],            'with a lockout that is not running');
checkSame(null, $bareStore->findForSignIn('nobody'), 'and a username nobody has is null, not a row');

// ─────────────────────────────────────────────────────────────
section('The sign-in form is a form, not something another site can fire');

// Login CSRF signs a visitor into somebody *else's* account, and everything they
// then do is done in that account's name. Every other POST in the app was covered;
// the front door was not.
//
// These read login.php's source, and that is a weaker instrument than the rest of
// this file — it can show the two lines are there, not that the page behaves. The
// behaviour they gate, csrfOk(), is checked properly above; what cannot be reached
// from here is a page that prints HTML and opens a real database. Named rather than
// dressed up.
$loginSrc = file_get_contents(__DIR__ . '/../login.php');
checkMentions($loginSrc, 'name="csrf_token"', 'the sign-in form carries a token');
checkMentions($loginSrc, 'csrfOk()',          'and the POST is gated on it');

// Not verifyCsrf(): that ends the request with a 403 and the word "security", and
// the commonest cause on this page by far is a browser not keeping the session
// cookie — which has no token to send and never will. A hard failure there is the
// invisible sign-in loop again with something frightening written on it.
check(strpos($loginSrc, 'verifyCsrf();') === false,
      'and it refuses softly rather than ending the request with a 403');
checkMentions($loginSrc, 'not keeping cookies',
      'the sentence it prints names the cause a person can actually act on');

// The gate is ahead of the account, so a request with no token cannot be used to
// run somebody's failed-attempt counter up and lock them out.
check(strpos($loginSrc, 'csrfOk()') < strpos($loginSrc, 'new LoginAttempt'),
      'and nothing looks at the account until the token has been checked');

// ─────────────────────────────────────────────────────────────
section('The sign-in cookie is marked Secure exactly when the request is');

// Secure on a plain-HTTP request is not a hardening: the browser discards the
// cookie, builder.php finds no session, and the correct password lands back on a
// blank login form for ever. So the flag is a property of the request, and this
// is the answer for every shape a host reports one in.
checkSame(true,  RequestScheme::isSecure(['HTTPS' => 'on']),   'HTTPS=on is https');
checkSame(true,  RequestScheme::isSecure(['HTTPS' => '1']),    'and so is the numeric spelling');
checkSame(false, RequestScheme::isSecure(['HTTPS' => 'off']),  'IIS sets the variable to "off" rather than leaving it out');
checkSame(false, RequestScheme::isSecure(['HTTPS' => '']),     'and an empty value is not https either');
checkSame(false, RequestScheme::isSecure([]),
          'a request that says nothing at all is plain HTTP — the safe answer, because the other one is a login page nobody can get past');
checkSame(true,  RequestScheme::isSecure(['SERVER_PORT' => '443']), 'port 443 counts');
checkSame(false, RequestScheme::isSecure(['SERVER_PORT' => '80']),  'port 80 does not');
checkSame(true,  RequestScheme::isSecure(['REQUEST_SCHEME' => 'HTTPS']), 'the scheme Apache reports counts, in any case');
checkSame(true,  RequestScheme::isSecure(['HTTP_X_FORWARDED_PROTO' => 'https']),
          'a proxy that terminated TLS is believed: the browser\'s own leg is what the flag protects');
checkSame(true,  RequestScheme::isSecure(['HTTP_X_FORWARDED_PROTO' => ' https , http ']),
          'and the first hop in the list is the browser\'s one');
checkSame(false, RequestScheme::isSecure(['HTTP_X_FORWARDED_PROTO' => 'http']), 'a proxy saying http is believed too');
checkSame(true,  RequestScheme::isSecure(['HTTP_X_FORWARDED_SSL' => 'on']),  'the older spelling of the same header');
checkSame(false, RequestScheme::isSecure(['HTTP_X_FORWARDED_SSL' => 'off']), 'and its negative');

// The other caller. admin_panel.php prints an absolute address somebody types into
// a television, and it used to answer this question itself from $_SERVER['HTTPS']
// alone — so a site behind a TLS-terminating proxy got an http:// address printed
// for it. Same fact, one method, no second opinion to drift.
checkSame('https', RequestScheme::scheme(['HTTPS' => 'on']), 'the link scheme follows the same answer');
checkSame('http',  RequestScheme::scheme([]),                'and says http when nothing says otherwise');
checkSame('https', RequestScheme::scheme(['HTTP_X_FORWARDED_PROTO' => 'https']),
          'including behind a proxy, which is the case the admin panel\'s own copy got wrong');
// Weaker than the three above and labelled as such: it reads the page's source
// rather than running it, so it shows the second copy is gone, not that the page
// behaves. There is no way to call viewerUrlFor() without a request behind it.
check(strpos(file_get_contents(__DIR__ . '/../admin_panel.php'), "\$_SERVER['HTTPS']") === false,
      'and no copy of the question is left in admin_panel.php to disagree with it');

// And the report an admin reads has to agree, or the one screen in the app that
// exists to be trusted calls a correct configuration broken.
$overHttp  = (new ServerReport(newTestDb(), []))->runtime();
$overHttps = (new ServerReport(newTestDb(), ['HTTPS' => 'on']))->runtime();
checkMentions($overHttp['Session cookie'][1], 'plain HTTP',
              'over plain HTTP the report says the missing Secure flag is deliberate');
checkMentions($overHttp['Session cookie'][1], 'https://',
              'and says what to do to get the protection back');
check(strpos($overHttps['Session cookie'][1], 'deliberately') === false,
      'over HTTPS it does not offer that excuse');
checkMentions($overHttps['Session cookie'][1], 'not marked Secure',
              'it complains instead, because on HTTPS the flag is free and was not set');

// ─────────────────────────────────────────────────────────────
section('The edit lock keeps time in UTC, so it survives a clock that repeats an hour');

// The whole defect in one property: lock stamps must be UTC, whatever zone the
// server is set to. Local wall-clock strings are not monotonic — the autumn
// fall-back replays an hour, second-pass strings sort below first-pass ones, and
// strtotime resolves the repeated hour to its first occurrence. For that hour
// anyone could take a held lock, nothing was read-only, and no publish was
// refused. There is no way to move this process's clock into November, but there
// is no need to: the bug is entirely "wrote local, compared as if absolute", and
// this asserts the storage is absolute.
$tzWas = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');   // the store's own zone, 7-8h off UTC

$pdo   = newTestDb();
$store = newTestDisplayStore($pdo);
$tz    = makeTestDisplay($pdo, 'tz', 'Timezone');

$store->claimLock($tz, 1);
$storedActivity = $pdo->query("SELECT lock_activity_at FROM displays WHERE id = " . $tz->id())->fetchColumn();
$storedTaken    = $pdo->query("SELECT lock_taken_at FROM displays WHERE id = " . $tz->id())->fetchColumn();
check(abs(strtotime($storedActivity . ' UTC') - time()) <= 5,
      'claimLock stores UTC even when the server is not on it');
check(abs(strtotime($storedTaken . ' UTC') - time()) <= 5,
      'and so does the moment the holder started');
check(abs(strtotime($storedActivity) - time()) > 3600,
      'read as local time those same strings are hours out — which is what used to happen');

$fresh = $store->forId($tz->id());
checkSame(true, $fresh->lockState()->isHeld(), 'a lock just taken reads as held, not as hours idle');
check($fresh->lockState()->idleSeconds() <= 5, 'and its idle age is seconds, not an offset');

$store->seizeLock($fresh, 2);
$storedSeize = $pdo->query("SELECT lock_activity_at FROM displays WHERE id = " . $tz->id())->fetchColumn();
check(abs(strtotime($storedSeize . ' UTC') - time()) <= 5, 'a takeover stores UTC too');
checkSame(true, $store->forId($tz->id())->lockState()->heldBy(2), 'and the Display is held by whoever took it');

// The boundary the two halves used to disagree about: LockState called exactly
// IDLE_LAPSE_SECONDS lapsed while the claim guard still called it held, so a
// second account got a read-write Builder for a Display the UPDATE would refuse.
ageTestLock($pdo, $tz->id(), LockState::IDLE_LAPSE_SECONDS);
$atBoundary = $store->forId($tz->id());
checkSame(false, $atBoundary->lockState()->isHeld(), 'at exactly the idle window the lock reads as free');
$claimed = $store->claimLock($atBoundary, 1);
checkSame(true, $claimed->lockState()->heldBy(1), 'and it can actually be claimed at that same moment');

date_default_timezone_set($tzWas);

// ─────────────────────────────────────────────────────────────
section('And a person reads it in the store\'s zone, not the server\'s (#44)');

// The other half of the section above, and the half that was still open. Storage
// being absolute is what makes the lock work; it says nothing about the sentence a
// person reads, and that sentence followed `date.timezone` — `America/Chicago` on the
// live host, while the store is in Washington. "editing since 2:15pm" printed 4:15pm.
//
// Two hours, and the checks below are deliberately not written against that number.
// This write-up first said seven, from an assumption that the host set nothing and fell
// back to UTC, and the host had never been looked at (§4ap). What the checks assert is
// that three zones *disagree* about one instant and that the label follows the setting;
// none of them would change if the host moved again, which is the only property a suite
// can honestly hold when the machine is somebody else's.
//
// The whole of it is now one module, so the rules are asserted on the module rather
// than through the four callers. The zone is a parameter with the setting as its
// default (§4o): a `define()` cannot be undone, so the property worth checking —
// the sentence follows the *setting* — is unreachable through the constant.

// ---- What counts as a zone ------------------------------------------------------
// A name, and only a name. The two refusals below are the substance: both build a
// perfectly valid DateTimeZone and both are wrong for half the year, which is this
// same defect with a smaller error bar.
checkSame(true,  StoreClock::isZone('America/Los_Angeles'), 'a region name is a zone');
checkSame(true,  StoreClock::isZone('UTC'),                 'and so is UTC, for a store that wants it');
checkSame(true,  StoreClock::isZone('Pacific/Honolulu'),    'and one that has never had daylight saving');
checkSame(false, StoreClock::isZone('+08:00'),  'a fixed offset is not — it is right for half the year');
checkSame(false, StoreClock::isZone('PST'),     'nor an abbreviation, for the same reason');
checkSame(false, StoreClock::isZone('America/Los Angeles'), 'nor a near miss');
checkSame(false, StoreClock::isZone(''),        'nor nothing');
checkSame(false, StoreClock::isZone(null),      'nor null');
checkSame(false, StoreClock::isZone(['UTC']),   'nor a list, which is what #27 was about');
// The one that pins the `true` flag on isZone()'s in_array, and the reason it needs its
// own line rather than joining the four above: `in_array(true, $zones, false)` is
// **true**, because every zone name is a non-empty string and therefore casts to true.
// So a loose comparison would accept a boolean as a time zone. Nothing else in this
// group can see that — null, '', a list and a float are all false under either flag —
// which is why relaxing the flag survived every check the first pass wrote (#50, §4aq).
checkSame(false, StoreClock::isZone(true),      'nor true, which a loose comparison would have taken');
checkSame(false, StoreClock::isZone(1.5),       'nor a number');

// ---- What a stored value means --------------------------------------------------
checkSame('America/Los_Angeles', StoreClock::pick('America/Los_Angeles'), 'a stored zone is used');
checkSame('Europe/London',       StoreClock::pick('Europe/London'),       'whichever one it is');
checkSame(StoreClock::DEFAULT_ZONE, StoreClock::pick('+08:00'), 'one that is not a zone falls back');
checkSame(StoreClock::DEFAULT_ZONE, StoreClock::pick(null),     'and so does an absent setting');
// The default is load-bearing rather than arbitrary. UTC is exactly what an unset
// date.timezone already gave, so a default of UTC would be this bug with a comment
// beside it — the mutation to check is the one that looks like caution.
checkSame(false, StoreClock::DEFAULT_ZONE === 'UTC',
          'and the default is not UTC, which is the value the defect already had');
checkSame(true, StoreClock::isZone(StoreClock::DEFAULT_ZONE), 'the default is itself a zone');

// ---- Which values get reported ---------------------------------------------------
// Absent is not unreadable: a config written before this setting existed simply does
// not define it, and a notice on every screen about nothing is a notice nobody reads.
//
// `unreadable(null)` means "read the constant", and this process has one — `auth.php`
// is required at the top of this file and pulls in `config.php`, which defines all ten
// branding names with their defaults. So this check is about a *present and usable*
// setting reporting nothing, which is worth having and is not what its label used to
// claim. The absent case is below, in a process that has loaded nothing: it is the one
// branch no check in here can reach, because a `define()` cannot be undone (#50, §4aq).
checkSame('', StoreClock::unreadable(null),                  'a setting this process can use is not something to report');
checkSame('', StoreClock::unreadable('Europe/Berlin'),       'nor is a usable one passed in');
checkSame('+08:00', StoreClock::unreadable('+08:00'),        'a stored offset is named exactly as stored');
checkSame('a array', StoreClock::unreadable(['UTC']),        'and a value with no sentence in it is named by type');
checkSame('', StoreClock::unreadable(''),                    'a setting stored blank reads short rather than quoting nothing');
checkSame('a integer', StoreClock::unreadable(0),            'and a number is named by type, like every other value with no sentence in it');

// ---- The three answers a process with no config gets -----------------------------
// Everything above runs with the constant defined, which leaves the module's other
// branch — nothing configured at all — unreachable. That is the state of every
// installation whose `branding_config.php` predates this setting, which is all of them
// until somebody saves the Settings form, so it is not a hypothetical: it is what the
// live sign is running today.
checkSame('|America/Los_Angeles|no', inFreshProcess('
        require LBM_ROOT . "/lib/store_clock.php";
        echo StoreClock::unreadable() . "|" . StoreClock::zone() . "|"
           . (defined("STORE_TIMEZONE") ? "yes" : "no");
    '), 'with nothing configured there is nothing to report, and the zone is the default');

// What `load()` is for, and its only statement. It reads the generated file for a
// caller with no app around it — a CLI tool, a report — and until now nothing observed
// that it did anything at all. Note what it does *not* do: the tracked config file was
// written before this setting existed, so reading it defines the eight branding names
// and leaves the zone absent, which is the line above still holding afterwards.
checkSame('no|yes|', inFreshProcess('
        require LBM_ROOT . "/lib/store_clock.php";
        $before = defined("BRAND_ACCENT") ? "yes" : "no";
        StoreClock::load();
        echo $before . "|" . (defined("BRAND_ACCENT") ? "yes" : "no") . "|" . StoreClock::unreadable();
    '), 'load() reads the generated file, which is the whole of what it promises');

// And the no-argument form really does consult the constant, rather than answering
// from its own default parameter — the two are indistinguishable in a process where
// the constant is absent, which is every process this suite can otherwise build.
checkSame('+08:00|America/Los_Angeles', inFreshProcess('
        define("STORE_TIMEZONE", "+08:00");
        require LBM_ROOT . "/lib/store_clock.php";
        echo StoreClock::unreadable() . "|" . StoreClock::zone();
    '), 'a stored offset is reported by the no-argument form, and the clock falls back past it');

// ---- A stored stamp is UTC, and one place knows it -------------------------------
// The rule was written out three times and the third copy left the ' UTC' off, which
// is the defect this consolidation exists for. Asserted with the process clock moved
// off UTC, because on a server that happens to be on it the mutation is invisible —
// which is exactly how the missing suffix survived.
$tzWas = date_default_timezone_get();
date_default_timezone_set('America/Los_Angeles');

$stamp = gmdate('Y-m-d H:i:s');
check(abs(StoreClock::epochOf($stamp) - time()) <= 5,
      'a stamp written by gmdate reads back as this moment, whatever zone the server is on');
check(abs(strtotime($stamp) - time()) > 3600,
      'read without the UTC suffix that same string is hours out — the line that got forgotten');
checkSame(0, StoreClock::epochOf('not a date'), 'a stamp that will not read is 0, not a warning');
checkSame(0, StoreClock::epochOf('0000-00-00 00:00:00'),
          'and so is the zero date, which reads as year zero rather than failing (§4bk)');
checkSame(0, StoreClock::epochOf('0000-00-00'), 'in either of the two shapes MySQL writes it');
checkSame(0, StoreClock::epochOf(''),           'and neither is an empty one');
checkSame(0, StoreClock::epochOf(null),         'nor a null column');

// ---- The sentence follows the setting, not the process ---------------------------
// One instant, three zones. 2026-08-11 21:15 UTC is 2:15pm in Washington — the very
// sentence #44 was filed about.
$instant = '2026-08-11 21:15:00';
checkSame('2:15pm', StoreClock::label($instant, 'g:ia', 'America/Los_Angeles'),
          'the moment a lock was taken reads as 2:15pm in the store');
checkSame('4:15pm', StoreClock::label($instant, 'g:ia', 'America/Chicago'),
          'and as 4:15pm in the zone the live host is set to, which is what was on the screen');
checkSame('9:15pm', StoreClock::label($instant, 'g:ia', 'UTC'),
          'and as 9:15pm in UTC, which is the widest of the three and none of the others');
checkSame('5:15pm', StoreClock::label($instant, 'g:ia', 'America/New_York'),
          'and as something else again three time zones east');
checkSame('Aug 11 at 2:15pm', StoreClock::label($instant, 'M j \a\t g:ia', 'America/Los_Angeles'),
          'the publish sentence uses the same door and the same zone');

// Not a function of the process clock. This is why label() converts explicitly
// instead of relying on what config.php set: viewer.php loads neither, and a
// formatter that depends on a global is one a caller cannot see being wrong about.
date_default_timezone_set('Asia/Tokyo');
checkSame('2:15pm', StoreClock::label($instant, 'g:ia', 'America/Los_Angeles'),
          'and it does not move when the process clock does');
date_default_timezone_set('America/Los_Angeles');

// A zone that is not one falls back rather than throwing, on the one path whose
// whole job is that an unconfigured clock does not break a screen.
checkSame('9:15pm', StoreClock::label($instant, 'g:ia', 'UTC'), 'a good zone is honoured');
checkSame(StoreClock::label($instant, 'g:ia', StoreClock::DEFAULT_ZONE),
          StoreClock::label($instant, 'g:ia', '+08:00'),
          'and a bad one is the documented default, not an exception on the page');
checkSame('', StoreClock::label('not a date', 'g:ia'),
          'an unreadable stamp is no words at all, so a refusal reads short rather than wrong');

// ---- Through the callers ---------------------------------------------------------
// These assert the store's answer rather than the server's — they would have read two
// hours out before this landed — and they say so *through* `StoreClock` rather than by
// naming a time. The clock arithmetic is a fixed point five lines up, where the zone is
// an argument and 2:15pm is written down; here the question is only whether the caller
// went through that door or read the column itself, which a bare `strtotime()` still
// fails by the hours the check above measures. Written as a literal these three passed
// only on a checkout with no `branding_config.php` in it, which is this one and CI and
// not the install they protect.
$pdo   = newTestDb();
$store = newTestDisplayStore($pdo);
$zoned = makeTestDisplay($pdo, 'zoned', 'Zoned');

// Two questions, and they used to be one check that was both flaky and weak. Comparing
// takenAtLabel() to labelForEpoch(time()) reads the clock a *second* time, so a claim
// and an assertion landing either side of a minute failed for a reason that has nothing
// to do with zones — which is what it did on 2026-08-19, once the MySQL leg was running
// far enough to reach these lines again and they went from one job a push to six. And it
// could not fail for the reason it was written for: both sides went through the store's
// door, so a stamp written in the wrong frame and read in the wrong frame agreed with
// each other. That cancelling pair is the whole of #44, asserted by a check blind to it.
//
// So: what claimLock() *writes* is UTC, measured against a UTC reference and not a label.
// Two checks and not one, because the first reads through epochOf() and would pass if
// epochOf() were broken the same way; the second is the half that notices. Removing
// either leaves the cancelling pair invisible again — which is how it got here.
$store->claimLock($zoned, 1);
$takenAt = $pdo->query("SELECT lock_taken_at FROM displays WHERE id = " . $zoned->id())->fetchColumn();
check(abs(StoreClock::epochOf($takenAt) - time()) <= 5,
      'claiming an edit lock records the moment in UTC, whatever zone the server is on');
check(abs(strtotime((string)$takenAt) - time()) > 3600,
      'so that same stamp read as local time is hours out — the banner\'s half of #44');

// And what the two callers *say* about it is the store's zone, measured against a known
// instant so the assertion is about the conversion rather than about two calls landing
// in the same minute.
$pdo->prepare("UPDATE displays SET lock_taken_at = ? WHERE id = ?")
    ->execute([$instant, $zoned->id()]);
checkSame(StoreClock::label($instant, 'g:ia'), $store->forId($zoned->id())->lockState()->takenAtLabel(),
          'a lock taken at 21:15 UTC is a lock taken at the store\'s own hour on the shop floor');
// The store's zone through the door rather than the literal `2:15pm` main wrote here:
// this suite now runs as a configured install (§4bg), so the zone is the install's and
// not this checkout's. `label()` itself is still pinned to a literal two blocks up, so
// the anchor did not move — it moved to where the zone is known.
checkMentions($store->forId($zoned->id())->editingSentence(),
              'since ' . StoreClock::label($instant, 'g:ia'),
              'and so does the sentence a refused publish prints');

// ---- The publish stamp was the third clock ---------------------------------------
// last_published_at was written with CURRENT_TIMESTAMP, which is MySQL's *session*
// zone — a clock neither PHP nor this store had any opinion about — and read back
// with a bare strtotime() as though PHP had written it. The two engines hid it from
// each other: SQLite's CURRENT_TIMESTAMP is UTC by definition, so the fixture always
// agreed with the reader. A bound gmdate() is the same string on both.
$layoutsZ = newTestLayoutStore($pdo);
$store->claimLock($zoned, 1);
$layoutsZ->publish($zoned, new PublishRequest([], Background::unchanged(), BrandChoice::unchanged(), 1, true, $zoned->layoutStamp()));
$publishedAt = $pdo->query("SELECT last_published_at FROM displays WHERE id = " . $zoned->id())->fetchColumn();
check(abs(StoreClock::epochOf($publishedAt) - time()) <= 5,
      'a publish records the moment in UTC, bound by PHP rather than asked of the database');
check(abs(strtotime((string)$publishedAt) - time()) > 3600,
      'so reading it as local time is hours out — which is what the old sentence did');

$pdo->prepare("UPDATE displays SET last_published_at = ?, last_published_by = 1 WHERE id = ?")
    ->execute([$instant, $zoned->id()]);
// `n/j/y g:ia` since the publish line moved into the canvas footer. The year is the
// point of the change rather than the brevity: without it a sign published last
// August and left alone reads as published this August, and "is what I'm looking at
// live?" is the one question this sentence exists to answer.
checkSame('sky, ' . StoreClock::label($instant, 'n/j/y g:ia'),
          $store->forId($zoned->id())->lastPublishDescription(),
          'and the refusal names the moment in the store\'s zone, year and all');
// Handed to the reader as a row for the Brand's reason one column along (§4bk): a
// DATETIME will not hold 'nonsense' on MySQL — strict mode raises 1292 and the whole run
// stops here — and `lastPublishDescription()` reads a row rather than a database, so the
// state this check is about does not need the column's permission to exist.
checkSame('sky', (new Display(['last_published_at'      => 'nonsense',
                               'last_published_by_name' => 'sky']))->lastPublishDescription(),
          'a stamp that will not read leaves the name rather than a half-written sentence');
// And the one unreadable stamp MySQL *can* hold, which is why the line above is not the
// whole check: a host running without strict mode, or a dump from one, leaves a zero
// date, and `strtotime()` reads that as a moment in year zero rather than failing.
checkSame('sky', (new Display(['last_published_at'      => '0000-00-00 00:00:00',
                               'last_published_by_name' => 'sky']))->lastPublishDescription(),
          'and so does the zero date a non-strict host writes, which does not fail to parse');

date_default_timezone_set($tzWas);

// ---- Where it is set, and where it is not ----------------------------------------
// Every page a person reads loads config.php through auth.php, so that is where the
// process clock is pointed at the store. Source checks, because what they are about
// is a line existing on a page rather than a decision a module makes.
$configSrc = file_get_contents(__DIR__ . '/../config.php');
checkMentions($configSrc, 'StoreClock::apply()',
              'config.php points the process clock at the store, once, for every page');
checkMentions(file_get_contents(__DIR__ . '/../db_connect.php'), "SET time_zone = '+00:00'",
              'and db_connect.php asks the database for UTC, which was the clock no screen showed');
checkSame(StoreClock::zone(), StoreClock::apply(),
          'apply() answers with the zone it set, so a caller can say which one it was');
checkSame(StoreClock::zone(), date_default_timezone_get(),
          'and the process is actually on it afterwards — config.php ran this on the way in');
// Which of those two is doing the work is invisible in a process whose zone is the
// default, and that is every process this suite can otherwise build: `apply()` could
// ignore the setting entirely and both lines above would still agree. One process that
// has been told a zone answers it.
checkSame('Pacific/Auckland|Pacific/Auckland', inFreshProcess('
        define("STORE_TIMEZONE", "Pacific/Auckland");
        require LBM_ROOT . "/lib/store_clock.php";
        $answered = StoreClock::apply();
        echo $answered . "|" . date_default_timezone_get();
    '), 'and the zone it sets is the one the store configured, not the one this app ships with');

// ---- The Settings form ------------------------------------------------------------
$panelTz = file_get_contents(__DIR__ . '/../admin_panel.php');
checkMentions($panelTz, 'name="store_timezone"', 'the Settings tab offers the setting');
checkMentions($panelTz, 'StoreClock::zones()',
              'as a list of the zones this app will accept, so nothing typeable can be wrong');
checkMentions($panelTz, 'StoreClock::isZone($_POST[\'store_timezone\'])',
              'and a submitted value is refused rather than substituted (#21)');
checkSame(1, preg_match('/\$curZone\s*=\s*StoreClock::zone\(\)/', $panelTz),
          'the form offers the zone the clock is actually using, not the raw stored string');
checkMentions($panelTz, "header('Location: admin_panel.php?tab=settings')",
              'and a saved setting redirects, since this request is still running on the old define()');
checkSame(0, preg_match('/date\(\s*.M j, Y.\s*,\s*strtotime/', $panelTz),
          'neither date printed on this page still reads a stamp for itself');

// ---- The report ------------------------------------------------------------------
// The card whose whole job was that a zone mismatch is otherwise invisible. It has
// to name all three clocks now, because there were three.
$tzReport = (new ServerReport($pdo, ['HTTPS' => 'on']))->runtime();
check(isset($tzReport[StoreClock::LABEL]), 'This Server reports the zone the app shows times in');
checkMentions($tzReport[StoreClock::LABEL][0], StoreClock::zone(), 'and says which one that is');
check(isset($tzReport['PHP time zone']), 'and the server\'s own, which no longer decides anything');
// The note on that row had been spelled inline against `ini_get('date.timezone')`,
// which meant it had one form on any machine that ran this suite and the other form
// on none of them: a host with no `date.timezone` line answers '', and `php -d
// date.timezone=` does not reproduce that — PHP rejects the empty value at startup
// and substitutes UTC. Seamed, both forms are reachable here.
checkSame('', ServerReport::phpZoneNoteFor('America/Chicago'),
          'a host that has set its own zone is told nothing about it');
checkSame('', ServerReport::phpZoneNoteFor(StoreClock::DEFAULT_ZONE),
          'and neither is one that happens to have set the app\'s own');
$noZone = ServerReport::phpZoneNoteFor('');
check($noZone !== '', 'a host that has set none does get a sentence');
checkMentions($noZone, 'Harmless',
              'and it opens by saying so, because this row used to mean times were hours out');
check(strpos($noZone, 'setting above') !== false,
      'and points at the store\'s own zone, which is the row that decides what a screen shows');
check(isset($tzReport['Database time zone']),
      'and the database\'s, which is where an account\'s creation date comes from');
// The value itself is the one thing here that genuinely differs by engine, so it is
// asserted the way `limitPublishLockWait()` below is: the engine *is* the expectation.
// `not applicable` is the catch in `databaseTimeZone()`, and it has to be reached on
// SQLite — where neither variable exists — and not reached on MySQL, where a real
// answer means the two queries ran.
checkSame(!testIsMysql(), $tzReport['Database time zone'][0] === 'not applicable',
          'the engine with no session zone at all says so, and the engine with one does not');
checkSame('', $tzReport['Database time zone'][1],
          'with nothing to warn about, because both engines here write their stamps in UTC');
// Seamed for the same reason `phpZoneNoteFor()` above it is, and the two forms this
// one had were the two engines: spelled inline, the row could only ever be asserted
// against whichever one the suite was started on, and the MySQL leg then failed on
// the value the SQLite leg had written down as correct (§4bl).
checkSame('', ServerReport::dbZoneNoteFor('not applicable'),
          'a database with no session zone at all has nothing to say about one');
checkSame('', ServerReport::dbZoneNoteFor('+00:00'),
          'and neither does the offset db_connect.php asks for');
checkSame('', ServerReport::dbZoneNoteFor('UTC'),
          'nor a host that normalises that to a name');
checkSame('', ServerReport::dbZoneNoteFor('SYSTEM (UTC)'),
          'nor one where the SET did not take and the host was already on UTC anyway');
$dbZoneWarn = ServerReport::dbZoneNoteFor('SYSTEM (CDT)');
check($dbZoneWarn !== '', 'while one where it did not take and the host is hours off is told');
checkMentions($dbZoneWarn, 'when an account was created',
              'and told which dates it is that may read wrong, rather than just that one does');
check(strpos($dbZoneWarn, 'Nothing a sign shows') !== false,
      'together with what is not affected, because a sign is what this app is for');
check(ServerReport::dbZoneNoteFor('America/Chicago') !== '',
      'a named zone that is not UTC is the same refusal by another spelling');
// The `trim()` inside the predicate, which survived §4bl's sweep. MySQL does not pad
// what it answers to `@@session.time_zone`, so the line is insurance rather than a
// path — but this is a public seam now, and one check is cheaper than the paragraph
// explaining why the line is allowed to be untested.
checkSame('', ServerReport::dbZoneNoteFor("  UTC \n"),
          'and a zone arriving with whitespace round it is still that zone');
checkSame('', $tzReport[StoreClock::LABEL][1],
          'and nothing to say about a setting this app can read');
// The note is the whole point of the row: a stored value nobody can use has to be
// named on the screen somebody would go looking at, or the setting and the clock
// disagree with no explanation anywhere (#21).
checkMentions((new ServerReport($pdo))->storeZoneNoteFor('+08:00'), '+08:00',
              'while one it cannot read is named exactly as stored');
checkMentions((new ServerReport($pdo))->storeZoneNoteFor('+08:00'), 'America/Los_Angeles',
              'together with what is being used instead of it');

// ─────────────────────────────────────────────────────────────
section('An account with no sign may read the shared library, not write it (#33)');

// The library is one pool behind every sign, and `uploads/` is one folder behind
// every library entry — neither is scoped to a Display, so neither is covered by the
// resolution seam that answers every other "may they?" in this app. A `basic` account
// with no grant could therefore add entries and upload files after the Builder had
// told it, in as many words, that no display was assigned to it.
$pdo   = newTestDb();
$store = newTestDisplayStore($pdo);
$deli  = makeTestDisplay($pdo, 'deli', 'Deli Case');
$lobby = makeTestDisplay($pdo, 'lobby', 'Lobby');
$all   = $store->all();

// Accounts 1 (admin) and 2 (clerk) are the fixture's.
checkSame(true, newTestActor($pdo, 1, 'admin')->holdsASign($all),
          'an admin holds every sign, so the library is theirs');
checkSame(false, newTestActor($pdo, 2, 'basic')->holdsASign($all),
          'a basic account with no grant holds none of them');

grantTestAccess($pdo, $lobby->id(), 2);
checkSame(true, newTestActor($pdo, 2, 'basic')->holdsASign($all),
          'one grant is enough — the library is a pool, not a per-sign store');

// A Display switched off is the case this predicate deliberately does *not* cover.
// Somebody whose one sign is out of service cannot open it, and can perfectly well be
// getting next week's promo into the library ready for it coming back — and the
// refusal's own wording ("no display has been assigned to you") would be a lie to
// them. Gating on openable() would also make turning a sign off silently take away a
// second thing on another page.
$pdo->exec("UPDATE displays SET is_active = 0 WHERE tag = 'lobby'");
$clerk = newTestActor($pdo, 2, 'basic');
$off   = $store->all();
checkSame(0, count($clerk->openable($off)), 'their only sign is turned off, so there is nothing to open');
checkSame(true, $clerk->holdsASign($off),   'and the library is still theirs, because the sign still is');
$pdo->exec("UPDATE displays SET is_active = 1 WHERE tag = 'lobby'");

// A grant row pointing at a Display that is gone is not a sign. Two things stand
// between the app and that state and only one of them is in this repo: the foreign key
// with its cascade, which this fixture does enforce — the insert below cannot be made
// through GrantStore at all — and the predicate reading the Display list rather than
// the grant list. Invariant 10 is why the second one is worth having: the constraint
// is added by convergence and may never have applied to the live table.
$stranded = Actor::withGrants(9, 'clerk', false, [4242]);
checkSame(false, $stranded->holdsASign($store->all()),
          'a grant naming a Display that is not there is not a sign');
checkSame([], $stranded->granted($store->all()),
          'because the Display list is what answers, not the grant row');

// A fresh installation, where an admin holds every Display and there are none. The
// one case where "holds every sign" and "the list is not empty" differ, and refusing
// there would aim the rule at the person about to add the first Display.
$empty = newTestDb();
checkSame([], newTestDisplayStore($empty)->all(), 'a fresh install has no Displays at all');
checkSame(true, newTestActor($empty, 1, 'admin')->holdsASign([]),
          'an admin may still add to the library there');
checkSame(false, newTestActor($empty, 2, 'basic')->holdsASign([]),
          'and a basic account still may not');

// ---- The two doors ------------------------------------------------------------
// Both are page-level gates and neither can be driven from here, so what is asserted
// is that both ask the one predicate, word the refusal from the one sentence, and ask
// before any file is moved. `move_uploaded_file()` cannot be rolled back, so a gate
// below it leaves exactly what it exists to prevent, minus the row.
//
// Each door is read from where it opens, not from the top of the file: api.php moves
// an uploaded background hundreds of lines above this endpoint, and that upload is an
// admin's and is scoped to a Display, so a check measuring against the file's first
// `move_uploaded_file` would be asking about the wrong one. The gate token differs
// too — crud.php asks the predicate once at the top, because the page needs the answer
// to decide whether to draw the form at all, and carries it into the branch as
// `$mayAdd`; api.php has only the one use and asks it there.
$doors = [
    'crud.php' => ['opens' => "isset(\$_POST['action_create'])", 'gate' => '$mayAdd'],
    'api.php'  => ['opens' => "\$action === 'upload_file'",      'gate' => 'holdsASign('],
];
foreach ($doors as $door => $where) {
    // Comments dropped first, and this check needed it to be right about itself: both
    // doors carry a comment saying `move_uploaded_file()` cannot be undone, sitting
    // *above* the gate that comment is explaining — so measured against raw source, a
    // correctly ordered file failed, for the same reason check_invariants.php strips
    // comments before it greps anything.
    $src = '';
    foreach (token_get_all(file_get_contents(__DIR__ . '/../' . $door)) as $token) {
        if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) { continue; }
        $src .= is_array($token) ? $token[1] : $token;
    }
    checkMentions($src, 'holdsASign(', $door . ' asks the one predicate');
    checkMentions($src, 'Actor::NO_SIGN_REFUSAL', 'and refuses in the one wording');

    $opens = strpos($src, $where['opens']);
    check($opens !== false, 'and the write it guards is still where this check looks for it');
    $gate  = $opens === false ? false : strpos($src, $where['gate'], $opens);
    $moves = $opens === false ? false : strpos($src, 'move_uploaded_file', $opens);
    check($gate !== false && $moves !== false && $gate < $moves,
          'and refuses before that door moves an uploaded file, which cannot be undone');
}

// The sentence itself has to survive rewording without losing either half: what did
// not happen, and who can change it.
checkMentions(Actor::NO_SIGN_REFUSAL, 'assigned to you',   'the refusal says why it refused');
checkMentions(Actor::NO_SIGN_REFUSAL, 'Ask an admin',      'and who to ask, since nothing here helps');
checkMentions(Actor::NO_SIGN_REFUSAL, 'nothing was saved', 'and that nothing was written');

// The Library's edit form is drawn for an admin only now — it always saved for an
// admin only, and drawing it for anybody who typed `?edit_id=` was a form that
// existed in order to be refused (§4j). It also chose which panel the page shows, so
// leaving it would have put the "no sign assigned" notice one query parameter away
// from an editor.
$crudSource = file_get_contents(__DIR__ . '/../crud.php');
check(preg_match('/\$editAsset\s*=\s*\(isAdmin\(\)\s*&&/', $crudSource) === 1,
      'and the edit form is built for whoever the save will accept, nobody else');

// ─────────────────────────────────────────────────────────────
section('An Asset Library entry knows which signs depend on it');

$pdo     = newTestDb();
$store   = newTestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$a = makeTestDisplay($pdo, 'aa', 'Sign A');
$b = makeTestDisplay($pdo, 'bb', 'Sign B');

// Both signs publish the same words. This used to hand them one shared row,
// because the pool de-duplicated by exact content — and a single delete then
// blanked that line on both of them, permanently. Two rows now.
$shared = ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'Sockeye  18.99',
           'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
$layouts->publish($a, new PublishRequest([$shared], Background::unchanged(), BrandChoice::unchanged(), 1, true, $a->layoutStamp()));
$store->releaseLock($a, 1);
$layouts->publish($b, new PublishRequest([$shared], Background::unchanged(), BrandChoice::unchanged(), 1, true, $b->layoutStamp()));
$store->releaseLock($b, 1);

$assetId = intval($pdo->query("SELECT id FROM assets ORDER BY id ASC LIMIT 1")->fetchColumn());
check($assetId > 0, 'publishing a text block put its words in the shared library');
checkSame(2, intval($pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn()),
          'two signs publishing the same words get a row each, not one between them');
checkSame(1, count($pdo->query("SELECT id FROM canvas_elements WHERE asset_id = " . $assetId)->fetchAll()),
          'so one entry reaches exactly one block');

// The delete that used to take out two signs at once now takes out one, and the
// Library page is told which — this is the read that lets it refuse.
$usage = $layouts->assetUsage($assetId);
checkSame(1, $usage['elements'],        'the library can see how many blocks depend on an entry');
checkSame(1, count($usage['displays']), 'and which display that is');

$idle = $layouts->assetUsage(999999);
checkSame(0, $idle['elements'], 'an entry nothing uses reports no blocks');
checkSame([], $idle['displays'], 'and no displays');

// The reason it matters: the element keeps no copy of its own text.
$own = $pdo->query("SELECT manual_content FROM canvas_elements WHERE asset_id = " . $assetId)->fetchAll();
checkSame([null], array_column($own, 'manual_content'),
          'a pooled block holds no content of its own, so losing the entry loses the words');

// A row shared by two signs still exists in a database that ran the old code, and
// it is not this change's job to unpick one — it is its job never to delete one.
$pdo->exec("UPDATE canvas_elements SET asset_id = " . $assetId);
$legacy = $layouts->assetUsage($assetId);
checkSame(2, $legacy['elements'],        'a row two signs already share is still reported as shared');
checkSame(2, count($legacy['displays']), 'on both of them');

// ─────────────────────────────────────────────────────────────
section('Publishing clears up the copies it leaves behind');

// The cost of not sharing rows: publishing copies a text block's words into the
// library, so the third time somebody fixes a typo the first two copies are
// pointed at by nothing. Rows nothing points at are what an admin scrolls past
// looking for the promo banner.
$pdo     = newTestDb();
$store   = newTestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$library = new AssetLibrary($pdo);
$sign    = makeTestDisplay($pdo, 'sweep', 'Deli Board');

$block = function ($words) {
    return ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => $words,
            'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
};

$fresh = $store->forId($sign->id());
$layouts->publish($sign, new PublishRequest([$block('Sockeye  18.99')], Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  19.99')], Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  21.99')], Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));

checkSame(1, intval($pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn()),
          'three publishes of one block leave one library entry, not three');
checkSame('Sockeye  21.99', $pdo->query("SELECT content FROM assets")->fetchColumn(),
          'and it is the words that are on the sign now');
checkSame(1, intval($pdo->query("SELECT COUNT(*) FROM canvas_elements WHERE asset_id IS NOT NULL")->fetchColumn()),
          'the block still points at it — the sweep did not cut the line it was reading');

// The label a publish gives a pooled row is what an admin scrolls the Library
// looking for, so it goes through the same sanitiser the content does. strip_tags
// on its own deletes from a "<" onwards, which labelled this row "Auto: Kids " and
// left the only clue to which block it belongs to on the cutting-room floor.
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Kids <12 eat free')], Background::unchanged(), BrandChoice::unchanged(),
                                             1, true, $fresh->layoutStamp()));
checkSame('Auto: Kids <12 eat free',
          $pdo->query("SELECT label FROM assets WHERE content = 'Kids <12 eat free'")->fetchColumn(),
          'an auto-saved row is labelled with the whole line, "<" and all');

// What the sweep must never touch: a row a person made. An unused one is not
// junk, it is the image somebody uploaded ready for next week.
$mine = $library->create('image', 'uploads/promo.jpg', 'Summer Promo Banner');
check($mine > 0, 'an admin can still add an entry of their own');
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  22.99')], Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));
check($library->forId($mine) !== null,
      'and publishing does not sweep it away, though no sign uses it');

// A row somebody has renamed is theirs, whatever created it.
$autoId = intval($pdo->query("SELECT id FROM assets WHERE auto_pooled = 1")->fetchColumn());
$library->update($autoId, 'Sockeye price', 'Sockeye  22.99');
checkSame(0, intval($pdo->query("SELECT auto_pooled FROM assets WHERE id = " . $autoId)->fetchColumn()),
          'naming an auto-saved entry makes it yours');
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  23.99')], Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));
check($library->forId($autoId) !== null,
      'so the next publish leaves it alone even once nothing points at it');

// The half a publish cannot reach: a block deleted from the admin Work Area
// releases its entry with no publish anywhere near it. That is the tidy button.
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Halibut  26.99')], Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));
$live = intval($pdo->query("SELECT id FROM canvas_elements WHERE asset_id IS NOT NULL")->fetchColumn());
$fresh = $store->forId($sign->id());
$layouts->deleteElement($fresh, $live, 1);

$orphans = $library->pooledNotIn($layouts->referencedAssetIds());
checkSame(1, count($orphans), 'deleting a block in the Work Area strands its auto-saved entry');
checkSame(1, $library->discardPooled($orphans), 'and the tidy-up removes it');
check($library->forId($mine) !== null,   'without touching the entry an admin uploaded');
check($library->forId($autoId) !== null, 'or the one an admin renamed');

// Told to remove a row a person made, AssetLibrary refuses. The caller counts the
// references and could get that wrong; this is the predicate that means a wrong
// count can only ever leave the library untidy.
checkSame(0, $library->discardPooled([$mine, $autoId]),
          'a caller that asks for a hand-made entry is refused outright');
checkSame(2, intval($pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn()),
          'so both are still there');

// referencedAssetIds() must say "I could not tell" rather than "nothing", because
// an empty list would sweep the entire pool.
$broken = newTestDb();
$broken->exec("DROP TABLE canvas_elements");
checkSame(null, (newTestLayoutStore($broken))->referencedAssetIds(),
          'a reference count that could not be read is null, never an empty list');

// ─────────────────────────────────────────────────────────────
section('The sweep looks at every sign, not just the one publishing');

// A row two Displays share exists in any database that ran the de-duplicating
// version. When one of them publishes something else, that row stops being this
// Display's — and is still the other one's. `asset_id` is ON DELETE SET NULL, so
// sweeping it here would blank a line over there with nothing to say so, which is
// the exact failure that ended the sharing in the first place.
$pdo     = newTestDb();
$store   = newTestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$library = new AssetLibrary($pdo);
$one = makeTestDisplay($pdo, 'one', 'Deli Board');
$two = makeTestDisplay($pdo, 'two', 'Lobby Screen');

$words = ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'OPEN 7 DAYS',
          'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
$layouts->publish($one, new PublishRequest([$words], Background::unchanged(), BrandChoice::unchanged(), 1, true, $one->layoutStamp()));
$store->releaseLock($one, 1);
$layouts->publish($two, new PublishRequest([$words], Background::unchanged(), BrandChoice::unchanged(), 1, true, $two->layoutStamp()));
$store->releaseLock($two, 1);

// Point both signs at one row, the way the old pooling did.
$sharedId = intval($pdo->query("SELECT MIN(id) FROM assets")->fetchColumn());
$pdo->exec("UPDATE canvas_elements SET asset_id = " . $sharedId);
checkSame(2, $layouts->assetUsage($sharedId)['elements'], 'two signs now share one entry, as the old code left them');

// The first sign publishes something else entirely. Its own layout no longer
// points at the shared row; the other sign's still does.
$fresh = $store->forId($one->id());
$layouts->publish($fresh, new PublishRequest(
    [['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'CLOSED SUNDAYS',
      'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60]],
    Background::unchanged(), BrandChoice::unchanged(), 1, true, $fresh->layoutStamp()));

check($library->forId($sharedId) !== null,
      'publishing one sign does not sweep an entry the other sign still uses');
checkSame(1, $layouts->assetUsage($sharedId)['elements'],
          'and that sign still reads its words from it');

// ─────────────────────────────────────────────────────────────
section('A library that cannot be written to leaves the words on the block');

// The pool row is where a published text block's words *move to*. If that write
// fails and the block is pointed at the row anyway, the words are nowhere: the
// element's own copy was cleared and the row does not exist. The line goes blank
// on the sign, and there is no undo. So a failed pool leaves the content where it
// already was — which renders.
// The table has to stay in place — `canvas_elements.asset_id` references it, so
// dropping it would fail the element insert as well and prove nothing about the
// pool. A trigger refuses exactly the one write under test.
$noLib   = newTestDb();
makeTableUnwritable($noLib, 'assets');
$noStore = newTestDisplayStore($noLib);
$noLay   = newTestLayoutStore($noLib);
$noSign  = makeTestDisplay($noLib, 'nolib', 'Deli Board');

checkSame(0, (new AssetLibrary($noLib))->pool('text', 'Sockeye  18.99'),
          'a pool write that cannot happen returns no id, rather than id 0 as a link');

$result = $noLay->publish($noSign, new PublishRequest(
    [['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'Sockeye  18.99',
      'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60]],
    Background::unchanged(), BrandChoice::unchanged(), 1, true, $noSign->layoutStamp()));
checkSame(true, $result->isOk(), 'the publish still succeeds');
$kept = $noLib->query("SELECT manual_content, asset_id FROM canvas_elements")->fetch();
checkSame('Sockeye  18.99', $kept['manual_content'], 'and the words stay on the block, where they render');
checkSame(null, $kept['asset_id'],   'pointing at nothing at all rather than at a row that does not exist');

// ─────────────────────────────────────────────────────────────
section('A library entry\'s type is read from the row, not from the form');

// The edit form used to post the type back in a hidden field, and that field
// decided both of the rules an edit has to pass: plain text for a text entry
// (ADR-0002), the image allow-list for an image entry. Either could be switched
// off by sending the other word. `update()` takes no type at all now — it reads
// the row — so the checks below are the rules being unable to be skipped rather
// than being remembered.
$pdo     = newTestDb();
$library = new AssetLibrary($pdo);

$textId = $library->create('text', 'Sockeye  18.99', 'Sockeye price');
$imgId  = $library->create('image', 'uploads/promo.jpg', 'Summer Promo');
check($textId > 0, 'an admin adds a text entry');
check($imgId > 0,  'and an image entry');

// The one moment a caller's word for the type is taken is creation, and it is
// taken only for the two kinds the add form offers.
checkSame(0, $library->create('carousel', '{"slides":[]}', 'Forged'),
          'a type the add form does not offer is refused rather than stored');

// ---- A text entry is plain text however the caller words it -------------------
$res = $library->update($textId, 'Sockeye price', "<b>Sockeye</b><script>alert(1)</script>\n18.99");
checkSame(true, $res->isOk(), 'a text entry accepts an edit');
$stored = $pdo->query("SELECT content FROM assets WHERE id = " . $textId)->fetchColumn();
check(strpos($stored, '<') === false,
      'and the markup is stripped on the way in, with no form field able to say otherwise');

// `!empty()` — the page's old guard — is false for a price block reading exactly
// zero, so the one legitimate falsy value was refused as if the form were empty.
$res = $library->update($textId, 'Sockeye price', '0');
checkSame(true, $res->isOk(), 'an entry reading exactly "0" is a real edit, not an empty one');
checkSame('0', $pdo->query("SELECT content FROM assets WHERE id = " . $textId)->fetchColumn(),
          'and it is stored as "0"');

$res = $library->update($textId, 'Sockeye price', "   \n  ");
checkSame(AssetEdit::REFUSED, $res->kind(),
          'emptying an entry is refused — every block reading it would go blank');
checkSame('0', $pdo->query("SELECT content FROM assets WHERE id = " . $textId)->fetchColumn(),
          'and the words that were there are still there');

// ---- An image entry is checked against the allow-list, always -----------------
checkSame(true, $library->update($imgId, 'Summer Promo', 'uploads/next-week.png')->isOk(),
          'an image entry accepts an image');
$res = $library->update($imgId, 'Summer Promo', 'https://elsewhere.example/logo.svg');
checkSame(AssetEdit::REFUSED, $res->kind(),
          'and refuses an .svg on another host, which the hidden field could switch off');
checkSame('uploads/next-week.png', $pdo->query("SELECT content FROM assets WHERE id = " . $imgId)->fetchColumn(),
          'leaving the image the signs are showing untouched');
checkMentions($res->message(), 'Nothing was changed', 'and says so');

// ---- No such row is not a successful save -------------------------------------
// The bare UPDATE this replaced matched nothing and returned true, so deleting an
// entry in one tab and saving it in another printed "Asset updated successfully".
$res = $library->update(999999, 'Ghost', 'uploads/ghost.png');
checkSame(AssetEdit::MISSING, $res->kind(), 'saving an entry that no longer exists is refused');
checkSame(false, $res->isOk(), 'and is never reported as a save');

// ---- The type the add form never offers and a publish does --------------------
// `assets.type` is ENUM('text','image','video'), and a `video` row is the only kind
// in the library nobody can create by hand: the add form offers two, and the third
// arrives when a publish pools a video block that carries its own path. It is the
// case the Library's edit form has a whole third branch for — not text, so nothing
// may strip it, and not an image, so the allow-list must not be reached for it.
//
// This block used to build a `carousel` row and assert that it was ordinary. It is
// not: the column refuses it, and the Builder never asks — `pool: false` on all three
// of the types that carry a block's settings rather than a piece of content. Two
// comments in this app say those rows exist and the schema and the Builder say they
// cannot, and the suite had been asserting the wrong pair for as long as SQLite was
// the only engine it ran on, since SQLite has no ENUM to refuse anything (§4bl).
$videoId = $library->pool('video', 'uploads/deli-loop.mp4');
check($videoId > 0, 'publishing a video block that carries its own path pools it');
$res = $library->update($videoId, 'Deli loop', 'uploads/deli-loop-v2.mp4');
checkSame(true, $res->isOk(), 'and that entry can still be edited');
checkSame('uploads/deli-loop-v2.mp4',
          $pdo->query("SELECT content FROM assets WHERE id = " . $videoId)->fetchColumn(),
          'without being held to the image allow-list, which no .mp4 could ever pass');
checkSame('video', $pdo->query("SELECT type FROM assets WHERE id = " . $videoId)->fetchColumn(),
          'and no edit anywhere changes what kind of entry a row is');

// The agreement that makes the three above the whole list, read out of the two files
// that have to hold it rather than restated here. A block type added to the Builder's
// poolable set and not to the column is a publish whose "save to library" silently
// does nothing; added to the column and not the Builder, it is a member no row can
// ever have. Neither says anything on any screen.
$assetsEnum = [];
if (preg_match('/CREATE TABLE IF NOT EXISTS assets\b.*?\n\s*type\s+ENUM\(([^)]*)\)/s',
               file_get_contents(__DIR__ . '/../schema.sql'), $enumMatch)) {
    foreach (explode(',', $enumMatch[1]) as $member) { $assetsEnum[] = trim($member, " '"); }
}
checkSame(['text', 'image', 'video'], $assetsEnum,
          'the library column offers exactly the three kinds a publish can pool');
$poolJs = file_get_contents(__DIR__ . '/../builder.php');
foreach (['carousel', 'table', 'marquee'] as $settingsType) {
    checkSame(1, preg_match('/type === \'' . $settingsType . '\'[^\n]*pool: false/', $poolJs),
              'and the Builder never asks the library to hold a ' . $settingsType);
}

// ---- The allow-list itself ----------------------------------------------------
checkSame(true,  AssetLibrary::isAllowedImageRef('uploads/a.jpg'),        'a jpg is an image');
checkSame(true,  AssetLibrary::isAllowedImageRef('uploads/A.PNG'),        'so is a PNG in capitals');
checkSame(true,  AssetLibrary::isAllowedImageRef('uploads/a.png|contain'), 'the Builder\'s |fit suffix is not part of the name');
checkSame(true,  AssetLibrary::isAllowedImageRef('uploads/a.png?v=2'),    'nor is a query string');
checkSame(false, AssetLibrary::isAllowedImageRef('uploads/a.svg?v=.png'), 'and a query string cannot disguise one');
checkSame(false, AssetLibrary::isAllowedImageRef('uploads/a.svg'),        'an svg is markup a browser runs');
checkSame(false, AssetLibrary::isAllowedImageRef('uploads/a'),            'something with no extension is not an image');
checkSame(false, AssetLibrary::isAllowedImageRef(''),                     'and neither is nothing at all');

// ---- What the rules say about the rows that were already here -----------------
// Nothing rewrites them: changing stored content on read is a write nobody asked
// for, on a table with no undo. What was wrong is that the state was invisible, so
// the Library marks them and the decision stays a person's.
checkSame(null, AssetLibrary::contentIssue(['type' => 'text', 'content' => 'Sockeye  18.99']),
          'an ordinary text entry has nothing to say about it');
checkSame(null, AssetLibrary::contentIssue(['type' => 'image', 'content' => 'uploads/promo.jpg']),
          'nor an ordinary image entry');
checkSame(null, AssetLibrary::contentIssue(['type' => 'carousel', 'content' => '{"slides":[]}']),
          'nor a pooled carousel entry, whose JSON is not markup to strip');
checkMentions(AssetLibrary::contentIssue(['type' => 'image', 'content' => 'uploads/old.svg']),
              'no longer allows', 'an image entry left pointing at an svg is marked');
checkMentions(AssetLibrary::contentIssue(['type' => 'text', 'content' => 'Fresh <b>today</b>']),
              'formatting', 'a text entry holding markup from before ADR-0002 is marked');
checkMentions(AssetLibrary::contentIssue(['type' => 'text', 'content' => '']),
              'empty', 'and so is one that is empty, whatever emptied it');
// The text test is the exact predicate "saving this would change it", so a row that
// merely reads oddly is not accused of anything.
checkSame(null, AssetLibrary::contentIssue(['type' => 'text', 'content' => 'Halibut < 5 lb']),
          'a stray angle bracket that saving would keep is not a mark against a row');

// The field itself must be gone, not merely unread: a hidden input still on the
// page is one `$_POST` read away from deciding this again. (The name survives in
// crud.php's prose, which is why these look for the two places it would be code.)
$crudSource = file_get_contents(__DIR__ . '/../crud.php');
check(strpos($crudSource, "'edit_type'") === false,
      'the editor reads no type from the request');
check(strpos($crudSource, 'name="edit_type"') === false,
      'and does not send one for it to read');

// ─────────────────────────────────────────────────────────────
section('A reset code gets five guesses in total, not five per browser');

// The defect these cover: the budget used to live in $_SESSION, which belongs to
// whoever is guessing. Clearing a cookie bought five more tries against the one
// live code, so the six-digit space was reachable in an evening. Every check here
// is written the way the attack was — a *new* caller each time, with no shared
// state — because that is exactly what a fresh cookie jar looks like to the
// server, and it is the only way to tell an account-keyed limiter from a
// session-keyed one.
$rPdo = newTestDb();

$code = (new ResetTokenStore($rPdo))->issue(1);
checkSame(6, strlen($code),          'issuing a code returns six digits');
checkSame(1, resetTokenCount($rPdo, 1), 'and stores exactly one token for the account');
checkSame(0, (new ResetTokenStore($rPdo))->attemptsSpent(1), 'a fresh code has spent no guesses');

// Four wrong guesses, each from a store built from scratch.
for ($i = 1; $i <= 4; $i++) {
    checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, '000000'),
              'wrong guess ' . $i . ' is refused');
}
checkSame(4, (new ResetTokenStore($rPdo))->attemptsSpent(1),
          'and all four were counted against the code, not against a session');

// The one that matters: the right code still works while budget remains.
checkSame(true, (new ResetTokenStore($rPdo))->redeem(1, $code), 'the real code is accepted on the fifth try');
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, $code),
          'and cannot be used a second time — a consumed code is dead');

// Now spend the budget completely and prove the code dies with it.
$rPdo = newTestDb();
$code = (new ResetTokenStore($rPdo))->issue(1);
for ($i = 1; $i <= 5; $i++) { (new ResetTokenStore($rPdo))->redeem(1, '000000'); }
checkSame(0, resetTokenCount($rPdo, 1), 'the fifth wrong guess destroys the token');
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, $code),
          'so the correct code is worthless afterwards — this is what a cleared cookie used to undo');

// A sixth guess must not resurrect a budget, however it is presented.
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, '000001'), 'and a sixth guess is refused too');

// Issuing again is the only way back, and it starts a fresh budget with a fresh
// code — the old one must not survive the reissue.
$rPdo  = newTestDb();
$first = (new ResetTokenStore($rPdo))->issue(1);
(new ResetTokenStore($rPdo))->redeem(1, '000000');
$second = (new ResetTokenStore($rPdo))->issue(1);
checkSame(1, resetTokenCount($rPdo, 1), 'requesting a new code leaves only one token');
checkSame(0, (new ResetTokenStore($rPdo))->attemptsSpent(1), 'with the guess budget back at zero');
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, $first), 'the superseded code no longer works');
checkSame(true,  (new ResetTokenStore($rPdo))->redeem(1, $second), 'and the new one does');

// Expiry is enforced by the same statement that spends the guess, so an expired
// code cannot be guessed at at all.
$rPdo = newTestDb();
$code = (new ResetTokenStore($rPdo))->issue(1);
expireTestResetToken($rPdo, 1);
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, $code), 'an expired code is refused even when correct');
checkSame(0, resetTokenCount($rPdo, 1), 'and is cleared away rather than left to be guessed at');

// An account that does not exist is the enumeration case: someone typed a
// username matching nobody, and step 2 must behave identically. There is no
// token for id 0, so there is nothing to find and nothing to leak.
$rPdo = newTestDb();
checkSame(false, (new ResetTokenStore($rPdo))->redeem(0, '000000'), 'a guess for no account is refused');
checkSame(false, (new ResetTokenStore($rPdo))->redeem(0, ''),       'including an empty one');
checkSame(0, resetTokenCount($rPdo, 0), 'and creates nothing');

// One account's budget is not another's. Both hold codes; burning one out must
// leave the other untouched.
$rPdo   = newTestDb();
$codeA  = (new ResetTokenStore($rPdo))->issue(1);
$codeB  = (new ResetTokenStore($rPdo))->issue(2);
for ($i = 1; $i <= 5; $i++) { (new ResetTokenStore($rPdo))->redeem(1, '000000'); }
checkSame(0, resetTokenCount($rPdo, 1), 'burning one account\'s budget destroys its token');
checkSame(1, resetTokenCount($rPdo, 2), 'and leaves the other account\'s alone');
checkSame(0, (new ResetTokenStore($rPdo))->attemptsSpent(2), 'with no guesses charged to it');
checkSame(true, (new ResetTokenStore($rPdo))->redeem(2, $codeB), 'so the other account can still reset');

// A near-miss must not be accepted by a loose comparison: '000000' and 0 and
// '0' are all different things to a six-digit code, and hash_equals is typed.
$rPdo = newTestDb();
(new ResetTokenStore($rPdo))->issue(1);
$rPdo->exec("UPDATE password_resets SET passcode = '000123' WHERE user_id = 1");
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, '123'),  'a code without its leading zeros is not the code');
checkSame(false, (new ResetTokenStore($rPdo))->redeem(1, '0001230'), 'nor is one with a digit added');
checkSame(true,  (new ResetTokenStore($rPdo))->redeem(1, '000123'), 'the exact six characters are');

// ─────────────────────────────────────────────────────────────
section('A reset either changes the password or changes nothing');

// The defect: the last step of a reset was three writes in a row with nothing
// tying them together — consume the code, set the password, clear the lockout.
// A failure after the first left the code spent and the password as it was, and
// told the person their reset had failed; the third assumed columns a runtime
// ALTER may never have added, so on such a database it threw *after* the password
// had already changed. Both end the same way: somebody is told the opposite of
// what happened, on the one flow where they cannot check.
//
// Two properties pull opposite ways and both are asserted below. The guess must
// survive a rollback — otherwise a failed write hands the guesser their five tries
// back — and the consume must not, because it is half of the change itself.

/** A store whose password write refuses, standing in for a database that will not take it. */
class RefusingAccountStore extends AccountStore
{
    public function setPassword($accountId, $passwordHash) { return false; }
}
/** And one that fails the way a real database does: by throwing. */
class ThrowingAccountStore extends AccountStore
{
    public function setPassword($accountId, $passwordHash)
    {
        throw new RuntimeException('the users table is not available');
    }
}

function storedHash(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([intval($accountId)]);
    return (string)$stmt->fetchColumn();
}
function newCompletion(PDO $pdo, ?AccountStore $accounts = null)
{
    return new PasswordResetCompletion($pdo, new ResetTokenStore($pdo),
        $accounts ? $accounts : new AccountStore($pdo));
}

// ---- The two halves of a guess, now that they are separable ----
$rPdo  = newTestDb();
$store = new ResetTokenStore($rPdo);
$code  = $store->issue(1);

$tokenId = $store->verify(1, $code);
check($tokenId > 0, 'verifying the right code names the token it matched');
checkSame(false, $store->isSpent($tokenId), 'and leaves it unspent — verifying is not consuming');
checkSame(1, $store->attemptsSpent(1),      'while still charging the guess');
checkSame($tokenId, $store->verify(1, $code), 'so the same code verifies again');
checkSame(2, $store->attemptsSpent(1),        'at the cost of a second guess');
checkSame(true,  $store->consume($tokenId), 'consuming it succeeds once');
checkSame(false, $store->consume($tokenId), 'and never twice, whoever asks');
checkSame(0, $store->verify(1, $code),      'after which the code verifies no more');

// ---- The happy path, end to end ----
$rPdo = newTestDb();
$rPdo->exec("UPDATE users SET failed_attempts = 5, last_failed_at = '2026-01-01 00:00:00',
                              locked_until = '2099-01-01 00:00:00' WHERE id = 1");
$was  = storedHash($rPdo, 1);
$code = (new ResetTokenStore($rPdo))->issue(1);

$outcome = newCompletion($rPdo)->complete(1, $code, 'a-brand-new-password');
checkSame(true, $outcome->isOk(), 'a right code and a new password complete the reset');
checkSame('ok', $outcome->kind(), 'which is what the outcome says it is');
check(storedHash($rPdo, 1) !== $was, 'the stored hash changed');
check(password_verify('a-brand-new-password', storedHash($rPdo, 1)),
      'and the new password verifies against it');
check(strpos(storedHash($rPdo, 1), 'a-brand-new-password') === false,
      'the password itself was never stored, only a hash of it');

$lock = $rPdo->query("SELECT failed_attempts, last_failed_at, locked_until FROM users WHERE id = 1")->fetch();
checkSame(0,    intval($lock['failed_attempts']), 'the login lockout counter is cleared');
checkSame(null, $lock['locked_until'],            'and the lock itself is lifted');
checkSame(null, $lock['last_failed_at'],          'and the failure it remembered is forgotten');

$again = newCompletion($rPdo)->complete(1, $code, 'a-third-password');
checkSame(true, $again->isRefused(), 'the same code cannot be redeemed a second time');
check(password_verify('a-brand-new-password', storedHash($rPdo, 1)),
      'and the password it already set is still the one on the account');

// ---- A password write that refuses: nothing changed, and the guess still spent ----
$rPdo = newTestDb();
$was  = storedHash($rPdo, 1);
$code = (new ResetTokenStore($rPdo))->issue(1);
$tokenId = intval($rPdo->query("SELECT id FROM password_resets WHERE user_id = 1")->fetchColumn());

$outcome = newCompletion($rPdo, new RefusingAccountStore($rPdo))->complete(1, $code, 'never-lands');
checkSame(false,    $outcome->isOk(),      'a refused password write does not report success');
checkSame(false,    $outcome->isRefused(), 'and does not claim the code was wrong, because it was not');
checkSame('failed', $outcome->kind(),      'it is a failure, which is a third answer');
checkSame($was, storedHash($rPdo, 1), 'the password is exactly as it was');
checkSame(false, (new ResetTokenStore($rPdo))->isSpent($tokenId),
          'the code was not consumed, so the person can simply try again');
checkSame(1, (new ResetTokenStore($rPdo))->attemptsSpent(1),
          'but the guess it cost is spent for good — a rollback must not refund the budget');
checkSame(false, $rPdo->inTransaction(), 'and no transaction is left open behind it');

$retry = newCompletion($rPdo)->complete(1, $code, 'lands-this-time');
checkSame(true, $retry->isOk(), 'the same code still works once the database will take the write');
check(password_verify('lands-this-time', storedHash($rPdo, 1)), 'and the password is the new one');
// Read straight off the row: attemptsSpent() only looks at codes still live, and
// this one has just been consumed by the reset that succeeded.
$spent = $rPdo->prepare("SELECT attempts FROM password_resets WHERE id = ?");
$spent->execute([$tokenId]);
checkSame(2, intval($spent->fetchColumn()),
          'having charged one guess per attempt, including the one that failed');

// ---- And one that throws, which is how a real database refuses ----
$rPdo = newTestDb();
$was  = storedHash($rPdo, 1);
$code = (new ResetTokenStore($rPdo))->issue(1);
$tokenId = intval($rPdo->query("SELECT id FROM password_resets WHERE user_id = 1")->fetchColumn());

$outcome = newCompletion($rPdo, new ThrowingAccountStore($rPdo))->complete(1, $code, 'never-lands');
checkSame('failed', $outcome->kind(), 'a write that throws is a failure, not an exception on the page');
checkSame($was, storedHash($rPdo, 1), 'with the password untouched');
checkSame(false, (new ResetTokenStore($rPdo))->isSpent($tokenId), 'and the code still unspent');
checkSame(false, $rPdo->inTransaction(), 'and the transaction rolled back, not left hanging');

// ---- Inside somebody else's transaction, it does nothing at all ----
// The guess would be rolled back with that transaction, and the rollback here would
// end one this method did not start. No caller does this today; the point is that the
// next one cannot.
$rPdo = newTestDb();
$code = (new ResetTokenStore($rPdo))->issue(1);
$tokenId = intval($rPdo->query("SELECT id FROM password_resets WHERE user_id = 1")->fetchColumn());
$rPdo->beginTransaction();
checkSame('failed', newCompletion($rPdo)->complete(1, $code, 'not-in-here')->kind(),
          'a reset asked for inside an open transaction is refused');
checkSame(true, $rPdo->inTransaction(), 'and the transaction is still the caller\'s to finish');
// Conditional on purpose: if the guard above ever stops working, this method will
// have committed the transaction, and an unconditional rollBack() would then end the
// run with an exception instead of failing the check that just caught it.
if ($rPdo->inTransaction()) { $rPdo->rollBack(); }
checkSame(0, (new ResetTokenStore($rPdo))->attemptsSpent(1), 'no guess was charged for the refusal');
checkSame(false, (new ResetTokenStore($rPdo))->isSpent($tokenId), 'and the code is untouched');

// ---- The refusals the page must not tell apart ----
$rPdo = newTestDb();
checkSame(true, newCompletion($rPdo)->complete(0, '000000', 'x-x-x-x-x')->isRefused(),
          'no account at all is a refusal, in the same words as a wrong code');
$code = (new ResetTokenStore($rPdo))->issue(1);
checkSame(true, newCompletion($rPdo)->complete(1, '000000', 'x-x-x-x-x')->isRefused(),
          'a wrong code is a refusal');
expireTestResetToken($rPdo, 1);
checkSame(true, newCompletion($rPdo)->complete(1, $code, 'x-x-x-x-x')->isRefused(),
          'so is a correct code that has expired');

$rPdo = newTestDb();
$code = (new ResetTokenStore($rPdo))->issue(1);
for ($i = 1; $i <= 5; $i++) { newCompletion($rPdo)->complete(1, '000000', 'x-x-x-x-x'); }
checkSame(true, newCompletion($rPdo)->complete(1, $code, 'x-x-x-x-x')->isRefused(),
          'and so is the right code once five guesses have gone');
checkSame('', storedHash($rPdo, 1), 'none of which changed a password');

// A valid code cannot be used to blank a password, even though no page offers it.
$rPdo = newTestDb();
$code = (new ResetTokenStore($rPdo))->issue(1);
$tokenId = intval($rPdo->query("SELECT id FROM password_resets WHERE user_id = 1")->fetchColumn());
checkSame('failed', newCompletion($rPdo)->complete(1, $code, '')->kind(),
          'an empty password is refused outright');
checkSame('', storedHash($rPdo, 1), 'and nothing was written');
checkSame(false, (new ResetTokenStore($rPdo))->isSpent($tokenId), 'the code is not spent by it either');

// ---- A database that predates the login lockout ----
// The columns are added at runtime by login.php, and the ALTER can fail for
// reasons nobody sees: no privilege, a full disk. A reset on such a database has
// nothing to unlock, and must say so by working rather than by throwing.
$bare = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$bare->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL,
                                 password_hash TEXT NOT NULL DEFAULT '')");
$bare->exec("CREATE TABLE password_resets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
                                           passcode TEXT NOT NULL, expires_at TEXT NOT NULL,
                                           used INTEGER NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0)");
$bare->exec("INSERT INTO users (username) VALUES ('sky')");

// Caught deliberately: the failure this asserts against is an *exception*, and an
// uncaught one here would end the run rather than fail a check — taking the rest of
// the file's checks with it and printing no total at all.
$cleared = true;
try {
    $cleared = (new AccountStore($bare))->clearLoginLockout(1);
} catch (Throwable $e) {
    $cleared = false;
}
checkSame(true, $cleared, 'clearing a lockout that has nowhere to be recorded is not a failure');
$code = (new ResetTokenStore($bare))->issue(1);
checkSame(true, newCompletion($bare)->complete(1, $code, 'works-here-too')->isOk(),
          'and a reset on that database completes');
check(password_verify('works-here-too', storedHash($bare, 1)), 'with the password really changed');

// setPassword answers about the row, not about how many rows the engine says it
// changed — MySQL counts changed rows, so a hash identical to the stored one reads
// like a missing account there and like a success here.
checkSame(false, (new AccountStore($bare))->setPassword(9999, 'no-such-account'),
          'setting a password on an account that does not exist is false');
checkSame(true, (new AccountStore($bare))->setPassword(1, storedHash($bare, 1)),
          'and storing a hash the row already holds is not treated as a failure');

// ─────────────────────────────────────────────────────────────
section('The server report tells the truth about a database behind the repo');

// The whole value of this report is that it goes red when a runtime-added column
// did not land — schemaTry() swallows that failure by design, so nothing else in
// the app will ever say so. A report that always says "everything is in place" is
// worse than no report, because someone acts on it.
$sPdo   = newTestDb();
$server = new ServerReport($sPdo);

check($server->isConverged(), 'a fully converged database reports as converged');
$columns = $server->convergence();
checkSame(10, count($columns), 'and every runtime-added column is accounted for');
foreach ($columns as $col) {
    check($col['ok'], 'present: ' . $col['table'] . '.' . $col['column']);
    checkSame('', $col['note'], 'with nothing to warn about for ' . $col['column']);
}

// Now take one away — the one that matters most. display_id is what scopes every
// element to a Display; without it a publish is not safe to run at all.
$sPdo->exec("CREATE TABLE ce_narrow AS SELECT id, type FROM canvas_elements");
$sPdo->exec("DROP TABLE canvas_elements");
$sPdo->exec("ALTER TABLE ce_narrow RENAME TO canvas_elements");

$server = new ServerReport($sPdo);
checkSame(false, $server->isConverged(), 'a database missing a column does not report as converged');
$missing = null;
foreach ($server->convergence() as $col) {
    if ($col['column'] === 'display_id') { $missing = $col; }
}
check($missing !== null && $missing['ok'] === false, 'and names the column that is missing');
check($missing !== null && strpos($missing['note'], 'Do not publish') !== false,
      'with the consequence spelled out rather than left to be inferred');

// The report asks lib/schema.php's catalogue reader rather than writing its own
// query, so there is one answer to "how do we find out what columns exist". What it
// must not do is trust that answer about a table the read never covered: a
// confident "missing" from the one report in the app that exists to be trusted
// would send somebody looking for a column that is sitting right there.
//
// Built by giving the real fixture tables a catalogue that is silent about
// password_resets. The table is there and so is its column; the catalogue does not
// mention it.
// (The three lockout columns used to be patched onto the shape here. They are in
// convergedSchemaShape() itself now that the plan gates on them.)
//
// SQLite even on a MySQL run: the premise is a catalogue that disagrees with the
// tables underneath it, and MySQL's cannot be made to disagree with anything. That
// is a property of the engine rather than a gap in the run — what a real
// information_schema says about a real database is checked directly, in the MySQL
// section at the end of this file.
$shape = convergedSchemaShape();
$pPdo = newSqliteTestDb();
fakeCatalogue($shape, $pPdo);
$partial = new ServerReport($pPdo);
$attempts = null;
foreach ($partial->convergence() as $col) {
    if ($col['table'] === 'password_resets') { $attempts = $col; }
}
check($attempts !== null && $attempts['ok'],
      'a column on a table the catalogue never covered is confirmed by reading the table');
check($partial->isConverged(), 'so a partial catalogue does not make a converged database look behind');

// The runtime facts are reported for whatever this machine is; what the report
// must not do is throw, or hand the page something it cannot print.
$runtime = (new ServerReport(newTestDb()))->runtime();
check(isset($runtime['PHP version']), 'the report names the PHP version');
checkSame(PHP_VERSION, $runtime['PHP version'][0], 'and it is this machine\'s actual version');
check(isset($runtime['Session cookie']), 'and reports whether the sign-in cookie is protected');
$allStrings = true;
foreach ($runtime as $fact) {
    if (!is_string($fact[0]) || !is_string($fact[1])) { $allStrings = false; }
}
check($allStrings, 'every fact is a printable pair, so the panel cannot be handed an object');

// The row an admin reads when a file was refused and they want to know whose rule it
// was. It too had been spelled inline against two PHP_INI_PERDIR settings, so on this
// machine it has always been the server-limited form naming 2M and could never be
// anything else — the same unreachability `UploadLimit::smallestOf()` was seamed for.
checkSame('', ServerReport::uploadCeilingNoteFor(UploadLimit::APP_MAX_BYTES, '64M', '128M'),
          'a host more generous than the app is not blamed for the app\'s own ceiling');
$hostBound = ServerReport::uploadCeilingNoteFor(2097152, '2M', '8M');
check($hostBound !== '', 'a host that is the binding limit says so');
checkMentions($hostBound, 'upload_max_filesize 2M',
              'and quotes the setting as it is written in the file somebody has to edit');
checkMentions($hostBound, 'post_max_size 8M',
              'and the other one, because the smaller of the two is the one that binds');
// The number in the row beside it is the effective limit; this sentence must not
// repeat it in bytes, which is not what is in any php.ini anybody will open.
check(strpos($hostBound, '2097152') === false,
      'and never in bytes — nobody edits a php.ini by typing 2097152');

// ASSUMED_PHP is the floor the repo is written to — 8.2, stated by the owner (§4k) and
// since observed twice: 8.2.33 on the runtime card, and `ea-php82` pinned explicitly to
// srcresort.com in cPanel (2026-08-11, HANDOFF §7). A server at or above it is told
// nothing; the two bands below it fire on different things and must not print the
// same sentence, because what to do about them differs. Three bands, and this machine
// is only ever one of them — which is why phpVersionNote() takes the version id
// rather than reading PHP_VERSION_ID.
checkSame('8.2', ServerReport::ASSUMED_PHP,
          'the floor is the version the store runs, observed twice');
checkSame('', ServerReport::phpVersionNote(80200),
          'a server on the floor has nothing said about it');
checkSame('', ServerReport::phpVersionNote(80400),
          'and neither does a newer one — being ahead of the floor is not a problem');
// 8.3 is not a hypothetical: it is this host's *system* default, and srcresort.com is
// above it only because the domain is pinned to ea-php82 explicitly. Clear that pin
// back to `inherit` and the app runs here — so silence at 8.3 is a live configuration
// this store can reach by an admin ticking one box, not a third arbitrary number.
checkSame('', ServerReport::phpVersionNote(80300),
          'and the host\'s own system default is silent, which is where an inherit lands');
$behind = ServerReport::phpVersionNote(80100);
check($behind !== '', 'a server below the floor does say so');
checkMentions($behind, ServerReport::ASSUMED_PHP,
              'and names the version the code is written for');
check(strpos($behind, 'may not parse') !== false,
      'and warns that syntax the repo may now use will not parse there');
check(strpos($behind, 'still hardened') !== false,
      'and says the sign-in cookie is unaffected, because above 7.3 it is');
// Above 7.3, so this band must not claim the old cookie branch is in use.
check(strpos($behind, 'pre-7.3 session cookie form') === false,
      'and does not blame the cookie form, which 8.1 does not use');
$ancient = ServerReport::phpVersionNote(70100);
checkMentions($ancient, '7.3',
              'below 7.3 the note reaches for the one thing that actually breaks');
check(strpos($ancient, 'pre-7.3 session cookie form') !== false,
      'and names which cookie form is in use, which is what auth.php branches on');
check(strpos($ancient, 'Session cookie') !== false,
      'and points at the row that reads the three flags back off the live cookie');
check($behind !== $ancient,
      'the two speaking bands do not print the same sentence — what to do next differs');

// ---- And the same for the engine, which had a number and no sentence ----------------
// The row printed `''` hardcoded while the row above it had three bands. The version had
// been read off that card and written into HANDOFF.md on 2026-08-11 — 5.7.23-23 — and
// eight days later nothing had been done with it: no floor, no note, and no check that
// the SQL this app sends is 5.7's to accept. It is 5.7 because the shop *is* 5.7.
checkSame('5.7', ServerReport::ASSUMED_MYSQL,
          'the database floor is the engine the shop runs, read off its own card');
checkSame('', ServerReport::mysqlVersionNote('mysql', '5.7.23-23'),
          'the engine the store actually has is told nothing about itself');
checkSame('', ServerReport::mysqlVersionNote('mysql', '8.0.36'),
          'and neither is a newer one — being ahead of the floor is not a problem');
// The driver is a parameter and this is why. SQLite answers 3.45.1, which parsed as a
// MySQL version is far below the floor: a note written without the driver would have
// fired on every SQLite run in this project and called the shop's engine ancient.
checkSame('', ServerReport::mysqlVersionNote('sqlite', '3.45.1'),
          'and the fixture engine is not told it is an ancient MySQL, because it is not one');
checkSame('', ServerReport::mysqlVersionNote('mysql', 'unknown'),
          'a version this cannot parse gets no opinion rather than a wrong one');

$oldDb = ServerReport::mysqlVersionNote('mysql', '5.6.51');
check($oldDb !== '', 'an engine below the floor does say so');
checkMentions($oldDb, ServerReport::ASSUMED_MYSQL, 'and names the version the SQL is written for');
check(strpos($oldDb, 'innodb_large_prefix') !== false,
      'and names the setting that makes a 1020-byte utf8mb4 index legal, which 5.6 has off');
// No SQL keyword in a sentence an admin reads — and `check_invariants.php` holds the whole
// repo to one place that may name the database's own clock, over string literals, which
// unlike comments it cannot drop. The first draft of this note failed that check.
check(strpos($oldDb, 'CURRENT_' . 'TIMESTAMP') === false,
      'and names no SQL keyword, which is both better copy and a rule this repo enforces');
// The reason this band cannot be left to "the query will fail and somebody will see it".
check(strpos($oldDb, 'never thrown') !== false,
      'and says a refused schema statement is silent, which is why the card has to say it');
check(strpos($oldDb, '5.7.23') === false,
      'and does not repeat the exact version, which is already the value beside it');

// MariaDB reports 10.x — numerically above the floor and a different product, so a
// version comparison alone answers "fine" about an engine nothing here has run on.
$maria = ServerReport::mysqlVersionNote('mysql', '10.6.16-MariaDB-log');
check($maria !== '', 'MariaDB is not silently accepted for being numbered above 5.7');
checkMentions($maria, 'MariaDB', 'the note names the product rather than the number');
checkMentions($maria, 'rehearse_phase1.php', 'and points at the one tool that would find out');
// The strict `!== false` on the stripos, pinned with a match at position 0 — the only
// string that tells `!== false` and `!= false` apart, since 0 is falsy. No version PDO
// returns begins with the word, so the loose form would have worked here by luck; this is
// the check that makes it right rather than lucky (invariant 30 — mutant 56 survived).
check(ServerReport::mysqlVersionNote('mysql', 'MariaDB 10.6 (repackaged)') !== '',
      'and a version naming the product first is still caught, where a falsy 0 would not be');
check($maria !== $oldDb, 'and it is not the below-the-floor sentence — the answer differs');

// ─────────────────────────────────────────────────────────────
section('Which install is this, and whose credentials does it use');

// Two copies of this app on one hosting account — `public_html/lbm/` for the sign,
// `public_html/lbm-test/` for a rehearsal against a duplicate database — walk up to
// the *same* private directory. So an unmodified rehearsal copy connected to the live
// database and behaved perfectly: schema converged, Displays all present, publish
// succeeded, sign overwritten. Nothing downstream could notice, which is why the rule
// is here and not left to whoever sets the folder up.
//
// Pure, so every shape can be put through it. Only the last two checks touch a real
// directory, and they use a throwaway one.
checkSame('lbm-test', InstallPaths::installName('/home/acct/public_html/lbm-test'),
          'the install is named by its own folder');
checkSame('lbm-test', InstallPaths::installName('/home/acct/public_html/lbm-test/'),
          'and a trailing slash does not change the answer');
checkSame('lbm', InstallPaths::installName('/home/acct/public_html/lbm'),
          'which for the live folder is the name it has always had');
// Not sanitising a request — the web server decides where the app lives. It is
// refusing to build a path out of a shape it did not expect, the way Color::read()
// refuses a value about to become CSS.
checkSame('', InstallPaths::installName('/'),        'a root directory names no install');
checkSame('', InstallPaths::installName('/home/x/.'), 'and neither does "."');
checkSame('', InstallPaths::installName('/home/x/..'), 'nor ".." — both match the characters and neither is a name');
checkSame('', InstallPaths::installName('/home/x/we b'), 'a space is refused rather than escaped');
checkSame('', InstallPaths::installName('/home/x/a$b'), 'and so is anything else outside the safe set');

$cands = InstallPaths::credentialsCandidates('/home/acct/public_html/lbm-test');
checkSame(2, count($cands), 'a named install has two candidates');
checkSame('/home/acct/private/db_credentials_lbm-test.php', $cands[0],
          'its own file is tried first, and lives OUTSIDE the webroot');
checkSame('/home/acct/private/db_credentials.php', $cands[1],
          'and the shared file is the fallback, so the live install is unaffected');
$shared = InstallPaths::credentialsCandidates('/');
checkSame(1, count($shared), 'an install with no usable name has only the shared candidate');
check(substr($shared[0], -20) === '/db_credentials.php' || substr($shared[0], -19) === '/db_credentials.php',
      'and it is the shared file exactly');
check(strpos($shared[0], 'db_credentials_') === false,
      'never a per-install name built out of something unexpected');

// The specific file winning is the whole feature, so it is proved against real files
// rather than inferred from the order of the list.
$instDir = newTestStateDir();
mkdir($instDir . '/public_html/lbm-test', 0777, true);
mkdir($instDir . '/private', 0777, true);
$appDir = $instDir . '/public_html/lbm-test';
checkSame('', InstallPaths::credentialsFile($appDir),
          'with neither file present it answers empty, so db_connect.php can say which is missing');
file_put_contents($instDir . '/private/db_credentials.php', "<?php\n");
checkSame($instDir . '/private/db_credentials.php', InstallPaths::credentialsFile($appDir),
          'with only the shared file it uses that — an install that predates this change is unchanged');
file_put_contents($instDir . '/private/db_credentials_lbm-test.php', "<?php\n");
checkSame($instDir . '/private/db_credentials_lbm-test.php', InstallPaths::credentialsFile($appDir),
          'and the moment its own file exists that wins, which is what isolates the rehearsal copy');

// ─────────────────────────────────────────────────────────────
section('Convergence asks the catalogue before it alters anything');

// This file's statements are MySQL-only, so the SQLite fixture cannot run them —
// which for a long time meant lib/schema.php had no automated coverage at all. The
// *decision* is now separable from the doing: signageSchemaPlan() takes facts and
// returns work, with no database anywhere near it. That is what these check.
//
// Why it matters more than a speed figure: three of the old statements were an
// ALTER on canvas_elements that succeeded on every single request, and an ALTER takes
// an exclusive metadata lock on the table holding every sign's layout. A publish
// holding that table makes the ALTER wait, and the Screens' 30-second polls queue up
// behind the waiting ALTER.

$converged = schemaPlanFor(convergedSchemaShape());
checkSame(0, count(planStatements($converged)),
          'a converged database is issued no ALTER or CREATE at all');
checkSame(['seed_first_brand', 'seed_block_styles', 'seed_legacy_display'], planSteps($converged),
          'only the three steps a catalogue cannot answer are left, and each is a small COUNT');

// The fallback has to be the old behaviour exactly, or a host whose catalogue
// cannot be read would quietly stop converging.
$blind = signageSchemaPlan(SchemaFacts::unknown());
checkSame(35, count(planStatements($blind)),
          'a database whose catalogue cannot be read is issued every statement, as before');
checkSame(7, count(planSteps($blind)), 'and every step');
checkSame(false, SchemaFacts::unknown()->known(), 'and it says so rather than answering false');
checkSame(null, SchemaFacts::unknown()->hasColumn('assets', 'auto_pooled'),
          'an unknown catalogue answers "cannot tell", never "not there"');

// A SQLite database has no information_schema. This is the case the fallback
// exists for, so it is worth proving it is reached rather than assumed — and it
// is asked of SQLite explicitly, because on a MySQL run the catalogue is really
// there and "unknown" would be the wrong answer. What a real catalogue reports is
// checked in the MySQL section at the end of this file.
checkSame(false, readSchemaFacts(newSqliteTestDb())->known(),
          'a database with no catalogue to read reports itself unknown');

// ---- The catalogue read itself, run rather than trusted -----------------------
// fakeCatalogue() attaches a second SQLite database called information_schema and
// supplies DATABASE(), so the real query text executes: the three table names, the
// four column aliases, the IN list, the YES/NO of IS_NULLABLE. A typo in any of
// them would otherwise surface as a live server that had stopped converging.

$read = readSchemaFacts(fakeCatalogue(convergedSchemaShape()));
check($read->known(), 'a catalogue that can be read is read');
checkSame(0, count(planStatements(signageSchemaPlan($read))),
          'and what it says round-trips to the same empty plan');
checkSame(true, $read->hasColumn('canvas_elements', 'display_id'), 'a column it lists is found');
checkSame(false, $read->hasColumn('canvas_elements', 'nonsense'), 'one it does not list is not');
checkSame(false, $read->columnAllowsNull('canvas_elements', 'display_id'),
          'and IS_NULLABLE = NO is read as NOT NULL');
check($read->hasIndex('canvas_elements', 'display_id'), 'an index it lists is found');
check($read->hasConstraint('canvas_elements', 'canvas_elements_ibfk_3'),
      'and so is a foreign key');

// IS_NULLABLE is what decides whether the tighten still has to run, so it has to
// come off the catalogue rather than be assumed from the column existing.
$shape = convergedSchemaShape();
$shape['columns']['canvas_elements']['display_id']['nullable'] = true;
$plan = signageSchemaPlan(readSchemaFacts(fakeCatalogue($shape)));
check(planWants($plan, 'MODIFY COLUMN display_id INT(11) NOT NULL'),
      'a column the catalogue reports as nullable is tightened');

// ---- The re-key, which is the one statement that replaces structure ----------
// The gate reads the PRIMARY's *columns*, because every table has a PRIMARY and an
// existence test would answer the same before and after. So the property worth
// asserting is both directions: a database still on the old key is asked for the
// swap, and one already on the new key is asked for nothing — a second
// `DROP PRIMARY KEY, ADD PRIMARY KEY` does not fail harmlessly, it rebuilds the table
// every sign's typography lives in.
$kShape = convergedSchemaShape();
$kFacts = schemaFactsFrom($kShape);
checkSame(false, $kFacts->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
          'a table already keyed on (brand_id, block_type) is not re-keyed');
check(!planWants(schemaPlanFor($kShape), 'DROP PRIMARY KEY'),
      'so a converged database is sent no DROP PRIMARY KEY at all');

$kShape['indexes']['block_styles']['PRIMARY'] = ['block_type'];
$kOld = schemaFactsFrom($kShape);
checkSame(true, $kOld->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
          'a table still on the old single-column key is re-keyed');
check(planWants(schemaPlanFor($kShape), 'DROP PRIMARY KEY, ADD PRIMARY KEY (brand_id, block_type)'),
      'and the plan carries exactly that statement');

// Order is the whole of a composite key. These are different keys, and a gate that
// compared them as sets would call the re-key already done and leave the table on a
// key whose first column is the block type — which is why the catalogue read orders
// by SEQ_IN_INDEX rather than trusting the row order MySQL happens to return.
$kShape['indexes']['block_styles']['PRIMARY'] = ['block_type', 'brand_id'];
checkSame(true, schemaFactsFrom($kShape)->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
          'the same two columns in the other order is a different key, and is re-keyed');

// A shape that recorded the index without its columns cannot answer, and "cannot
// tell" has to mean null rather than an empty list — an empty list would compare
// unequal and issue the rebuild on every request, for ever.
$kShape['indexes']['block_styles']['PRIMARY'] = true;
checkSame(null, schemaFactsFrom($kShape)->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
          'an index recorded without its columns answers "cannot tell", never a confident list');
checkSame(null, schemaFactsFrom($kShape)->indexColumns('block_styles', 'PRIMARY'),
          'and indexColumns says so directly');
checkSame(null, SchemaFacts::unknown()->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
          'and so does a catalogue that could not be read at all');

// The three-valued discipline the rest of this file has: a table that is not there is
// a definite no, because the CREATE that makes it declares its own key.
$kGone = convergedSchemaShape();
unset($kGone['columns']['block_styles'], $kGone['indexes']['block_styles']);
checkSame(false, schemaFactsFrom($kGone)->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
          'a table that is not there is not re-keyed');

// A catalogue that answers but knows nothing about this app is not a database with
// no tables — it is a question that did not land. Reading it as "everything is
// missing" would issue two CREATE TABLEs and five foreign keys against a database
// that has them all.
$empty = readSchemaFacts(fakeCatalogue(['columns' => [], 'indexes' => [], 'constraints' => []]));
checkSame(false, $empty->known(), 'a catalogue with nothing to say about this app is unknown, not empty');
checkSame(35, count(planStatements(signageSchemaPlan($empty))),
          'so it falls back to trying everything rather than creating what already exists');

// Two things about a catalogue this app does not control, both of which decide
// whether a statement runs, and neither of which had a check (decision #49).
//
// Names first. MySQL reports them however the database declared them, and on a
// case-preserving host that is whatever the person who typed the CREATE chose. Every
// name in this file is lower case, so both levels are folded on the way in — the
// table and the column. Folding only the table would make every column look absent
// and put the whole plan back on a database that has all of it.
$mixedCase = SchemaFacts::of(
    ['Canvas_Elements' => ['Display_ID' => ['type' => 'INT(11)', 'nullable' => false]]],
    ['Canvas_Elements' => ['Display_ID' => true]],
    ['Canvas_Elements' => ['Canvas_Elements_IBFK_3' => true]]
);
checkSame(true, $mixedCase->hasTable('canvas_elements'), 'a table named in capitals is the same table');
checkSame(true, $mixedCase->hasColumn('canvas_elements', 'display_id'), 'and a column is the same column');
checkSame(true, $mixedCase->hasIndex('canvas_elements', 'display_id'), 'the same index');
checkSame(true, $mixedCase->hasConstraint('canvas_elements', 'canvas_elements_ibfk_3'),
          'and the same foreign key');

// And IS_NULLABLE, which is the one catalogue value read as a word rather than a
// name. MySQL says YES; a driver or a host that answers `yes` must not turn a
// nullable column into one this app believes is already tightened — that is the
// tighten silently never running again, on the column every scoped query depends on.
$lowerCase = fakeCatalogue(convergedSchemaShape());
$lowerCase->exec("UPDATE information_schema.COLUMNS SET IS_NULLABLE = lower(IS_NULLABLE)");
$lowerFacts = readSchemaFacts($lowerCase);
checkSame(true, $lowerFacts->columnAllowsNull('displays', 'lock_taken_at'),
          'a catalogue answering "yes" in lower case still reads as nullable');
checkSame(false, $lowerFacts->columnAllowsNull('canvas_elements', 'display_id'),
          'and "no" still reads as tightened');

// ---- One thing missing asks for exactly that thing ---------------------------

// The three ADR-0001 lockout columns, which reached the live database a different
// way until recently: three ungated ALTERs fired from login.php on every sign-in
// POST — DDL with no account behind it, three metadata locks on `users` per
// password guess. They are gated plan entries now, and a gate nothing checks is a
// gate that may as well not be there (invariant 19).
$shape = convergedSchemaShape();
unset($shape['columns']['users']['failed_attempts']);
unset($shape['columns']['users']['last_failed_at']);
unset($shape['columns']['users']['locked_until']);
$plan = schemaPlanFor($shape);
checkSame(3, count(planStatements($plan)), 'a database with no lockout columns is issued exactly three statements');
check(planWants($plan, 'ALTER TABLE users ADD COLUMN failed_attempts'), 'the counter');
check(planWants($plan, 'ALTER TABLE users ADD COLUMN last_failed_at'),  'the stamp it ages out from');
check(planWants($plan, 'ALTER TABLE users ADD COLUMN locked_until'),    'and the lockout itself');

$shape = convergedSchemaShape();
unset($shape['columns']['users']['locked_until']);
$plan = schemaPlanFor($shape);
checkSame(1, count(planStatements($plan)), 'and a database missing only one of them is issued only that one');
check(planWants($plan, 'ALTER TABLE users ADD COLUMN locked_until'), 'namely the one it is missing');

// closed_at, which arrived the same ungated way until recently — one ALTER on every
// admin-panel load, from AccountStore::ensureSchema(). Milder than the login one
// (authenticated, one statement) and the same defect.
$shape = convergedSchemaShape();
unset($shape['columns']['users']['closed_at']);
$plan = schemaPlanFor($shape);
checkSame(1, count(planStatements($plan)), 'a database that cannot record a closed account is issued one statement');
check(planWants($plan, 'ALTER TABLE users ADD COLUMN closed_at'), 'and it is the one that adds the column');
check(!planWants($plan, 'ALTER TABLE users ADD COLUMN failed_attempts'),
      'and nothing else on users, because the rest of them are already there');

// Each carries `need => true`, which is what decides whether a failure is worth
// emailing an admin about (invariant 20). A column that genuinely could not be
// added is the state that sat unnoticed on the live database for months.
foreach ($plan as $entry) {
    if (!isset($entry['sql'])) { continue; }
    checkSame(true, $entry['need'], 'a lockout column the catalogue says is missing is a certainty, not a guess');
}

$shape = convergedSchemaShape();
unset($shape['columns']['assets']['auto_pooled']);
$plan = schemaPlanFor($shape);
checkSame(1, count(planStatements($plan)), 'a database without the pool marker is issued one statement');
check(planWants($plan, 'ALTER TABLE assets ADD COLUMN auto_pooled'), 'and it is the one that adds it');
check(in_array('backfill_auto_pooled', planSteps($plan), true),
      'with the backfill that marks what the old pooling left behind');

// The backfill reads the `Auto: ` label prefix, and it used to run on every
// authenticated request. Saving a pooled row adopts it by clearing the marker and
// leaves the label alone — so the statement un-adopted it within one page load and
// the Library's Tidy up could then delete what somebody had claimed. Show the
// statement really does that, then show the plan is what stops it running.
$aPdo = newTestDb();
$lib  = new AssetLibrary($aPdo);
$pooledId = $lib->pool('text', 'OPEN 7 DAYS');
check($pooledId > 0, 'a published text block leaves a marked row in the library');
$lib->update($pooledId, 'Auto: OPEN 7 DAYS', 'OPEN 7 DAYS');   // adopted; label left alone
$marked = function () use ($aPdo, $pooledId) {
    $stmt = $aPdo->prepare("SELECT auto_pooled FROM assets WHERE id = ?");
    $stmt->execute([$pooledId]);
    return intval($stmt->fetchColumn());
};
checkSame(0, $marked(), 'saving it adopts it, so no sweep can take it');
backfillPooledMarker($aPdo);
checkSame(1, $marked(), 'but the backfill statement claims it straight back, from the label it still has');
check(!in_array('backfill_auto_pooled', planSteps($converged), true),
      'which is why a converged database never runs that statement again');

// ---- The column that scopes everything --------------------------------------

$shape = convergedSchemaShape();
unset($shape['columns']['canvas_elements']['display_id']);
unset($shape['indexes']['canvas_elements']['display_id']);
unset($shape['constraints']['canvas_elements']['canvas_elements_ibfk_3']);
$plan = schemaPlanFor($shape);
checkSame(['canvas_elements.display_id', 'backfill_display_id', 'display_id is NOT NULL',
           'display_id indexed', 'canvas_elements → displays'],
          array_values(array_filter(planOrder($plan), function ($why) {
              return strpos($why, 'display_id') !== false || $why === 'canvas_elements → displays';
          })),
          'display_id is added nullable, backfilled, tightened, indexed and keyed — in that order');

// A database that got the column but never the tighten: the ALTER that adds it
// must not be re-issued, and the backfill must still run, because a nullable
// column is exactly where an unscoped row can still be hiding.
$shape = convergedSchemaShape();
$shape['columns']['canvas_elements']['display_id']['nullable'] = true;
$plan = schemaPlanFor($shape);
check(!planWants($plan, 'ADD COLUMN display_id'), 'a column already added is not added again');
check(in_array('backfill_display_id', planSteps($plan), true),
      'but a nullable display_id is still swept for unscoped rows');
check(planWants($plan, 'MODIFY COLUMN display_id INT(11) NOT NULL'), 'and still tightened');

// ---- The two statements that used to run every single time -------------------

$shape = convergedSchemaShape();
$shape['columns']['canvas_elements']['type']['type'] =
    "enum('section','text','image','video','carousel','marquee')";      // no 'table'
$plan = schemaPlanFor($shape);
checkSame(1, count(planStatements($plan)), 'an ENUM missing a value is widened');
check(planWants($plan, 'MODIFY COLUMN type'), 'by the statement that lists every value');

// MySQL reports COLUMN_TYPE lower case and unspaced, but nothing in this app
// depends on that: a needless rewrite of the layout table is the thing being
// removed, so the comparison must not be defeated by formatting.
$shape = convergedSchemaShape();
$shape['columns']['canvas_elements']['type']['type'] =
    "ENUM('section', 'text', 'image', 'video', 'carousel', 'marquee', 'table')";
checkSame(0, count(planStatements(schemaPlanFor($shape))),
          'the same ENUM spelled with capitals and spaces is not rewritten');

// ---- Tables, and the difference between a column and a constraint ------------

$shape = convergedSchemaShape();
unset($shape['columns']['displays'], $shape['indexes']['displays'],
      $shape['constraints']['displays']);
$plan = schemaPlanFor($shape);
check(planWants($plan, 'CREATE TABLE IF NOT EXISTS displays'), 'an absent Displays table is created');
check(!planWants($plan, 'ADD COLUMN lock_taken_at'),
      'and its columns are not then added again — the CREATE declares them');
check(planWants($plan, 'displays_ibfk_1') && planWants($plan, 'displays_ibfk_2'),
      'but its two foreign keys are, because the CREATE does not declare those');

$shape = convergedSchemaShape();
unset($shape['constraints']['display_permissions']['display_permissions_ibfk_2']);
$plan = schemaPlanFor($shape);
checkSame(1, count(planStatements($plan)), 'a single missing foreign key asks for one statement');
check(planWants($plan, 'display_permissions_ibfk_2'), 'the one that was missing');

// An installation that does not have the layout table at all cannot be altered
// into having one — schema.sql creates it. Issuing five ALTERs that must fail is
// noise, and noise is what hid the failures worth seeing.
$shape = convergedSchemaShape();
unset($shape['columns']['canvas_elements'], $shape['indexes']['canvas_elements'],
      $shape['constraints']['canvas_elements']);
$plan = schemaPlanFor($shape);
check(!planWants($plan, 'ALTER TABLE canvas_elements ADD COLUMN'),
      'a table nothing here creates is not altered when it is missing');
check(!in_array('backfill_display_id', planSteps($plan), true),
      'nor backfilled');

// "Not altered" is four separate rules, and only three of them agree (decision #49).
// A plan can ask a table for a column, a MODIFY, an index and a foreign key; the
// first three are pointless against a table that is not there, and the fourth is
// still wanted, because the CREATE TABLE this file issues declares its columns and
// never its foreign keys. Asserted on the facts as well as the plan, because these
// are the answers every future statement will be gated on.
$absent = schemaFactsFrom($shape);
checkSame(false, $absent->needsColumn('canvas_elements', 'text_align'),
          'a missing table needs no column added');
checkSame(false, $absent->needsColumnType('canvas_elements', 'type', SCHEMA_ELEMENT_TYPE_ENUM),
          'nor a column rewritten');
checkSame(false, $absent->needsIndex('canvas_elements', 'display_id'),
          'nor an index put on it');
checkSame(true, $absent->needsConstraint('canvas_elements', 'canvas_elements_ibfk_3'),
          'but its foreign key is still wanted, because no CREATE TABLE here declares one');
check(!planWants($plan, 'MODIFY COLUMN'), 'so the plan sends it no MODIFY');
check(!planWants($plan, 'ADD KEY'),       'and no ADD KEY');
checkSame(['seed_first_brand', 'seed_block_styles', 'seed_legacy_display',
           'canvas_elements → displays'],
          planOrder($plan),
          'leaving the three steps and the one statement that is not redundant, and nothing else');

// The same rule one level down: a column that is absent from a table that is *there*
// is added by its own ADD COLUMN, or not at all. MODIFY has nothing to work on, and
// on MySQL it is an error rather than a no-op.
$shape = convergedSchemaShape();
unset($shape['columns']['canvas_elements']['block_subtype']);
check(!planWants(schemaPlanFor($shape), 'MODIFY COLUMN block_subtype'),
      'a column that is not there is not MODIFYed into shape');

// The block-style seed is a row count, so no catalogue can decide it — but the
// catalogue can still say there is no table to count, and then it is skipped. The
// step would otherwise fail on every request of an install that is mid-deploy.
$shape = convergedSchemaShape();
unset($shape['columns']['block_styles']);
check(!in_array('seed_block_styles', planSteps(schemaPlanFor($shape)), true),
      'with no block_styles table there is nothing to seed into, so the step is left out');

// ---- The steps, and what a failure now looks like ----------------------------

// The seed used to be one `INSERT IGNORE`, which SQLite rejects — so on this engine
// a `true` return could only ever mean "the count found all six and nothing was
// sent", and the inserting half of the function had never run in a test at all.
// Re-keying on the Brand replaced it with a computed set of plain INSERTs, which
// both engines run, so the seed can now be watched actually seeding.
$bPdo = newSqliteTestDb();
checkSame(true, seedBlockStyles($bPdo), 'a complete set of branded block types is not re-seeded');
$bPdo->exec("DELETE FROM block_styles WHERE block_type = 'price_2'");
checkSame(true, seedBlockStyles($bPdo), 'a missing one is put back');
checkSame(6, count((new BrandStyles($bPdo))->all(1)), 'so the Brand has all six again');

// A second Brand with no standards at all is six more rows, not zero: the seed is
// per Brand now, and a Brand created by a hand-written INSERT is exactly the state
// that used to leave a whole venue's typography form silently saving nothing.
makeTestBrandRow($bPdo, 'Unseeded Venue');
checkSame(true, seedBlockStyles($bPdo), 'a Brand with no standards at all is seeded');
checkSame(6, count((new BrandStyles($bPdo))->all(2)), 'with its own six rows');
checkSame('Arial', (new BrandStyles($bPdo))->all(2)['price']['font_family'],
          'started from BrandStyles::STARTING_POINTS rather than from the other Brand');

// And the cascade of a Brand that could not be created: nothing to seed *for*.
$bNoBrand = newSqliteTestDb();
$bNoBrand->exec("DELETE FROM block_styles");
$bNoBrand->exec("DELETE FROM displays");
$bNoBrand->exec("DELETE FROM brands");
$bErr = 'untouched';
checkSame(false, seedBlockStyles($bNoBrand, $bErr), 'with no Brand there is nothing to seed for');
checkMentions($bErr, 'no Brand', 'and it says that rather than naming a column');

checkSame(true, runSchemaStep(newTestDb(), 'no_such_step'),
          'a step name nothing knows is nothing to do, not a failure');

// Until convergence gated itself, every request failed twelve statements by
// design, so a real failure was indistinguishable from the normal case. Now the
// statements that run are the ones the catalogue said were missing, and one that
// fails is worth something.
$fPdo   = newTestDb();
$failed = runSchemaPlan($fPdo, [
    ['why' => 'a statement that works',       'sql' => "UPDATE assets SET label = label"],
    ['why' => 'a statement that cannot work', 'sql' => "ALTER TABLE nope ADD COLUMN x INT"],
    ['why' => 'seed_legacy_display',          'step' => 'seed_legacy_display'],
]);
checkSame(1, count($failed), 'the plan reports back the statement that failed');
checkSame('a statement that cannot work', $failed[0]['why'], 'and names it in words, not SQL');

// ─────────────────────────────────────────────────────────────
section('An account is closed, never deleted, so its number is never reused');

// The defect: `DELETE FROM users` freed the id, and MySQL hands a freed id to the
// next account created. Everything still pointing at it — a grant that outlived
// its cascade, a held edit lock, a publish record, a signed-in browser — would
// then be pointing at a different person. Closing removes the freeing.
$aPdo   = newTestDb();
$aStore = new AccountStore($aPdo);
$aAdmin = newTestAccountAdmin($aPdo);
$aDisps = newTestDisplayStore($aPdo);
$aSign  = makeTestDisplay($aPdo, 'lobby', 'Lobby');

// Account 2 is the clerk. Give them a Display to edit and let them hold the lock,
// so closing has something real to surrender.
grantTestAccess($aPdo, $aSign->id(), 2);
$aDisps->claimLock($aSign, 2);
checkSame([2], (new GrantStore($aPdo))->displayIdsFor(2) ? [2] : [], 'the clerk starts out granted the sign');
checkSame(true, $aDisps->forId($aSign->id())->lockState()->heldBy(2), 'and holding its edit lock');

$res = $aAdmin->close(2, 1);
checkSame(true, $res->isOk(), 'an admin can close somebody else\'s account');
check(strpos($res->message(), 'clerk') !== false, 'and the answer names who was closed');

checkSame(true, $aStore->isClosed(2), 'the account reads as closed');
checkSame(1, count($aPdo->query("SELECT id FROM users WHERE id = 2")->fetchAll()),
          'and its row is still there — which is the whole point, so the id is never free');
checkSame([], (new GrantStore($aPdo))->displayIdsFor(2), 'closing surrendered every grant it held');
checkSame(false, $aDisps->forId($aSign->id())->lockState()->isHeld(), 'and released the display it was holding');

// The list a manager sees, and the list of names that stay spoken for.
$open = $aStore->open();
checkSame(1, count($open), 'a closed account is out of the user list');
checkSame('sky', $open[0]['username'], 'leaving the accounts still in service');
$closed = $aStore->closed();
checkSame(1, count($closed), 'and into the closed list, where its name is visible');
checkSame('clerk', $closed[0]['username'], 'so "that name is taken" is never a dead end');

// The reason closing beats deleting, asserted rather than assumed: history keeps
// printing a name. A delete would have left this blank or, worse, someone else's.
$names = $aStore->names();
checkSame('clerk', isset($names[2]) ? $names[2] : null,
          'a closed account still answers to its name, so "published by" survives');

// Closing twice is not a second closure, and must not re-run the surrenders.
$again = $aAdmin->close(2, 1);
checkSame(false, $again->isOk(), 'closing an account that is already closed is refused');

// Two refusals that exist because closing cannot be undone.
$self = $aAdmin->close(1, 1);
checkSame(false, $self->isOk(), 'an admin cannot close their own account');
$onlyAdmin = $aAdmin->close(1, 2);
checkSame(false, $onlyAdmin->isOk(), 'nor can the last admin who can still sign in be closed');
check(strpos($onlyAdmin->message(), 'cannot be undone') !== false,
      'and the refusal says why it matters');

// With a second admin in place the guard lifts — it is a floor, not a ban.
$aPdo2   = newTestDb();
$aAdmin2 = newTestAccountAdmin($aPdo2);
makeTestAccount($aPdo2, 'second', 'admin');
checkSame(true, $aAdmin2->close(1, 3)->isOk(), 'with another admin available, an admin can be closed');
checkSame(1, (new AccountStore($aPdo2))->openAdminCount(), 'and exactly one admin is left in service');

// An id that names nobody must not report success, or the panel says it closed
// something it did not touch.
checkSame(false, $aAdmin2->close(999, 1)->isOk(), 'closing an account that does not exist is refused');
checkSame(false, $aAdmin2->close(0, 1)->isOk(),   'and so is closing nothing at all');

// A database that predates the column has never closed anybody, and must say so
// rather than throwing — this is what the live server looks like before deploy.
// Dropping the column rather than rebuilding the table around it: `displays` and
// `display_permissions` both hold foreign keys into `users`, so on MySQL the old
// copy-and-swap could not drop the original at all, and on either engine the
// rebuilt table lost the constraints that make the rest of the fixture behave.
$oldPdo = newTestDb();
$oldPdo->exec("ALTER TABLE users DROP COLUMN closed_at");
$oldStore = new AccountStore($oldPdo);
checkSame(false, $oldStore->isClosed(1), 'without the column, no account reads as closed');
checkSame(2, count($oldStore->open()),   'and every account is still in service');
checkSame([], $oldStore->closed(),       'with none of them closed');
checkSame(false, (new AccountAdmin($oldPdo, $oldStore, new GrantStore($oldPdo), newTestDisplayStore($oldPdo)))
                 ->close(2, 1)->isOk(),
          'and closing refuses rather than half-doing it');

// ─────────────────────────────────────────────────────────────
section('Taking access away, and what it takes with it');

// Three defects, one shape: a change of access that was decided by an *absence*.
//
//   · A Display missing from the submitted matrix meant "revoke", so a form rendered
//     before that Display existed silently undid grants another admin had just made.
//   · A revoked grant left the edit lock behind, on an account that could no longer
//     open the Display to release it — so the sign stayed locked for a quarter of an
//     hour to somebody who was not allowed near it.
//   · A promotion to admin left the grant rows in place, invisible on the one screen
//     that administers them, and a demotion months later handed the old access back.

/** A GrantStore whose revoke fails — so a half-written matrix can be looked for. */
class RefusingGrantStore extends GrantStore
{
    public function revoke($displayId, $accountId)
    {
        throw new RuntimeException('revoke refused');
    }
}

$xPdo    = newTestDb();
$xStore  = newTestDisplayStore($xPdo);
$xAdmin  = newTestDisplayAdmin($xPdo);
$xGrants = new GrantStore($xPdo);

$xDrive = makeTestDisplay($xPdo, 'drive-thru', 'Drive-Thru');
$xLobby = makeTestDisplay($xPdo, 'lobby', 'Lobby');
$xDeli  = makeTestDisplay($xPdo, 'deli', 'Deli Case');
$xJane  = makeTestAccount($xPdo, 'jane');       // a second basic account

// ---- Only the part of the matrix the form covered (#16) ------------------------

$xGrants->grant($xLobby->id(), 2);
$xGrants->grant($xDeli->id(),  2);

// The stale form: rendered when only two Displays existed, submitted after a
// colleague added the third and granted it. Its columns are the two it showed.
$res = $xAdmin->setAccess([2], [$xDrive->id(), $xLobby->id()], [2 => [$xLobby->id()]]);
check($res->isOk(), 'a save covering some of the displays is accepted');
checkSame([$xLobby->id(), $xDeli->id()], $xGrants->displayIdsFor(2),
          'a grant on a display the form never showed survives it');
check(strpos($res->message(), 'unchanged') !== false,
      'and with nothing else to do, the save says nothing changed');

// A tick is no more powerful than a column: the two axes are declared together, so a
// hand-built POST cannot grant through a column the form did not carry.
$res = $xAdmin->setAccess([2], [$xLobby->id()], [2 => [$xLobby->id(), $xDrive->id()]]);
checkSame([$xLobby->id(), $xDeli->id()], $xGrants->displayIdsFor(2),
          'a tick outside the covered columns grants nothing');

// And with the column covered, an unticked box still means revoke — the whole point
// of the grid. This is the check that would pass on the unfixed code as well; it is
// here so that "covered" cannot be quietly widened into "never revokes".
$res = $xAdmin->setAccess([2], [$xLobby->id(), $xDeli->id()], [2 => [$xLobby->id()]]);
check($res->isOk(), 'a save covering both columns is accepted');
checkSame([$xLobby->id()], $xGrants->displayIdsFor(2), 'and the unticked one is taken away');

// A form covering nothing changes nothing. Reachable: a POST built by hand, or the
// grid submitted from a page where every Display had been deleted.
$res = $xAdmin->setAccess([2], [], [2 => []]);
check($res->isOk() && strpos($res->message(), 'unchanged') !== false,
      'a save that covers no displays at all reports nothing changed');
checkSame([$xLobby->id()], $xGrants->displayIdsFor(2), 'and takes nothing away');

// A column naming a Display that has since been deleted is dropped, not fatal —
// the same rule the ticks already followed.
$res = $xAdmin->setAccess([2], [$xLobby->id(), 99999], [2 => [$xLobby->id()]]);
check($res->isOk(), 'a column naming no display is dropped rather than failing the save');
checkSame([$xLobby->id()], $xGrants->displayIdsFor(2), 'leaving the real one alone');

// ---- Revoking frees the edit lock, by holder (#17) -----------------------------

$xGrants->grant($xDeli->id(),  2);
$xGrants->grant($xDrive->id(), 2);
$xGrants->grant($xDeli->id(),  $xJane);

$xStore->claimLock($xLobby, 2);
$xStore->claimLock($xDrive, 2);
$xStore->claimLock($xDeli,  $xJane);
checkSame(true, $xStore->forId($xLobby->id())->lockState()->heldBy(2),
          'the clerk is holding the lobby sign');
checkSame(true, $xStore->forId($xDeli->id())->lockState()->heldBy($xJane),
          'and jane is holding the deli case');

// Take the lobby and the deli away from the clerk. Jane is holding the deli.
$res = $xAdmin->setAccess([2], [$xDrive->id(), $xLobby->id(), $xDeli->id()],
                          [2 => [$xDrive->id()]]);
check($res->isOk(), 'taking two displays away is accepted');
checkSame(false, $xStore->forId($xLobby->id())->lockState()->isHeld(),
          'the display the revoked account was editing is released');
checkSame(true, $xStore->forId($xDeli->id())->lockState()->heldBy($xJane),
          'but a colleague\'s lock on another revoked display is left alone');
checkSame(true, $xStore->forId($xDrive->id())->lockState()->heldBy(2),
          'and so is their own lock on a display they still hold');
check(strpos($res->message(), 'edit lock has been released') !== false,
      'and the answer says a lock was released, because somebody\'s session just ended');
check(strpos($res->message(), '1 display was') !== false,
      'counting the one that was actually being edited, not the two that were revoked');

// The two halves of #17 meeting: the seam now refuses that account, which is the
// `forbidden` the Builder's heartbeat turns into its own notice.
$r = DisplayRequest::forEditing($xStore, ['display' => 'lobby'], newTestActor($xPdo, 2, 'basic'));
checkSame(DisplayResolution::FORBIDDEN, $r->kind(),
          'the revoked account\'s next heartbeat for that display is refused');

// And the sign is genuinely free, not just unheld by them: the next person can start
// without waiting out the idle window.
$xGrants->grant($xLobby->id(), $xJane);
$xStore->claimLock(loadTestDisplay($xPdo, $xLobby->id()), $xJane);
checkSame(true, $xStore->forId($xLobby->id())->lockState()->heldBy($xJane),
          'and somebody else can pick it up immediately');

// A revoke with nobody in there says the other sentence, and never claims a lock
// was released. The clerk leaves the drive-thru sign first, so this is the same
// revoke against a Display nobody is holding.
$xStore->releaseLockOn($xDrive->id(), 2);
$res = $xAdmin->setAccess([2], [$xDrive->id()], [2 => []]);
check(strpos($res->message(), 'no longer open that display') !== false,
      'a revoke of a display nobody was editing says just that it is no longer theirs');
check(strpos($res->message(), 'edit lock') === false,
      'and does not mention a lock it did not release');

// releaseLockOn answers whether there was one, which is what the count above is made
// of — and it never touches a lock held by somebody else.
$xStore->claimLock(loadTestDisplay($xPdo, $xDrive->id()), $xJane);
checkSame(false, $xStore->releaseLockOn($xDrive->id(), 2),
          'releasing a lock this account does not hold reports nothing released');
checkSame(true, $xStore->forId($xDrive->id())->lockState()->heldBy($xJane),
          'and leaves the holder holding it');
checkSame(true, $xStore->releaseLockOn($xDrive->id(), $xJane),
          'releasing the holder\'s own lock reports it released');
checkSame(false, $xStore->forId($xDrive->id())->lockState()->isHeld(), 'and frees the display');

// ---- The lock release is inside the transaction --------------------------------
// The point of invariant 22: if the revoke fails, the freed lock has to come back
// with it, or the admin is told nothing changed while a sign sits unlocked.
$yPdo   = newTestDb();
$yStore = newTestDisplayStore($yPdo);
$yLobby = makeTestDisplay($yPdo, 'lobby', 'Lobby');
$yDeli  = makeTestDisplay($yPdo, 'deli', 'Deli Case');
$yBreak = new DisplayAdmin($yPdo, $yStore, newTestLayoutStore($yPdo), new RefusingGrantStore($yPdo),
                           new BrandStore($yPdo));
grantTestAccess($yPdo, $yLobby->id(), 2);
$yStore->claimLock($yLobby, 2);

$res = $yBreak->setAccess([2], [$yLobby->id(), $yDeli->id()], [2 => [$yDeli->id()]]);
checkSame(false, $res->isOk(), 'a matrix save whose revoke fails is reported as failed');
check(strpos($res->message(), 'Nothing was changed') !== false, 'and says nothing was changed');
checkSame([$yLobby->id()], (new GrantStore($yPdo))->displayIdsFor(2),
          'the grant it had already added in the same save is rolled back');
checkSame(true, $yStore->forId($yLobby->id())->lockState()->heldBy(2),
          'and the edit lock it had already freed is back where it was');
checkSame(false, $yPdo->inTransaction(), 'with no transaction left open');

// ---- Promotion clears the grants; demotion frees the locks (#18) ---------------

$zPdo   = newTestDb();
$zStore = newTestDisplayStore($zPdo);
$zAcc   = new AccountStore($zPdo);
$zAdmin = newTestAccountAdmin($zPdo);
$zLobby = makeTestDisplay($zPdo, 'lobby', 'Lobby');
$zDeli  = makeTestDisplay($zPdo, 'deli', 'Deli Case');
grantTestAccess($zPdo, $zLobby->id(), 2);
$zStore->claimLock($zLobby, 2);

$res = $zAdmin->edit(2, 'admin', true, 'clerk@example.test', 1);
checkSame(true, $res->isOk(), 'a basic account can be promoted to admin');
checkSame('admin', $zAcc->roleOf(2), 'the row says admin');
checkSame([], (new GrantStore($zPdo))->displayIdsFor(2),
          'and the individual grants are gone, so the matrix is the whole truth about them');
check(strpos($res->message(), 'cleared') !== false, 'the answer says they were cleared');
check(strpos($res->message(), 'give those back') !== false,
      'and warns that a demotion will not bring them back');
checkSame(true, newTestActor($zPdo, 2, 'admin')->mayEdit($zDeli),
          'they now hold every display by role instead');
checkSame(true, $zStore->forId($zLobby->id())->lockState()->heldBy(2),
          'and the display they were editing is still theirs to finish — a promotion takes nothing away');

// The defect this closes, stated as a check: demote them and the March access must
// not come back.
$res = $zAdmin->edit(2, 'basic', true, 'clerk@example.test', 1);
checkSame(true, $res->isOk(), 'and can be demoted again');
checkSame([], (new GrantStore($zPdo))->displayIdsFor(2),
          'the old access does not silently return');
checkSame(false, $zStore->forId($zLobby->id())->lockState()->isHeld(),
          'and the lock they can no longer reach to release is freed for them');
check(strpos($res->message(), 'hold no displays now') !== false,
      'the answer says they hold nothing and where to fix that');
check(strpos($res->message(), 'has been released') !== false,
      'and that the display they had open was let go');

// A save that changes neither role nor anything else touches neither table.
grantTestAccess($zPdo, $zLobby->id(), 2);
$zStore->claimLock(loadTestDisplay($zPdo, $zLobby->id()), 2);
$res = $zAdmin->edit(2, 'basic', true, 'clerk@example.test', 1);
checkSame(true, $res->isOk(), 'saving an account with no change of role is accepted');
checkSame([$zLobby->id()], (new GrantStore($zPdo))->displayIdsFor(2), 'the grants are untouched');
checkSame(true, $zStore->forId($zLobby->id())->lockState()->heldBy(2), 'and so is the edit lock');
checkSame('User updated.', $res->message(), 'and it says nothing about access, because nothing happened to it');

// Email and the active flag still work, and still go through the one transaction.
$res = $zAdmin->edit(2, 'basic', false, 'clerk2@example.test', 1);
checkSame(true, $res->isOk(), 'the email and the active flag can be changed');
$row = $zPdo->query("SELECT email, is_active FROM users WHERE id = 2")->fetch();
checkSame('clerk2@example.test', $row['email'], 'the new email is stored');
checkSame(0, intval($row['is_active']), 'and the account is deactivated');
checkSame([$zLobby->id()], (new GrantStore($zPdo))->displayIdsFor(2),
          'deactivating does not clear grants — closing an account is the permanent one');

// An email another account already holds fails the *whole* change, including the
// role and the grants. This is invariant 22 from the other side: the message is
// decided by what is now true, not by which statement threw.
makeTestAccount($zPdo, 'taken');                       // taken@example.test
$res = $zAdmin->edit(2, 'admin', true, 'taken@example.test', 1);
checkSame(false, $res->isOk(), 'an email another account holds fails the save');
check(strpos($res->message(), 'Nothing was changed') !== false, 'and says nothing was changed');
checkSame('basic', $zAcc->roleOf(2), 'the role really did not move');
checkSame([$zLobby->id()], (new GrantStore($zPdo))->displayIdsFor(2),
          'and the promotion did not take the grants with it on the way out');
checkSame(false, $zPdo->inTransaction(), 'with no transaction left open');

// The two refusals that were on the page before this module existed, kept here.
$res = $zAdmin->edit(1, 'basic', true, 'sky@example.test', 1);
checkSame(false, $res->isOk(), 'an admin cannot demote their own account');
checkSame('admin', $zAcc->roleOf(1), 'and the row is untouched');
checkSame(false, $zAdmin->edit(1, 'admin', false, 'sky@example.test', 1)->isOk(),
          'nor deactivate it');
checkSame(false, $zAdmin->edit(999, 'basic', true, 'nobody@example.test', 1)->isOk(),
          'editing an account that does not exist is refused');
checkSame(false, $zAdmin->edit(0, 'basic', true, 'nobody@example.test', 1)->isOk(),
          'and so is editing nothing at all');

// And a closed one, because this form's `is_active` is the only thing in the app that
// could look like an undo of a closure (invariant 14). Only a hand-built POST gets
// here — the panel renders no edit form for a closed account.
makeTestAccount($zPdo, 'gone');
$goneId = intval($zPdo->query("SELECT id FROM users WHERE username = 'gone'")->fetchColumn());
checkSame(true, $zAdmin->close($goneId, 1)->isOk(), 'an account can be closed');
checkSame(false, $zAdmin->edit($goneId, 'admin', true, 'gone@example.test', 1)->isOk(),
          'editing a closed account is refused, whatever the form said');
$goneRow = $zPdo->query("SELECT role, is_active FROM users WHERE username = 'gone'")->fetch();
checkSame(0, intval($goneRow['is_active']), 'and it is still shut out');
checkSame('basic', $goneRow['role'], 'with the role it held when it closed');

// The store's own write, which the use case is not allowed to guess about.
checkSame(false, $zAcc->updateProfile(999, 'basic', true, 'nobody2@example.test'),
          'updateProfile reports false for an id that names no account');
checkSame(true,  $zAcc->updateProfile(2, 'basic', true, 'clerk2@example.test'),
          'and true for a write that changed nothing, because the row is there');

// ---- The sentence that survives a redirect (#16) -------------------------------
// The grid redirects after saving so that F5 cannot replay a whole-matrix write.
// That needs the answer to outlive the redirect, and to be read exactly once.
$_SESSION = [];
checkSame(null, takeFlashMessage(), 'with nothing left behind, there is no message to show');
flashMessage('Access updated.', 'success');
$flash = takeFlashMessage();
checkSame('Access updated.', $flash['message'], 'a message left for a redirect is read back');
checkSame('success', $flash['type'], 'with the kind it was left as');
checkSame(null, takeFlashMessage(),
          'and reading it removes it, so a reload shows the page without repeating it');
flashMessage('Access could not be changed.', 'error');
checkSame('error', takeFlashMessage()['type'], 'an error keeps its kind');
flashMessage('Something', 'nonsense');
checkSame('success', takeFlashMessage()['type'], 'and an unknown kind reads as success rather than shouting');
flashMessage('', 'error');
checkSame(null, takeFlashMessage(), 'an empty message is no message');
$_SESSION['flash'] = 'not an array';
checkSame(null, takeFlashMessage(), 'and a session field of the wrong shape is ignored, not rendered');
$_SESSION = [];

// ─────────────────────────────────────────────────────────────
section('The three other ways a lock outlived the reach behind it');

// #17 closed one door: a revoked grant. Three more led to the same room.
//
//   · Turning a Display off takes it away from a `basic` account and leaves it with an
//     admin (Actor::mayOpen), so a clerk holding it loses the sign and cannot hand the
//     lock back — releasing goes through the seam that has just started refusing them.
//   · Suspending an account ends its session, so its Builder cannot beat and cannot
//     release either.
//   · Renaming the screen name tag breaks the address a Builder is holding. That one is
//     deliberately *not* a lost lock: a rename changes where the sign answers, not who
//     may edit it.
//
// Freeing the lock as each of those happens covers the doors somebody thought of.
// LockState refusing to honour a lock whose holder cannot sign in covers the rest —
// including rows already stranded before any of this existed, which is the only part
// that can help the live database on the day it is deployed.

$wPdo   = newTestDb();
$wStore = newTestDisplayStore($wPdo);
$wAdmin = newTestDisplayAdmin($wPdo);
$wAcct  = newTestAccountAdmin($wPdo);

$wDrive = makeTestDisplay($wPdo, 'drive-thru', 'Drive-Thru');
$wLobby = makeTestDisplay($wPdo, 'lobby', 'Lobby');
makeTestAccount($wPdo, 'kayla');                    // id 3, basic
grantTestAccess($wPdo, $wDrive->id(), 2);           // the clerk from the fixture
grantTestAccess($wPdo, $wLobby->id(), 2);
grantTestAccess($wPdo, $wDrive->id(), 3);

// ---- A lock whose holder can no longer sign in is not a lock --------------------

$wStore->claimLock(loadTestDisplay($wPdo, $wDrive->id()), 2);
checkSame(true, loadTestDisplay($wPdo, $wDrive->id())->lockState()->isHeld(),
          'a clerk holding a display holds it');

// Straight to the column, not through the module: this is the state a row is already
// in on the live database, arrived at by a path that no longer exists.
$wPdo->exec("UPDATE users SET is_active = 0 WHERE id = 2");
$wStranded = loadTestDisplay($wPdo, $wDrive->id());
checkSame(false, $wStranded->lockState()->isHeld(),
          'and stops holding it the moment that account cannot sign in');
checkSame(0, $wStranded->lockState()->holderId(),
          'so nothing names them as the holder');
checkSame('', $wStranded->editingSentence(),
          'and no banner says they are editing a sign they are locked out of');
checkSame(2, intval($wPdo->query("SELECT lock_holder_id FROM displays WHERE id = " . $wDrive->id())
                        ->fetchColumn()),
          'the row still records them, because this is a reading rule and not a sweep');

// The read and the write have to agree. They did not have to before, and a
// disagreement here is silent: a colleague shown an editable canvas whose every claim
// quietly does nothing, finding out at the publish.
$wAfter = $wStore->claimLock(loadTestDisplay($wPdo, $wDrive->id()), 3);
checkSame(true, $wAfter->lockState()->heldBy(3),
          'a colleague can really take a display stranded by a locked-out holder');

$wPdo->exec("UPDATE users SET is_active = 1 WHERE id = 2");
checkSame(true, loadTestDisplay($wPdo, $wDrive->id())->lockState()->heldBy(3),
          'and reinstating the old holder does not hand it back to them');

// The default matters as much as the rule: a row read without the users join has not
// learned the holder is locked out, it has learned nothing. Both directions of that
// are asserted, because a hand-written query somewhere else in the app is the one way
// to arrive at a Display whose joined columns are missing — and the two defaults have
// to lean opposite ways. Unknown "can they sign in" must leave a colleague's lock
// standing; unknown "are they an admin" must not protect a lock from being freed.
$wBare = new LockState(2, 'clerk', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'));
checkSame(true, $wBare->isHeld(),
          'a lock read without asking about its holder is left alone, not freed');
$wBareRow = new Display([
    'id' => 1, 'tag' => 'x', 'title' => 'X', 'canvas_width' => 1920, 'canvas_height' => 1080,
    'is_active' => 1, 'bg_type' => 'color', 'bg_val' => '#000', 'layout_revision' => 1,
    'last_published_at' => null, 'last_published_by' => null,
    'lock_holder_id' => 2, 'lock_activity_at' => gmdate('Y-m-d H:i:s'),
]);
checkSame(true, $wBareRow->lockState()->isHeld(),
          'and a Display built from a row with no holder columns still honours its lock');
checkSame(false, $wBareRow->lockHolderIsAdmin(),
          'while an unknown role is not read as admin, so it cannot shield a lock from being freed');

// ---- Turning a Display off frees the lock it takes away (#22) -------------------

$wStore->releaseLockOn($wDrive->id(), 3);
$wStore->claimLock(loadTestDisplay($wPdo, $wLobby->id()), 2);
$res = $wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), false);
checkSame(true, $res->isOk(), 'a display can be turned off');
checkSame(false, loadTestDisplay($wPdo, $wLobby->id())->isActive(), 'and really is off');
checkSame(false, loadTestDisplay($wPdo, $wLobby->id())->lockState()->isHeld(),
          'the clerk who was editing it is released, because it is no longer theirs to edit');
checkMentions($res->message(), 'edit lock has been released',
              'and the admin is told a session was ended rather than left to guess');
checkMentions($res->message(), 'editable by admins',
              'with what "still editable" now means');

// Only turning one *off* is a change of reach. Turning it on — or saving the state it
// is already in — takes nothing away from anybody, so it must take no lock either.
$res = $wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), true);
checkSame(true, $res->isOk(), 'and can be turned back on');
$wStore->claimLock(loadTestDisplay($wPdo, $wLobby->id()), 2);
$wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), true);
checkSame(true, loadTestDisplay($wPdo, $wLobby->id())->lockState()->heldBy(2),
          'turning an already-on display on again leaves the clerk editing it alone');
$wStore->releaseLockOn($wLobby->id(), 2);

// An admin holding a retired Display keeps it: that is the whole point of a Display
// staying editable while out of service. The rule is "free the holders who lost it",
// not "free everyone", and those differ by exactly this case.
$wStore->claimLock(loadTestDisplay($wPdo, $wLobby->id()), 1);   // account 1 is the admin
$res = $wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), false);
checkSame(true, loadTestDisplay($wPdo, $wLobby->id())->lockState()->heldBy(1),
          'an admin editing a display they retire keeps it');
check(strpos($res->message(), 'edit lock has been released') === false,
      'and is not told a lock was released when none was');

// Nobody editing at all is a third case, and it must not claim to have freed anything.
$wStore->releaseLockOn($wLobby->id(), 1);
$res = $wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), true);
$res = $wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), false);
check(strpos($res->message(), 'edit lock has been released') === false,
      'turning off a display nobody was editing mentions no lock');
$wAdmin->setActive(loadTestDisplay($wPdo, $wLobby->id()), true);
checkSame(false, $wPdo->inTransaction(), 'and none of that leaves a transaction open');

// ---- Suspending an account frees what it was holding (#22) ---------------------

$wStore->claimLock(loadTestDisplay($wPdo, $wDrive->id()), 2);
$wStore->claimLock(loadTestDisplay($wPdo, $wLobby->id()), 3);
$res = $wAcct->edit(2, 'basic', false, 'clerk@example.test', 1);
checkSame(true, $res->isOk(), 'an account can be suspended');
checkSame(false, loadTestDisplay($wPdo, $wDrive->id())->lockState()->isHeld(),
          'and the display it was holding is freed in the same write');
checkSame(0, intval($wPdo->query("SELECT lock_holder_id FROM displays WHERE id = " . $wDrive->id())
                        ->fetchColumn()),
          'really freed, in the row, not merely read as free');
checkSame(true, loadTestDisplay($wPdo, $wLobby->id())->lockState()->heldBy(3),
          'while a colleague editing another sign keeps theirs — freeing is by holder');
checkMentions($res->message(), 'cannot sign in', 'the answer says they are shut out');
checkMentions($res->message(), 'has been released', 'and that a display was let go');
checkSame([$wDrive->id(), $wLobby->id()],
          (new GrantStore($wPdo))->displayIdsFor(2),
          'suspending keeps their assignments, because it is not a closure');

// Suspending an account that is holding nothing must not claim otherwise, and
// suspending one that is already suspended must not either.
$res = $wAcct->edit(2, 'basic', false, 'clerk@example.test', 1);
check(strpos($res->message(), 'has been released') === false,
      'suspending an account twice frees nothing the second time');
checkMentions($res->message(), 'cannot sign in', 'and still says why they cannot get in');
$res = $wAcct->edit(2, 'basic', true, 'clerk@example.test', 1);
checkSame(true, $res->isOk(), 'and it can be let back in');
check(strpos($res->message(), 'cannot sign in') === false,
      'with nothing said about being shut out, because they are not');

// ---- A renamed tag tells them, and keeps their lock (#22) ----------------------

// The decision this encodes: a rename changes the address, not who may edit. Taking
// the lock away would punish somebody for an admin's retyping — so their page says
// the address moved, and reloading picks the same lock back up because it is still
// theirs.
$wStore->claimLock(loadTestDisplay($wPdo, $wDrive->id()), 2);
$res = $wAdmin->updateDetails(loadTestDisplay($wPdo, $wDrive->id()), [
    'brand_id' => 1, 'tag' => 'drive-through', 'title' => 'Drive-Thru', 'location' => '',
]);
checkSame(true, $res->isOk(), 'a screen name tag can be renamed');
checkSame('drive-through', loadTestDisplay($wPdo, $wDrive->id())->tag(), 'and really changes');
checkSame(true, loadTestDisplay($wPdo, $wDrive->id())->lockState()->heldBy(2),
          'the person editing it keeps the lock, because a rename is not a change of access');
checkMentions($res->message(), 'reload',
              'and the admin is told their page will ask them to reload');
checkMentions($res->message(), 'still theirs',
              'and that the display is still that person\'s to finish');

// With nobody editing, there is nothing to say about a reload.
$wStore->releaseLockOn($wDrive->id(), 2);
$res = $wAdmin->updateDetails(loadTestDisplay($wPdo, $wDrive->id()), [
    'brand_id' => 1, 'tag' => 'drive-thru', 'title' => 'Drive-Thru', 'location' => '',
]);
check(strpos($res->message(), 'reload') === false,
      'renaming a display nobody has open says nothing about anybody reloading');
checkMentions($res->message(), 'address changed', 'but still says the address moved');

// ---- What the Builder is told, asserted against the server's own reasons -------

// The Builder acts on five reasons and ignores everything else. Four of them are
// DisplayResolution kinds, so if one is ever renamed there this check fails rather
// than the page quietly going silent again.
$wJs = file_get_contents(__DIR__ . '/../builder.php');
foreach ([DisplayResolution::FORBIDDEN, DisplayResolution::INACTIVE,
          DisplayResolution::UNKNOWN,   DisplayResolution::MISMATCH] as $kind) {
    check(preg_match('/^\s*' . preg_quote($kind, '/') . '\s*:/m', $wJs) === 1,
          'the builder has a sentence for a refusal of kind "' . $kind . '"');
}
check(strpos($wJs, 'signed_out:') !== false,
      'and one for a session that is no longer signed in');
check(strpos(file_get_contents(__DIR__ . '/../api.php'), "'reason'  => 'signed_out'") !== false,
      'which is the reason api.php sends when the account behind a request went inactive');

// The publish reply's "who and when", read out of the source because there is no HTTP
// here to ask. Weaker than a behaviour check and stated as such (§4as's grade): what it
// pins is that both ends still name the same field and that the sentence is the stored
// one rather than a second copy built from time() and the session's username.
$apiSrc = file_get_contents(__DIR__ . '/../api.php');
checkMentions($apiSrc, "'published'    => \$justPublished ? \$justPublished->lastPublishDescription() : ''",
              'a successful publish sends back the sentence the row now holds');
checkMentions($apiSrc, '$justPublished = $displays->forId($display->id());',
              'read fresh after the publish, not composed beside it');
checkMentions($wJs, "showPublishState(res.published);",
              'and the Builder puts that sentence in its canvas footer');
checkMentions($wJs, '<span id="pub-state">',
              'which is a line the page renders on load as well, so it is there before any publish');
checkMentions($wJs, 'Last published by <?= Markup::text($display->lastPublishDescription()) ?>',
              'from the same method, through the one escaping door');
// The line moved out of the nav and into the footer, and the footer is emitted for
// a read-only Builder too — that was the case it was written for. `#canvas-footer`
// sits outside every `$readOnly` conditional, which selftest_builder_readonly.js
// holds it to by name; this is the half that says the publish line is inside it.
check(strpos($wJs, '<span id="pub-state">') > strpos($wJs, '<div id="canvas-footer">'),
      'and it is inside the canvas footer rather than the bar the sketch was clearing');

// ─────────────────────────────────────────────────────────────
section('What a visitor is told when something breaks');

// ErrorPolicy::install() is deliberately NOT called here: it would replace this
// suite's own error handler, which is the thing that makes a PHP diagnostic count
// as a failure. Everything below is reachable without installing, because the
// decisions worth testing — what each kind of caller is told, and whether a
// Screen can recover on its own — are pure functions of the mode.

$screen = ErrorPolicy::noticeFor(ErrorPolicy::SCREEN, 'This sign is temporarily unavailable.');
check(strpos($screen, 'content="30"') !== false,
      'a Screen is given a notice that re-checks every 30 seconds');
check(strpos($screen, 'This sign is temporarily unavailable.') !== false,
      'and it says so in words a customer can read');
check(strpos($screen, '#111') !== false,
      'on the same dark background the Viewer already uses, so it looks like the sign');
check(strpos($screen, '<!DOCTYPE') === 0,
      'as a whole document, because nothing of the page got out');

// Output already sent: the document is gone, so the notice has to cover it.
$partial = ErrorPolicy::noticeFor(ErrorPolicy::SCREEN, 'This sign is temporarily unavailable.', true);
check(strpos($partial, '<!DOCTYPE') === false,
      'a failure after output has begun does not send a second document');
check(strpos($partial, 'location.reload') !== false,
      'but it still gets the sign back on its own');
check(strpos($partial, 'z-index:2147483647') !== false,
      'and covers whatever half-drawn page it landed on');

$api = ErrorPolicy::noticeFor(ErrorPolicy::API, 'Temporarily unavailable.');
$decoded = json_decode($api, true);
check(is_array($decoded), 'an endpoint gets JSON, not HTML — its caller is a script');
checkSame('error', $decoded['status'], 'with the status the Builder and the Viewer both branch on');
checkSame('Temporarily unavailable.', $decoded['message'],
          'and the message the Viewer prints straight onto the sign');

$page = ErrorPolicy::noticeFor(ErrorPolicy::PAGE, 'Something went wrong.');
check(strpos($page, 'content="30"') === false,
      'a page somebody is typing into does not reload itself under them');
check(strpos($page, 'Something went wrong.') !== false, 'it just says so');

// The sentence reaches the browser. Nothing that ever becomes one is attacker-set
// today, but this is the file that prints during a failure, when the guards that
// normally escape output have already been skipped.
$nasty = ErrorPolicy::noticeFor(ErrorPolicy::SCREEN, '<script>alert(1)</script>');
check(strpos($nasty, '<script>alert(1)</script>') === false,
      'the sentence is escaped on its way into the notice');

check(strpos(ErrorPolicy::noticeFor('nonsense', 'x'), '<!DOCTYPE') === 0,
      'an unrecognised mode falls back to a page, never to nothing');

checkSame('This sign is temporarily unavailable.', ErrorPolicy::sentence(ErrorPolicy::SCREEN),
          'the Screen sentence names the sign, not the server');
check(strpos(ErrorPolicy::sentence(ErrorPolicy::PAGE), 'error log') !== false,
      'a signed-in person is told where the detail went');
foreach ([ErrorPolicy::SCREEN, ErrorPolicy::API, ErrorPolicy::PAGE] as $mode) {
    check(strpos(ErrorPolicy::sentence($mode), '/') === false,
          'no default sentence can carry a server path (' . $mode . ')');
}

// api.php's public poll overrides the wording, because its caller is a Screen
// even though its reply is JSON.
ErrorPolicy::sayOnFailure('This sign is temporarily unavailable.');
checkSame('This sign is temporarily unavailable.', ErrorPolicy::sentence(ErrorPolicy::API),
          'an endpoint serving a Screen can say the Screen\'s words');
ErrorPolicy::sayOnFailure('');
checkSame('Temporarily unavailable. Please try again in a moment.', ErrorPolicy::sentence(ErrorPolicy::API),
          'and clearing the override restores the default');

// ─────────────────────────────────────────────────────────────
section('The error log');

ErrorPolicy::useLogFile('');
checkSame(false, ErrorPolicy::log('nowhere to write this'),
          'with no writable directory the log reports that it wrote nothing');

$stateDir = newTestStateDir();
$logPath  = $stateDir . '/lbm-error.log';
ErrorPolicy::useLogFile($logPath);

checkSame(true, ErrorPolicy::log('a plain message'), 'otherwise it writes');
$written = file_get_contents($logPath);
check(strpos($written, 'a plain message') !== false, 'and the message is in the file');
check(strpos($written, ' UTC]') !== false,
      'stamped in UTC, so two entries from either side of a clock change still order');

// One entry, one line. A message carrying newlines — an exception with a trace,
// a MySQL error — would otherwise break every later reading of this file.
ErrorPolicy::log("first line\nsecond line\r\nthird");
$lines = array_filter(explode("\n", file_get_contents($logPath)));
checkSame(2, count($lines), 'a multi-line message is flattened into a single entry');

ErrorPolicy::handleError(E_WARNING, 'a warning nobody caught', '/srv/app/thing.php', 42);
$written = file_get_contents($logPath);
check(strpos($written, 'WARNING: a warning nobody caught') !== false,
      'a PHP warning is written down rather than printed');
check(strpos($written, 'thing.php:42') !== false, 'with the file and line that raised it');
checkSame(true, ErrorPolicy::handleError(E_NOTICE, 'a notice', '', 0),
          'and the handler reports it handled it, so PHP prints nothing itself');

// The app is full of deliberate `@` calls — this very module's filesystem writes,
// schemaTry, the reset email. Logging them would bury the real entries.
//
// Both sides of this comparison are cleared first. `log()` stats the same path on
// every call, and on some PHP builds the cached answer survives the append that
// follows it — so an uncleared `$before` is the size the file was several entries
// ago, and the check fails on a log that behaved perfectly.
clearstatcache(true, $logPath);
$before = filesize($logPath);
$was    = error_reporting(0);
ErrorPolicy::handleError(E_WARNING, 'a deliberately suppressed call', '', 0);
error_reporting($was);
clearstatcache(true, $logPath);
checkSame($before, filesize($logPath), 'a suppressed diagnostic is not logged at all');

// A shared host has a disk quota, and this file is appended to by every request
// forever. The file is grown behind the module's back here on purpose: that is what
// the *other* requests appending to this same log do, and rotation has to decide on
// the size the file is rather than the size it was when this process last looked.
file_put_contents($logPath, str_repeat('x', ErrorPolicy::MAX_LOG_BYTES + 1));
ErrorPolicy::log('the entry that tipped it over');
clearstatcache(true, $logPath);
check(file_exists($logPath . '.1'), 'an oversized log is rotated rather than grown forever');
clearstatcache();
check(filesize($logPath) < 1024, 'and the live file starts again');

// The size is not measured once, it is measured before every entry, so what matters
// is whether a *later* entry in the same request measures it again — a request that
// logs, keeps working, and logs again.
//
// Three entries, not two, and the order is the whole check: the very first log() to a
// path that does not exist yet never reaches filesize() at all, because `is_file` is
// false and `&&` stops there. It takes a second entry to put a size in the cache and
// a third to read it back stale. Written with two, this passes against the unfixed
// module — there was never a cached answer for it to trust — which is how the version
// that landed first was hollow without looking it.
//
// Honest about its reach: on PHP 8 it passes with or without the clearstatcache() in
// ErrorPolicy::log(), because 8 invalidates the cache on its own writes. It is
// load-bearing below 8 only, and this runtime is 8.4 — so it records what the fix is
// for rather than proving it here. Kept because the knowledge outlasts the runtime.
$rollDir  = newTestStateDir();
$rollPath = $rollDir . '/lbm-error.log';
ErrorPolicy::useLogFile($rollPath);
ErrorPolicy::log('the first entry of the request, which creates the file');
ErrorPolicy::log('the second, which is the one that measures it');
file_put_contents($rollPath, str_repeat('x', ErrorPolicy::MAX_LOG_BYTES + 1));
ErrorPolicy::log('the third, by which time the file is far too big');
clearstatcache(true, $rollPath);
check(file_exists($rollPath . '.1'),
      'a later entry in the same request measures the file again rather than remembering it');

// ─────────────────────────────────────────────────────────────
section('What the Settings tab says the error policy is doing');

// This readout had no check of any kind. `admin_panel.php` prints it through the same
// `[value, note]` loop as `ServerReport::runtime()` — the loop that runtime() has a
// check about, three lines of "every fact is a printable pair" — and nothing ever
// called status() at all. Which matters more here than there, because its own docblock
// says why it exists: every part of what it reports fails *silently by design*. An
// unwritable directory means no log; no recipients means no alert; and both look
// exactly like nothing having gone wrong. A readout that is the only way to see that
// is a readout worth knowing renders.
$statusDir  = newTestStateDir();
$statusPath = $statusDir . '/lbm-error.log';
ErrorPolicy::useLogFile($statusPath);
ErrorPolicy::log('an entry, so there is something to report the age of');
$status = ErrorPolicy::status();

$statusStrings = true;
foreach ($status as $fact) {
    if (!is_array($fact) || count($fact) !== 2
        || !is_string($fact[0]) || !is_string($fact[1])) { $statusStrings = false; }
}
check($statusStrings, 'every fact is a printable pair, the same shape the panel loops for both cards');
check(isset($status['Errors shown to visitors']), 'it says whether a PHP error would reach a visitor');
check(isset($status['Error log']), 'and where what goes wrong is being written');
check(isset($status['Last logged']), 'and when something last did');
check(isset($status['Alerts go to']), 'and who finds out without looking');
checkSame($statusPath, $status['Error log'][0], 'the log row names the file actually in use');
checkSame('', $status['Error log'][1], 'and says nothing more when it can be written to');
check(strpos($status['Last logged'][0], 'UTC') !== false,
      'the moment carries its frame, since this row is read beside two other clocks');
// The alert half. `ErrorPolicy::$alerts` is unset in this process, which is the same
// state as a shop where no admin has an email address on file.
checkSame('Nobody', $status['Alerts go to'][0], 'with nobody attached, the alert row says Nobody');
checkMentions($status['Alerts go to'][1], 'nobody will be told',
              'and spells out the consequence rather than leaving "Nobody" to be read as fine');

// The branch that fires when the app's own decision has been overridden. `-d
// display_errors=1` reaches it because this suite deliberately never calls
// ErrorPolicy::install(), and `selftest_installed.php` runs an arm that does exactly
// that — so the two forms of this row are each produced by a real process.
//
// Stated as the invariant and not as this machine's answer. Predicting the word from
// `ini_get('display_errors')` here would be the §4bg mistake in miniature — and worse
// than usual, because `ini_get` answers the string 'Off' for a flag that is off, which
// is truthy, so the prediction would have been *wrong* on a host that spells it that
// way and right on this one, which spells it ''.
$showing = ErrorPolicy::status()['Errors shown to visitors'];
check($showing[0] === 'On' || $showing[0] === 'Off',
      'the row is one of two words, whichever way this host is set');
check(($showing[0] === 'On') === ($showing[1] !== ''),
      'and it says something exactly when errors would reach a visitor, which is never '
    . 'the app\'s own doing — it sets the flag off on every request');

// Nowhere to write. The first row of the pair changes and the second disappears —
// there is no "last logged" when there is no log.
ErrorPolicy::useLogFile('');
$noLog = ErrorPolicy::status();
checkSame('Nowhere to write', $noLog['Error log'][0], 'with no writable directory the log row says so');
checkMentions($noLog['Error log'][1], 'no alert can be sent',
              'and names the second thing that stops, which is the one nobody would guess');
check(!isset($noLog['Last logged']),
      'and there is no "last logged" row at all, rather than one reading never');
ErrorPolicy::useLogFile($statusPath);

// ---- Which request the log line is about -----------------------------------------
// The tag on a JSON failure, and the reason this pass exists in one line: the `'cli'`
// fallback was written for the command line and has never once been reached there.
// PHP sets SCRIPT_NAME on the CLI too — to the script path, and under `php -r` to the
// literal phrase "Standard input code", which is PHP's own internal English arriving
// in a log a person reads to find out which page broke.
checkSame('cli', ErrorPolicy::requestNameFor('cli', '/home/acct/tools/selftest_layout.php'),
          'a command-line run is cli, whatever PHP put in SCRIPT_NAME');
checkSame('cli', ErrorPolicy::requestNameFor('cli', 'Standard input code'),
          'including `php -r`, which is how selftest_installed.php runs every arm');
checkSame('api.php', ErrorPolicy::requestNameFor('apache2handler', '/lbm/api.php'),
          'a real request is named by its page');
checkSame('api.php', ErrorPolicy::requestNameFor('fpm-fcgi', '/lbm-test/api.php'),
          'and by the page rather than the install, which the row above it already names');
checkSame('cli', ErrorPolicy::requestNameFor('cgi-fcgi', ''),
          'and a host that supplies no script name still gets a word rather than an empty tag');
checkSame('cli', ErrorPolicy::whichRequest(),
          'so this suite tags its own log lines cli, which is what it is');

// ─────────────────────────────────────────────────────────────
section('Alerts: one per problem per hour, to admins only');

$alertDir = newTestStateDir();
$mailer   = new TestAlertMailer($alertDir, 'Lummi Bay Market');

checkSame(false, $mailer->notify('db', 'Database unreachable', 'detail'),
          'with nobody to write to, nothing is sent');

$mailer->remember(['sky@example.test', 'boss@example.test']);
checkSame(['sky@example.test', 'boss@example.test'], $mailer->recipients(),
          'the cached recipients survive being written to disk and read back');

// The cache is the only list available when the database is the thing that failed,
// so a call that would empty it has to be refused rather than obeyed.
$mailer->remember([]);
checkSame(2, count($mailer->recipients()), 'an empty list never blanks a working one');
$mailer->remember(['sky@example.test', 'boss@example.test', 'not-an-address']);
checkSame(2, count($mailer->recipients()), 'and something that is not an address is dropped');
$mailer->remember(['sky@example.test', "boss@example.test\nBcc: someone@else.test"]);
checkSame(1, count($mailer->recipients()),
          'a newline in an address is refused — it would forge a header');

$mailer2 = new TestAlertMailer($alertDir, 'Lummi Bay Market');
$mailer2->remember(['sky@example.test', 'boss@example.test']);
checkSame(true, $mailer2->notify('db|db_connect.php:47', 'Database unreachable', 'detail'),
          'a problem nobody has heard about is sent');
checkSame(1, count($mailer2->sent), 'once');
check(strpos($mailer2->sent[0]['to'], 'sky@example.test') !== false
      && strpos($mailer2->sent[0]['to'], 'boss@example.test') !== false,
      'to every admin on the list');
check(strpos($mailer2->sent[0]['subject'], 'Lummi Bay Market') !== false,
      'with the site named in the subject, because one inbox may watch several');

// The whole point. Four Screens polling every 30 seconds through an outage is
// 11,520 emails a day if this returns true.
checkSame(false, $mailer2->notify('db|db_connect.php:47', 'Database unreachable', 'detail'),
          'the same problem again inside the hour is not sent');
checkSame(1, count($mailer2->sent), 'and no second message goes out');

// Per problem, not per hour. A second failure elsewhere is news.
checkSame(true, $mailer2->notify('fatal|viewer.php:22', 'Fatal error', 'detail'),
          'a different problem in the same hour is still sent');
checkSame(2, count($mailer2->sent), 'so both are known about');

check($mailer2->lastSent('db|db_connect.php:47') > 0, 'and the panel can see when one last went out');
checkSame(0, $mailer2->lastSent('never-happened'), 'while a problem that never happened has no time');

// The recipient list and the rate limiter live in the same directory, so losing it
// loses both — there is nobody to tell and no way to remember having told them.
$blind = new TestAlertMailer('', 'Lummi Bay Market');
checkSame(false, $blind->notify('db', 'Database unreachable', 'detail'),
          'with no writable directory at all, nothing is sent');
checkSame(0, count($blind->sent), 'not even the first one');

// And the case the guard above cannot reach: a directory that is there, with a
// recipient list in it, where this one stamp cannot be written. Sending without
// recording it is how one problem becomes an email per Screen per poll, so the
// send has to lose. The stamp's name is read back from a successful send rather
// than recomputed here — a test that reimplements the naming would agree with a
// broken module.
$stampDir = newTestStateDir();
$namer    = new TestAlertMailer($stampDir, 'Lummi Bay Market');
$namer->remember(['sky@example.test']);
$namer->notify('wedged|viewer.php:1', 'Fatal error', 'detail');
$stampName = basename(glob($stampDir . '/alert-*.stamp')[0]);

$jammedDir = newTestStateDir();
$jammed    = new TestAlertMailer($jammedDir, 'Lummi Bay Market');
$jammed->remember(['sky@example.test']);
mkdir($jammedDir . '/' . $stampName);   // a path that can never be written as a file
checkSame(false, $jammed->notify('wedged|viewer.php:1', 'Fatal error', 'detail'),
          'a send it could not have recorded is refused instead');
checkSame(0, count($jammed->sent), 'so an outage cannot turn into an email per poll');

// ─────────────────────────────────────────────────────────────
section('A schema statement that really was refused says so');

// schemaTry() has always swallowed failures, because most of them mean "already
// applied" — so a statement that genuinely could not run was indistinguishable from
// the twelve that fail by design every request, and nothing said so. The lockout
// columns were missing on the live database for months.
//
// The rule that makes reporting safe is narrow: only a statement the *catalogue*
// said was missing is ever reported. A statement included because the catalogue
// could not be read is a guess, and guesses fail all the time.

$sPdo2 = newTestDb();

// The reason now survives the catch, which is what lets an alert say why.
$err = 'untouched';
checkSame(false, schemaTry($sPdo2, "ALTER TABLE nope ADD COLUMN x INT", $err),
          'a statement that cannot run still fails quietly');
check(strpos($err, 'nope') !== false, 'but hands back the database\'s own reason');
checkSame(true, schemaTry($sPdo2, "UPDATE assets SET label = label", $err),
          'one that works still succeeds');
checkSame('', $err, 'and leaves no reason behind it');

// Every plan entry carries the need it was included on. In a blind plan every
// *statement* is a guess — its need was the catalogue's word and there was no
// catalogue — but the two row-count steps are not, because their need never came
// from the catalogue at all. A count runs and answers on any host, so a failure
// there means something real wherever it happens, and it stays reportable.
$blindPlan = signageSchemaPlan(SchemaFacts::unknown());
$guessed = $certain = [];
foreach ($blindPlan as $entry) {
    if ($entry['need'] === null) { $guessed[] = $entry['why']; }
    if ($entry['need'] === true) { $certain[] = $entry['why']; }
}
checkSame(39, count($guessed), 'with no catalogue, every statement in the plan is a guess');
checkSame(['seed_first_brand', 'seed_block_styles', 'seed_legacy_display'], $certain,
          'and the only certainties are the three steps that ask the rows, not the catalogue');
$statementNeeds = [];
foreach ($blindPlan as $entry) {
    if (isset($entry['sql'])) { $statementNeeds[] = $entry['need']; }
}
checkSame([null], array_values(array_unique($statementNeeds, SORT_REGULAR)),
          'not one blind statement claims the catalogue backed it');

$shape = convergedSchemaShape();
unset($shape['columns']['assets']['auto_pooled']);
$known = schemaPlanFor($shape);
checkSame(true, $known[0]['need'], 'a statement the catalogue proved missing is marked as known');

$shape = convergedSchemaShape();
unset($shape['columns']['assets']['auto_pooled']);
$known = schemaPlanFor($shape);
checkSame(true, $known[0]['need'], 'a statement the catalogue proved missing is marked as known');

checkSame(1, count(schemaFailuresWorthReporting([
    ['why' => 'the catalogue said so', 'need' => true],
    ['why' => 'a guess',               'need' => null],
    ['why' => 'assembled by hand'],
])), 'only the known one is worth reporting');

// ---- The case that must stay silent -------------------------------------------
// A host that hides information_schema converges by trying everything. Most of
// these statements are MySQL-only, so on SQLite nearly all of them fail — which is
// exactly the shape of that host, and exactly what must never reach an inbox.
$stateDir9 = newTestStateDir();
ErrorPolicy::useLogFile($stateDir9 . '/lbm-error.log');
$mailer9 = new TestAlertMailer($stateDir9, 'Lummi Bay Market');
$mailer9->remember(['sky@example.test']);
ErrorPolicy::useAlerts($mailer9);

$blindFailures = runSchemaPlan(newTestDb(), signageSchemaPlan(SchemaFacts::unknown()));
check(count($blindFailures) > 5, 'a blind plan against the wrong engine fails most of its statements');
checkSame(false, reportSchemaFailures($blindFailures),
          'and not one of them is reported, because none of them was known to be needed');
checkSame(0, count($mailer9->sent), 'so a host that hides its catalogue cannot fill an inbox');
checkSame(false, file_exists($stateDir9 . '/lbm-error.log'),
          'nor write a line to the log');

// ---- The case that must not ---------------------------------------------------
// The catalogue says `assets` has no auto_pooled column; the fixture's table has
// one. So the ALTER is included on a known need and then genuinely refused, which
// is the live shape of "this could not be applied and nobody would ever know".
$realFailures = runSchemaPlan($sPdo2, $known);
checkSame(1, count($realFailures), 'a statement the catalogue asked for and the database refused');
check(isset($realFailures[0]['error']) && $realFailures[0]['error'] !== '',
      'is carried back with the reason attached');

checkSame(true, reportSchemaFailures($realFailures), 'that one is reported');
checkSame(1, count($mailer9->sent), 'an admin is emailed about it');
$body = $mailer9->sent[0]['body'];
checkMentions($body, 'assets.auto_pooled', 'the message names the change in the plan\'s own words');
checkMentionsAnyCase($body, 'duplicate column', 'and the reason the database gave');
checkMentions($body, 'Database Structure', 'and where to see what a missing column costs');
check(strpos($body, 'ALTER TABLE') === false,
      'and never the SQL — the words are for a person, not a DBA');
checkMentions($mailer9->sent[0]['subject'], 'refused', 'the subject says what happened');
$logged = file_get_contents($stateDir9 . '/lbm-error.log');
checkMentions($logged, 'assets.auto_pooled', 'and it is in the log as well as the email');

// ---- Once an hour, and not once per page load ---------------------------------
// A refused statement is retried on every signed-in page load, and on the Viewer's
// self-heal path every 30 seconds per Screen. Unthrottled that is thousands of
// identical lines a day in a file that rotates at 2 MB.
checkSame(false, reportSchemaFailures($realFailures),
          'the same failures again inside the hour are not reported');
checkSame(1, count($mailer9->sent), 'no second email');
checkSame(1, count(array_filter(explode("\n", $logged))),
          'and the log still has the one entry');
clearstatcache();
checkSame(1, count(array_filter(explode("\n", file_get_contents($stateDir9 . '/lbm-error.log')))),
          'confirmed against the file rather than the copy read earlier');

// Per set of failures, not per hour: something new breaking is news immediately.
checkSame(true, reportSchemaFailures([
    ['why' => 'display_id is NOT NULL', 'need' => true, 'error' => 'data too long'],
]), 'a different failure in the same hour is reported straight away');
checkSame(2, count($mailer9->sent), 'so the new one is not held behind the old one');

// ---- …and the same set in a different order is the same set (decision #49) ----
// The key is built by sorting the names and hashing them, which is the whole of
// what makes "the same failures" mean anything. The plan is ordered, but the set
// that comes back from it is not stable across a database that fixes one statement
// and breaks another — and an unsorted key turns that into a second email inside
// the hour, from the alert whose entire purpose is not to do that.
$orderDir = newTestStateDir();
ErrorPolicy::useLogFile($orderDir . '/lbm-error.log');
$orderMailer = new TestAlertMailer($orderDir, 'Lummi Bay Market');
$orderMailer->remember(['sky@example.test']);
ErrorPolicy::useAlerts($orderMailer);

$one = ['why' => 'displays table',      'need' => true, 'error' => 'no CREATE privilege'];
$two = ['why' => 'grant → account',     'need' => true, 'error' => 'no CREATE privilege'];
checkSame(true,  reportSchemaFailures([$one, $two]), 'two failures are reported once');
checkSame(false, reportSchemaFailures([$two, $one]),
          'and the same two the other way round are the same problem, not a new one');
checkSame(1, count($orderMailer->sent), 'so an admin gets one email, not one per ordering');

// ---- A message from the database is not given the run of the email ------------
// `$e->getMessage()` is whatever the driver felt like saying, and on a failed
// `CREATE TABLE` that can be the whole statement echoed back. The log rotates at
// 2 MB and the email is read on a phone, so it is cut at 200 characters with an
// ellipsis to say that it was.
checkSame(true, reportSchemaFailures([
    ['why' => 'displays table', 'need' => true, 'error' => str_repeat('x', 500)],
]), 'a failure with a runaway reason is still reported');
$longBody = $orderMailer->sent[1]['body'];
check(strpos($longBody, str_repeat('x', 201)) === false, 'but the reason is cut short');
check(strpos($longBody, str_repeat('x', 200)) !== false, 'at the 200 characters it keeps');
checkMentions($longBody, '…', 'and says so rather than looking like the whole of it');

// ---- Ten of them, and a count of the rest -------------------------------------
// A database with no privileges at all fails every statement in the plan. Listing
// seventeen of them tells an admin nothing that "seventeen" does not, and the first
// ten are the ones that ran first.
$many = [];
for ($i = 1; $i <= 12; $i++) {
    $many[] = ['why' => 'change number ' . $i, 'need' => true, 'error' => 'refused'];
}
checkSame(true, reportSchemaFailures($many), 'a plan that fails wholesale is reported');
$manyBody = $orderMailer->sent[2]['body'];
checkSame(10, substr_count($manyBody, 'change number '), 'ten of them are named');
checkSame(11, substr_count($manyBody, "\n  * "),
          'in eleven bullets, because the count of the rest is one of them');
checkMentions($manyBody, 'and 2 more', 'and the rest are counted rather than listed');
checkMentions($manyBody, '12 schema updates', 'with the real total in the opening line');
check(strpos($manyBody, 'change number 12') === false, 'so the twelfth is not in the list');

// One is one, and the sentence has to read like it — this is the alert an admin
// sees most often, because one refused statement is the common shape of the fault.
checkSame(true, reportSchemaFailures([
    ['why' => 'displays.lock_taken_at', 'need' => true, 'error' => 'refused'],
]), 'a single failure is reported');
checkMentions($orderMailer->sent[3]['body'], '1 schema update the database says it needs',
              'and reads as one update, not "1 schema updates"');

// ---- What counts as "the catalogue said so" is the value, not its truthiness ---
// `need` is three-valued and the report turns on it being exactly `true`. Anything
// merely truthy got there some other way, and the rule this protects — never report
// a guess — is only as good as the comparison.
checkSame(0, count(schemaFailuresWorthReporting([
    ['why' => 'a need of 1',       'need' => 1],
    ['why' => 'a need of "true"',  'need' => 'true'],
    ['why' => 'a need of false',   'need' => false],
])), 'a need that is merely truthy is not the catalogue having said so');

ErrorPolicy::useLogFile($stateDir9 . '/lbm-error.log');
ErrorPolicy::useAlerts($mailer9);

// ---- Through the entry point, not just the reporting function -----------------
// The checks above call reportSchemaFailures() directly, which proves the rule and
// not the wiring: removing the call from ensureSignageSchema() altogether would
// leave every one of them passing. This is the one that fails if nothing reports.
$wiredDir = newTestStateDir();
ErrorPolicy::useLogFile($wiredDir . '/lbm-error.log');
$wiredMailer = new TestAlertMailer($wiredDir, 'Lummi Bay Market');
$wiredMailer->remember(['sky@example.test']);
ErrorPolicy::useAlerts($wiredMailer);

// A fixture whose catalogue disagrees with it about one column: the plan asks for
// the ALTER on a known need, the table already has the column, the database refuses.
//
// SQLite even on a MySQL run, and for a second reason beyond the catalogue: the
// refusal being reported here is a real database saying no to a statement the plan
// was sure about. On MySQL against a schema.sql-built database the plan is empty,
// so there is no statement to refuse and nothing to report.
$wiredPdo = newSqliteTestDb();
$wiredShape = convergedSchemaShape();
unset($wiredShape['columns']['assets']['auto_pooled']);
fakeCatalogue($wiredShape, $wiredPdo);

SchemaLatch::forget();
ensureSignageSchema($wiredPdo);
checkSame(1, count($wiredMailer->sent), 'the entry point itself reports a refused statement');
checkMentions($wiredMailer->sent[0]['body'], 'assets.auto_pooled', 'naming it');

// And the latch: once per request means once, so a second call does nothing at all.
ensureSignageSchema($wiredPdo);
checkSame(1, count($wiredMailer->sent), 'and converging twice in one request reports once');
SchemaLatch::forget();
checkSame(true, SchemaLatch::take(), 'a cleared latch can be taken again');
checkSame(false, SchemaLatch::take(), 'but only once');
SchemaLatch::forget();

// With nowhere to write, the throttle cannot remember — and nothing is being
// written either, so letting the report through costs nothing.
ErrorPolicy::useLogFile('');
checkSame(true, ErrorPolicy::report('nowhere', 'detail', 'Problem', ErrorPolicy::REPORT_WINDOW),
          'with no writable directory a throttled report is not held back');

// Put the policy back where the rest of the suite expects it.
ErrorPolicy::useAlerts(new TestAlertMailer('', 'Lummi Bay Market'));

// ---- The two steps that can fail for a reason worth naming --------------------

// The drive-thru Display is what unscoped elements are handed to. Without it the
// backfill has nothing to point at and the tighten after it refuses — so the seed
// is the failure worth reporting and the other two are its consequences.
$noDisplay = newTestDb();
$noDisplay->exec("DELETE FROM canvas_elements");
$noDisplay->exec("DELETE FROM displays");
$err = '';
checkSame(false, backfillDisplayId($noDisplay, $err), 'with no Display, the backfill refuses');
checkMentions($err, 'no Display', 'and says that is why');

// An empty database is what the seed is for: it creates the Display the
// pre-multi-display layout belongs to, and says so by succeeding.
$seeded = newTestDb();
$err = 'untouched';
checkSame(true, seedLegacyDisplay($seeded, $err), 'an empty database gets the drive-thru Display');
checkSame('', $err, 'with nothing to report');
checkSame(LEGACY_DISPLAY_TAG, $seeded->query("SELECT tag FROM displays")->fetchColumn(),
          'under the tag the Viewer URL contract uses');

// And a Display already there means somebody got here first — nothing to do, and
// certainly nothing to create a second of.
$err = 'untouched';
checkSame(true, seedLegacyDisplay($seeded, $err), 'a second pass leaves it alone');
checkSame('', $err, 'still with nothing to report');
checkSame(1, intval($seeded->query("SELECT COUNT(*) FROM displays")->fetchColumn()),
          'and no second Display is created');

// The seed's other quiet path is a tag collision — two first-ever requests racing,
// where the loser's insert fails on a Display that is now there. It asks
// legacyDisplayId() and reports nothing, so that answer is what the branch rests on.
// This covers the question; the interleaving that asks it is produced further down,
// under "The deploy-day race".
$idPdo = newTestDb();
checkSame(0, legacyDisplayId($idPdo), 'with no Display at all there is nothing to point at');
makeTestDisplay($idPdo, LEGACY_DISPLAY_TAG, 'Drive-Thru');
check(legacyDisplayId($idPdo) > 0, 'the drive-thru Display is found by its tag');
$idPdo->exec("UPDATE displays SET tag = 'renamed-by-an-admin'");
check(legacyDisplayId($idPdo) > 0, 'and by being the oldest when an admin has renamed it');

// The two answers in that order, not either one (decision #49). A store that added a
// second sign by hand before this build ran has two Displays, and the unscoped rows
// belong to the drive-thru one whether or not it happens to be the older row.
$orderPdo = newTestDb();
makeTestDisplay($orderPdo, 'lobby', 'Lobby');
$driveThruId = makeTestDisplay($orderPdo, LEGACY_DISPLAY_TAG, 'Drive-Thru')->id();
checkSame($driveThruId, legacyDisplayId($orderPdo),
          'the tag is asked for first, so age only decides when no tag matches');

// A seed that genuinely cannot write is the one an admin needs to hear about.
$refuses = newTestDb();
$refuses->exec("DELETE FROM canvas_elements");
$refuses->exec("DELETE FROM displays");
makeTableUnwritable($refuses, 'displays');
$err = '';
checkSame(false, seedLegacyDisplay($refuses, $err), 'a seed the database refuses fails');
checkMentions($err, 'drive-thru Display could not be created', 'and names what is missing');

// And a table that is not there at all reads differently from one that refused.
$noTable = newTestDb();
$noTable->exec("DROP TABLE display_permissions");
$noTable->exec("DROP TABLE canvas_elements");
$noTable->exec("DROP TABLE displays");
$err = '';
checkSame(false, seedLegacyDisplay($noTable, $err), 'no displays table at all also fails');
checkMentions($err, 'not there', 'and says the table is missing rather than blaming the data');

$err = 'untouched';
checkSame(true, runSchemaStep(newTestDb(), 'no_such_step', $err),
          'an unknown step still reports nothing to do');
checkSame('', $err, 'and leaves no reason');

// ---- What each step writes, and what it must leave alone (decision #49) --------
// The four steps are the part of convergence that touches rows rather than
// structure, which makes them the part that can lose somebody's data. Sixty-two
// mutations of lib/schema.php left nineteen alive and most of them were here: a
// WHERE clause could be deleted from either backfill in silence, and the seed could
// be made to create a second Display or to carry the wrong background forward.

// A count that cannot even be taken is not "all six are present". The step runs on
// an install whose CREATE TABLE has not landed yet, and Brand Standards saves with
// UPDATE … WHERE block_type = ?, so a missing row is a form that reverts on reload
// and says nothing — which is exactly what an admin needs told.
$noStyles = newTestDb();
$noStyles->exec("DROP TABLE block_styles");
$err = '';
checkSame(false, seedBlockStyles($noStyles, $err), 'with no block_styles table the seed fails');
checkMentions($err, 'could not be read', 'and says it could not even count what is there');

// The pool marker is for rows a *publish* made. The only evidence left of that on a
// database being upgraded is the `Auto: ` label prefix, so the statement reads it —
// and a statement that stopped reading it would claim an admin's own uploads, which
// the Library's Tidy up button then offers to delete.
$mixPdo = newTestDb();
$mixPdo->exec("INSERT INTO assets (type,content,label) VALUES ('text','OPEN 7 DAYS','Auto: OPEN 7 DAYS')");
$mixPdo->exec("INSERT INTO assets (type,content,label) VALUES ('image','uploads/promo.jpg','Summer banner')");
checkSame(true, backfillPooledMarker($mixPdo), 'the pool backfill runs');
checkSame(1, intval($mixPdo->query("SELECT auto_pooled FROM assets WHERE label LIKE 'Auto:%'")->fetchColumn()),
          'and marks what the old pooling left behind');
checkSame(0, intval($mixPdo->query("SELECT auto_pooled FROM assets WHERE label = 'Summer banner'")->fetchColumn()),
          'while a row somebody named themselves is left unmarked, so no sweep can take it');

// The display_id backfill hands *unscoped* rows to the drive-thru sign. Without the
// WHERE it hands over every row on every sign, which is every other Display going
// blank at once and no way back — the layouts are gone, not moved somewhere findable.
// Run against a nullable display_id, because that is the only state it ever sees:
// the plan puts it between the ADD COLUMN and the tighten.
$midPdo = newTestDb();
createNullableDisplayIdElements($midPdo);
$midDrive = makeTestDisplay($midPdo, LEGACY_DISPLAY_TAG, 'Drive-Thru');
$midLobby = makeTestDisplay($midPdo, 'lobby', 'Lobby');
$midPdo->exec("INSERT INTO canvas_elements (display_id) VALUES (" . $midLobby->id() . ")");
$midPdo->exec("INSERT INTO canvas_elements (display_id) VALUES (NULL)");
checkSame(true, backfillDisplayId($midPdo), 'the display_id backfill runs');
checkSame(1, intval($midPdo->query("SELECT COUNT(*) FROM canvas_elements WHERE display_id = "
                                   . $midDrive->id())->fetchColumn()),
          'the row that predates Display scoping becomes the drive-thru sign\'s');
checkSame(1, intval($midPdo->query("SELECT COUNT(*) FROM canvas_elements WHERE display_id = "
                                   . $midLobby->id())->fetchColumn()),
          'and a row that already belongs to another sign stays exactly where it was');

// The seed stops at "any Display exists", not at "a Display called drive-thru
// exists" — because the tag is the admin's to change (ADR-0003), and a second
// drive-thru appearing behind their back is a sign nobody is looking at, taking the
// backfill with it. A UNIQUE index would refuse the duplicate; a renamed tag is the
// case where nothing but this count would.
$renamed = newTestDb();
$err = '';
checkSame(true, seedLegacyDisplay($renamed, $err), 'a first pass creates the drive-thru Display');
$renamed->exec("UPDATE displays SET tag = 'front-window'");
checkSame(true, seedLegacyDisplay($renamed, $err), 'and a pass after an admin renamed it does nothing');
checkSame(1, intval($renamed->query("SELECT COUNT(*) FROM displays")->fetchColumn()),
          'so the store still has one Display, under the name they gave it');
checkSame('front-window', $renamed->query("SELECT tag FROM displays")->fetchColumn(),
          'which is still their name and not this file\'s');

// The background the pre-multi-display layout was drawn on lives in canvas_settings,
// and this is the one and only place left that reads it. Losing it here means every
// Screen coming back from a deploy on the default navy instead of the store's own
// wallpaper — visible from the car park, and not obviously a deploy's fault.
$bgPdo = newTestDb();
createLegacyCanvasSettings($bgPdo);
$bgPdo->exec("INSERT INTO canvas_settings (bg_type,bg_val) VALUES ('image','uploads/wall.jpg')");
$bgPdo->exec("INSERT INTO canvas_settings (bg_type,bg_val) VALUES ('color','#ff0000')");
checkSame(true, seedLegacyDisplay($bgPdo), 'the seed runs against a database that has one');
$bgRow = $bgPdo->query("SELECT bg_type, bg_val FROM displays")->fetch();
checkSame('image', $bgRow['bg_type'], 'and the Display inherits what the canvas was set to');
checkSame('uploads/wall.jpg', $bgRow['bg_val'], 'including which image');

// A fresh install has no canvas_settings at all, which is not a failure — it is the
// defaults, and they are the ones that row used to hold.
$freshPdo = newTestDb();
$err = 'untouched';
checkSame(true, seedLegacyDisplay($freshPdo, $err), 'no canvas_settings is not a problem');
checkSame('', $err, 'and nothing is reported about it');
$freshRow = $freshPdo->query("SELECT bg_type, bg_val FROM displays")->fetch();
checkSame('color', $freshRow['bg_type'], 'the Display gets the default background type');
checkSame('#1a1a2e', $freshRow['bg_val'], 'and the colour that row always defaulted to');

// A stored value that is there but empty is not a background either.
$blankBg = newTestDb();
createLegacyCanvasSettings($blankBg);
$blankBg->exec("INSERT INTO canvas_settings (bg_type,bg_val) VALUES ('color','')");
checkSame(true, seedLegacyDisplay($blankBg), 'a canvas_settings row with no colour in it seeds');
checkSame('#1a1a2e', $blankBg->query("SELECT bg_val FROM displays")->fetchColumn(),
          'and falls back rather than storing an empty background');

// ---- The deploy-day race, produced rather than reasoned about ------------------
// Six Screens and an admin can hit the first request after a deploy together. Two of
// them count zero Displays, both insert, and one loses on the UNIQUE tag. That loser
// must report *success* — the Display exists, which is all the step was for — because
// a failure here is an email to an admin about two people signing in at once, sent by
// the alert that exists to be believed.
//
// A BEFORE INSERT trigger using RAISE(FAIL) is the interleaving: FAIL aborts the
// statement without undoing what the trigger program already did, so the row the
// "other request" wrote survives and this one's insert throws. That is the state the
// catch block is written for, and nothing else in one process can produce it.
//
// SQLite-only, and not because the dialect is inconvenient — MySQL cannot express
// this state at all. A trigger there may not write to the table it is defined on
// (1442), which is the half that has to survive the failure, and its second
// connection cannot be made to interleave *inside* a statement. What is under test
// is a catch block in PHP, identical on both engines; what only one engine can build
// is the interleaving that reaches it (§4bl).
$racePdo = newSqliteTestDb();
$racePdo->exec("CREATE TRIGGER seed_race BEFORE INSERT ON displays BEGIN
    INSERT INTO displays (tag,title,canvas_width,canvas_height,brand_id)
        VALUES ('" . LEGACY_DISPLAY_TAG . "','Drive-Thru',1920,1080,1);
    SELECT RAISE(FAIL, 'UNIQUE constraint failed: displays.tag');
END");
$err = 'untouched';
checkSame(true, seedLegacyDisplay($racePdo, $err),
          'losing the race to create the drive-thru Display is not a failure');
checkSame('', $err, 'so nothing is reported about it');
checkSame(1, intval($racePdo->query("SELECT COUNT(*) FROM displays")->fetchColumn()),
          'and the Display the other request made is the one that is there');

// ─────────────────────────────────────────────────────────────
section('A repair nobody asked for is guarded three ways');

// ensureSignageSchema() is called deliberately, at the top of a page, where nothing
// is open. DisplayStore's recovery is the other door: a query already failed with
// "no such table", and it converges from wherever that happened — including the
// public poll, which is the whole reason it exists. Everything below is a rule that
// door needs and did not have, and none of it had ever been executed: the trigger
// was SQLSTATE 42S02, which MySQL raises and SQLite does not, so the fixture could
// not reach the path at all.

// ---- Is this the error a repair could fix? ------------------------------------
checkSame(true, schemaErrorSaysTableMissing('42S02',
    "Base table or view not found: 1146 Table 'lbm.displays' doesn't exist"),
    'MySQL\'s missing-table SQLSTATE is recognised');
checkSame(true, schemaErrorSaysTableMissing('HY000', 'no such table: displays'),
    'and SQLite\'s, which says so only in the message');
checkSame(true, schemaErrorSaysTableMissing('HY000', 'NO SUCH TABLE: displays'),
    'whatever case it uses');
checkSame(false, schemaErrorSaysTableMissing('42S22', "Unknown column 'display_id' in 'field list'"),
    'a missing column is not a missing table');
checkSame(false, schemaErrorSaysTableMissing('42000', "Unknown database 'lbm'"),
    'nor is a missing database — convergence cannot make one');
checkSame(false, schemaErrorSaysTableMissing('HY000', 'database is locked'),
    'nor a lock, which retrying DDL would only make worse');

// The one that matters: a real exception from the fixture, put to the real question.
$probe = newTestDb();
$caught = null;
try { $probe->query("SELECT * FROM a_table_that_is_not_there"); }
catch (PDOException $e) { $caught = $e; }
check($caught !== null, 'the fixture can produce a missing-table failure');
checkSame(true, schemaErrorSaysTableMissing($caught->getCode(), $caught->getMessage()),
          'and the detector recognises the real thing, not just the strings above');

// ---- 1. Never while a transaction is open -------------------------------------
// DDL commits the surrounding transaction in MySQL and says nothing about it.
// LayoutStore::publish() deletes a Display's whole layout and re-inserts it inside
// one, and its last two calls read the `displays` row through the LEFT JOIN on
// `users` that can raise exactly this error. A repair fired from there would commit
// the publish, rethrow, and then report "Publish failed. Nothing was saved."
//
// Nowhere to write, so the retry window and the lock are both out of the way and
// the only thing under test is the transaction.
ErrorPolicy::useLogFile('');

$txPdo = newTestDb();
$txPdo->exec("DELETE FROM canvas_elements");
$txPdo->exec("DELETE FROM displays");
$txPdo->beginTransaction();
SchemaLatch::forget();
$why = 'untouched';
checkSame(false, repairSchemaAfterFailure($txPdo, $why), 'a repair inside a transaction is refused');
checkMentions($why, 'transaction', 'and says that is why');
checkSame(0, legacyDisplayId($txPdo), 'and nothing in the plan ran');
$txPdo->rollBack();

// Outside one, the same call on the same database does the work.
SchemaLatch::forget();
$why = 'untouched';
checkSame(true, repairSchemaAfterFailure($txPdo, $why), 'outside a transaction it goes ahead');
checkSame('', $why, 'with no reason to give');
check(legacyDisplayId($txPdo) > 0, 'and the drive-thru Display is back');

// ---- 2. Not twice on one request ----------------------------------------------
// An authenticated page always converges at the top, so a failure later in that
// request has nothing left to gain — and saying so leaves the retry window unspent
// for the Screens, which are the callers that actually need it.
$why = 'untouched';
checkSame(false, repairSchemaAfterFailure($txPdo, $why),
          'a second repair on the same request is refused');
checkMentions($why, 'already ran', 'and names the convergence that already happened');
SchemaLatch::forget();

// ---- 3. Not again for five minutes --------------------------------------------
// A repair that cannot succeed is otherwise retried every 30 seconds by every
// Screen, forever, on the one query that must never be slow.
$repairDir = newTestStateDir();
ErrorPolicy::useLogFile($repairDir . '/lbm-error.log');

$throttled = newTestDb();
$throttled->exec("DELETE FROM canvas_elements");
$throttled->exec("DELETE FROM displays");
SchemaLatch::forget();
checkSame(true, repairSchemaAfterFailure($throttled), 'the first repair runs');
check(legacyDisplayId($throttled) > 0, 'and fixes what it could');

$throttled->exec("DELETE FROM canvas_elements");
$throttled->exec("DELETE FROM displays");
SchemaLatch::forget();
$why = 'untouched';
checkSame(false, repairSchemaAfterFailure($throttled, $why), 'the next one inside the window is not');
checkMentions($why, 'seconds ago', 'and says how recently the last one was');
checkSame(0, legacyDisplayId($throttled), 'and it really did not run');
checkSame(300, SCHEMA_REPAIR_RETRY_SECONDS, 'the window is five minutes');

// ---- One repair at a time, installation-wide ----------------------------------
// Six Screens fail on the same 30-second tick. Unguarded, all six read the
// catalogue, all six see the same column missing, and five lose the ALTER — which
// fails with "duplicate column name" on a need the catalogue said was true, which is
// the one shape that emails an admin. The alert would have announced its own success
// as a failure, six times, on deploy day.
ErrorPolicy::useLogFile('');
checkSame('ran', withSchemaRepairLock(function () { return 'ran'; }),
          'with nowhere to write a lock, the work still runs');

$lockDir = newTestStateDir();
ErrorPolicy::useLogFile($lockDir . '/lbm-error.log');
$inner = 'not reached';
checkSame(false, withSchemaRepairLock(function () use (&$inner) {
    // A second holder, which is what a second request is. flock() is held per open
    // file, so two handles in one process contend exactly as two processes do.
    $inner = withSchemaRepairLock(function () { return 'ran twice'; });
    return $inner;
}), 'a second repair while one is running does nothing');
checkSame(false, $inner, 'the inner one is the one that is turned away');
check(file_exists($lockDir . '/' . SCHEMA_REPAIR_LOCK), 'and the lock is a file an admin can find');

// The lock is released when the work throws, not only when it returns — because it
// belongs to the open file rather than to a stamp somebody has to remember to
// delete. A repair interrupted by a timeout must not leave the database unfixable.
$threw = false;
try { withSchemaRepairLock(function () { throw new RuntimeException('interrupted'); }); }
catch (RuntimeException $e) { $threw = true; }
checkSame(true, $threw, 'a repair that throws still throws');
checkSame('after', withSchemaRepairLock(function () { return 'after'; }),
          'and the next one can still take the lock');

// Through the entry point: a held lock means convergence does not run at all.
$heldPdo = newTestDb();
$heldPdo->exec("DELETE FROM canvas_elements");
$heldPdo->exec("DELETE FROM displays");
$heldHandle = fopen($lockDir . '/' . SCHEMA_REPAIR_LOCK, 'c');
flock($heldHandle, LOCK_EX | LOCK_NB);
SchemaLatch::forget();
ensureSignageSchema($heldPdo);
checkSame(0, legacyDisplayId($heldPdo), 'the entry point skips convergence while another holds the lock');
flock($heldHandle, LOCK_UN);
fclose($heldHandle);
SchemaLatch::forget();
ensureSignageSchema($heldPdo);
check(legacyDisplayId($heldPdo) > 0, 'and converges once the lock is free');

// ---- The recovery itself, through DisplayStore --------------------------------
// The path that had never been executed. `displays` is genuinely gone, a read fails,
// and the store decides whether to try fixing it. Whether it *ran* is observable
// through the retry window: an attempt spends it, a refusal leaves it.
$healDir = newTestStateDir();
ErrorPolicy::useLogFile($healDir . '/lbm-error.log');
$healPdo = newTestDb();
$healPdo->exec("DROP TABLE display_permissions");
$healPdo->exec("DROP TABLE canvas_elements");
$healPdo->exec("DROP TABLE displays");
$healStore = new DisplayStore($healPdo);
SchemaLatch::forget();
$healThrew = false;
$healed    = null;
try { $healed = $healStore->forTag('drive-thru'); } catch (PDOException $e) { $healThrew = true; }

// The one place in this file where the two engines must be asserted about
// differently, because on one of them the repair can only be *attempted* and on the
// other it genuinely completes.
//
// On SQLite the statements are MySQL dialect, so the CREATE fails, the table is
// still missing when the read is retried, and the exception comes back out. That
// still proves the useful half — the store tried, and a repair that cannot work
// does not swallow the error.
//
// On MySQL the whole sequence runs for the first time: `displays` and
// `display_permissions` are recreated, the drive-thru Display is seeded, and the
// read that triggered all of it returns. Until #48 this path had never been
// executed end to end by anything except tools/rehearse_phase1.php against a copy
// of live data. `canvas_elements` stays missing on purpose — convergence only ever
// alters that table, it does not create it.
if (testIsMysql()) {
    check(!$healThrew && $healed !== null && $healed->tag() === 'drive-thru',
          'the repair recreates the table and the read that triggered it completes');
} else {
    checkSame(true, $healThrew, 'a table this fixture cannot recreate still ends in the error');
}
checkSame(false, ErrorPolicy::firstInWindow('schema-repair', SCHEMA_REPAIR_RETRY_SECONDS),
          'but the store did attempt the repair — the window has been spent');

// The same store again: once per request means the second failure does not re-enter
// the repair, whatever the window says.
$sameAgain = newTestStateDir();
ErrorPolicy::useLogFile($sameAgain . '/lbm-error.log');
SchemaLatch::forget();
try { $healStore->forTag('lobby'); } catch (PDOException $e) { }
checkSame(true, ErrorPolicy::firstInWindow('schema-repair', SCHEMA_REPAIR_RETRY_SECONDS),
          'a second failure on the same store does not attempt it again');

// And the whole point of #12, at the level it would actually happen: the same
// missing table, the same store, inside a transaction. No repair is attempted, so no
// DDL runs, so nothing commits a half-finished publish.
$txHealDir = newTestStateDir();
ErrorPolicy::useLogFile($txHealDir . '/lbm-error.log');
$txHeal = newTestDb();
$txHeal->exec("DROP TABLE display_permissions");
$txHeal->exec("DROP TABLE canvas_elements");
$txHeal->exec("DROP TABLE displays");
$txHealStore = new DisplayStore($txHeal);
$txHeal->beginTransaction();
SchemaLatch::forget();
$txHealThrew = false;
try { $txHealStore->forTag('drive-thru'); } catch (PDOException $e) { $txHealThrew = true; }
checkSame(true, $txHealThrew, 'inside a transaction the failure is reported, not repaired');
checkSame(true, ErrorPolicy::firstInWindow('schema-repair', SCHEMA_REPAIR_RETRY_SECONDS),
          'and no repair was attempted at all');
checkSame(true, $txHeal->inTransaction(), 'the transaction is still the caller\'s to roll back');
$txHeal->rollBack();

// A read that works is not this path's business, and neither is a failure of any
// other kind — the tables are all there, so nothing may reach the repair.
$otherDir = newTestStateDir();
ErrorPolicy::useLogFile($otherDir . '/lbm-error.log');
$otherPdo   = newTestDb();
$otherStore = new DisplayStore($otherPdo);
makeTestDisplay($otherPdo, 'lobby', 'Lobby');
SchemaLatch::forget();
check($otherStore->forTag('lobby') !== null, 'a healthy read still returns its Display');
checkSame(null, $otherStore->forTag('never-created'), 'and a Display that is not there is null, not an error');
checkSame(true, ErrorPolicy::firstInWindow('schema-repair', SCHEMA_REPAIR_RETRY_SECONDS),
          'neither of which goes near the repair path');

// Put the policy back where the rest of the suite expects it.
ErrorPolicy::useLogFile('');
SchemaLatch::forget();

// ─────────────────────────────────────────────────────────────
section('Who counts as an admin worth alerting');

$mailPdo = newTestDb();
$mStore  = new AccountStore($mailPdo);
checkSame(['sky@example.test'], $mStore->adminEmails(), 'a basic account is not alerted');

makeTestAccount($mailPdo, 'second', 'admin');
checkSame(2, count($mStore->adminEmails()), 'a second admin is');

$mailPdo->exec("UPDATE users SET is_active = 0 WHERE username = 'second'");
checkSame(1, count($mStore->adminEmails()), 'a deactivated admin is not');

$mailPdo->exec("UPDATE users SET is_active = 1, closed_at = '2026-01-01 00:00:00' WHERE username = 'second'");
checkSame(1, count($mStore->adminEmails()), 'nor is a closed one, whatever is_active says');

$mailPdo->exec("UPDATE users SET email = '' WHERE username = 'sky'");
checkSame([], $mStore->adminEmails(), 'and an admin with no address on file is left out');

// ─────────────────────────────────────────────────────────────
section('How big a file can actually get here');

// api.php refused anything over 50 MB and named that number. On shared hosting it
// is rarely the binding one, and the one that binds most often — post_max_size —
// is not an error PHP reports: it abandons the request body and carries on, so the
// script sees a POST with no fields at all. In api.php that meant a missing CSRF
// token, and a 40 MB video was answered "Security token mismatch. Please reload
// the page and try again." Reloading changes nothing.

checkSame(8388608, UploadLimit::toBytes('8M'),   'an ini size in megabytes is understood');
checkSame(524288,  UploadLimit::toBytes('512K'), 'and in kilobytes');
checkSame(2147483648, UploadLimit::toBytes('2G'), 'and in gigabytes');
checkSame(1048576, UploadLimit::toBytes('1048576'), 'and as a plain byte count');
checkSame(8388608, UploadLimit::toBytes(' 8M '),  'with whitespace around it');
checkSame(8388608, UploadLimit::toBytes('8MB'),   'and with the B some hosts write');
checkSame(8388608, UploadLimit::toBytes('8m'),    'in either case');

// 0 means "no limit stated here", and it must drop out of the comparison rather
// than become the answer — a limit of zero bytes refuses every upload there is.
checkSame(0, UploadLimit::toBytes('0'),        'zero is no stated limit');
checkSame(0, UploadLimit::toBytes(''),         'so is nothing at all');
checkSame(0, UploadLimit::toBytes('lots'),     'and so is something unparseable');
checkSame(0, UploadLimit::toBytes('-1'),       'and a negative');

// The effective limit is never unbounded and never zero: the app's own ceiling is
// always one of the candidates.
UploadLimit::forget();
check(UploadLimit::bytes() > 0, 'the effective limit is a real number');
check(UploadLimit::bytes() <= UploadLimit::APP_MAX_BYTES,
      'and never more than the app allows, whatever the host says');

// The comparison itself, over values handed in — those two ini settings cannot be
// changed at runtime, so this is the only way to reach the cases that matter.
checkSame(8388608, UploadLimit::smallestOf(['64M', '8M']),
          'the smallest of the host\'s two ceilings is the one that binds');
checkSame(UploadLimit::APP_MAX_BYTES, UploadLimit::smallestOf(['0', '0']),
          'a host that states no limit leaves the app\'s own ceiling standing');
checkSame(UploadLimit::APP_MAX_BYTES, UploadLimit::smallestOf(['nonsense', '']),
          'and so does one whose settings cannot be read — never a limit of zero bytes');
checkSame(UploadLimit::APP_MAX_BYTES, UploadLimit::smallestOf([]),
          'with nothing to compare at all, the app ceiling is the answer');
checkSame(2097152, UploadLimit::smallestOf(['2M', '0']),
          'one stated limit and one absent still binds on the stated one');
check(UploadLimit::smallestOf(['500M', '500M']) === UploadLimit::APP_MAX_BYTES,
      'a generous host does not raise the app above its own 50 MB');

checkSame('8 MB',    UploadLimit::describeBytes(8388608),  'a size reads as megabytes');
// 20.9 MB. Rounded down on purpose: a limit printed as "21 MB" that refuses a
// 20.9 MB file sends somebody to trim their file to a number that fails again.
checkSame('20 MB',   UploadLimit::describeBytes(21915238), 'rounded down, so the number quoted is always achievable');
checkSame('512 KB',  UploadLimit::describeBytes(524288),   'a smaller one as kilobytes');
checkSame('900 bytes', UploadLimit::describeBytes(900),    'and a tiny one in bytes');

// Both boundaries, at the value itself and one below (#50). These read as pedantry and
// are not: the number this function returns is the number in the sentence somebody is
// told to trim their file to, so a unit that changes one byte early prints "1024 KB" and
// one that changes one byte late prints "1048575 bytes". Nothing pinned either edge, so
// both comparisons could be relaxed and both constants moved with the suite green.
checkSame('1 MB',      UploadLimit::describeBytes(1048576), 'exactly a megabyte is one megabyte');
checkSame('1023 KB',   UploadLimit::describeBytes(1048575), 'and one byte under it is still kilobytes');
checkSame('1 KB',      UploadLimit::describeBytes(1024),    'exactly a kilobyte is one kilobyte');
checkSame('1023 bytes', UploadLimit::describeBytes(1023),   'and one byte under it is bytes');
checkSame('0 bytes',   UploadLimit::describeBytes(null),    'and a column with nothing in it is 0 bytes, not " bytes"');

// The ceiling is the app's own promise, quoted verbatim in the refusal a person reads,
// so the number is asserted rather than only compared against itself.
checkSame(52428800, UploadLimit::APP_MAX_BYTES, 'the app ceiling is 50 MB, to the byte');
checkSame('50 MB', UploadLimit::describeBytes(UploadLimit::APP_MAX_BYTES),
          'and says so in the words the refusal uses');

// The silent case, detected from the only symptom it has.
checkSame(true, UploadLimit::bodyWasDropped(
    ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '41943040'], [], []),
    'a POST that announced a body and arrived with none was dropped for its size');
checkSame(false, UploadLimit::bodyWasDropped(
    ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '0'], [], []),
    'a genuinely empty POST is not confused with it');
checkSame(false, UploadLimit::bodyWasDropped(
    ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '120'], ['csrf_token' => 'x'], []),
    'nor is one whose fields arrived');
checkSame(false, UploadLimit::bodyWasDropped(
    ['REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '9000000'], [], ['file' => []]),
    'nor one whose file arrived');
checkSame(false, UploadLimit::bodyWasDropped(
    ['REQUEST_METHOD' => 'GET', 'CONTENT_LENGTH' => '41943040'], [], []),
    'and a GET is never this');
checkSame(false, UploadLimit::bodyWasDropped([], [], []),
    'a request with no method at all is not either');
// A POST with nothing in it and no content length announced. The absence has to read as
// zero rather than as something, or every empty POST on a host that omits the header is
// answered "that file was too large" — which is the old defect with the sentences swapped.
checkSame(false, UploadLimit::bodyWasDropped(['REQUEST_METHOD' => 'POST'], [], []),
    'and neither is a POST that announced no length at all');

checkMentions(UploadLimit::droppedBodyMessage(), 'too large',
              'and what the user is told names the problem');
checkMentions(UploadLimit::droppedBodyMessage(), 'Nothing was changed',
              'and says nothing was changed');
check(strpos(UploadLimit::droppedBodyMessage(), 'token') === false,
      'and never mentions a security token, which was the old answer');

// ── The two ceilings that used to be written at the sink ──
// `10 * 1024 * 1024` sat in crud.php with the words "max 10 MB" beside it, and
// `2 * 1024 * 1024` in admin_panel.php. Both were opinions about a limit neither page
// could see: on a host whose post_max_size is 8M the Library still promised 10 MB, and
// the request carrying an 9 MB image never arrived to be measured at all. cappedAt() is
// the `min` that makes a stated limit one that can be kept.
checkSame(10485760, UploadLimit::IMAGE_MAX_BYTES, 'an Asset Library image may be 10 MB');
checkSame(2097152,  UploadLimit::LOGO_MAX_BYTES,  'and the brand logo 2 MB');
checkSame('10 MB', UploadLimit::describeBytes(UploadLimit::IMAGE_MAX_BYTES),
          'in the words the form beside the picker prints');
checkSame('2 MB', UploadLimit::describeBytes(UploadLimit::LOGO_MAX_BYTES),
          'and the words beside the logo picker');

// The direction of the cap, which is the whole of it: a sink may ask for less than the
// transport allows and never for more. Reversing the min, or returning the constant
// unchanged, passes every check above and reintroduces exactly the promise that could
// not be kept.
UploadLimit::forget();
check(UploadLimit::imageBytes() <= UploadLimit::bytes(),
      'no library image is allowed to be larger than what can reach the server');
check(UploadLimit::imageBytes() <= UploadLimit::IMAGE_MAX_BYTES,
      'nor larger than the library asked for');
check(UploadLimit::logoBytes() <= UploadLimit::bytes(),
      'and no logo larger than what can reach the server');
check(UploadLimit::logoBytes() <= UploadLimit::LOGO_MAX_BYTES,
      'nor larger than the logo rule asked for');
check(UploadLimit::logoBytes() <= UploadLimit::imageBytes(),
      'and a logo is never allowed to be bigger than a library image');
checkSame(UploadLimit::describeBytes(UploadLimit::imageBytes()), UploadLimit::describeImage(),
          'the words a form prints are the number it will be refused by');
checkSame(UploadLimit::describeBytes(UploadLimit::logoBytes()), UploadLimit::describeLogo(),
          'and the same holds for the logo');

// A ceiling under the transport limit is honoured, and one over it is not. Passed in
// rather than read from ini, because these are PHP_INI_PERDIR and cannot be set here —
// the same reason smallestOf() exists as a seam beside bytes().
checkSame(512, UploadLimit::cappedAt(512), 'a small ceiling is used as it stands');
check(UploadLimit::cappedAt(PHP_INT_MAX) === UploadLimit::bytes(),
      'and one larger than the server allows is cut down to what the server allows');

// That both sinks go *through* cappedAt(), and this one is read from the source rather
// than measured — deliberately, and it is the weaker grade of check.
//
// No comparison of values can hold it. `logoBytes()` rewritten to `return
// self::LOGO_MAX_BYTES;` is a real defect and it is *invisible* to every value check on
// any host whose limit is already above 2 MB, because there the cap changes nothing and
// the two expressions are equal. That was tried: a value check comparing
// `cappedAt(LOGO_MAX_BYTES)` against `logoBytes()` passes with the cap deleted, being
// the same coincidence in different clothing. `upload_max_filesize` and `post_max_size`
// are PHP_INI_PERDIR, so the suite cannot lower the host's limit to make the cap bite —
// which is why cappedAt() exists as a seam and is pinned by the three checks above,
// and why the delegation to it is held by reading the file. Same shape as §4as's
// loadLayout hook, and written down for the same reason.
$limitSrc = file_get_contents(__DIR__ . '/../lib/upload_limits.php');
checkMentions($limitSrc, 'imageBytes()    { return self::cappedAt(self::IMAGE_MAX_BYTES); }',
              'the library ceiling is that cap applied to the library rule');
checkMentions($limitSrc, 'logoBytes()    { return self::cappedAt(self::LOGO_MAX_BYTES); }',
              'and the logo ceiling that cap applied to the logo rule');

// ─────────────────────────────────────────────────────────────
section('The branding file is swapped in, never written in place');

// Decision #36. `branding_config.php` is generated PHP that every page in the app
// requires, so the interesting property is not "the save worked" — it is that the
// live path only ever holds a complete, loadable file, whatever happens to the
// write. Everything below runs in a throwaway directory: a self-test that rewrote
// the deployment's own branding would be changing the thing it is measuring.
$brandDir = newTestStateDir();
$brandCfg = new BrandingConfig($brandDir);

// Work from inside that directory for the length of this section. Not tidiness:
// this is the one module whose subject is a *path*, so the mutation that makes
// `path()` answer with a bare filename sends every save below into the deployment's
// own branding_config.php — which is how a mutation run rewrote the repo's copy
// once. A relative answer now lands in the throwaway directory with everything else.
$brandCwd = getcwd();
chdir($brandDir);

checkSame($brandDir . '/branding_config.php', $brandCfg->path(),
          'the module owns the filename, so no page has to spell it');
checkSame(10, count(BrandingConfig::DEFAULTS), 'ten settings live in the file');
checkSame(array_keys(BrandingConfig::DEFAULTS), array_keys($brandCfg->current()),
          'and current() answers about all ten');
check(array_key_exists('UNDO_STEPS', BrandingConfig::DEFAULTS),
      'the Builder\'s undo depth is one of them, not a define() of its own');
check(array_key_exists('STORE_TIMEZONE', BrandingConfig::DEFAULTS),
      'and so is the store time zone, for the same reason — a second writer of this file\n      would drop it on the next Branding save (#44)');

// ── What a stored undo depth means, once ─────────────────────
// The value in the file is a string somebody may have typed, and the Builder acts on
// it: it decides whether there is an Undo button, whether Ctrl+Z does anything, and
// how many snapshots a tab holds. Two readers of it — the settings form and
// builder.php — so it is one function, and the function takes the stored value as an
// argument rather than reading the constant, or none of the shapes below could be
// asked at all in a process that has already defined it (§4o).
checkSame(5,  undoStepsSetting('5'),   'the default reads as five steps');
checkSame(0,  undoStepsSetting('0'),   'zero reads as zero — off is a real answer, not a missing one');
checkSame(20, undoStepsSetting('20'),  'the ceiling itself is allowed');
checkSame(UNDO_STEPS_MAX, undoStepsSetting('500'),
          'and a hand-edit above it is clamped, not honoured — the cost of the stack is a tab\'s memory');
checkSame(0,  undoStepsSetting('-1'),  'a negative depth is off rather than an error');
checkSame(0,  undoStepsSetting('five'),
          'and so is a word, which is where a hand-edit to nonsense should land: no Undo behaves exactly as before');
checkSame(0,  undoStepsSetting(''),    'an empty value is off');
checkSame(3,  undoStepsSetting('3.7'), 'a fractional one is truncated, not rounded up past what was meant');
checkSame(intval(BrandingConfig::DEFAULTS['UNDO_STEPS']),
          undoStepsSetting(BrandingConfig::DEFAULTS['UNDO_STEPS']),
          'and the shipped default survives its own reader, which is what stops the two disagreeing');
// The constants are defined in this process by config.php, which is the state a
// real save happens in: what is in force, not what the defaults say.
checkSame(SITE_NAME, $brandCfg->current()['SITE_NAME'],
          'current() reports the value actually in force, not the fallback');

$res = $brandCfg->save(['SITE_NAME' => 'Lummi Bay Market']);
checkSame(BrandingWrite::OK, $res->kind(), 'a save of one setting succeeds');
check(is_file($brandCfg->path()), 'and the file is there afterwards');

$written = file_get_contents($brandCfg->path());
check(BrandingConfig::parses($written), 'what the swap put in place is loadable PHP');
checkMentions($written, "define('SITE_NAME'", 'and defines the name that was saved');
checkMentions($written, var_export('Lummi Bay Market', true), 'with the value asked for');
// The other seven were not in the save and must be exactly what they were. This is
// the defect the old eight-argument call had: the Site & Email form passed the five
// branding values back in from page variables, so it rewrote them every time.
// Pinned, because in this process all eight constants sit at their defaults and a
// save that kept them and a save that reset them write the same bytes.
$pinned = BrandingConfig::DEFAULTS;
$pinned['BRAND_ACCENT'] = '#abcdef';
$pinned['SITE_NAME']    = 'Before';
$pinnedCfg = new PinnedBrandingConfig($brandDir, $pinned);
checkSame(BrandingWrite::OK, $pinnedCfg->save(['SITE_NAME' => 'After'])->kind(),
          'a save over settings that are not the defaults succeeds');
$merged = file_get_contents($pinnedCfg->path());
checkMentions($merged, var_export('After', true), 'the setting asked for is changed');
checkMentions($merged, var_export('#abcdef', true),
              'and a save of one setting leaves the seven it never mentioned alone');
checkSame(BrandingConfig::render(array_merge($pinned, ['SITE_NAME' => 'After'])), $merged,
          'the file is exactly what is in force with the change applied on top');

// The check above only means anything if a truncated file would have failed it.
// This is what an interrupted in-place write leaves behind, and it is why the old
// code could take every page of the app down with one save.
$cutHere   = strrpos($written, 'define(');
$truncated = substr($written, 0, $cutHere + 20);
check(!BrandingConfig::parses($truncated),
      'and a file cut short mid-statement is not — which is the whole defect');

// Anti-injection. A site name is free text an admin types; it reaches a file the
// app executes. var_export is the entire defence, so count the calls: an escape
// that let a value close its own string would show up as an eleventh.
$evil = "'); echo 'pwned'; define('X', '";
checkSame(BrandingWrite::OK, $brandCfg->save(['SITE_NAME' => $evil])->kind(),
          'a site name full of quotes and semicolons still saves');
$written = file_get_contents($brandCfg->path());
check(BrandingConfig::parses($written), 'and the file it wrote still parses');
$defineCalls = 0;
foreach (token_get_all($written) as $token) {
    if (is_array($token) && $token[0] === T_STRING && $token[1] === 'define') { $defineCalls++; }
}
checkSame(10, $defineCalls, 'with exactly ten define() calls — nothing was injected');
checkMentions($written, var_export($evil, true), 'the value is stored as one escaped literal');

// A backslash is the other half of it: var_export doubles it, and a naive escape
// that handled quotes but not backslashes would end the literal one character early.
checkSame(BrandingWrite::OK, $brandCfg->save(['SITE_NAME' => 'Bob\'s \\ Market'])->kind(),
          'a backslash saves too');
check(BrandingConfig::parses(file_get_contents($brandCfg->path())),
      'and does not end the string early');

// A name nothing reads would be written into a file every page loads and would
// never have any effect, and the admin would be told it saved.
$before = file_get_contents($brandCfg->path());
$res    = $brandCfg->save(['NOT_A_SETTING' => 'x']);
checkSame(BrandingWrite::REFUSED, $res->kind(), 'a setting the file does not hold is refused');
checkMentions($res->message(), 'NOT_A_SETTING', 'and the refusal names it');
checkMentions($res->message(), 'Nothing was changed', 'and says nothing was changed');
checkSame($before, file_get_contents($brandCfg->path()), 'which is true — the file is byte-identical');

// ── The reason this module exists ────────────────────────────
// A write that comes up short. The old code truncated the live file first, so this
// left every page of the app requiring half a define() — a parse error, on the sign
// as well as in the office. Nothing here may touch the live path.
$shortCfg = new ShortWriteBrandingConfig($brandDir);
$before   = file_get_contents($brandCfg->path());
$res      = $shortCfg->save(['SITE_NAME' => 'Half A Name']);
checkSame(BrandingWrite::FAILED, $res->kind(), 'a short write fails the save');
checkMentions($res->message(), 'Nothing was changed', 'and says nothing was changed');
checkMentions($res->message(), 'still using the settings it had', 'and that the site is still standing');
checkMentions($res->message(), 'disk may be full', 'and names the cause an admin can act on');
checkSame($before, file_get_contents($brandCfg->path()),
          'and the live file is byte-for-byte what it was');
check(BrandingConfig::parses(file_get_contents($brandCfg->path())),
      'so every page that requires it still loads');
check(strpos(file_get_contents($brandCfg->path()), 'Half A Name') === false,
      'and none of the abandoned save is in it');
checkSame([], glob($brandDir . '/.[!.]*'),
          'the half-written temporary file was cleaned up, not left in the webroot');

// Where that temporary file was, which is only visible from inside the write.
checkSame($brandDir, dirname($shortCfg->lastTemp),
          'the replacement is built beside the file it replaces — rename() is only atomic within one filesystem');
check(strpos(basename($shortCfg->lastTemp), '.php') === false,
      'and is never named *.php: AddHandler matches that extension anywhere in a filename');
check(strpos(basename($shortCfg->lastTemp), '.branding_config.') === 0,
      'it is named for what it is about to become');
checkMentions(file_get_contents(__DIR__ . '/../.htaccess'), '^\.branding_config\.',
              'and the webroot denies that name for the moment it exists');

// The swap must not be a way for this file to quietly change who can read it.
chmod($brandCfg->path(), 0640);
clearstatcache(true, $brandCfg->path());
checkSame(BrandingWrite::OK, $brandCfg->save(['SITE_NAME' => 'Permissions Test'])->kind(),
          'a save over a file with its own permissions succeeds');
clearstatcache(true, $brandCfg->path());
checkSame(0640, fileperms($brandCfg->path()) & 0777,
          'and the replacement inherits them rather than the umask');

// A folder that cannot be written is the other end of the same promise: fail, say
// so, create nothing — and say something different, because "the disk is full" and
// "this folder is not yours to write" are not the same errand.
$res = (new BrandingConfig($brandDir . '/nowhere'))->save(['SITE_NAME' => 'x']);
checkSame(BrandingWrite::FAILED, $res->kind(), 'a folder that cannot be written fails the save');
checkMentions($res->message(), 'Nothing was changed', 'and says nothing was changed');
checkMentions($res->message(), 'permissions', 'and sends the admin somewhere different from a full disk');
check(strpos($res->message(), 'disk may be full') === false, 'not to both places at once');
check(!is_dir($brandDir . '/nowhere'), 'and nothing was created on the way');

// render() is pure, so the bytes are checkable without a disk at all — including
// the one property two saves of the same values must have.
$sample = BrandingConfig::render(BrandingConfig::DEFAULTS);
checkSame($sample, BrandingConfig::render(BrandingConfig::DEFAULTS),
          'the same values render to the same bytes every time');
check(strpos($sample, '<?php') === 0, 'the generated file opens as PHP');
check(substr($sample, -1) === "\n", 'and ends with a newline');
check(strpos($sample, '?>') === false, 'with no closing tag to leak whitespace before a header');
check(BrandingConfig::parses($sample), 'and the defaults render to something loadable');
checkSame(BrandingConfig::render(BrandingConfig::DEFAULTS),
          BrandingConfig::render(['SITE_NAME' => BrandingConfig::DEFAULTS['SITE_NAME']]),
          'a value render() is not given falls back to the same default the app uses');

// And the panel writes none of it itself any more. The whole point of the module is
// that there is one writer; a file_put_contents back in the page is that undone.
$panelSource = file_get_contents(__DIR__ . '/../admin_panel.php');
check(strpos($panelSource, 'file_put_contents') === false,
      'the Admin Panel writes no file of its own');
check(strpos($panelSource, "define('BRAND_") === false,
      'and generates none of the file it used to build by hand');

// ── The read side: one list of nine names, not five ──────────
// `config.php`, `login.php`, `builder.php` and `help.php` each used to spell out the
// same defaults, and two of them guarded the require on a different constant from
// the other two. A page carrying its own copy of a default is a page that can
// disagree with the Admin Panel about what colour the nav bar is.
// Not a search for the colour itself — `#1a252f` is a perfectly ordinary dark shade
// and several stylesheets use it for something that is not the nav bar. What must
// not come back is a page *declaring* one of these names, or reaching for the
// generated file on its own account.
$pagesWithTheirOwn = [];
$pagesLoadingItThemselves = [];
foreach ((array)glob(__DIR__ . '/../*.php') as $page) {
    // The generated file declares all eight; that is what it is.
    if (basename($page) === 'branding_config.php') { continue; }
    $src = file_get_contents($page);
    if (strpos($src, "define('BRAND_") !== false)      { $pagesWithTheirOwn[] = basename($page); }
    if (strpos($src, "/branding_config.php'") !== false) { $pagesLoadingItThemselves[] = basename($page); }
}
checkSame([], $pagesWithTheirOwn, 'no page declares a branding constant of its own');
checkSame([], $pagesLoadingItThemselves, 'and none of them reaches for the file directly');
checkMentions(file_get_contents(__DIR__ . '/../config.php'), '->apply()',
              'config.php is the one place the ten names are brought into being');

// Every one of them is defined in this process — config.php did it on the way in —
// so `apply()` here must be a silent no-op rather than ten warnings and an
// argument about who was right.
$siteBefore = SITE_NAME;
$brandCfg->apply();
$defined = 0;
foreach (BrandingConfig::DEFAULTS as $name => $unusedDefault) {
    if (defined($name)) { $defined++; }
}
checkSame(10, $defined, 'apply() leaves all ten names defined');
checkSame($siteBefore, SITE_NAME, 'and overrides nothing that was already set');

// The same promise for the generated file itself, which is the half that a bare
// `define()` got wrong: config.php offers db_credentials.php as the way to override
// any of these, and a `define()` of a name already taken warns and then keeps the
// first value — the documented override worked while complaining about itself. Any
// unsuppressed warning is a failed check in this harness, so loading it below is
// the assertion: ten of them would show up as ten failures.
$reloadPath = $brandDir . '/reload_test.php';
file_put_contents($reloadPath, BrandingConfig::render(['SITE_NAME' => 'Something Else']));
include $reloadPath;
checkSame($siteBefore, SITE_NAME,
          'loading the generated file again changes no constant that is already set');

chdir($brandCwd);

section('What a publishable layout is, as a pure function (#29, #30, #31, #32)');

// LayoutRules has no database, no Display and no transaction in it, which is the
// whole reason it was pulled out of the publish path: the question "can this
// payload be stored faithfully?" can then be asked several hundred times in a
// millisecond, instead of once or twice through a transaction that has to be set
// up and torn down. Everything in this section is that function alone. The section
// after it proves the store actually asks it.

/** A layout that is fine, as the baseline every case below varies from. */
function goodLayout()
{
    return [
        ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 10, 'y_pos' => 20,
         'width' => 600, 'height' => 380],
        ['type' => 'text', 'block_subtype' => 'price', 'parent_temp_id' => 's1',
         'manual_content' => 'Sockeye 18.99', 'x_pos' => 5, 'y_pos' => 5,
         'width' => 160, 'height' => 60, 'font_size' => 48, 'line_height' => 1.4],
    ];
}

/** The baseline with one field of one block replaced. */
function layoutWithField($index, $key, $value)
{
    $elements = goodLayout();
    $elements[$index][$key] = $value;
    return $elements;
}

/** Shorthand: is this payload refused? */
function refuses(array $elements)
{
    return !LayoutRules::check($elements)->isOk();
}

check(LayoutRules::check(goodLayout())->isOk(), 'an ordinary layout is publishable');
check(LayoutRules::check([])->isOk(), 'and so is an empty one — that is somebody who deleted everything');
check(LayoutRules::check([[]])->isOk(),
      'a block with no fields at all is fine: every one of them has an insert default');

// ---- The type vocabulary (#29) --------------------------------------------------
// `$el['type'] ?? 'text'` accepted anything at all. On a MySQL that is not in strict
// mode an unknown value is stored as '' — a row that renders as nothing, cannot be
// selected by type, and is invisible in the Work Area's type filter.
foreach (LayoutRules::ELEMENT_TYPES as $type) {
    check(LayoutRules::check([['type' => $type]])->isOk(),
          'a block of type ' . $type . ' is accepted');
}
check(refuses([['type' => 'script']]), 'a type that is not one of the seven is refused');
check(refuses([['type' => 'Section']]), 'and so is one that differs only in case');
check(refuses([['type' => 'section ']]), 'or by a trailing space');
check(refuses([['type' => '']]), 'or is empty');
check(refuses([['type' => ['section']]]), 'or is not even a string');
checkMentions(LayoutRules::check([['type' => 'script']])->message(), 'carousel',
              'and the refusal lists the types that would have worked');

// ---- And it says what arrived, in words (#50) -----------------------------------
// The refusal is a sentence an admin has to match against their own canvas, and the
// only part of it naming *their* value is `describe()`. Every branch of that function
// could be removed with the suite green: nothing had asserted the described value,
// only the wording built around it. This is the same defect `Color::describe()` had —
// a description function covered by inference from the messages that quote it.
$typeMsg = function ($type) {
    return LayoutRules::check([['temp_id' => 's1', 'type' => $type]])->problems()[0];
};
checkMentions($typeMsg(['section']), '(a list of 1 things)',
              'a value that arrived as a list is named as one, with its size');
checkMentions($typeMsg(new stdClass), '(an object)',
              'and an object by its shape, since printing one is a warning and the word Object');
checkMentions($typeMsg(''), '(an empty value)',
              'and a field submitted blank is named as blank, not quoted as nothing');
checkMentions($typeMsg(true), '(true)',
              'and a boolean by value, because "1" would read as something somebody typed');
checkMentions($typeMsg('carousell'), '("carousell")',
              'while an ordinary near miss is quoted back, which is the whole point of the sentence');

// The cut, at both edges. 40 characters of a pasted value belongs in a refusal; a
// screenful does not, and the ellipsis is what tells an admin the rest is theirs.
checkMentions($typeMsg(str_repeat('q', 40)), '("' . str_repeat('q', 40) . '")',
              'forty characters is quoted whole');
checkMentions($typeMsg(str_repeat('q', 41)), '("' . str_repeat('q', 37) . '…")',
              'forty-one is cut to thirty-seven and an ellipsis, so the sentence stays a sentence');

// A casing variant is the one that mattered most: `insertContent` skips a section
// with `!==`, so 'Section' slipped past the skip and was inserted as content — at
// top level, by a basic account, which is the rule ADR-0005 exists to hold.
check(refuses([['type' => 'SECTION', 'parent_temp_id' => 's1']]),
      'a mis-cased section cannot sneak past the skip that keeps basic accounts out of the layout');

foreach (LayoutRules::BLOCK_SUBTYPES as $subtype) {
    check(LayoutRules::check([['type' => 'text', 'block_subtype' => $subtype]])->isOk(),
          'the ' . $subtype . ' block style is accepted');
}
check(refuses([['type' => 'text', 'block_subtype' => 'headline']]),
      'a block style that is not one of the seven is refused');
check(LayoutRules::check([['type' => 'section', 'block_subtype' => 'nonsense']])->isOk(),
      'but a section is not asked about one — it has no block style to store');

// ---- Two sections, one handle (#31) ---------------------------------------------
// The temp-id map is a plain array, so the second write to a key replaced the
// first. Every block belonging to the first section was then inserted into the
// second: a whole column moved across the sign, silently, reporting success.
$dup = [
    ['type' => 'section', 'temp_id' => 'same'],
    ['type' => 'section', 'temp_id' => 'same'],
    ['type' => 'text', 'parent_temp_id' => 'same', 'manual_content' => 'Halibut 24.99'],
];
check(refuses($dup), 'two sections sharing a temporary id are refused');
checkMentions(LayoutRules::check($dup)->message(), 'shares its temporary id',
              'and the refusal says so in those words');
checkMentions(LayoutRules::check($dup)->message(), 'block 1',
              'naming the other block it collides with');
check(LayoutRules::check([
        ['type' => 'section', 'temp_id' => 'a'],
        ['type' => 'section', 'temp_id' => 'b'],
      ])->isOk(), 'while two sections with their own handles are fine');
check(LayoutRules::check([
        ['type' => 'section', 'temp_id' => 'a'],
        ['type' => 'text', 'temp_id' => 'a'],
      ])->isOk(),
      'and a content block reusing a section handle is not a collision — only sections are mapped');

// PHP's `empty()` counts the string "0", so the store skips a temp_id of '0'
// entirely and nothing is ever parented by it. The check mirrors that rather than
// inventing a rule the store does not apply: two of them collide with nothing,
// because neither one is a handle.
check(LayoutRules::check([
        ['type' => 'section', 'temp_id' => '0'],
        ['type' => 'section', 'temp_id' => '0'],
      ])->isOk(), 'two sections whose handle is "0" are not a collision — the store maps neither');

check(refuses([['type' => 'section', 'temp_id' => ['an', 'array']]]),
      'a temporary id that cannot be an array key is refused');
check(refuses([['type' => 'text', 'parent_temp_id' => ['an', 'array']]]),
      'and so is a parent named by one');
check(LayoutRules::check([['type' => 'section', 'temp_id' => 7]])->isOk(),
      'an integer handle is usable and accepted');
check(refuses([['type' => 'section', 'temp_id' => str_repeat('x', 200)]]),
      'a handle longer than any client sends is refused');

// ---- Wrong-shaped and absurd numbers (#30) --------------------------------------
// Every one of these used to go through `intval()`, which has an answer for
// everything and reports none of them.
check(refuses(layoutWithField(0, 'x_pos', 'abc')), 'a position that is not a number is refused');
check(refuses(layoutWithField(0, 'x_pos', ['x'])),
      'and so is one that is a list — `intval` on an array is 1, silently');
check(refuses(layoutWithField(0, 'x_pos', true)), 'and a true/false is not a coordinate');
check(LayoutRules::check(layoutWithField(0, 'x_pos', '250'))->isOk(),
      'a numeric string is a number and is accepted');
check(LayoutRules::check(layoutWithField(0, 'x_pos', 250.0))->isOk(),
      'so is a float that is a whole number — JSON has no integer type');
check(refuses(layoutWithField(0, 'x_pos', 250.5)),
      'but a fractional position is not, because the column cannot hold it');
check(LayoutRules::check(layoutWithField(0, 'x_pos', -500))->isOk(),
      'a negative position is fine — a block may hang off the edge of the canvas');
check(refuses(layoutWithField(0, 'x_pos', 999999)), 'an absurd position is refused');
check(refuses(layoutWithField(0, 'y_pos', -999999)), 'in both directions');
check(LayoutRules::check(layoutWithField(0, 'x_pos', LayoutRules::POS_MAX))->isOk(),
      'the bound itself is inside');
check(refuses(layoutWithField(0, 'x_pos', LayoutRules::POS_MAX + 1)), 'and one past it is not');

check(refuses(layoutWithField(0, 'width', 0)), 'a width of nothing is refused');
check(refuses(layoutWithField(0, 'width', -10)), 'and a negative one');
check(refuses(layoutWithField(0, 'height', 999999)), 'and a height no screen could show');
check(refuses(layoutWithField(1, 'font_size', 0)), 'text of no size is refused');
check(refuses(layoutWithField(1, 'font_size', 100000)), 'and text taller than any sign');
check(refuses(layoutWithField(0, 'z_index', 0)),
      'a layer below the floor is refused rather than quietly raised to 1');
check(refuses(layoutWithField(0, 'sort_order', -1)), 'and so is a negative order');
checkMentions(LayoutRules::check(layoutWithField(0, 'width', 999999))->message(), 'outside',
              'an out-of-range value is reported as out of range, not as the wrong shape');

// The library link is the one where a coerced value points at real data: `intval`
// on an array is 1, so a wrong-shaped asset_id aimed the block at library row 1 —
// whatever that happens to be on this installation.
check(refuses(layoutWithField(1, 'asset_id', ['x'])),
      'a library link that is a list is refused, not read as item 1');
check(refuses(layoutWithField(1, 'asset_id', 'seventeen')), 'nor is a word a library item');
check(LayoutRules::check(layoutWithField(1, 'asset_id', '17'))->isOk(), 'while "17" is');
check(LayoutRules::check(layoutWithField(1, 'asset_id', ''))->isOk(),
      'and an empty one means no link at all, which is how the Builder sends it');

check(refuses(layoutWithField(1, 'locked', ['yes'])), 'a locked flag that is a list is refused');
check(LayoutRules::check(layoutWithField(1, 'locked', true))->isOk(),
      'while a real true/false is exactly what a flag is');
check(LayoutRules::check(layoutWithField(1, 'hidden', 1))->isOk(), 'and so is 1');

// ---- Stored strings and the widths of their columns (#30) -----------------------
// Past the column width, a MySQL in strict mode fails the whole publish with
// "Publish failed" and one that is not truncates and says nothing.
check(refuses(layoutWithField(1, 'font_family', str_repeat('A', 101))),
      'a font name past the column width is refused');
check(LayoutRules::check(layoutWithField(1, 'font_family', str_repeat('A', 100)))->isOk(),
      'and one exactly at it is not');
check(refuses(layoutWithField(1, 'text_align', str_repeat('x', 17))), 'so is an alignment');
check(refuses(layoutWithField(1, 'font_color', str_repeat('x', 51))), 'and a colour');
// The two rows of that table nothing stood over (#50). Five string fields are capped
// here and only `font_family` had a check, so those two lines could be deleted and a
// five-thousand-character font weight would reach a VARCHAR(20) — which is the whole
// defect this section exists for, on the two fields nobody thought to name.
check(refuses(layoutWithField(1, 'font_weight', str_repeat('b', 21))), 'and a font weight');
check(refuses(layoutWithField(1, 'font_style',  str_repeat('i', 21))), 'and a font style');
check(refuses(layoutWithField(0, 'section_bg', str_repeat('p', 256))),
      'and a section background path');
check(refuses(layoutWithField(1, 'manual_content', str_repeat('t', 65536))),
      'content past what TEXT holds is refused rather than cut in half');
check(refuses(layoutWithField(1, 'font_family', ['Arial'])), 'a font name that is a list is refused');
check(refuses(layoutWithField(1, 'manual_content', ['not' => 'a string'])),
      'and content that is an object — the payload that used to be a TypeError');
checkMentions(LayoutRules::check(layoutWithField(1, 'font_family', str_repeat('A', 101)))->message(),
              '101 characters', 'a too-long value is told how long it was');

// ---- What a colour means, not just how long it is (#41) -------------------------
// §4ab checked font_color's shape and length and stopped there, on purpose, because
// its semantics belong to this item. The reason they cannot stay unchecked is what
// reads the value back: the Builder assigns it to `block.style.color`, the CSSOM
// discards anything it cannot parse *silently*, and the publish payload then sent
// #000000. So an unreadable colour did not survive being looked at — opening the
// Display and pressing Publish rewrote that block black, on a canvas whose default
// is #1a1a2e.
check(LayoutRules::check(layoutWithField(1, 'font_color', '#ff0000'))->isOk(),
      'a colour publishes');
check(LayoutRules::check(layoutWithField(1, 'font_color', '#FF0000'))->isOk(),
      'and so does one an admin typed in capitals');
check(LayoutRules::check(layoutWithField(1, 'font_color', ''))->isOk(),
      'and blank does, because that is what a block with no colour of its own carries');
check(refuses(layoutWithField(1, 'font_color', 'puce')),
      'a text colour that is not a colour is refused rather than published as black');
check(refuses(layoutWithField(1, 'font_color', 'rgb(255,0,0)')),
      'and so is a notation this app does not store, however readable a browser finds it');
check(refuses(layoutWithField(1, 'font_color', '#f00')),
      'and the three-digit shorthand, for the same reason');
check(refuses(layoutWithField(1, 'font_color', '#12345g')),
      'and six characters that are not all hexadecimal');
checkMentions(LayoutRules::check(layoutWithField(1, 'font_color', 'puce'))->message(),
              'Block 2', 'the refusal says which block, because that is what you go and fix');
checkMentions(LayoutRules::check(layoutWithField(1, 'font_color', 'puce'))->message(),
              '"puce"', 'and quotes the value, so a typo is distinguishable from a stale tab');

// One wrong value is one problem. The length check runs first and would otherwise
// report the same field twice, which inflates the "and 3 other problems" count that
// tells somebody how much is left to fix.
checkSame(1, count(LayoutRules::check(layoutWithField(1, 'font_color', 'puce'))->problems()),
          'an unreadable colour is one problem');
checkSame(1, count(LayoutRules::check(layoutWithField(1, 'font_color', str_repeat('x', 51)))->problems()),
          'and one that is also too long is still one problem, reported by length');

// A section has no text of its own, so font_color is not among the fields checked
// for one — the same list the insert writes.
check(LayoutRules::check(layoutWithField(0, 'font_color', 'puce'))->isOk(),
      'a section is not asked about a text colour it does not carry');

check(refuses(['not a block']), 'a payload entry that is not a block at all is refused');
check(refuses([['type' => 'text'], 'and this one']), 'even when the others are fine');

// ---- Line height (#32) ------------------------------------------------------------
check(refuses(layoutWithField(1, 'line_height', 'tall')),
      'a line height that is not a number is refused');
check(LayoutRules::check(layoutWithField(1, 'line_height', 2000))->isOk(),
      'but an absurd *number* is accepted here, because the decision was to clamp it');

checkSame('1.40', LayoutRules::lineHeight(1.4), 'an ordinary line height is stored as written');
checkSame('5.00', LayoutRules::lineHeight(2000), 'an absurd one is clamped to the top of the range');
checkSame('0.50', LayoutRules::lineHeight(0.01), 'and a tiny one to the bottom');
checkSame('1.40', LayoutRules::lineHeight('nonsense'), 'something that is not a number becomes the default');
checkSame('1.40', LayoutRules::lineHeight(null), 'and so does nothing at all');
checkSame('1.46', LayoutRules::lineHeight(1.456), 'two decimal places, which is what the column holds');
checkSame('2.50', LayoutRules::lineHeight('2.5'), 'a numeric string is read as the number it is');

// The actual defect: `number_format($v, 2)` uses a comma for thousands, so a line
// height of 2000 was handed to a DECIMAL(4,2) column as the string "2,000.00".
check(strpos(LayoutRules::lineHeight(2000), ',') === false,
      'and no clamped value can carry a thousands separator into the column');
check(strpos(LayoutRules::lineHeight(999999), ',') === false, 'however large the number was');
// Not clamped to the top of the range but sent to the default, deliberately: an
// infinity is not a large line height somebody typed, it is a value that arrived
// through arithmetic nobody meant to do. JSON cannot carry either of these, so both
// are here as a statement about what the clamp does with a non-number rather than
// about anything the Builder can send.
checkSame('1.40', LayoutRules::lineHeight(INF), 'an infinity is not a line height');
checkSame('1.40', LayoutRules::lineHeight(NAN), 'and neither is a NaN');

// ---- What the refusal says --------------------------------------------------------
$many = LayoutRules::check([
    ['type' => 'text', 'x_pos' => 'abc'],
    ['type' => 'text', 'width' => 0],
    ['type' => 'nope'],
]);
checkSame(3, count($many->problems()), 'every problem is found, not just the first');
checkMentions($many->message(), 'Block 1', 'the message leads with the first one');
checkMentions($many->message(), '2 other problems', 'and says how many others there are');
checkMentions($many->message(), 'nothing was saved', 'it says nothing was saved');
checkMentions($many->message(), 'still on screen', 'and that the work is still there');
checkSame('', LayoutCheck::ok()->message(), 'while a layout with nothing wrong has nothing to say');

$one = LayoutRules::check([['type' => 'nope'], ['type' => 'text', 'width' => 0]]);
checkSame(2, count($one->problems()), 'two problems are two problems');
checkMentions($one->message(), '1 other problem', 'and the second is reported in the singular');
check(strpos(LayoutRules::check([['type' => 'nope']])->message(), 'other problem') === false,
      'and a single problem mentions no others at all');

// A JSON object rather than a list decodes to string keys, and "block 3" would then
// be a number nobody could find on their canvas.
$named = LayoutRules::check(['header' => ['type' => 'nope']]);
checkMentions($named->message(), '"header"', 'a named entry is reported by its name, not by a position');

// ---- One vocabulary, not two --------------------------------------------------------
// The list the publish accepts and the list the column stores are now generated from
// the same array. These pin the generated strings to what schema.php spelled out by
// hand before, so a rebuild cannot quietly widen the ENUM by editing one of them.
checkSame("enum('section','text','image','video','carousel','marquee','table')",
          SCHEMA_ELEMENT_TYPE_ENUM, 'the element ENUM is exactly what it always was');
checkSame("enum('free','section_header','item_title','item_title_2','price','price_2','description')",
          SCHEMA_BLOCK_SUBTYPE_ENUM, 'and so is the block-style ENUM');
checkMentions(file_get_contents(__DIR__ . '/../schema.sql'),
              "ENUM('section','text','image','video','carousel','marquee','table')",
              'and schema.sql declares the same seven types');

// ─────────────────────────────────────────────────────────────
section('The store refuses a layout it cannot store, before deleting the old one');

$vPdo     = newTestDb();
$vStore   = newTestDisplayStore($vPdo);
$vLayouts = newTestLayoutStore($vPdo);
$vSign    = makeTestDisplay($vPdo, 'valid', 'Validation');

check($vLayouts->publish($vSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true, $vSign->layoutStamp()))->isOk(),
      'a good layout publishes');
checkSame(2, count(elementsOf($vPdo, $vSign->id())), 'and lands as two elements');

/** Try to publish this payload over the layout that is already there. */
function refusedPublish(LayoutStore $layouts, DisplayStore $store, Display $sign, array $elements,
                        $isAdmin = true, ?Background $bg = null)
{
    $fresh = $store->forId($sign->id());
    return $layouts->publish($fresh, new PublishRequest(
        $elements, $bg ?: Background::unchanged(), BrandChoice::unchanged(), 1, $isAdmin, $fresh->layoutStamp()));
}

$res = refusedPublish($vLayouts, $vStore, $vSign, layoutWithField(0, 'type', 'script'));
checkSame('invalid', $res->kind(), 'an unknown block type is refused as invalid, not as failed');
checkSame(2, count(elementsOf($vPdo, $vSign->id())), 'and the published layout is untouched');
checkSame(false, $vPdo->inTransaction(), 'with no transaction opened at all');

$res = refusedPublish($vLayouts, $vStore, $vSign, [
    ['type' => 'section', 'temp_id' => 'same'],
    ['type' => 'section', 'temp_id' => 'same'],
]);
checkSame('invalid', $res->kind(), 'so are two sections sharing a handle');
checkSame(2, count(elementsOf($vPdo, $vSign->id())), 'and again nothing was deleted');

$res = refusedPublish($vLayouts, $vStore, $vSign, layoutWithField(0, 'width', 999999));
checkSame('invalid', $res->kind(), 'and an absurd width');
checkSame(2, count(elementsOf($vPdo, $vSign->id())), 'still nothing deleted');

// A basic account's publish is checked by the same rules. It has to be: the delete
// it performs is narrower, but it is still a delete with no undo behind it.
$res = refusedPublish($vLayouts, $vStore, $vSign, layoutWithField(1, 'type', 'script'), false);
checkSame('invalid', $res->kind(), "a basic account's publish is checked the same way");
checkSame(2, count(elementsOf($vPdo, $vSign->id())), 'and its narrower delete did not run either');

// The stamp is untouched by a refusal, so the Builder that sent it can fix the block
// and publish again without being told it is now stale as well.
$vSign = $vStore->forId($vSign->id());
checkSame('1', $vSign->layoutStamp(), 'a refused publish does not advance the stamp');
check($vLayouts->publish($vSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true, $vSign->layoutStamp()))->isOk(),
      'so the corrected layout publishes on the next try');

// And the clamp reaches the column, which is the half a pure function cannot prove.
//
// On a `free` block rather than the baseline's price. A branded subtype's line
// height belongs to Brand Standards and publish no longer carries it at all
// (invariant 34), so the baseline would now pass this check for the wrong reason:
// the stored 1.40 would be the *default*, proving the value never arrived rather
// than that it was clamped on the way in. This caught it.
$vSign  = $vStore->forId($vSign->id());
$absurd = layoutWithField(1, 'line_height', 2000);
$absurd[1]['block_subtype'] = 'free';
check($vLayouts->publish($vSign, new PublishRequest(
        $absurd, Background::unchanged(), BrandChoice::unchanged(), 1, true,
        $vSign->layoutStamp()))->isOk(),
      'a layout with an absurd line height publishes, clamped');
$stored = elementsOf($vPdo, $vSign->id());
$content = null;
foreach ($stored as $row) { if ($row['type'] === 'text') { $content = $row; } }
checkSame('5.00', number_format(floatval($content['line_height']), 2),
          'and the stored line height is the top of the range, not a truncated 2000');

// ─────────────────────────────────────────────────────────────
section('A hidden block is not in what a Screen is sent (#25)');

// get_layout needs no sign-in — a TV in a shop window cannot log in — so whatever
// this returns is readable by anyone who knows a screen name tag. It used to return
// the whole layout and let the Viewer's JavaScript skip the hidden blocks on the way
// to the DOM: a rendering rule standing in for an access rule.
$hPdo     = newTestDb();
$hStore   = newTestDisplayStore($hPdo);
$hLayouts = newTestLayoutStore($hPdo);
$hSign    = makeTestDisplay($hPdo, 'window', 'Window Board');

check($hLayouts->publish($hSign, new PublishRequest([
        ['type' => 'section', 'temp_id' => 'open',   'x_pos' => 0,   'y_pos' => 0,
         'width' => 600, 'height' => 400],
        ['type' => 'section', 'temp_id' => 'closed', 'x_pos' => 620, 'y_pos' => 0,
         'width' => 600, 'height' => 400, 'hidden' => 1],
        ['type' => 'text', 'parent_temp_id' => 'open',   'manual_content' => 'Sockeye 18.99'],
        ['type' => 'text', 'parent_temp_id' => 'open',   'manual_content' => 'NEXT MONTH 24.99',
         'hidden' => 1],
        ['type' => 'text', 'parent_temp_id' => 'closed', 'manual_content' => 'Closed for the season'],
        ['type' => 'text', 'manual_content' => 'Open daily'],
      ], Background::unchanged(), BrandChoice::unchanged(), 1, true, $hSign->layoutStamp()))->isOk(),
      'a layout with hidden blocks publishes');

$hSign  = $hStore->forId($hSign->id());
$editor = $hLayouts->snapshot($hSign);
$public = $hLayouts->publicSnapshot($hSign);

checkSame(6, count($editor['elements']), 'the Builder is sent every block, hidden ones included');
checkSame(3, count($public['elements']), 'a Screen is sent only the three that are on the sign');

$publicJson = json_encode($public);
check(strpos($publicJson, 'NEXT MONTH') === false,
      'next month\'s price is not in the public payload at all');
check(strpos($publicJson, 'Closed for the season') === false,
      'nor is the content inside a hidden section');
checkMentions($publicJson, 'Sockeye 18.99', 'while what is actually on the sign still is');
checkMentions($publicJson, 'Open daily', 'including a block at root level');
checkMentions(json_encode($editor), 'NEXT MONTH',
              'and the Builder still receives it, or nothing could ever unhide it');

// The Display's own facts survive an empty layout: a Screen showing nothing must
// show a blank sign of the right size and colour, not an error.
$hBlank = makeTestDisplay($hPdo, 'allhidden', 'Everything Hidden');
$hLayouts->publish($hBlank, new PublishRequest([
    ['type' => 'text', 'manual_content' => 'Not yet', 'hidden' => 1],
], Background::unchanged(), BrandChoice::unchanged(), 1, true, $hBlank->layoutStamp()));
$hBlank = $hStore->forId($hBlank->id());
$blankPublic = $hLayouts->publicSnapshot($hBlank);
checkSame(0, count($blankPublic['elements']), 'a Display whose every block is hidden sends none');
checkSame('allhidden', $blankPublic['display']['tag'], 'but still says which Display it is');
check(isset($blankPublic['block_styles']), 'and still carries the shared typography');
checkSame($hBlank->layoutStamp(), $blankPublic['layout_stamp'],
          'and the stamp, which is what the poll compares');

// Hiding on one Display says nothing about another.
checkSame(3, count($hLayouts->publicSnapshot($hStore->forId($hSign->id()))['elements']),
          'and hiding everything on one sign leaves the other sign alone');

// ─────────────────────────────────────────────────────────────
section('A background address is checked by the module, not only by the panel (#23, #24)');

// The rules lived in admin_panel.php's neighbourhood and nowhere else, so a publish
// — which writes the same column — had none of them. `bg_val` is read back by the
// Viewer as `background-image: url('…')`, which makes an unvalidated colour field an
// address every Screen in the building fetches on every render.
checkSame(null, Background::color('#1a1a2e')->problemWith('#000000'),
          'a six-digit hex colour is a colour');
checkSame(null, Background::color('#ABCDEF')->problemWith('#000000'), 'in either case');
check(Background::color('https://elsewhere.example/tracker.png')->problemWith('#000000') !== null,
      'a URL is not a colour, however much the column would have accepted it');
check(Background::color('red')->problemWith('#000000') !== null, 'nor is a colour name');
check(Background::color('#fff')->problemWith('#000000') !== null, 'nor a three-digit hex');
check(Background::color('')->problemWith('#000000') !== null, 'nor an empty field');
checkMentions(Background::color('red')->problemWith('#000000'), 'Nothing was saved',
              'and the refusal says nothing was saved');

checkSame(null, Background::image('uploads/bg_1234.jpg')->problemWith('#000000'),
          'an upload this server made is a background image');
checkSame(null, Background::image('uploads/bg_68a.1b2c3d.png')->problemWith(''),
          'including the dotted names uniqid produces');
check(Background::image('https://elsewhere.example/x.png')->problemWith('') !== null,
      'a full URL is refused');
check(Background::image('//elsewhere.example/x.png')->problemWith('') !== null,
      'and a protocol-relative one');
check(Background::image('/etc/passwd')->problemWith('') !== null, 'and an absolute path');
check(Background::image('uploads/../../../etc/passwd')->problemWith('') !== null,
      'and one that climbs out of the uploads directory');
check(Background::image('uploads\\evil.png')->problemWith('') !== null, 'and a backslash path');
check(Background::image('uploads/')->problemWith('') !== null, 'and the directory itself');
check(Background::image('')->problemWith('') !== null, 'and nothing at all');
check(Background::image('uploads/' . str_repeat('x', 300) . '.png')->problemWith('') !== null,
      'and a name longer than the column');

// keep-image is the arm that makes the colour hole reachable without ever sending an
// image: publish a "colour" of https://…, then publish image with no file, and this
// promotes the stored string to the image path. It is also #23 — choosing Image on a
// Display that has never had one leaves `url('#1a1a2e')`, which loads nothing and
// takes the sign near black.
checkSame(null, Background::keepImage()->problemWith('uploads/bg_1234.jpg'),
          'switching back to a stored image is fine when there is one');
check(Background::keepImage()->problemWith('#1a1a2e') !== null,
      'and refused when what is stored is a colour');
check(Background::keepImage()->problemWith('') !== null, 'or nothing');
checkMentions(Background::keepImage()->problemWith('#1a1a2e'), 'no background image stored',
              'and says which of the two things went wrong');

checkSame(null, Background::unchanged()->problemWith('anything at all'),
          'and leaving the background alone is never refused, whatever is stored');

// ---- Through a real publish ------------------------------------------------------
$bPdo     = newTestDb();
$bStore   = newTestDisplayStore($bPdo);
$bLayouts = newTestLayoutStore($bPdo);
$bSign    = makeTestDisplay($bPdo, 'poison', 'Background');
$bLayouts->publish($bSign, new PublishRequest(
    goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true, $bSign->layoutStamp()));

$res = refusedPublish($bLayouts, $bStore, $bSign, goodLayout(), true,
                      Background::color('https://elsewhere.example/tracker.png'));
checkSame('invalid', $res->kind(), 'a publish carrying a URL as its colour is refused');
checkSame(2, count(elementsOf($bPdo, $bSign->id())), 'and the layout it came with is not saved either');
checkSame('#1a1a2e', $bStore->forId($bSign->id())->backgroundValue(),
          'and the stored background is exactly what it was');

$res = refusedPublish($bLayouts, $bStore, $bSign, goodLayout(), true, Background::keepImage());
checkSame('invalid', $res->kind(),
          'switching a colour-backed Display to Image with no file is refused (#23)');
checkSame('color', $bStore->forId($bSign->id())->backgroundType(),
          'and it is still on a colour, not on url(#1a1a2e)');

$bSign = $bStore->forId($bSign->id());
check($bLayouts->publish($bSign, new PublishRequest(
        goodLayout(), Background::color('#2c3e50'), BrandChoice::unchanged(), 1, true, $bSign->layoutStamp()))->isOk(),
      'while a real colour publishes');
checkSame('#2c3e50', $bStore->forId($bSign->id())->backgroundValue(), 'and is stored');

// A basic account never sends a background at all, so it is never asked. Worth
// pinning: the check is inside the isAdmin branch, and moving it out would refuse
// every basic publish on a Display whose stored value predates these rules.
//
// The admin's lock has to go first. A publish keeps its publisher's lock alive
// (ADR-0007), so without this the clerk is refused for a reason that has nothing to
// do with backgrounds — and the check would pass while proving something else.
$bStore->releaseLock($bStore->forId($bSign->id()), 1);
$bSign = $bStore->forId($bSign->id());
// basicLayoutFor(), not goodLayout(): a clerk's payload fills a section that already
// exists rather than declaring one, and a root-level block from this role is now
// refused. Using the admin shape here would fail for that reason and look like a
// background problem.
check($bLayouts->publish($bSign, new PublishRequest(
        basicLayoutFor($bPdo, $bSign, 'Sockeye 18.99'),
        Background::unchanged(), BrandChoice::unchanged(), 2, false, $bSign->layoutStamp()))->isOk(),
      "a basic account's publish is not held up by a background it cannot change");

// ─────────────────────────────────────────────────────────────
section('A publish carries the Brand it was picked with, or it carries nothing');

// Step 4's server half. Picking a Brand in the Builder writes nothing at the moment it
// is picked — it repaints the canvas in the browser and rides out with the next Publish
// (decision 6), which means the publish endpoint receives an *intent* and the intent
// has to be refusable before anything is written. Exactly the shape of the section
// above, one column over, and the same rule underneath it: refuse rather than merge,
// because falling back to the Brand already on the sign would report success over the
// one change the person made (invariant 5).

checkSame('unchanged', BrandChoice::unchanged()->kind(), 'leaving the Brand alone is an intent of its own');
checkSame(0, BrandChoice::unchanged()->id(), 'and it names no Brand');
check(BrandChoice::unchanged()->isUsable(), 'it is always carryable');
checkSame(null, BrandChoice::unchanged()->problemWith(null),
          'and is never refused, whatever the caller found');

checkSame('brand', BrandChoice::brand('3')->kind(), 'an id from a form is a Brand intent');
checkSame(3, BrandChoice::brand('3')->id(), 'carrying the number it named');
checkSame(3, BrandChoice::brand(3)->id(), 'written as a string or as an int');
check(BrandChoice::brand('3')->isUsable(), 'and it is carryable');

// The half that needs no database, and the reason it is a separate question: this is
// what `intval()` would have guessed at. `intval('7abc')` is 7 — so a mangled field
// would not have failed, it would have published a *different venue's* Brand onto the
// sign, which is #21's shape and `DisplayStore::isIdLike()`'s whole reason for being.
foreach ([
    ['7abc',  'an id with something after it'],
    ['',      'an empty field'],
    ['0',     'a zero'],
    ['-2',    'a negative'],
    ['7.9',   'a float that would have selected Brand 7'],
    ['1e2',   'exponent notation'],
    [' ',     'a space'],
    [[],      'an array, which intval() calls 1'],
    [null,    'nothing at all'],
    [true,    'a boolean, which casts to the first Brand ever created'],
] as $case) {
    $bad = BrandChoice::brand($case[0]);
    checkSame(BrandChoice::INVALID, $bad->kind(), $case[1] . ' is not a Brand');
    checkSame(0, $bad->id(), 'and ' . $case[1] . ' names no Brand id');
    check(!$bad->isUsable(), 'and ' . $case[1] . ' cannot be carried');
    // Which refusal, not merely that there is one. There are two sentences here — "that
    // is not a brand" and "that brand has been deleted" — and they tell somebody to do
    // different things; a check that accepted either would pass on the wrong one.
    checkMentions($bad->problemWith(null), 'cannot read',
                  'and ' . $case[1] . ' is refused as unreadable rather than as deleted');
}

// The boundary, both sides. `1` is a real Brand id — the seeded one every install has —
// so a threshold that crept up by one would refuse the Brand nobody can be without, and
// every other check in this section would still pass.
checkSame('brand', BrandChoice::brand(1)->kind(), 'Brand 1 is a Brand, not a rounding error');
checkSame(1, BrandChoice::brand(1)->id(), 'and it names itself');

checkMentions(BrandChoice::brand('7abc')->problemWith(null), '7abc',
              'and the refusal quotes what actually arrived');
checkMentions(BrandChoice::brand('7abc')->problemWith(null), 'nothing was saved',
              'and says nothing was saved');
check(strpos(BrandChoice::brand(str_repeat('9', 400))->problemWith(null), str_repeat('9', 400)) === false,
      'a very long value is shortened rather than printed whole into a page');

// ---- The half only the database knows -------------------------------------------
$kPdo    = newTestDb();
$kStore  = newTestDisplayStore($kPdo);
$kBrands = new BrandStore($kPdo);
$kOther  = makeTestBrand($kPdo, 'Salmon House');

checkSame(null, BrandChoice::brand($kOther)->problemWith($kBrands->forId($kOther)),
          'a Brand that is there is a Brand this publish may wear');
checkSame(null, BrandChoice::brand(1)->problemWith($kBrands->forId(1)),
          'including Brand 1, which is the one every install is seeded with');
check(BrandChoice::brand(999999)->problemWith($kBrands->forId(999999)) !== null,
      'and one that is not is refused rather than merged away');
checkMentions(BrandChoice::brand(999999)->problemWith(null), 'no longer exists',
              'saying what happened, because somebody deleted it while the tab was open');
checkMentions(BrandChoice::brand(999999)->problemWith(null), 'still on screen',
              'and that the work is not lost');

// Both spellings answered, which is the rule `Background::problemWith()` states: the
// kind is what the factory produces today and the guard is what keeps this honest if
// the factory ever stops filtering. A reader knowing only one of them answers "no
// problem" for the other, and that is a publish nothing refuses.
check(BrandChoice::brand('7abc')->problemWith($kBrands->forId($kOther)) !== null,
      'and something that is not an id is refused even when a real Brand is handed in');

// ---- Through a real publish ------------------------------------------------------
$kLayouts = newTestLayoutStore($kPdo);
$kSign    = makeTestDisplay($kPdo, 'venue', 'Venue board');
checkSame(1, $kSign->brandId(), 'a fixture sign starts on the seeded Brand');

check($kLayouts->publish($kSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::brand($kOther), 1, true,
        $kSign->layoutStamp()))->isOk(),
      'an admin publish carrying a Brand is accepted');
checkSame($kOther, $kStore->forId($kSign->id())->brandId(),
          'and the sign wears it afterwards — the publish is what wrote it, not the picking');

$kSign = $kStore->forId($kSign->id());
check($kLayouts->publish($kSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true,
        $kSign->layoutStamp()))->isOk(),
      'a publish that names no Brand still publishes');
checkSame($kOther, $kStore->forId($kSign->id())->brandId(), 'and leaves the one it is wearing');

// The refusals, and what they left behind. Two elements is `goodLayout()` stored, so
// finding two afterwards is finding the layout that was there before the refusal.
$kSign = $kStore->forId($kSign->id());
$res = $kLayouts->publish($kSign, new PublishRequest(
    goodLayout(), Background::unchanged(), BrandChoice::brand('7abc'), 1, true, $kSign->layoutStamp()));
checkSame('invalid', $res->kind(), 'a publish naming something that is not a Brand is refused');
checkSame(false, $kPdo->inTransaction(),
          'with no transaction opened at all — it is settled beside the layout rules');
checkSame(2, count(elementsOf($kPdo, $kSign->id())), 'and the layout it came with is not saved either');
checkSame($kOther, $kStore->forId($kSign->id())->brandId(), 'and the sign wears what it wore');

// Which of the two gates answered, and it matters: one of them needs no database and the
// other holds the row lock. A publish that is wrong in *both* ways — not an id, and a
// stamp from before somebody else published — must come back `invalid` rather than
// `stale`, because the id was settled before the transaction was ever opened. Without
// this, the outer gate could be deleted and every other check in this section would
// still pass, since the inner one answers `invalid` too.
$res = $kLayouts->publish($kSign, new PublishRequest(
    goodLayout(), Background::unchanged(), BrandChoice::brand('7abc'), 1, true, 'not-the-stamp'));
checkSame('invalid', $res->kind(),
          'a publish wrong in two ways is refused for the one that needed no database');
checkSame(false, $kPdo->inTransaction(), 'and still opens no transaction');

$res = $kLayouts->publish($kSign, new PublishRequest(
    goodLayout(), Background::unchanged(), BrandChoice::brand(999999), 1, true, $kSign->layoutStamp()));
checkSame('invalid', $res->kind(), 'so is one naming a Brand that has been deleted');
checkSame(2, count(elementsOf($kPdo, $kSign->id())), 'and that layout is not saved either');
checkSame($kOther, $kStore->forId($kSign->id())->brandId(), 'and the sign still wears what it wore');
checkMentions($res->message(), 'Reload', 'and the refusal says what to do next');

// A basic account cannot change the Brand, so it is never asked about one — the check
// is inside the `isAdmin` branch beside the background's. Worth pinning both ways: a
// clerk naming a Brand does not move the sign onto it, and a clerk naming a Brand that
// does not exist is not refused for it either. Moving that check out of the branch
// would stop every clerk publishing the moment an admin deleted a Brand.
$kStore->releaseLock($kStore->forId($kSign->id()), 1);
$kSign = $kStore->forId($kSign->id());
check($kLayouts->publish($kSign, new PublishRequest(
        basicLayoutFor($kPdo, $kSign, 'Sockeye 18.99'),
        Background::unchanged(), BrandChoice::brand(999999), 2, false, $kSign->layoutStamp()))->isOk(),
      "a basic account's publish is not held up by a Brand it cannot set");
checkSame($kOther, $kStore->forId($kSign->id())->brandId(),
          'and the Brand it named is not the Brand the sign wears');

// Both halves of that, for the same people. A deleted Brand and a mistyped one are the
// same thing to a clerk — neither is theirs to set and neither is going to be stored —
// so a rule that refused one and allowed the other would be arbitrary from where they
// are standing. This is the check that holds the pre-transaction gate to the same
// `isAdmin()` as the one under the lock.
$kSign = $kStore->forId($kSign->id());
check($kLayouts->publish($kSign, new PublishRequest(
        basicLayoutFor($kPdo, $kSign, 'Sockeye 19.99'),
        Background::unchanged(), BrandChoice::brand('7abc'), 2, false, $kSign->layoutStamp()))->isOk(),
      "nor by one that is not an id at all");

// ---- The Brand a publish sets is the Brand its rows are read under ---------------
// The `copyLayout()` rule, arriving by the one door that had not needed it: what
// decides whether a typography column is the Brand's to paint is the Brand the row
// will be read under (invariant 34), and the publish that *changes* the Brand is the
// one publish where "before" and "after" differ. Deciding it from the Display this
// method was handed would bake the old venue's answer into the new venue's rows.
//
// `makeTestBrandRow()` and not `makeTestBrand()`: a Brand with no standards behind it
// paints nothing, so a `price` block publishing onto it keeps its own typography —
// which is the observable difference between the two answers.
$kBare = makeTestBrandRow($kPdo, 'No standards yet');
// The clerk's publish above took the edit lock, as a publish does (ADR-0007), so the
// admin has to be given it back or every check below is refused for a reason that has
// nothing to do with Brands — and would pass while proving something else.
$kStore->releaseLock($kStore->forId($kSign->id()), 2);
$kStore->claimLock($kStore->forId($kSign->id()), 1);
$kSign = $kStore->forId($kSign->id());
check($kLayouts->publish($kSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::brand($kBare), 1, true,
        $kSign->layoutStamp()))->isOk(),
      'a publish onto a Brand with no standards is accepted');
$kRows  = elementsOf($kPdo, $kSign->id());
$kPrice = null;
foreach ($kRows as $row) { if (($row['block_subtype'] ?? '') === 'price') { $kPrice = $row; } }
check($kPrice !== null, 'and the price block it carried is stored');
checkSame(48, intval($kPrice['font_size']),
          "and keeps its own font size, because the Brand it now wears paints nothing");

// The other direction, so the check above is not passing on a Brand nobody painted
// with: back onto a Brand that does have standards, and the same block publishes the
// documented default instead of its own 48.
$kSign = $kStore->forId($kSign->id());
check($kLayouts->publish($kSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::brand($kOther), 1, true,
        $kSign->layoutStamp()))->isOk(),
      'and publishing back onto a Brand that has them is accepted too');
$kPrice = null;
foreach (elementsOf($kPdo, $kSign->id()) as $row) {
    if (($row['block_subtype'] ?? '') === 'price') { $kPrice = $row; }
}
checkSame(intval(BrandStyles::DEFAULTS['font_size']), intval($kPrice['font_size']),
          'where the same block stores the documented default, because that Brand paints it');

// ---- The two contracts meet, and the stamp is what settles it -------------------
// The Admin Panel changes a Display's Brand *immediately* — every Screen showing it
// picks up the new venue's typography on its next poll, with no publish, which is that
// page's normal contract. The Builder *stages* it. So a Builder tab that loaded before
// such a save is holding the old Brand and its publish would put it straight back:
// silently, on every screen wearing the sign, with a payload that is otherwise perfectly
// valid. `updateDetails()` advances the layout stamp for exactly that, which is ADR-0006
// answering the question it was written for.
$kAdmin = newTestDisplayAdmin($kPdo);
$kSign  = $kStore->forId($kSign->id());
$kStamp = $kSign->layoutStamp();

$kRes = $kAdmin->updateDetails($kSign, ['title' => 'Venue board', 'tag' => 'venue',
                                        'location' => '', 'brand_id' => $kBare]);
check($kRes->isOk(), 'an admin may move a sign onto another Brand from the panel');
checkSame($kBare, $kStore->forId($kSign->id())->brandId(), 'and the sign wears it at once');
check($kStore->forId($kSign->id())->layoutStamp() !== $kStamp,
      'and the layout stamp moved, so a Builder that loaded before it is holding a stale sign');
checkMentions($kRes->message(), 'No standards yet', 'the sentence names the Brand it moved to');
checkMentions($kRes->message(), '30 seconds', 'and says the screens pick it up on their own');

$kRes = $kLayouts->publish($kSign, new PublishRequest(
    goodLayout(), Background::unchanged(), BrandChoice::brand($kOther), 1, true, $kStamp));
checkSame('stale', $kRes->kind(),
          'so that tab\'s publish is refused rather than putting the old Brand back');
checkSame($kBare, $kStore->forId($kSign->id())->brandId(), 'and the sign still wears the panel\'s choice');
checkMentions($kRes->message(), 'changed since you opened it',
              'with the sentence ADR-0006 already had, which is true of this too');

// The other half, and the reason this is a *comparison* rather than a bump on every
// save: an ordinary rename must not refuse a colleague's publish. A rename has its own
// answer already — the Builder is told the address moved and keeps its lock.
$kSign  = $kStore->forId($kSign->id());
$kStamp = $kSign->layoutStamp();
$kRes   = $kAdmin->updateDetails($kSign, ['title' => 'Venue board B', 'tag' => 'venue-b',
                                          'location' => 'Deck', 'brand_id' => $kBare]);
check($kRes->isOk(), 'a save that leaves the Brand alone still saves');
checkSame($kStamp, $kStore->forId($kSign->id())->layoutStamp(),
          'and does not move the stamp, so it refuses nobody');
check(strpos($kRes->message(), 'wears the brand') === false,
      'and says nothing about a brand it did not change');

// ─────────────────────────────────────────────────────────────
section('Refusing a value rather than guessing what it meant (#21)');

// The shape of every defect in this section is the same: the app was handed
// something it could not read, substituted a value of its own, wrote that, and
// reported success. Which value it substituted depended on which form you were on —
// four copies of the colour rule with four different fallbacks — so "saved" meant
// four different things and none of them meant "what you typed".
//
// The rule itself is one pure function now. Everything else here is a caller
// declining to guess.

checkSame('#1a2b3c', Color::read('#1a2b3c'), 'a colour reads back as itself');
checkSame('#1a2b3c', Color::read('#1A2B3C'), 'and capitals are the same colour, stored one way');
checkSame('', Color::read(''),               'blank is not a colour — that is the absent/unreadable line');
checkSame('', Color::read('red'),            'nor is a CSS keyword this app has never stored');
checkSame('', Color::read('rgb(255,0,0)'),   'nor a notation a browser would happily render');
checkSame('', Color::read('#f00'),           'nor the three-digit shorthand');
checkSame('', Color::read('#12345g'),        'nor six characters that are not all hexadecimal');
checkSame('', Color::read('#1234567'),       'nor seven of them');
checkSame('', Color::read('1a2b3c'),         'nor the digits with no hash');
checkSame('', Color::read(' #1a2b3c '),      'and this is not a normaliser: padding is not trimmed away');
checkSame('', Color::read(null),             'nothing is not a colour');
checkSame(false, Color::isColor(''),         'so blank fails the predicate too');
checkSame(true,  Color::isColor('#1a2b3c'),  'while a colour passes it');

// The reason read() asks is_string() first rather than casting. A hand-built
// `bg_val[]=x` used to reach `preg_match('…', (string)$value)`, and casting an array
// prints "Array to string conversion" — a warning above the document, on a page
// that was only trying to check a form.
$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
$listAnswer = Color::read(['#1a2b3c']);
restore_error_handler();
checkSame('', $listAnswer, 'a list is not a colour');
checkSame(false, $warned,  'and saying so emits no "Array to string conversion" warning');

// ---- What the refusal is allowed to say the value was (#50) --------------------
// `Color::describe()` is the other half of not guessing: a refusal that does not name
// what was wrong leaves an admin unable to tell a typo from a stale form, so
// `DisplayAdmin` quotes this in its message and the Admin Panel prints it twice on the
// unreadable-colours card. Ten of its thirteen mutants survived — the whole function
// could be reduced to quoting the value back, and the two checks that touched it were
// about the message around it rather than about this. It is a pure function of one
// argument, so there is no reason for it to be covered by inference.
checkSame('"#1a2b3c"', Color::describe('#1a2b3c'), 'a value that is a string is quoted');
checkSame('blank', Color::describe(''),            'blank is named, not quoted as nothing');
checkSame('nothing', Color::describe(null),        'and absent is named as nothing, which is a different sentence');
checkSame('a list of values', Color::describe(['#fff']), 'a list is named by shape');
checkSame('true', Color::describe(true),           'and a boolean by value, because "1" would read as a colour attempt');
checkSame('false', Color::describe(false),         'both of them');
checkSame('integer', Color::describe(7),           'a number is named by type — it went through no field that could hold one');
checkSame('double', Color::describe(7.5),          'and so is a float');

// The cut, at both edges. Twenty characters is a swatch value with a typo; a hundred
// is a paste, and it lands inside a sentence on a page. The boundary is `> 20`, so a
// value of exactly twenty is short enough to show whole — the off-by-one either
// truncates a value that fits or quotes one that does not.
checkSame('"' . str_repeat('a', 20) . '"', Color::describe(str_repeat('a', 20)),
          'twenty characters is quoted whole');
checkSame('"' . str_repeat('a', 20) . '…"', Color::describe(str_repeat('a', 21)),
          'twenty-one is cut to twenty with an ellipsis');
checkSame('"abcdefghijklmnopqrst…"', Color::describe('abcdefghijklmnopqrstuvwxyz'),
          'and the twenty kept are the first twenty, from the start of the value');

// ---- A background colour is refused, not replaced -------------------------------
$cPdo   = newTestDb();
$cStore = new DisplayStore($cPdo);
$cAdmin = newTestDisplayAdmin($cPdo);

$res = $cAdmin->create(['brand_id' => 1, 'title' => 'Deli Board', 'canvas_width' => 1920,
                        'canvas_height' => 1080, 'bg_val' => 'darkish blue']);
checkSame(false, $res->isOk(), 'a Display is not created with a background nobody can read');
checkSame(DisplayResult::INVALID, $res->kind(), 'it is invalid input rather than a database failure');
checkSame('bg_val', $res->field(), 'and the refusal points at the swatch');
checkMentions($res->message(), '#1a1a2e', 'saying what a colour looks like, since the form was wrong about it');
checkSame(0, count($cStore->all()), 'and no Display was created — not one in the wrong colour');

$res = $cAdmin->create(['brand_id' => 1, 'title' => 'Deli Board', 'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(true, $res->isOk(), 'a form that named no colour at all is fine');
checkSame(DisplayAdmin::DEFAULT_BACKGROUND, $res->display()->backgroundValue(),
          'and gets the default, which is what "nothing supplied" has always meant');

$res = $cAdmin->create(['brand_id' => 1, 'title' => 'Bakery', 'canvas_width' => 1920,
                        'canvas_height' => 1080, 'bg_val' => '#AABBCC']);
checkSame(true, $res->isOk(), 'a real colour creates a Display');
checkSame('#aabbcc', $res->display()->backgroundValue(), 'stored the one way the app stores colours');

// The harsher half. This path used to substitute #1a1a2e, advance the layout stamp,
// and report the background "set" — so an admin got a colour they had not chosen, on
// every Screen within 30 seconds, and every Builder tab open at the time was
// invalidated on the way past. All three of those had to stop happening.
$bakery      = $res->display();
$stampBefore = $cStore->forId($bakery->id())->layoutStamp();
$res = $cAdmin->setBackgroundColor($bakery, 'not a colour');
checkSame(false, $res->isOk(), 'setting a background to something unreadable is refused');
checkSame('bg_val', $res->field(), 'against the same field, with the same sentence as create()');
checkSame('#aabbcc', $cStore->forId($bakery->id())->backgroundValue(),
          'the stored colour is exactly what it was');
checkSame($stampBefore, $cStore->forId($bakery->id())->layoutStamp(),
          'and the layout stamp did not move, so no open Builder was invalidated for nothing');

$res = $cAdmin->setBackgroundColor($bakery, '#123456');
checkSame(true, $res->isOk(), 'while a real colour still sets');
checkSame('#123456', $cStore->forId($bakery->id())->backgroundValue(), 'and is stored');
check($cStore->forId($bakery->id())->layoutStamp() !== $stampBefore,
      'and that one does advance the stamp, because the Screens have something new to show');

// ---- An id that is not an id names no account -----------------------------------
// `intval` never fails, it guesses: "2abc" is 2, [] is 1, true is 1. So a mangled
// form field did not error, it silently addressed a *different, real* account — and
// account 1 is the first admin the store ever created.
$iPdo   = newTestDb();
$iStore = new AccountStore($iPdo);
$iAdmin = newTestAccountAdmin($iPdo);

$res = $iAdmin->close('2abc', 1);
checkSame(false, $res->isOk(), '"2abc" closes nothing, though intval() reads it as account 2');
checkSame(false, $iStore->isClosed(2), 'and account 2 is still open');
checkMentions($res->message(), 'did not name an account', 'the refusal says what was actually wrong');
$res = $iAdmin->close([], 1);
checkSame(false, $res->isOk(), 'a list closes nothing, though intval() reads it as account 1');
checkSame(false, $iStore->isClosed(1), 'so the first admin is still there');
checkSame(false, $iAdmin->close(true, 1)->isOk(), 'and neither does true, which also casts to 1');
checkSame(false, $iAdmin->edit('2abc', 'basic', true, 'x@example.com', 1)->isOk(),
          'the same id edits nothing');
checkSame('clerk', $iStore->names()[2], 'and account 2 is untouched');
checkSame(true, $iAdmin->close(2, 1)->isOk(), 'while the id written plainly closes the account it names');

// ---- Resetting somebody's password addresses one account or none ----------------
// This one ran `UPDATE users SET password_hash = ? WHERE id = ?` in the panel, on an
// id it had already cast, and printed "Password reset." whatever the statement
// matched — including nothing, and including somebody else.
$rPdo   = newTestDb();
$rAdmin = newTestAccountAdmin($rPdo);
$hashOf = function ($id) use ($rPdo) {
    $stmt = $rPdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return (string)$stmt->fetchColumn();
};
$clerkHash = $hashOf(2);

checkSame(false, $rAdmin->resetPassword('2abc', 'a long enough password')->isOk(),
          '"2abc" resets nobody, though intval() reads it as account 2');
checkSame($clerkHash, $hashOf(2), 'and account 2 keeps the password it had');
checkSame(false, $rAdmin->resetPassword(2, 'short')->isOk(), 'a short password is refused');
checkSame($clerkHash, $hashOf(2), 'and changes nothing either');
checkSame(false, $rAdmin->resetPassword(9999, 'a long enough password')->isOk(),
          'an account number nobody has is refused rather than reported as reset');

$res = $rAdmin->resetPassword(2, 'a long enough password');
checkSame(true, $res->isOk(), 'a real account and a long enough password does reset');
check($hashOf(2) !== $clerkHash, 'and the stored hash actually changed');

$rAdmin->close(2, 1);
checkSame(false, $rAdmin->resetPassword(2, 'another long password')->isOk(),
          'a closed account cannot be handed a working password — closing is not undoable');

// ---- And the same rule for a Display id -----------------------------------------
// Reached straight from `$_POST['d_id']` by three of the panel's forms, one of which
// is the delete button.
$fPdo   = newTestDb();
$fSign  = makeTestDisplay($fPdo, 'lobby', 'Lobby');
$fStore = new DisplayStore($fPdo);
$fId    = $fSign->id();

check($fStore->forId($fId) !== null, 'a Display is found by its number');
check($fStore->forId((string)$fId) !== null, 'and by that number as a string, which is what a form sends');
checkSame(null, $fStore->forId($fId . 'abc'), 'but not by a number with something stuck on the end');
checkSame(null, $fStore->forId([]),   'nor by a list, which intval() reads as Display 1');
checkSame(null, $fStore->forId(true), 'nor by true, for the same reason');
checkSame(null, $fStore->forId($fId + 0.9), 'nor by a fraction that would round down onto a real one');
checkSame(null, $fStore->forId(''),   'nor by nothing at all');
checkSame(true, DisplayStore::isIdLike('7'), 'the predicate itself: digits as a string are an id');
checkSame(false, DisplayStore::isIdLike('7abc'), 'and digits with a tail are not');

// ─────────────────────────────────────────────────────────────
section('Two publishes colliding is a sentence, not a timeout (#35)');

// InnoDB waits 50 seconds for a row lock and PHP gives up after 30, so the second of
// two publishes to one Display was killed mid-wait: no result object, a truncated
// body behind a Content-Type that had already promised JSON, and "Network error." in
// the Builder for a publish whose fate nobody could tell.
check(errorSaysLockWait('HY000', 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'),
      'a MySQL lock wait timeout is recognised');
check(errorSaysLockWait('40001', 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock'),
      'and a deadlock, which the person experiences identically');
check(errorSaysLockWait('HY000', 'SQLSTATE[HY000]: General error: 5 database is locked'),
      'and SQLite\'s version of the same thing');
check(errorSaysLockWait('HY000', 'General error: 6 database table is locked'),
      'in both of the spellings it uses');

// The other half of #11's rule: a detector that says yes to everything is not a
// detector, and this is the one that used to be a bare MySQL error number.
check(!errorSaysLockWait('42S02', "Base table or view not found: 1146 Table 'x.displays' doesn't exist"),
      'a missing table is not a lock wait');
check(!errorSaysLockWait('HY000', 'SQLSTATE[HY000]: General error: 1364 Field \'email\' doesn\'t have a default value'),
      'nor is a column with no default');
check(!errorSaysLockWait('23000', 'Integrity constraint violation: 1062 Duplicate entry'),
      'nor a duplicate key');
check(!errorSaysLockWait('', ''), 'and nothing at all is not a lock wait');

$wStore = newTestDisplayStore(newTestDb());
checkSame(testIsMysql(), $wStore->limitPublishLockWait(),
          'shortening the wait is something only the engine with row locks can do');

// ─────────────────────────────────────────────────────────────
section('A reply always parses, and says what it is (#26, #28)');

/** A structure past json_encode's nesting limit — the failure with no bad byte in it. */
function deeplyNested($depth)
{
    $v = 'bottom';
    for ($i = 0; $i < $depth; $i++) { $v = [$v]; }
    return $v;
}

// ---- #26: the body -----------------------------------------------------------
// `echo json_encode($payload)` printed the empty string whenever json_encode
// returned false, behind a 200 and a Content-Type promising JSON. The Viewer's
// r.json() rejected, its .catch kept the old layout, and the cause — a byte in the
// database — was there on the next poll and the one after that.
//
// So the property under test is not "encoding usually works". It is that no input
// produces a reply that cannot be parsed.

$plain = HttpReply::reply(['status' => 'success', 'elements' => ['a', 'b']]);
checkSame(200, $plain['code'],    'an ordinary payload is a 200');
checkSame('',  $plain['trouble'], 'with nothing to report');
checkSame(['status' => 'success', 'elements' => ['a', 'b']], json_decode($plain['body'], true),
          'and arrives as what was handed over');

// A lone continuation byte: valid in latin1, meaningless in UTF-8, and enough to
// make json_encode refuse an entire layout. It reaches the database through a
// restore or a hand edit — not through this app, because json_decode refuses it on
// the way in, which is what makes repairing it on the way out safe.
$bad = HttpReply::reply([
    'status'   => 'success',
    'display'  => ['tag' => 'drive-thru'],
    'elements' => [['manual_content' => "Sockeye \xB1 18.99"]],
]);
checkSame(false, json_encode(['x' => "Sockeye \xB1 18.99"]),
          'json_encode really does refuse one bad byte outright');
checkSame('substituted', $bad['trouble'], 'a reply holding invalid UTF-8 is repaired, not dropped');
checkSame(200, $bad['code'],              'and is still the answer the endpoint meant to give');
$decoded = json_decode($bad['body'], true);
check(is_array($decoded),                             'the repaired body parses');
checkSame('drive-thru', $decoded['display']['tag'],   'and everything that was fine is untouched');
check(strpos($decoded['elements'][0]['manual_content'], '18.99') !== false,
      'including the rest of the block the bad byte was in');
check($bad['detail'] !== '', 'and there is something to tell an admin');

// The unrepairable half. INF is not a JSON number and no flag makes it one, so the
// reply becomes a real 500 carrying a body built from a payload this app controls.
$inf = HttpReply::reply(['status' => 'success', 'value' => INF]);
checkSame('unencodable', $inf['trouble'], 'a value JSON has no form for cannot be substituted away');
checkSame(500, $inf['code'],              'so the reply becomes a real server error');
$infBody = json_decode($inf['body'], true);
check(is_array($infBody),                     'and is still JSON the caller can read');
checkSame('error',       $infBody['status'],  'that says it failed');
checkSame('unencodable', $infBody['reason'],  'and why');
check(is_string($infBody['message']) && $infBody['message'] !== '',
      'with a sentence somebody could act on');

// An explicit code is honoured — and overruled by a body that will not encode,
// because a 413 with nothing in it is no more readable than a 200 with nothing in it.
checkSame(413, HttpReply::reply(['status' => 'error'], 413)['code'], 'a given code is used');
checkSame(500, HttpReply::reply(['status' => 'error', 'v' => NAN], 413)['code'],
          'unless the body could not be built, which is a 500 whatever was asked for');

// The invariant itself, over everything that has ever broken an encode here.
foreach ([
    'an empty payload'          => [],
    'a bare list'               => ['a', 'b', 'c'],
    'invalid UTF-8 in a key'    => ["k\xB1" => 'v'],
    'invalid UTF-8 in a value'  => ['k' => "v\xC3\x28"],
    'a truncated multi-byte'    => ['k' => "price \xE2\x82"],
    'infinity'                  => ['k' => INF],
    'negative infinity'         => ['k' => -INF],
    'not a number'              => ['k' => NAN],
    'both at once'              => ['k' => "\xB1", 'n' => NAN],
    'nested past the limit'     => ['k' => deeplyNested(600)],
] as $what => $payload) {
    $r = HttpReply::reply($payload);
    check($r['body'] !== '' && json_decode($r['body']) !== null,
          $what . ' still leaves as JSON that parses');
}

// ---- #26: and the admin hears about it ---------------------------------------
// "No notice" is half the item. A sign that repairs itself quietly is a sign whose
// content is wrong and nobody knows.
$replyLog = newTestStateDir() . '/lbm-error.log';
ErrorPolicy::useLogFile($replyLog);
ob_start();
HttpReply::json(['status' => 'success', 'display' => ['tag' => 'drive-thru'],
                 'elements' => [['manual_content' => "\xB1"]]]);
$sent = ob_get_clean();
check($sent !== '' && json_decode($sent) !== null, 'json() puts a parseable body on the wire');
$written = (string)@file_get_contents($replyLog);
check(strpos($written, 'not valid UTF-8') !== false, 'and writes down that it had to repair one');
check(strpos($written, 'drive-thru') !== false,      'naming the sign an admin has to go and open');

// Throttled, or a Screen polling every 30 seconds writes 2,880 of these a day into a
// log that rotates at 2 MB.
@file_put_contents($replyLog, '');
ob_start();
HttpReply::json(['status' => 'success', 'display' => ['tag' => 'drive-thru'],
                 'elements' => [['manual_content' => "\xB1"]]]);
ob_end_clean();
checkSame('', (string)@file_get_contents($replyLog),
          'the second identical repair inside the hour says nothing');
ErrorPolicy::useLogFile('');

// The same defect wearing a different hat. viewer.php prints the screen name tag
// into its script through a short-echo tag; when the encode fails that emits
// `var TAG = ;`, a parse error that takes the Viewer's whole script down and leaves
// a blank television. (Written in prose rather than quoted, because a closing PHP
// tag inside a comment ends the file.)
checkSame('"drive-thru"', HttpReply::jsValue('drive-thru'), 'an ordinary tag is a JS string');
check(HttpReply::jsValue("\xB1") !== '',       'and a tag that will not encode is still something');
check(json_decode(HttpReply::jsValue("\xB1")) !== null,
      'that a JavaScript parser can read, rather than a gap in the statement');

// All four characters, one check each (#50). The flags are a single `|` chain, so one
// character mistyped as `&` collapses two of them to nothing — and the suite could not
// tell, because nothing named the characters. Each of the four ends something: `<`
// ends the script element as far as the HTML parser is concerned, `'` and `"` end a
// string literal, and `&` starts an entity the attribute parser will decode before the
// JavaScript parser ever looks (§4ah).
checkSame('"\u003Cb\u003E"', HttpReply::jsValue('<b>'),
          'an angle bracket cannot end the script element it is inside');
checkSame('"o\u0027brien"', HttpReply::jsValue("o'brien"),
          'a single quote cannot end the string literal — the username #15 is named after');
checkSame('"say \u0022hi\u0022"', HttpReply::jsValue('say "hi"'),
          'nor can a double quote, whichever kind the call site used');
checkSame('"a\u0026b"', HttpReply::jsValue('a&b'),
          'and an ampersand cannot start an entity for the attribute parser to decode first');

// The floor under all four. `JSON_INVALID_UTF8_SUBSTITUTE` means almost anything encodes,
// so what still cannot is a float with no JSON spelling — and the answer then has to be a
// literal the parser accepts, because it is being printed into the middle of somebody's
// statement. `false` would echo as the empty string and take the whole script block down,
// which is §4af's shape at the smallest scale there is.
checkSame('null', HttpReply::jsValue(INF),
          'a value with no JSON spelling at all is the literal null, not nothing');
checkSame('null', HttpReply::jsValue(NAN), 'and so is the other one');

// ---- #28: the status line ------------------------------------------------------
// Missing, unknown and switched-off signs all answered 200. Anything that does not
// read the body — a proxy, an uptime check, curl after typing a tag onto a new
// television — was told all three had worked.
checkSame(400, HttpReply::codeFor('no_tag'),    'naming no sign is a bad request');
checkSame(404, HttpReply::codeFor('unknown'),   'naming one that is not here is a 404');
checkSame(503, HttpReply::codeFor('inactive'),  'and one deliberately switched off is out of service');
check(HttpReply::codeFor('unknown') !== HttpReply::codeFor('inactive'),
      'which is the distinction #28 is about: those two are not the same answer');
checkSame(403, HttpReply::codeFor('forbidden'), 'a sign that is not this account\'s is forbidden');
checkSame(409, HttpReply::codeFor('mismatch'),  'a tag and an id that disagree is a conflict');
checkSame(409, HttpReply::codeFor('stale'),     'so is somebody having published first');
checkSame(422, HttpReply::codeFor('invalid'),   'a layout read and refused is unprocessable');
checkSame(500, HttpReply::codeFor('failed'),    'and our own failure is ours');
checkSame(400, HttpReply::codeFor('something-new'), 'a name nobody listed still is not a success');

// Derived from the payload, never chosen beside it, because a code and a reason that
// disagree would disagree silently.
checkSame(200, HttpReply::codeForPayload(['status' => 'success']), 'success is 200');
checkSame(200, HttpReply::codeForPayload(['a', 'b']),
          'and a bare list, which has no status to read, is one too');
checkSame(404, HttpReply::codeForPayload(['status' => 'error', 'reason' => 'unknown']),
          'a refusal takes the code its own reason implies');
checkSame(400, HttpReply::codeForPayload(['status' => 'error', 'message' => 'no reason given']),
          'and a refusal that did not say why is still not a 200');

// Every word the app actually uses, and which code it maps to (#50).
//
// This used to assert only that the map *had* an answer for each — `codeFor($reason, 0)
// !== 0` — and six of the fourteen rows had nothing else standing over them:
// `not_found`, `signed_out`, `locked`, `busy`, `too_large` and `unencodable` could each
// be moved to a neighbouring code with the suite green. A loop over a table that
// asserts its keys reads from the outside exactly like one that asserts the table, and
// that is the shape decision #50 is about. The `0` default is kept as the sentinel, so a
// reason added to a module and never listed here still fails rather than quietly
// becoming a 400.
$expectedCodes = [
    DisplayResolution::NO_TAG    => 400,   // nothing named a sign
    DisplayResolution::UNKNOWN   => 404,   // named one that is not here
    DisplayResolution::INACTIVE  => 503,   // here, and deliberately not serving (#28)
    DisplayResolution::FORBIDDEN => 403,
    DisplayResolution::MISMATCH  => 409,
    'stale'       => 409,                  // PublishResult: somebody published first
    'locked'      => 409,                  // somebody else holds the sign
    'busy'        => 409,                  // two publishes at once (#35)
    'invalid'     => 422,                  // read, understood, refused
    'failed'      => 500,                  // ours
    'not_found'   => 404,                  // ElementResult, about one block
    'signed_out'  => 403,                  // api.php's own
    'too_large'   => 413,
    'unencodable' => 500,                  // the reply itself would not encode
];
foreach ($expectedCodes as $reason => $expected) {
    checkSame($expected, HttpReply::codeFor($reason, 0),
              'the map answers ' . $expected . ' for "' . $reason . '"');
}

// The three that share a code are sharing it on purpose, and the two that must never
// share one are the whole of #28. Written as comparisons because that is the property —
// a table where every row happened to be 409 would pass the loop above.
check(HttpReply::codeFor('locked') === HttpReply::codeFor('stale'),
      'a held sign and a moved stamp are the same kind of answer: two truths that cannot both hold');
check(HttpReply::codeFor('too_large') !== HttpReply::codeFor('invalid'),
      'while a body too big to arrive and one read and refused are not — only the second was read');
check(HttpReply::codeFor('signed_out') === HttpReply::codeFor(DisplayResolution::FORBIDDEN),
      'and a session that ended is the same answer as a sign that is not yours: ask again with credentials');

// The two entry points that answer in HTML rather than JSON ask the same question of
// the same map, through the resolution they already hold.
$codePdo   = newTestDb();
$codeStore = newTestDisplayStore($codePdo);
makeTestDisplay($codePdo, 'code-on', 'On');
$offDisplay = makeTestDisplay($codePdo, 'code-off', 'Off');
$codeStore->setActive($offDisplay, false);

checkSame(200, HttpReply::codeForResolution(
              DisplayRequest::forViewing($codeStore, ['display' => 'code-on'])),
          'a sign that renders is a 200');
checkSame(404, HttpReply::codeForResolution(
              DisplayRequest::forViewing($codeStore, ['display' => 'no-such-sign'])),
          'a tag nothing answers to is a 404');
checkSame(503, HttpReply::codeForResolution(
              DisplayRequest::forViewing($codeStore, ['display' => 'code-off'])),
          'and one an admin turned off says so, so it can be told from the typo');
checkSame(400, HttpReply::codeForResolution(DisplayRequest::forViewing($codeStore, [])),
          'a URL that named nothing is a bad request');
checkSame(400, HttpReply::codeForResolution(
              DisplayRequest::forViewing($codeStore, ['display' => ['code-on']])),
          'and so is one whose parameter is not a tag at all (#27)');

// ---- #28: the caching rules ----------------------------------------------------
// Nothing anywhere set one. That mattered least while every answer was a 200 and
// matters immediately now that some are 404s, which are heuristically cacheable by
// default where an unlabelled 200 with no validator is not — so fixing the codes
// without fixing this would have made a mistyped tag stickier than it was.
$cacheLines = HttpReply::cacheHeaders();
check(count($cacheLines) === 3, 'the caching rules are three header lines');
$cacheText = implode(' | ', $cacheLines);
check(stripos($cacheText, 'no-store') !== false, 'and the modern one is among them');
check(stripos($cacheText, 'Pragma: no-cache') !== false,
      'with the HTTP/1.0 spelling beside it, for the proxy that has not heard of the first');
check(stripos($cacheText, 'Expires: 0') !== false, 'and an expiry already in the past');
foreach ($cacheLines as $line) {
    check(strpos($line, ': ') !== false && strpos($line, "\n") === false,
          '"' . $line . '" is one well-formed header');
}

// ─────────────────────────────────────────────────────────────
section('Putting a stored value on a page, once and the same way (#15)');

// 159 calls to htmlspecialchars() with no flags between them, and the default flag
// set is not one behaviour: before PHP 8.1 it left `'` alone, from 8.1 it escapes it.
// So the same source was safe or an injection depending on the host, and nothing said
// which was meant. The flags are named once now, and this asserts the two that matter
// rather than the fact that some flags were passed.

checkSame('&lt;script&gt;', Markup::text('<script>'), 'a tag is escaped, not stripped');
checkSame('&quot;', Markup::text('"'),  'a double quote cannot end an attribute');
checkSame('&#039;', Markup::text("'"),  'and neither can a single one — the half the old default left');
checkSame('&amp;lt;', Markup::text('&lt;'), 'an ampersand is escaped once, so nothing is double-decoded');
checkSame('&#039;', Markup::text("'"),  'as a numeric entity, which every parser knows');

// The quieter half of the default, and the one that costs a price rather than
// leaking one: without ENT_SUBSTITUTE, htmlspecialchars() answers '' for a value
// holding one byte of invalid UTF-8. Not the value, not an error — nothing. That is
// #26's shape, on a page instead of in a reply.
$badByte = "Sockeye 18.99 \xB0";
check(Markup::text($badByte) !== '', 'one bad byte does not blank the whole value');
checkMentions(Markup::text($badByte), 'Sockeye 18.99', 'the price still reaches the page');

// Non-strings answer '' rather than being cast, for the reason Color::read() does.
$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
$arrayAnswer = Markup::text(['x']);
$nullAnswer  = Markup::text(null);
restore_error_handler();
checkSame('', $arrayAnswer, 'a list has nothing to escape and prints nothing');
checkSame('', $nullAnswer,  'and neither does a column that was null');
checkSame(false, $warned,   'without an "Array to string conversion" warning above the page, '
                          . 'or a deprecation notice logged on every load');
checkSame('42', Markup::text(42), 'a number is text and passes through');

// ---- The confirm box #15 names ---------------------------------------------------
// The flags were never the half that mattered here. `htmlspecialchars()` inside an
// event attribute looks escaped and is not: the HTML parser decodes the attribute
// before the JavaScript parser sees it, so the &#039; that ENT_QUOTES just produced
// is a plain quote again by the time it is a string literal, and the string ends.
$hostile = "o'brien');alert(1);//";
$asHtml  = Markup::text($hostile);
checkMentions($asHtml, '&#039;', 'HTML escaping turns the quote into an entity');
check(strpos(html_entity_decode($asHtml, ENT_QUOTES, 'UTF-8'), "');") !== false,
      'which the HTML parser hands back as a quote — the whole defect, in one line');

$asJs = Markup::jsInAttr($hostile);
checkSame(false, strpos($asJs, "'") !== false, 'the JavaScript form contains no quote at all');
checkSame(false, strpos($asJs, '<') !== false, 'nor an angle bracket that could end the attribute');
check(strpos(html_entity_decode($asJs, ENT_QUOTES, 'UTF-8'), "');") === false,
      'and decoding the attribute the way a browser does still yields no quote');
checkSame('"o\u0027brien\u0027);alert(1);\/\/"',
          html_entity_decode($asJs, ENT_QUOTES, 'UTF-8'),
          'what the JavaScript parser finally sees is one string literal, the quotes inside it '
        . 'spelled as escapes it will never mistake for the end of anything');

// The page must pass the value as the whole argument. Spliced into a longer string it
// would be a JSON literal in the middle of one, which is why the sentence lives in
// the function and admin_panel.php passes only the name.
$panel = file_get_contents(__DIR__ . '/../admin_panel.php');
checkMentions($panel, 'confirmCloseAccount(<?= Markup::jsInAttr($u[\'username\']) ?>)',
              'the close-account confirm passes the username as a value, not as text');
// And nowhere on that page is a value escaped for HTML and then dropped into a
// JavaScript string literal, which is the construction rather than the instance.
// `tools/check_invariants.php` holds every page to it; this is the one it came from.
checkSame(0, preg_match('/on[a-z]+="[^"]*\'[^"]*Markup::/i', $panel),
          'and no event attribute on that page splices an escaped value into a JS string');

// ─────────────────────────────────────────────────────────────
section('Finding the colours nobody can read, before a publish finds them (#41)');

// #41 closed the way an unreadable colour was written and re-written. What it could
// not close is the rows that were already there: a `font_color` holding `puce` makes
// its Display refuse every publish, with the block named, and the only way to learn
// that was for somebody to press Publish and be told no — mid-change, in front of the
// sign they came to fix. ColorAudit asks the same question of the whole database,
// changes nothing, and reports.
//
// Every bad value below is written with a direct UPDATE, because that is the only way
// one can exist: every door the app has refuses it now. Which is also the point — the
// audit is for the rows that came in before the doors did, or beside them.

$aPdo   = newTestDb();
$aStore = new DisplayStore($aPdo);
$aLay   = newTestLayoutStore($aPdo);
// The fourth argument is the store's own branding, which lives in a file rather than
// the database. Named here rather than left to default, or every count below would
// depend on what `branding_config.php` happens to hold on the machine running this.
$aBrand = SiteChrome::DEFAULTS;
$aAudit = new ColorAudit($aStore, $aLay, new BrandStyles($aPdo), new BrandStore($aPdo), $aBrand);

$aDrive = makeTestDisplay($aPdo, 'drive-thru', 'Drive-Thru Menu');
$aPatio = makeTestDisplay($aPdo, 'patio', 'Patio Board');
publishAs($aLay, $aDrive, layoutWith('Sockeye 18.99'), '0');
publishAs($aLay, $aPatio, layoutWith('Crab 14.00', 's2'), '0');

checkSame([], $aAudit->findings(), 'a database whose colours all read reports nothing at all');

// One block, by hand, exactly as a row edited outside the app would look.
$aBad = null;
foreach (elementsOf($aPdo, $aDrive->id()) as $row) {
    if ($row['type'] === 'text') { $aBad = $row['id']; }
}
$aPdo->prepare("UPDATE canvas_elements SET font_color = 'puce' WHERE id = ?")->execute([$aBad]);

$found = $aAudit->findings();
checkSame(1, count($found), 'one hand-edited colour is one finding');
checkSame(ColorAudit::BLOCKS_PUBLISH, $found[0]['kind'], 'and it is the kind that stops a publish');
checkSame('drive-thru', $found[0]['scope'], 'named by the tag somebody would open');
checkSame('puce', $found[0]['value'],       'quoting what is actually stored');
checkMentions($found[0]['what'], 'price',   'and pointing at the block by what it is');
checkMentions($found[0]['what'], '5,5',     'and by where it sits on the canvas');

// The claim the finding makes, made good. If the door did not refuse this layout the
// audit would be raising an alarm about nothing, and the two would have drifted apart
// without either one being wrong on its own.
$aRefusal = LayoutRules::check([
    ['type' => 'text', 'block_subtype' => 'price', 'font_color' => 'puce',
     'x_pos' => 5, 'y_pos' => 5, 'width' => 160, 'height' => 60],
]);
checkSame(false, $aRefusal->isOk(), 'and the publish door really does refuse that same value');

// A section has no text of its own, so the door does not check its colour and neither
// does this. Reporting it would send somebody to a block with nothing to fix.
$aSection = null;
foreach (elementsOf($aPdo, $aPatio->id()) as $row) {
    if ($row['type'] === 'section') { $aSection = $row['id']; }
}
$aPdo->prepare("UPDATE canvas_elements SET font_color = 'nonsense' WHERE id = ?")->execute([$aSection]);
checkSame(1, count($aAudit->findings()), 'a section carrying an unreadable colour is not a finding');

// Blank is legal. It means "no colour of its own", which is what every branded block
// carries — the absent-versus-unreadable line #21 turns on, and a predicate that
// missed it would report the whole store.
$aPdo->prepare("UPDATE canvas_elements SET font_color = '' WHERE id = ?")->execute([$aBad]);
checkSame([], $aAudit->findings(), 'and a blank colour is not a fault, it is a branded block');
$aPdo->prepare("UPDATE canvas_elements SET font_color = 'puce' WHERE id = ?")->execute([$aBad]);

// A hidden block is not on the sign and is still in the payload, so it refuses the
// publish from somewhere nobody is looking. The finding says so.
$aPdo->prepare("UPDATE canvas_elements SET hidden = 1 WHERE id = ?")->execute([$aBad]);
checkMentions($aAudit->findings()[0]['what'], 'hidden',
              'a hidden block that blocks the publish is reported as hidden');
$aPdo->prepare("UPDATE canvas_elements SET hidden = 0 WHERE id = ?")->execute([$aBad]);

// ---- The two that never refuse anything ----------------------------------------
// Both render. That is what makes them worse to find and worth separating: nothing
// stops, nobody is told, and the sign is simply the wrong colour.

$aPdo->prepare("UPDATE displays SET bg_val = 'darkblue' WHERE id = ?")->execute([$aPatio->id()]);
$found = $aAudit->findings();
checkSame(2, count($found), 'a background nobody can read is the second finding');
checkSame(ColorAudit::WRONG_ON_SIGN, $found[1]['kind'], 'of the kind that renders wrongly rather than refusing');
checkSame('patio', $found[1]['scope'], 'against the sign it is on');
checkMentions($found[1]['consequence'], '#1a1a2e', 'saying which colour the Screen shows instead');

// A Display on an image background still carries whatever bg_val held before the
// switch. Nothing reads it, so reporting it would send somebody to fix a sign that is
// not wrong.
$aPdo->prepare("UPDATE displays SET bg_type = 'image', bg_val = 'uploads/patio.jpg' WHERE id = ?")
     ->execute([$aPatio->id()]);
checkSame(1, count($aAudit->findings()), 'a Display showing an image is not asked about its colour');

// Brand Standards are shared, so one row is every sign at once — and this is the only
// one of the three with no refusal anywhere in front of it. BrandStyles cleans on the
// way in, not on the way out, so a row edited by hand goes to every Screen as it is.
$aPdo->prepare("UPDATE block_styles SET font_color = 'gold' WHERE block_type = 'price'")->execute();
$found = $aAudit->findings();
checkSame(2, count($found), 'a Brand Standards colour nobody can read is a finding too');
checkSame('', $found[1]['scope'], 'belonging to no one sign, because it belongs to all of them');
checkMentions($found[1]['what'], 'price', 'named by the block style it governs');
checkMentions($found[1]['consequence'], 'every sign', 'and saying that is what it means');

// Ordering is the report's whole shape: a list that opens with a cosmetic finding
// reads like a tidy-up rather than like a sign that cannot be published.
checkSame(ColorAudit::BLOCKS_PUBLISH, $found[0]['kind'], 'the blocking finding is listed first');

// ─────────────────────────────────────────────────────────────
section('The colours that are not in the database at all (#15, second half)');

// `branding_config.php` is a generated PHP file the Admin Panel writes and its own
// header invites a person to edit. What it holds is interpolated into the `<style>`
// block on the Builder, the Help page and the sign-in page — the one place in this
// app where escaping is the wrong tool, because a `<style>` has no delimiter for an
// entity to neutralise and a value that is not a colour is simply more CSS.
//
// Every check below goes through `SiteChrome::pick()` rather than the constants, for the
// reason the module says: `define()` cannot be undone, so a rule reachable only
// through the constants could only ever be tested with the one value this machine
// holds.

checkSame('#3498db', SiteChrome::pick('accent', '#3498db'), 'a colour that reads is the colour');
checkSame('#3498db', SiteChrome::pick('accent', '#3498DB'), 'in the one case this app stores it in');
checkSame('#3498db', SiteChrome::pick('accent', null),      'an absent value is the documented default');
checkSame('#3498db', SiteChrome::pick('accent', ''),        'and so is a blank one');
checkSame('#1a252f', SiteChrome::pick('nav_bg', 'darkblue'),
          'a CSS colour keyword is not a colour this app stores, so it is the default');
checkSame('#3498db', SiteChrome::pick('accent', ['#fff']),
          'and neither is an array, which is what a hand-built config could hold');

// The shape that made this worth doing: escaped, it is still a closed rule and a new
// one, because nothing in a stylesheet is looking for an entity.
$aInject = '#fff; } body { background: url(https://example.invalid/x)';
checkSame('#3498db', SiteChrome::pick('accent', $aInject),
          'a value that closes the rule and opens another is refused, not escaped');
checkSame(true, strpos(Markup::text($aInject), 'body {') !== false,
          'which matters because escaping leaves that value doing exactly what it said');

// Each colour falls back to its own default, not to one shared "some colour".
checkSame('#0d1b24', SiteChrome::pick('nav_border', 'nope'), 'the border falls back to the border default');
// The role is `nav_text` since step 5 named all thirteen of them consistently; the
// *method* is still `SiteChrome::text()`, because every page and every check says so
// and the point of that step was that no call site changes.
checkSame('#ffffff', SiteChrome::pick('nav_text', 'nope'),   'and the nav text to its own');

$aThrew = false;
try { SiteChrome::pick('no_such_colour', '#ffffff'); } catch (Throwable $e) { $aThrew = true; }
checkSame(true, $aThrew, 'a colour this app does not have is a mistake, not an answer');

// ---- The Display Branding forms and their handler agree about field names ----
// Grep-shaped on purpose, and it is worth saying what that does and does not buy.
// Nothing here renders the page, so this cannot see a broken layout — that is the
// browser pass's job and it is owed for this tab. What it *can* see is the failure a
// rendered page would not shout about either: a form posting `b_palette_1_unset` at a
// handler reading `b_palette_1_clear` looks perfectly fine on screen and silently
// drops what somebody typed. The two halves are written 700 lines apart in one file,
// which is exactly the distance a rename survives.
foreach (['b_name', 'b_id', 'b_bg', 'b_logo', 'b_confirm_name'] as $bF) {
    check(strpos($panel, 'name="' . $bF . '"') !== false, 'the Display Branding form posts ' . $bF);
    check(strpos($panel, "\$_POST['" . $bF . "']") !== false,
          'and the handler reads it back under the same name');
}

// The palette's twelve fields are not literals on either side: the form emits them
// from `BrandStore::paletteFields()` and the handler reads them back from the same
// list. That is stronger than matching names — one list means they cannot drift — so
// what is asserted is that both halves really do come from it, rather than one of
// them having been spelled out by hand at some point and left behind.
checkMentions($panel, 'BrandStore::paletteFields()', 'the palette form is built from the one list of slots');
checkSame(2, substr_count($panel, 'BrandStore::paletteFields()'),
          'and so is the handler that reads it back — both halves, no third copy');
checkMentions($panel, "\$_POST['b_' . \$_pf . '_unset']",
              'the handler reads each slot\'s empty tick, which is how "no colour" is said at all');

// The three actions the tab offers, each reaching its use case rather than SQL.
foreach (['action_create_brand', 'action_save_brand', 'action_delete_brand'] as $bA) {
    checkMentions($panel, "\$_POST['" . $bA . "']", 'the panel handles ' . $bA);
}
checkMentions($panel, '$brandAdmin->create(',        'creating a Brand goes through BrandAdmin');
checkMentions($panel, '$brandAdmin->updateDetails(', 'and so does saving one');
checkMentions($panel, '$brandAdmin->destroy(',       'and destroying one');
checkMentions($panel, 'editedByAnyoneElseUsingBrand',
              'and the lock refusal is the narrowed one, not the old install-wide question');
check(strpos($panel, 'editedByAnyoneElse(') === false,
      'the install-wide refusal is gone from the panel entirely, not merely unused');

// What the Branding tab and the audit both read.
checkSame([], SiteChrome::unreadable(SiteChrome::DEFAULTS), 'a config that reads has nothing to report');
checkSame([], SiteChrome::unreadable([]),              'and neither has one that defines nothing yet');
$aBadCfg = SiteChrome::unreadable(['accent' => 'puce'] + SiteChrome::DEFAULTS);
checkSame(1, count($aBadCfg),        'one value nobody can read is one thing to report');
checkSame('accent', $aBadCfg[0]['key'],   'named by the field it is');
checkSame('Accent', $aBadCfg[0]['label'], 'in the words the Branding form uses');
checkSame('puce',   $aBadCfg[0]['value'], 'quoting what is actually in the file');
// Defined-and-blank is not the same silence as never defined, and the line separating
// them is one character wide: `=== null` rather than `== null`, which would read a
// blank `define()` as an absent one. A colour somebody deleted the value out of is a
// line in the file with nothing in it, and the person who did that is the one who
// wants telling — the default is what gets painted either way, so nothing else in
// the app can distinguish these two and say so.
$aBlankCfg = SiteChrome::unreadable(['accent' => ''] + SiteChrome::DEFAULTS);
checkSame(1, count($aBlankCfg), 'a colour defined as blank is reported, not treated as undefined');
checkSame('', $aBlankCfg[0]['value'], 'quoting the nothing that is stored');

// And it reaches the same report every other unreadable colour reaches, under a kind
// of its own — because this one is the only colour in the app that no sign uses, and
// a finding that read like the others would send somebody to the shop floor over a
// navigation bar.
$aCfgAudit = new ColorAudit($aStore, $aLay, new BrandStyles($aPdo), new BrandStore($aPdo),
                            ['accent' => 'puce'] + SiteChrome::DEFAULTS);
$found = $aCfgAudit->findings();
checkSame(3, count($found), 'a brand colour nobody can read joins the audit');
checkSame(ColorAudit::WRONG_IN_APP, $found[2]['kind'], 'under the kind that touches no sign');
checkSame('', $found[2]['scope'], 'belonging to no Display');
checkMentions($found[2]['what'], 'branding_config.php', 'named by the file a person would open');
checkMentions($found[2]['consequence'], '#3498db', 'saying which colour is being drawn instead');
checkMentions($found[2]['consequence'], 'nothing on the shop floor',
              'and saying plainly that no sign is affected');
checkMentions($found[2]['fix'], 'Branding', 'pointing at the tab that rewrites the file');

// The blocking finding is still first. A new kind appended to the list must not have
// moved the one that stops a sign being published.
checkSame(ColorAudit::BLOCKS_PUBLISH, $found[0]['kind'], 'and the blocking finding is still first');

// ─────────────────────────────────────────────────────────────
section('The row a page draws, which is not always the row (#15, third half)');

// BrandStyles cleans on the way in. That is a promise about rows this app wrote and
// about no others — and the Admin Panel's live preview put six of those fields
// straight into a `style` attribute. Escaping stops a value ending the *attribute*.
// Nothing stopped it ending the *declaration* inside it, which is one boundary
// further in than §4ai's and the same mistake.

$aRaw = ['block_type' => 'price', 'font_family' => 'Arial', 'font_size' => 30,
         'font_color' => '#e74c3c', 'font_weight' => 'bold', 'font_style' => 'normal',
         'line_height' => '1.20'];

checkSame([], BrandStyles::unrenderable($aRaw), 'a row the app itself wrote has nothing to report');
checkSame('#e74c3c', BrandStyles::readable($aRaw)['font_color'], 'and reads back as itself');
checkSame('Arial',   BrandStyles::readable($aRaw)['font_family'], 'in every field');

// The two the sweep found. Both were escaped, and escaping was the wrong tool for
// both: what is inside a `style` attribute is CSS, and `;` is its separator.
$aCss = ['font_family' => 'Arial; position: fixed; top: 0'] + $aRaw;
checkSame('Arial', BrandStyles::readable($aCss)['font_family'],
          'a font family carrying a second declaration is refused, not escaped');
checkSame(true, strpos(Markup::text('Arial; position: fixed; top: 0'), 'position: fixed') !== false,
          'which matters because escaping leaves that value doing exactly what it said');

$aBadCol = ['font_color' => 'gold'] + $aRaw;
checkSame('#ffffff', BrandStyles::readable($aBadCol)['font_color'],
          'a colour keyword the CSSOM would discard reads as the substitute a save would store');
checkSame('#000000', BrandStyles::readable(['block_type' => 'price'])['font_color'],
          'while a colour that is simply absent reads as the column default — a different question');

// And the page says so, rather than drawing the substitute and looking deliberate.
$aSaid = BrandStyles::unrenderable($aBadCol);
checkSame(1, count($aSaid),                'one field nobody can use is one thing to report');
checkSame('font_color', $aSaid[0]['field'], 'named by the column it is');
checkSame('Colour',  $aSaid[0]['label'],    'in the words the form puts above it');
checkSame('gold',    $aSaid[0]['value'],    'quoting what is actually stored');
checkSame('#ffffff', $aSaid[0]['instead'],  'and saying what every sign is drawing instead');

checkSame(1, count(BrandStyles::unrenderable($aCss)), 'a font family that cannot be used is reported too');

// Clamped is not the same as unusable, and both have to be said. A size of 0 is
// invisible on a sign; the clamp is what stops that, and the report is what stops it
// being silent.
$aTiny = ['font_size' => 0] + $aRaw;
checkSame(8, BrandStyles::readable($aTiny)['font_size'], 'a size of zero is clamped to the smallest that shows');
checkSame('Size', BrandStyles::unrenderable($aTiny)[0]['label'], 'and the clamp is reported, not swallowed');

// The engines disagree about how a DECIMAL(4,2) comes back — MySQL says '1.20' and
// SQLite says 1.2 — and a difference of engine is not a fault to put in front of an
// admin. Compared as numbers for exactly that reason.
checkSame([], BrandStyles::unrenderable(['line_height' => '1.20'] + $aRaw),
          'a line height stored as 1.20 is not a finding');
checkSame([], BrandStyles::unrenderable(['line_height' => 1.2] + $aRaw),
          'and neither is the same one stored as 1.2');
checkSame([], BrandStyles::unrenderable(['font_size' => '30'] + $aRaw),
          'nor a size the driver handed back as a string');

// Absent is not wrong: a column added after a row was written has no value here, and
// the documented default is the right answer with nothing for anybody to go and fix.
checkSame([], BrandStyles::unrenderable(['block_type' => 'price']),
          'a row missing every field is not six findings');
checkSame('Arial', BrandStyles::readable([])['font_family'], 'it reads as the app\'s documented values');
checkSame(16, BrandStyles::readable([])['font_size'],        'including the size, rather than the clamp');

// The reader and the writer have to agree, or a page draws one thing and the next
// save stores another — which is worse than either alone, because nothing says so.
foreach ([['font_family', 'Arial; }'], ['font_color', 'puce'], ['font_size', 900],
          ['font_weight', 'heavy'], ['font_style', 'oblique'], ['line_height', 40]] as $aPair) {
    $aRow = [$aPair[0] => $aPair[1]] + $aRaw;
    $aSaveStore = new BrandStyles(newTestDb());
    $aSaveStore->save(1, ['price' => $aRow]);
    $aDrawn = BrandStyles::readable($aRow)[$aPair[0]];
    $aKept  = $aSaveStore->all(1)['price'][$aPair[0]];
    // Numerically for the two numeric columns, by the same rule unrenderable() uses:
    // readable() answers what CSS takes — a float — and the column answers what a
    // DECIMAL(4,2) round-trips to, which is '5.00' on MySQL and 5 on SQLite. The
    // property is that they are the same value, not that they are spelled alike.
    $aAgree = in_array($aPair[0], ['font_size', 'line_height'], true)
            ? (floatval($aDrawn) === floatval($aKept))
            : ($aDrawn . '' === $aKept . '');
    checkSame(true, $aAgree,
              'what the form draws for ' . $aPair[0] . ' is what a save would store');
}

// And `all()` stays raw, because ColorAudit reads it. A source that had already been
// tidied would report nothing and would be believed.
$aRawPdo = newTestDb();
$aRawPdo->prepare("UPDATE block_styles SET font_color = 'gold' WHERE block_type = 'price'")->execute();
checkSame('gold', (new BrandStyles($aRawPdo))->all(1)['price']['font_color'],
          'all() hands back what is stored, not what renders');

// And the page that draws them uses the reader, not the row. Cross-file, because the
// defect was never in this module — it was in a caller trusting a promise the module
// had only made about values it wrote itself.
checkMentions($panel, 'BrandStyles::readable($stored)',
              'the Brand Standards preview draws the row through the reader');
checkSame(0, preg_match('/\$\w+\[.font_family.\]\s*\?\?/', $panel),
          'and no longer reaches into the row for a field with a default beside it');
checkMentions($panel, 'BrandStyles::unrenderable(',
              'and works out which stored values it could not use');
// Computing that list and not drawing it is the same page as before with more code in
// it, so the check is on the render rather than on the loop above it.
checkMentions($panel, 'if ($styleBad):',
              'and puts them on the tab, which is the whole point of working them out');

// ─────────────────────────────────────────────────────────────
section('A Workspace Theme paints a person, and a store default paints everybody else');

// The second of CONTEXT.md's two nouns (v2 roadmap decision 1, step 5). What is being
// checked here is a *resolution*: three layers — a worn theme, then
// `branding_config.php`, then the documented default — with one direction and one
// function that knows the order. It matters because the two ways it could go wrong are
// both silent. A layer read in the wrong order paints a screen the colour of somebody
// else's preference; a layer read where it does not belong let the Branding form offer
// a theme's colours as the store's and save them over the shop's own.
//
// `SiteChrome::wear(null)` is left set at the end of each part on purpose. This is one
// process, the static outlives a section, and a check further down this file that
// happened to ask for a colour would otherwise be answered by whatever the last theme
// here was — which is the class of defect §4am's mutation run exists to find.

$tPdo   = newTestDb();
$tStore = new WorkspaceThemeStore($tPdo);

// ---- The store default: no theme, no row, no change --------------------------------
SiteChrome::wear(null);
checkSame(0, $tStore->count(), 'a database that has converged has no themes in it at all');
checkSame(null, SiteChrome::worn(), 'and nothing is being worn');
checkSame(SiteChrome::DEFAULTS['work_area'], SiteChrome::workArea(),
          'so the work area is the colour it was a literal in builder.php');
checkSame(SiteChrome::DEFAULTS['status_bad'], SiteChrome::statusBad(),
          'and so is every status colour');
checkSame(SiteChrome::DEFAULTS['selection'], SiteChrome::selection(),
          'and the selection outline');

// The thirteen and the table agree. Two lists, one of them readable only by MySQL, so
// the check is that the plan's own statement names a column for every role — a role
// added to ROLES with no column would resolve to its default on every screen for ever
// and nothing else here would notice.
$tCreate = '';
foreach (signageSchemaPlan(SchemaFacts::unknown()) as $tEntry) {
    if (isset($tEntry['sql']) && strpos($tEntry['sql'], 'CREATE TABLE IF NOT EXISTS workspace_themes') !== false) {
        $tCreate = $tEntry['sql'];
    }
}
check($tCreate !== '', 'the plan carries a statement creating workspace_themes');
checkSame(13, count(SiteChrome::ROLES), 'there are thirteen chrome roles');
checkSame(count(SiteChrome::ROLES), count(SiteChrome::DEFAULTS),
          'and every one of them has a documented default');
foreach (SiteChrome::ROLES as $tRole => $tMeta) {
    check(preg_match('/^\s*' . preg_quote($tRole, '/') . '\s+VARCHAR\(7\)\s+NOT NULL DEFAULT \'([^\']+)\'/m',
                     $tCreate, $tHit) === 1,
          'the table has a NOT NULL column for ' . $tRole);
    checkSame(SiteChrome::DEFAULTS[$tRole], isset($tHit[1]) ? $tHit[1] : '',
              'and the column starts where the documented default is');
    check($tMeta[0] !== '', 'and the role has words a person can pick it by');
}
checkSame(13, preg_match_all('/VARCHAR\(7\)/', $tCreate),
          'and the table has no fourteenth colour column that no role names');

// The four that Site Branding still owns, and the nine that are a theme's alone.
$tConfigBacked = array_keys(SiteChrome::FIELDS);
checkSame(4, count($tConfigBacked), 'four roles are backed by branding_config.php');
foreach ($tConfigBacked as $tKey) {
    check(isset(SiteChrome::ROLES[$tKey]), $tKey . ' is one of the thirteen roles');
}
check(!isset(SiteChrome::FIELDS['selection']),
      'and the canvas selection outline is not something the Branding form can set');

// ---- A worn theme wins, per role ---------------------------------------------------
$tOne = $tStore->insert(['name' => 'Night shift', 'nav_bg' => '#101820', 'accent' => '#ffcc00',
                         'work_area' => '#050505', 'status_bad' => '#ff0000']);
check($tOne instanceof WorkspaceTheme, 'a theme can be created');
checkSame('Night shift', $tOne->name(), 'under the name it was given');
checkSame('#101820', $tOne->colorFor('nav_bg'), 'holding the colour it was given');
checkSame(SiteChrome::DEFAULTS['panel'], $tOne->colorFor('panel'),
          'and the documented default for a role the form did not carry');

SiteChrome::wear($tOne);
checkSame('#101820', SiteChrome::navBg(), 'wearing it, the nav is the theme\'s colour');
checkSame('#ffcc00', SiteChrome::accent(), 'and so is the accent');
checkSame('#050505', SiteChrome::workArea(), 'and the work area');
checkSame('#ff0000', SiteChrome::statusBad(), 'and the status colour');
checkSame($tOne->id(), SiteChrome::worn()->id(), 'and the page can say which theme it is wearing');

// The Branding form's own reads must not go through the theme. This is the defect that
// was one edit away: an admin wearing a theme opens Site Branding, is shown the
// theme's colours as "what is there now", and saves them into the store's own file.
// Written against the *worn* colour and the config's own answer rather than against
// `DEFAULTS`, which is the shape they were first written in and which was true here only
// because this container has no branding file. Both of them failed the moment the suite
// was run in a process that had one — on the live install they were asserting that the
// shop's nav is the colour the app ships with. What they mean has no `DEFAULTS` in it.
check(SiteChrome::configColor('nav_bg') !== $tOne->colorFor('nav_bg'),
      'while the Branding form is still shown what the config holds, not what is worn');
// Wearing a theme this app cannot read, because the empty list this check used to assert
// was a property of the machine rather than of the seam: it said "no findings" on a
// checkout whose config is clean, and said it while the worn theme had nothing wrong with
// it either. What it means is that a theme's bad value is not reported as the shop's.
SiteChrome::wear(new WorkspaceTheme(['id' => 0, 'name' => 'Unreadable', 'nav_bg' => 'chartreuse-ish']));
check(!in_array('chartreuse-ish', array_column(SiteChrome::unreadable(), 'value'), true),
      'and the audit still reports on the config rather than on a theme, even wearing one '
    . 'whose colour it cannot read');
SiteChrome::wear($tOne);

SiteChrome::wear(null);
checkSame(SiteChrome::configColor('nav_bg'), SiteChrome::navBg(),
          'taking the theme off puts every role back to the store default');

// ---- A theme that stores something nobody can read --------------------------------
// The column defaults make this state unreachable through the form, so it is built the
// way it would really arise: somebody in a database client.
// Four characters, not the eight 'darkblue' would take: the column is `VARCHAR(7)`, so
// MySQL's strict mode refuses the longer name outright (error 1406) and the state this
// check is about never arrives. A colour that cannot be read has to *fit* the column
// before anybody can be shown the wrong thing by it (§4bk). 'gold' is still a colour a
// browser would happily paint, which is the property that matters here.
$tPdo->prepare("UPDATE workspace_themes SET nav_bg = 'gold', panel = '' WHERE id = ?")
     ->execute([$tOne->id()]);
$tBad = $tStore->forId($tOne->id());
SiteChrome::wear($tBad);
checkSame(SiteChrome::configColor('nav_bg'), SiteChrome::navBg(),
          'an unreadable colour in a worn theme falls through to the layer under it');
checkSame('#ffcc00', SiteChrome::accent(),
          'and the roles either side of it are unaffected — the fallback is per role');
checkSame(SiteChrome::DEFAULTS['panel'], SiteChrome::panel(),
          'and a role with no config layer falls all the way to its documented default');
$tBadList = $tBad->unreadable();
checkSame(2, count($tBadList), 'and the theme can say which of its values it could not use');
checkSame('nav_bg', $tBadList[0]['key'], 'named by the role it is');
checkSame('Navigation background', $tBadList[0]['label'], 'in the words the theme form uses');
checkSame('gold', $tBadList[0]['value'], 'quoting what is actually stored');
SiteChrome::wear(null);
$tPdo->prepare("UPDATE workspace_themes SET nav_bg = '#101820', panel = '#1a252f' WHERE id = ?")
     ->execute([$tOne->id()]);

// ---- Which layer that fallback actually landed on ---------------------------------
// The three checks above cannot tell. This container has no `branding_config.php` — it
// is server-side and deliberately not in the repo (`docs/DEPLOY-SKIP.md`) — so the
// colour the shop set and the colour the app ships with are the same string here, and a
// fallback to either passes. On the live install they are a dark red and a dark slate.
// So the layering is asserted in a process built to have both, which is the same
// machinery `StoreClock`'s absent-setting branch uses and the only way anything here
// reaches this line at all: mutation moved it and every check lived (§4bf).
checkSame('#8b0000|#123456|' . SiteChrome::DEFAULTS['work_area'], inFreshProcess('
        define("BRAND_NAV_BG", "#8b0000");
        require LBM_ROOT . "/lib/workspace_themes.php";
        SiteChrome::wear(new WorkspaceTheme(["id" => 1, "name" => "T",
                                            "nav_bg" => "darkblue", "panel" => "#123456"]));
        echo SiteChrome::navBg() . "|" . SiteChrome::panel() . "|" . SiteChrome::workArea();
    '), 'an unusable theme colour paints what the shop set, a usable one paints itself, '
      . 'and a role the config has no line for paints the documented default');
// The same theme with nothing configured, which is what makes the line above about the
// config file rather than about a constant that happened to be there.
checkSame(SiteChrome::DEFAULTS['nav_bg'], inFreshProcess('
        require LBM_ROOT . "/lib/workspace_themes.php";
        SiteChrome::wear(new WorkspaceTheme(["id" => 1, "name" => "T", "nav_bg" => "darkblue"]));
        echo SiteChrome::navBg();
    '), 'and with no config file at all it lands on the documented default, one layer further down');
// And the pair further up this section, held where they can actually say something. In
// this container they compare the store default with the store default; on a branded
// install they are the difference between the Branding form showing the shop what it set
// and showing it somebody's night-shift theme, which is the save that would overwrite the
// shop's own file. Running the whole suite with the four constants defined is how both
// were found: of 2271 checks exactly two noticed, and both by failing.
checkSame('#8b0000|#101820|#8b0000', inFreshProcess('
        define("BRAND_NAV_BG", "#8b0000");
        require LBM_ROOT . "/lib/workspace_themes.php";
        SiteChrome::wear(new WorkspaceTheme(["id" => 1, "name" => "T", "nav_bg" => "#101820"]));
        echo SiteChrome::configColor("nav_bg") . "|" . SiteChrome::navBg() . "|";
        SiteChrome::wear(null);
        echo SiteChrome::navBg();
    '), 'the Branding form reads the shop\'s own colour while the page around it wears a '
      . 'theme, and taking the theme off puts that colour back rather than the shipped one');

// ---- What the browser is handed ----------------------------------------------------
// Resolved, not raw: `style.setProperty()` discards a value it cannot read in silence,
// which is §4ax's defect one boundary further out.
$tClient = $tStore->forId($tOne->id())->toClientArray();
checkSame(13, count($tClient['colors']), 'the client payload carries every role');
checkSame('#101820', $tClient['colors']['nav_bg'], 'resolved to a colour a browser will take');
$tPdo->prepare("UPDATE workspace_themes SET status_note = 'puce' WHERE id = ?")->execute([$tOne->id()]);
checkSame(SiteChrome::DEFAULTS['status_note'],
          $tStore->forId($tOne->id())->toClientArray()['colors']['status_note'],
          'and an unreadable one is resolved there too, rather than sent for the CSSOM to drop');
$tPdo->prepare("UPDATE workspace_themes SET status_note = '#7a4a12' WHERE id = ?")->execute([$tOne->id()]);

// The variable names three separate things have to agree about.
checkSame('--nav-bg', SiteChrome::varName('nav_bg'), 'a role is drawn through a named custom property');
checkSame('--status-good', SiteChrome::varName('status_good'), 'with underscores as hyphens');
$tThrew = false;
try { SiteChrome::varName('not_a_role'); } catch (Throwable $e) { $tThrew = true; }
checkSame(true, $tThrew, 'and a role this app does not have is a mistake, not a name');
$tVars = SiteChrome::styleVariables();
foreach (array_keys(SiteChrome::ROLES) as $tRole) {
    checkMentions($tVars, SiteChrome::varName($tRole) . ':', 'the :root block declares ' . $tRole);
}
// Every line of it, shape and all — which is what makes the block safe to print into a
// `<style>` unescaped: a stylesheet has no delimiter for escaping to neutralise, so the
// property that matters is that nothing here can be anything but a colour.
$tVarLines = array_filter(array_map('trim', explode("\n", $tVars)), 'strlen');
checkSame(13, count($tVarLines), 'the :root block is thirteen declarations and nothing else');
$tShapely = 0;
foreach ($tVarLines as $tLine) {
    if (preg_match('/^--[a-z-]+: #[0-9a-f]{6};$/', $tLine) === 1) { $tShapely++; }
}
checkSame(13, $tShapely, 'and every one of them is a role name and a six-digit colour');

// ---- Which theme an account is wearing ---------------------------------------------
// ---- A row older than the code ----------------------------------------------------
// Invariant 10's ordinary state: a database that has not converged has no column for a
// role, and every layer above has to read that as "this theme does not decide this one"
// rather than falling over. Built as a row rather than by dropping a column, because
// SQLite cannot drop one and the value object is what is being asked.
$tPartial = new WorkspaceTheme(['id' => 99, 'name' => 'Older than the code',
                                'nav_bg' => '#123456']);
checkSame('#123456', $tPartial->colorFor('nav_bg'), 'a role the row has is the row\'s answer');
checkSame(null, $tPartial->colorFor('status_note'),
          'and one it has no column for is null, not a warning and a blank');
checkSame([], $tPartial->unreadable(),
          'absent is not unreadable — there is nothing there for anybody to go and fix');
SiteChrome::wear($tPartial);
checkSame('#123456', SiteChrome::navBg(), 'wearing it, the role it knows is its own');
checkSame(SiteChrome::DEFAULTS['status_note'], SiteChrome::statusNote(),
          'and the one it does not know falls through to the layer underneath');
checkSame(13, count($tPartial->toClientArray()['colors']),
          'and the browser is still handed thirteen roles, not the two the row had');
SiteChrome::wear(null);

// ---- Ids that would name a different row ------------------------------------------
// Each of these is checked against a database where the mangled id **would** resolve, and
// that is the whole point: `forAccount('7abc')` on a database with no account 7 answers
// null whether the guard is there or not, which is a check that cannot fail. The mutation
// run said so — the guards survived every mutant until these named rows that exist.
checkSame(null, $tStore->forId('1abc'),
          'an id that intval() would read as an existing theme is refused, not read');
checkSame(null, $tStore->forId(0),  'nor is 0 a theme');
checkSame(null, $tStore->forId(-1), 'nor a negative one');
checkSame($tOne->id(), $tStore->forId((string)$tOne->id())->id(),
          'while the id as a string, which is how a form sends it, still works');

checkSame(null, $tStore->forAccount(1), 'an account that has chosen nothing wears the store default');
$tAccounts = new AccountStore($tPdo);
checkSame(true, $tAccounts->chooseWorkspaceTheme(1, $tOne->id()), 'an account can choose a theme');
checkSame($tOne->id(), $tStore->forAccount(1)->id(), 'and is wearing it on the next request');
checkSame(null, $tStore->forAccount(2), 'while a colleague is unaffected');
checkSame(true, $tAccounts->chooseWorkspaceTheme(1, 0), 'and "use the store default" is one write away');
checkSame(null, $tStore->forAccount(1), 'which puts them back with everybody else');
checkSame(false, $tAccounts->chooseWorkspaceTheme(99999, $tOne->id()),
          'an id that names no account is refused rather than reported as saved');
checkSame(null, $tStore->forAccount(0), 'and no account has no theme');
// Against an account that really is wearing one, so the refusal is the guard's doing
// rather than the row simply not being there.
$tAccounts->chooseWorkspaceTheme(2, $tOne->id());
checkSame($tOne->id(), $tStore->forAccount(2)->id(), 'a colleague is wearing a theme');
checkSame(null, $tStore->forAccount('2abc'),
          'and an id intval() would read as *their* account is refused, not answered with theirs');
$tAccounts->chooseWorkspaceTheme(2, 0);

// A theme somebody is wearing cannot be deleted out from under them, and the refusal
// can say whose screens it would have changed.
$tAccounts->chooseWorkspaceTheme(2, $tOne->id());
checkSame(['clerk'], $tStore->accountsUsing($tOne), 'the store can name who is wearing a theme');
$tAccounts->chooseWorkspaceTheme(1, $tOne->id());
checkSame(2, count($tStore->accountsUsing($tOne)), 'all of them, not the first one it found');
$tAccounts->chooseWorkspaceTheme(1, 0);
$tAccounts->chooseWorkspaceTheme(2, 0);
checkSame([], $tStore->accountsUsing($tOne), 'and nobody once they have moved off it');

// Closing an account frees its theme, which is the edit lock's rule one table further
// out: a change to what somebody may reach frees what they are holding. A closed account
// can never sign in to move itself off a theme, so without this the theme is pinned for
// ever by somebody who will never use it — and the Admin Panel's refusal names them.
$tAccounts->chooseWorkspaceTheme(2, $tOne->id());
checkSame(['clerk'], $tStore->accountsUsing($tOne), 'a colleague is on the theme');
$tClose = newTestAccountAdmin($tPdo)->close(2, 1);
checkSame(true, $tClose->isOk(), 'and their account is closed');
checkSame([], $tStore->accountsUsing($tOne),
          'which frees the theme — nobody is holding it who could never let go');
// Suspension is not closure, and the difference is that one of them is coming back.
$tPdo->exec("UPDATE users SET closed_at = NULL, is_active = 1 WHERE id = 2");
$tAccounts->chooseWorkspaceTheme(2, $tOne->id());
$tPdo->exec("UPDATE users SET is_active = 0 WHERE id = 2");
checkSame(['clerk'], $tStore->accountsUsing($tOne),
          'while a suspended account keeps its choice, because it may be turned back on');
$tPdo->exec("UPDATE users SET is_active = 1 WHERE id = 2");
$tAccounts->chooseWorkspaceTheme(2, 0);

// ---- Names, on a picker ------------------------------------------------------------
checkSame(null, $tStore->otherThemeNamed('Daylight'), 'a name nothing uses is free');
checkSame($tOne->id(), $tStore->otherThemeNamed('night SHIFT')->id(),
          'one that differs only in case is the same name on a list');
checkSame(null, $tStore->otherThemeNamed('Night shift', $tOne->id()),
          'and a theme may keep its own name while being renamed');
checkSame('Night shift', PickerName::clean("  Night   shift "), 'a typed name is folded, not invented');
checkSame('', PickerName::clean(['Night shift']),
          'and something that is not a string is not a name badly written');
checkSame(false, PickerName::isValid(''), 'a blank name is refused');
checkSame(false, PickerName::isValid(str_repeat('x', PickerName::MAX + 1)),
          'so is one longer than the column, rather than being truncated to fit');
checkSame(false, PickerName::isValid("Night\tshift"), 'and one with a control character in it');
checkSame(PickerName::MAX, BrandStore::NAME_MAX,
          'and a Brand asks the same rule rather than carrying a second copy of it');
// Called with nothing to excuse, which is what every caller's own default passes down.
// Asserted here because the two stores both hand `clashIn()` three arguments, so its
// own default parameter is reached from nowhere else — and a default of 1 rather than
// 0 would quietly excuse the first row anybody ever made from every clash check.
checkSame($tOne->id(), PickerName::clashIn($tStore->all(), 'Night shift')->id(),
          'clashIn() excusing nothing excuses no row, not row 1');
// A row whose name is blank, which the form cannot make and the column allows. The
// guard is what stops it being the answer to every unnamed clash check — an empty
// name matching an empty name is true, and the caller asking is a save that has not
// validated the name yet.
$tPdo->exec("INSERT INTO workspace_themes (id, name) VALUES (77, '')");
checkSame(null, $tStore->otherThemeNamed(''),
          'an empty name clashes with nothing, even with a blank-named row to match');
checkSame(null, $tStore->otherThemeNamed('   '), 'and neither does one that is only spaces');
checkSame($tOne->id(), $tStore->otherThemeNamed('Night shift')->id(),
          'while a real name still finds its row past it');
$tPdo->exec("DELETE FROM workspace_themes WHERE id = 77");

// ---- The colour rules the form leans on --------------------------------------------
checkSame('#ffcc00', WorkspaceThemeStore::cleanColor('#FFCC00'), 'a colour is stored one way');
checkSame('', WorkspaceThemeStore::cleanColor('goldenrod'), 'and something that is not one is not');
$tSubmitted = ['nav_bg' => '#ffffff', 'accent' => 'puce', 'status_warn' => ['#fff']];
$tUnread    = WorkspaceThemeStore::unreadableIn($tSubmitted);
checkSame(2, count($tUnread), 'a submitted set says everything wrong with it at once');
checkSame(true, array_key_exists('accent', $tUnread), 'naming the field that was typed wrong');
checkSame(true, array_key_exists('status_warn', $tUnread), 'and the one that was not even a string');
checkSame([], WorkspaceThemeStore::unreadableIn(['name' => 'x']),
          'while a role the payload never mentioned is not a complaint about a colour');

// A whole-row save, for the reason BrandStore::updateDetails() is whole-row.
$tSaved = $tStore->updateDetails($tStore->forId($tOne->id()),
                                 ['name' => 'Night shift', 'nav_bg' => '#222222']);
checkSame('#222222', $tSaved->colorFor('nav_bg'), 'a save writes what it was given');
checkSame(SiteChrome::DEFAULTS['accent'], $tSaved->colorFor('accent'),
          'and puts a role the form did not carry back to its documented default, never to NULL');
SiteChrome::wear($tSaved);
checkSame(SiteChrome::DEFAULTS['accent'], SiteChrome::accent(),
          'which is a colour the page can draw either way');
SiteChrome::wear(null);

$tStore->deleteRow($tSaved);
checkSame(0, $tStore->count(), 'and a theme nobody is wearing can be removed');
checkSame(null, $tStore->forId($tSaved->id()), 'leaving nothing behind to point at');

// ---- Contrast: warned about, never refused (decision 13) ---------------------------
checkSame(21.0, round(Color::contrastRatio('#000000', '#ffffff'), 1),
          'black on white is the widest two colours get');
checkSame(1.0, round(Color::contrastRatio('#3498db', '#3498db'), 1),
          'and a colour on itself is the narrowest');
checkSame(true, Color::hardToRead('#ffffff', '#1a252f') === false,
          'today\'s nav text on today\'s nav background is readable');
checkSame(true, Color::hardToRead('#7f8c8d', '#8fa6bb'),
          'two mid greys are not, which is the case the warning exists for');
checkSame(true, Color::hardToRead('#101820', '#101820'),
          'and a theme whose two nav colours are identical is warned about');
checkSame(Color::READABLE_RATIO, 4.5, 'the threshold is named once rather than typed into a form');
// Three colours whose answer depends on *which* channel is which. Every fixed point
// above is grey, identical or both, and a grey is the one input a channel mix-up
// cannot change: mutation moved the two substring offsets and both weightings in
// `luminance()` and each one lived, because #000000, #ffffff and a colour on itself
// give the same ratio whichever way round the bytes are read. Red, green and blue on
// white are three different numbers, and the standard's weighting is why — green
// carries 0.7152 of the luminance and blue 0.0722, so the same byte in a different
// channel is a tenfold difference in what a person can read.
checkSame(4.0, round(Color::contrastRatio('#ff0000', '#ffffff'), 2),
          'red on white is a shade under the threshold');
checkSame(1.37, round(Color::contrastRatio('#00ff00', '#ffffff'), 2),
          'green on white is nearly invisible, being the channel most of the luminance is in');
checkSame(8.59, round(Color::contrastRatio('#0000ff', '#ffffff'), 2),
          'and blue on white is comfortable, being the channel least of it is in');
checkSame(true, Color::hardToRead('#ff0000', '#ffffff'),
          'so a red-on-white theme is warned about');
checkSame(false, Color::hardToRead('#0000ff', '#ffffff'),
          'and a blue-on-white one is not — the pair a mixed-up channel would answer backwards');
$tThrew = false;
try { Color::contrastRatio('puce', '#ffffff'); } catch (Throwable $e) { $tThrew = true; }
checkSame(true, $tThrew,
          'and a value that is not a colour has no contrast, rather than the worst possible contrast');

// ---- A theme's unreadable colour reaches the audit ---------------------------------
// The one tool safe to point at the live database, and a table this step created is a
// place it could have had a blind spot. Reported under the kind that says no sign is
// affected, beside `branding_config.php`'s, because that is what is true of both — and a
// finding that read like the others would send somebody to the shop floor over a menu bar.
// Its own theme, made here: by this point the one above has been deleted, and an UPDATE
// matching no row would have left this whole block asserting that an audit of nothing
// finds nothing.
$tAuditTheme = $tStore->insert(['name' => 'Night shift']);
check($tAuditTheme instanceof WorkspaceTheme, 'a theme for the audit to find something in');
// Six characters rather than ten, for the `VARCHAR(7)` reason above (§4bk).
$tPdo->prepare("UPDATE workspace_themes SET status_warn = 'tomato' WHERE id = ?")
     ->execute([$tAuditTheme->id()]);
$tAudit = new ColorAudit(new DisplayStore($tPdo), new LayoutStore($tPdo, new DisplayStore($tPdo)),
                         new BrandStyles($tPdo), new BrandStore($tPdo), SiteChrome::DEFAULTS,
                         $tStore);
$tFound = $tAudit->findings();
$tThemeFindings = [];
foreach ($tFound as $tF) {
    if (strpos($tF['what'], 'workspace theme') !== false) { $tThemeFindings[] = $tF; }
}
checkSame(1, count($tThemeFindings), 'a theme colour nobody can read joins the audit');
checkSame(ColorAudit::WRONG_IN_APP, $tThemeFindings[0]['kind'], 'under the kind that touches no sign');
checkSame('tomato', $tThemeFindings[0]['value'], 'quoting what is actually stored');
checkMentions($tThemeFindings[0]['what'], 'Night shift', 'naming the theme a person would open');
// The store default for that role, which is what the fallback paints — asked for by the
// same method the finding uses rather than by naming a constant, because for a role Site
// Branding *can* set they are two different colours on a live install and the same one
// here. `status_warn` is not one of the four, so this check cannot tell them apart; the
// pair of subprocess checks above is where that distinction is actually held.
checkMentions($tThemeFindings[0]['consequence'], SiteChrome::configColor('status_warn'),
              'saying which colour is drawn instead');
checkMentions($tThemeFindings[0]['consequence'], 'nothing on the shop floor',
              'and saying plainly that no sign is affected');
checkMentions($tThemeFindings[0]['fix'], 'Workspace Themes', 'pointing at the tab that fixes it');
// And the store is optional, so the audit that forgets to pass one reports nothing about
// themes and looks exactly like a clean table. The tool is what must not forget.
$tNoThemes = new ColorAudit(new DisplayStore($tPdo), new LayoutStore($tPdo, new DisplayStore($tPdo)),
                            new BrandStyles($tPdo), new BrandStore($tPdo), SiteChrome::DEFAULTS);
$tSilent = 0;
foreach ($tNoThemes->findings() as $tF) {
    if (strpos($tF['what'], 'workspace theme') !== false) { $tSilent++; }
}
checkSame(0, $tSilent, 'an audit built without the theme store says nothing about themes');
checkMentions(file_get_contents(__DIR__ . '/audit_colors.php'), 'new WorkspaceThemeStore($pdo)',
              'which is why the tool passes one explicitly');
$tStore->deleteRow($tAuditTheme);

// ---- What "use the store default" is, as a value -----------------------------------
// `storeColors()` is what the Builder hands its script so the escape hatch can put the
// thirteen back, and the mutation run found its whole body deletable: the node suite
// substitutes that payload, so nothing here had ever run it. The property that matters is
// the last check — it must answer the *store's* colours while a theme is being worn,
// because that is the only state it is ever called in.
$tStoreColors = SiteChrome::storeColors();
checkSame(13, count($tStoreColors), 'the store default is thirteen colours like any theme');
checkSame(SiteChrome::configColor('nav_bg'), $tStoreColors['nav_bg'],
          'the four Site Branding owns come from the config');
checkSame(SiteChrome::DEFAULTS['status_busy'], $tStoreColors['status_busy'],
          'and the other nine from the documented defaults');
// Its own theme, because by this point in the section the earlier one has been deleted —
// and a `forId()` answering null here would have made the next two checks pass by wearing
// nothing at all, which is the shape of every hollow check this file has found.
$tForStore = $tStore->insert(['name' => 'Loud', 'nav_bg' => '#ff00ff']);
check($tForStore instanceof WorkspaceTheme, 'a theme to wear while asking about the store default');
SiteChrome::wear($tForStore);
checkSame($tStoreColors, SiteChrome::storeColors(),
          'and wearing a theme does not change what the store default is — which is the '
        . 'only state this is ever asked in');
checkSame('#ff00ff', SiteChrome::navBg(),
          'while the page around it is painted in the theme, so the two really are different answers');
SiteChrome::wear(null);
$tStore->deleteRow($tForStore);

// ---- The two pages a theme must never reach, by construction -----------------------
// Decision 12 says the sign-in page and the Viewer are unaffected "by construction", and
// a construction is worth a check: what makes it true is that neither page ever calls
// `wear()`, so `SiteChrome` answers the store default there and no query is made. The
// sign-in page is the one that would be tempting — it draws a stylesheet from the same
// four colours — and it has no account to look a theme up for, which is the whole reason
// the lookup is passed in rather than reached for.
// The Viewer's filename is built rather than written, and that is not a dodge — it is
// the ADR-0003 check working. That rule looks for `viewer.php` immediately followed by a
// quote, because a closing quote is what makes a string a *link* rather than a mention,
// and a link with no Display is the "no display specified" notice. A path this file reads
// its source from would match that pattern and be flagged, correctly by the rule's own
// terms. Same shape as admin_panel.php spelling out a `<script>` tag in words so the gate
// that extracts its script block does not trip on a comment.
$tLogin  = file_get_contents(__DIR__ . '/../login.php');
$tViewer = file_get_contents(__DIR__ . '/../viewer' . '.php');
checkSame(0, substr_count($tLogin, 'SiteChrome::wear'),
          'the sign-in page never wears a theme');
checkSame(0, substr_count($tLogin, 'WorkspaceThemeStore'),
          'and never reads the table, so it makes no query for one');
check(strpos($tLogin, 'SiteChrome::accent()') !== false,
      'while still drawing the store\'s own colours, which is what it is for');
checkSame(0, substr_count($tViewer, 'SiteChrome'),
          'and the Viewer does not know this module exists at all');
checkSame(0, substr_count($tViewer, 'workspace_theme'),
          'nor the table behind it — the page a customer sees loads neither config.php nor auth.php');

// ---- The panel's theme surface and its handler agree about field names -------------
// Grep-shaped, for the reason the Display Branding block above says: nothing here renders
// the page, so a broken layout is the browser pass's job. What this can see is the
// failure a rendered page would not shout about either — a form posting `t_nav_bg` at a
// handler reading `t_navbg` looks perfectly fine and silently drops what was picked. The
// two halves are 600 lines apart in one file.
foreach (['t_name', 't_id'] as $tF) {
    checkMentions($panel, 'name="' . $tF . '"', 'the theme form posts ' . $tF);
    checkMentions($panel, "\$_POST['" . $tF . "']", 'and the handler reads it back under the same name');
}
// The thirteen are not literals on either side: the form emits them from
// `SiteChrome::ROLES` and the handler reads them back from the same list. That is
// stronger than matching names — one list means they cannot drift — so what is asserted
// is that both halves really do come from it.
checkMentions($panel, 'name="t_<?= Markup::text($tRole) ?>"',
              'each role\'s input is named from the one list of roles');
checkMentions($panel, "\$_POST['t_' . \$_tr]",
              'and the handler reads all thirteen from that same list');
// Not a count — the first version of this check asserted three uses and there are five,
// all of them legitimate (the save loop, the refusal's labels, the swatch loop, each
// swatch's title, the form's grouping). A number would have to be edited every time this
// page grows a way of showing a role, which is a check that trains people to change it.
// What matters is that no *hand-written* list of roles exists beside the one list.
check(substr_count($panel, 'SiteChrome::ROLES') >= 4,
      'every place that walks the roles walks the one list');
checkSame(0, preg_match("/'nav_bg'\s*(=>|,)\s*'?#?[a-z0-9]*'?\s*,?\s*'nav_border'/", $panel),
          'and the page holds no second copy of what the roles are');

foreach (['action_create_theme', 'action_save_theme', 'action_delete_theme'] as $tA) {
    checkMentions($panel, "\$_POST['" . $tA . "']", 'the panel handles ' . $tA);
}
checkMentions($panel, '$themeStore->insert(',        'creating a theme goes through the store that owns the table');
checkMentions($panel, '$themeStore->updateDetails(', 'and so does saving one');
checkMentions($panel, '$themeStore->deleteRow(',     'and deleting one');
checkMentions($panel, '$themeStore->accountsUsing(',
              'and a delete asks who is wearing it first, so the refusal can name them');
// The two rules the form leans on, asked rather than restated.
checkMentions($panel, 'WorkspaceThemeStore::unreadableIn(',
              'the form asks which submitted colours cannot be read');
checkMentions($panel, 'Color::hardToRead(',
              'and whether the result is legible, which it warns about rather than refusing');
check(strpos($panel, 'Nothing was saved') !== false,
      'and says nothing was saved when it refuses, rather than only what was wrong');
// The threshold reaches the browser from the one place it is declared. A form carrying
// its own 4.5 would be the second opinion that disagrees the day this changes.
checkMentions($panel, 'HttpReply::jsValue(Color::READABLE_RATIO)',
              'the live warning is given the threshold rather than holding a copy of it');
checkSame(0, preg_match('/THEME_READABLE_RATIO\s*=\s*4\.5/', $panel),
          'and no literal 4.5 is written into the script beside it');
// The panel is where a theme is *made*; it is not where one is worn by somebody else.
// Nothing here may write the choice column — that is the account's own, through the API.
checkSame(0, substr_count($panel, 'chooseWorkspaceTheme'),
          'and the panel never chooses a theme for anybody: that write is the account\'s own');

// ─────────────────────────────────────────────────────────────
section('The installer (§4bo)');

require_once __DIR__ . '/../lib/installer.php';

// `install.php`'s own zip reader and path guard, declared without running an install.
// Nothing else in the repo defines this constant and a web request cannot, which is what
// makes the guard a test seam rather than a door.
define('INSTALLER_INSPECT', true);
require_once __DIR__ . '/../install.php';

// ---- Splitting a script into statements ----------------------------------------
// The installer runs `schema.sql` from PHP, which nothing here had ever had to do. Every
// check below is a shape that file does not have *today* — which is the point: the day
// somebody writes `COMMENT 'the tag; the address'`, the alternative to these checks is a
// syntax error halfway through creating the table every sign's layout lives in, on a
// machine nobody is watching.
checkSame(['SELECT 1', 'SELECT 2'], sqlStatements('SELECT 1; SELECT 2;'),
          'two statements are two statements');
checkSame(["SELECT ';'"], sqlStatements("SELECT ';';"),
          'a semicolon inside a quoted string is not a statement boundary');
checkSame(['SELECT 1'], sqlStatements("-- a comment; with a semicolon\nSELECT 1;"),
          'a `-- ` comment is dropped, semicolon and all');
checkSame(['SELECT 1--x'], sqlStatements("SELECT 1--x;"),
          'but `--x` is not a comment to MySQL, so the rest of the line is not dropped');
checkSame(['SELECT 1'], sqlStatements("# also a comment;\nSELECT 1;"),
          'and neither spelling of a line comment is missed');
checkSame(['SELECT 1'], sqlStatements("/* a block;\n   comment */ SELECT 1;"),
          'a block comment goes too, across lines');
checkSame(['SELECT `odd;name`'], sqlStatements('SELECT `odd;name`;'),
          'a backtick identifier can hold a semicolon');
checkSame(["SELECT 'it''s'"], sqlStatements("SELECT 'it''s';"),
          'a doubled quote inside a string is that character, not the end of it');
checkSame(["SELECT 'it\\'s; still'"], sqlStatements("SELECT 'it\\'s; still';"),
          'and a backslash escapes the next character, so the string does not end early');
checkSame(['SELECT 1'], sqlStatements('SELECT 1;;  ;'),
          'stray and trailing semicolons produce no empty statements');
checkSame([], sqlStatements("-- nothing but a comment\n"),
          'a script that is only prose is no statements at all');

// The real file, which is the one that has to work. Nine CREATE TABLEs and two SETs.
$schemaSql = file_get_contents(__DIR__ . '/../schema.sql');
$schemaStatements = sqlStatements($schemaSql);
checkSame(9, count(array_filter($schemaStatements, function ($one) {
              return stripos($one, 'CREATE TABLE') === 0; })),
          'schema.sql splits into the nine CREATE TABLE statements it holds');
checkSame(0, count(array_filter($schemaStatements, function ($one) {
              return strpos($one, '--') === 0 || $one === ''; })),
          'and no statement is a comment or empty');

// ---- Running that script, which nothing here used to do (§4bq) -----------------
// `sqlStatements()` had eleven checks above and `applySchemaScript()` had **none** — the
// function the installer's schema step actually calls was never called by any local gate.
// Its only caller outside the installer is `tools/rehearse_install.php`, which needs a MySQL
// server and therefore only ever ran on CI, where it was a fatal. Four days of red legs, and
// what it was fatal on is the *ordinary* call shape:
//
//     applySchemaScript($pdo, $script, $failures)      // $failures never initialised
//
// with `array &$failures = []` in the signature. An undefined variable passed by reference is
// created as `null`, the declared type rejects `null`, and the call dies before the body runs
// — so the shape the `= []` default exists for was the one shape that could not work. The
// first check below is that call, deliberately spelled with a variable this file has never
// mentioned, because writing `$x = []` above it is exactly what hid this.
$probeDb = newSqliteTestDb(false);
checkSame(true, applySchemaScript($probeDb, 'CREATE TABLE probe_one (id INTEGER);',
                                  $failuresNeverInitialised),
          'a script the engine accepts returns true — called the way both real callers call '
          . 'it, with an out-parameter that does not exist yet');
checkSame([], $failuresNeverInitialised,
          'and the out-parameter is an array afterwards, which the function guarantees rather '
          . 'than the signature');

$twoBad = [];
checkSame(false, applySchemaScript($probeDb,
              "CREATE TABLE probe_two (id INTEGER);\nNOT SQL AT ALL;\nALSO NOT SQL;", $twoBad),
          'a refused statement is reported as a failure rather than thrown');
checkSame(2, count($twoBad),
          'and **every** refusal is collected, not just the first — the installer prints them '
          . 'all, because one missing privilege is eleven "command denied" errors and a person '
          . 'shown one of them goes and fixes one thing');
check(isset($twoBad[0]['statement']) && isset($twoBad[0]['error']),
      'each one carries the statement and the engine\'s own message');
$probeTables = [];
foreach ($probeDb->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll() as $row) {
    $probeTables[] = $row['name'];
}
check(in_array('probe_two', $probeTables, true),
      'and the statements before the refusal really ran — this reports what happened rather '
      . 'than rolling back, which is why the installer refuses to go on to the administrator');

$delimiter = [];
checkSame(false, applySchemaScript($probeDb, "DELIMITER //\nCREATE TABLE x (id INTEGER)//",
                                   $delimiter),
          'a script setting its own delimiter is refused as a whole rather than mis-split');
checkMentions($delimiter ? (string) $delimiter[0]['error'] : '', 'phpMyAdmin',
              'naming a tool that can import it, because refusing without an alternative is '
              . 'where somebody starts editing schema.sql by hand');

// ---- Where an archive may write ------------------------------------------------
// A zip is data. An unpacker that joins a path out of data onto a directory without
// looking at it writes wherever the data says — and the entry this refuses,
// `../../private/db_credentials.php`, is the one that would land a file outside the
// webroot with an attacker's contents in it.
checkSame(true,  installerSafeEntryName('lib/schema.php'), 'an ordinary entry is allowed');
checkSame(false, installerSafeEntryName('../outside.php'), 'a leading ../ is refused');
checkSame(false, installerSafeEntryName('lib/../../outside.php'),
          'and so is one buried in the middle, which is the form that reads as harmless');
checkSame(false, installerSafeEntryName('/etc/passwd'), 'an absolute path is refused');
checkSame(false, installerSafeEntryName('C:/windows/x'), 'so is a drive letter');
checkSame(false, installerSafeEntryName('lib\\schema.php'),
          'a backslash is refused rather than normalised — it is a separator on one host');
checkSame(false, installerSafeEntryName(''), 'and an empty name names nothing');

// ---- Reading the archive -------------------------------------------------------
$zipFile = tempnam(sys_get_temp_dir(), 'lbmzip');
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('one.php', "<?php\n// one\n");
$zip->addFromString('lib/.htaccess', "Require all denied\n");
$zip->close();
$zipBytes = file_get_contents($zipFile);
unlink($zipFile);

$entries = installerZipEntries($zipBytes, $zipWhy);
check(is_array($entries), 'a zip written by ZipArchive is read by the installer\'s reader');
if (is_array($entries)) {
    $byName = [];
    foreach ($entries as $entry) { $byName[$entry['name']] = $entry['data']; }
    checkSame("<?php\n// one\n", isset($byName['one.php']) ? $byName['one.php'] : '',
              'and a deflated entry comes back as the bytes that went in');
    checkSame("Require all denied\n", isset($byName['lib/.htaccess']) ? $byName['lib/.htaccess'] : '',
              'including a dotfile, which is the whole reason the app travels inside one file');
}

// A stored entry, built by hand — the method a zip uses for a file too small to compress,
// and the one path a ZipArchive-built fixture may never take.
$body   = 'plain';
$stored = "PK\x03\x04" . pack('vvv', 10, 0, 0) . pack('V', 0)
        . pack('V', crc32($body)) . pack('VV', strlen($body), strlen($body))
        . pack('vv', 5, 0) . 'a.txt' . $body;
$storedEntries = installerZipEntries($stored, $zipWhy);
checkSame('plain', is_array($storedEntries) ? $storedEntries[0]['data'] : '',
          'a stored entry is read without being inflated');

// And the failure this exists for: an FTP client in ASCII mode rewrites bytes inside data
// it believes is text. The archive still inflates often enough to be dangerous; it does
// not still match its own checksum.
$broken = $stored;
$broken[strlen($broken) - 1] = 'X';
checkSame(null, installerZipEntries($broken, $zipWhy),
          'a rewritten byte fails the checksum rather than unpacking quietly');
checkMentions($zipWhy, 'binary mode',
              'and the sentence names the cause, because that is what a person can act on');
checkSame(null, installerZipEntries('not a zip at all', $zipWhy),
          'and something that is not an archive is refused');

// ---- Unpacking ----------------------------------------------------------------
$into = sys_get_temp_dir() . '/lbm-unpack-' . getmypid();
@mkdir($into, 0755, true);
checkSame(2, installerUnpack($entries, $into, $unpackWhy),
          'unpacking writes every file and counts only the ones it read back');
checkSame(true, is_file($into . '/lib/.htaccess'),
          'creating the folders it needs on the way');
checkSame(0, installerUnpack([['name' => '../escaped.php', 'data' => 'x', 'dir' => false]],
                             $into, $unpackWhy),
          'and an entry pointing outside the folder stops the whole unpack, not just itself');
checkSame(false, is_file(dirname($into) . '/escaped.php'),
          'so nothing at all is written when one entry is wrong');
@unlink($into . '/one.php');
@unlink($into . '/lib/.htaccess');
@rmdir($into . '/lib');
@rmdir($into);

// ---- What the installer makes of a machine -------------------------------------
// Every fact is a parameter (invariant 37), which is the only reason these can be asked:
// PHP 8.0 with no zlib and an unwritable webroot is a machine nobody here has.
$goodMachine = ['php' => '8.2.33', 'pdoMysql' => true, 'zlib' => true,
                'appWritable' => true, 'privateWritable' => true, 'https' => true];
checkSame(false, Installer::blocked(Installer::preflight($goodMachine)),
          'a host that can run the app is not blocked');
checkSame(true, Installer::blocked(Installer::preflight(
              array_merge($goodMachine, ['php' => '8.1.99']))),
          'a PHP below the floor stops the install rather than warning about it');
checkSame(true, Installer::blocked(Installer::preflight(
              array_merge($goodMachine, ['pdoMysql' => false]))),
          'and so does a host with no MySQL driver');
checkSame(true, Installer::blocked(Installer::preflight(
              array_merge($goodMachine, ['appWritable' => false]))),
          'and one whose folder cannot be written to');
checkSame(false, Installer::blocked(Installer::preflight(
              array_merge($goodMachine, ['privateWritable' => false]))),
          'but a folder above the webroot that cannot be written is a warning: the install '
          . 'works, and the page prints the file to place by hand');
checkSame(false, Installer::blocked(Installer::preflight(
              array_merge($goodMachine, ['https' => false]))),
          'and so is plain HTTP — refusing would strand an install on a host mid-certificate');
checkSame(true, Installer::blocked(Installer::preflight([])),
          'a preflight handed no facts at all is blocked: an unsupplied fact is not a fact '
          . 'in this install\'s favour');

$httpWarning = '';
foreach (Installer::preflight(array_merge($goodMachine, ['https' => false])) as $check) {
    if ($check->name() === 'HTTPS') { $httpWarning = $check->sentence(); }
}
checkMentions($httpWarning, 'in the clear',
              'and the HTTPS warning says what is at stake rather than only that it is off');

// ---- The privileges report -----------------------------------------------------
// A report and not a verdict: nothing branches on it, because the engine answers properly
// a moment later by refusing statements. It exists so a person meeting eleven "command
// denied" errors has already been told which box to tick (HANDOFF §5).
checkSame([], Installer::missingPrivileges(['GRANT ALL PRIVILEGES ON `db`.* TO `u`@`h`']),
          'ALL PRIVILEGES leaves nothing missing');
checkSame(['DELETE', 'CREATE', 'ALTER', 'INDEX', 'REFERENCES'],
          Installer::missingPrivileges(['GRANT SELECT, INSERT, UPDATE ON `db`.* TO `u`@`h`']),
          'a partial grant is reported as the names it does not hold, in the order they '
          . 'appear on the cPanel form');
checkSame(Installer::PRIVILEGES, Installer::missingPrivileges(['GRANT USAGE ON *.* TO `u`@`h`']),
          'USAGE is MySQL\'s word for no privileges and is not read as one');
checkSame(Installer::PRIVILEGES, Installer::missingPrivileges([]),
          'and a user whose grants could not be read is reported as holding none, which is '
          . 'the safe direction for a sentence nobody acts on automatically');

// ---- The credentials file ------------------------------------------------------
$blank = Installer::credentialsSource(__DIR__ . '/..');
checkMentions($blank, "define('DB_HOST', 'localhost')",
              'the blank form carries the four defines with the placeholders db_connect.php '
              . 'documents');
checkMentions($blank, 'your_database_password',
              'and a placeholder obvious enough that a half-filled file is not plausible');
$filled = Installer::credentialsSource(__DIR__ . '/..', ['host' => 'localhost',
              'name' => 'shop_signs', 'user' => 'shop_u', 'pass' => "it's \\ odd\"quoted\""]);
checkMentions($filled, "define('DB_NAME', 'shop_signs')",
              'a filled-in file holds the values it was given');
// Both of these could only ever pass — `token_get_all()` always returns an array, and an
// `eval` of `return true` always evaluates — so they are gone rather than kept as two more
// `ok` lines (invariant 30). What is left is the one that can fail: PHP's own parser, on
// the file this actually writes, with a password holding the two characters that end a
// single-quoted string.
$tmpCred = tempnam(sys_get_temp_dir(), 'lbmcred');
file_put_contents($tmpCred, $filled);
$lint = [];
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmpCred) . ' 2>&1', $lint, $lintStatus);
checkSame(0, $lintStatus,
          'a password holding a quote and a backslash still produces a file PHP can parse — '
          . 'var_export is the whole of that, and a parse error here is a file outside the '
          . 'webroot that nobody thinks to look at');
unlink($tmpCred);

// Where it goes, which is the isolation rule `install_paths.php` exists for.
$fakeAccount = sys_get_temp_dir() . '/lbm-install-' . getmypid();
@mkdir($fakeAccount . '/public_html/signs', 0755, true);
$firstTarget = Installer::credentialsTarget($fakeAccount . '/public_html/signs');
checkSame($fakeAccount . '/private/db_credentials.php', $firstTarget,
          'the first install writes the shared credentials file, above the webroot');
@mkdir($fakeAccount . '/private', 0700, true);
file_put_contents($firstTarget, "<?php\n");
checkSame($fakeAccount . '/private/db_credentials_signs-test.php',
          Installer::credentialsTarget($fakeAccount . '/public_html/signs-test'),
          'and a second install finds that file already there and writes its own, rather '
          . 'than overwriting the first one\'s database name with its own');
@unlink($firstTarget);
@rmdir($fakeAccount . '/private');
@rmdir($fakeAccount . '/public_html/signs');
@rmdir($fakeAccount . '/public_html');
@rmdir($fakeAccount);

// ---- Whose credentials file is this? (§4bp) ------------------------------------
// `credentialsTarget()` above is the write side of the second-install rule and has always
// been right. This is the read side, which did not exist: a second install resolved the
// *shared* file, connected to the first install's database, found an administrator in it
// and reported a finished install. Nothing was broken — every line did what it says — and
// `INSTALL.md` promised the opposite, because the form the promise was about was never
// reached.
//
// Pure and filesystem-free, which is the point: every arrangement below is one this
// machine is not in.
$signs = '/home/acct/public_html/signs';
$mine  = '/home/acct/private/db_credentials_signs.php';
$ours  = '/home/acct/private/db_credentials.php';

checkSame(Installer::NONE, Installer::credentialsOwnership($signs, ''),
          'no credentials file at all is a first install, not a borrowed one');
checkSame(Installer::OWN, Installer::credentialsOwnership($signs, $mine),
          'a file named after this folder is this install\'s, stamp or no stamp');
checkSame(Installer::OWN, Installer::credentialsOwnership($signs, $mine, 'somewhere-else'),
          'and the filename outranks a stamp that disagrees with it — the app reads that '
          . 'file first, so what it says about itself cannot change which one is being used');
checkSame(Installer::UNKNOWN, Installer::credentialsOwnership($signs, $ours),
          'the shared file with no stamp is undecidable: it is either this install\'s own or '
          . 'another copy\'s, and nothing on disk can tell');
checkSame(Installer::OWN, Installer::credentialsOwnership($signs, $ours, 'signs'),
          'a stamp naming this folder settles it');
checkSame(Installer::OWN, Installer::credentialsOwnership($signs, $ours, "  signs\n"),
          'and it is trimmed, because that file is edited by hand');
checkSame(Installer::BORROWED, Installer::credentialsOwnership($signs, $ours, 'signs-test'),
          'a stamp naming a different folder is the whole defect, decided rather than '
          . 'guessed at');
checkSame(Installer::UNKNOWN,
          Installer::credentialsOwnership('/home/acct/public_html/we b', $ours, 'signs'),
          'a folder whose name InstallPaths refused can have no file of its own, so it can '
          . 'never be told apart from the install that wrote the shared one');
checkSame(Installer::UNKNOWN,
          Installer::credentialsOwnership($signs, '/somewhere/else/entirely.php', 'signs'),
          'and a path that is neither candidate is not read as either');

checkSame('', Installer::sharingNote(Installer::OWN, $signs, $mine, 'shop_signs'),
          'an install on its own credentials file has nothing to be told');
checkSame('', Installer::sharingNote(Installer::NONE, $signs, '', ''),
          'and neither has one that has not got a database yet');
$borrowed = Installer::sharingNote(Installer::BORROWED, $signs, $ours, 'shop_signs');
checkMentions($borrowed, 'shop_signs',
              'the sentence names the database that was reached, which is the fact that '
              . 'makes it worth reading');
checkMentions($borrowed, 'signs', 'and the folder this install is in');
checkMentions($borrowed, $mine,
              'and the file to create, in full — the fix is one file above the webroot and '
              . 'nothing in the app folder');
checkMentions($borrowed, 'folder called something else',
              'and says the stamp decided it, rather than leaving a person to wonder how '
              . 'much of this is a guess');
$unstamped = Installer::sharingNote(Installer::UNKNOWN, $signs, $ours, 'shop_signs');
checkMentions($unstamped, 'does not say which install',
              'an unstamped file says so instead — this is every credentials file written '
              . 'before the stamp existed, including the live one');
checkMentions($unstamped, "define('DB_INSTALL_FOLDER', 'signs');",
              'and it carries the second remedy, spelled out: one line in a file they '
              . 'already have, after which a later install in another folder is decided '
              . 'rather than adopted');
check(strpos($borrowed, 'DB_INSTALL_FOLDER') === false,
      'the borrowed sentence does not offer it — that file belongs to another install and '
      . 'stamping it for this one would be a lie about somebody else\'s database');
checkMentions(Installer::sharingNote(Installer::BORROWED, $signs, $ours, ''),
              'the database it names',
              'and a file that named no database still produces a sentence rather than a '
              . 'gap where the name should be');
checkMentions(Installer::sharingNote(Installer::UNKNOWN, '/home/acct/public_html/we b',
                                     $ours, 'shop_signs'),
              'Rename',
              'the folder that cannot have its own file gets the fix it actually needs, '
              . 'which is a different one');

// ---- The question that replaces adopting it (§4br) --------------------------------
// The state these describe is the one that reached the store: an unstamped shared file,
// a database with an administrator already in it, and an installer that called that
// `finished` and deleted itself before anybody could say otherwise.
check(Installer::mustAskWhose(Installer::UNKNOWN, 1),
      'an unstamped file over a database that already has an administrator is asked about '
      . 'rather than adopted');
check(!Installer::mustAskWhose(Installer::UNKNOWN, 0),
      'the same file over an empty database is not — installing into it is what "no '
      . 'credentials file at all" would do anyway, and a question with the same answer '
      . 'either way is furniture');
check(!Installer::mustAskWhose(Installer::UNKNOWN, -1),
      'and -1 is "the tables are not there", which must not read as "no administrator": '
      . 'that is the schema step, not an ambiguity about whose database this is');
check(!Installer::mustAskWhose(Installer::OWN, 12),
      'a file this folder owns is never ambiguous, however many accounts are in it');
check(!Installer::mustAskWhose(Installer::BORROWED, 12),
      'and a borrowed one is already refused a connection, so it never gets this far');
check(!Installer::mustAskWhose(Installer::NONE, 12),
      'no file at all is the ordinary first install');

// A folder name that appears nowhere in the sentence's own prose. The obvious fixture
// here is `$signs`, and it is the wrong one: this sentence says "the first one's signs",
// so a check for "signs" passes on the prose and survives deleting the folder name
// altogether — which is exactly what it did, until this line named the folder something
// the sentence would never say by itself (invariant 30).
$second   = '/home/acct/public_html/second-copy';
$question = Installer::whoseQuestion($second, $ours, 'shop_signs');
checkMentions($question, 'The folder second-copy',
              'the question names the folder being installed');
checkMentions($question, 'shop_signs', 'and the database it reached');
checkMentions($question, basename($ours), 'and the file it read to get there');
checkMentions($question, 'already has an administrator',
              'and why that is a question rather than a finished install');
checkMentions(Installer::whoseQuestion($signs, $ours, ''), 'a database',
              'a file naming no database still asks a whole sentence');
checkMentions(Installer::whoseQuestion('/home/acct/public_html/we b', $ours, 'shop_signs'),
              'This folder',
              'and a folder whose name cannot be used says "this folder" rather than '
              . 'printing a name it is about to refuse');

checkSame('', Installer::repointRefusal('hunter2', 'hunter2'),
          'the password of the database in use is what this page can check, and holding it '
          . 'is what lets somebody point the folder somewhere else');
check(Installer::repointRefusal('hunter3', 'hunter2') !== '',
          'a wrong one is refused — this is the gate that stops a stray install.php on a '
          . 'live app being a way to repoint it at somebody else\'s database');
check(Installer::repointRefusal('', 'hunter2') !== '',
          'and so is an empty answer, which is what a forged request carries');
checkMentions(Installer::repointRefusal('hunter3', 'hunter2'), 'credentials file',
              'and the refusal says where to find it rather than only that it was wrong');
check(Installer::repointRefusal('', '') !== '',
          'a database with no password recorded is a refusal, not a pass: there is nothing '
          . 'to prove, so proving it means nothing');
check(Installer::repointRefusal('anything', '') !== '',
          'and no answer gets through that door either — a gate that cannot tell anybody '
          . 'apart is not one');
checkMentions(Installer::repointRefusal('anything', ''), 'by hand',
              'so it names the one route that has never needed this page\'s permission');

// The stamp itself, in the file the installer writes.
$stampedFile = Installer::credentialsSource($signs, ['host' => 'localhost',
                   'name' => 'shop_signs', 'user' => 'shop_u', 'pass' => 'p']);
checkMentions($stampedFile, "define('DB_INSTALL_FOLDER', 'signs');",
              'a file the installer fills in says which folder it was written for');
check(strpos($blank, "// define('DB_INSTALL_FOLDER'") !== false,
      'the blank form leaves that line commented out');
check(strpos($blank, "\ndefine('DB_INSTALL_FOLDER'") === false,
      'and really commented out — a placeholder left alone would make a working install '
      . 'look borrowed, which is a database form offered over a live sign');
check(strpos($filled, "\ndefine('DB_INSTALL_FOLDER'") === false,
      'and a folder whose name cannot be used is left unstamped rather than stamped with '
      . 'something that is not a folder name');

// And the same fact on the card an admin would open to check it.
checkSame('', ServerReport::installNote('signs', $mine, $mine),
          'the server card says nothing about an install reading its own file');
$shared = ServerReport::installNote('signs', $ours, $mine);
checkMentions($shared, 'db_credentials.php',
              'and names the shared file when that is what was read');
checkMentions($shared, $mine,
              'with the path that would settle it — the card is where somebody looks after '
              . 'the installer has deleted itself');
checkMentions(ServerReport::installNote('signs', '', $mine), 'fallback',
              'no file at all is the fallback in db_connect.php, named as such');
checkMentions(ServerReport::installNote('', $ours, $ours), 'Rename',
              'and a folder name InstallPaths refused is a different problem with a '
              . 'different fix');

// ---- Writing a file, and reading it back ---------------------------------------
$writeTarget = sys_get_temp_dir() . '/lbm-write-' . getmypid() . '/nested/file.php';
checkSame(true, Installer::writeFile($writeTarget, "<?php\n// written\n", $writeWhy),
          'writeFile creates the folders it needs and reports what it read back');
checkSame("<?php\n// written\n", file_get_contents($writeTarget),
          'and the file holds what was handed to it');
checkSame(false, Installer::writeFile('/proc/lbm-cannot-exist/x', 'x', $writeWhy),
          'a write it cannot do is reported as a failure rather than as a success');
check($writeWhy !== '', 'with a sentence naming the path, because that is the actionable part');
// The mode on the folder it creates, which is the folder a database password lives in. It
// survived a sweep as `0701` — world-traversable — because nothing had ever read it back
// (invariant 30, §4bp). Not a umask artefact: 0700 is what 0700 masks to under any umask a
// host sets, which is why the assertion is the literal mode rather than a comparison.
checkSame('0700', substr(sprintf('%o', fileperms(dirname($writeTarget))), -4),
          'and the folder it had to create is readable only by the account that owns it — '
          . 'this is where the database password goes');
@unlink($writeTarget);
@rmdir(dirname($writeTarget));
@rmdir(dirname(dirname($writeTarget)));

// ---- The store's own identity, on the same form (§4bq) -------------------------
// The fieldset is optional end to end, so the first thing to pin is that *absent* means
// "leave the shipped default alone" rather than "clear it" — the #21 line, in the one place
// where clearing it would write a file every page requires.
checkSame('', Installer::storeProblem([]), 'a skipped fieldset is not a problem');
checkSame('', Installer::storeProblem(['site_name' => '', 'mail_from' => '', 'nav_bg' => '',
                                       'bg_val' => '']),
          'and neither are four empty boxes, which is what a browser actually sends');
checkMentions(Installer::storeProblem(['site_name' => str_repeat('x', Installer::SITE_NAME_MAX + 1)]),
              (string) Installer::SITE_NAME_MAX,
              'too long names the limit rather than describing it');
checkSame('', Installer::storeProblem(['site_name' => str_repeat('x', Installer::SITE_NAME_MAX)]),
          'and the limit itself is allowed — the boundary, because `>` and `>=` are one '
          . 'character apart');
checkMentions(Installer::storeProblem(['site_name' => "Bay\tMarket"]), 'control characters',
              'a control character is refused, because this lands in generated PHP and in a '
              . 'browser tab');
checkMentions(Installer::storeProblem(['mail_from' => 'not-an-address']), 'email address',
              'an address that is not one is refused rather than written into the file the '
              . 'password-reset mail is sent from');
checkSame('', Installer::storeProblem(['mail_from' => 'signs@example.com']),
          'and a real one is accepted');
$navBad = Installer::storeProblem(['nav_bg' => 'reddish']);
checkMentions($navBad, 'across the top',
              'a colour is refused by *where it shows*, because "invalid colour" on a form '
              . 'with two of them is a sentence nobody can act on');
checkMentions(Installer::storeProblem(['bg_val' => '#12345']), 'sign starts with',
              'and the other one names its own place — five hex digits is the near-miss '
              . 'that makes this worth checking');
checkSame('', Installer::storeProblem(['nav_bg' => '#1A252F', 'bg_val' => '#1a1a2e']),
          'two readable colours are no problem, upper case included');

// The one value this form derives. Both bands, and the refusal.
checkSame('#000000', Installer::readableTextOn('#ffffff'),
          'white needs black text on it');
checkSame('#ffffff', Installer::readableTextOn('#1a252f'),
          'and the shipped navy needs white — which is the shipped default, so this is the '
          . 'case where deriving must agree with what was already there');
checkSame('#ffffff', Installer::readableTextOn('not a colour'),
          'anything unreadable answers white rather than throwing: a form that refused the '
          . 'colour already said so, and this is not the place it is reported');

// ---- The logo ------------------------------------------------------------------
// Every arm takes its facts as parameters, which is the only reason the 20 MB file, the
// truncated upload and the SVG-named-.png can be asked about at all (invariant 37).
checkSame('', Installer::logoFileProblem(['error' => UPLOAD_ERR_OK, 'size' => 2048,
                                          'name' => 'logo.PNG'], 10485760, 'image/png'),
          'an ordinary PNG is accepted, and the extension is matched case-insensitively');
checkMentions(Installer::logoFileProblem(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 0,
                                          'name' => 'logo.png'], 1048576, ''),
              'larger than this server accepts',
              'a file the server itself refused says so, naming the limit — the arm that '
              . 'arrives with no file content at all');
checkMentions(Installer::logoFileProblem(['error' => UPLOAD_ERR_PARTIAL, 'size' => 10,
                                          'name' => 'logo.png'], 1048576, 'image/png'),
              'did not finish uploading', 'a truncated upload is not read as an image');
checkMentions(Installer::logoFileProblem(['error' => UPLOAD_ERR_OK, 'size' => 20971520,
                                          'name' => 'logo.png'], 10485760, 'image/png'),
              'accepts up to', 'too big names both sizes');
checkMentions(Installer::logoFileProblem(['error' => UPLOAD_ERR_OK, 'size' => 100,
                                          'name' => 'shell.php'], 10485760, 'image/png'),
              'has to be a', 'an extension outside the list is refused before the type is');
checkMentions(Installer::logoFileProblem(['error' => UPLOAD_ERR_OK, 'size' => 100,
                                          'name' => 'logo.png'], 10485760, 'image/svg+xml'),
              'named like an image and is not one',
              'and a file whose *type* disagrees with its name is refused too — the check '
              . 'the extension alone cannot make');

// The line that stops a logo being a PHP file. `uploads/` has no .htaccess of its own, so
// what matters is that **nothing the browser sent reaches the filename** — not the basename
// and not the extension. The name is looked up from the type the file really is, which is
// the shape admin_panel.php's branding upload already had (invariant 40).
checkSame('install_deadbeef.png', Installer::logoStoredName('image/png', 'DEADbeef'),
          'the stored name is the type\'s own extension and the caller\'s token, lower case');
checkSame('install_ab12.jpg', Installer::logoStoredName('image/jpeg', 'ab12'),
          'and JPEG is stored as .jpg, the spelling this app uses');
checkSame('', Installer::logoStoredName('image/svg+xml', 'ab12'),
          'SVG produces no filename — it can carry <script> and would be stored XSS served '
          . 'from this app\'s own origin');
checkSame('', Installer::logoStoredName('text/x-php', 'ab12'),
          'and so does anything else: a type this does not know is refused rather than '
          . 'sanitised, because sanitising is where the interesting failures live');
checkSame('', Installer::logoStoredName('', 'ab12'),
          'including the empty type mime_content_type() answers for a file it could not read');
checkSame('', Installer::logoStoredName('image/png', ''),
          'no token, no filename');
checkSame('', Installer::logoStoredName('image/png', '../..'),
          'and a token that is not hex is left with nothing rather than escaped');
check(!in_array('svg', AssetLibrary::IMAGE_EXTENSIONS, true)
      && !array_key_exists('image/svg+xml', Installer::LOGO_TYPES),
      'the two lists agree that SVG is not an image this app stores — asserted rather than '
      . 'assumed, because they are declared in two files');

// ---- What reaches branding_config.php ------------------------------------------
checkSame([], Installer::brandingChanges([]),
          'nothing given is nothing written — `save()` is not called at all, because writing '
          . 'a file every page requires in order to change nothing in it is a risk taken for '
          . 'no reason');
$named = Installer::brandingChanges(['site_name' => 'Lummi Bay Market']);
checkSame('Lummi Bay Market', $named['SITE_NAME'] ?? '', 'a store name becomes SITE_NAME');
checkSame('Lummi Bay Market', $named['MAIL_FROM_NAME'] ?? '',
          'and the name beside the address, so one place does not end up with two names');
$coloured = Installer::brandingChanges(['nav_bg' => '#EEEEEE']);
checkSame('#eeeeee', $coloured['BRAND_NAV_BG'] ?? '',
          'a colour is normalised to the six-digit lower-case form the file is read with');
checkSame('#000000', $coloured['BRAND_TEXT'] ?? '',
          'and the text on it comes along, which is the whole reason a pale brand colour is '
          . 'not a vanished menu');
// The protection invariant 14's exemption was granted on: this module may name two of the
// four colour constants, so every name it can emit is held to being one the file holds.
$everything = Installer::brandingChanges(['site_name' => 'S', 'mail_from' => 'a@b.com',
                                          'logo_path' => 'uploads/x.png', 'nav_bg' => '#123456']);
check(count($everything) === 6,
      'the fullest form of this fieldset writes six settings');
foreach ($everything as $settingName => $ignored) {
    check(array_key_exists($settingName, BrandingConfig::DEFAULTS),
          'the setting ' . $settingName . ' is one branding_config.php actually holds');
}

// ---- The first administrator ---------------------------------------------------
// The validation order is setup.php's, kept deliberately: a person filling in a form wants
// the first thing that is wrong with it, and these checks are what stops that order being
// rearranged by accident later.
$installer = new Installer(newTestDb());
checkMentions($installer->createFirstAdmin('', 'a@b.com', 'longenough', 'longenough', 'Venue')
                        ->message(), 'Every field',
              'an empty field is the first thing reported');
checkMentions($installer->createFirstAdmin('me', 'not-an-email', 'longenough', 'longenough', 'V')
                        ->message(), 'email address',
              'then the email, before anything is looked at in the database');
checkMentions($installer->createFirstAdmin('me', 'a@b.com', 'short', 'short', 'Venue')
                        ->message(), 'at least ' . Installer::PASSWORD_MIN,
              'then the password length, naming the number rather than describing it');
checkMentions($installer->createFirstAdmin('me', 'a@b.com', 'longenough', 'different', 'Venue')
                        ->message(), 'not the same',
              'then the confirmation');
// The boundary, not just a clearly-short password: `strlen($password) < PASSWORD_MIN`
// survived a sweep as `<=`, because every check above hands it a length nothing near the
// edge. A password of exactly the minimum has to be *accepted* as long enough, so this asks
// for the next complaint in the order rather than the length one (invariant 30, §4bp).
checkMentions($installer->createFirstAdmin('me', 'a@b.com',
                  str_repeat('x', Installer::PASSWORD_MIN),
                  str_repeat('x', Installer::PASSWORD_MIN) . 'y', 'Venue')->message(),
              'not the same',
              'and a password of exactly the minimum is long enough — the complaint that '
              . 'comes back is the confirmation, not the length');
$onSeeded = $installer->createFirstAdmin('me', 'a@b.com', 'longenough', 'longenough', 'Venue');
checkSame(false, $onSeeded->isOk(),
          'and a database that already holds accounts has no first administrator to create');
checkMentions($onSeeded->message(), 'Sign in',
              'which is a sentence saying what to do instead, not a refusal');

// ---- And the whole of it, once, on a database with nobody in it (§4bq) ---------
// The only place `createFirstAdmin()`'s *success* path runs: everything above hands it a
// database that already has accounts, which is the refusal. What this pins is the chain —
// a logo row, a Brand carrying it, a generated PHP file, and an account — and the order,
// which is the part that decides what a failure leaves behind.
$fresh     = newTestDb(false);
$freshInst = new Installer($fresh);
checkSame(0, $freshInst->accountCount(), 'the accountless fixture really has no accounts');

// `refusalFor()` is what the page asks before it moves a file, so it is asked here the same
// way round: a form that would be refused must be refused *without* the database being
// touched, or the whole point of the split is lost.
check($freshInst->refusalFor('me', 'a@b.com', 'short', 'short', 'Venue') !== null,
      'a form that would fail is refused before anything is written');
checkSame(null, $freshInst->refusalFor('me', 'a@b.com', 'longenough', 'longenough', 'Venue'),
          'and a form that would succeed answers null, which is the page\'s green light to '
          . 'move the uploaded logo');
checkSame(0, $freshInst->accountCount(),
          'and asking created nothing — the refusal check is a read');

$logoId = (new AssetLibrary($fresh))->create('image', 'uploads/install_abc.png',
                                             Installer::LOGO_LABEL);
check($logoId > 0, 'a logo goes into the Asset Library through the module that owns it');

$brandingDir = sys_get_temp_dir() . '/lbm-branding-' . getmypid();
@mkdir($brandingDir, 0700, true);
$config = new BrandingConfig($brandingDir);
$made   = $freshInst->createFirstAdmin('owner', 'owner@example.test', 'longenough',
    'longenough', 'Bay Market',
    ['site_name' => 'Lummi Bay Market', 'mail_from' => 'signs@example.test',
     'nav_bg' => '#2E5C3A', 'bg_val' => '#101820', 'logo_asset_id' => $logoId,
     'logo_path' => 'uploads/install_abc.png'],
    $config);
checkSame(true, $made->isOk(), 'the whole form lands: ' . $made->message());
checkSame(1, $freshInst->accountCount(), 'with exactly one account, which is the administrator');

$brandRows = (new BrandStore($fresh))->all();
checkSame(1, count($brandRows),
          'and one Brand still — the venue was *renamed*, not added beside the seeded one');
checkSame('Bay Market', $brandRows[0]->name(), 'wearing the venue name that was typed');
checkSame($logoId, $brandRows[0]->logoAssetId(),
          'and the logo, which rides in the same write because BrandStore::updateDetails '
          . 'writes every column it knows and a second call would erase the first');
checkSame('#101820', $brandRows[0]->backgroundValue(),
          'and the background a sign starts with');

// The generated file, read back off disk rather than from the object that wrote it.
$written = @file_get_contents($config->path());
check(is_string($written) && $written !== '', 'branding_config.php was written');
checkMentions((string) $written, "'Lummi Bay Market'", 'holding the store name');
checkMentions((string) $written, "'signs@example.test'",
              'and the address mail comes from, which is the setting this fieldset exists for');
checkMentions((string) $written, "'#2e5c3a'", 'and the navigation colour, normalised');
checkMentions((string) $written, "'#ffffff'",
              'and white text, derived from that colour rather than asked for');
checkMentions((string) $written, "'uploads/install_abc.png'",
              'and the logo path, so the sign-in page draws it too — one upload, three places');
$lint = [];
$lintStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($config->path()) . ' 2>&1',
     $lint, $lintStatus);
checkSame(0, $lintStatus,
          'and PHP can parse what was written, which is the whole of why BrandingConfig is '
          . 'the only writer of that file — every page in the app requires it');
@unlink($config->path());
@rmdir($brandingDir);

// Skipping the fieldset writes no file at all, which is the other half of "absent means
// leave it alone". A separate database, because the one above now has an administrator.
$second   = new Installer(newTestDb(false));
$bareDir  = sys_get_temp_dir() . '/lbm-branding-bare-' . getmypid();
@mkdir($bareDir, 0700, true);
$bare     = new BrandingConfig($bareDir);
$skipped  = $second->createFirstAdmin('owner', 'owner@example.test', 'longenough',
                                      'longenough', 'Bay Market', [], $bare);
checkSame(true, $skipped->isOk(), 'an install that skipped the store details still works');
checkSame(false, is_file($bare->path()),
          'and wrote no branding file — the risk of touching a file every page requires is '
          . 'not taken to store the values it already had');
@rmdir($bareDir);

// ─────────────────────────────────────────────────────────────
// Everything above this line runs on both engines. What follows can only be asked
// of a real MySQL database, and is skipped entirely on the SQLite default — which
// is why reportChecks() below is given two numbers.
if (testIsMysql()) {

section('What only a real MySQL database can be asked (#48)');

// ---- The catalogue, read for real ----------------------------------------------
// readSchemaFacts() is executed against a fake information_schema further up, which
// proves the query text parses and the aliases line up. What it could not prove was
// that MySQL's catalogue actually answers the way the fixture pretends — the gap
// BUILD-REFERENCE names in so many words. This is that gap closed.
$mFacts = readSchemaFacts(newTestDb());
checkSame(true, $mFacts->known(), 'a real catalogue reads as known, not as unavailable');
checkSame(true, $mFacts->hasColumn('canvas_elements', 'display_id'),
          'and reports the column every query is scoped by');
checkSame(false, $mFacts->hasColumn('canvas_elements', 'no_such_column'),
          'and answers a definite no about one that is not there, rather than "cannot tell"');

// ---- schema.sql and lib/schema.php agree ---------------------------------------
// The most valuable check in this section, and the reason the MySQL fixture is
// built by running schema.sql rather than by DDL written here. Convergence asks the
// catalogue what is missing; on a database freshly built from schema.sql the honest
// answer is "nothing". Any statement in this plan is a column, index or constraint
// that lib/schema.php believes in and schema.sql does not write — invariant 15,
// which until now had no automated check at all and was to be diffed by eye.
$mPlan = signageSchemaPlan($mFacts);
$mStatements = planStatements($mPlan);
checkSame([], $mStatements,
          'a database built from schema.sql has nothing left for convergence to do');

// The steps are row work rather than shape work — a backfill has nothing to move on
// an empty database, but it is still asked, so they are not expected to be empty.
check(is_array(planSteps($mPlan)), 'and the row-level steps are still offered');

// ---- The seed, against the engine the shop runs ---------------------------------
// This pair used to be the only place the seed was executed at all: it was one
// `INSERT IGNORE`, which SQLite rejects, so the SQLite half could only ever witness
// the statement *not* being sent. Re-keying on the Brand replaced it with computed
// plain INSERTs that both engines run, and the SQLite half up in the convergence
// section now watches it seed for real. What is still worth asking only here is
// whether MySQL agrees — the composite primary key is what makes a second Brand's
// six rows six more rows rather than a duplicate-key error.
$mSeed = newTestDb();
checkSame(true, seedBlockStyles($mSeed), 'a complete set of branded block types is not re-seeded');
$mSeed->exec("DELETE FROM block_styles WHERE block_type = 'price_2'");
checkSame(true, seedBlockStyles($mSeed), 'and a missing one is put back rather than only attempted');
checkSame(6, intval($mSeed->query("SELECT COUNT(*) FROM block_styles")->fetchColumn()),
          'leaving all six branded types on the table');
checkSame('#e74c3c', $mSeed->query(
    "SELECT font_color FROM block_styles WHERE block_type = 'price'")->fetchColumn(),
    'and the store\'s own values were left alone');

// A second Brand on the real engine: six more rows under the composite key, which
// on the pre-ADR-0011 key would have been six duplicate-key failures.
makeTestBrandRow($mSeed, 'Second Venue');
checkSame(true, seedBlockStyles($mSeed), 'a second Brand is seeded too');
checkSame(12, intval($mSeed->query("SELECT COUNT(*) FROM block_styles")->fetchColumn()),
          'and MySQL keeps both Brands\' rows, which is the whole point of the re-key');

// ---- The row lock, actually taken -----------------------------------------------
// `SELECT … FOR UPDATE` is the statement the SQLite fixture replaces, which made the
// line the publish transaction depends on the least-tested one in the repo. On MySQL
// the real DisplayStore is used throughout this run, so every publish check above
// already went through it. This asserts the seam directly: the row lock reads the
// stamp it is supposed to, inside a transaction, from the real statement.
$lockPdo   = newTestDb();
$lockStore = new DisplayStore($lockPdo);
$lockSign  = makeTestDisplay($lockPdo, 'lockable', 'Lockable');
$lockPdo->beginTransaction();
checkSame(0, intval($lockStore->lockLayoutRevision($lockSign)),
          'the real FOR UPDATE statement reads the stamp of a Display nobody has published');
$lockPdo->rollBack();

$lockLayouts = newTestLayoutStore($lockPdo);
$pubbed = $lockLayouts->publish($lockSign, new PublishRequest(
    layoutWith('Locked publish'), Background::unchanged(), BrandChoice::unchanged(), 1, true, $lockSign->layoutStamp()));
checkSame(true, $pubbed->isOk(), 'and a publish taking that lock for real succeeds');
$lockPdo->beginTransaction();
checkSame(1, intval($lockStore->lockLayoutRevision(loadTestDisplay($lockPdo, $lockSign->id()))),
          'leaving the stamp advanced where the next transaction will read it');
$lockPdo->rollBack();

// ---- Two publishes colliding, for real (#35) --------------------------------------
// The one check in this suite that needs two database sessions. A row lock is only a
// row lock across connections — the same PDO handle re-entering its own transaction
// waits for nothing — so nothing short of a second session can show what the second
// publish does while the first is still holding the Display.

/** The real store, giving up after a second instead of five. */
class FastLockWaitStore extends DisplayStore
{
    public function limitPublishLockWait($seconds = 1)
    {
        return parent::limitPublishLockWait(1);
    }
}

$colPdo     = newTestDb();
$colStore   = new DisplayStore($colPdo);
$colLayouts = new LayoutStore($colPdo, $colStore);
$colSign    = makeTestDisplay($colPdo, 'collide', 'Collision');
check($colLayouts->publish($colSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true, $colSign->layoutStamp()))->isOk(),
      'a Display to collide on starts with a published layout');

// A second session takes the Display's row and holds it, which is exactly what the
// first of two simultaneous publishes is doing while the second arrives.
$holder = secondConnectionToLatestTestDb();
$holder->beginTransaction();
$holder->prepare("SELECT layout_revision FROM displays WHERE id = ? FOR UPDATE")
       ->execute([$colSign->id()]);

$colSign     = $colStore->forId($colSign->id());
$fastLayouts = new LayoutStore($colPdo, new FastLockWaitStore($colPdo));
$startedAt   = microtime(true);
$res = $fastLayouts->publish($colSign, new PublishRequest(
    goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true, $colSign->layoutStamp()));
$waited = microtime(true) - $startedAt;

checkSame('busy', $res->kind(), 'a publish that collides with another comes back as busy');
check($waited < 15,
      'having given up in seconds, rather than being killed by PHP part-way through the wait');
checkMentions($res->message(), 'publish again',
              'and it tells the person the one thing that is true here — try again');
checkMentions($res->message(), 'still here', 'and that their work has not gone anywhere');
checkSame(2, count(elementsOf($colPdo, $colSign->id())),
          'the layout it collided with is untouched — the wait is before the first DELETE');
checkSame(false, $colPdo->inTransaction(), 'and no transaction is left open behind it');

$holder->rollBack();
$colSign = $colStore->forId($colSign->id());
check($colLayouts->publish($colSign, new PublishRequest(
        goodLayout(), Background::unchanged(), BrandChoice::unchanged(), 1, true, $colSign->layoutStamp()))->isOk(),
      'and once the other session lets go, the same publish succeeds');

// ---- Changed rows are not affected rows ------------------------------------------
// A divergence the audit recorded in a comment and could not check: MySQL reports
// *changed* rows, so writing a password hash identical to the stored one updates
// nothing and looks exactly like an account that is not there. AccountStore answers
// about the row rather than about the count, and this is the engine that can prove
// it — on SQLite both cases report a row and the distinction never arises.
$cPdo   = newTestDb();
$cStore = new AccountStore($cPdo);
checkSame(true, $cStore->setPassword(1, 'a-real-hash'), 'setting a password reports success');
checkSame(true, $cStore->setPassword(1, 'a-real-hash'),
          'and setting the identical hash again still does, though MySQL changed no rows');
checkSame(false, $cStore->setPassword(9999, 'no-such-account'),
          'while an account that does not exist is still false');

}

// Two numbers because the MySQL run adds a section the SQLite one cannot ask for.
// Both are anchored: a section deleted from either path has to show up as a failure,
// which is the whole reason reportChecks() takes a count at all.
//
// Both figures are what the suite reported when it was run, never a delta added on
// paper — `docs/work-lanes.md` item 3 is about this exact line, because every branch
// that adds a check changes it and so it conflicts on every merge. This number is the
// first one settled that way rather than by argument: #33 and #44 grew the suite from
// the same base of 1634, by +22 and +59, and 1634 + 22 + 59 is exactly what the run
// then reported. **The sum being right is not a reason to sum.** It was right because
// neither branch changed the *shape* of a section the other had counted, and that is a
// property of those two diffs rather than of arithmetic — the same addition was wrong
// the last time this line was merged, when #21 closed while a section describing the
// coercion it removed was still open. A sum is a prediction that can be checked in one
// command; check it. The MySQL figure is the SQLite one plus the 23 checks in the
// engine-only section below, which is the same difference it has always been — if that
// section did not change, the difference did not either.
//
// Checked once more on the next merge, which was #44's own correction coming across
// (§4ap: the live host is on Central, not the UTC this write-up first asserted). One
// check added, so 1779 was a confident prediction — and it was run anyway, because a
// prediction that turns out right is exactly what the paragraph above is warning about.
//
// **And the merge after that is where it went wrong.** Step 3 of the v2 roadmap added
// two checks to the engine-only section below and moved the MySQL figure by three, so
// from that commit until this one the MySQL arm expected one check more than the suite
// contains — a failure nothing here could see, because this container has no MySQL
// server and that arm never runs. The paragraph above describes the mistake precisely
// and was read while it was being made: a delta was carried across instead of checked.
//
// So the MySQL figure is no longer a delta. The section below is straight-line code —
// no loop, no conditional, no helper that checks more than once — so its checks can be
// *counted*, and there are 25 of them. The MySQL figure is this file's SQLite number
// plus that count, and both halves are things somebody can verify without a database.
// The SQLite number stays what the run reported.
//
// Step 5 moved it from 2068 to 2257, its mutation runs added the eleven checks its
// survivors asked for (2268), and changing which layer an unusable theme colour falls
// through to added three more, two of them in a subprocess. Running the suite as an
// install that has actually been set up (§4bg) then rewrote seven checks that were
// asserting this checkout's own configuration and added two that say what the rewrites
// gave up. None of that touched the engine-only section, so the count below it is still
// 25 — read, not assumed, which is the whole of the paragraph above.
//
// Then §4bi: the four readouts that describe *the machine* and had no seam between them
// and it — the PHP time zone note, the upload ceiling note, `ErrorPolicy::status()` in
// its entirety, and the log's request tag. 31 checks, all engine-independent, so 25 is
// still the difference and 2304 was what that run reported.
//
// Then the engine's own row, which had a number and a hardcoded `''` where the PHP row
// beside it had three bands — the version having been read off that card and written down
// eight days earlier without anything being done with it. 14 more checks, all through the
// seam and so all engine-independent again: 2320, and 25 is still the difference.
//
// Then §4bk, which is the first change to this number the MySQL arm had a vote in: three
// checks over the zero date, all engine-independent, and four writes this file had been
// making that MySQL will not accept — two of them replaced by handing the reader a row
// instead of a column, which is where they belonged. 2323, and 25 is still the
// difference, because nothing here added or removed a check inside that section.
//
// Then §4bl, which is the first change to this number that a *failing* MySQL run asked
// for rather than a local reading: four more of the same class, found only once the four
// in §4bk stopped killing the run before it reached them. Two engine-dependent readouts
// went through seams and lost the value they were asserting off the machine, the library
// block moved onto the type the column actually offers and gained the schema-and-Builder
// agreement that says why, and the role check was handed a row. 13 net, none of them
// inside the engine-only section, so 25 is still the difference. 2336 and 2361, and this
// is the first entry in this paragraph the MySQL arm has *confirmed* rather than been
// predicted at: run 32286293398 reported 2361 having never been asked locally.
//
// Then one more, in the same breath as §4bm: the `trim()` in `isUtcOffset()` survived the
// sweep, and a public seam is worth a check rather than a note about why a line is
// allowed to be untested. 25 is still the difference.
//
// **Then the merge with `main`, and neither number moved.** Work-lanes item 3 says to
// resolve this line by running the suite and never by adding two branches' deltas, and
// this is the case that would have punished the sum: `main` had written the same class of
// fix over the same file, so the merge's whole diff here is one comment. Adding the two
// deltas would have claimed checks that do not exist. The run said 2337, unchanged, and
// the engine-only section is untouched, so the MySQL figure stays its 25 above.
//
// **Then the merge with `main` that carried #16 across, and this time one moved.** Same
// file, same block, the same class of fix again — main had rewritten the edit-lock stamp
// checks this branch had already touched (§4ba there), so git conflicted rather than
// merging quietly. What survives the resolution is main's split of the write from the
// read plus this branch's zone-through-the-door form, and that is one check more than
// either side had alone: main's `editingSentence()` assertion had no counterpart here.
// Run, not summed — 2338, and the engine-only section is untouched again, so 25 still.
reportChecks(testIsMysql() ? 2539 : 2514);
