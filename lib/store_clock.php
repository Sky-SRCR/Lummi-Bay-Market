<?php
// ============================================================
// WHAT TIME IT IS IN THE SHOP
// ============================================================
// Decision #44. Nothing in this app ever set a timezone, so every time a person read
// came out of whatever `date.timezone` the host happened to hold. The store is in
// Washington; the host holds `America/Chicago` (observed on Settings → This Server,
// 2026-08-11 — see §4ap, and note that this repo asserted UTC here for a day before
// anybody looked). So "Dana has been editing Drive-Thru since 2:15pm" printed 4:15pm,
// and the only thing on any screen that hinted at it was one row saying the server's
// zone was Central.
//
// Two hours is worse to have shipped than seven would have been. Seven is obviously
// broken; two reads like a colleague who really did start at 4:15pm. That is the whole
// reason a clock in the wrong zone is a different kind of defect from a colour in the
// wrong hex, and it is why `unreadable()` below names a value it will not use instead
// of quietly picking one.
//
// It is worth being exact about how many clocks were involved, because "set a
// timezone" sounds like one line and was not:
//
//   1. **PHP's process zone.** `America/Chicago`, set by the host — not by anything in
//      this repo, whose `.htaccess` sets session flags and no `date.` value. Every
//      `date()` call that printed a moment for a person used it.
//   2. **MySQL's session zone.** `CURRENT_TIMESTAMP` and every `TIMESTAMP` column read
//      use it, and it is the host's system zone unless somebody says otherwise. Nobody
//      did — so the same machine's Central. `displays.last_published_at` was written
//      with it and `users.created_at` still is.
//   3. **The store's own.** What the person reading the screen is standing in.
//
// **Those first two being the same zone is what hid the second defect below.** One
// machine, one system zone, so the missing `' UTC'` cancelled the `CURRENT_TIMESTAMP`
// frame exactly and that sentence was wrong by the same two hours as every other one.
// Setting either clock on its own turns that into five. A one-line fix here is not a
// partial fix, it is a new bug.
//
// Storage was already settled and is not what this module changes: §4t and §4v made
// every moment PHP writes UTC — `gmdate()` in, `strtotime($s . ' UTC')` out — because
// local wall-clock is not monotonic and the autumn fall-back replays an hour. That
// work is what makes a *store* zone safe to introduce at all: nothing compares two
// moments in the zone this file decides, so a change to it cannot move a lock window
// or a lockout. The only thing it changes is a sentence.
//
// So there are two jobs here and they are different, which is why the interface has
// two halves:
//
//   · **A stored stamp is UTC.** `epochOf()` is the one place that knows it. That
//     rule was written out in three places and two of them had it right: the edit
//     lock and the login lockout appended `' UTC'`, and `lastPublishDescription()`
//     did not — a latent error in the one sentence a refused publish shows, waiting
//     for either clock above to move. Invariant 28 is that this file is the only
//     place `strtotime()` is called.
//   · **"Now" is store time too.** `apply()` sets the process default, so a bare
//     `date()` on a page agrees with `label()` instead of being two hours from it.
//     Nothing in the app relies on it today — every render goes through the door —
//     and that is the point: the cheap call and the correct call give the same
//     answer, so the next line somebody adds is right by default.
//
// **A fixed offset is not a timezone.** `+08:00` and `PST` both construct a perfectly
// valid `DateTimeZone`, and both are wrong for half the year — which is #44 again with
// a smaller error bar. So the accepted set is the identifiers PHP *lists*, which are
// region names and nothing else, and a name is the only thing that knows when daylight
// saving starts. `US/Pacific` and `EST` are casualties of that rule: they work in PHP
// and are refused here. They are also unreachable through the form, which offers a
// `<select>` of the listed identifiers, so the only door they can arrive through is a
// hand-edited `branding_config.php` — and `unreadable()` says so on the page rather
// than substituting in silence (#21).
//
// Depends on nothing but `BrandingConfig`, which owns the file the setting lives in.
// It does not open that file a second way, for the reason `lib/brand.php` gives: two
// readers is how the two of them come to disagree about what a missing value means.

require_once __DIR__ . '/branding.php';

class StoreClock
{
    /**
     * The zone when the config does not name one, or names something that is not a
     * zone.
     *
     * Not UTC, and not the host's. A default of either is "show every time in a zone
     * the store is not in", which is the defect restated as a policy. The store is in
     * Washington (CONTEXT.md), the suite has used `America/Los_Angeles` as "the store's
     * own zone" since §4t, and an installation that has never opened the Settings page
     * is far likelier to be this store than to be anywhere else.
     */
    const DEFAULT_ZONE = 'America/Los_Angeles';

    /** The constant the setting is stored in, and the label the form puts on it. */
    const SETTING = 'STORE_TIMEZONE';
    const LABEL   = 'Store time zone';

    /**
     * Make sure the generated branding file has been read, for a caller with no app
     * around it — the same service `Brand::load()` performs, and for the same
     * reason. Every page already has this through `config.php`.
     */
    public static function load()
    {
        (new BrandingConfig(dirname(__DIR__)))->load();
    }

    /**
     * Is this a zone this module will use?
     *
     * `listIdentifiers()` rather than `new DateTimeZone()`, and the difference is the
     * rule rather than an implementation detail: the constructor accepts `PST` and
     * `+08:00`, and a value with no daylight-saving rule in it is right for half the
     * year and silently wrong for the other half. Pure, so the shapes worth testing
     * — an offset, an abbreviation, a typo, a list — can all be put through it.
     *
     * **The strict flag is the whole rule, and it is stated once.** This began as
     * `if (!is_string($id) || $id === '') { return false; }` in front of the same
     * `in_array`, and #50's mutation run is what showed the guard could not be tested:
     * strict comparison already answers false for a list, for null, for a float and for
     * `''`, so removing the guard changed no answer for any shape, and the two lines
     * were a rule written twice. That is the failure this repo keeps finding — three
     * copies of "a stored moment is UTC" (§4ap), four of the branding defaults (§4y) —
     * and the tell is the same each time: neither statement can be tested while the
     * other stands. Four mutants lived in that guard; deleting it left one, on the
     * `true` flag, and that one is now pinned by `isZone(true)` — because
     * `in_array(true, …, false)` is **true**, every zone name being a truthy string.
     */
    public static function isZone($id)
    {
        return in_array($id, self::zones(), true);
    }

    /** Every zone this module will accept, in the order PHP lists them. */
    public static function zones()
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * The zone for a stored value, or the documented default when it is not one.
     *
     * Pure — the stored value is passed in rather than read — for the reason
     * `Brand::pick()` is (§4o): a `define()` cannot be undone, so a rule reachable
     * only through the constant could only ever be tested with the one value this
     * process happens to hold.
     */
    public static function pick($stored)
    {
        return self::isZone($stored) ? (string)$stored : self::DEFAULT_ZONE;
    }

    /** The zone in force right now. */
    public static function zone()
    {
        return self::pick(self::stored());
    }

    /**
     * The stored value this module could not use, or '' when there is nothing to
     * report.
     *
     * Absent is not unreadable: a config written before this setting existed simply
     * does not define it, and the default is the right answer with nothing for
     * anybody to go and fix. Same distinction `Brand::unreadable()` draws, and it is
     * the whole difference between a notice worth reading and one on every screen.
     *
     * @param mixed $stored the raw value, or null to read the constant
     */
    public static function unreadable($stored = null)
    {
        if ($stored === null) { $stored = self::stored(); }
        if ($stored === null) { return ''; }
        if (self::isZone($stored)) { return ''; }
        // A list or an object reaches a form field as no sentence at all, so it is
        // named by its type rather than cast — the mistake #27 was.
        return is_string($stored) ? $stored : ('a ' . gettype($stored));
    }

    /**
     * Point PHP's own clock at the store, so `date()` and `label()` agree.
     *
     * Called once from `config.php`, which `auth.php` requires at the top of every
     * page. Deliberately *not* what the rendering below depends on — `label()`
     * converts explicitly, so it is right in `viewer.php` (which loads neither), in
     * a CLI tool, and in a self-test that moves the process zone about to prove the
     * storage is absolute. This is the belt: it stops a bare `date()` added later
     * from being hours away from every other time on the same page.
     *
     * Returns the zone it set, so a caller that wants to say so can.
     */
    public static function apply()
    {
        $zone = self::zone();
        // Suppressed rather than checked: the identifier came from listIdentifiers()
        // by construction, so a warning here would mean PHP disagreeing with its own
        // list, and a diagnostic printed above the document is §4m.
        @date_default_timezone_set($zone);
        return $zone;
    }

    /**
     * A stored timestamp as an epoch second.
     *
     * **The one place in the repo that reads one** (invariant 28). Every stamp this
     * app writes is UTC and `strtotime()` reads a bare `Y-m-d H:i:s` in the process
     * zone, so it has to be told — and being told in one place is the point, because
     * the version of this line that forgot was indistinguishable from the two that
     * did not, right up until somebody compared two sentences on one page.
     *
     * 0 for anything unreadable, which errs the way the callers already err: an edit
     * lock with an epoch of 0 reads as long lapsed, so the sign frees rather than
     * sticking, and one heartbeat rewrites the stamp correctly.
     *
     * **A zero date is one of those, and it is the only unreadable stamp MySQL can
     * actually hold** (invariant 32). Strict mode refuses a string that is not a datetime, so
     * the garbage this function was written for cannot reach the column at all — but a
     * host running without strict mode, or a dump taken from one, leaves
     * `0000-00-00 00:00:00`, and `strtotime()` reads that as a real moment in year zero
     * rather than failing. Without this line the canvas footer answers "is what I'm
     * looking at live?" with a date in the year 0, which is the half-written sentence
     * that whole seam exists to prevent. Matched on the zero date itself rather than on
     * an epoch floor: a stamp genuinely older than 1970 is not something this app can
     * write, and a floor would be a second rule to be wrong about.
     */
    public static function epochOf($stamp)
    {
        if (!is_string($stamp) || $stamp === '') { return 0; }
        if (strpos($stamp, '0000-00-00') === 0) { return 0; }
        $epoch = strtotime($stamp . ' UTC');
        return $epoch === false ? 0 : $epoch;
    }

    /**
     * A stored timestamp as words, in the store's zone.
     *
     * '' when there is no stamp or it cannot be read — the callers all have a
     * sentence that works without the time in it ("sky, editing" rather than "sky,
     * editing since —"), and a placeholder in the middle of a refusal is worse than
     * a shorter refusal.
     *
     * Converted through `DateTimeZone` rather than by setting the process zone,
     * because a formatter that depends on a global is one a caller can be wrong
     * about without being able to see it.
     *
     * The zone is a **parameter** with the setting as its default, for §4o's reason
     * and not for a caller's convenience: a `define()` cannot be undone, so a
     * formatter that could only read the constant could only ever be tested against
     * the one zone this process happens to hold — and the property worth testing is
     * precisely that the sentence follows the *setting* rather than the server. Every
     * caller in the app takes the two-argument form, so none of them can hold an
     * opinion about which zone the store is in.
     *
     * @param string      $stamp  a stored 'Y-m-d H:i:s' in UTC
     * @param string      $format a `date()` format string, written out at the call site
     * @param string|null $zone   a zone to use instead of the setting
     */
    public static function label($stamp, $format, $zone = null)
    {
        $epoch = self::epochOf($stamp);
        if ($epoch === 0) { return ''; }
        return self::labelForEpoch($epoch, $format, $zone);
    }

    /** The same, for a moment already in hand — "now", for a report. */
    public static function labelForEpoch($epoch, $format, $zone = null)
    {
        try {
            $when = new DateTime('@' . intval($epoch));      // always UTC
            $when->setTimezone(new DateTimeZone($zone === null ? self::zone() : self::pick($zone)));
            return $when->format((string)$format);
        } catch (Throwable $e) {
            // Unreachable while zone() answers from listIdentifiers(). Here because
            // the alternative to a fallback is an uncaught throw on the page that
            // reports the problem — and this module's whole job is that a clock
            // nobody configured does not break a screen.
            return gmdate((string)$format, intval($epoch));
        }
    }

    /** What the config file defined, or null if it defined nothing. */
    private static function stored()
    {
        return defined(self::SETTING) ? constant(self::SETTING) : null;
    }
}
