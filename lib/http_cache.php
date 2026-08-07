<?php
// ============================================================
// HTTP CACHING — nothing this app answers may be stored
// ============================================================
// Until now: nothing set a caching rule anywhere, on any path, so what happened to
// a reply was whatever the browser, the kiosk's WebView, and any proxy between the
// store and the host each decided on their own. A response with no `Cache-Control`,
// no `Expires` and no `Last-Modified` is not "uncacheable" — it is *undefined*, and
// the shared answer to undefined is a heuristic freshness lifetime.
//
// Two of this app's paths cannot survive that, and they are the two with nobody
// watching:
//
//   1. `api.php?action=get_layout` is the sign's whole content, fetched every 30
//      seconds. A cache that holds it for ten minutes is a sign that shows last
//      week's prices while the Builder reports every publish as a success — the
//      exact failure this repo has no undo for.
//   2. `viewer.php` answers a notice when a Display is missing, unknown or turned
//      off. Stored, that notice outlives the thing it was reporting: an admin
//      corrects the screen name tag, or turns the sign back on, and the Screen keeps
//      showing "Display not found" until somebody drives over and clears a cache
//      nobody can see. That is the same shape of defect as the latched layout hash
//      (§4g) — a negative answer remembered for longer than the negative lasted.
//
// The rest is not exempt so much as unmeasurable: a signed-in page carries somebody's
// name and a CSRF token, and the back button on a shared back-office browser after a
// sign-out is a page nobody meant to leave up. There is no page in this app worth
// caching — every one of them is either a sign, an endpoint, or a form — so there is
// no reason to have this decision twice. `db_connect.php` calls it once, for
// everything, before it even tries to connect: a failure notice from a database that
// is down must not be stored either, or the outage outlives itself.
//
// Three headers for one rule, all of them on purpose:
//
//   `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` is the modern
//   one, and `no-store` is the load-bearing word — `no-cache` only means "revalidate
//   before reuse", which is a promise a cache can keep while still holding the bytes.
//   `Pragma: no-cache` and `Expires: 0` are for HTTP/1.0 caches, which are exactly
//   the kind of thing a signage widget or a small-business router turns out to be
//   running. They cost 30 bytes a reply and remove a class of failure that can only
//   be diagnosed by standing in front of the sign.
//
// One thing this deliberately does not reach: `uploads/`. Images and videos are served
// by Apache and never pass through PHP, which is the wanted answer rather than an
// oversight — every filename there carries a `uniqid()`, so the bytes behind a path
// never change, and no-storing a 40 MB video would re-fetch it over the store's
// connection every time a sign reloaded.
//
// Depends on nothing. Prints nothing. Sets headers and says whether it managed to.

class HttpCache
{
    /**
     * The rule itself, as data, so it can be read and tested without a web server —
     * the same reason `ErrorPolicy::noticeFor()` is pure. Name => value, in the
     * order they go out.
     */
    public static function headers()
    {
        return [
            // no-store first: of these four directives it is the only one that
            // forbids *keeping* the bytes. The other three are belt and braces for
            // caches implementing an older spec, or a subset of this one.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ];
    }

    /**
     * Say it on this response. Returns false when it could not — output has already
     * begun, so the headers are gone and there is nothing to be done about it.
     *
     * Silent rather than noisy: the one caller is an include at the top of every
     * entry point, so a false here means somebody has printed before opening the
     * database, which is a defect that will announce itself in louder ways than a
     * caching header. Raising a warning from inside a header-setting function on a
     * request that has already sent its headers is how a page ends up with PHP's own
     * output on it — the thing invariant 16 exists to prevent.
     */
    public static function neverStore()
    {
        if (headers_sent()) { return false; }
        foreach (self::headers() as $name => $value) {
            header($name . ': ' . $value);
        }
        return true;
    }
}
