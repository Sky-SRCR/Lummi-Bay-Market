<?php
// ============================================================
// BRANDING CONFIG — the one file this app rewrites while it is running
// ============================================================
// `branding_config.php` is generated PHP: ten `define()` calls holding the logo path,
// four nav colours, the site name, the two mail-from fields, the Builder's undo depth
// and the store's time zone. `config.php`
// requires it and `auth.php` requires `config.php`, so it is loaded at the top of
// every page in the app — which makes it the only file here whose *contents* are
// edited by the running application and whose *syntax* the whole application
// depends on. (`login.php`, `builder.php` and `help.php` used to load it a second
// time each, with their own copies of the defaults; see `apply()`.)
//
// The Admin Panel used to write it with `file_put_contents($path, $php)`. That call
// opens the live file with O_TRUNC: for the length of the write, the file every page
// requires is first empty, then partial, then complete. Two ways that ends badly,
// and neither is rare enough to ignore on a shared host:
//
//   · **A reader arrives mid-write.** Somebody loads any page — including
//     `login.php` — and requires a file that stops in the middle of
//     `define('BRAND_ACCENT`. That is a parse error, which is a fatal, which is a
//     blank page. Not on the Admin Panel: on *every* page, for everybody, for as
//     long as the write takes.
//   · **The write does not finish.** A full disk, a quota, the host's process
//     reaper mid-request. `file_put_contents` returns the number of bytes it
//     managed and the old code compared that to `false`, so a half-written file was
//     reported as "Branding saved." while the site stayed dark with nothing on
//     screen saying why. The error message it did have — "Could not write
//     branding_config.php. Check file permissions." — could only ever be printed by
//     a request that had already truncated the file it was talking about.
//
// Decision #36: write a temporary file, then swap it in. `rename()` within one
// directory is atomic on POSIX — a reader gets the whole old file or the whole new
// one, never a mixture and never nothing. So the sequence is:
//
//     render → parse it → write a temp file → read that file back and compare
//            every byte → match the live file's permissions → rename over it
//
// The read-back is what catches the short write, because the bytes on disk are what
// a reader will get and the count a syscall returned is only a claim about them —
// believing that claim is what the old code did. The parse is the guarantee stated
// plainly: **this module does not write a file that does not compile**, so a later
// change to `render()` that emits bad PHP is a refused save rather than a dark
// site. Nothing writes the live path except the final `rename`, which is why every
// failure here can say — and does say — that the site is still running on exactly
// what it had.
//
// The temp file is `.branding_config.<random>.tmp`, in the same directory because
// `rename()` is only atomic within one filesystem. It deliberately does not contain
// `.php`: Apache's `AddHandler application/x-httpd-php .php` matches that extension
// *anywhere* in a filename, so `branding_config.php.1234.tmp` is executed by a
// common configuration. The leading dot keeps it out of a directory listing, and
// the root `.htaccess` denies the name outright for the millisecond it exists.
//
// `opcache_invalidate()` afterwards because the swap changes the inode. With
// `opcache.validate_timestamps=0` — an ordinary production setting — the old file
// stays compiled in memory, and the admin would be told the branding saved while
// nothing on the site changed.
//
// Windows would not get the atomicity: `rename()` over an existing file is
// unreliable there. The live server is LAMP (HANDOFF.md).
//
// This module depends on nothing — no database, no session, no config — because the
// page that reads it is also the page that has to keep working when the file it
// manages is missing.

/**
 * What happened to a save, as a value rather than a boolean.
 *
 * Every kind other than OK means the file on disk was not touched at all, and the
 * message says so in those words: an admin who has just been told a save failed
 * needs to know whether the site is still standing before anything else.
 */
class BrandingWrite
{
    const OK      = 'ok';
    const REFUSED = 'refused';   // asked to store something that is not a setting
    const FAILED  = 'failed';    // could not write; the live file is untouched

    private $kind;
    private $message;

    private function __construct($kind, $message)
    {
        $this->kind    = $kind;
        $this->message = $message;
    }

    public static function ok($message)      { return new self(self::OK, $message); }
    public static function refused($message) { return new self(self::REFUSED, $message); }
    public static function failed($message)  { return new self(self::FAILED, $message); }

    public function isOk()    { return $this->kind === self::OK; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }
}

/**
 * The only writer of `branding_config.php`.
 *
 * A caller says what it is changing and nothing else. The ten settings and their
 * defaults live here, so the two forms in the Admin Panel — branding, and site and
 * email — no longer hand each other five values back that neither of them edited.
 */
class BrandingConfig
{
    const FILE = 'branding_config.php';

    /**
     * Every setting the generated file holds, and what the app uses when it does
     * not hold one. The same names and the same fallbacks used to be spelled
     * out again in `login.php`, `builder.php`, `help.php` and
     * `config.php` — four copies of one list, which is four things to change and
     * three chances to forget. `apply()` is what those four became. A name absent
     * from here cannot be stored, and does not exist as far as this app knows.
     */
    const DEFAULTS = [
        'BRAND_LOGO'       => '',
        'BRAND_NAV_BG'     => '#1a252f',
        'BRAND_NAV_BORDER' => '#0d1b24',
        'BRAND_ACCENT'     => '#3498db',
        'BRAND_TEXT'       => '#ffffff',
        'SITE_NAME'        => 'Store Display System',
        'MAIL_FROM'        => 'noreply@yourdomain.com',
        'MAIL_FROM_NAME'   => 'Display System',
        // The ninth, and the first that is not about how the app looks or who mail
        // comes from: how many steps the Builder's Undo goes back (ADR-0010). It
        // lives here because this is the only writer of the generated file, and a
        // `define()` of it anywhere else would be dropped the next time an admin
        // saved the Branding form — the value gone, the form reporting success.
        // Stored as a string like everything else; `undoStepsSetting()` in
        // `config.php` is what turns it into a number the Builder can act on, and
        // clamps it, so a hand-edit to 500 or to "five" still lands somewhere sane.
        'UNDO_STEPS'       => '5',
        // The tenth, and here for the same reason the ninth is: it is a setting the
        // Settings page writes, so it belongs in the file this module owns rather
        // than in a `define()` somewhere that the next Branding save would drop.
        //
        // Which zone a person in the shop reads a time in (#44). Nothing set one, so
        // every "editing since 2:15pm" followed whatever `date.timezone` the host
        // held — nothing, on the live host, which is UTC. `StoreClock` in
        // `lib/store_clock.php` is what turns this string into a zone, validates it,
        // and says so when it cannot; the default there is this same value, so a
        // config that has never held the name behaves identically to one that does.
        'STORE_TIMEZONE'   => 'America/Los_Angeles',
    ];

    private $dir;

    /** @param string $dir Directory holding the file. Defaults to the app root. */
    public function __construct($dir = null)
    {
        $dir = ($dir === null) ? dirname(__DIR__) : (string)$dir;
        $this->dir = rtrim($dir, '/\\');
    }

    public function path()
    {
        return $this->dir . '/' . self::FILE;
    }

    /**
     * Load the generated file, if it is there and nothing has loaded it already.
     *
     * The guard is on a constant rather than on a flag of our own so that a file
     * something else has already loaded — a `db_credentials.php` that defines its
     * own branding, a page in some future arrangement — is not loaded twice. Since
     * `render()` emits `defined(…) || define(…)`, loading it twice would be harmless
     * anyway; the guard is what makes it free as well.
     */
    public function load()
    {
        if (!defined('BRAND_LOGO') && @is_file($this->path())) {
            require_once $this->path();
        }
    }

    /**
     * Load the file and make sure every one of the names exists.
     *
     * This is the read side, and it is one call because it used to be seven lines
     * repeated in four files — `config.php`, `login.php`, `builder.php` and
     * `help.php` — each spelling out the same defaults, and two of them guarding the
     * `require` on a different constant from the other two. Templates use the
     * constants directly (`<?= htmlspecialchars(BRAND_ACCENT) ?>` sits inside the
     * CSS), so what a page needs is the names defined, not an array returned.
     *
     * Defining constants is a global side effect, which nothing else in `lib/` has.
     * It is the point of this file rather than an exception to the rule: the names
     * are the interface every page already reads, and the alternative is four copies
     * of the list that can disagree about what colour the nav bar is.
     *
     * Never overrides. A constant already defined — by `db_credentials.php`, which
     * `config.php` documents as the way to override any of these — is left alone.
     */
    public function apply()
    {
        $this->load();
        foreach (self::DEFAULTS as $name => $default) {
            if (!defined($name)) { define($name, $default); }
        }
    }

    /**
     * The settings in force right now: the constants where they are defined, the
     * defaults where they are not. This is what a save is applied on top of, so a
     * form that edits three of them cannot silently rewrite the other five.
     */
    public function current()
    {
        $out = [];
        foreach (self::DEFAULTS as $name => $default) {
            $out[$name] = defined($name) ? (string)constant($name) : $default;
        }
        return $out;
    }

    /**
     * Change some settings and leave the rest exactly as they are.
     *
     * @param array $changes  name => value, for names in DEFAULTS only.
     * @return BrandingWrite
     */
    public function save(array $changes)
    {
        $values = $this->current();
        foreach ($changes as $name => $value) {
            if (!array_key_exists($name, $values)) {
                // Not a typo to work around: a name nothing reads would be written
                // to a file every page loads and would never have any effect, and
                // the admin would be told it saved.
                return BrandingWrite::refused(
                    '"' . $name . '" is not one of the settings this file holds,'
                    . ' so nothing was saved. Nothing was changed.');
            }
            $values[$name] = (string)$value;
        }
        return $this->swapIn(self::render($values));
    }

    /**
     * The generated source. Deterministic, and in DEFAULTS order, so two saves of
     * the same values produce identical bytes.
     *
     * Every value goes through `var_export`, which is the whole of the escaping:
     * it emits a single-quoted literal with `\` and `'` escaped, so no value —
     * including a site name somebody pastes a quote into — can close the string and
     * add a statement. The self-test counts the `define` calls in the result for
     * exactly that reason.
     *
     * `defined(…) || define(…)` rather than a bare `define()`. `config.php` offers
     * `db_credentials.php` as the place to override any of these, and a bare
     * `define()` of a name that is already taken raises a warning and then keeps the
     * first value — so the documented override worked while complaining about
     * itself. The guard makes it silent, and makes this file safe to load after
     * anything else that has an opinion about the same names.
     */
    public static function render(array $values)
    {
        $php = "<?php\n"
             . "// ============================================================\n"
             . "// Store Branding Configuration\n"
             . "// Generated by the Admin Panel — Branding, and Site & Email.\n"
             . "// Edit by hand only if the Admin Panel cannot be reached; the\n"
             . "// next save from either form overwrites the whole file.\n"
             . "// ============================================================\n";
        foreach (self::DEFAULTS as $name => $default) {
            $value  = array_key_exists($name, $values) ? (string)$values[$name] : $default;
            $quoted = var_export($name, true);
            // Padded into columns because this file is hand-editable by design —
            // it is what somebody reaches for when the Admin Panel cannot be.
            $php   .= str_pad('defined(' . $quoted . ')', 28) . ' || define('
                    . str_pad($quoted . ',', 20) . ' ' . var_export($value, true) . ");\n";
        }
        return $php;
    }

    /**
     * Put these bytes at the live path, or leave the live path alone.
     *
     * Read the header of this file for why each step is here. The short version is
     * that the only operation touching the live path is the `rename`, and it is not
     * reached until the replacement has been parsed, written to a file of its own,
     * read back and compared.
     */
    private function swapIn($php)
    {
        $path = $this->path();

        // First, before anything exists on disk. Nothing a caller can pass gets
        // here — `var_export` sees to that, and the self-test counts the `define`
        // calls in the result to prove it — so no test fails when these four lines
        // are deleted on their own. What fails is the test that a *broken*
        // `render()` cannot take the site down: with this guard, emitting bad PHP
        // is a refused save; without it, the file every page requires stops
        // parsing. Both were run. That is the whole reason it is here.
        if (!self::parses($php)) {
            return BrandingWrite::failed(
                'The new settings file would not have loaded, so it was not written.'
                . ' Nothing was changed; the site is still using the settings it'
                . ' had. This is a fault in the application, not in what you'
                . ' entered — please report it.');
        }

        // No `is_writable()` ahead of this. It answers about the moment before the
        // write rather than the write, it is wrong for root and for ACLs, and the
        // attempt below already gives the true answer with nothing at stake — the
        // temporary file is not the live one. A guard that can only agree with what
        // happens next is a second opinion waiting to disagree.
        $temp = $this->tempPath();

        if ($this->putTemp($temp, $php) === false) {
            @unlink($temp);
            return BrandingWrite::failed(
                'The new settings file could not be written. Nothing was changed;'
                . ' the site is still using the settings it had. Check the folder'
                . ' permissions.');
        }

        // The one check that matters, and the reason there is no second one beside
        // it counting bytes: this compares what a *reader* would get against what
        // was meant, so it covers the short write, the write that claimed every
        // byte and stored fewer, and anything else the filesystem did. A count
        // returned by a syscall is a claim about the same thing, and a claim is
        // what the old code believed.
        @clearstatcache(true, $temp);
        if (@file_get_contents($temp) !== $php) {
            @unlink($temp);
            return BrandingWrite::failed(
                'The new settings file did not write completely — the disk may be'
                . ' full. Nothing was changed; the site is still using the settings'
                . ' it had.');
        }

        // Take the live file's own permissions across the swap. `rename` replaces
        // the file with the temporary one, so without this the mode is whatever the
        // umask gave a brand-new file, and a deployment that had tightened or
        // loosened this file on purpose would silently lose that on the next save.
        $mode = @fileperms($path);
        @chmod($temp, ($mode === false) ? 0644 : ($mode & 0777));

        if (!@rename($temp, $path)) {
            @unlink($temp);
            return BrandingWrite::failed(
                'The new settings file could not be put in place. Nothing was'
                . ' changed; the site is still using the settings it had. Check the'
                . ' folder permissions.');
        }

        @clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) {
            // Not conditional on opcache being enabled: the call is a no-op when it
            // is not, and asking `opcache_get_status()` first is a second thing to
            // get wrong. Suppressed because it warns when opcache is compiled in
            // but disabled for CLI, which is every run of the self-test.
            @opcache_invalidate($path, true);
        }

        return BrandingWrite::ok('Settings saved.');
    }

    /**
     * Does this source compile? `TOKEN_PARSE` makes `token_get_all` run the real
     * parser and throw on anything it cannot read, which is the same answer
     * `php -l` gives without needing a second process.
     */
    public static function parses($php)
    {
        try {
            token_get_all((string)$php, TOKEN_PARSE);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The bytes actually going to disk, as their own method so the self-test can
     * subclass it and hand back a short write — the failure this whole module
     * exists for, and one no test can otherwise reach.
     */
    protected function putTemp($temp, $php)
    {
        return @file_put_contents($temp, $php);
    }

    /** Same directory as the live file, and never named `*.php`. See the header. */
    private function tempPath()
    {
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $suffix = (string)getmypid() . dechex(mt_rand(0, 0xffffff));
        }
        return $this->dir . '/.branding_config.' . $suffix . '.tmp';
    }
}
