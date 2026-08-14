<?php
require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/displays.php';
require_once __DIR__ . '/lib/layout_store.php';
require_once __DIR__ . '/lib/grants.php';
require_once __DIR__ . '/lib/display_admin.php';
require_once __DIR__ . '/lib/brand_styles.php';
require_once __DIR__ . '/lib/brands.php';
require_once __DIR__ . '/lib/brand_admin.php';
require_once __DIR__ . '/lib/assets.php';
require_once __DIR__ . '/lib/branding.php';
require_once __DIR__ . '/lib/server_report.php';
require_once __DIR__ . '/lib/password_resets.php';
require_once __DIR__ . '/lib/accounts.php';
// Explicit, though display_admin.php pulls it in too: this page asks Color directly
// when it refuses a branding colour, and a transitive include is not a dependency.
require_once __DIR__ . '/lib/color.php';
// Same reason: this page asks StoreClock directly — it offers the zone list, refuses a
// value that is not one, and prints three stored stamps through it — so the include is
// named here rather than arriving through config.php.
require_once __DIR__ . '/lib/store_clock.php';
// Same reason again: this page has a logo upload, so it asks UploadLimit for its own
// ceiling and for the sentence a dropped request body gets. server_report.php pulls
// the file in as well, and a transitive include is not a dependency.
require_once __DIR__ . '/lib/upload_limits.php';
// The other noun: Workspace Themes, which this page is also where you make one.
require_once __DIR__ . '/lib/workspace_themes.php';
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

// What this admin's own screens are painted in (v2 step 5). After convergence, because
// the column it reads is one the plan adds, and the account is passed in rather than
// reached for. This page is a light document with a chrome nav, so what a theme paints
// here is the nav and the buttons — see the `:root` block below.
$themeStore = new WorkspaceThemeStore($pdo);
SiteChrome::wear($themeStore->forAccount($user['id']));

// Displays are administered through DisplayAdmin: this page collects the form and
// shows the answer, and every rule about what a Display may be lives in lib/.
$displayStore = new DisplayStore($pdo);
$layoutStore  = new LayoutStore($pdo, $displayStore);
$grantStore   = new GrantStore($pdo);
// Brands are administered through BrandAdmin for the same reason Displays go through
// DisplayAdmin: creating one writes a `brands` row *and* six `block_styles` rows, and
// half of that landing is a Brand whose typography form saves nothing and says nothing.
$brandStore   = new BrandStore($pdo);
$brandStyles  = new BrandStyles($pdo);
$brandAdmin   = new BrandAdmin($pdo, $brandStore, $brandStyles, $displayStore);
$displayAdmin = new DisplayAdmin($pdo, $displayStore, $layoutStore, $grantStore, $brandStore);

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

// The four colours are read back through SiteChrome:: rather than taken from the config
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
$brandBad   = SiteChrome::unreadable();
$curLogo    = $brand['BRAND_LOGO'];
// `configColor()` and not the painted accessors, which is the whole of a defect step 5
// was one edit away from shipping: this form edits the *store's* colours, and through
// the layered read an admin wearing a theme would have been shown their own theme's
// colours as "what is there now" and saved them over the shop's. #21's shape exactly —
// the wrong value, stored, with a green message.
$curNavBg   = SiteChrome::configColor('nav_bg');
$curBorder  = SiteChrome::configColor('nav_border');
$curAccent  = SiteChrome::configColor('accent');
$curText    = SiteChrome::configColor('nav_text');
$curSite    = $brand['SITE_NAME'];
$curMail    = $brand['MAIL_FROM'];
$curMailN   = $brand['MAIL_FROM_NAME'];

// Through undoStepsSetting(), not the raw constant: config.php is the one place
// that decides what a stored undo depth means, and the Builder reads it the same
// way — so this form cannot offer a number the editor would not act on.
$curUndo    = undoStepsSetting();

// And the same rule one setting further, for the same reason (#44): what this form
// offers as "the zone now" is `StoreClock::zone()`, the answer every time on every page
// is actually drawn in, not the raw `STORE_TIMEZONE`. A stored value this app will not
// use would otherwise be shown as selected in a dropdown that is not what the clock is
// doing, and "keep what is there" would then mean storing it back. `$zoneBad` is what
// the Settings tab says so with — the same shape as `$brandBad`, and reachable the same
// one way, by hand-editing the generated file (#21).
$curZone    = StoreClock::zone();
$zoneBad    = StoreClock::unreadable();

// ============================================================
// USER MANAGEMENT ACTIONS
// ============================================================
// Before the CSRF gate below, for the reason api.php and crud.php both check it there:
// a POST whose body PHP dropped for exceeding `post_max_size` carries no token either,
// so verifyCsrf() answered a logo that was too big with a bare 403 about a security
// token — and the Brand tab is the one place on this page a large file can be picked.
// Nothing else on this request is readable in that state, so no handler below can run;
// the sentence is all there is to give.
if (UploadLimit::bodyWasDropped($_SERVER, $_POST, $_FILES)) {
    http_response_code(413);
    $msg     = UploadLimit::droppedBodyMessage();
    $msgType = 'error';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            'brand_id'       => $_POST['d_brand']    ?? '',
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
                // Declared by the form, like every other field on it. Changing it
                // repaints that sign within 30 seconds with no publish — the Admin
                // Panel's ordinary contract, and the reason the Builder's own Brand
                // control is staged behind Publish instead (ADR-0011, decision 6).
                'brand_id' => $_POST['d_brand']    ?? '',
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
            // Asked rather than stated: `2 * 1024 * 1024` here was this page's own
            // opinion about a limit it could not see, and on a host whose post_max_size
            // is under 2M the request never arrived to be measured. logoBytes() is the
            // 2 MB decision capped by what can actually reach the server, and it is
            // the same call the label beside the picker quotes.
            } elseif ($_FILES['logo_file']['size'] > UploadLimit::logoBytes()) {
                $msg = 'That logo is ' . UploadLimit::describeBytes($_FILES['logo_file']['size'])
                     . '. It must be ' . UploadLimit::describeLogo() . ' or smaller.';
                $msgType = 'error';
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
        // A zone that is not one is refused and named, not swapped for something that
        // is — the #21 rule, and it matters more here than for a colour, because the
        // wrong answer is not visibly wrong. A sign shows a colour; a clock two hours
        // out shows a perfectly ordinary time — which is what this host was doing. A field that was never on the form keeps
        // what is stored, which is the different case: this form says what it covered.
        $zoneBadSubmit = '';
        if (!isset($_POST['store_timezone'])) {
            $storeZone = $curZone;
        } elseif (StoreClock::isZone($_POST['store_timezone'])) {
            $storeZone = (string)$_POST['store_timezone'];
        } else {
            $zoneBadSubmit = is_string($_POST['store_timezone']) ? $_POST['store_timezone'] : '';
            $storeZone     = $curZone;
            $msg     = ($zoneBadSubmit === '' ? 'That' : '"' . $zoneBadSubmit . '"')
                     . ' is not a time zone this server knows, so nothing was saved.'
                     . ' Pick one from the list — a name like America/Los_Angeles, not'
                     . ' an offset, because only a name knows when daylight saving'
                     . ' starts. Nothing was changed.';
            $msgType = 'error';
        }
        if ($msg === '') {
            $res = $branding->save([
                'SITE_NAME'      => $siteName,
                'MAIL_FROM'      => $mailFrom,
                'MAIL_FROM_NAME' => $mailName,
                'UNDO_STEPS'     => (string)$undoSteps,
                'STORE_TIMEZONE' => $storeZone,
            ]);
            if ($res->isOk()) {
                // Redirect rather than re-render, which the three settings above did
                // not need and this one does. A `define()` cannot be undone: the ten
                // constants were fixed at the top of *this* request, so everything
                // downstream that reads them — `StoreClock::zone()`, and through it the
                // "This Server" card a few inches below the dropdown — would go on
                // answering the zone that was there before the save. Patching page
                // variables by hand is what the branding form does, and it does not
                // reach a module that reads the constant itself.
                //
                // A fresh request loads the file that was just written, so what the
                // form shows and what the clock is doing cannot disagree. It also makes
                // F5 harmless, which is the other half of `flashMessage()`'s reason.
                flashMessage('Settings saved.');
                header('Location: admin_panel.php?tab=settings');
                exit;
            }
            $msg = $res->message(); $msgType = 'error';
        }
        $tab = 'settings';
    }

    // Create a Brand — the row and its six sets of standards, in one transaction.
    if (isset($_POST['action_create_brand'])) {
        $tab = 'brand';
        $res = $brandAdmin->create(['name' => $_POST['b_name'] ?? '']);
        $msg     = $res->message();
        $msgType = $res->isOk() ? 'success' : 'error';
        // Land on the Brand that was just made, so its typography form is the one on
        // screen rather than whichever Brand happened to be selected before.
        if ($res->isOk() && $res->brand()) { $_GET['brand'] = (string)$res->brand()->id(); }
    }

    // Save a Brand's name, logo, default background and palette.
    if (isset($_POST['action_save_brand'])) {
        $tab   = 'brand';
        $brand = $brandStore->forId($_POST['b_id'] ?? 0);
        if (!$brand) {
            $msg = 'That brand no longer exists.'; $msgType = 'error';
        } else {
            $_GET['brand'] = (string)$brand->id();
            $fields = ['name'          => $_POST['b_name'] ?? '',
                       'logo_asset_id' => $_POST['b_logo'] ?? '',
                       'bg_type'       => 'color',
                       'bg_val'        => $_POST['b_bg']   ?? ''];
            // An `<input type="color">` always submits *something*, so "this slot is
            // empty" cannot be said by leaving it blank — the box would post the black
            // it fell back to and the palette would gain a colour nobody chose. The
            // tick box beside each one is how the form says it, and it is read here as
            // the slot being cleared. Every slot is named whether it was filled in or
            // not, which is the grant matrix's rule: a browser posts only the ticked
            // boxes, so an unticked box and a field that was never on the page look
            // identical, and only a declared axis tells them apart.
            foreach (BrandStore::paletteFields() as $_pf) {
                $fields[$_pf] = empty($_POST['b_' . $_pf . '_unset'])
                    ? ($_POST['b_' . $_pf] ?? '') : '';
            }
            unset($_pf);

            // Narrowed from "anyone editing anything" to "anyone editing a sign
            // wearing this Brand" (ADR-0011). The refusal names the Display and the
            // holder, because "somebody is editing" is not something a person can act
            // on without going to look.
            $busy = $displayStore->editedByAnyoneElseUsingBrand($user['id'], $brand->id());
            if ($busy) {
                $msg     = $busy->editingSentence()
                         . ' That display wears this brand, and a brand change reaches every'
                         . ' screen wearing it within 30 seconds without a publish, so it'
                         . ' cannot change while somebody is editing one. Try again once they'
                         . ' are finished.';
                $msgType = 'error';
            } else {
                $res     = $brandAdmin->updateDetails($brand, $fields);
                $msg     = $res->message();
                $msgType = $res->isOk() ? 'success' : 'error';
            }
        }
    }

    // Delete a Brand, with its name typed back. Refused while any sign wears it.
    if (isset($_POST['action_delete_brand'])) {
        $tab   = 'brand';
        $brand = $brandStore->forId($_POST['b_id'] ?? 0);
        if (!$brand) {
            $msg = 'That brand no longer exists.'; $msgType = 'error';
        } else {
            $res     = $brandAdmin->destroy($brand, $_POST['b_confirm_name'] ?? '');
            $msg     = $res->message();
            $msgType = $res->isOk() ? 'success' : 'error';
            if (!$res->isOk()) { $_GET['brand'] = (string)$brand->id(); }
        }
    }

    // ---- Workspace Themes (v2 step 5) ------------------------------------------------
    // The other noun, and the shorter handler of the two, because a theme is one row in
    // one table. That is the whole reason there is no `ThemeAdmin` beside `BrandAdmin`:
    // a Brand needs its six `block_styles` rows or its typography form reports success
    // and changes nothing, so creating one spans two tables and needs a transaction. A
    // theme has no second table to be half of.
    //
    // Nothing here refuses a save because somebody holds an edit lock, and that is not
    // an omission. A Brand edit reaches every Screen wearing it on the next poll; a
    // theme edit reaches one person's browser on their next page load and no sign, ever.
    // The lock exists to stop typography changing under somebody sizing blocks against
    // it, and there is nothing here for it to protect.
    if (isset($_POST['action_create_theme']) || isset($_POST['action_save_theme'])) {
        $tab      = 'branding';
        $creating = isset($_POST['action_create_theme']);
        $editing  = $creating ? null : $themeStore->forId($_POST['t_id'] ?? 0);

        if (!$creating && !$editing) {
            $msg = 'That theme no longer exists, so nothing was saved.'; $msgType = 'error';
        } else {
            // Every role is named whether the form filled it in or not, which is the
            // grant matrix's rule (§4s) in a smaller place: a whole-row save cannot tell
            // a role somebody cleared from one that was never on the page, so the page
            // declares all thirteen and the save writes all thirteen. An
            // `<input type="color">` always posts something, so there is no "cleared"
            // state to distinguish here — every role is a colour or the form is broken.
            $tFields = ['name' => $_POST['t_name'] ?? ''];
            foreach (array_keys(SiteChrome::ROLES) as $_tr) {
                $tFields[$_tr] = $_POST['t_' . $_tr] ?? '';
            }
            unset($_tr);

            $tName    = PickerName::clean($tFields['name']);
            $tBadCols = WorkspaceThemeStore::unreadableIn($tFields);
            $tClash   = $themeStore->otherThemeNamed($tName, $editing ? $editing->id() : 0);

            if (!PickerName::isValid($tName)) {
                $msg     = 'A theme needs a name of its own, no longer than '
                         . intval(PickerName::MAX) . ' characters. Nothing was saved.';
                $msgType = 'error';
            } elseif ($tClash) {
                // Quoting the stored name rather than the typed one, because those differ
                // exactly when the case-insensitive comparison did the work it exists for.
                $msg     = 'There is already a theme called ' . $tClash->name()
                         . '. Give this one a different name — two themes with the same '
                         . 'name on one picker cannot be told apart. Nothing was saved.';
                $msgType = 'error';
            } elseif ($tBadCols) {
                // Named, never substituted (#21). Every bad one at once, so somebody who
                // pasted three does not resubmit three times.
                $tSaid = [];
                foreach ($tBadCols as $_trole => $_tval) {
                    $tSaid[] = SiteChrome::ROLES[$_trole][0] . ' (' . Color::describe($_tval) . ')';
                }
                unset($_trole, $_tval);
                $msg     = 'Nothing was saved: ' . implode(', ', $tSaid)
                         . ' — ' . (count($tSaid) === 1 ? 'that is not a colour this app can store.'
                                                        : 'those are not colours this app can store.');
                $msgType = 'error';
            } else {
                $tSaved = $creating ? $themeStore->insert($tFields)
                                    : $themeStore->updateDetails($editing, $tFields);
                if (!$tSaved) {
                    $msg = 'That theme could not be saved.'; $msgType = 'error';
                } else {
                    $_GET['theme'] = (string)$tSaved->id();
                    // Warned about, never refused (decision 13): an admin owns their own
                    // legibility policy, and a rule that refused would be the enforced
                    // palette ADR-0011 turned down wearing different clothes. Said on the
                    // save as well as live in the form, because the live warning is
                    // JavaScript and a saved theme is a fact.
                    $tWarn = Color::hardToRead($tSaved->colorFor('nav_text'), $tSaved->colorFor('nav_bg'))
                        ? ' Its navigation text is hard to read on its navigation background —'
                        . ' that is allowed, and worth looking at on a screen before anybody'
                        . ' chooses it.'
                        : '';
                    $msg     = ($creating ? 'Theme ' : 'Saved ') . $tSaved->name()
                             . ($creating ? ' created.' : '.')
                             . ' It is on everybody\'s picker; nobody is wearing it until they'
                             . ' choose it.' . $tWarn;
                    $msgType = 'success';
                }
            }
        }
    }

    // Delete a theme. Refused while anybody is wearing it, naming them — the same shape
    // as a Brand a sign still wears, and for the same reason: moving three people back to
    // the store default on one click is the merge invariant 5 exists to prevent, and the
    // person who would notice is not the one clicking.
    if (isset($_POST['action_delete_theme'])) {
        $tab    = 'branding';
        $tGoing = $themeStore->forId($_POST['t_id'] ?? 0);
        if (!$tGoing) {
            $msg = 'That theme no longer exists.'; $msgType = 'error';
        } else {
            $tWearers = $themeStore->accountsUsing($tGoing);
            if ($tWearers) {
                $msg     = 'Nothing was deleted: ' . implode(', ', $tWearers)
                         . (count($tWearers) === 1 ? ' is' : ' are')
                         . ' using ' . $tGoing->name() . '. Removing it would change '
                         . (count($tWearers) === 1 ? 'their' : 'their') . ' screens without'
                         . ' telling them — they can switch to the store default themselves,'
                         . ' from the gear menu in the Builder.';
                $msgType = 'error';
                $_GET['theme'] = (string)$tGoing->id();
            } else {
                $tName = $tGoing->name();
                $themeStore->deleteRow($tGoing);
                $msg     = 'Theme ' . $tName . ' deleted. Nobody was using it.';
                $msgType = 'success';
            }
        }
    }

    // Save brand standards
    if (isset($_POST['action_save_styles'])) {
        $types = ['section_header','item_title','item_title_2','price','price_2','description'];
        $tab   = 'brand';

        $brand = $brandStore->forId($_POST['b_id'] ?? 0);
        if ($brand) { $_GET['brand'] = (string)$brand->id(); }

        // The same refusal the API makes, narrowed to the Brand being edited: these
        // rows reach every Screen *wearing this Brand* on the next poll with no
        // publish, so a live lock on one of those signs is a claim on them. Somebody
        // working a sign wearing a different Brand is nothing to do with this save
        // (ADR-0011) — the one place this work makes the app less restrictive.
        $busy = $brand ? $displayStore->editedByAnyoneElseUsingBrand($user['id'], $brand->id()) : null;
        if (!$brand) {
            $msg     = 'That brand no longer exists, so nothing was saved.';
            $msgType = 'error';
        } elseif ($busy) {
            $msg     = $busy->editingSentence()
                     . ' That display wears this brand, and brand standards reach every screen'
                     . ' wearing it within 30 seconds without a publish, so they cannot change'
                     . ' while somebody is editing one. Try again once they are finished.';
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
            $saved = $brandStyles->save($brand->id(), $submitted);
            $msg = $saved
                ? 'Brand standards for "' . $brand->name() . '" saved. Every screen wearing it '
                  . 'picks them up within 30 seconds — no publishing needed.'
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
// ---- Brands, and which one the Display Branding tab is showing --------------
// Every Brand for the list and for the Display forms' dropdowns; one of them is
// "open", and its standards are what the typography table below edits.
$brands = $brandStore->all();

// The Brand on screen. From the query string so the list can link to each one, and
// from the POST handlers above so a refused save redraws the Brand it refused. A
// value naming no Brand falls back to the first rather than erroring: the tab has to
// render something, and there is always at least one Brand on a converged database.
$openBrand = $brandStore->forId($_GET['brand'] ?? 0);
if (!$openBrand && $brands) { $openBrand = $brands[0]; }

// The Workspace Themes the Site Branding tab lists, and which one — if any — its form is
// editing. Deliberately **not** falling back to the first theme the way `$openBrand`
// does: a Brand always exists and its typography form has to be about one of them, while
// a theme is optional and the form's resting state is "make a new one". Landing on
// somebody's theme by default would put a Delete button under a name nobody named.
$themes    = $themeStore->all();
$openTheme = $themeStore->forId($_GET['theme'] ?? 0);

// Who is wearing what, so the list can say it and the delete refusal can name them. One
// read per theme, on a page that already does a dozen — and the alternative is a page
// that offers a Delete it is going to refuse.
$themeWearers = [];
foreach ($themes as $_t) { $themeWearers[$_t->id()] = $themeStore->accountsUsing($_t); }
unset($_t);

// Which signs wear each Brand — for the count on every row of the list, and for the
// sentence on the delete confirm. Asked once here rather than once per row.
$brandWearers = [];
foreach ($brands as $_b) { $brandWearers[$_b->id()] = $displayStore->usingBrand($_b->id()); }
unset($_b);

$styles = $openBrand ? $brandStyles->all($openBrand->id()) : [];
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

// The same for the palette: a stored slot this app cannot read is named rather than
// quietly dropped from the swatch row (#21).
$paletteBad = $openBrand ? $openBrand->unreadablePalette() : [];

// The library rows a Brand's logo can point at. Images only — a text snippet is not
// a logo, and offering one would produce a Brand whose logo block renders a price.
$logoChoices = [];
foreach ((new AssetLibrary($pdo))->all() as $_asset) {
    if (($_asset['type'] ?? '') === 'image') { $logoChoices[] = $_asset; }
}
unset($_asset);
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
        /* ── The Workspace Theme's thirteen roles (v2 step 5) ──
           One validated echo; `var(--…)` below. **This page wears fewer of them than the
           Builder does, and that is deliberate.** It is a light document — white cards on
           #f0f2f5 — and only its nav bar and its buttons are chrome in the sense the roles
           name. `--work-area` is the dark space behind a canvas; mapping this page's paper
           onto it would turn the Admin Panel black, which is not what "a theme applies to
           every signed-in page" meant. So the roles reach every page, and how much of a
           page they paint depends on how much of that page is chrome. */
        :root {
<?= SiteChrome::styleVariables() ?>
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { background: #f0f2f5; min-height: 100vh; }

        /* --- Nav ---
           These were literals until step 5, so this page's nav was the one place in the
           app that ignored Site Branding: a shop that set BRAND_NAV_BG got it on the
           Builder, the Help page and the sign-in page, and a stock #1a252f here. Reaching
           the roles fixes that as a side effect, which means a shop with customised nav
           colours will see this bar change to match the rest of the app. */
        nav { background: var(--nav-bg); padding: 0 20px; display: flex; align-items: center; gap: 20px; height: 52px; }
        nav .brand { color: var(--nav-text); font-weight: bold; font-size: 15px; margin-right: auto; }
        nav a { color: #bdc3c7; text-decoration: none; font-size: 13px; padding: 6px 10px; border-radius: 4px; }
        nav a:hover, nav a.active { background: var(--work-area); color: #fff; }
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
        .btn-blue   { background: var(--accent); color: #fff; }
        .btn-blue:hover   { background: #2980b9; }
        .btn-green  { background: var(--status-good); color: #fff; }
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
                    <?php
                    // Through the door rather than reading the stamp here. `created_at`
                    // is a TIMESTAMP the database fills in, so it is the one moment on
                    // this page PHP never wrote and could not convert; db_connect.php
                    // asks the connection for UTC so it arrives in the same frame as
                    // everything else, and this reads it in that frame (#44).
                    //
                    // No `!empty()` guard: `label()` answers '' for a stamp that is null,
                    // empty *or* unreadable, and all three mean the same thing to a person
                    // reading this table. The guard covered only the first two, so a stamp
                    // that would not parse printed a blank cell where the em dash belongs.
                    // The `??` stays, because an *absent key* is a different thing again —
                    // a database where the column never landed — and reading one warns.
                    //
                    // A PHP comment and not an HTML one on purpose: check_invariants.php
                    // drops PHP comments before it greps, and an HTML comment naming the
                    // call this line no longer makes would fail invariant 28 against the
                    // very sentence explaining why it holds.
                    ?>
                    <td><?= Markup::text(StoreClock::label($u['created_at'] ?? '', 'M j, Y') ?: '—') ?></td>
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
                    <td><?= Markup::text(StoreClock::label($c['closed_at'] ?? '', 'M j, Y') ?: '—') ?></td>
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
                <?php /* Which brand this sign wears, from the list read once above rather
                        than a query per card. Named "brand missing" rather than left blank
                        when the id points nowhere: on a converged database it cannot happen
                        (brand_id is NOT NULL and foreign-keyed), and a silent gap would be
                        the one state worth noticing rendering as the ordinary one. */ ?>
                <?php $dBrandName = '';
                      foreach ($brands as $_db) {
                          if ($_db->id() === $d->brandId()) { $dBrandName = $_db->name(); }
                      }
                      unset($_db); ?>
                <strong><?= Markup::text($dBrandName !== '' ? $dBrandName : 'brand missing') ?></strong> brand ·
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
                            <label>Brand</label>
                            <select name="d_brand" style="width:170px;">
                                <?php foreach ($brands as $b): ?>
                                    <option value="<?= intval($b->id()) ?>"
                                        <?= $d->brandId() === $b->id() ? 'selected' : '' ?>>
                                        <?= Markup::text($b->name()) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                    <div class="form-group">
                        <?php /* Required, and deliberately not defaulted to the first brand:
                                on a property with a restaurant, a bar and a casino floor
                                there is no obvious answer, and the wrong one repaints the
                                sign within thirty seconds of it being created. */ ?>
                        <label>Brand</label>
                        <select name="d_brand" style="width:180px;" required>
                            <option value="">Choose a brand…</option>
                            <?php foreach ($brands as $b): ?>
                                <option value="<?= intval($b->id()) ?>"><?= Markup::text($b->name()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="hint" style="font-size:12px;">
                    The tag is the display's address: <code>viewer.php?display=<span id="tag-echo">lobby-screen</span></code>.
                    Lowercase letters, numbers and hyphens. Leave it blank and it is taken from the title.
                    The brand is where this display's typography, palette and logo come from —
                    it can be changed later, and the change reaches the screen within 30 seconds.
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
        <h2>Brands</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:16px;">
            A brand is one venue's look: its typography, its palette, its logo and the canvas
            background its screens start from. Several displays can wear one brand, so a
            restaurant with three boards has one red, edited once. Every display wears exactly
            one.
        </p>
        <table class="bs-table" style="margin-bottom:16px;">
            <thead>
                <tr><th>Brand</th><th>Displays wearing it</th><th>Palette</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($brands as $b):
                $wearers = $brandWearers[$b->id()] ?? [];
                $isOpen  = $openBrand && $openBrand->id() === $b->id();
            ?>
                <tr<?= $isOpen ? ' style="background:#f4f8fb;"' : '' ?>>
                    <td><strong><?= Markup::text($b->name()) ?></strong></td>
                    <td style="font-size:13px; color:#555;">
                        <?php if ($wearers): ?>
                            <?php $names = [];
                                  foreach ($wearers as $w) { $names[] = $w->title(); } ?>
                            <?= Markup::text(implode(', ', $names)) ?>
                        <?php else: ?>
                            <span style="color:#7f8c8d;">nothing yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php /* Through palette(), which answers what will actually render.
                                A slot holding something that is not a colour is named in the
                                notice below rather than drawn as a swatch nobody chose. The
                                inline colour is a validated `#rrggbb`, which is the one shape
                                allowed into a style attribute without escaping — escaping
                                stops a value ending the attribute, not the declaration. */ ?>
                        <?php foreach ($b->palette() as $swatch): ?>
                            <span style="display:inline-block; width:18px; height:18px; border-radius:3px;
                                         border:1px solid #ccc; vertical-align:middle;
                                         background:<?= Markup::text($swatch) ?>;"></span>
                        <?php endforeach; ?>
                        <?php if (!$b->palette()): ?>
                            <span style="font-size:12px; color:#7f8c8d;">none set</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!$isOpen): ?>
                            <a href="admin_panel.php?tab=brand&amp;brand=<?= intval($b->id()) ?>"
                               style="font-size:13px;">Open</a>
                        <?php else: ?>
                            <span style="font-size:13px; color:#7f8c8d;">open below</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <form method="POST" style="display:flex; gap:8px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <input type="text" name="b_name" placeholder="New brand name — e.g. Salmon House"
                   maxlength="<?= intval(BrandStore::NAME_MAX) ?>" required style="max-width:320px;">
            <button type="submit" name="action_create_brand" class="btn btn-green">Add Brand</button>
        </form>
    </div>

<?php if ($openBrand): ?>
    <div class="card">
        <h2><?= Markup::text($openBrand->name()) ?> — palette, logo and background</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:16px;">
            The palette is <em>offered</em> wherever a colour is picked for a display wearing this
            brand — never enforced, so a block with its own colour keeps it. Leave a slot empty to
            drop it from the row.
        </p>
        <?php if ($paletteBad): ?>
            <div style="border-left:4px solid #e67e22; background:#fff8f0; border-radius:4px;
                        padding:10px 14px; margin-bottom:16px;">
                <strong style="color:#e67e22; font-size:13px;">Stored palette colours that cannot be used</strong>
                <ul style="font-size:13px; color:#555; margin:6px 0 0 18px;">
                    <?php foreach ($paletteBad as $bad): ?>
                        <li><?= Markup::text($bad['label']) ?> is stored as
                            <?= Markup::text(Color::describe($bad['value'])) ?>, so it is not
                            offered as a swatch at all.</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <input type="hidden" name="b_id" value="<?= intval($openBrand->id()) ?>">
            <label style="display:block; font-size:13px; font-weight:600; margin-bottom:4px;">Name</label>
            <input type="text" name="b_name" value="<?= Markup::text($openBrand->name()) ?>"
                   maxlength="<?= intval(BrandStore::NAME_MAX) ?>" required style="max-width:320px;">

            <label style="display:block; font-size:13px; font-weight:600; margin:14px 0 4px;">Palette</label>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <?php foreach (BrandStore::paletteFields() as $i => $field):
                    // The *stored* value, not the rendered one: a slot holding something
                    // unreadable is reported above and left in the box as it is, so saving
                    // the form does not quietly store a substitute over it (#21).
                    $slot = $openBrand->paletteSlot($i);
                ?>
                    <?php /* Picking a colour clears the "empty" tick, because otherwise the
                            two controls contradict each other and the tick wins silently —
                            somebody chooses a colour for an empty slot, saves, and the slot
                            is still empty with nothing saying why. The `<input type="color">`
                            has to keep a value even when the slot is empty (a browser gives
                            it black regardless), which is the whole reason the tick exists:
                            "empty" cannot be said by leaving the box alone. */ ?>
                    <span style="display:inline-flex; align-items:center; gap:4px;">
                        <input type="color" name="b_<?= Markup::text($field) ?>"
                               value="<?= Markup::text(Color::read($slot) !== '' ? Color::read($slot) : '#ffffff') ?>"
                               oninput="paletteSlotChosen(this)">
                        <label style="font-size:12px; color:#7f8c8d;">
                            <input type="checkbox" name="b_<?= Markup::text($field) ?>_unset" value="1"
                                   <?= $slot === '' ? 'checked' : '' ?>> empty
                        </label>
                    </span>
                <?php endforeach; ?>
            </div>

            <label style="display:block; font-size:13px; font-weight:600; margin:14px 0 4px;">
                Default canvas background
            </label>
            <input type="color" name="b_bg"
                   value="<?= Markup::text(Color::read($openBrand->backgroundValue()) !== ''
                                           ? Color::read($openBrand->backgroundValue())
                                           : Background::DEFAULT_COLOR) ?>">

            <label style="display:block; font-size:13px; font-weight:600; margin:14px 0 4px;">
                Venue logo (Asset Library)
            </label>
            <select name="b_logo">
                <option value="">— none —</option>
                <?php foreach ($logoChoices as $asset): ?>
                    <option value="<?= intval($asset['id']) ?>"
                        <?= $openBrand->logoAssetId() === intval($asset['id']) ? 'selected' : '' ?>>
                        <?= Markup::text($asset['label'] !== '' ? $asset['label'] : $asset['content']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">
                The builder can place this in one click. Screens never draw it by themselves —
                a fixed corner cannot be right for both a landscape menu board and a portrait
                specials board.
            </p>

            <div style="margin-top:16px;">
                <button type="submit" name="action_save_brand" class="btn btn-green">Save Brand</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h2>Brand Standards for <?= Markup::text($openBrand->name()) ?> — Locked Text Styles</h2>
        <p style="font-size:13px; color:#7f8c8d; margin-bottom:16px;">
            These styles apply to the six branded text blocks, on every display wearing
            <strong><?= Markup::text($openBrand->name()) ?></strong>. Basic users cannot change
            them. Changes reach those screens within 30 seconds — no publishing needed, because
            a screen reads this typography on each poll.
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
                <input type="hidden" name="b_id" value="<?= intval($openBrand->id()) ?>">
                <button type="submit" name="action_save_styles" class="btn btn-green">
                    Save Brand Standards
                </button>
            </div>
        </form>
    </div>

    <div class="card" style="border-left:4px solid #e74c3c;">
        <h2 style="color:#c0392b;">Delete <?= Markup::text($openBrand->name()) ?></h2>
        <?php $openWearers = $brandWearers[$openBrand->id()] ?? []; ?>
        <?php if ($openWearers): ?>
            <p style="font-size:13px; color:#555;">
                <?= count($openWearers) === 1 ? 'One display wears' : count($openWearers) . ' displays wear' ?>
                this brand, so it cannot be deleted:
                <?php $names = [];
                      foreach ($openWearers as $w) { $names[] = $w->title(); } ?>
                <strong><?= Markup::text(implode(', ', $names)) ?></strong>.
                Move <?= count($openWearers) === 1 ? 'it' : 'them' ?> to another brand first —
                reassigning <?= count($openWearers) === 1 ? 'it' : 'them' ?> automatically would
                repaint <?= count($openWearers) === 1 ? 'that screen' : 'those screens' ?> within
                30 seconds, and there is no undo.
            </p>
        <?php else: ?>
            <p style="font-size:13px; color:#555; margin-bottom:10px;">
                Nothing wears this brand. Deleting it removes its six sets of typography as
                well, and cannot be undone. Type <strong><?= Markup::text($openBrand->name()) ?></strong>
                to confirm.
            </p>
            <form method="POST" style="display:flex; gap:8px; align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                <input type="hidden" name="b_id" value="<?= intval($openBrand->id()) ?>">
                <input type="text" name="b_confirm_name" placeholder="Type the brand name"
                       required style="max-width:320px;">
                <button type="submit" name="action_delete_brand" class="btn btn-red">Delete Brand</button>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
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
                <!-- Said "PNG, JPG, SVG" and SVG is deliberately refused above — an SVG
                     can carry a script tag and would be stored XSS from our own origin.
                     A label offering a type the code blocks is a refusal somebody was
                     invited into. (Spelled out in words rather than as the tag: the
                     standing gate extracts this page's script block by scanning for it,
                     and a browser ignoring a tag inside a comment is not a reason to
                     leave one where a tool will trip.) -->
                <label>Upload Logo (PNG, JPG, GIF, WEBP)</label>
                <input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp"
                       data-max-bytes="<?= intval(UploadLimit::logoBytes()) ?>"
                       data-max-label="<?= Markup::text(UploadLimit::describeLogo()) ?>"
                       onchange="previewBrandLogo(this)">
                <div id="brand-logo-note" style="display:none; font-size:11px; margin-top:4px; line-height:1.5;"></div>
            </div>
            <!-- The figure comes from UploadLimit, so on a host that cannot take 2 MB
                 this says what that host will take instead of promising 2. -->
            <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">Max <?= Markup::text(UploadLimit::describeLogo()) ?>. Leave blank to keep existing logo.</p>
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

    <!-- ============================================================ -->
    <!-- WORKSPACE THEMES (v2 step 5)                                  -->
    <!-- ============================================================ -->
    <!-- Site Branding is the *store default* — the colours everybody sees who has not
         chosen otherwise. A Workspace Theme is one person's alternative to it. They are
         on the same tab because that is the question a person has when they arrive here
         ("what colour is this application?"), and they are separated by a heading and a
         sentence because the answer differs: one reaches every account, the other
         reaches whoever picks it. Neither reaches a sign — that is the Brand tab. -->
    <div class="card" style="margin-top:26px;">
        <h2>Workspace Themes</h2>
        <p style="font-size:13px; color:#555; line-height:1.6; margin-bottom:14px;">
            The colours above are the <strong>store default</strong> — what everybody's screens
            are painted in. A theme is an alternative anybody can choose for themselves, from
            the gear menu in the Builder. Nothing here reaches a display: a sign's colours and
            typography belong to its Brand, on the Display Branding tab.
        </p>

        <?php if (!$themes): ?>
            <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
                There are no themes yet, so everybody is on the store default.
            </p>
        <?php else: ?>
            <table style="margin-bottom:16px;">
                <thead><tr><th>Theme</th><th>Colours</th><th>In use by</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($themes as $t): ?>
                    <?php $tBad = $t->unreadable(); ?>
                    <tr>
                        <td style="font-weight:600;"><?= Markup::text($t->name()) ?></td>
                        <td>
                            <?php foreach (array_keys(SiteChrome::ROLES) as $tRole): ?>
                                <!-- Drawn through SiteChrome::pick(), so a swatch shows the colour
                                     the page will actually take rather than the one in the row. A
                                     stored value nobody can read is named below instead of being
                                     shown as black (#21). -->
                                <span title="<?= Markup::text(SiteChrome::ROLES[$tRole][0]) ?>"
                                      style="display:inline-block; width:13px; height:16px; border-radius:2px;
                                             border:1px solid #ccc; margin-right:2px;
                                             background:<?= SiteChrome::pick($tRole, $t->colorFor($tRole)) ?>"></span>
                            <?php endforeach; ?>
                            <?php if ($tBad): ?>
                                <div style="font-size:11px; color:#c0392b; margin-top:4px;">
                                    Stored but unreadable, so the default is drawn instead:
                                    <?php foreach ($tBad as $i => $bad): ?><?= $i ? ', ' : '' ?><?= Markup::text($bad['label']) ?>
                                        (<?= Markup::text(Color::describe($bad['value'])) ?>)<?php endforeach; ?>.
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px; color:#555;">
                            <?= $themeWearers[$t->id()]
                                    ? Markup::text(implode(', ', $themeWearers[$t->id()]))
                                    : 'nobody' ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-blue" style="text-decoration:none;"
                               href="admin_panel.php?tab=branding&amp;theme=<?= intval($t->id()) ?>">Edit</a>
                            <?php if (!$themeWearers[$t->id()]): ?>
                                <!-- Offered only when it would be allowed, and refused again in the
                                     handler: a POST does not have to come from a form this page drew
                                     (invariant 8), and somebody may choose this theme between the
                                     page being drawn and the button being pressed. -->
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirmDeleteTheme(<?= Markup::jsInAttr($t->name()) ?>)">
                                    <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
                                    <input type="hidden" name="t_id" value="<?= intval($t->id()) ?>">
                                    <button type="submit" name="action_delete_theme" class="btn btn-red">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- One form for both jobs, because they are the same thirteen fields and one of
             them is a whole-row save. Which it is comes from whether `t_id` is there,
             which is also what the two submit buttons below say. -->
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <?php if ($openTheme): ?>
                <input type="hidden" name="t_id" value="<?= intval($openTheme->id()) ?>">
            <?php endif; ?>

            <h2 style="font-size:14px; border:none; margin:6px 0 10px;">
                <?= $openTheme ? 'Editing ' . Markup::text($openTheme->name()) : 'Add a theme' ?>
                <?php if ($openTheme): ?>
                    <a href="admin_panel.php?tab=branding" style="font-size:12px; font-weight:normal;
                       margin-left:10px;">start a new one instead</a>
                <?php endif; ?>
            </h2>

            <div class="form-group" style="max-width:400px; margin-bottom:12px;">
                <label>Theme name</label>
                <input type="text" name="t_name" maxlength="<?= intval(PickerName::MAX) ?>"
                       value="<?= Markup::text($openTheme ? $openTheme->name() : '') ?>"
                       placeholder="Night shift" style="width:100%;" required>
            </div>

            <?php
            // Grouped as ROLES declares them, so the form's shape comes from the same
            // list the table, the resolution and the check all read. A hand-written
            // grouping here would be the fourth copy of "what the roles are".
            $tGroups = ['chrome'  => ['Application chrome', 'The nav bar, the work area and the panels.'],
                        'status'  => ['Status colours', 'The banners: saved, warning, problem, somebody else is here, advisory note.'],
                        'overlay' => ['On the canvas', 'The selection outline and the resize handles — the only thing a theme paints over a canvas. Everything else drawn there belongs to the display\'s Brand.']];
            foreach ($tGroups as $tGroup => $tAbout):
            ?>
            <div style="margin-bottom:14px;">
                <div style="font-size:12px; font-weight:600; color:#555;"><?= Markup::text($tAbout[0]) ?></div>
                <div style="font-size:11px; color:#7f8c8d; margin-bottom:6px;"><?= Markup::text($tAbout[1]) ?></div>
                <div style="display:flex; flex-wrap:wrap; gap:14px;">
                    <?php foreach (SiteChrome::ROLES as $tRole => $tMeta): ?>
                        <?php if ($tMeta[1] !== $tGroup) { continue; } ?>
                        <label style="font-size:11px; color:#555; font-weight:600;
                                      display:flex; flex-direction:column; gap:3px;">
                            <?= Markup::text($tMeta[0]) ?>
                            <input type="color" name="t_<?= Markup::text($tRole) ?>"
                                   data-role="<?= Markup::text($tRole) ?>"
                                   value="<?= SiteChrome::pick($tRole, $openTheme ? $openTheme->colorFor($tRole) : null) ?>"
                                   oninput="themeFormPreview()">
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; unset($tGroup, $tAbout, $tRole, $tMeta); ?>

            <!-- The preview, and the contrast warning that does not block the save
                 (decision 13: an admin owns their own legibility policy). It is a strip
                 rather than a mock of the whole Builder, because a mock is a second
                 renderer to keep in step with the real one — and the real one is one
                 click away in the gear menu. -->
            <div id="theme-preview" style="border-radius:6px; padding:0; overflow:hidden;
                                          border:1px solid #ccc; max-width:520px;">
                <div id="tpv-nav" style="padding:9px 12px; display:flex; align-items:center; gap:12px;">
                    <span id="tpv-name" style="font-weight:bold; font-size:13px;"><?= Markup::text($curSite) ?></span>
                    <span id="tpv-btn" style="color:#fff; padding:3px 10px; border-radius:3px; font-size:11px;">Publish</span>
                </div>
                <div id="tpv-body" style="padding:12px; display:flex; gap:10px; align-items:stretch;">
                    <div id="tpv-panel" style="width:96px; border-radius:4px; padding:8px; font-size:11px;
                                               color:#dfe6ec;">Palette</div>
                    <div id="tpv-canvas" style="flex:1; background:#fff; border-radius:3px; min-height:54px;
                                                position:relative;">
                        <span id="tpv-sel" style="position:absolute; left:10px; top:10px; width:70px; height:30px;
                                                  background:#eee;"></span>
                    </div>
                </div>
                <div id="tpv-bar" style="padding:6px 12px; font-size:11px; color:#fff;">A banner looks like this</div>
            </div>
            <p style="font-size:11px; color:#7f8c8d; margin-top:6px; max-width:520px;">
                The white rectangle is a display's canvas. Its colours are the Brand's, never a
                theme's — only the selection outline around a block is drawn from here.
            </p>
            <div id="theme-contrast" style="display:none; font-size:12px; margin-top:8px; padding:7px 10px;
                                            border-radius:4px; background:#fff8e1; border:1px solid #e6c86a;
                                            color:#7a5b00; max-width:520px;"></div>

            <div style="margin-top:14px;">
                <button type="submit" name="<?= $openTheme ? 'action_save_theme' : 'action_create_theme' ?>"
                        class="btn btn-green"><?= $openTheme ? 'Save theme' : 'Add theme' ?></button>
            </div>
        </form>
    </div>
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
            <h2>Store Time Zone</h2>
            <?php if ($zoneBad !== ''): ?>
                <!-- Same door and the same one-way street as $brandBad on the Branding
                     tab: nothing this form can submit reaches here, because it offers a
                     list. It arrives from branding_config.php being generated and
                     documented as hand-editable. Saying which value could not be used
                     is the whole of #21 — a clock in the wrong zone shows an ordinary
                     time, so unlike a wrong colour there is nothing about it to
                     notice. -->
                <p style="font-size:13px; color:#c0392b; font-weight:600; margin-bottom:10px;">
                    branding_config.php holds <?= Markup::text('"' . $zoneBad . '"') ?>, which is not a
                    time zone this server knows — a fixed offset or an abbreviation will not do,
                    because neither says when daylight saving starts. Every time on every page is
                    being shown in <?= Markup::text(StoreClock::DEFAULT_ZONE) ?> instead. Saving this
                    form replaces it with whatever is picked below.
                </p>
            <?php endif; ?>
            <p style="font-size:13px; color:#7f8c8d; margin-bottom:14px;">
                Which zone the times on these screens are written in — "editing since 2:15pm" on a
                sign somebody else has open, when an account was created, when a Display was last
                published. Nothing a customer sees is affected, and nothing stored moves: every
                moment is recorded in UTC and converted for whoever is reading it.
            </p>
            <div class="form-group" style="max-width:340px;">
                <label>Time zone</label>
                <select name="store_timezone" style="width:100%;">
                    <?php
                    // Grouped by the part before the slash, which is what makes 419
                    // options usable. The names are PHP's own list — a name and not an
                    // offset, deliberately (lib/store_clock.php).
                    $zoneGroups = [];
                    foreach (StoreClock::zones() as $_z) {
                        $_slash = strpos($_z, '/');
                        $_group = $_slash === false ? 'Other' : substr($_z, 0, $_slash);
                        $zoneGroups[$_group][] = $_z;
                    }
                    foreach ($zoneGroups as $_group => $_zones): ?>
                        <optgroup label="<?= Markup::text($_group) ?>">
                        <?php foreach ($_zones as $_z): ?>
                            <option value="<?= Markup::text($_z) ?>"<?= $_z === $curZone ? ' selected' : '' ?>><?= Markup::text($_z) ?></option>
                        <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <p style="font-size:12px; color:#7f8c8d; margin-top:6px;">
                Showing <strong><?= Markup::text(StoreClock::labelForEpoch(time(), 'D j M Y, g:ia')) ?></strong>
                in <?= Markup::text($curZone) ?>. This Server below reports the server's own clock
                and the database's, which no longer have to agree with this one.
            </p>
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

    // The sentence lives here rather than in the attribute, which is the rule and not a
    // preference: an HTML parser decodes an attribute before the JavaScript parser reads
    // it, so a name spliced into a quoted string there is escaped for the wrong parser
    // and the string ends at the first apostrophe. `Markup::jsInAttr()` is passed as the
    // whole argument; the words are here.
    function confirmDeleteTheme(name) {
        return confirm('Delete the ' + name + ' theme?\n\n' +
            'Nobody is using it, so nobody\'s screens change. It cannot be undone — ' +
            'a theme with the same name later is a new one.');
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

    // ============================================================
    // WORKSPACE THEME FORM — the preview, and the warning that does not refuse
    // ============================================================
    // The threshold and the sentence are the server's; only the arithmetic is here, and
    // that is the one rule in this app written in two languages. It has to be: a warning
    // that appears while somebody drags a colour picker cannot ask the server on every
    // frame. What keeps the two from drifting is that neither is checked against the
    // other — both are checked against WCAG's own fixed points, black on white at 21 and
    // a colour on itself at 1, in `selftest_layout.php` and in
    // `selftest_builder_theme.js`. A formula from a standard is a safer thing to write
    // twice than a decision would be, and the decision — 4.5, and the words — is
    // declared once and printed here.
    var THEME_READABLE_RATIO = <?= HttpReply::jsValue(Color::READABLE_RATIO) ?>;

    /** WCAG relative luminance for `#rrggbb`, or null when that is not what it is. */
    function themeLuminance(hex) {
        if (!/^#[0-9a-fA-F]{6}$/.test(String(hex))) { return null; }
        var parts = [1, 3, 5].map(function (at) {
            var c = parseInt(hex.substr(at, 2), 16) / 255;
            return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2];
    }

    /** How far apart two colours are, 1 to 21, or null if either is not a colour. */
    function themeContrast(one, two) {
        var a = themeLuminance(one), b = themeLuminance(two);
        if (a === null || b === null) { return null; }
        return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    }

    /** Every role the form is showing: role name => the colour picked for it. */
    function themeFormColors() {
        var out = {};
        var inputs = document.querySelectorAll('[data-role]');
        for (var i = 0; i < inputs.length; i++) {
            out[inputs[i].getAttribute('data-role')] = inputs[i].value;
        }
        return out;
    }

    /**
     * Draw the strip, and say when the nav would be hard to read.
     *
     * The canvas rectangle in the preview is deliberately painted white and left alone:
     * decision 11 says the canvas belongs to the display's Brand, and a preview that
     * themed it would be teaching the admin the opposite of what the app does. Only the
     * selection outline around the block inside it comes from the theme.
     */
    function themeFormPreview() {
        var c = themeFormColors();
        if (!c.nav_bg) { return; }          // not on this tab, or the form is not drawn
        var set = function (id, prop, value) {
            var node = document.getElementById(id);
            if (node) { node.style[prop] = value; }
        };
        set('tpv-nav', 'background', c.nav_bg);
        set('tpv-nav', 'borderBottom', '2px solid ' + c.nav_border);
        set('tpv-name', 'color', c.nav_text);
        set('tpv-btn', 'background', c.accent);
        set('tpv-body', 'background', c.work_area);
        set('tpv-panel', 'background', c.panel);
        set('tpv-panel', 'border', '1px solid ' + c.panel_border);
        set('tpv-bar', 'background', c.status_busy);
        set('tpv-sel', 'outline', '2px solid ' + c.selection);

        var box   = document.getElementById('theme-contrast');
        var ratio = themeContrast(c.nav_text, c.nav_bg);
        if (!box) { return; }
        if (ratio !== null && ratio < THEME_READABLE_RATIO) {
            box.textContent = 'Nav text on Nav background is hard to read (' +
                              ratio.toFixed(1) + ':1, where ' + THEME_READABLE_RATIO +
                              ':1 is the readable minimum). You can save it anyway.';
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }
    }

    /**
     * Preview the picked logo — or say why there is nothing to preview.
     *
     * It used to do neither: readAsDataURL succeeds on any file at all, so a renamed
     * .txt produced a preview element pointing at data it could not draw, and a file
     * over the limit produced a preview of a logo the save was about to refuse. The
     * only signal either way was whether a picture appeared, which is not a sentence.
     *
     * The server still decides — it reads the real bytes with mime_content_type() and
     * SVG is refused there whatever a browser calls it. This is the half that answers
     * before the save, and before the upload on a slow connection.
     */
    var LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    function sayAboutLogo(text, bad) {
        var note = document.getElementById('brand-logo-note');
        if (!note) { return; }
        note.textContent   = text;
        note.style.color   = bad ? '#c0392b' : '#7f8c8d';
        note.style.display = text ? 'block' : 'none';
    }

    function previewBrandLogo(input) {
        var wrap = document.getElementById('brand-logo-preview');
        var img  = document.getElementById('brand-logo-img');
        var f    = input.files && input.files[0];
        if (!f) { sayAboutLogo('', false); return; }

        // Cleared on every refusal, so picking the same file again is an event the
        // browser reports rather than one it swallows as "no change".
        function refuse(text) {
            sayAboutLogo(text, true);
            input.value = '';
            if (wrap) { wrap.style.display = 'none'; }
        }
        if (LOGO_TYPES.indexOf(f.type) < 0) {
            refuse('Wrong file type (' + (f.type || 'type not recognised') + '). Use a PNG, JPG, '
                 + 'GIF or WEBP — this file was not selected. SVG is not accepted.');
            return;
        }
        if (f.size === 0) { refuse('That file is empty, so it was not selected.'); return; }

        var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
        if (max > 0 && f.size > max) {
            refuse('File too big — ' + Math.max(1, Math.floor(f.size / 1024)) + ' KB. The logo must be '
                 + (input.getAttribute('data-max-label') || 'smaller')
                 + ' or smaller. This file was not selected.');
            return;
        }

        var reader = new FileReader();
        reader.onload = function(e) {
            if (img)  { img.src = e.target.result; }
            if (wrap) { wrap.style.display = 'block'; }
            sayAboutLogo(f.name + ' — saves when you press Save Branding.', false);
        };
        reader.onerror = function() {
            refuse('That file could not be read, so it was not selected. If it is on a drive '
                 + 'or a share, copy it to this computer first.');
        };
        reader.readAsDataURL(f);
    }

    function toggleEdit(id) {
        var row = document.getElementById('edit-' + id);
        row.classList.toggle('open');
    }

    // Choosing a palette colour means the slot is not empty. Without this the tick
    // box and the swatch disagree, and the tick wins on the server — a colour picked
    // for an empty slot would be discarded with nothing saying so.
    function paletteSlotChosen(input) {
        var group = input.parentNode;
        if (!group) { return; }
        var box = group.querySelector('input[type=checkbox]');
        if (box) { box.checked = false; }
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

// Draw the theme strip once, from the values the form was rendered with. The
// alternative was thirteen more colour echoes in the markup to set its initial state —
// this asks the pickers that already hold them, so the preview cannot show a colour the
// form is not about to submit. Harmless on every other tab: `themeFormPreview()` returns
// at once when the form is not on the page.
themeFormPreview();
</script>
</body>
</html>
