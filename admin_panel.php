<?php
require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/layout_store.php';
require_once __DIR__ . '/lib/grants.php';
require_once __DIR__ . '/lib/display_admin.php';
require_once __DIR__ . '/lib/brand_styles.php';
require_once __DIR__ . '/lib/branding.php';
require_once __DIR__ . '/lib/server_report.php';
require_once __DIR__ . '/lib/password_resets.php';
require_once __DIR__ . '/lib/accounts.php';
// Explicit, though display_admin.php pulls it in too: this page asks Color directly
// when it refuses a branding colour, and a transitive include is not a dependency.
require_once __DIR__ . '/lib/color.php';
requireCurrentAccount($pdo);
requireAdmin();

$user = currentUser();
$tab  = $_GET['tab'] ?? 'users';
$msg  = '';
$msgType = 'success';

// The answer to a save that redirected rather than rendered — see the grant matrix
// below, and flashMessage() in auth.php. Taken before the POST block so that a
// handler on this request still has the last word, and taken exactly once so a
// reload shows this page without the sentence rather than repeating it.
$flash = takeFlashMessage();
if ($flash) { $msg = $flash['message']; $msgType = $flash['type']; }

// Authenticated, so this is where schema convergence belongs (BUILD-REFERENCE §2.7).
ensureSignageSchema($pdo);
// The reset-token table converges from the pre-auth reset page (ADR-0001's rule
// for pre-auth state), which means a site nobody has ever asked for a password
// reset on would show its guess-budget column missing in the Settings tab below
// with no way for an admin to act on it. Converging it here too makes that
// readout's advice — sign out and back in — true.
(new ResetTokenStore($pdo))->ensureSchema();

// Displays are administered through DisplayAdmin: this page collects the form and
// shows the answer, and every rule about what a Display may be lives in lib/.
$displayStore = new DisplayStore($pdo);
$layoutStore  = new LayoutStore($pdo, $displayStore);
$grantStore   = new GrantStore($pdo);
$displayAdmin = new DisplayAdmin($pdo, $displayStore, $layoutStore, $grantStore);

// Accounts are closed, never deleted, so an id number can never come back into
// service under a different person (lib/accounts.php). `closed_at` used to be added
// by an `AccountStore::ensureSchema()` call on this line — one ungated ALTER per
// page load; it is in the plan `ensureSignageSchema()` ran above, which is why the
// store is built after that call rather than before. AccountAdmin holds the
// transaction that surrenders their grants and their edit lock in the same breath.
$accountStore = new AccountStore($pdo);
$accountAdmin = new AccountAdmin($pdo, $accountStore, $grantStore, $displayStore);

// The one moment an alert can learn who to write to. When the database is
// unreachable — the failure most worth an email — the list cannot be looked up,
// so it is cached here, on a page that by definition is working, and read back
// from disk at the moment of failure. See lib/alerts.php.
(new AlertMailer(ErrorPolicy::stateDir(), defined('SITE_NAME') ? SITE_NAME : ''))
    ->remember($accountStore->adminEmails());

/**
 * The address to type into a TV or a signage widget — absolute, because it is
 * being copied to a device that has no idea what page it came from.
 */
function viewerUrlFor(Display $display): string {
    // The same question the session cookie is decided from, asked once. This copy
    // knew only about the HTTPS server variable, so on a host that terminates TLS
    // at a proxy it printed an http:// address for a site that is HTTPS — and the
    // person it fails is standing at a television with no way to tell why.
    $scheme = RequestScheme::scheme($_SERVER);
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir . '/viewer.php?display=' . urlencode($display->tag());
}

// ── Branding config ───────────────────────────────────────────
// The one file this app rewrites while it is running, and every page requires it,
// so a half-written one is not a failed save — it is the whole site down until
// somebody notices. BrandingConfig owns the file: it writes a temporary copy,
// checks it, and swaps it in with a single rename, so a reader gets the whole old
// file or the whole new one. See §4y. This page collects the two forms and prints
// what came back; it knows neither the filename nor the eight setting names.
$branding = new BrandingConfig(__DIR__);
$branding->load();
$brand      = $branding->current();

// The four colours are read back through Brand:: rather than taken from the config
// as stored — the same reader the Builder, the Help page and the sign-in page draw
// their stylesheets from, so what this form offers as "what is there now" is what
// those pages are actually painting. Handing an unreadable stored value to a
// `type=color` input shows black, and "keep what is there" would then quietly mean
// "store black". `$brandBad` is what the Branding tab says so with, because a config
// nobody can read is something to be told about, not silently corrected (#21).
//
// There is no writer here. `writeBrandingConfig()` lived on this page until §4y and
// the branch this came from still carried it; `$branding->save()` below is the only
// way the file is written now, and it is the only thing that names it.
$brandBad   = Brand::unreadable();
$curLogo    = $brand['BRAND_LOGO'];
$curNavBg   = Brand::navBg();
$curBorder  = Brand::navBorder();
$curAccent  = Brand::accent();
$curText    = Brand::text();
$curSite    = $brand['SITE_NAME'];
$curMail    = $brand['MAIL_FROM'];
$curMailN   = $brand['MAIL_FROM_NAME'];

// Through undoStepsSetting(), not the raw constant: config.php is the one place
// that decides what a stored undo depth means, and the Builder reads it the same
// way — so this form cannot offer a number the editor would not act on.
$curUndo    = undoStepsSetting();

// ============================================================
// USER MANAGEMENT ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    // Create user
    if (isset($_POST['action_create_user'])) {
        $uname = trim($_POST['username'] ?? '');
        $email = trim($_POST['email']    ?? '');
        $pass  = $_POST['password']      ?? '';
        $role  = in_array($_POST['role'] ?? '', ['admin','basic']) ? $_POST['role'] : 'basic';

        if (!$uname || !$email || !$pass) {
            $msg = 'All fields are required.'; $msgType = 'error';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email address.'; $msgType = 'error';
        } elseif (strlen($pass) < AccountAdmin::PASSWORD_MIN) {
            $msg = 'Password must be at least ' . AccountAdmin::PASSWORD_MIN . ' characters.';
            $msgType = 'error';
        } else {
            try {
                $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)")
                    ->execute([$uname, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
                $msg = "User \"$uname\" created.";
            } catch (PDOException $e) {
                // A closed account keeps its name, and a closed account is not in
                // the list on this page — so "already exists" for a name nobody
                // can see is a dead end. Say which one it is.
                $clash = $accountStore->findByNameOrEmail($uname, $email);
                $msg = ($clash && $accountStore->isClosed($clash['id']))
                    ? 'That username or email belonged to "' . $clash['username'] . '", a closed account. '
                      . 'Closed names stay reserved so nobody inherits their history — choose another.'
                    : 'Username or email already exists.';
                $msgType = 'error';
            }
        }
        $tab = 'users';
    }

    // Edit user (role, active status, email). Through AccountAdmin, because a change
    // of role is not a change to one row: an admin holds every Display by role, so
    // promoting somebody makes their individual grants meaningless and invisible, and
    // demoting somebody takes away every Display including the one they may have open
    // right now. All of it is one transaction there.
    if (isset($_POST['action_edit_user'])) {
        $email = trim($_POST['edit_email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email address.'; $msgType = 'error';
        } else {
            // The id goes across raw. Casting it here was the panel deciding which
            // account this form meant, and `intval` decides that for any input at
            // all — "7abc" is account 7 (#21). AccountAdmin refuses what does not
            // name one.
            $res = $accountAdmin->edit(
                $_POST['edit_id'] ?? '',
                in_array($_POST['edit_role'] ?? '', ['admin','basic']) ? $_POST['edit_role'] : 'basic',
                isset($_POST['edit_active']),
                $email,
                $user['id']
            );
            $msg     = $res->message();
            $msgType = $res->isOk() ? 'success' : 'error';
        }
        $tab = 'users';
    }

    // Reset another user's password. Through AccountAdmin, because the statement this
    // used to run here matched no row for an id that named nothing and printed
    // "Password reset." regardless — and matched the *wrong* row for an id like
    // "7abc", which intval() reads as 7 (#21).
    if (isset($_POST['action_reset_pass'])) {
        $res     = $accountAdmin->resetPassword($_POST['reset_uid'] ?? '', $_POST['new_pass'] ?? '');
        $msg     = $res->message();
        $msgType = $res->isOk() ? 'success' : 'error';
        $tab     = 'users';
    }

    // Close an account. Never a DELETE: the row has to stay so its id number can
    // never be handed to somebody else (lib/accounts.php). Every rule about what
    // closing means, and the transaction that surrenders their access with it,
    // lives in AccountAdmin — this collects the form and prints the answer.
    if (isset($_POST['action_close_user'])) {
        $res     = $accountAdmin->close($_POST['close_id'] ?? '', $user['id']);
        $msg     = $res->message();
        $msgType = $res->isOk() ? 'success' : 'error';
        $tab     = 'users';
    }

    // ============================================================
    // DISPLAY MANAGEMENT ACTIONS
    // ============================================================
    // Each one collects a form, hands it to DisplayAdmin, and shows what came
    // back. No validation, no SQL, and no idea what a valid tag looks like.

    // Add a display — dimensions first, then blank or a duplicate of one the
    // same size (ADR-0004).
    if (isset($_POST['action_create_display'])) {
        $startFrom = ($_POST['d_start'] ?? 'blank') === 'duplicate' ? ($_POST['d_source'] ?? '') : '';
        $res = $displayAdmin->create([
            'title'          => $_POST['d_title']    ?? '',
            'tag'            => $_POST['d_tag']      ?? '',
            'location'       => $_POST['d_location'] ?? '',
            'canvas_width'   => $_POST['d_width']    ?? '',
            'canvas_height'  => $_POST['d_height']   ?? '',
            'bg_val'         => $_POST['d_bg']       ?? '',
            'duplicate_from' => $startFrom,
        ]);
        $msg     = $res->message();
        $msgType = $res->isOk() ? 'success' : 'error';
        $tab     = 'displays';
    }

    // Edit title, screen name tag, location — and the background colour, which is
    // a separate change because it advances the layout stamp.
    if (isset($_POST['action_update_display'])) {
        $display = $displayStore->forId($_POST['d_id'] ?? 0);
        if (!$display) {
            $msg = 'That display no longer exists.'; $msgType = 'error';
        } else {
            $res = $displayAdmin->updateDetails($display, [
                'title'    => $_POST['d_title']    ?? '',
                'tag'      => $_POST['d_tag']      ?? '',
                'location' => $_POST['d_location'] ?? '',
            ]);
            $msg     = $res->message();
            $msgType = $res->isOk() ? 'success' : 'error';

            // Only when this Display is actually on a colour. A colour input always
            // submits a value, so on an image-background Display the form carried
            // "#1a1a2e" whether the admin touched it or not — and saving a change of
            // location silently replaced the uploaded background with that colour
            // and advanced the layout stamp, invalidating every open Builder tab.
            // There was no way to edit the title of such a Display without losing
            // its background, and no undo. Changing an image background is the
            // Builder's job, where you can see what you are doing.
            $newBg = $display->backgroundType() === 'color' ? ($_POST['d_bg'] ?? '') : '';
            if ($res->isOk() && $newBg !== ''
                && strcasecmp($newBg, $display->backgroundValue()) !== 0) {
                $bgRes = $displayAdmin->setBackgroundColor($res->display(), $newBg);
                $msg  .= ' ' . $bgRes->message();
                if (!$bgRes->isOk()) { $msgType = 'error'; }
            }
        }
        $tab = 'displays';
    }

    // Retire a display, or bring it back. The layout is kept either way.
    if (isset($_POST['action_toggle_display'])) {
        $display = $displayStore->forId($_POST['d_id'] ?? 0);
        if (!$display) {
            $msg = 'That display no longer exists.'; $msgType = 'error';
        } else {
            $res     = $displayAdmin->setActive($display, empty($_POST['d_turn_off']));
            $msg     = $res->message();
            $msgType = $res->isOk() ? 'success' : 'error';
        }
        $tab = 'displays';
    }

    // Delete a display and its layout. There is no undo anywhere in this app, so
    // the typed screen name tag is one safeguard — DisplayAdmin checks it — and
    // refusing while somebody else has it open in the builder is the other (#19).
    // The actor is passed because that check is "somebody *else*": an admin who has
    // this sign open themselves is deleting their own work and is not stopped.
    if (isset($_POST['action_delete_display'])) {
        $display = $displayStore->forId($_POST['d_id'] ?? 0);
        if (!$display) {
            $msg = 'That display no longer exists.'; $msgType = 'error';
        } else {
            $res     = $displayAdmin->destroy($display, $_POST['confirm_tag'] ?? '', $user['id']);
            $msg     = $res->message();
            $msgType = $res->isOk() ? 'success' : 'error';
        }
        $tab = 'displays';
    }

    // Who may edit which display (ADR-0005). One save for the part of the matrix the
    // form actually showed, so what an admin sees on screen is exactly what this
    // write has an opinion about — and nothing outside it is touched.
    if (isset($_POST['action_save_grants'])) {
        // Only `basic` accounts can be granted anything: an admin already holds
        // every Display by role, so a grant on one would mean nothing. Intersecting
        // the submitted accounts with the basic ones is also what stops a forged
        // POST from writing grant rows for an admin account.
        // Open accounts only: a closed one surrendered its grants when it closed,
        // and handing it a new one would rebuild the stale pointer on purpose.
        $basicIds = [];
        foreach ($accountStore->open() as $row) {
            if ($row['role'] === 'basic') { $basicIds[] = intval($row['id']); }
        }
        $declared = isset($_POST['grants_accounts']) && is_array($_POST['grants_accounts'])
            ? array_map('intval', $_POST['grants_accounts']) : [];
        $covered  = array_values(array_intersect($declared, $basicIds));

        // And the columns. The form names the Displays it rendered, because an
        // unticked box and a Display that was never on the page look identical in a
        // POST — and treating the second as "revoke" is how a tab left open while a
        // colleague added a display silently undid the grants they had just made.
        $coveredDisplays = isset($_POST['grants_displays']) && is_array($_POST['grants_displays'])
            ? array_map('intval', $_POST['grants_displays']) : [];

        $res = $displayAdmin->setAccess($covered, $coveredDisplays,
            isset($_POST['grant']) && is_array($_POST['grant']) ? $_POST['grant'] : []);

        // Redirect rather than render (post/redirect/get). This is the one form on
        // the page that rewrites a table of state wholesale, so a browser reload
        // replaying it is a second whole-matrix write against a page that has since
        // moved on — the same defect from the other end. The sentence travels in the
        // session and is read once.
        flashMessage($res->message(), $res->isOk() ? 'success' : 'error');
        header('Location: admin_panel.php?tab=displays');
        exit;
    }

    // Save branding (logo + colors)
    if (isset($_POST['action_save_branding'])) {
        // Four colours, and a value that is not one is refused rather than swapped
        // for whatever is already saved (#21). The old line did the swap silently and
        // then reported "Branding saved." — so an admin who pasted a colour in the
        // wrong notation was told it worked, went and looked at the navigation bar,
        // and saw the colour they had been trying to replace. Nothing distinguished
        // that from the save having had no effect, because it had had no effect.
        //
        // Blank still means "not supplied, keep what is there": these are `type=color`
        // inputs, which always submit, so a blank one is a form that did not render
        // the field rather than an admin clearing it.
        $brandFields = [
            'nav_bg'     => ['Navigation background', $curNavBg],
            'nav_border' => ['Navigation border',     $curBorder],
            'accent'     => ['Accent',                $curAccent],
            'nav_text'   => ['Navigation text',       $curText],
        ];
        $brandKept   = [];
        $brandUnread = [];
        foreach ($brandFields as $field => $spec) {
            $raw = $_POST[$field] ?? '';
            if ($raw === '') { $brandKept[$field] = $spec[1]; continue; }
            $read = Color::read($raw);
            if ($read === '') {
                $brandUnread[]       = $spec[0];
                $brandKept[$field]   = $spec[1];
            } else {
                $brandKept[$field]   = $read;
            }
        }
        if ($brandUnread) {
            // The whole save is refused, logo included — see the guard on the upload
            // below. A branding save that stored the new logo and none of the colours
            // would be a half-applied change with no undo and nothing saying which
            // half landed.
            $msg = (count($brandUnread) === 1
                    ? $brandUnread[0] . ' is not a colour this app can store.'
                    : implode(', ', $brandUnread) . ' are not colours this app can store.')
                 . ' Colours are written as six hexadecimal digits after a hash, like'
                 . ' #1a1a2e. Nothing was saved.';
            $msgType = 'error';
        }
        $navBg     = $brandKept['nav_bg'];
        $navBorder = $brandKept['nav_border'];
        $accent    = $brandKept['accent'];
        $navText   = $brandKept['nav_text'];
        $rawExist  = $_POST['existing_logo'] ?? '';
        $logoPath  = preg_match('/^uploads\/brand_logo\.[a-z]{2,4}$/', $rawExist) ? $rawExist : $curLogo;
        // `$msg === ''` here is the refusal above, and it is what makes this save
        // all-or-nothing: move_uploaded_file() cannot be rolled back, so a refused
        // colour has to stop the upload before it happens rather than after.
        if ($msg === '' && !empty($_FILES['logo_file']['name'])) {
            // SVG is intentionally excluded: an SVG can carry <script> and would
            // be stored XSS when served from our own origin.
            $allowed = ['image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif',
                        'image/webp'=>'webp'];
            // The error check comes first. A logo over the host's upload limit
            // arrives with an empty tmp_name, and on PHP 8 mime_content_type('')
            // throws a ValueError — an uncaught fatal on the Admin Panel. The error
            // policy now turns that into a sentence rather than a stack trace
            // (invariant 16), but a page that dies where it could have said "too
            // large" is still a defect; the policy is the floor, not the fix.
            // api.php and crud.php both check the error code first; this was the
            // only sink that did not.
            if ($_FILES['logo_file']['error'] !== UPLOAD_ERR_OK) {
                $msg = 'Logo upload failed. Please try again.'; $msgType = 'error';
            } elseif ($_FILES['logo_file']['size'] > 2 * 1024 * 1024) {
                $msg = 'Logo must be 2 MB or smaller.'; $msgType = 'error';
            } elseif (!isset($allowed[$mime = mime_content_type($_FILES['logo_file']['tmp_name'])])) {
                $msg = 'Logo must be a PNG, JPG, GIF, or WEBP image.'; $msgType = 'error';
            } else {
                $dest = __DIR__ . '/uploads/brand_logo.' . $allowed[$mime];
                if (!move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
                    $msg = 'Could not save logo. Check uploads/ folder permissions.'; $msgType = 'error';
                } else {
                    $logoPath = 'uploads/brand_logo.' . $allowed[$mime];
                }
            }
        }
        if ($msg === '') {
            // Only the five this form edits. The old call took all eight positionally
            // and this page passed the other three back in from its own variables,
            // which is a save of Site & Email that nobody asked for, written from
            // whatever those variables happened to hold.
            $res = $branding->save([
                'BRAND_LOGO'       => $logoPath,
                'BRAND_NAV_BG'     => $navBg,
                'BRAND_NAV_BORDER' => $navBorder,
                'BRAND_ACCENT'     => $accent,
                'BRAND_TEXT'       => $navText,
            ]);
            if ($res->isOk()) {
                $curLogo = $logoPath; $curNavBg = $navBg; $curBorder = $navBorder; $curAccent = $accent; $curText = $navText;
                // All four went through Color::read() above, so the notice this page
                // may have opened with is no longer true of the file on disk. Leaving
                // it up would tell an admin their save had not taken.
                $brandBad = [];
                $msg = 'Branding saved.';
            } else {
                $msg = $res->message(); $msgType = 'error';
            }
        }
        $tab = 'branding';
    }

    // Save site & email settings
    if (isset($_POST['action_save_settings'])) {
        $siteName = trim($_POST['site_name'] ?? '') ?: $curSite;
        $mailFrom = filter_var(trim($_POST['mail_from'] ?? ''), FILTER_VALIDATE_EMAIL) ?: $curMail;
        $mailName = trim($_POST['mail_name'] ?? '') ?: $curMailN;
        // 0 is a real answer here — it switches Undo off — so this one cannot use the
        // `?:` the three above use, which would read 0 as "not filled in" and quietly
        // keep the old depth. A field that was never on the form at all is the
        // different case, and keeps what is stored: this form says what it covered.
        $undoSteps = isset($_POST['undo_steps'])
            ? max(0, min(UNDO_STEPS_MAX, intval($_POST['undo_steps'])))
            : $curUndo;
        $res = $branding->save([
            'SITE_NAME'      => $siteName,
            'MAIL_FROM'      => $mailFrom,
            'MAIL_FROM_NAME' => $mailName,
            'UNDO_STEPS'     => (string)$undoSteps,
        ]);
        if ($res->isOk()) {
            $curSite = $siteName; $curMail = $mailFrom; $curMailN = $mailName;
            $curUndo = $undoSteps;
            $msg = 'Settings saved.';
        } else {
            $msg = $res->message(); $msgType = 'error';
        }
        $tab = 'settings';
    }

    // Save brand standards
    if (isset($_POST['action_save_styles'])) {
        $types = ['section_header','item_title','item_title_2','price','price_2','description'];
        $tab   = 'brand';

        // The same refusal the API makes: this table is shared by every Display and
        // reaches every Screen on the next poll with no publish, so a held edit lock
        // anywhere is a claim on it.
        $busy = $displayStore->editedByAnyoneElse($user['id']);
        if ($busy) {
            $msg     = $busy->editingSentence()
                     . ' Brand standards apply to every display and reach every screen'
                     . ' within 30 seconds without a publish, so they cannot change while'
                     . ' somebody is editing. Try again once they are finished.';
            $msgType = 'error';
        } else {
            // Only the types this form actually carried. The loop used to write all
            // six unconditionally against `?? 'Arial'` / `?? 16` / `?? '#000000'`
            // defaults, so a POST that arrived without the fields — a truncated form,
            // a resubmitted stale one, a request built by hand — reset the store's
            // entire brand typography to black Arial 16 on every sign, reported
            // "saved", and could not be undone. BrandStyles validates too: the
            // min/max and the dropdowns below are HTML, which is to say advisory.
            $submitted = [];
            foreach ($types as $t) {
                if (!isset($_POST["bs_{$t}_family"])) { continue; }
                $submitted[$t] = [
                    'font_family' => $_POST["bs_{$t}_family"],
                    'font_size'   => $_POST["bs_{$t}_size"]   ?? null,
                    'font_color'  => $_POST["bs_{$t}_color"]  ?? null,
                    'font_weight' => $_POST["bs_{$t}_weight"] ?? null,
                    'font_style'  => $_POST["bs_{$t}_fstyle"] ?? null,
                    'line_height' => $_POST["bs_{$t}_lh"]     ?? null,
                ];
            }
            $saved = (new BrandStyles($pdo))->save($submitted);
            $msg = $saved
                ? 'Brand standards saved. Every screen picks them up within 30 seconds — no publishing needed.'
                : 'Nothing was saved: that form arrived with no typography in it.';
            $msgType = $saved ? 'success' : 'error';
        }
    }
}

// ---- READ DATA ----
// Two lists, because a closed account is neither gone nor in service. `$users` is
// everyone still working here — the management table and the grant matrix — and
// `$closedUsers` is the reserved names, shown so that "already exists" for a name
// that is nowhere on the page is never a dead end.
$users       = $accountStore->open();
$closedUsers = $accountStore->closed();

// Displays, with how much each one would lose if it were deleted, and the sizes a
// new Display could be duplicated from (ADR-0004: identical dimensions only).
$displays      = $displayStore->all();
$elementCounts = [];
$dupCandidates = [];
foreach ($displays as $d) {
    $elementCounts[$d->id()] = $layoutStore->elementCount($d);
    $dupCandidates[] = [
        'tag'   => $d->tag(),
        'title' => $d->title(),
        'w'     => $d->canvasWidth(),
        'h'     => $d->canvasHeight(),
    ];
}

// Grants, both ways round: by account for the matrix's rows, by Display for the
// "who may edit this sign" line on each card. Admins are in neither — they hold
// every Display by role and are never granted one (ADR-0005).
$grantsByAccount = $grantStore->displayIdsByAccount();
$grantsByDisplay = $grantStore->accountIdsByDisplay();
$basicUsers      = [];
foreach ($users as $u) {
    if ($u['role'] === 'basic') { $basicUsers[] = $u; }
}
// Names for *every* account, closed included. This is the reason closing beats
// deleting: "published by Kayla" and a held edit lock both name an account by id,
// and they have to keep printing a name after that person leaves.
$userNames = $accountStore->names();

// Offered as a starting point only; any size inside the bounds can be typed.
$canvasPresets = [
    ['1920×1080 — Landscape HD (the drive-thru sign)', 1920, 1080],
    ['1080×1920 — Portrait HD',                        1080, 1920],
    ['3840×2160 — Landscape 4K',                       3840, 2160],
    ['2160×3840 — Portrait 4K',                        2160, 3840],
    ['1280×720 — Landscape, smaller screen',           1280,  720],
    ['1920×540 — Wide strip / ticker',                 1920,  540],
];
$styles = (new BrandStyles($pdo))->all();
// Read raw, above, because ColorAudit reads the same method and an audit whose source
// had already been tidied would find nothing. What the form draws goes through
// BrandStyles::readable(); this is the list of places the two differ, which is what the
// tab says out loud rather than substituting in silence (#21's whole point).
$styleBad = [];
foreach ($styles as $_bsType => $_bsRow) {
    foreach (BrandStyles::unrenderable($_bsRow) as $_bsBad) {
        $styleBad[] = ['type' => $_bsType] + $_bsBad;
    }
}
unset($_bsType, $_bsRow, $_bsBad);
$typeLabels = [
    'section_header' => 'Section Header',
    'item_title'     => 'Item Title',
    'item_title_2'   => 'Item Title 2',
    'price'          => 'Price',
    'price_2'        => 'Price 2',
    'description'    => 'Description',
];
$fontFamilies = ['Arial','Georgia','Verdana','Tahoma','Trebuchet MS','Times New Roman','Courier New','Impact'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Panel — <?= Markup::text(SITE_NAME) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { background: #f0f2f5; min-height: 100vh; }

        /* --- Nav --- */
        nav { background: #1a252f; padding: 0 20px; display: flex; align-items: center; gap: 20px; height: 52px; }
        nav .brand { color: #fff; font-weight: bold; font-size: 15px; margin-right: auto; }
        nav a { color: #bdc3c7; text-decoration: none; font-size: 13px; padding: 6px 10px; border-radius: 4px; }
        nav a:hover, nav a.active { background: #2c3e50; color: #fff; }
        nav .role-badge { background: #e74c3c; color: #fff; font-size: 11px; font-weight: bold;
                          padding: 2px 8px; border-radius: 10px; }

        /* --- Tabs --- */
        .tabs { display: flex; gap: 2px; background: #dde3ea; padding: 6px 24px 0; }
        .tab-btn { padding: 9px 20px; border: none; cursor: pointer; font-size: 14px; font-weight: 600;
                   background: transparent; color: #555; border-radius: 6px 6px 0 0; }
        .tab-btn.active { background: #fff; color: #2c3e50; }

        /* --- Content --- */
        .content { padding: 24px; max-width: 1100px; margin: 0 auto; }
        .msg-box { padding: 11px 16px; border-radius: 5px; margin-bottom: 18px; font-size: 14px; }
        .msg-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-error   { background: #fdecea; color: #c0392b; border: 1px solid #e74c3c; }

        /* --- Cards --- */
        .card { background: #fff; border-radius: 8px; padding: 22px; box-shadow: 0 2px 8px rgba(0,0,0,.07); margin-bottom: 20px; }
        .card h2 { font-size: 16px; color: #2c3e50; margin-bottom: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px; }

        /* --- Forms --- */
        .form-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 12px; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 12px; font-weight: 600; color: #666; }
        input[type="text"], input[type="email"], input[type="password"],
        input[type="number"], select {
            padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;
        }
        input[type="color"] { padding: 2px; height: 34px; cursor: pointer; border: 1px solid #ccc; border-radius: 4px; }
        .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; }
        .btn-blue   { background: #3498db; color: #fff; }
        .btn-blue:hover   { background: #2980b9; }
        .btn-green  { background: #27ae60; color: #fff; }
        .btn-green:hover  { background: #219a52; }
        .btn-red    { background: #e74c3c; color: #fff; }
        .btn-red:hover    { background: #c0392b; }
        .btn-gray   { background: #95a5a6; color: #fff; }

        /* --- User table --- */
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        tr:hover td { background: #fafbfc; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; }
        .badge-admin { background: #e8d5fb; color: #6c3483; }
        .badge-basic { background: #d6eaf8; color: #1a5276; }
        .badge-active   { background: #d4efdf; color: #1e8449; }
        .badge-inactive { background: #fdecea; color: #c0392b; }

        /* --- Edit row --- */
        .edit-row { display: none; background: #f8f9fa; }
        .edit-row.open { display: table-row; }
        .edit-row td { padding: 12px; }

        /* --- Brand standards --- */
        .bs-table { width: 100%; border-collapse: collapse; }
        .bs-table th, .bs-table td { padding: 10px 8px; border-bottom: 1px solid #eee; font-size: 13px; }
        .bs-table th { background: #f8f9fa; font-weight: 600; color: #555; }
        .bs-table input[type="number"] { width: 70px; }
        .bs-table select { min-width: 130px; }
        .preview-text { padding: 4px 8px; border: 1px solid #eee; border-radius: 3px; white-space: nowrap; }

        /* --- Displays --- */
        .display-card { border: 1px solid #e3e8ee; border-radius: 7px; padding: 14px 16px; margin-bottom: 12px; }
        .display-card.is-off { background: #fbfbfc; border-color: #e8dede; }
        .display-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .display-head h3 { font-size: 15px; color: #2c3e50; }
        .tag-chip { font-family: "SF Mono", Menlo, Consolas, monospace; font-size: 12px; background: #eef3f8;
                    color: #1a5276; border: 1px solid #d4e2ee; border-radius: 3px; padding: 1px 7px; }
        .display-facts { font-size: 12px; color: #7f8c8d; margin-top: 5px; line-height: 1.6; }
        .display-facts strong { color: #555; font-weight: 600; }
        .display-facts .lock-line { color: #6b5291; }
        .addr-row { display: flex; align-items: center; gap: 7px; margin-top: 10px; flex-wrap: wrap; }
        .addr-row input { flex: 1; min-width: 260px; font-family: "SF Mono", Menlo, Consolas, monospace;
                          font-size: 12px; background: #f8f9fa; color: #2c3e50; }
        .display-actions { display: flex; gap: 7px; margin-top: 12px; flex-wrap: wrap; }
        .display-actions .btn { font-size: 12px; padding: 6px 12px; }
        .display-panel { display: none; margin-top: 12px; padding-top: 12px; border-top: 1px dashed #dfe6ec; }
        .display-panel.open { display: block; }
        .danger-panel { background: #fdf3f2; border: 1px solid #f5c6c1; border-radius: 5px; padding: 12px 14px; }
        .hint { font-size: 13px; color: #7f8c8d; line-height: 1.6; }
        .step-label { font-size: 12px; font-weight: 700; color: #2c3e50; text-transform: uppercase;
                      letter-spacing: .4px; margin-bottom: 8px; }
        .step { border-left: 3px solid #dde3ea; padding: 0 0 0 14px; margin-bottom: 20px; }
        .step.locked { opacity: .45; }
        fieldset { border: none; }

        /* --- Grant matrix --- */
        .grant-table { border-collapse: collapse; font-size: 13px; }
        .grant-table th, .grant-table td { padding: 9px 14px; border-bottom: 1px solid #ecf0f1;
                                           text-align: left; vertical-align: middle; }
        .grant-table thead th { background: #f8f9fa; font-weight: 600; color: #555; font-size: 12px;
                                line-height: 1.7; white-space: nowrap; vertical-align: bottom; }
        .grant-table tbody tr:hover td { background: #fafbfc; }
        .grant-table input[type="checkbox"] { width: 17px; height: 17px; cursor: pointer; }

        /* --- Work Area element type badges --- */
        .el-badge { display:inline-block; padding:2px 7px; border-radius:3px; font-size:11px; font-weight:bold; text-transform:uppercase; }
        .el-section  { background:#e8d5fb; color:#6c3483; }
        .el-text      { background:#d6eaf8; color:#1a5276; }
        .el-image     { background:#d4efdf; color:#1e8449; }
        .el-video     { background:#fde8d8; color:#a04000; }
        .el-carousel  { background:#fef9e7; color:#7d6608; border:1px solid #d4ac0d; }
        .el-table     { background:#eaf7fb; color:#0e6655; }
        .el-marquee   { background:#fdedec; color:#922b21; }
        .el-desc { font-size:13px; color:#2c3e50; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .el-hidden-tag { display:inline-block; padding:1px 6px; border-radius:8px; font-size:10px;
                         font-weight:bold; background:#fdecea; color:#c0392b; margin-left:6px; vertical-align:middle; }
    </style>
</head>
<body>

<nav>
    <span class="brand"><?= Markup::text(SITE_NAME) ?></span>
    <a href="builder.php">Builder</a>
    <a href="crud.php">Asset Manager</a>
    <a href="admin_panel.php" class="active">Admin Panel</a>
    <span style="color:#bdc3c7; font-size:13px;">
        <?= Markup::text($user['username']) ?>
        <span class="role-badge">ADMIN</span>
    </span>
    <a href="logout.php">Sign Out</a>
</nav>

<div class="tabs">
    <button class="tab-btn <?= $tab==='users'    ?'active':'' ?>" onclick="showTab('users')">User Management</button>
    <button class="tab-btn <?= $tab==='displays' ?'active':'' ?>" onclick="showTab('displays')">Displays</button>
    <button class="tab-btn <?= $tab==='brand'    ?'active':'' ?>" onclick="showTab('brand')">Display Branding</button>
    <button class="tab-btn <?= $tab==='branding' ?'active':'' ?>" onclick="showTab('branding')">Site Branding</button>
    <button class="tab-btn <?= $tab==='settings' ?'active':'' ?>" onclick="showTab('settings')">Settings</button>
    <button class="tab-btn <?= $tab==='workarea' ?'active':'' ?>" onclick="showTab('workarea')">Work Area</button>
</div>

<div class="content">

<?php if ($msg): ?>
    <div class="msg-box msg-<?= Markup::text($msgType) ?>"><?= Markup::text($msg) ?></div>
<?php endif; ?>

<!-- ============================================================ -->
<!-- USERS TAB                                                     -->
<!-- ============================================================ -->
<div id="tab-users" style="display:<?= $tab==='users'?'block':'none' ?>">
    <div class="card">
        <h2>Add New User</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="janedoe" required style="width:150px;">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="jane@store.com" required style="width:200px;">
                </div>
                <div class="form-group">
                    <label>Temp Password</label>
                    <input type="password" name="password" placeholder="min 8 chars" required style="width:160px;">
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" style="width:100px;">
                        <option value="basic">Basic User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" name="action_create_user" class="btn btn-green">Add User</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>All Users (<?= count($users) ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>Username</th><th>Email</th><th>Role</th>
                    <th>Status</th><th>Created</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= Markup::text($u['username']) ?></strong>
                        <?php if ($u['id'] === $user['id']): ?>
                            <span style="font-size:11px; color:#7f8c8d;">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= Markup::text($u['email']) ?></td>
                    <td><span class="badge badge-<?= Markup::text($u['role']) ?>"><?= Markup::text(strtoupper($u['role'])) ?></span></td>
                    <td><span class="badge badge-<?= $u['is_active']?'active':'inactive' ?>">
                        <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                    </span></td>
                    <td><?= !empty($u['created_at']) ? date('M j, Y', strtotime($u['created_at'])) : '—' ?></td>
                    <td>
                        <button class="btn btn-blue" style="font-size:11px; padding:5px 10px;"
                                onclick="toggleEdit(<?= intval($u['id']) ?>)">Edit</button>

                        <?php if ($u['id'] !== $user['id']): ?>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirmCloseAccount(<?= Markup::jsInAttr($u['username']) ?>)">
                            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                            <input type="hidden" name="close_id" value="<?= intval($u['id']) ?>">
                            <button type="submit" name="action_close_user"
                                    class="btn btn-red" style="font-size:11px; padding:5px 10px;"
                                    title="Permanently close this account. Their name stays reserved and anything they published still says who published it.">Close Account</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <!-- Inline edit row -->
                <tr class="edit-row" id="edit-<?= intval($u['id']) ?>">
                    <td colspan="6">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                            <div class="form-row">
                                <input type="hidden" name="edit_id" value="<?= intval($u['id']) ?>">
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" name="edit_email"
                                           value="<?= Markup::text($u['email']) ?>" style="width:200px;" required>
                                </div>
                                <div class="form-group">
                                    <label>Role</label>
                                    <select name="edit_role" style="width:100px;">
                                        <option value="basic"  <?= $u['role']==='basic' ?'selected':'' ?>>Basic</option>
                                        <option value="admin"  <?= $u['role']==='admin' ?'selected':'' ?>>Admin</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="edit_active" value="1"
                                               <?= $u['is_active']?'checked':'' ?>> Active
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" name="action_edit_user"
                                            class="btn btn-green" style="font-size:12px;">Save</button>
                                </div>
                            </div>
                        </form>
                        <form method="POST" style="margin-top:8px;">
                            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                            <div class="form-row">
                                <input type="hidden" name="reset_uid" value="<?= intval($u['id']) ?>">
                                <div class="form-group">
                                    <label>Set New Password</label>
                                    <input type="password" name="new_pass" placeholder="min 8 characters" style="width:200px;">
                                </div>
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" name="action_reset_pass"
                                            class="btn btn-gray" style="font-size:12px;">Reset Password</button>
                                </div>
                            </div>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($closedUsers): ?>
    <div class="card">
        <h2>Closed Accounts (<?= count($closedUsers) ?>)</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
            These people can no longer sign in and have no access to any display. Their records are
            kept so that anything they published still says who published it, and
            <strong>their usernames and email addresses stay reserved</strong> — nobody else can be
            given the same name. This cannot be undone; a returning employee needs a new account.
        </p>
        <table>
            <thead>
                <tr><th>Username</th><th>Email</th><th>Role held</th><th>Closed</th></tr>
            </thead>
            <tbody>
            <?php foreach ($closedUsers as $c): ?>
                <tr style="color:#7f8c8d;">
                    <td><strong><?= Markup::text($c['username']) ?></strong></td>
                    <td><?= Markup::text($c['email']) ?></td>
                    <td><?= Markup::text(strtoupper($c['role'])) ?></td>
                    <td><?= !empty($c['closed_at']) ? date('M j, Y', strtotime($c['closed_at'] . ' UTC')) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================================ -->
<!-- DISPLAYS TAB                                                  -->
<!-- ============================================================ -->
<div id="tab-displays" style="display:<?= $tab==='displays'?'block':'none' ?>">
    <div class="card">
        <h2>Displays (<?= count($displays) ?>)</h2>
        <p class="hint" style="margin-bottom:18px;">
            One display is one sign. Each has its own layout and its own screen address —
            <strong>anyone who has that address can view the sign without signing in</strong>, so treat the
            addresses as public and keep prices you are not ready to show off the canvas.
        </p>

        <?php if (!$displays): ?>
            <p class="hint">No displays yet. Add the first one below.</p>
        <?php endif; ?>

        <?php foreach ($displays as $d):
            $did   = $d->id();
            $count = $elementCounts[$did];
            $url   = viewerUrlFor($d);
            // The basic accounts granted this Display. Admins are not listed: they
            // hold every Display and listing them would suggest it is revocable.
            $editors = [];
            foreach (isset($grantsByDisplay[$did]) ? $grantsByDisplay[$did] : [] as $uid) {
                if (isset($userNames[$uid])) { $editors[] = $userNames[$uid]; }
            }
        ?>
        <div class="display-card <?= $d->isActive() ? '' : 'is-off' ?>">
            <div class="display-head">
                <h3><?= Markup::text($d->title()) ?></h3>
                <span class="tag-chip"><?= Markup::text($d->tag()) ?></span>
                <span class="badge badge-<?= $d->isActive() ? 'active' : 'inactive' ?>">
                    <?= $d->isActive() ? 'On' : 'Turned off' ?>
                </span>
            </div>
            <div class="display-facts">
                <strong><?= Markup::text($d->dimensionsLabel()) ?></strong> <?= Markup::text($d->orientation()) ?>
                &nbsp;·&nbsp; <?= intval($count) ?> element<?= $count === 1 ? '' : 's' ?>
                <?php if ($d->location() !== ''): ?>
                    &nbsp;·&nbsp; <?= Markup::text($d->location()) ?>
                <?php endif; ?>
                <br>
                <?php if ($d->lastPublishDescription() !== ''): ?>
                    Last published by <?= Markup::text($d->lastPublishDescription()) ?>
                <?php else: ?>
                    Never published
                <?php endif; ?>
                <br>
                <?php if ($editors): ?>
                    Assigned to <strong><?= Markup::text(implode(', ', $editors)) ?></strong>
                    &nbsp;·&nbsp; and every admin
                <?php else: ?>
                    Admins only — no basic account has been assigned this display
                <?php endif; ?>
                <?php
                // Who has it open right now (ADR-0007). Shown here because "why can I
                // not edit this?" is asked on this screen; the taking-over is offered
                // in the Builder, where you can see what you would be interrupting.
                $cardLock = $d->lockState();
                if ($cardLock->isHeld()):
                ?>
                    <br><span class="lock-line">Being edited now by
                    <strong><?= Markup::text($cardLock->holderName() !== '' ? $cardLock->holderName() : 'someone') ?></strong><?php
                        if ($cardLock->takenAtLabel() !== ''): ?>, since <?= Markup::text($cardLock->takenAtLabel()) ?><?php
                        endif; ?> — opening it in the builder shows it read-only, and an admin can take over there.</span>
                <?php endif; ?>
            </div>

            <div class="addr-row">
                <label style="font-size:12px;font-weight:600;color:#666;">Screen address</label>
                <input type="text" readonly value="<?= Markup::text($url) ?>"
                       id="addr-<?= intval($did) ?>" onclick="this.select()">
                <button class="btn btn-gray" style="font-size:12px;padding:6px 12px;"
                        onclick="copyAddr(<?= intval($did) ?>)">Copy</button>
            </div>

            <div class="display-actions">
                <a class="btn btn-blue" style="text-decoration:none;"
                   href="builder.php?display=<?= urlencode($d->tag()) ?>">Open in Builder</a>
                <a class="btn btn-gray" style="text-decoration:none;" target="_blank"
                   href="viewer.php?display=<?= urlencode($d->tag()) ?>">View ↗</a>
                <button class="btn btn-gray" onclick="togglePanel('edit-display-<?= intval($did) ?>')">Edit</button>

                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                    <input type="hidden" name="d_id" value="<?= intval($did) ?>">
                    <?php if ($d->isActive()): ?>
                        <input type="hidden" name="d_turn_off" value="1">
                        <button type="submit" name="action_toggle_display" class="btn btn-gray"
                                onclick="return confirmTurnOff(<?= Markup::jsInAttr($d->title()) ?>)">
                            Turn off</button>
                    <?php else: ?>
                        <button type="submit" name="action_toggle_display" class="btn btn-green">Turn on</button>
                    <?php endif; ?>
                </form>

                <button class="btn btn-red" onclick="togglePanel('del-display-<?= intval($did) ?>')">Delete…</button>
            </div>

            <!-- Edit -->
            <div class="display-panel" id="edit-display-<?= intval($did) ?>">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                    <input type="hidden" name="d_id" value="<?= intval($did) ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Title</label>
                            <input type="text" name="d_title" value="<?= Markup::text($d->title()) ?>"
                                   style="width:200px;" required>
                        </div>
                        <div class="form-group">
                            <label>Screen name tag</label>
                            <input type="text" name="d_tag" value="<?= Markup::text($d->tag()) ?>"
                                   data-original-tag="<?= Markup::text($d->tag()) ?>"
                                   pattern="[a-z0-9\-]{2,32}" style="width:170px;" required>
                        </div>
                        <div class="form-group">
                            <label>Location (for reference)</label>
                            <input type="text" name="d_location" value="<?= Markup::text($d->location()) ?>"
                                   placeholder="e.g. Front entrance" style="width:200px;">
                        </div>
                        <div class="form-group">
                            <label>Canvas size</label>
                            <input type="text" value="<?= Markup::text($d->dimensionsLabel()) ?>" disabled
                                   title="Set when the display was created and fixed from then on" style="width:110px;">
                        </div>
                        <div class="form-group">
                            <label>Background colour</label>
                            <input type="color" name="d_bg"
                                   value="<?= $d->backgroundType() === 'color'
                                              ? Markup::text($d->backgroundValue()) : '#1a1a2e' ?>">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="action_update_display" class="btn btn-green"
                                    onclick="return confirmTagChange(this.form)">Save</button>
                        </div>
                    </div>
                    <p class="hint" style="font-size:12px;">
                        The canvas size cannot be changed — the layout is positioned in exact pixels and there is
                        no undo. To run this sign at another size, add a display at that size instead.
                        <?php if ($d->backgroundType() === 'image'): ?>
                            <br>This display currently uses an uploaded background image
                            (<code><?= Markup::text($d->backgroundValue()) ?></code>). Saving a colour here
                            replaces it. Background images are uploaded from the Builder.
                        <?php else: ?>
                            Background images are uploaded from the Builder, where you can see the canvas.
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <!-- Delete -->
            <?php
            // What deleting this actually costs, stated before the button rather
            // than discovered after it (#19). The element count was the whole of it
            // and it was the smallest part: the assignments go too, and somebody may
            // be working on the canvas at this moment. `$busyNow` is the same
            // question DisplayAdmin::destroy() asks — this page just asks it early,
            // so a deletion that would be refused is not offered in the first place.
            $busyNow = $cardLock->heldByOther($user['id']) ? $cardLock : null;
            ?>
            <div class="display-panel" id="del-display-<?= intval($did) ?>">
                <div class="danger-panel">
                    <p style="font-size:13px;color:#c0392b;font-weight:600;margin-bottom:8px;">
                        Delete “<?= Markup::text($d->title()) ?>” and its <?= intval($count) ?>
                        element<?= $count === 1 ? '' : 's' ?>?
                    </p>
                    <p class="hint" style="margin-bottom:10px;">
                        This cannot be undone — nothing in this app is versioned. Any screen still pointed at
                        <code><?= Markup::text($d->tag()) ?></code> will show “Display not found”.
                        <?php if ($editors): ?>
                            <br><?= count($editors) ?> account<?= count($editors) === 1 ? '' : 's' ?>
                            assigned to it (<?= Markup::text(implode(', ', $editors)) ?>)
                            lose<?= count($editors) === 1 ? 's' : '' ?> that access with it.
                        <?php endif; ?>
                    </p>
                    <?php if ($busyNow): ?>
                        <p style="font-size:13px;color:#c0392b;font-weight:600;margin-bottom:10px;">
                            <?= Markup::text($busyNow->holderName() !== '' ? $busyNow->holderName() : 'Somebody') ?>
                            has this open in the builder<?php
                                if ($busyNow->takenAtLabel() !== ''): ?>, since
                                <?= Markup::text($busyNow->takenAtLabel()) ?><?php
                                endif; ?>. Deleting it now would lose whatever they have not published,
                            so it will be refused. Ask them to close it, or wait — the lock lapses
                            <?= intdiv(LockState::IDLE_LAPSE_SECONDS, 60) ?> minutes after their last change.
                        </p>
                    <?php endif; ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                        <input type="hidden" name="d_id" value="<?= intval($did) ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Type <code><?= Markup::text($d->tag()) ?></code> to confirm</label>
                                <input type="text" name="confirm_tag" autocomplete="off" style="width:200px;"
                                       <?= $busyNow ? 'disabled' : '' ?>>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <?php // Disabled, not absent: a page drawn while somebody was editing and
                                      // submitted after they stopped would otherwise be a POST the server
                                      // has to refuse anyway. The server refuses either way — this is the
                                      // half that stops an admin typing the tag for nothing. ?>
                                <button type="submit" name="action_delete_display" class="btn btn-red"
                                        <?= $busyNow ? 'disabled' : '' ?>>
                                    Delete this display</button>
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="button" class="btn btn-gray"
                                        onclick="togglePanel('del-display-<?= intval($did) ?>')">Cancel</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Who can edit which display: one grant is one checkbox ── -->
    <div class="card">
        <h2>Who can edit which display</h2>
        <p class="hint" style="margin-bottom:16px;">
            A tick means that person may open that display in the Builder and publish it to its screen.
            <strong>Admins are not listed: an admin can already edit every display</strong>, and that
            comes with the role rather than from this table. What someone may change inside a display
            they have been given is decided by their role too — a basic user still edits content inside
            existing sections and cannot move the section layout.
        </p>

        <?php if (!$basicUsers): ?>
            <p class="hint">Every account is an admin, so every account can already edit every display.
               Add a basic user on the User Management tab to hand out one sign at a time.</p>
        <?php elseif (!$displays): ?>
            <p class="hint">There are no displays to assign yet. Add one below.</p>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <?php foreach ($displays as $d): ?>
                <!-- Names the displays this save covers — the columns below. A box
                     nobody ticked and a display that was not on the page when this
                     form was rendered are the same absence in a POST, and only one of
                     them means "take that access away". -->
                <input type="hidden" name="grants_displays[]" value="<?= intval($d->id()) ?>">
            <?php endforeach; ?>
            <div style="overflow-x:auto;">
                <table class="grant-table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <?php foreach ($displays as $d): ?>
                                <th style="text-align:center;">
                                    <?= Markup::text($d->title()) ?><br>
                                    <span class="tag-chip"><?= Markup::text($d->tag()) ?></span>
                                    <?php if (!$d->isActive()): ?><br><span
                                        style="font-size:10px;color:#c0392b;font-weight:700;">TURNED OFF</span><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($basicUsers as $bu):
                        $uid  = intval($bu['id']);
                        $held = isset($grantsByAccount[$uid]) ? $grantsByAccount[$uid] : [];
                    ?>
                        <tr>
                            <td>
                                <!-- Names the accounts this save covers, so one left open while an
                                     account was added cannot strip the new account's access. -->
                                <input type="hidden" name="grants_accounts[]" value="<?= intval($uid) ?>">
                                <strong><?= Markup::text($bu['username']) ?></strong>
                                <?php if (!$bu['is_active']): ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                                <div style="font-size:11px;color:#7f8c8d;">
                                    <?= count($held) ?> display<?= count($held) === 1 ? '' : 's' ?>
                                </div>
                            </td>
                            <?php foreach ($displays as $d): ?>
                                <td style="text-align:center;">
                                    <input type="checkbox" name="grant[<?= intval($uid) ?>][]" value="<?= intval($d->id()) ?>"
                                           <?= in_array($d->id(), $held, true) ? 'checked' : '' ?>
                                           title="<?= Markup::text($bu['username'] . ' may edit ' . $d->title()) ?>">
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="hint" style="font-size:12px;margin:12px 0;">
                Taking a display away from someone who has the Builder open releases their edit lock,
                so the sign is free for somebody else straight away. Their page tells them the access
                was removed within a minute; nothing they had unsaved reaches the screen, and their
                work stays on their own screen until they leave the page.
            </p>
            <button type="submit" name="action_save_grants" class="btn btn-green">Save access</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- ── Add a display: size first, then a name, then what it starts from ── -->
    <div class="card">
        <h2>Add a Display</h2>
        <form method="POST" id="new-display-form">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">

            <div class="step">
                <div class="step-label">1 · Canvas size</div>
                <p class="hint" style="margin-bottom:10px;">
                    Set this first, and set it to the screen's real resolution. It is fixed from here on:
                    the layout is positioned in exact pixels, so there is no safe way to resize it afterwards.
                    A screen of the same shape but a different resolution is fine — the sign scales to fit.
                </p>
                <div class="form-row">
                    <div class="form-group">
                        <label>Preset</label>
                        <select id="d_preset" onchange="applyPreset()" style="width:290px;">
                            <option value="">Choose a size…</option>
                            <?php foreach ($canvasPresets as $p): ?>
                                <option value="<?= intval($p[1]) ?>x<?= intval($p[2]) ?>"><?= Markup::text($p[0]) ?></option>
                            <?php endforeach; ?>
                            <option value="custom">Custom…</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Width (px)</label>
                        <input type="number" name="d_width" id="d_width" min="<?= DisplayStore::CANVAS_MIN ?>"
                               max="<?= DisplayStore::CANVAS_MAX ?>" oninput="sizeChanged()" style="width:110px;" required>
                    </div>
                    <div class="form-group">
                        <label>Height (px)</label>
                        <input type="number" name="d_height" id="d_height" min="<?= DisplayStore::CANVAS_MIN ?>"
                               max="<?= DisplayStore::CANVAS_MAX ?>" oninput="sizeChanged()" style="width:110px;" required>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <span id="size-readout" class="hint" style="padding-bottom:9px;">No size chosen yet</span>
                    </div>
                </div>
            </div>

            <div class="step">
                <div class="step-label">2 · Name it</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Title</label>
                        <input type="text" name="d_title" id="d_title" placeholder="Lobby Screen"
                               oninput="suggestTag()" style="width:210px;" required>
                    </div>
                    <div class="form-group">
                        <label>Screen name tag</label>
                        <input type="text" name="d_tag" id="d_tag" placeholder="lobby-screen"
                               pattern="[a-z0-9\-]{2,32}" style="width:180px;">
                    </div>
                    <div class="form-group">
                        <label>Location (for reference)</label>
                        <input type="text" name="d_location" placeholder="Front entrance" style="width:200px;">
                    </div>
                    <div class="form-group">
                        <label>Background colour</label>
                        <input type="color" name="d_bg" value="#1a1a2e">
                    </div>
                </div>
                <p class="hint" style="font-size:12px;">
                    The tag is the display's address: <code>viewer.php?display=<span id="tag-echo">lobby-screen</span></code>.
                    Lowercase letters, numbers and hyphens. Leave it blank and it is taken from the title.
                </p>
            </div>

            <div class="step locked" id="start-step">
                <div class="step-label">3 · Start from</div>
                <fieldset id="start-fields" disabled>
                    <label style="display:block;font-size:13px;margin-bottom:7px;">
                        <input type="radio" name="d_start" value="blank" checked onchange="startChanged()">
                        A blank canvas
                    </label>
                    <label style="display:block;font-size:13px;">
                        <input type="radio" name="d_start" value="duplicate" id="d_start_dup" onchange="startChanged()">
                        A copy of an existing display's layout
                    </label>
                    <div class="form-row" style="margin-top:8px; margin-left:22px;">
                        <div class="form-group">
                            <select name="d_source" id="d_source" disabled style="width:320px;"></select>
                        </div>
                    </div>
                    <p class="hint" style="font-size:12px;" id="dup-note">
                        Only displays of exactly the same size can be copied from — blocks are positioned in
                        exact pixels, and rescaling a layout is a redesign, not a copy.
                    </p>
                </fieldset>
                <p class="hint" style="font-size:12px;" id="start-locked-note">Choose a canvas size first.</p>
            </div>

            <button type="submit" name="action_create_display" class="btn btn-green">Add Display</button>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- BRAND STANDARDS TAB                                          -->
<!-- ============================================================ -->
<div id="tab-brand" style="display:<?= $tab==='brand'?'block':'none' ?>">
    <div class="card">
        <h2>Brand Standards — Locked Text Styles</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:16px;">
            These styles apply to the six branded text blocks, on every display. Basic users
            cannot change them. Changes reach every screen within 30 seconds — no publishing
            needed, because a screen reads this typography on each poll.
        </p>
        <?php if ($styleBad): ?>
            <!-- Nothing this form can submit produces one of these — BrandStyles
                 validates every field on the way in. It reaches here from a row edited
                 outside the app, or written before one of these rules existed. The
                 table below already shows what renders; without this it would look
                 like the row, and the next save would store the substitute for good. -->
            <div style="border-left:4px solid #e67e22; background:#fff8f0; border-radius:4px;
                        padding:10px 14px; margin-bottom:16px;">
                <strong style="color:#e67e22; font-size:13px;">Stored values that cannot be used</strong>
                <ul style="font-size:13px; color:#555; margin:6px 0 0 18px;">
                    <?php foreach ($styleBad as $bad): ?>
                        <li><?= Markup::text($typeLabels[$bad['type']] ?? $bad['type']) ?> —
                            <?= Markup::text($bad['label']) ?> is stored as
                            <?php /* Color::describe() rather than the value: it shortens,
                                    quotes, and names the type of anything that is not a
                                    string. Not colour-specific despite where it lives —
                                    a second copy for font families would be a second
                                    opinion about how to quote a value in a sentence. */ ?>
                            <?= Markup::text(Color::describe($bad['value'])) ?>, so
                            <?= Markup::text($bad['instead']) ?> is what every sign draws.</li>
                    <?php endforeach; ?>
                </ul>
                <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">
                    The boxes below show what is being drawn. Saving this form stores it.
                </p>
            </div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <table class="bs-table">
                <thead>
                    <tr>
                        <th>Block Type</th>
                        <th>Font Family</th>
                        <th>Size (px)</th>
                        <th>Color</th>
                        <th>Weight</th>
                        <th>Style</th>
                        <th>Line Height</th>
                        <th>Live Preview</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($typeLabels as $t => $label):
                    // Through readable(), not out of the row. These six land in a
                    // `style` attribute below, and escaping stops a value ending the
                    // attribute but not the declaration inside it: a stored
                    // `Arial; position: fixed; top: 0` was, after escaping, exactly
                    // that. The cleaners were only ever reached on the way in, which
                    // is a promise about rows this app wrote and about no others.
                    // $stored keeps the row itself, because the notice above the
                    // table quotes what is actually there (#15, §4ai).
                    $stored = $styles[$t] ?? [];
                    $s  = BrandStyles::readable($stored);
                    $ff = $s['font_family'];
                    $fs = $s['font_size'];
                    $fc = $s['font_color'];
                    $fw = $s['font_weight'];
                    $fi = $s['font_style'];
                    $lh = $s['line_height'];
                ?>
                <tr id="bs-row-<?= Markup::text($t) ?>">
                    <td><strong><?= Markup::text($label) ?></strong></td>
                    <td>
                        <select name="bs_<?= Markup::text($t) ?>_family" onchange="updatePreview(<?= Markup::jsInAttr($t) ?>)">
                            <?php foreach ($fontFamilies as $ff_opt): ?>
                                <option value="<?= Markup::text($ff_opt) ?>"
                                    <?= $ff === $ff_opt ? 'selected' : '' ?>><?= Markup::text($ff_opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="bs_<?= Markup::text($t) ?>_size" value="<?= intval($fs) ?>"
                               min="8" max="200" onchange="updatePreview(<?= Markup::jsInAttr($t) ?>)">
                    </td>
                    <td>
                        <input type="color" name="bs_<?= Markup::text($t) ?>_color" value="<?= Markup::text($fc) ?>"
                               oninput="updatePreview(<?= Markup::jsInAttr($t) ?>)">
                    </td>
                    <td>
                        <select name="bs_<?= Markup::text($t) ?>_weight" onchange="updatePreview(<?= Markup::jsInAttr($t) ?>)">
                            <option value="normal" <?= $fw==='normal'?'selected':'' ?>>Normal</option>
                            <option value="bold"   <?= $fw==='bold'  ?'selected':'' ?>>Bold</option>
                        </select>
                    </td>
                    <td>
                        <select name="bs_<?= Markup::text($t) ?>_fstyle" onchange="updatePreview(<?= Markup::jsInAttr($t) ?>)">
                            <option value="normal" <?= $fi==='normal' ?'selected':'' ?>>Normal</option>
                            <option value="italic" <?= $fi==='italic' ?'selected':'' ?>>Italic</option>
                        </select>
                    </td>
                    <td>
                        <input type="number" name="bs_<?= Markup::text($t) ?>_lh" value="<?= number_format(floatval($lh),2) ?>"
                               min="0.8" max="4" step="0.1" style="width:70px;" onchange="updatePreview(<?= Markup::jsInAttr($t) ?>)">
                    </td>
                    <td style="max-width:220px; overflow:hidden; white-space:nowrap;">
                        <span class="preview-text" id="preview-<?= Markup::text($t) ?>"
                              style="font-family:<?= Markup::text($ff) ?>; font-size:<?= intval($fs) ?>px;
                                     color:<?= Markup::text($fc) ?>; font-weight:<?= Markup::text($fw) ?>;
                                     font-style:<?= Markup::text($fi) ?>; line-height:<?= floatval($lh) ?>;
                                     display:inline-block; max-width:200px; overflow:hidden; white-space:nowrap; vertical-align:middle;">
                            <?= Markup::text($label) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:16px;">
                <button type="submit" name="action_save_styles" class="btn btn-green">
                    Save Brand Standards
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- BRANDING TAB                                                  -->
<!-- ============================================================ -->
<div id="tab-branding" style="display:<?= $tab==='branding'?'block':'none' ?>">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
        <input type="hidden" name="existing_logo" value="<?= Markup::text($curLogo) ?>">

        <?php if ($brandBad): ?>
            <!-- Nothing typed into this form can produce one of these — #21 refuses the
                 save and names the field. It reaches here from branding_config.php being
                 a generated file that is documented as hand-editable, or from a colour
                 stored before that rule existed. The pages are already showing the
                 default; saying so is the difference between a page that looks wrong and
                 a page that is wrong, and only one of them is worth anybody's morning. -->
            <div class="card" style="border-left:4px solid #e67e22;">
                <h2 style="color:#e67e22;">Stored colours this app cannot read</h2>
                <p style="font-size:13px; color:#555; margin-bottom:8px;">
                    branding_config.php holds <?= count($brandBad) === 1 ? 'a value' : 'values' ?>
                    that <?= count($brandBad) === 1 ? 'is not a colour' : 'are not colours' ?>.
                    Every page is drawing the default instead. Saving this form replaces
                    <?= count($brandBad) === 1 ? 'it' : 'them' ?> with whatever the pickers below show.
                </p>
                <ul style="font-size:13px; color:#555; margin:0 0 0 18px;">
                    <?php foreach ($brandBad as $bad): ?>
                        <li><?= Markup::text($bad['label']) ?> is stored as
                            <?= Markup::text(Color::describe($bad['value'])) ?>.</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h2>Logo</h2>
            <?php if ($curLogo): ?>
                <div style="margin-bottom:10px;">
                    <img src="<?= Markup::text($curLogo) ?>" alt="Current logo"
                         style="max-height:60px; max-width:200px; object-fit:contain; border:1px solid #eee; padding:4px; border-radius:4px;">
                </div>
                <p style="font-size:12px; color:#7f8c8d; margin-bottom:10px;">Current: <?= Markup::text($curLogo) ?></p>
            <?php endif; ?>
            <div class="form-group">
                <label>Upload Logo (PNG, JPG, SVG)</label>
                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp" onchange="previewBrandLogo(this)">
            </div>
            <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">Max 2 MB. Leave blank to keep existing logo.</p>
            <div id="brand-logo-preview" style="margin-top:10px;display:none;">
                <img id="brand-logo-img" src="" alt="" style="max-height:60px; max-width:200px; object-fit:contain;">
            </div>
        </div>

        <div class="card">
            <h2>Nav Colors</h2>
            <table style="border-collapse:collapse; width:100%; font-size:13px;">
                <tr>
                    <td style="padding:8px 0; width:160px; color:#555; font-weight:600;">Nav Background</td>
                    <td style="padding:8px 0;"><input type="color" name="nav_bg" value="<?= Markup::text($curNavBg) ?>" oninput="brandPreview()"></td>
                    <td style="padding:8px 12px; color:#7f8c8d; font-size:12px;">Top navigation background</td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:#555; font-weight:600;">Nav Border</td>
                    <td style="padding:8px 0;"><input type="color" name="nav_border" value="<?= Markup::text($curBorder) ?>" oninput="brandPreview()"></td>
                    <td style="padding:8px 12px; color:#7f8c8d; font-size:12px;">Bottom border line on nav</td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:#555; font-weight:600;">Accent Color</td>
                    <td style="padding:8px 0;"><input type="color" name="accent" value="<?= Markup::text($curAccent) ?>" oninput="brandPreview()"></td>
                    <td style="padding:8px 12px; color:#7f8c8d; font-size:12px;">Buttons and highlights</td>
                </tr>
                <tr>
                    <td style="padding:8px 0; color:#555; font-weight:600;">Nav Text Color</td>
                    <td style="padding:8px 0;"><input type="color" name="nav_text" value="<?= Markup::text($curText) ?>" oninput="brandPreview()"></td>
                    <td style="padding:8px 12px; color:#7f8c8d; font-size:12px;">Site name text in the nav</td>
                </tr>
            </table>

            <div style="margin-top:16px; background:#2c3e50; border-radius:6px; padding:10px 14px;
                        display:flex; align-items:center; gap:14px;" id="brand-preview-nav">
                <span id="bpv-brand" style="font-weight:bold; font-size:14px; color:<?= Markup::text($curText) ?>;">
                    <?= Markup::text($curSite) ?>
                </span>
                <span id="bpv-btn" style="background:<?= Markup::text($curAccent) ?>; color:#fff;
                      padding:4px 12px; border-radius:4px; font-size:12px;">Publish</span>
            </div>
        </div>

        <button type="submit" name="action_save_branding" class="btn btn-green">Save Branding</button>
    </form>
</div>

<!-- ============================================================ -->
<!-- SETTINGS TAB                                                  -->
<!-- ============================================================ -->
<div id="tab-settings" style="display:<?= $tab==='settings'?'block':'none' ?>">
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">

        <div class="card">
            <h2>Site Name</h2>
            <div class="form-group" style="max-width:400px;">
                <label>Site Name</label>
                <input type="text" name="site_name" value="<?= Markup::text($curSite) ?>"
                       placeholder="Store Display System" style="width:100%;">
            </div>
            <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">Shown in browser tab and on the login screen.</p>
        </div>

        <div class="card">
            <h2>Email Settings</h2>
            <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
                Used for password-reset emails. The <em>From Address</em> must be a real mailbox on your
                hosting domain (e.g. <code>noreply@biggbrawler.com</code>) for emails to be delivered.
            </p>
            <div class="form-row">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label>From Address</label>
                    <input type="email" name="mail_from" value="<?= Markup::text($curMail) ?>"
                           placeholder="noreply@yourdomain.com" style="width:100%;">
                </div>
                <div class="form-group" style="flex:1; min-width:200px;">
                    <label>From Name</label>
                    <input type="text" name="mail_name" value="<?= Markup::text($curMailN) ?>"
                           placeholder="Display System" style="width:100%;">
                </div>
            </div>
        </div>

        <div class="card">
            <h2>Builder Undo</h2>
            <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
                How many steps back the <strong>Undo</strong> button in the Builder can go. A step is
                one finished change — a block moved, a block deleted, a piece of text edited and
                clicked away from. Undo works on the canvas in front of you, before you publish;
                it cannot take back a publish, and it is gone when the page is reloaded or closed.
                Set it to <code>0</code> to remove the button and the Ctrl+Z shortcut entirely.
            </p>
            <div class="form-group" style="max-width:220px;">
                <label>Steps</label>
                <input type="number" name="undo_steps" min="0" max="<?= UNDO_STEPS_MAX ?>" step="1"
                       value="<?= intval($curUndo) ?>" style="width:100%;">
            </div>
            <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">
                0 to <?= UNDO_STEPS_MAX ?>. Each step holds a copy of the whole canvas in the editor's
                browser tab, so a large number on a busy sign is that tab's memory — nothing is
                stored on the server.
            </p>
        </div>

        <button type="submit" name="action_save_settings" class="btn btn-green">Save Settings</button>
    </form>

    <?php
    // Read-only, and outside the form on purpose: nothing here is a setting and
    // there is nothing to submit. See lib/server_report.php for why it exists —
    // in short, the repo is written to a PHP version this code has never observed —
    // 8.2, on the owner's word (#51) — so this card is what confirms it once the build
    // is deployed and what contradicts it if the host ever moves; and the schema
    // converges silently enough that a column can fail to apply for months without
    // anyone noticing.
    $server = new ServerReport($pdo);
    ?>
    <div class="card">
        <h2>This Server</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
            What the site is actually running on. Nothing here can be changed from this page —
            it is here so nobody has to guess. If you are asked what version of PHP the site uses,
            this is the answer.
        </p>
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <?php foreach ($server->runtime() as $label => $fact): ?>
            <tr style="border-bottom:1px solid #ecf0f1;">
                <td style="padding:7px 10px 7px 0; color:#7f8c8d; white-space:nowrap; vertical-align:top;">
                    <?= Markup::text($label) ?>
                </td>
                <td style="padding:7px 0; vertical-align:top;">
                    <strong><?= Markup::text($fact[0]) ?></strong>
                    <?php if ($fact[1] !== ''): ?>
                        <div style="color:#7f8c8d; font-size:12px; margin-top:2px;"><?= Markup::text($fact[1]) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Errors and Alerts</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
            When something breaks, nothing is printed on the page — it is written to a log file and,
            once an hour at most, emailed to the admins listed below. If a sign ever goes dark on its
            own, this is the first place to look. Recipients are refreshed every time this page loads.
        </p>
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
            <?php foreach (ErrorPolicy::status() as $label => $fact): ?>
            <tr style="border-bottom:1px solid #ecf0f1;">
                <td style="padding:7px 10px 7px 0; color:#7f8c8d; white-space:nowrap; vertical-align:top;">
                    <?= Markup::text($label) ?>
                </td>
                <td style="padding:7px 0; vertical-align:top; word-break:break-all;">
                    <strong><?= Markup::text($fact[0]) ?></strong>
                    <?php if ($fact[1] !== ''): ?>
                        <div style="color:#7f8c8d; font-size:12px; margin-top:2px; word-break:normal;"><?= Markup::text($fact[1]) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h2>Database Structure</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
            Some columns are added to the database automatically the first time an admin signs in
            after an update. If one of them could not be added, the feature that needs it stops
            working quietly rather than saying so — this is where that shows up.
        </p>
        <?php if ($server->isConverged()): ?>
            <p style="color:#27ae60; font-size:13px; font-weight:600;">
                &#10003; Everything this version of the app expects is in place.
            </p>
        <?php else: ?>
            <p style="color:#c0392b; font-size:13px; font-weight:600; margin-bottom:10px;">
                Something is missing. Signing out and back in as an admin usually applies it;
                if a row below stays red, the database itself is refusing the change.
            </p>
        <?php endif; ?>
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin-top:8px;">
            <?php foreach ($server->convergence() as $col): ?>
            <tr style="border-bottom:1px solid #ecf0f1;">
                <td style="padding:6px 10px 6px 0; white-space:nowrap;">
                    <code><?= Markup::text($col['table']) ?>.<?= Markup::text($col['column']) ?></code>
                </td>
                <td style="padding:6px 0; color:<?= $col['ok'] ? '#27ae60' : '#c0392b' ?>; font-weight:600;">
                    <?= $col['ok'] ? '&#10003; present' : '&#10007; missing' ?>
                    <?php if ($col['note'] !== ''): ?>
                        <span style="color:#7f8c8d; font-weight:normal;">— <?= Markup::text($col['note']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>

<!-- ============================================================ -->
<!-- WORK AREA TAB                                                  -->
<!-- ============================================================ -->
<div id="tab-workarea" style="display:<?= $tab==='workarea'?'block':'none' ?>">
    <div class="card">
        <h2>Canvas Elements</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:16px;">
            All elements currently on one display's canvas. Hide removes the element from that display
            immediately — no publish required. Delete permanently removes it from the canvas. Either one
            means a builder tab opened before now must reload before it can publish.
        </p>
        <div class="form-row" style="margin-bottom:14px;">
            <div class="form-group">
                <label>Display</label>
                <select id="wa-display" onchange="loadCanvasElements()" style="width:340px;">
                    <?php foreach ($displays as $d): ?>
                    <option value="<?= Markup::text($d->tag()) ?>" data-id="<?= intval($d->id()) ?>">
                        <?= Markup::text($d->title()) ?> — <?= Markup::text($d->tag()) ?>
                        (<?= Markup::text($d->dimensionsLabel()) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button class="btn btn-blue" style="font-size:12px;" onclick="loadCanvasElements()">&#8635; Refresh</button>
            </div>
        </div>
        <div id="canvas-elements-wrap">
            <p style="color:#7f8c8d;font-size:13px;">Click Refresh or open this tab to load.</p>
        </div>
    </div>
</div>

</div><!-- .content -->

<script>
    var _tabs = ['users','displays','brand','branding','settings','workarea'];
    var CSRF_TOKEN = <?= HttpReply::jsValue(csrfToken()) ?>;

    // Every Display, for filtering the "duplicate from" list to the ones of the
    // exact size being created (ADR-0004). The server checks it again — this only
    // stops the wrong choice being offered.
    var DISPLAYS = <?= HttpReply::jsValue($dupCandidates) ?>;

    function showTab(name) {
        _tabs.forEach(function(t) {
            document.getElementById('tab-' + t).style.display = t === name ? 'block' : 'none';
        });
        document.querySelectorAll('.tab-btn').forEach(function(b, i) {
            b.classList.toggle('active', _tabs[i] === name);
        });
        if (name === 'workarea') loadCanvasElements();
    }

    // ── Displays ───────────────────────────────────────────────
    function togglePanel(id) {
        var el = document.getElementById(id);
        if (el) el.classList.toggle('open');
    }

    function copyAddr(id) {
        var input = document.getElementById('addr-' + id);
        input.select();
        // execCommand is deprecated but is the only copy that works without HTTPS,
        // and this app is reached over plain HTTP on the store network.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(input.value);
        } else {
            try { document.execCommand('copy'); } catch (e) { /* the text is selected either way */ }
        }
    }

    /**
     * The account name arrives as a JavaScript value, never spliced into one.
     *
     * This sentence used to be built in the HTML attribute with the username
     * escaped for HTML, which the HTML parser undoes before this parser sees it —
     * so `o'brien` ended the string and anything after it ran (#15). Nothing is
     * escaped here because nothing needs to be: it is already a string.
     */
    function confirmCloseAccount(username) {
        return confirm('Close the account for ' + username + '?\n\n' +
            'They will not be able to sign in again, and they will lose access to ' +
            'every display. This cannot be undone, and the name stays reserved.');
    }

    function confirmTurnOff(title) {
        return confirm('Turn off ' + title + '?\n\n' +
            'The layout is kept and stays editable by admins, but any screen showing it will say ' +
            '"This display is turned off" within 30 seconds.');
    }

    /** Renaming the tag changes the display's address, so say so before saving. */
    function confirmTagChange(form) {
        var field = form.querySelector('[name="d_tag"]');
        var was   = field.getAttribute('data-original-tag');
        var now   = (field.value || '').trim().toLowerCase();
        if (now === was) return true;
        return confirm(
            'Change the screen name tag from "' + was + '" to "' + now + '"?\n\n' +
            'The display\'s address becomes:\n    viewer.php?display=' + now + '\n\n' +
            'Anything still pointed at the old address — a TV, a signage widget, a bookmark — ' +
            'will show "Display not found" until you re-point it.');
    }

    // ── Add a display: size first, then the start-from choice ──
    function applyPreset() {
        var v = document.getElementById('d_preset').value;
        if (v && v !== 'custom') {
            var parts = v.split('x');
            document.getElementById('d_width').value  = parts[0];
            document.getElementById('d_height').value = parts[1];
        } else if (v === 'custom') {
            document.getElementById('d_width').focus();
        }
        sizeChanged();
    }

    function chosenSize() {
        var w = parseInt(document.getElementById('d_width').value, 10);
        var h = parseInt(document.getElementById('d_height').value, 10);
        var min = <?= DisplayStore::CANVAS_MIN ?>, max = <?= DisplayStore::CANVAS_MAX ?>;
        if (!w || !h || w < min || h < min || w > max || h > max) return null;
        return { w: w, h: h };
    }

    /**
     * The size gates step 3: until it is set there is nothing to duplicate *from*,
     * because "same size" is the only thing that makes a copy meaningful.
     */
    function sizeChanged() {
        var size   = chosenSize();
        var step   = document.getElementById('start-step');
        var fields = document.getElementById('start-fields');
        var locked = document.getElementById('start-locked-note');
        var readout = document.getElementById('size-readout');

        if (!size) {
            step.classList.add('locked');
            fields.disabled = true;
            locked.style.display = 'block';
            readout.textContent = 'No size chosen yet';
            return;
        }

        var shape = size.h > size.w ? 'portrait' : (size.w > size.h ? 'landscape' : 'square');
        readout.textContent = size.w + ' × ' + size.h + ' ' + shape;
        step.classList.remove('locked');
        fields.disabled = false;
        locked.style.display = 'none';

        var matches = DISPLAYS.filter(function(d) { return d.w === size.w && d.h === size.h; });
        var select  = document.getElementById('d_source');
        var dupRadio = document.getElementById('d_start_dup');
        select.innerHTML = matches.map(function(d) {
            return '<option value="' + escHtml(d.tag) + '">' + escHtml(d.title) + ' — ' + escHtml(d.tag) + '</option>';
        }).join('');

        dupRadio.disabled = matches.length === 0;
        if (matches.length === 0) {
            if (dupRadio.checked) document.querySelector('[name="d_start"][value="blank"]').checked = true;
            document.getElementById('dup-note').textContent =
                'No display is ' + size.w + ' × ' + size.h + ', so there is nothing to copy from at this size. ' +
                'A layout can only be duplicated between displays of exactly the same size.';
        } else {
            document.getElementById('dup-note').textContent =
                matches.length + ' display' + (matches.length === 1 ? '' : 's') + ' at this exact size can be ' +
                'copied from. The copy takes positions, hidden and locked blocks and section backgrounds, ' +
                'and points at the same library assets.';
        }
        startChanged();
    }

    function startChanged() {
        var dup = document.getElementById('d_start_dup');
        document.getElementById('d_source').disabled = !(dup && dup.checked);
    }

    /** Fill the tag from the title until the admin types their own. */
    function suggestTag() {
        var tagField = document.getElementById('d_tag');
        var suggested = document.getElementById('d_title').value
            .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').substring(0, 32)
            .replace(/-+$/, '');
        if (!tagField.dataset.touched) { tagField.value = suggested; }
        document.getElementById('tag-echo').textContent = (tagField.value || suggested) || 'lobby-screen';
    }

    (function initDisplayForm() {
        var tagField = document.getElementById('d_tag');
        if (!tagField) return;
        tagField.addEventListener('input', function() {
            this.dataset.touched = this.value === '' ? '' : '1';
            document.getElementById('tag-echo').textContent = this.value || 'lobby-screen';
        });
        sizeChanged();
    })();

    // ── Work Area ──────────────────────────────────────────────
    /** Which Display the Work Area is looking at — every call here is scoped to it. */
    function waDisplay() {
        var sel = document.getElementById('wa-display');
        return sel ? sel.value : '';
    }

    /**
     * The id of that same Display, as this page was built. Sent alongside the tag
     * so a rename in another tab cannot point a hide or a delete at whichever sign
     * inherited the name — the server refuses the pair rather than acting on it.
     */
    function waDisplayId() {
        var sel = document.getElementById('wa-display');
        if (!sel || sel.selectedIndex < 0) { return ''; }
        return sel.options[sel.selectedIndex].getAttribute('data-id') || '';
    }

    function loadCanvasElements() {
        var wrap = document.getElementById('canvas-elements-wrap');
        var tag  = waDisplay();
        if (!tag) {
            wrap.innerHTML = '<p style="color:#7f8c8d;font-size:13px;">No displays exist yet — add one on the Displays tab.</p>';
            return;
        }
        wrap.innerHTML = '<p style="color:#7f8c8d;font-size:13px;">Loading…</p>';
        fetch('api.php?action=get_canvas_elements&display=' + encodeURIComponent(tag)
              + '&display_id=' + encodeURIComponent(waDisplayId()))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!Array.isArray(data)) {
                    // A resolution failure comes back as an object carrying the notice.
                    wrap.innerHTML = '<p style="color:#e74c3c;font-size:13px;">' +
                        escHtml((data && data.message) || 'Could not load that display.') + '</p>';
                    return;
                }
                if (!data.length) {
                    wrap.innerHTML = '<p style="color:#7f8c8d;font-size:13px;">No elements on this display\'s canvas.</p>';
                    return;
                }
                renderElementsList(data);
            })
            .catch(function() {
                wrap.innerHTML = '<p style="color:#e74c3c;font-size:13px;">Failed to load elements.</p>';
            });
    }

    function renderElementsList(elements) {
        // Map section id → display number
        var secNum = {}, n = 0;
        elements.forEach(function(el) { if (el.type === 'section') secNum[el.id] = ++n; });

        var rows = elements.map(function(el) {
            var isHidden = parseInt(el.hidden) === 1;
            var parentCell = el.section_id
                ? '<span style="font-size:11px;color:#7f8c8d;">Section ' + (secNum[el.section_id] || el.section_id) + '</span>'
                : '—';
            var hiddenTag  = isHidden ? '<span class="el-hidden-tag">HIDDEN</span>' : '';
            var toggleLbl  = isHidden ? '&#128065; Show' : '&#128683; Hide';
            var toggleCls  = isHidden ? 'btn-green' : 'btn-gray';
            return '<tr>' +
                '<td><span class="el-badge el-' + el.type + '">' + el.type + '</span></td>' +
                '<td><span class="el-desc">' + escHtml(elDesc(el)) + '</span>' + hiddenTag + '</td>' +
                '<td>' + parentCell + '</td>' +
                '<td style="white-space:nowrap;color:#555;">' + el.width + '×' + el.height + '</td>' +
                '<td><button class="btn ' + toggleCls + '" style="font-size:11px;padding:4px 10px;" ' +
                    'onclick="setElHidden(' + el.id + ',' + (isHidden ? 0 : 1) + ')">' + toggleLbl + '</button></td>' +
                '<td><button class="btn btn-red" style="font-size:11px;padding:4px 10px;" ' +
                    'onclick="delEl(' + el.id + ',\'' + el.type + '\')">' +
                    '&#128465; Delete</button></td>' +
            '</tr>';
        }).join('');

        document.getElementById('canvas-elements-wrap').innerHTML =
            '<table><thead><tr>' +
            '<th>Type</th><th>Description</th><th>Parent</th><th>Size</th><th>Visibility</th><th>Delete</th>' +
            '</tr></thead><tbody>' + rows + '</tbody></table>';
    }

    function elDesc(el) {
        if (el.type === 'section')  return 'Section';
        if (el.type === 'image')    return 'Image';
        if (el.type === 'video')    return 'Video';
        if (el.type === 'carousel') return 'Carousel';
        if (el.type === 'marquee') {
            try { return (JSON.parse(el.manual_content || '{}').text || 'Marquee').substring(0, 60); }
            catch(e) { return 'Marquee'; }
        }
        if (el.type === 'table') {
            try {
                var td = JSON.parse(el.manual_content || '{}');
                return 'Table ' + (td.headers||[]).length + ' col × ' + (td.rows||[]).length + ' row';
            } catch(e) { return 'Table'; }
        }
        // text
        var labels = { section_header:'Section Header', item_title:'Item Title', item_title_2:'Item Title 2',
                       price:'Price', price_2:'Price 2', description:'Description' };
        var prefix = (el.block_subtype && el.block_subtype !== 'free') ? '[' + (labels[el.block_subtype]||el.block_subtype) + '] ' : '';
        var txt = (el.manual_content || '').replace(/<[^>]*>/g, '').substring(0, 60);
        return prefix + (txt || '(empty)');
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function setElHidden(id, hidden) {
        var fd = new FormData();
        fd.append('action', 'set_element_hidden');
        fd.append('element_id', id);
        fd.append('hidden', hidden);
        fd.append('display', waDisplay());
        fd.append('display_id', waDisplayId());
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('api.php', { method:'POST', body:fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.status === 'success') { loadCanvasElements(); }
                else { alert('Error: ' + (res.message || 'Unknown')); }
            });
    }

    function delEl(id, type) {
        var msg = type === 'section'
            ? 'Delete this section AND all elements inside it? This cannot be undone.'
            : 'Delete this element from the canvas? This cannot be undone.';
        if (!confirm(msg)) return;
        var fd = new FormData();
        fd.append('action', 'delete_canvas_element');
        fd.append('element_id', id);
        fd.append('display', waDisplay());
        fd.append('display_id', waDisplayId());
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('api.php', { method:'POST', body:fd })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.status === 'success') { loadCanvasElements(); }
                else { alert('Error: ' + (res.message || 'Unknown')); }
            });
    }

    // Auto-load if Work Area is the landing tab
    if (<?= HttpReply::jsValue($tab) ?> === 'workarea') loadCanvasElements();

    function brandPreview() {
        var nav  = document.getElementById('brand-preview-nav');
        var bg   = document.querySelector('[name="nav_bg"]').value;
        var brd  = document.querySelector('[name="nav_border"]').value;
        var acc  = document.querySelector('[name="accent"]').value;
        var txt  = document.querySelector('[name="nav_text"]').value;
        if (nav) {
            nav.style.background = bg;
            nav.style.borderBottom = '2px solid ' + brd;
            document.getElementById('bpv-brand').style.color = txt;
            document.getElementById('bpv-btn').style.background = acc;
        }
    }

    function previewBrandLogo(input) {
        if (!input.files || !input.files[0]) return;
        var wrap = document.getElementById('brand-logo-preview');
        var img  = document.getElementById('brand-logo-img');
        var reader = new FileReader();
        reader.onload = function(e) { img.src = e.target.result; wrap.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }

    function toggleEdit(id) {
        var row = document.getElementById('edit-' + id);
        row.classList.toggle('open');
    }

    function updatePreview(type) {
        var row     = document.getElementById('bs-row-' + type);
        var preview = document.getElementById('preview-' + type);
        preview.style.fontFamily  = row.querySelector('[name$="_family"]').value;
        preview.style.fontSize    = row.querySelector('[name$="_size"]').value + 'px';
        preview.style.color       = row.querySelector('[name$="_color"]').value;
        preview.style.fontWeight  = row.querySelector('[name$="_weight"]').value;
        preview.style.fontStyle   = row.querySelector('[name$="_fstyle"]').value;
        preview.style.lineHeight  = row.querySelector('[name$="_lh"]').value;
    }
</script>
</body>
</html>
