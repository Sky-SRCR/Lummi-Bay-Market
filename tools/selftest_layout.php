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
$actor  = ['id' => 1, 'username' => 'sky', 'role' => 'admin'];

// Viewing is strict as of Phase 2 (ADR-0003): the Screens send their tag, so a
// URL that names nothing gets a notice rather than a guess — even when only one
// Display exists and the guess would have been right.
$r = DisplayRequest::forViewing($store, []);
checkSame(DisplayResolution::NO_TAG, $r->kind(), 'viewing with no tag is refused even with a sole Display');

// The editing entry rule: one Display and no tag goes straight in.
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
// account's to work on, and that is decided in the seam, not in a page.
$clerk = ['id' => 2, 'username' => 'clerk', 'role' => 'basic'];
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], $clerk);
checkSame(DisplayResolution::INACTIVE, $r->kind(), 'but not by a basic account');
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

function publishAs(LayoutStore $layouts, Display $display, array $elements, $stamp, $isAdmin = true, $actorId = 1, Background $bg = null)
{
    return $layouts->publish($display, new PublishRequest(
        $elements, $bg ?: Background::unchanged(), $actorId, $isAdmin, $stamp
    ));
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

checkSame(false, $layouts->setElementHidden($driveT, $lobbyElement['id'], true),
    'hiding another Display\'s element is refused');
$reread = $pdo->query("SELECT hidden FROM canvas_elements WHERE id = " . intval($lobbyElement['id']))->fetchColumn();
checkSame(0, intval($reread), 'and it stays visible');

checkSame(false, $layouts->deleteElement($driveT, $lobbyElement['id']),
    'deleting another Display\'s element is refused');
$reread = $pdo->query("SELECT COUNT(*) FROM canvas_elements WHERE id = " . intval($lobbyElement['id']))->fetchColumn();
checkSame(1, intval($reread), 'and it still exists');

$ownElement = elementsOf($pdo, $driveT->id())[1];
$stampBefore = loadTestDisplay($pdo, $driveT->id())->layoutStamp();
checkSame(true, $layouts->setElementHidden($driveT, $ownElement['id'], true), 'hiding an own element works');
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
checkSame(true, $layouts->deleteElement($driveT, $sectionId), 'deleting a section works');
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
$r = DisplayRequest::forEditing($store, ['display' => 'lobby'], ['id' => 1, 'role' => 'admin']);
check($r->isFound(), 'and it is still editable — which is why the Builder needs the editing read');

$lobby = $admin->setActive($lobby, true)->display();
checkSame(true, $lobby->isActive(), 'and it can be turned back on');

// ---- Destroying --------------------------------------------------------------
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

// The roadmap decided there is no "last Display" rule: an installation may have
// none, and the Builder says so rather than the panel refusing.
$res = $admin->destroy($store->forTag('drive-thru'), 'drive-thru');
check($res->isOk(), 'the last Display can be deleted too');
checkSame(0, $store->count(), 'leaving none');
checkSame(0, count(allElements($pdo)), 'and no elements behind');

reportChecks();
