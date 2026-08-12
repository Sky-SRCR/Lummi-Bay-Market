<?php
// ============================================================
// UPLOAD LIMITS — how big a file can actually get here
// ============================================================
// api.php refused anything over 50 MB and said so. That number was the app's
// opinion, and on shared hosting it is rarely the binding one: PHP has two
// separate ceilings of its own, and whichever is smallest decides.
//
//   upload_max_filesize   the largest single file PHP will accept
//   post_max_size         the largest request body PHP will read at all
//
// The second is the dangerous one, because exceeding it is not an error PHP
// reports. It abandons the body and carries on: $_POST is empty, $_FILES is
// empty, and the script runs as though the browser had sent a bare POST. In
// api.php that meant the CSRF token was missing, so a 40 MB video on a host with
// post_max_size = 8M was answered **"Security token mismatch. Please reload the
// page and try again."** Reloading changes nothing; the file is simply too big,
// and nothing on the screen ever said so.
//
// So one module answers three questions, and every caller asks it rather than
// carrying its own number:
//
//   bytes()      → the smallest of the three ceilings, in bytes
//   describe()   → that number as something a person reads ("8 MB")
//   bodyWasDropped() → is this request the silent post_max_size case?
//
// bytes() is also what the Builder sends to the browser, so a file too big to
// arrive is refused in the file picker instead of after two minutes of upload.
//
// Nothing is required here: this file must be includable from a page that has
// not started a session (see BUILD-REFERENCE.md §1).

class UploadLimit
{
    /** The app's own ceiling. Even a host that allows more does not need to. */
    const APP_MAX_BYTES = 52428800;   // 50 MB

    // Two sinks want a smaller ceiling than the media one, and both used to carry
    // their number inline — `10 * 1024 * 1024` in crud.php, `2 * 1024 * 1024` in
    // admin_panel.php. That is the thing the header of this file says not to do,
    // and it cost the two failures it always costs. Neither number was the
    // smallest ceiling on a host with a small `post_max_size`, so the Library
    // promised 10 MB on a server that drops the body at 8; and neither was
    // reachable from the page that drew the file picker, so the picker could not
    // refuse anything and the Library's limit was stated nowhere on the screen.
    //
    // A per-use ceiling is still a real decision — a price sign's logo has no
    // business being 40 MB — so these are not one number. They are read through
    // cappedAt() below, which is `min(the decision, what can actually arrive)`:
    // the only form of a stated limit that cannot turn out to be a lie.
    const IMAGE_MAX_BYTES = 10485760;   // 10 MB — one Asset Library image
    const LOGO_MAX_BYTES  =  2097152;   //  2 MB — the brand logo

    private static $bytes = null;

    /**
     * The largest file that can actually reach this server, in bytes.
     *
     * An ini value of 0 (or one that cannot be parsed) means "no limit imposed
     * here", which must not be read as "nothing may be uploaded" — it drops out
     * of the comparison instead. The app ceiling is always in it, so the answer
     * can never be unbounded.
     */
    public static function bytes()
    {
        if (self::$bytes !== null) { return self::$bytes; }
        self::$bytes = self::smallestOf([
            ini_get('upload_max_filesize'),
            ini_get('post_max_size'),
        ]);
        return self::$bytes;
    }

    /**
     * The same arithmetic, over ini values handed in rather than read.
     *
     * Separated because `upload_max_filesize` and `post_max_size` are PHP_INI_PERDIR
     * — they cannot be set at runtime, so the interesting cases (a host that states
     * no limit, one whose value is unparseable) are unreachable through bytes().
     * This is the seam the self-test uses; bytes() is the one line that supplies
     * the real settings.
     */
    public static function smallestOf(array $iniValues)
    {
        $limits = [self::APP_MAX_BYTES];
        foreach ($iniValues as $value) {
            $parsed = self::toBytes($value);
            if ($parsed > 0) { $limits[] = $parsed; }
        }
        return min($limits);
    }

    /**
     * The limit in words, for a message somebody has to act on.
     *
     * Rounded down, deliberately: a limit printed as "9 MB" that refuses an
     * 8.7 MB file is worse than a slightly pessimistic one, because the person
     * trims the file to what they were told and is refused again.
     */
    public static function describe()
    {
        return self::describeBytes(self::bytes());
    }

    /**
     * A sink's own ceiling, never larger than what can reach this server.
     *
     * The `min` is the whole point and it only goes one way: a sink may ask for
     * less than the transport allows, never more. Asking for more would print a
     * number in the file picker that the request cannot honour, which is the
     * `post_max_size` trap wearing a helpful label.
     */
    public static function cappedAt($appCeiling)
    {
        return min(intval($appCeiling), self::bytes());
    }

    /** The largest Asset Library image, in bytes, and in words. */
    public static function imageBytes()    { return self::cappedAt(self::IMAGE_MAX_BYTES); }
    public static function describeImage() { return self::describeBytes(self::imageBytes()); }

    /** The largest brand logo, in bytes, and in words. */
    public static function logoBytes()    { return self::cappedAt(self::LOGO_MAX_BYTES); }
    public static function describeLogo() { return self::describeBytes(self::logoBytes()); }

    /** Any byte count in the same words, for "that file was 63 MB". */
    public static function describeBytes($bytes)
    {
        $bytes = intval($bytes);
        if ($bytes >= 1048576) { return floor($bytes / 1048576) . ' MB'; }
        if ($bytes >= 1024)    { return floor($bytes / 1024) . ' KB'; }
        return $bytes . ' bytes';
    }

    /**
     * Did PHP throw this request body away for being too large?
     *
     * The symptom, and there is no other: a POST that announced a content length
     * arrives with no fields and no files. A genuinely empty POST sends no body,
     * so its content length is 0 and it is not confused with this.
     *
     * Checked before the CSRF gate, because the missing token is a *consequence*
     * of the dropped body and answering "security token mismatch" sends the user
     * to reload a page that was never the problem.
     */
    public static function bodyWasDropped(array $server, array $post, array $files)
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') { return false; }
        if (!empty($post) || !empty($files))              { return false; }
        return intval($server['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /** What to tell somebody whose upload never arrived. */
    public static function droppedBodyMessage()
    {
        return 'That file was too large to upload — this server accepts up to '
             . self::describe() . '. Nothing was changed.';
    }

    /**
     * Parse an ini size ("8M", "512K", "2G", "1048576") into bytes.
     *
     * Returns 0 for anything unparseable, which bytes() reads as "no limit
     * stated here" — the safe direction, since the app ceiling still applies and
     * the server would enforce its own regardless of what we guessed.
     */
    public static function toBytes($value)
    {
        $value = trim((string)$value);
        if ($value === '') { return 0; }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/i', $value, $m)) { return 0; }

        $number = floatval($m[1]);
        switch (strtoupper($m[2])) {
            case 'G': $number *= 1073741824; break;
            case 'M': $number *= 1048576;    break;
            case 'K': $number *= 1024;       break;
        }
        return intval($number);
    }

    /** For the self-test and the Settings readout: forget the cached answer. */
    public static function forget()
    {
        self::$bytes = null;
    }
}
