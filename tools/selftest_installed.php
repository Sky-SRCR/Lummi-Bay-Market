<?php
// ============================================================
// THE SUITE, RUN AS A REAL INSTALL ON A REAL SERVER
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
// **Several passes rather than one**, because a `define()` cannot be undone, an ini
// setting cannot be changed from inside the process it governs, and the interesting
// configurations disagree with each other. They fall on two axes, because an install is
// two things — what the shop chose, and what the machine underneath it was set to:
//
//   ── what the shop chose (`branding_config.php`) ──
//   branded    every colour changed, a logo, a site name, a zone eleven hours away and
//              an undo depth that is not five. What a shop looks like after setup.
//   live-like  the zone the live host was observed on (§4ap, CLAUDE.md), and nothing
//              else — because most installs have edited one setting, not ten.
//   damaged    a colour in the config that this app cannot read. Not a normal install:
//              it is what `tools/audit_colors.php` exists to report, and a suite that
//              fails on it is a suite that fails on the customer's data rather than on
//              the code, which is the sort of red line people learn to skip.
//
//   ── what the machine was set to (php.ini) — §4bi ──
//   generous   a host that will carry a video: 64M/128M, so the app's own 50 MB ceiling
//              is the binding one and the upload note falls silent. Plus a process zone
//              that is not UTC, which is the clock `viewer.php` runs on.
//   tight      1M/1M, so that note is produced a second time with different numbers in
//              it rather than once with the only pair this container can make.
//   errors on  `display_errors=1`, the one row of the error-policy readout that says
//              something is wrong — unreachable here, because this container ships the
//              flag off and the suite deliberately never calls ErrorPolicy::install().
//
// Four readouts in this app describe the host, and until §4bi not one of them had a
// seam between its sentence and the `ini_get` that chose it. So each had exactly one
// form on any machine that ran the suite and the other form on none of them, and one —
// `ErrorPolicy::status()`, the whole Settings-tab readout of what happens when
// something breaks — had no check of any kind. The arms below are half of that fix; the
// seams in `lib/` are the other half, and they are the half that reaches the cases no
// `-d` flag can produce at all (an unset `date.timezone` is one).
//
// Not a replacement for the plain run and not a second suite: it is the same file, six
// more times, with the environments the app actually ships into. Add it to the gate list
// in CLAUDE.md next to the plain one — it takes about as long as six plain runs, which
// is the whole of its cost.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Nothing here may be a default. A value shared with `BrandingConfig::DEFAULTS` would
// make this arm agree with the plain one, which is the entire failure being closed —
// so the list is checked against that class rather than trusted to stay different.
require_once __DIR__ . '/../lib/branding.php';

// Two axes, because an install is two things. `settings` is what the shop chose and
// `branding_config.php` holds; `host` is what the *machine* was set to and nobody in
// the shop has ever seen. The second axis is §4bi: four readouts in this app describe
// the host, and until that pass none of them had a seam between the sentence and the
// `ini_get` that chose it — so each had exactly one form on any machine that ran the
// suite, and the other form on none of them.
$arms = [
    'a branded install' => ['settings' => [
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
    ]],
    'an install like the live one' => ['settings' => [
        'STORE_TIMEZONE'   => 'America/Chicago',
    ]],
    'an install with a colour it cannot read' => ['settings' => [
        'BRAND_NAV_BG'     => 'puce',
        'STORE_TIMEZONE'   => 'UTC',
    ]],

    // A host that will carry a video for a sign. The app's own 50 MB ceiling becomes
    // the binding one, so `ServerReport`'s upload note falls silent — the branch this
    // container could not produce, since it caps uploads at 2M.
    'a generous host' => ['host' => [
        'upload_max_filesize' => '64M',
        'post_max_size'       => '128M',
        // Not the store's zone — the *process* default, which is a third clock and the
        // one `viewer.php` runs on, since it loads neither config.php nor auth.php.
        'date.timezone'       => 'America/Chicago',
    ]],

    // And one tighter than this container, so the note is produced twice with different
    // numbers in it rather than once with the only pair a passing run ever showed.
    'a tight host' => ['host' => [
        'upload_max_filesize' => '1M',
        'post_max_size'       => '1M',
    ]],

    // The one row of the error-policy readout that says something is wrong. The app
    // turns this off on every request, so an admin seeing "On" is seeing an override —
    // and the sentence beside it was unreachable here, because this container ships
    // the flag off and the suite deliberately never calls ErrorPolicy::install().
    'a host that shows errors' => ['host' => [
        'display_errors'      => '1',
    ]],
];

// The one thing this file asserts itself, before it runs anything: an arm that is
// accidentally the configuration this machine is already in proves nothing and would
// say so in green. Both axes, for the same reason and with the same consequence.
foreach ($arms as $name => $arm) {
    foreach (($arm['settings'] ?? []) as $const => $value) {
        if (!isset(BrandingConfig::DEFAULTS[$const])) {
            fwrite(STDERR, "  $const is not a setting branding_config.php holds.\n");
            exit(1);
        }
        if (BrandingConfig::DEFAULTS[$const] === $value) {
            fwrite(STDERR, "  the '$name' arm sets $const to its own default, so it "
                         . "tests nothing the plain run does not.\n");
            exit(1);
        }
    }
    foreach (($arm['host'] ?? []) as $setting => $value) {
        $here = ini_get($setting);
        if ($here === false) {
            fwrite(STDERR, "  $setting is not a php.ini setting this PHP knows.\n");
            exit(1);
        }
        if ((string)$here === (string)$value) {
            fwrite(STDERR, "  the '$name' arm sets $setting to what this machine already "
                         . "has ($here), so it tests nothing the plain run does not.\n");
            exit(1);
        }
    }
}

$suite  = __DIR__ . '/selftest_layout.php';
$failed = [];

// Anchored, for the reason `selftest_layout.php` anchors its check count and §4bh found
// four node suites without one: an arm deleted from the list above is a configuration
// nobody is testing any more, and the run would still print a clean line and exit 0. Two
// axes, so the count is written as its two halves — deleting the whole host axis is the
// mistake this most needs to catch, and `6` alone would not say which three went missing.
$expectedArms = 3 + 3;   // what the shop chose, and what the machine was set to
if (count($arms) !== $expectedArms) {
    fwrite(STDERR, '  expected ' . $expectedArms . ' arms, found ' . count($arms)
                 . ". An arm removed is a configuration nothing is checking.\n");
    exit(1);
}

foreach ($arms as $name => $arm) {
    $head = '';
    foreach (($arm['settings'] ?? []) as $const => $value) {
        $head .= 'define(' . var_export($const, true) . ', ' . var_export($value, true) . ');';
    }
    // The host axis has to be on the command line rather than in the code: these are
    // the settings a running process cannot change. `upload_max_filesize` and
    // `post_max_size` are PHP_INI_PERDIR, and `date.timezone` is rejected at startup
    // if it is empty — which is why the notes that read them needed a seam as well as
    // an arm, and why the seam is the half that reaches the unset case at all.
    $flags = '';
    foreach (($arm['host'] ?? []) as $setting => $value) {
        $flags .= ' -d ' . escapeshellarg($setting . '=' . $value);
    }
    echo "\n─── as $name ───\n";
    // A subprocess per arm, for the reason `inFreshProcess()` exists: the constants are
    // the state under test and PHP has no way to put one back.
    $out = [];
    $status = 0;
    exec(escapeshellcmd(PHP_BINARY) . $flags
         . ' -r ' . escapeshellarg($head . ' require ' . var_export($suite, true) . ';')
         . ' 2>&1', $out, $status);
    // Only what failed, and the summary. The `ok` lines and the section headings are the
    // same ones the plain run just printed, and six more copies of them is a wall
    // nobody reads a failure out of.
    $summaries = 0;
    foreach ($out as $line) {
        // Anchored, because a check's own *sentence* can contain either word — one of
        // them reads "the hash is spent before the state checks, so a suspended refusal
        // costs what a wrong password costs", which a substring match prints in full.
        if (preg_match('/^\d+ checks, \d+ failed$/', $line)) { $summaries++; }
        if (preg_match('/^\s*FAIL/', $line) || preg_match('/^\d+ checks, \d+ failed$/', $line)) {
            echo $line . "\n";
        }
    }
    // A run that printed no summary at all is the one shape a zero exit status cannot be
    // trusted about: this filter is looking for two lines in someone else's output, and
    // "found neither" and "found nothing wrong" print identically. It has already been
    // wrong once — the first version of this filter matched " checks, " as a substring
    // and printed a check's own sentence as though it were a summary.
    if ($summaries !== 1) {
        echo "  no summary line: this arm did not run the suite to the end\n";
        $failed[] = $name;
        continue;
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
