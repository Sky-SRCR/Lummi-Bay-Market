<?php
// ============================================================
// DISPLAY REQUEST RESOLUTION
// ============================================================
// "Which Display does this HTTP request mean, and may this account have it?"
//
// One place answers that for every endpoint and every page. That matters twice
// over: it is where ADR-0003's notice wording lives (so a Screen shows the same
// sentence whoever asks), and it is where Phase 4 will check grants — putting
// the grant check here means every endpoint is covered by construction, instead
// of each one remembering its own `if`.
//
// Two intents, because they answer differently:
//   forViewing — a Screen rendering a sign. A deactivated Display is a notice.
//   forEditing — the Builder or admin panel. A deactivated Display is editable
//                by design (CONTEXT.md: "Still editable by admins").
//
// The URL contract is the screen name tag, in the `display` parameter, and
// nothing else. One way in keeps the Viewer URL an admin types on a device
// identical to the one the app uses everywhere.

require_once __DIR__ . '/displays.php';

class DisplayResolution
{
    const FOUND    = 'found';
    const NO_TAG   = 'no_tag';     // nothing named a Display
    const UNKNOWN  = 'unknown';    // named one that does not exist
    const INACTIVE = 'inactive';   // named a deactivated Display

    private $kind;
    private $display;
    private $message;

    private function __construct($kind, $display, $message)
    {
        $this->kind    = $kind;
        $this->display = $display;
        $this->message = $message;
    }

    public static function found(Display $display)
    {
        return new self(self::FOUND, $display, '');
    }

    /**
     * A failed resolution carries the notice to show on the Screen. The wording
     * differs per case on purpose (ADR-0003): someone standing in front of a
     * blank sign must be able to tell a mistyped URL from a retirement.
     */
    public static function noTag()
    {
        return new self(self::NO_TAG, null, 'No display specified');
    }

    public static function unknown()
    {
        return new self(self::UNKNOWN, null, 'Display not found');
    }

    public static function inactive(Display $display)
    {
        return new self(self::INACTIVE, $display, 'This display is turned off');
    }

    public function isFound() { return $this->kind === self::FOUND; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }

    /** The Display, or null. Present for INACTIVE too — the admin panel still shows it. */
    public function display() { return $this->display; }
}

class DisplayRequest
{
    /** The one request parameter that names a Display. */
    const PARAM = 'display';

    /**
     * Resolve for rendering a sign. Public: no session, no account.
     *
     * Strict since Phase 2: a Viewer URL names its Display or renders a notice
     * (ADR-0003). There is no fallback here — the Screens send their tag.
     *
     * @param array $params usually $_GET
     */
    public static function forViewing(DisplayStore $store, array $params)
    {
        $resolution = self::locate($store, $params, false);
        if (!$resolution->isFound()) { return $resolution; }
        if (!$resolution->display()->isActive()) {
            return DisplayResolution::inactive($resolution->display());
        }
        return $resolution;
    }

    /**
     * Resolve for editing — the Builder, the publish endpoint, the admin panel.
     * A deactivated Display resolves normally here: retiring a sign preserves its
     * layout and keeps it editable.
     *
     * @param array $actor currentUser() — the account asking. Unused in Phase 1;
     *                     Phase 4 checks its grants here, once, for every caller.
     */
    public static function forEditing(DisplayStore $store, array $params, array $actor)
    {
        return self::locate($store, $params, true);
    }

    /**
     * Tag → Display.
     *
     * @param bool $allowSoleFallback PHASE-1 TRANSITIONAL, editing paths only.
     */
    private static function locate(DisplayStore $store, array $params, $allowSoleFallback)
    {
        $raw = isset($params[self::PARAM]) ? (string)$params[self::PARAM] : '';

        if (trim($raw) === '') {
            if (!$allowSoleFallback) {
                // Viewing is strict (ADR-0003): the Screen shows a notice rather
                // than guessing which sign was meant.
                return DisplayResolution::noTag();
            }

            // PHASE-1 TRANSITIONAL — no tag resolves to the sole Display.
            //
            // Editing paths only, now that the Viewer sends its tag. The Builder
            // and admin panel have no picker until Phase 3, so a request that
            // names nothing gets the one Display that exists.
            //
            // `sole()` returns null the moment a second Display is created, so
            // this can never route a write to the wrong sign — it fails instead.
            // Remove with the Phase 3 picker (BUILD-REFERENCE.md §3).
            $only = $store->sole();
            return $only ? DisplayResolution::found($only) : DisplayResolution::noTag();
        }

        $display = $store->forTag($raw);
        return $display ? DisplayResolution::found($display) : DisplayResolution::unknown();
    }
}
