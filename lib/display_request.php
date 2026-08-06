<?php
// ============================================================
// DISPLAY REQUEST RESOLUTION
// ============================================================
// "Which Display does this HTTP request mean, and may this account have it?"
//
// One place answers that for every endpoint and every page. That matters twice
// over: it is where ADR-0003's notice wording lives (so a Screen shows the same
// sentence whoever asks), and it is where grants are checked — one check here
// covers every endpoint by construction, instead of each one remembering its own
// `if`. A publish forged to name someone else's sign is refused by the same code
// that decides what the picker offers.
//
// Two intents, because they answer differently:
//   forViewing — a Screen rendering a sign. No account, no grants: a Viewer is
//                public, and a deactivated Display is a notice.
//   forEditing — the Builder or the API. Takes the Actor asking, so the answer
//                depends on which Displays that account holds (ADR-0005) and on
//                its role (a retired Display stays editable by admins).
//
// The URL contract is the screen name tag, in the `display` parameter, and
// nothing else. One way in keeps the Viewer URL an admin types on a device
// identical to the one the app uses everywhere.
//
// The tag is the address, not the identity: an admin may rename it (CONTEXT.md),
// and a renamed tag is free to be given to a different sign afterwards. So a page
// that has been open for a while holds an address that may since have come to mean
// a different Display. Any caller that knows which record it loaded may say so in
// `display_id`, and a disagreement between the two is refused rather than resolved
// — see DisplayRequest::confirmIdentity.

require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/grants.php';

class DisplayResolution
{
    const FOUND     = 'found';
    const NO_TAG    = 'no_tag';      // nothing named a Display
    const UNKNOWN   = 'unknown';     // named one that does not exist
    const INACTIVE  = 'inactive';    // named a deactivated Display
    const FORBIDDEN = 'forbidden';   // named one this account has not been granted
    const MISMATCH  = 'mismatch';    // tag and id name different Displays

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

    /**
     * A real Display that is not this account's to edit.
     *
     * The message says the sign exists rather than pretending it does not. Hiding
     * it would send a clerk hunting for a typo; naming it sends them to an admin,
     * which is the outcome that fixes the problem. The list a `basic` account is
     * offered still contains only their own Displays (ADR-0005) — this is the
     * message for someone who typed or bookmarked an address instead.
     */
    public static function forbidden(Display $display)
    {
        return new self(self::FORBIDDEN, $display,
            'That display has not been assigned to you. An admin can give you access to it.');
    }

    /**
     * The tag resolved, but to a different Display than the caller meant.
     *
     * Carries the Display the *tag* names — the one that was about to be written —
     * because that is what a refusal has to be able to name. The message tells the
     * truth about the only two ways to get here: the tag moved under an open page,
     * or the request was assembled by hand.
     */
    public static function mismatch(Display $display)
    {
        return new self(self::MISMATCH, $display,
            'That screen name tag no longer belongs to the display this page was opened on, '
            . 'so nothing was saved. Reload the page and try again.');
    }

    public function isFound() { return $this->kind === self::FOUND; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }

    /**
     * The Display, or null. Present for INACTIVE and FORBIDDEN too — the admin
     * panel still shows a retired Display, and a refusal can name the sign.
     */
    public function display() { return $this->display; }
}

class DisplayRequest
{
    /** The one request parameter that names a Display. */
    const PARAM = 'display';

    /**
     * What the caller believes that tag currently names. Optional, and checked
     * only when sent — a Viewer URL on a TV carries the tag and nothing else
     * (ADR-0003), and so does a Builder tab left open across a deploy.
     */
    const ID_PARAM = 'display_id';

    /**
     * Resolve for rendering a sign. Public: no session, no account, no grants.
     *
     * Strict since Phase 2: a Viewer URL names its Display or renders a notice
     * (ADR-0003). There is no fallback here — the Screens send their tag.
     *
     * @param array $params usually $_GET
     */
    public static function forViewing(DisplayStore $store, array $params)
    {
        $resolution = self::locate($store, $params, null);
        if (!$resolution->isFound()) { return $resolution; }
        if (!$resolution->display()->isActive()) {
            return DisplayResolution::inactive($resolution->display());
        }
        return $resolution;
    }

    /**
     * Resolve for editing — the Builder, the publish endpoint, the Work Area.
     *
     * Both axes of authority are applied here and nowhere else (ADR-0005): the
     * grant decides *which* Displays are this account's, and the role decides how
     * much power it has inside them — including whether a Display that is out of
     * service is still workable, which is an admin's job (CONTEXT.md).
     *
     * The decision is one call to `Actor::mayOpen()`; the two questions after it
     * only choose the wording, so a refusal can never disagree with itself.
     */
    public static function forEditing(DisplayStore $store, array $params, Actor $actor)
    {
        $resolution = self::locate($store, $params, $actor);
        if (!$resolution->isFound()) { return $resolution; }

        $display = $resolution->display();
        if (!$actor->mayOpen($display)) {
            return $actor->mayEdit($display)
                ? DisplayResolution::inactive($display)     // theirs, but out of service
                : DisplayResolution::forbidden($display);   // not theirs
        }
        return $resolution;
    }

    /**
     * Tag → Display.
     *
     * @param Actor|null $actor null for viewing, which never resolves an unnamed
     *                          Display; an Actor for editing, whose own list of
     *                          Displays decides whether "no tag" is answerable
     */
    private static function locate(DisplayStore $store, array $params, $actor)
    {
        $raw = isset($params[self::PARAM]) ? (string)$params[self::PARAM] : '';

        if (trim($raw) === '') {
            if ($actor === null) {
                // Viewing is strict (ADR-0003): the Screen shows a notice rather
                // than guessing which sign was meant.
                return DisplayResolution::noTag();
            }

            // The editing entry rule: an account with one sign to work on is not
            // asked which sign it meant. A Builder or API request naming no
            // Display gets that one — which for an admin at a single-sign store is
            // the only Display there is, and for a `basic` account with one grant
            // is the sign they were given.
            //
            // The safety property is that this resolves to nothing the moment two
            // Displays are openable, so a write is never routed to a guessed sign:
            // the request fails and the Builder shows its picker instead
            // (BUILD-REFERENCE.md §3).
            $openable = $actor->openable($store->all());
            return count($openable) === 1
                ? self::confirmIdentity($openable[0], $params)
                : DisplayResolution::noTag();
        }

        $display = $store->forTag($raw);
        return $display ? self::confirmIdentity($display, $params) : DisplayResolution::unknown();
    }

    /**
     * Does the caller still agree with us about which Display this is?
     *
     * The tag routes; the id confirms. An admin renaming a tag is an ordinary act,
     * and the tag it vacated may be given to another sign the same afternoon — so a
     * Builder that has been open across both changes holds an address that now
     * resolves, cleanly and silently, to somebody else's screen. Its next publish
     * would overwrite that layout and report success, and there is no undo anywhere
     * in this app (CLAUDE.md). Refusing the write is the only safe answer.
     *
     * A claim that is absent, or empty, is no claim: the check cannot be the thing
     * that stops a Screen rendering. A claim that is malformed — an array, a word,
     * `007` — *is* a disagreement, because nothing that knows its Display sends
     * one, and it is not this method's business to guess what was meant.
     */
    private static function confirmIdentity(Display $display, array $params)
    {
        if (!isset($params[self::ID_PARAM])) { return DisplayResolution::found($display); }

        $claim = $params[self::ID_PARAM];
        if (is_array($claim)) { return DisplayResolution::mismatch($display); }

        $claim = trim((string)$claim);
        if ($claim === '') { return DisplayResolution::found($display); }

        return ((string)intval($claim) === $claim && intval($claim) === $display->id())
            ? DisplayResolution::found($display)
            : DisplayResolution::mismatch($display);
    }
}
