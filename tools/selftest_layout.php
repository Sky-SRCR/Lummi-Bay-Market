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
 * The same layout as a *basic* account's Builder would send it back.
 *
 * The difference is `db_id` on the section: a basic publish inserts no sections, so
 * that id is the only thing its content can be parented by. Without it the content
 * resolves to root level, which is layout — and refused since #30's follow-up. The
 * Builder has always sent it; a test payload that omits it is testing a client that
 * does not exist.
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
function publishAs(LayoutStore $layouts, Display $display, array $elements, $stamp, $isAdmin = true, $actorId = 1, Background $bg = null)
{
    global $pdo;
    $result = $layouts->publish($display, new PublishRequest(
        $elements, $bg ?: Background::unchanged(), $actorId, $isAdmin, $stamp
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
// It used to be accepted, with the block landing at root level on the publisher's own
// Display — scoping held, but a basic account had placed layout. Both halves are now
// one refusal: the forged id resolves to no section *of this Display*, so the content
// it parents is content outside every section, which this role may not add.
checkSame('rejected', $res->kind(), 'a forged db_id naming another Display\'s section is refused');
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
section('Only a block type this app knows is published');

// The column is an ENUM, and an ENUM is not an allowlist. MySQL matches a member
// case-insensitively, and reads a *quoted number* with no matching member as an
// index into the list — so `type: "1"` wrote a section. Nothing in the publish path
// looked at the value before handing it over, so a basic account, whose publish may
// not touch the section layout at all (ADR-0005), could put a section on the canvas
// at the top level with one hand-made POST.
//
// This fixture is SQLite, which stores any of these strings verbatim rather than
// coercing them, so what these checks prove is the refusal — not MySQL's behaviour
// behind it. That is the right way round: the refusal is what has to hold on both.

$driveT   = loadTestDisplay($pdo, $driveT->id());
$goodRows = count(elementsOf($pdo, $driveT->id()));
$goodStamp = $driveT->layoutStamp();

/** One content block of a stated type, in a section, with an otherwise valid layout. */
function layoutOfType($type, $extra = [])
{
    return [
        ['type' => 'section', 'temp_id' => 's1', 'width' => 600, 'height' => 380],
        array_merge(['type' => $type, 'parent_temp_id' => 's1', 'manual_content' => 'x'], $extra),
    ];
}

$res = publishAs($layouts, $driveT, layoutOfType('1'), $goodStamp);
checkSame('rejected', $res->kind(), 'a quoted number as a type is refused — MySQL would read it as a section');

$res = publishAs($layouts, $driveT, layoutOfType('Section'), $goodStamp);
checkSame('rejected', $res->kind(), 'and so is a type that differs only in case');

$res = publishAs($layouts, $driveT, layoutOfType('iframe'), $goodStamp);
checkSame('rejected', $res->kind(), 'and one that is simply not a block this app has');
checkMentions($res->message(), 'unknown type', 'the refusal says what was wrong');
checkMentions($res->message(), 'iframe', 'and quotes the value it would not store');
checkMentions($res->message(), 'Nothing was saved', 'and says nothing was saved');

// The one place a publish payload becomes a sentence somebody reads.
$res = publishAs($layouts, $driveT, layoutOfType('<img src=x onerror=alert(1)>'), $goodStamp);
checkSame('rejected', $res->kind(), 'a type made of markup is refused too');
check(strpos($res->message(), '<') === false && strpos($res->message(), '"') === false,
      'and nothing of what was sent survives into the message but letters and digits');

$res = publishAs($layouts, $driveT, [['type' => 'section', 'temp_id' => 's1'], 42], $goodStamp);
checkSame('rejected', $res->kind(), 'an entry that is not a block at all is refused');
checkMentions($res->message(), 'not a block at all', 'and named as such, not as a type');

$res = publishAs($layouts, $driveT, layoutOfType('text', ['block_subtype' => 'headline']), $goodStamp);
checkSame('rejected', $res->kind(), 'an unknown block style is refused as well');
checkMentions($res->message(), 'unknown style', 'and told apart from an unknown type');

$res = publishAs($layouts, $driveT, layoutOfType('text', ['block_subtype' => '4']), $goodStamp);
checkSame('rejected', $res->kind(), 'including the quoted-number form of that trick');

// The refusal is decided before the transaction opens, so there is nothing to roll
// back: the layout, the stamp and the publish record are all as they were.
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame($goodRows, count(elementsOf($pdo, $driveT->id())), 'a refused publish deleted nothing');
checkSame($goodStamp, $driveT->layoutStamp(), 'and did not advance the stamp');

// Every type the app does have still publishes — an allowlist that refuses real work
// is a worse defect than the one it fixes.
$everyType = [['type' => 'section', 'temp_id' => 's1', 'width' => 900, 'height' => 700]];
foreach (['text', 'image', 'video', 'carousel', 'marquee', 'table'] as $known) {
    $everyType[] = ['type' => $known, 'parent_temp_id' => 's1', 'manual_content' => 'x'];
}
$res = publishAs($layouts, $driveT, $everyType, $goodStamp);
check($res->isOk(), 'a layout using every known type publishes');
checkSame(7, count(elementsOf($pdo, $driveT->id())), 'and all seven elements landed');

// Absent, null and '' are all "not a branded block". '' is not an ENUM member, so
// letting it through to the column stores the invalid-enum empty string.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutOfType('text', ['block_subtype' => '']), $driveT->layoutStamp());
check($res->isOk(), 'a block stating no style publishes');
$stored = '';
foreach (elementsOf($pdo, $driveT->id()) as $row) {
    if ($row['type'] === 'text') { $stored = $row['block_subtype']; }
}
checkSame('free', $stored, 'and is stored as free, never as the empty string');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutOfType('text', ['block_subtype' => null]), $driveT->layoutStamp());
check($res->isOk(), 'and so does one stating null');

// Invariant 15 applies to an allowlist as much as to a column: the app's list and
// the ENUM `schema.php` converges to are two statements of one structure, and a
// widening that lands in only one of them is a publish refused for a type the
// database would have taken (item_title_2 and price_2 were exactly that, once).
function enumMembers($definition)
{
    preg_match_all("/'([^']*)'/", $definition, $m);
    $out = $m[1];
    sort($out);
    return $out;
}
$appTypes = LayoutStore::ELEMENT_TYPES;  sort($appTypes);
$appSubs  = LayoutStore::BLOCK_SUBTYPES; sort($appSubs);
checkSame(enumMembers(SCHEMA_ELEMENT_TYPE_ENUM), $appTypes,
          'the types a publish accepts are exactly the ones the column converges to');
checkSame(enumMembers(SCHEMA_BLOCK_SUBTYPE_ENUM), $appSubs,
          'and the same for block styles');

// ─────────────────────────────────────────────────────────────
section('A value no column can hold is refused, not coerced');

// Every one of these used to be `intval($el[…] ?? default)` or a raw string handed
// to a VARCHAR. Nothing failed: intval answered 0 for a word, MySQL clamped what was
// too big and cut what was too long, and the publish reported success — so the person
// went on looking at the layout they drew while the sign showed something else. There
// is no undo, which is why the answer is a refusal rather than a best guess.
//
// This fixture is SQLite and does not clamp or truncate anything, so what these prove
// is the refusal, not MySQL's behaviour behind it. That is the right way round.

$driveT    = loadTestDisplay($pdo, $driveT->id());
$goodRows  = count(elementsOf($pdo, $driveT->id()));
$goodStamp = $driveT->layoutStamp();

/** The valid layout above, with one field on the content block replaced. */
function layoutWithField($field, $value)
{
    return layoutOfType('text', [$field => $value]);
}

$res = publishAs($layouts, $driveT, layoutWithField('x_pos', 'left'), $goodStamp);
checkSame('rejected', $res->kind(), 'a position that is a word is refused, not read as 0');
checkMentions($res->message(), 'x_pos', 'and the refusal names the field');
checkMentions($res->message(), 'not a number', 'and says what was wrong with it');

$res = publishAs($layouts, $driveT, layoutWithField('width', ''), $goodStamp);
checkSame('rejected', $res->kind(), 'an empty width is refused rather than becoming a zero-width block');

$res = publishAs($layouts, $driveT, layoutWithField('y_pos', true), $goodStamp);
checkSame('rejected', $res->kind(), 'and so is a position sent as a boolean');

$res = publishAs($layouts, $driveT, layoutWithField('x_pos', 999999999), $goodStamp);
checkSame('rejected', $res->kind(), 'a position past any canvas is refused, not clamped by the column');
checkMentions($res->message(), 'far outside', 'and told apart from a value of the wrong shape');

$res = publishAs($layouts, $driveT, layoutWithField('height', -40), $goodStamp);
checkSame('rejected', $res->kind(), 'a negative height is refused');

// intval() truncates rather than rounds, so this used to store 12 and say nothing.
$res = publishAs($layouts, $driveT, layoutWithField('x_pos', 12.9), $goodStamp);
checkSame('rejected', $res->kind(), 'a fractional position is refused, not truncated to 12');
checkMentions($res->message(), 'whole number', 'and told apart from the other two number refusals');

$res = publishAs($layouts, $driveT, layoutWithField('font_size', 0), $goodStamp);
checkSame('rejected', $res->kind(), 'so is a font size of zero, which is text nobody can read');

$res = publishAs($layouts, $driveT, layoutWithField('z_index', -3), $goodStamp);
checkSame('rejected', $res->kind(), 'and a stacking order below the canvas, which used to be clamped to 1');

// The limit is generous on purpose: a block half off the edge is a real layout.
checkSame(2 * DisplayStore::CANVAS_MAX, LayoutStore::COORD_LIMIT,
          'the coordinate limit is twice the biggest canvas this app allows');
$res = publishAs($layouts, $driveT, layoutWithField('x_pos', LayoutStore::COORD_LIMIT), $goodStamp);
check($res->isOk(), 'a block at exactly that limit still publishes');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('x_pos', LayoutStore::COORD_LIMIT + 1),
                 $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'one pixel past it does not');

// Width 0 is admitted deliberately: this defect could already have written one, and
// refusing it would leave a sign nobody can publish without editing SQL.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('width', 0), $driveT->layoutStamp());
check($res->isOk(), 'a zero width publishes, because a row this bug already wrote may hold one');

// JSON gives a float for 850.0, and a float that is a whole number is a whole number.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('x_pos', 850.0), $driveT->layoutStamp());
check($res->isOk(), 'a whole number that arrives as a float publishes');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('locked', 'yes'), $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'a lock flag reading "yes" is refused — intval made it 0, meaning unlocked');
checkMentions($res->message(), 'neither on nor off', 'and says so in those terms');

$res = publishAs($layouts, $driveT, layoutWithField('hidden', 2), $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'and a hidden flag that is neither 0 nor 1');

$res = publishAs($layouts, $driveT, layoutWithField('locked', true), $driveT->layoutStamp());
check($res->isOk(), 'a real boolean is a flag and publishes');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('font_color', str_repeat('c', 60)),
                 $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'a colour longer than its column is refused, not silently cut in half');
checkMentions($res->message(), '50', 'and the refusal quotes the width that column has');

$res = publishAs($layouts, $driveT, layoutWithField('font_family', ['Arial']), $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'a font family sent as an array is refused');

$res = publishAs($layouts, $driveT, layoutWithField('manual_content', str_repeat('x', 70000)),
                 $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'content past what the column holds is refused rather than truncated');

$res = publishAs($layouts, $driveT, layoutWithField('asset_id', 'abc'), $driveT->layoutStamp());
checkSame('rejected', $res->kind(), 'a library id that is not a number is refused');
checkMentions($res->message(), 'library item', 'and named as a library item, not as a number');

// '' and 0 both mean "this block has no library row" at the insert site, so neither
// is judged — refusing them would refuse every hand-typed text block.
$res = publishAs($layouts, $driveT, layoutWithField('asset_id', ''), $driveT->layoutStamp());
check($res->isOk(), 'a blank library id means no library row and publishes');

$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('asset_id', 0), $driveT->layoutStamp());
check($res->isOk(), 'and so does a zero one');

// Absent and null are the same silence the insert sites read with `??`, so a null
// takes the column default rather than being refused for not being a number.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('x_pos', null), $driveT->layoutStamp());
check($res->isOk(), 'a field sent as null is not stated, so it takes the default');

// line_height is deliberately not refused here: #32 clamps it instead, because every
// stored value passes back out through the same formatting.
$driveT = loadTestDisplay($pdo, $driveT->id());
$res = publishAs($layouts, $driveT, layoutWithField('line_height', 99), $driveT->layoutStamp());
check($res->isOk(), 'an absurd line height is not this refusal — it is clamped, by decision #32');

// Nothing above was decided inside the transaction, so no refusal cost anybody a row.
$driveT = loadTestDisplay($pdo, $driveT->id());
$beforeRows = count(elementsOf($pdo, $driveT->id()));
$beforeStamp = $driveT->layoutStamp();
$res = publishAs($layouts, $driveT, layoutWithField('x_pos', 'left'), $beforeStamp);
checkSame('rejected', $res->kind(), 'a refused publish is refused');
$driveT = loadTestDisplay($pdo, $driveT->id());
checkSame($beforeRows, count(elementsOf($pdo, $driveT->id())), 'and deleted nothing');
checkSame($beforeStamp, $driveT->layoutStamp(), 'and did not advance the stamp');

// What the Builder actually sends, stored exactly as sent. An allowlist that refuses
// real work is a worse defect than the one it fixes.
$asBuilderSends = [
    ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 0, 'y_pos' => 0,
     'width' => 900, 'height' => 700, 'section_bg' => null, 'locked' => 0,
     'sort_order' => 0, 'z_index' => 1, 'hidden' => 0],
    ['type' => 'text', 'block_subtype' => 'price', 'parent_temp_id' => 's1',
     'x_pos' => 850, 'y_pos' => 120, 'width' => 300, 'height' => 90,
     'asset_id' => '', 'manual_content' => 'Sockeye 18.99', 'save_to_db_pool' => false,
     'font_family' => 'Arial', 'font_size' => 42, 'font_color' => '#ffcc00',
     'font_weight' => 'bold', 'font_style' => 'normal', 'line_height' => 1.4,
     'text_align' => 'center', 'locked' => 1, 'sort_order' => 0, 'z_index' => 4,
     'hidden' => 1],
];
$res = publishAs($layouts, $driveT, $asBuilderSends, $driveT->layoutStamp());
check($res->isOk(), 'a payload shaped the way the Builder sends one publishes');
$priceRow = null;
foreach (elementsOf($pdo, $driveT->id()) as $row) {
    if ($row['type'] === 'text') { $priceRow = $row; }
}
checkSame(850, intval($priceRow['x_pos']), 'and the position it sent is the position stored');
checkSame(42,  intval($priceRow['font_size']), 'and the font size');
checkSame(1,   intval($priceRow['locked']), 'and the lock flag');
checkSame(1,   intval($priceRow['hidden']), 'and the hidden flag');
checkSame(4,   intval($priceRow['z_index']), 'and the stacking order');

// Invariant 15 again: these limits are a second statement of what `schema.sql`
// declares, and a column widened in one place and not the other is a publish refused
// for a value the database would have taken.
function schemaColumnTypes($table)
{
    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \((.*?)\n\)/s', $sql, $m)) {
        return [];
    }
    $out = [];
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^\s+([a-z_]+)\s+([A-Za-z]+)(\((\d+)\))?/', $line, $c)) {
            $out[$c[1]] = ['type' => strtoupper($c[2]),
                           'width' => isset($c[4]) ? intval($c[4]) : null];
        }
    }
    return $out;
}
$columns = schemaColumnTypes('canvas_elements');
check(count($columns) > 20, 'schema.sql parses, so the two checks below are not vacuous');

$declared = [];
foreach ($columns as $name => $col) {
    if ($col['type'] === 'VARCHAR') { $declared[$name] = $col['width']; }
}
$applied = LayoutStore::TEXT_LIMITS;
ksort($declared); ksort($applied);
checkSame($declared, $applied,
          'the lengths a publish accepts are exactly the widths schema.sql declares');

$notNumbers = [];
foreach (array_keys(LayoutStore::NUMBER_RANGE) as $field) {
    if (!isset($columns[$field]) || $columns[$field]['type'] !== 'INT') { $notNumbers[] = $field; }
}
checkSame([], $notNumbers, 'and every field range-checked as a number is an INT column');

// ─────────────────────────────────────────────────────────────
section('A basic publish may return root content, never invent it');

// The residual §4u named and left standing. A `text` block with no parent_temp_id
// lands with section_id NULL, which is layout — and placing layout is not what this
// role does (ADR-0005). The type allowlist closed the forged-`type` route in; a plain
// text block with no parent still walked through. The payload now says which root
// blocks it is *returning*: content carries db_id the way sections always have.

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
checkSame('rejected', $res->kind(), 'a root block this account invented is refused');
checkMentions($res->message(), 'not inside any section', 'and the refusal says what is wrong with it');
checkMentions($res->message(), 'admin', 'and who can do it instead');
$sign = loadTestDisplay($pdo, $sign->id());
checkSame($before, count(elementsOf($pdo, $sign->id())), 'the refusal deleted nothing');
checkSame($beforeStamp, $sign->layoutStamp(), 'and did not advance the stamp');

// One row cannot be two blocks: returning the same id twice is inventing one of them.
$twice   = basicWithRoot($sectionId, $rootId);
$twice[] = ['type' => 'image', 'db_id' => $rootId, 'manual_content' => 'uploads/logo.png'];
$res = publishAs($layouts, $sign, $twice, $sign->layoutStamp(), false, 2);
checkSame('rejected', $res->kind(), 'and the same root id returned twice is refused');

// A root id belonging to another Display is not this Display's to return.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $other, [
    ['type' => 'image', 'db_id' => $rootId, 'manual_content' => 'uploads/logo.png'],
], loadTestDisplay($pdo, $other->id())->layoutStamp(), false, 2);
checkSame('rejected', $res->kind(), 'a root id from another Display is refused');

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
section('One address, one section');

// #31. temp_id, parent_temp_id and db_id are how a payload points at itself, and for
// the length of one publish they are PHP array keys. Two sections answering to one
// address does not fail — the map is built by assignment, so the second silently took
// the first's place, and every block aimed at the first was inserted into the second.

$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 0,   'width' => 300, 'height' => 300],
    ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 400, 'width' => 300, 'height' => 300],
    ['type' => 'text', 'parent_temp_id' => 's1', 'manual_content' => 'Which section?'],
], $sign->layoutStamp(), true, 1);
checkSame('rejected', $res->kind(), 'two sections sharing a temporary id is refused');
checkMentions($res->message(), 'sharing one temporary id', 'and the refusal says which trap it hit');
checkMentions($res->message(), 'Reload the display', 'and what to do about it');

// '5' and 5 are one array key, so they are one section — a payload that looks like it
// names two would have gone through the same overwrite.
$sign = loadTestDisplay($pdo, $sign->id());
$before = count(elementsOf($pdo, $sign->id()));
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => '5', 'width' => 300, 'height' => 300],
    ['type' => 'section', 'temp_id' => 5,   'width' => 300, 'height' => 300],
], $sign->layoutStamp(), true, 1);
checkSame('rejected', $res->kind(), 'and so are the string and the number of the same id');
checkSame($before, count(elementsOf($pdo, $sign->id())), 'a refused publish deleted nothing');

// The other address. A basic publish resolves a section by db_id, so two canvas
// sections claiming one stored row pour both their contents into it.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => 's1', 'db_id' => $sectionId],
    ['type' => 'section', 'temp_id' => 's2', 'db_id' => $sectionId],
    ['type' => 'text', 'parent_temp_id' => 's2', 'manual_content' => 'Merged'],
], $sign->layoutStamp(), false, 2);
checkSame('rejected', $res->kind(), 'two sections claiming one stored section is refused too');
checkMentions($res->message(), 'same stored section', 'and told apart from the temporary id');

// Shape. An array subscript throws, so this used to be 'failed' — a fault, when it is
// a payload being refused, and the two say different things about whose problem it is.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => ['an', 'array'], 'width' => 300, 'height' => 300],
], $sign->layoutStamp(), true, 1);
checkSame('rejected', $res->kind(), 'an array where a temporary id belongs is a refusal, not a fault');
checkMentions($res->message(), 'not a name this app can use', 'and says so in those terms');

// The shapes that do not throw are the worse half: PHP folds true, 1.5 and 1.9 all
// onto the key 1, so sections that look distinct share one address.
foreach ([true, 1.5, 2.5] as $shape) {
    $sign = loadTestDisplay($pdo, $sign->id());
    $res = publishAs($layouts, $sign, [
        ['type' => 'section', 'temp_id' => $shape, 'width' => 300, 'height' => 300],
    ], $sign->layoutStamp(), true, 1);
    checkSame('rejected', $res->kind(), 'a temporary id of ' . gettype($shape)
              . ' is refused before it can fold onto a whole-number key');
}

// parent_temp_id is the same field read from the other end, and db_id has to be a
// row number — intval(['x']) is 1, which is a real section on some Display.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => 's1', 'width' => 300, 'height' => 300],
    ['type' => 'text', 'parent_temp_id' => 1.5, 'manual_content' => 'x'],
], $sign->layoutStamp(), true, 1);
checkSame('rejected', $res->kind(), 'a parent_temp_id of the same shape is refused as well');

$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => 's1', 'db_id' => ['x'], 'width' => 300, 'height' => 300],
], $sign->layoutStamp(), true, 1);
checkSame('rejected', $res->kind(), 'and a db_id that is not a row number');

// An allowlist that refuses real work is worse than the defect it fixes. What the
// Builder actually sends — one temp_id per section, one db_id per section, blocks
// pointing at either — still publishes.
$sign = loadTestDisplay($pdo, $sign->id());
$res = publishAs($layouts, $sign, [
    ['type' => 'section', 'temp_id' => 'tmp-a1b2c3', 'x_pos' => 0,   'width' => 300, 'height' => 300],
    ['type' => 'section', 'temp_id' => 'tmp-d4e5f6', 'x_pos' => 400, 'width' => 300, 'height' => 300],
    ['type' => 'text', 'parent_temp_id' => 'tmp-a1b2c3', 'manual_content' => 'Left'],
    ['type' => 'text', 'parent_temp_id' => 'tmp-d4e5f6', 'manual_content' => 'Right'],
], $sign->layoutStamp(), true, 1);
check($res->isOk(), 'two sections with two ids, each holding its own block, publishes');

$byParent = [];
foreach (elementsOf($pdo, $sign->id()) as $row) {
    if ($row['type'] === 'section') { continue; }
    $byParent[intval($row['section_id'])] = $row['manual_content'];
}
checkSame(2, count($byParent), 'and the two blocks are in two different sections');

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

$res = $admin->create(['title' => 'Drive-Thru', 'canvas_width' => 1920, 'canvas_height' => 1080]);
check($res->isOk(), 'a Display is created from a title and a canvas size alone');
$driveT = $res->display();
checkSame('drive-thru', $driveT->tag(), 'its tag was suggested from its title');
checkSame(1920, $driveT->canvasWidth(), 'it has the width it was given');
checkSame(true, $driveT->isActive(), 'it is active from the moment it exists');
check(strpos($res->message(), 'viewer.php?display=drive-thru') !== false,
      'and the confirmation gives the address to point a Screen at');

$res = $admin->create(['title' => 'Second Drive-Thru', 'tag' => 'drive-thru',
                       'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(DisplayResult::CONFLICT, $res->kind(), 'a tag already in use is refused');
checkSame('tag', $res->field(), 'and the refusal names the field to fix');
checkSame(1, $store->count(), 'nothing was created');

$res = $admin->create(['title' => '', 'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(DisplayResult::INVALID, $res->kind(), 'a Display with no title is refused');
$res = $admin->create(['title' => 'Bad Tag', 'tag' => 'Lobby_1', 'canvas_width' => 1920, 'canvas_height' => 1080]);
checkSame(DisplayResult::INVALID, $res->kind(), 'a tag with an underscore is refused');
$res = $admin->create(['title' => 'No Size', 'canvas_width' => 0, 'canvas_height' => 0]);
checkSame(DisplayResult::INVALID, $res->kind(), 'a Display with no canvas size is refused');
checkSame('canvas_width', $res->field(), 'and the refusal points at the size');
checkSame(1, $store->count(), 'still nothing created');

// Give the drive-thru a layout worth duplicating: a section with a block inside.
$res = publishAs($layouts, $driveT, layoutWith('Drive-thru $9.99'), '0');
check($res->isOk(), 'the drive-thru gets a layout to duplicate');
$driveT = loadTestDisplay($pdo, $driveT->id());

$res = $admin->create(['title' => 'Portrait Board', 'canvas_width' => 1080, 'canvas_height' => 1920,
                       'duplicate_from' => 'drive-thru']);
checkSame(DisplayResult::INVALID, $res->kind(), 'duplicating into a different shape is refused (ADR-0004)');
check(strpos($res->message(), '1920 × 1080') !== false, 'and the refusal states the shape it would have copied');
checkSame(1, $store->count(), 'the Display was not created either');

$res = $admin->create(['title' => 'Lobby', 'canvas_width' => 1920, 'canvas_height' => 1080,
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

$res = $admin->create(['title' => 'Third', 'canvas_width' => 1920, 'canvas_height' => 1080,
                       'duplicate_from' => 'no-such-display']);
checkSame(DisplayResult::INVALID, $res->kind(), 'duplicating from a Display that does not exist is refused');

checkSame(false, $layouts->copyLayout($driveT, loadTestDisplay($pdo, $lobby->id())),
          'a layout is never copied over a Display that already has one');

// ---- Editing -----------------------------------------------------------------
checkSame('lobby', $lobby->tag(), 'the duplicate was tagged from its own title, not the original\'s');

$res = $admin->updateDetails($lobby, ['title' => 'Lobby Screen', 'tag' => 'lobby-screen',
                                      'location' => 'Front entrance']);
check($res->isOk(), 'title, tag and location can be edited');
$lobby = $res->display();
checkSame('lobby-screen', $lobby->tag(), 'the tag changed');
checkSame('Front entrance', $lobby->location(), 'the location is stored');
check(strpos($res->message(), 'viewer.php?display=lobby-screen') !== false,
      'a rename says what address the Screen must be pointed at now');

$res = $admin->updateDetails($lobby, ['title' => 'Lobby Screen', 'tag' => 'lobby-screen']);
check($res->isOk() && strpos($res->message(), 'address changed') === false,
      'saving without changing the tag does not claim the address changed');

$res = $admin->updateDetails($lobby, ['title' => 'Lobby Screen', 'tag' => 'drive-thru']);
checkSame(DisplayResult::CONFLICT, $res->kind(), 'renaming onto another Display\'s tag is refused');
checkSame('lobby-screen', $store->forId($lobby->id())->tag(), 'and the tag is unchanged');

$res = $admin->updateDetails($lobby, ['title' => 'Lobby', 'tag' => '']);
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

$res = $admin->destroy($lobby, 'lobbi');
checkSame(DisplayResult::INVALID, $res->kind(), 'a mistyped tag does not delete a Display');
checkSame(2, $store->count(), 'both Displays are still there');
checkSame(2, count(elementsOf($pdo, $lobby->id())), 'and its layout is untouched');

$res = $admin->destroy($lobby, ' LOBBY ');
check($res->isOk(), 'the typed tag is matched after trimming and lowercasing');
check(strpos($res->message(), '2 elements were deleted') !== false, 'and the confirmation says what was lost');
checkSame(1, $store->count(), 'the Display is gone');
checkSame(0, count(elementsOf($pdo, $lobby->id())), 'its elements went with it');
checkSame(2, count(elementsOf($pdo, $driveT->id())), 'and the other Display kept every one of its own');
checkSame(2, count(allElements($pdo)), 'nothing was orphaned');

$grants = allGrants($pdo);
checkSame(1, count($grants), 'the grant on the deleted Display went with it');
checkSame($driveT->id(), intval($grants[0]['display_id']), 'and the surviving Display kept its own');

// The roadmap decided there is no "last Display" rule: an installation may have
// none, and the Builder says so rather than the panel refusing.
$res = $admin->destroy($store->forTag('drive-thru'), 'drive-thru');
check($res->isOk(), 'the last Display can be deleted too');
checkSame(0, $store->count(), 'leaving none');
checkSame(0, count(allElements($pdo)), 'and no elements behind');
checkSame(0, count(allGrants($pdo)), 'and no grant pointing at a Display that is gone');

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
$pdo->exec("INSERT INTO users (username, role) VALUES ('jane','basic')");
$janeId = intval($pdo->lastInsertId());

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
// basic account's publish has nowhere to put content until one exists — that is the
// same rule, seen from the other side.
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
    layoutWith('Published over somebody'), Background::unchanged(), 1, true, $driveT->layoutStamp()
));
checkSame('locked', $res->kind(), 'a publish is refused while somebody else holds the lock');
check(strpos($res->message(), 'clerk') !== false, 'the refusal names who is editing');
check(strpos($res->message(), 'still on screen') !== false,
      'and says the work is not lost, because it is not');
checkSame($before, count(elementsOf($pdo, $driveT->id())), 'and nothing was written');

// The holder's own publish goes through, and keeps the lock alive.
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('The holder publishes'), Background::unchanged(), 2, true, $driveT->layoutStamp()
));
check($res->isOk(), 'the account holding the lock may publish');
$d = $store->forId($driveT->id());
checkSame(true, $d->lockState()->heldBy(2), 'and publishing keeps the lock — it is a real interaction');

// The lock refusal comes first: "reload and re-apply" is bad advice while somebody
// else is mid-edit, because re-applying would only be refused again.
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('Stale and locked out'), Background::unchanged(), 1, true, 'nonsense-stamp'
));
checkSame('locked', $res->kind(), 'a publish that is both stale and locked out reports the lock');

// A lapsed lock nobody claimed does not stop the person who let it lapse.
ageTestLock($pdo, $driveT->id(), LockState::IDLE_LAPSE_SECONDS + 1);
$driveT = $store->forId($driveT->id());
$res = $layouts->publish($driveT, new PublishRequest(
    layoutWith('Back after a break'), Background::unchanged(), 1, true, $driveT->layoutStamp()
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
    layoutWith('Publishing after being ousted'), Background::unchanged(), 1, true, $driveT->layoutStamp()
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
    checkSame(null, PublishRequest::fromPostedJson($case[0], $bg, 1, true, '0'),
              $case[1] . ' is not a layout');
}

// The other half: an empty array really is a layout. Somebody who deleted every
// block and published meant it, and must not be refused.
$emptyReq = PublishRequest::fromPostedJson('[]', $bg, 1, true, '0');
check($emptyReq !== null,               'an empty array is still a publish');
checkSame([], $emptyReq->elements(),    'and it carries no elements');

// End to end, on a Display that has something to lose.
$pdo     = newTestDb();
$store   = new TestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$victim  = makeTestDisplay($pdo, 'victim', 'Victim');
$layouts->publish($victim, new PublishRequest(
    layoutWith('Sockeye 18.99'), Background::unchanged(), 1, true, $victim->layoutStamp()
));
$victim = $store->forId($victim->id());
checkSame(2, count(elementsOf($pdo, $victim->id())), 'the Display starts with a published layout');

checkSame(null, PublishRequest::fromPostedJson('[{"type":', $bg, 1, true, $victim->layoutStamp()),
          'and an unreadable publish for it never reaches the store');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'so its elements are still there');

// ─────────────────────────────────────────────────────────────
section('A hostile publish payload is a refusal, never an escaping error');

// toPlainText() is typed `string`, so manual_content arriving as an object raises a
// TypeError — which extends Error, not Exception. `catch (Exception)` let it escape
// *after* both DELETEs had run: no rollback of the module's own, no result object,
// and the Builder reported "Network error." for a rejected publish.
//
// It is caught before the transaction now (#30) and so answers 'rejected' rather
// than 'failed'. The array temp_id below it used to be what still reached the net;
// #31 refuses that before the transaction too, so the net gets its own check.
$victim = $store->forId($victim->id());
$res = $layouts->publish($victim, new PublishRequest(
    [['type' => 'text', 'manual_content' => ['not' => 'a string'], 'temp_id' => 't1']],
    Background::unchanged(), 1, true, $victim->layoutStamp()
));
checkSame('rejected', $res->kind(), 'manual_content as an object is refused, never a fatal');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'and the layout it would have replaced survives');
checkSame(false, $pdo->inTransaction(), 'with no transaction left open behind it');

// Something that throws where no pre-transaction pass can see it coming: an asset_id
// shaped exactly like a real one, pointing at a library row that does not exist. The
// foreign key refuses it from inside the transaction, after both DELETEs have run —
// which is the case the Throwable net exists for, and the case that has to roll back.
$victim = $store->forId($victim->id());
$res = $layouts->publish($victim, new PublishRequest(
    [['type' => 'section', 'temp_id' => 's1'],
     ['type' => 'image', 'parent_temp_id' => 's1', 'asset_id' => 987654]],
    Background::unchanged(), 1, true, $victim->layoutStamp()
));
checkSame('failed', $res->kind(), 'a failure the payload checks cannot foresee is a failed result');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'and the layout the DELETEs had already taken is rolled back');
checkSame(false, $pdo->inTransaction(), 'with nothing left open behind that either');

// ─────────────────────────────────────────────────────────────
section('The edit lock covers every element write, not just publishing');

$pdo     = newTestDb();
$store   = new TestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$sign    = makeTestDisplay($pdo, 'deli', 'Deli Case');
$layouts->publish($sign, new PublishRequest(
    layoutWith('Chowder 6.50'), Background::unchanged(), 1, true, $sign->layoutStamp()
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
    layoutWith('Cascade, explicitly'), Background::unchanged(), 1, true, $sign->layoutStamp()
));
$pdo->exec("PRAGMA foreign_keys = OFF");
$sectionId = 0;
foreach (elementsOf($pdo, $sign->id()) as $row) { if ($row['type'] === 'section') { $sectionId = intval($row['id']); } }
checkSame(true, $layouts->deleteElement($store->forId($sign->id()), $sectionId, 1)->isOk(),
          'a section is deleted with foreign keys switched off');
checkSame(0, count(elementsOf($pdo, $sign->id())),
          'and its children go with it without relying on ON DELETE CASCADE');
$pdo->exec("PRAGMA foreign_keys = ON");

// ─────────────────────────────────────────────────────────────
section('Brand Standards: shared typography, and what may change it');

$pdo   = newTestDb();
$store = new TestDisplayStore($pdo);
$brand = new BrandStyles($pdo);
$one   = makeTestDisplay($pdo, 'one', 'Sign One');
$two   = makeTestDisplay($pdo, 'two', 'Sign Two');

checkSame(null, $store->editedByAnyoneElse(1), 'nobody is editing anything to begin with');
$store->claimLock($two, 2);
$busy = $store->editedByAnyoneElse(1);
check($busy !== null,        'a lock held by another account is visible to the whole installation');
checkSame('two', $busy ? $busy->tag() : '', 'and it names which Display');
checkSame(null, $store->editedByAnyoneElse(2), 'the holder is not blocked by their own lock');

ageTestLock($pdo, $two->id(), LockState::IDLE_LAPSE_SECONDS + 60);
checkSame(null, $store->editedByAnyoneElse(1), 'a lapsed lock does not block a brand change');

// Absent means untouched — the defect that reset every sign to black Arial 16.
$before = $brand->all();
checkSame(0, $brand->save([]), 'a save carrying no typography writes nothing');
checkSame($before, $brand->all(), 'and leaves every stored style exactly as it was');

checkSame(1, $brand->save(['price' => ['font_family' => 'Georgia', 'font_size' => 44,
                                       'font_color' => '#00FF00', 'font_weight' => 'bold',
                                       'font_style' => 'normal', 'line_height' => 1.25]]),
          'a save carrying one type writes one row');
$after = $brand->all();
checkSame('Georgia', $after['price']['font_family'],   'the submitted family is stored');
checkSame('#00ff00', $after['price']['font_color'],    'a colour is normalised to lowercase hex');
checkSame($before['item_title'], $after['item_title'], 'and the five types it did not carry are untouched');

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
$store = new TestDisplayStore($pdo);
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
section('An Asset Library entry knows which signs depend on it');

$pdo     = newTestDb();
$store   = new TestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$a = makeTestDisplay($pdo, 'aa', 'Sign A');
$b = makeTestDisplay($pdo, 'bb', 'Sign B');

// Both signs publish the same words. This used to hand them one shared row,
// because the pool de-duplicated by exact content — and a single delete then
// blanked that line on both of them, permanently. Two rows now.
$shared = ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'Sockeye  18.99',
           'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
$layouts->publish($a, new PublishRequest([$shared], Background::unchanged(), 1, true, $a->layoutStamp()));
$store->releaseLock($a, 1);
$layouts->publish($b, new PublishRequest([$shared], Background::unchanged(), 1, true, $b->layoutStamp()));
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
$store   = new TestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$library = new AssetLibrary($pdo);
$sign    = makeTestDisplay($pdo, 'sweep', 'Deli Board');

$block = function ($words) {
    return ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => $words,
            'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
};

$fresh = $store->forId($sign->id());
$layouts->publish($sign, new PublishRequest([$block('Sockeye  18.99')], Background::unchanged(), 1, true, $fresh->layoutStamp()));
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  19.99')], Background::unchanged(), 1, true, $fresh->layoutStamp()));
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  21.99')], Background::unchanged(), 1, true, $fresh->layoutStamp()));

checkSame(1, intval($pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn()),
          'three publishes of one block leave one library entry, not three');
checkSame('Sockeye  21.99', $pdo->query("SELECT content FROM assets")->fetchColumn(),
          'and it is the words that are on the sign now');
checkSame(1, intval($pdo->query("SELECT COUNT(*) FROM canvas_elements WHERE asset_id IS NOT NULL")->fetchColumn()),
          'the block still points at it — the sweep did not cut the line it was reading');

// What the sweep must never touch: a row a person made. An unused one is not
// junk, it is the image somebody uploaded ready for next week.
$mine = $library->create('image', 'uploads/promo.jpg', 'Summer Promo Banner');
check($mine > 0, 'an admin can still add an entry of their own');
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  22.99')], Background::unchanged(), 1, true, $fresh->layoutStamp()));
check($library->forId($mine) !== null,
      'and publishing does not sweep it away, though no sign uses it');

// A row somebody has renamed is theirs, whatever created it.
$autoId = intval($pdo->query("SELECT id FROM assets WHERE auto_pooled = 1")->fetchColumn());
$library->update($autoId, 'Sockeye price', 'Sockeye  22.99');
checkSame(0, intval($pdo->query("SELECT auto_pooled FROM assets WHERE id = " . $autoId)->fetchColumn()),
          'naming an auto-saved entry makes it yours');
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Sockeye  23.99')], Background::unchanged(), 1, true, $fresh->layoutStamp()));
check($library->forId($autoId) !== null,
      'so the next publish leaves it alone even once nothing points at it');

// The half a publish cannot reach: a block deleted from the admin Work Area
// releases its entry with no publish anywhere near it. That is the tidy button.
$fresh = $store->forId($sign->id());
$layouts->publish($fresh, new PublishRequest([$block('Halibut  26.99')], Background::unchanged(), 1, true, $fresh->layoutStamp()));
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
$store   = new TestDisplayStore($pdo);
$layouts = newTestLayoutStore($pdo);
$library = new AssetLibrary($pdo);
$one = makeTestDisplay($pdo, 'one', 'Deli Board');
$two = makeTestDisplay($pdo, 'two', 'Lobby Screen');

$words = ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'OPEN 7 DAYS',
          'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
$layouts->publish($one, new PublishRequest([$words], Background::unchanged(), 1, true, $one->layoutStamp()));
$store->releaseLock($one, 1);
$layouts->publish($two, new PublishRequest([$words], Background::unchanged(), 1, true, $two->layoutStamp()));
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
    Background::unchanged(), 1, true, $fresh->layoutStamp()));

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
$noLib->exec("CREATE TRIGGER no_pool_writes BEFORE INSERT ON assets
              BEGIN SELECT RAISE(ABORT, 'library is read-only'); END");
$noStore = new TestDisplayStore($noLib);
$noLay   = newTestLayoutStore($noLib);
$noSign  = makeTestDisplay($noLib, 'nolib', 'Deli Board');

checkSame(0, (new AssetLibrary($noLib))->pool('text', 'Sockeye  18.99'),
          'a pool write that cannot happen returns no id, rather than id 0 as a link');

$result = $noLay->publish($noSign, new PublishRequest(
    [['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'Sockeye  18.99',
      'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60]],
    Background::unchanged(), 1, true, $noSign->layoutStamp()));
checkSame(true, $result->isOk(), 'the publish still succeeds');
$kept = $noLib->query("SELECT manual_content, asset_id FROM canvas_elements")->fetch();
checkSame('Sockeye  18.99', $kept['manual_content'], 'and the words stay on the block, where they render');
checkSame(null, $kept['asset_id'],   'pointing at nothing at all rather than at a row that does not exist');

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
function newCompletion(PDO $pdo, AccountStore $accounts = null)
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
checkSame(7, count($columns), 'and every runtime-added column is accounted for');
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
$shape = convergedSchemaShape();
$shape['columns']['users']['failed_attempts'] = ['type' => 'int(11)',  'nullable' => false];
$shape['columns']['users']['last_failed_at']  = ['type' => 'datetime', 'nullable' => true];
$shape['columns']['users']['locked_until']    = ['type' => 'datetime', 'nullable' => true];
$pPdo = newTestDb();
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
checkSame(['seed_block_styles', 'seed_legacy_display'], planSteps($converged),
          'only the two steps a catalogue cannot answer are left, and both are a small COUNT');

// The fallback has to be the old behaviour exactly, or a host whose catalogue
// cannot be read would quietly stop converging.
$blind = signageSchemaPlan(SchemaFacts::unknown());
checkSame(17, count(planStatements($blind)),
          'a database whose catalogue cannot be read is issued every statement, as before');
checkSame(4, count(planSteps($blind)), 'and every step');
checkSame(false, SchemaFacts::unknown()->known(), 'and it says so rather than answering false');
checkSame(null, SchemaFacts::unknown()->hasColumn('assets', 'auto_pooled'),
          'an unknown catalogue answers "cannot tell", never "not there"');

// The fixture is SQLite: no information_schema. This is the case the fallback
// exists for, so it is worth proving it is reached rather than assumed.
checkSame(false, readSchemaFacts(newTestDb())->known(),
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

// A catalogue that answers but knows nothing about this app is not a database with
// no tables — it is a question that did not land. Reading it as "everything is
// missing" would issue two CREATE TABLEs and five foreign keys against a database
// that has them all.
$empty = readSchemaFacts(fakeCatalogue(['columns' => [], 'indexes' => [], 'constraints' => []]));
checkSame(false, $empty->known(), 'a catalogue with nothing to say about this app is unknown, not empty');
checkSame(17, count(planStatements(signageSchemaPlan($empty))),
          'so it falls back to trying everything rather than creating what already exists');

// ---- One thing missing asks for exactly that thing ---------------------------

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

// ---- The steps, and what a failure now looks like ----------------------------

// SQLite rejects `INSERT IGNORE`, which makes it a useful witness: a true return
// can only mean the count found all six types and the statement was never sent.
$bPdo = newTestDb();
checkSame(true, seedBlockStyles($bPdo), 'a complete set of branded block types is not re-seeded');
$bPdo->exec("DELETE FROM block_styles WHERE block_type = 'price_2'");
checkSame(false, seedBlockStyles($bPdo), 'a missing one makes it try the seed');

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
$aDisps = new TestDisplayStore($aPdo);
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
$oldPdo = newTestDb();
$oldPdo->exec("CREATE TABLE u2 AS SELECT id, username, email, role, is_active FROM users");
$oldPdo->exec("DROP TABLE users");
$oldPdo->exec("ALTER TABLE u2 RENAME TO users");
$oldStore = new AccountStore($oldPdo);
checkSame(false, $oldStore->isClosed(1), 'without the column, no account reads as closed');
checkSame(2, count($oldStore->open()),   'and every account is still in service');
checkSame([], $oldStore->closed(),       'with none of them closed');
checkSame(false, (new AccountAdmin($oldPdo, $oldStore, new GrantStore($oldPdo), new TestDisplayStore($oldPdo)))
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
$xStore  = new TestDisplayStore($xPdo);
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
$yStore = new TestDisplayStore($yPdo);
$yLobby = makeTestDisplay($yPdo, 'lobby', 'Lobby');
$yDeli  = makeTestDisplay($yPdo, 'deli', 'Deli Case');
$yBreak = new DisplayAdmin($yPdo, $yStore, newTestLayoutStore($yPdo), new RefusingGrantStore($yPdo));
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
$zStore = new TestDisplayStore($zPdo);
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
$wStore = new TestDisplayStore($wPdo);
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
    'tag' => 'drive-through', 'title' => 'Drive-Thru', 'location' => '',
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
    'tag' => 'drive-thru', 'title' => 'Drive-Thru', 'location' => '',
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
$before = filesize($logPath);
$was    = error_reporting(0);
ErrorPolicy::handleError(E_WARNING, 'a deliberately suppressed call', '', 0);
error_reporting($was);
clearstatcache();
checkSame($before, filesize($logPath), 'a suppressed diagnostic is not logged at all');

// A shared host has a disk quota, and this file is appended to by every request
// forever.
file_put_contents($logPath, str_repeat('x', ErrorPolicy::MAX_LOG_BYTES + 1));
ErrorPolicy::log('the entry that tipped it over');
check(file_exists($logPath . '.1'), 'an oversized log is rotated rather than grown forever');
check(filesize($logPath) < 1024, 'and the live file starts again');

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
checkSame(19, count($guessed), 'with no catalogue, every statement in the plan is a guess');
checkSame(['seed_block_styles', 'seed_legacy_display'], $certain,
          'and the only certainties are the two steps that ask the rows, not the catalogue');
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
checkMentions($body, 'duplicate column', 'and the reason the database gave');
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
$wiredPdo = newTestDb();
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
// The interleaving itself cannot be produced inside one process; this covers the
// question it asks.
$idPdo = newTestDb();
checkSame(0, legacyDisplayId($idPdo), 'with no Display at all there is nothing to point at');
makeTestDisplay($idPdo, LEGACY_DISPLAY_TAG, 'Drive-Thru');
check(legacyDisplayId($idPdo) > 0, 'the drive-thru Display is found by its tag');
$idPdo->exec("UPDATE displays SET tag = 'renamed-by-an-admin'");
check(legacyDisplayId($idPdo) > 0, 'and by being the oldest when an admin has renamed it');

// A seed that genuinely cannot write is the one an admin needs to hear about.
$refuses = newTestDb();
$refuses->exec("DELETE FROM canvas_elements");
$refuses->exec("DELETE FROM displays");
$refuses->exec("CREATE TRIGGER no_displays BEFORE INSERT ON displays
                BEGIN SELECT RAISE(ABORT, 'displays is read-only'); END");
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
try { $healStore->forTag('drive-thru'); } catch (PDOException $e) { $healThrew = true; }
checkSame(true, $healThrew, 'a table this fixture cannot recreate still ends in the error');
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

checkMentions(UploadLimit::droppedBodyMessage(), 'too large',
              'and what the user is told names the problem');
checkMentions(UploadLimit::droppedBodyMessage(), 'Nothing was changed',
              'and says nothing was changed');
check(strpos(UploadLimit::droppedBodyMessage(), 'token') === false,
      'and never mentions a security token, which was the old answer');

reportChecks(923);
