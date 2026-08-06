<?php
// ============================================================
// HTTP REPLY — the envelope every answer leaves in
// ============================================================
// Three things travel with every reply and none of them had an owner: the status
// line, the caching rules, and — for the JSON endpoints — the bytes of the body.
// They are one concern because they fail as one. A reply whose body did not encode
// still went out as "200 OK, application/json, zero bytes" (#26), and a reply that
// said "no such sign" said it with the same 200 as a working one, uncached by
// nothing in particular, because no file in the app had ever set a cache header
// (#28).
//
// Both of those are invisible from the inside. Nothing throws, nothing is logged,
// and the sign in the shop goes on showing last week's prices.
//
// ---- The body -----------------------------------------------------------------
//
// `echo json_encode($payload)` is a silent failure waiting for one bad byte.
// json_encode returns `false` — not an empty array, not a throw — and `echo false`
// prints the empty string. The caller gets 200 with nothing in it, `r.json()`
// rejects, and the Viewer's `.catch()` does the right thing for a dropped
// connection: it keeps the layout on the screen and tries again in 30 seconds.
// Forever, because the bad byte is in the database and every retry hits it.
//
// So the encode is never done by a caller. It is done here, where a failure can be
// told apart:
//
//   · Malformed UTF-8 is re-encoded with the bad bytes replaced (U+FFFD) and
//     reported to an admin. See substituteRatherThanRefuse() below for why that is
//     not the "refuse rather than merge" rule being broken.
//   · Anything else — INF, NAN, recursion, a resource, nesting past the depth
//     limit — cannot be repaired, so the reply becomes a real 500 carrying a body
//     that is *known* to encode, and the admin is told.
//
// Either way the caller gets JSON it can parse. That is the whole invariant: this
// app never answers a JSON request with something that is not JSON.
//
// ---- The status line ------------------------------------------------------------
//
// The app already names its own failures — `reason` on every error payload, kind()
// on every DisplayResolution — so the status code is derived from that name rather
// than chosen at each call site. A code and a reason that disagree would disagree
// silently, and there are twenty-odd call sites to keep in step.
//
// ---- The caching rules ------------------------------------------------------------
//
// Nothing anywhere set one, which mattered least while every answer was a 200 and
// matters immediately now that some are 404s: a 404 is heuristically cacheable by
// default (RFC 9110 §15.5.5) where an unlabelled 200 with no validator mostly is
// not. Fixing the status codes without fixing the caching would have made a
// mistyped screen name tag *stickier* than it was before.
//
// Everything this app serves is either a signed-in page, a poll whose entire
// purpose is to be current, or a notice that exists to stop being true. None of it
// should ever be held: no-store, everywhere, from one place.

require_once __DIR__ . '/error_policy.php';

class HttpReply
{
    /** What a Screen is told to wait before asking again — the Viewer's poll cadence. */
    const RETRY_AFTER = 30;

    /** How often one admin hears about the same unencodable reply. */
    const REPORT_WINDOW = 3600;

    /**
     * The floor. Not built by json_encode, because the case it is for is
     * json_encode having already failed on a payload we control.
     */
    const LAST_RESORT = '{"status":"error","reason":"unencodable",'
                      . '"message":"Temporarily unavailable. Please try again in a moment."}';

    /**
     * The app's own vocabulary of failure, as HTTP.
     *
     * Read down the right-hand column and the list has to make sense to something
     * that will never see the body: a proxy, an uptime check, `curl` during a
     * deploy. That is the only reason the code exists — the body has said all of
     * this in words since Phase 2.
     */
    private static $codes = [
        // Nothing named a sign. The address is wrong; asking again will not help.
        'no_tag'      => 400,
        // Named one that is not here — a typo, a rename, a deletion.
        'unknown'     => 404,
        // ElementResult's word for the same thing about one block rather than a sign.
        'not_found'   => 404,
        // Named one that exists and is deliberately not serving. It comes back when
        // an admin turns it back on, which is why this is 503 and not another 404:
        // "not here" and "here, switched off" are the two states an admin most needs
        // to tell apart from across the shop, and #28 is the item about having
        // conflated them.
        'inactive'    => 503,
        'forbidden'   => 403,
        'signed_out'  => 403,
        // Two truths about the world that cannot both hold. The tag and the id name
        // different signs; somebody else published first; somebody else holds the
        // lock; two publishes arrived at once (#35).
        'mismatch'    => 409,
        'stale'       => 409,
        'locked'      => 409,
        'busy'        => 409,
        // Read, understood, refused. 422 rather than 400: the request was well
        // formed and the content was not acceptable.
        'invalid'     => 422,
        'too_large'   => 413,
        // Ours.
        'failed'      => 500,
        'unencodable' => 500,
    ];

    /**
     * The status code for a named failure, or $default when the name is not one of
     * ours. A reason added to an endpoint later and never listed here still gets a
     * sane code rather than a 200 that says the failure succeeded.
     */
    public static function codeFor($reason, $default = 400)
    {
        return isset(self::$codes[$reason]) ? self::$codes[$reason] : $default;
    }

    /**
     * The code a payload implies. `status` is the app's own success flag and is
     * present on every reply that has one; a bare array — the asset list, the Work
     * Area index — has neither and is a 200.
     */
    public static function codeForPayload(array $payload)
    {
        $status = isset($payload['status']) ? $payload['status'] : 'success';
        if ($status === 'success') { return 200; }

        // DisplayResolution puts its kind in `reason`; so does every publish result.
        // A payload that failed without saying why is a 400: it is a refusal, and a
        // refusal must not leave as a success.
        return self::codeFor(isset($payload['reason']) ? $payload['reason'] : '', 400);
    }

    /**
     * The whole reply, as a pure function of its arguments. No output, no headers,
     * no logging — so it can be read and tested without a server, the way
     * ErrorPolicy::noticeFor() can.
     *
     * Returns:
     *   code    — the status to send, which a failed encode overrides to 500
     *   body    — bytes that always parse as JSON. Never '' and never false.
     *   trouble — '' | 'substituted' | 'unencodable'
     *   detail  — for the log; never for the caller
     */
    public static function reply(array $payload, $code = null)
    {
        if ($code === null) { $code = self::codeForPayload($payload); }

        $body = @json_encode($payload);
        if ($body !== false) {
            return ['code' => $code, 'body' => $body, 'trouble' => '', 'detail' => ''];
        }

        $why = json_last_error();

        // ---- substituteRatherThanRefuse ----------------------------------------
        // The recoverable one, and the only one that happens in a shop.
        //
        // A block's text is invalid UTF-8 — a paste out of a mixed-charset restore,
        // a row hand-edited in phpMyAdmin, a dump from before the columns were
        // utf8mb4. One bad byte in one block made json_encode refuse the *entire*
        // payload, so a sign with forty correct prices on it went stale over a
        // character nobody could see.
        //
        // "Prefer refusing a write to merging one" (CLAUDE.md) is not in tension
        // with this, and it is worth saying why rather than leaving it to be
        // rediscovered:
        //
        //   · This is a read. The stored bytes are not touched.
        //   · The write door is already shut. A publish arrives as a JSON string and
        //     json_decode refuses malformed UTF-8 outright, so nothing invalid can
        //     enter through the app — see PublishRequest::fromPostedJson.
        //   · Refusing the read instead would take the whole sign dark over one
        //     character *and* make the fault unfixable through the app: the Builder
        //     is the only tool for editing that text, and it would refuse to load
        //     the layout containing it. The only remaining fix would be SQL against
        //     the live database.
        //   · U+FFFD loses nothing that was not already lost. The byte could not be
        //     rendered, searched or exported. What it buys is that the damage
        //     becomes *visible* — in the Builder, in the text, where the person who
        //     can fix it is looking.
        //
        // And an admin is told, so it is not a repair that happens quietly forever.
        if ($why === JSON_ERROR_UTF8) {
            $body = @json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
            if ($body !== false) {
                return [
                    'code'    => $code,
                    'body'    => $body,
                    'trouble' => 'substituted',
                    'detail'  => 'A reply held text that is not valid UTF-8. It was sent with the '
                               . 'bad characters replaced. Open the display in the builder: the '
                               . 'damaged text now shows a replacement character where the bad '
                               . 'bytes were, and re-typing and publishing that block fixes it.',
                ];
            }
        }

        // Not repairable: INF or NAN in a number, a recursive structure, a resource,
        // nesting past the depth limit. The caller still gets JSON, and it is JSON
        // built from a payload this file controls rather than from the one that just
        // failed — encoded with substitution as well, because the sentence comes
        // from ErrorPolicy and this is not the place to find out it never can fail.
        $fallback = @json_encode([
            'status'  => 'error',
            'reason'  => 'unencodable',
            'message' => ErrorPolicy::sentence(),
        ], JSON_INVALID_UTF8_SUBSTITUTE);

        return [
            'code'    => self::codeFor('unencodable'),
            'body'    => $fallback === false ? self::LAST_RESORT : $fallback,
            'trouble' => 'unencodable',
            'detail'  => 'A reply could not be encoded as JSON (' . self::whyName($why) . '), so an '
                       . 'error was sent in its place. Nothing was saved or changed by this request.',
        ];
    }

    /**
     * Send one. The only method the endpoints call.
     *
     * $code is derived from the payload unless given. Pass it explicitly for the
     * handful of replies whose shape predates `reason` — the bare asset list, the
     * Work Area index — or where the code is the point (413 for a dropped body).
     */
    public static function json(array $payload, $code = null)
    {
        $reply = self::reply($payload, $code);

        if ($reply['trouble'] !== '') {
            // Throttled, and keyed by what went wrong and where. A Screen polls
            // every 30 seconds and the cause is in the database, so an unthrottled
            // report is 2,880 identical lines a day per Screen in a log that rotates
            // at 2 MB — the exact case ErrorPolicy::report's $every exists for.
            ErrorPolicy::report(
                'json-reply|' . $reply['trouble'] . '|' . ErrorPolicy::whichRequest()
                    . '|' . self::subjectOf($payload),
                $reply['detail'] . self::subjectSuffix($payload),
                $reply['trouble'] === 'substituted'
                    ? 'A display holds text that is not valid UTF-8'
                    : 'A reply could not be encoded',
                self::REPORT_WINDOW
            );
        }

        if (!headers_sent()) {
            @http_response_code($reply['code']);
            @header('Content-Type: application/json; charset=utf-8');
            if ($reply['code'] === 503) { @header('Retry-After: ' . self::RETRY_AFTER); }
            self::noStore();
        }

        echo $reply['body'];
    }

    /**
     * Never hold this reply — the header lines, as a list, so the decision can be
     * tested where header() does nothing (CLI) and headers_list() is empty.
     *
     * `no-store` alone is the correct modern answer and the other three are for
     * caches that predate it. They cost one line each and this app has already been
     * bitten once by assuming a mechanism was in force when it silently was not
     * (the session cookie flags — see auth.php).
     */
    public static function cacheHeaders()
    {
        return [
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0',
            'Pragma: no-cache',
            'Expires: 0',
        ];
    }

    /**
     * Apply them. Safe to call twice and safe to call late: a page that has already
     * begun printing cannot un-send its headers, and saying so quietly is right —
     * the alternative is a warning printed onto a sign.
     */
    public static function noStore()
    {
        if (headers_sent()) { return false; }
        foreach (self::cacheHeaders() as $line) { @header($line); }
        return true;
    }

    /**
     * The status code for a resolution that did not find its Display, for the two
     * entry points that answer in HTML rather than JSON.
     */
    public static function codeForResolution(DisplayResolution $resolution)
    {
        return $resolution->isFound() ? 200 : self::codeFor($resolution->kind(), 404);
    }

    /**
     * A PHP value as a JavaScript literal, for printing into a page's own script.
     *
     * Two things, and every `var X = json_encode(...)` in this app needed both.
     *
     * **It is always syntactically valid.** json_encode returning false emits
     * `var X = ;` — a parse error that takes down the *whole* script block, not
     * just the one value. On viewer.php that is a blank television, from one byte,
     * with nothing in any log; on builder.php it is a page of controls that do
     * nothing. Nine call sites had this and none of them checked.
     *
     * **It escapes for the context it is printed into.** `<` `>` `'` `"` and `&`
     * become \u escapes, so a title containing `</script>` cannot end the block it
     * is inside. builder.php and admin_panel.php passed these four flags by hand at
     * every call and viewer.php passed none — which was safe only because a screen
     * name tag is `[a-z0-9-]`, i.e. safe by an argument that lives in another file.
     */
    public static function jsValue($value)
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
               | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = @json_encode($value, $flags);
        // `null` rather than `''`: a literal the parser accepts, and a value the
        // page's own guards already handle, since every one of these is a string or
        // an array the script tests before using.
        return $json === false ? 'null' : $json;
    }

    // ---- Internals ----------------------------------------------------------

    /** Which sign the trouble is about, for the throttle key. '' when unknowable. */
    private static function subjectOf(array $payload)
    {
        return isset($payload['display']['tag']) && is_string($payload['display']['tag'])
            ? $payload['display']['tag']
            : '';
    }

    /** The same, as a sentence for the admin, who needs to know which sign to open. */
    private static function subjectSuffix(array $payload)
    {
        $tag = self::subjectOf($payload);
        return $tag === '' ? '' : "\n\nThe display involved is \"" . $tag . '".';
    }

    private static function whyName($code)
    {
        $names = [
            JSON_ERROR_DEPTH            => 'nested too deeply',
            JSON_ERROR_STATE_MISMATCH   => 'malformed structure',
            JSON_ERROR_CTRL_CHAR        => 'a control character',
            JSON_ERROR_SYNTAX           => 'a syntax error',
            JSON_ERROR_UTF8             => 'text that is not valid UTF-8',
            JSON_ERROR_RECURSION        => 'a value that refers to itself',
            JSON_ERROR_INF_OR_NAN       => 'a number that is infinite or not a number',
            JSON_ERROR_UNSUPPORTED_TYPE => 'a value of a type JSON has no form for',
        ];
        return isset($names[$code]) ? $names[$code] : 'error ' . intval($code);
    }
}
