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
check($res->isOk(), 'the forged publish is accepted as a publish');

checkSame($lobbyBefore, count(elementsOf($pdo, $lobby->id())), 'lobby gained nothing from the forged publish');
$injected = null;
foreach (elementsOf($pdo, $driveT->id()) as $row) {
    if ($row['manual_content'] === 'Injected') { $injected = $row; }
}
check($injected !== null, 'the injected block landed on the publisher\'s own Display');
checkSame(null, $injected['section_id'], 'and was not parented into the other Display\'s section');

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

$res = publishAs($layouts, $driveT, layoutWith('bg4'), $driveT->layoutStamp(), false, 2, Background::color('#ffffff'));
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
// would be no permission at all.
$lobby = loadTestDisplay($pdo, $lobby->id());
$res = publishAs($layouts, $lobby, layoutWith('Granted publish'), $lobby->layoutStamp(), false, 2);
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
$grants->grant($driveT->id(), $janeId);

$res = $admin->setAccess([2], [2 => [$lobby->id(), $driveT->id()]]);
check($res->isOk(), 'the access matrix saves');
checkSame([$driveT->id(), $lobby->id()], (new GrantStore($pdo))->displayIdsFor(2),
          'the account ends up holding exactly what was ticked');
checkSame([$driveT->id()], (new GrantStore($pdo))->displayIdsFor($janeId),
          'and an account the save did not cover keeps what it had');

$res = $admin->setAccess([2], [2 => [$lobby->id(), $driveT->id()]]);
check($res->isOk() && strpos($res->message(), 'unchanged') !== false,
      'saving the same matrix again says nothing changed');
checkSame(3, count(allGrants($pdo)), 'and does not duplicate a grant');

$res = $admin->setAccess([2], [2 => [$lobby->id(), 99999]]);
check($res->isOk(), 'an id naming no Display is dropped rather than failing the whole save');
checkSame([$lobby->id()], (new GrantStore($pdo))->displayIdsFor(2), 'the real one is kept');
check(strpos($res->message(), 'cannot publish') !== false,
      'and a revoke warns that whoever lost access cannot publish that display again');

$res = $admin->setAccess([2], []);
check($res->isOk(), 'an account with nothing ticked is allowed');
checkSame([], (new GrantStore($pdo))->displayIdsFor(2), 'and holds nothing');
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], newTestActor($pdo, 2, 'basic'));
checkSame(DisplayResolution::FORBIDDEN, $r->kind(), 'so the Display it was editing is refused again');

// An admin can never be granted anything through this path — the panel passes
// `basic` accounts only, and nothing here would make a difference if it did.
$res = $admin->setAccess([1], [1 => [$lobby->id()]]);
check($res->isOk(), 'granting an admin is accepted');
checkSame(true, newTestActor($pdo, 1, 'admin')->mayEdit($deli),
          'and changes nothing: an admin already held every Display');

// An account being deleted takes its grants with it, the same way a Display does.
checkSame(1, $grants->revokeAllForAccount($janeId), 'deleting an account revokes its grants');
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
checkSame(2, $store->releaseLocksHeldBy(2), 'deleting an account releases every lock it held');
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
$victim = $store->forId($victim->id());
$res = $layouts->publish($victim, new PublishRequest(
    [['type' => 'text', 'manual_content' => ['not' => 'a string'], 'temp_id' => 't1']],
    Background::unchanged(), 1, true, $victim->layoutStamp()
));
checkSame('failed', $res->kind(), 'manual_content as an object is a failed result, not a fatal');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'and the layout it would have replaced survives');
checkSame(false, $pdo->inTransaction(), 'with no transaction left open behind it');

$victim = $store->forId($victim->id());
$res = $layouts->publish($victim, new PublishRequest(
    [['type' => 'section', 'temp_id' => ['an', 'array']]],
    Background::unchanged(), 1, true, $victim->layoutStamp()
));
checkSame('failed', $res->kind(), 'an array where a temp_id belongs is refused the same way');
checkSame(2, count(elementsOf($pdo, $victim->id())), 'and again nothing was lost');

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
$pdo->exec("DELETE FROM users WHERE id = 2");
checkSame(false, syncSessionAccount($pdo), 'and so is a deleted one');

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

// Both signs publish the same words. The pool de-duplicates by exact content, so
// they end up sharing one row — which is what made a single delete blank both.
$shared = ['type' => 'text', 'block_subtype' => 'price', 'manual_content' => 'Sockeye  18.99',
           'save_to_db_pool' => true, 'x_pos' => 0, 'y_pos' => 0, 'width' => 200, 'height' => 60];
$layouts->publish($a, new PublishRequest([$shared], Background::unchanged(), 1, true, $a->layoutStamp()));
$store->releaseLock($a, 1);
$layouts->publish($b, new PublishRequest([$shared], Background::unchanged(), 1, true, $b->layoutStamp()));
$store->releaseLock($b, 1);

$assetId = intval($pdo->query("SELECT id FROM assets ORDER BY id ASC LIMIT 1")->fetchColumn());
check($assetId > 0, 'publishing a text block put its words in the shared library');
checkSame(2, count($pdo->query("SELECT id FROM canvas_elements WHERE asset_id = " . $assetId)->fetchAll()),
          'and both signs point at the one row');

$usage = $layouts->assetUsage($assetId);
checkSame(2, $usage['elements'],        'the library can see how many blocks depend on an entry');
checkSame(2, count($usage['displays']), 'and how many displays that is');

$idle = $layouts->assetUsage(999999);
checkSame(0, $idle['elements'], 'an entry nothing uses reports no blocks');
checkSame([], $idle['displays'], 'and no displays');

// The reason it matters: the elements keep no copy of their own text.
$own = $pdo->query("SELECT manual_content FROM canvas_elements WHERE asset_id = " . $assetId)->fetchAll();
checkSame([null, null], array_column($own, 'manual_content'),
          'a pooled block holds no content of its own, so losing the entry loses the words');

reportChecks(316);
