<?php
// ============================================================
// REQUEST SCHEME — how this request arrived, and what the cookie may claim
// ============================================================
// `auth.php` marked the session cookie **Secure** unconditionally. Secure does not
// mean "prefer HTTPS"; it means the browser must refuse to store or send this cookie
// over plain HTTP. So on an install served over http:// the whole of sign-in worked
// and none of it stuck:
//
//   login.php checks the password, writes $_SESSION, sends 302 → builder.php
//   the browser drops the Set-Cookie on the floor
//   builder.php sees no session, requireLogin() sends 302 → login.php
//   login.php sees no session and prints the empty form again
//
// Nothing failed. No warning, no log line, no wrong password — the form simply
// comes back, and it comes back forever. It is invisible exactly where this code is
// developed and total where it is not: the live site's `.htaccess` redirects to
// HTTPS before PHP runs, so on that one server the flag was always right, which is
// why a defect that locks everybody out of the app never showed up in it. Any copy
// without that redirect — an intranet install, a staging box, the afternoon a
// certificate is being sorted out — cannot be signed into at all.
//
// So the flag follows the connection instead of asserting one. Over HTTPS the cookie
// is Secure, as it always was on the live site; over plain HTTP it is not, and the
// Settings → This Server readout says so in those words rather than reporting a
// protection that "did not apply" (`lib/server_report.php`).
//
// The rejected alternative was a setting — `define('SECURE_COOKIES', true)` in
// config.php. It fails the same way in a new place: nothing sets it correctly by
// default, a wrong value reproduces this exact invisible loop, and the request
// already knows the answer. A site that later moves to HTTPS needs no edit.
//
// Nothing is required here: this file must be includable from a page that has not
// started a session (see BUILD-REFERENCE.md §1), which is the whole point of it.

class RequestScheme
{
    /**
     * Did this request arrive over TLS?
     *
     * Four ways to be told so, because one server states it in one place and the
     * next states it in another:
     *
     *   HTTPS                 Apache/nginx on the machine terminating TLS ("on", "1")
     *   REQUEST_SCHEME        Apache's own answer, and the plainest one
     *   SERVER_PORT           443, for a server that sets neither
     *   X-Forwarded-Proto     a proxy or load balancer that terminated TLS in front
     *
     * The forwarded header is trusted deliberately. A stranger can set it only on
     * their **own** request, and all that buys them is a cookie their own browser
     * then refuses to keep — while ignoring it on a host whose TLS ends at a proxy
     * would hand every signed-in person a cookie with no Secure flag on a site that
     * genuinely is HTTPS. The forgeable direction costs the forger; the cautious
     * direction costs the store.
     *
     * A CLI run (the self-tests) has none of these and is therefore not HTTPS,
     * which is the right answer: there is no connection to protect.
     */
    public static function isHttps(array $server)
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') { return true; }

        if (strtolower((string)($server['REQUEST_SCHEME'] ?? '')) === 'https') { return true; }

        if (intval($server['SERVER_PORT'] ?? 0) === 443) { return true; }

        // A chain of proxies leaves a comma-separated list; the client's own scheme
        // is the first entry, and the rest describe hops we are not being asked about.
        $forwarded = (string)($server['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($forwarded !== '') {
            $first = strtolower(trim(explode(',', $forwarded)[0]));
            if ($first === 'https') { return true; }
        }

        return false;
    }

    /** 'https' or 'http' — for an address being copied to a TV that has no page context. */
    public static function scheme(array $server)
    {
        return self::isHttps($server) ? 'https' : 'http';
    }

    /**
     * The session cookie's four attributes for a request that arrived like this.
     *
     * Separated from applying them so the decision can be asserted without a live
     * session: the interesting case is the one no test process can be in, a request
     * over plain HTTP that must still be signable-into.
     */
    public static function sessionCookie(array $server)
    {
        return [
            'path'     => '/',
            'httponly' => true,                        // unreadable to page scripts
            'secure'   => self::isHttps($server),      // only claimable over TLS
            'samesite' => 'Lax',                       // not sent on cross-site requests
        ];
    }

    /**
     * Set those attributes on the session about to be started.
     *
     * Two forms, because the options-array signature arrived in PHP 7.3 and this app
     * targets 7.1 with the live version unverified. On 7.1 the array form is not a
     * partial success — it fails argument parsing and sets *nothing*, so the cookie
     * loses HttpOnly and Secure as well as SameSite, and the warning it emits lands
     * before session_start() and can break sign-in outright. The pre-7.3 idiom
     * appends the attribute to the path, which the header accepts verbatim.
     */
    public static function applyToSession(array $server)
    {
        $cookie = self::sessionCookie($server);
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($cookie);
            return;
        }
        session_set_cookie_params(
            0,
            $cookie['path'] . '; SameSite=' . $cookie['samesite'],
            '',
            $cookie['secure'],
            $cookie['httponly']
        );
    }
}
