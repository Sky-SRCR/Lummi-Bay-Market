<?php
// ============================================================
// THE SUITE, RUN AS AN INSTALL THAT HAS BEEN SET UP
// ============================================================
//   php tools/selftest_installed.php
//
// `selftest_layout.php` has always run in a checkout with no `branding_config.php` in
// it — this container, and CI, and nowhere else. That file is generated, it is
// deliberately outside the repo (`docs/DEPLOY-SKIP.md`), and every install that has
// ever opened the Branding page or the Settings tab has one. So the suite has been
// asserting the app's behaviour in the one configuration no shop is running.
//
// It cost seven checks to find out, and finding out took ten seconds: defining the ten
// names before loading the suite. Two of those seven asserted that the shop's navigation
// is the colour the app ships with, and five that the clock is in the zone the app ships
// with. All seven passed here and all seven would have failed on the live install — on
// the very run somebody would make to convince themselves a deployment had gone well.
//
// They are fixed. This exists so the *next* one is a failing check rather than another
// afternoon: the constants are what a set-up install holds, and a check written against
// a default it happens to share with this machine fails here now, immediately, in the
// arm that is about that difference.
//
// **Three passes rather than one**, because a `define()` cannot be undone and the
// interesting configurations disagree with each other:
//
//   branded    every colour changed, a logo, a site name, a zone eleven hours away and
//              an undo depth that is not five. What a shop looks like after setup.
//   live-like  the zone the live host was observed on (§4ap, CLAUDE.md), and nothing
//              else — because most installs have edited one setting, not ten.
//   damaged    a colour in the config that this app cannot read. Not a normal install:
//              it is what `tools/audit_colors.php` exists to report, and a suite that
//              fails on it is a suite that fails on the customer's data rather than on
//              the code, which is the sort of red line people learn to skip.
//
// Not a replacement for the plain run and not a second suite: it is the same file, three
// more times, with the environment the app actually ships into. Add it to the gate list
// in CLAUDE.md next to the plain one — it takes about as long as three plain runs, which
// is the whole of its cost.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Nothing here may be a default. A value shared with `BrandingConfig::DEFAULTS` would
// make this arm agree with the plain one, which is the entire failure being closed —
// so the list is checked against that class rather than trusted to stay different.
require_once __DIR__ . '/../lib/branding.php';

$arms = [
    'branded' => [
        'BRAND_LOGO'       => 'uploads/a-real-logo.png',
        'BRAND_NAV_BG'     => '#8b0000',
        'BRAND_NAV_BORDER' => '#4b0000',
        'BRAND_ACCENT'     => '#00aa88',
        'BRAND_TEXT'       => '#fffff0',
        'SITE_NAME'        => 'Lummi Bay Market Signs',
        'MAIL_FROM'        => 'signs@example.invalid',
        'MAIL_FROM_NAME'   => 'Lummi Bay Market',
        'UNDO_STEPS'       => '9',
        'STORE_TIMEZONE'   => 'Pacific/Auckland',
    ],
    'live-like' => [
        'STORE_TIMEZONE'   => 'America/Chicago',
    ],
    'damaged' => [
        'BRAND_NAV_BG'     => 'puce',
        'STORE_TIMEZONE'   => 'UTC',
    ],
];

// The one thing this file asserts itself, before it runs anything: an arm that is
// accidentally the default configuration proves nothing and would say so in green.
foreach ($arms as $name => $defines) {
    foreach ($defines as $const => $value) {
        if (!isset(BrandingConfig::DEFAULTS[$const])) {
            fwrite(STDERR, "  $const is not a setting branding_config.php holds.\n");
            exit(1);
        }
        if (BrandingConfig::DEFAULTS[$const] === $value) {
            fwrite(STDERR, "  the $name arm sets $const to its own default, so it "
                         . "tests nothing the plain run does not.\n");
            exit(1);
        }
    }
}

$suite  = __DIR__ . '/selftest_layout.php';
$failed = [];

foreach ($arms as $name => $defines) {
    $head = '';
    foreach ($defines as $const => $value) {
        $head .= 'define(' . var_export($const, true) . ', ' . var_export($value, true) . ');';
    }
    echo "\n─── as a $name install ───\n";
    // A subprocess per arm, for the reason `inFreshProcess()` exists: the constants are
    // the state under test and PHP has no way to put one back.
    $out = [];
    $status = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' -r ' . escapeshellarg($head . ' require ' . var_export($suite, true) . ';')
         . ' 2>&1', $out, $status);
    // Only what failed, and the summary. The `ok` lines and the section headings are the
    // same ones the plain run just printed, and three more copies of them is a wall
    // nobody reads a failure out of.
    foreach ($out as $line) {
        // Anchored, because a check's own *sentence* can contain either word — one of
        // them reads "the hash is spent before the state checks, so a suspended refusal
        // costs what a wrong password costs", which a substring match prints in full.
        if (preg_match('/^\s*FAIL/', $line) || preg_match('/^\d+ checks, \d+ failed$/', $line)) {
            echo $line . "\n";
        }
    }
    if ($status !== 0) { $failed[] = $name; }
}

echo "\n";
if ($failed) {
    echo 'FAILED as: ' . implode(', ', $failed) . "\n";
    echo "A check that passes on a bare checkout and fails here is asserting this\n"
       . "machine's configuration rather than the app's behaviour. Say what it means\n"
       . "instead of what this checkout happens to hold — the store's own colour,\n"
       . "the store's own zone — rather than pinning the arm to the default.\n";
    exit(1);
}
echo "The suite holds in every configuration an install can be in.\n";
