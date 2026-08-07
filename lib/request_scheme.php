<?php
// ============================================================
// REQUEST SCHEME — did this request reach the browser over HTTPS?
// ============================================================
// One question, asked for one reason: whether the sign-in cookie may carry the
// `Secure` attribute.
//
// A browser silently discards a `Secure` cookie that arrives over plain HTTP.
// PHP sets the attribute anyway, and says nothing. So on any deployment reached
// over http:// — a staging copy, a host where `.htaccess` is not honoured, the
// live site on the day mod_rewrite is off — sign-in did this: login.php verified
// the password, wrote the session, and redirected to builder.php; the browser
// threw the cookie away on the way; builder.php found no session and redirected
// back to login.php; login.php rendered a clean, blank form. A correct password,
// any number of times, and not one word anywhere about why. The password was
// never the problem, so nothing the person could try would ever fix it, and there
// is no error text to search for because there is no error.
//
// The flag protects the leg between the browser and whatever terminates TLS. That
// is the only thing it can protect, so that is the only thing this asks about:
// **is the browser's own leg HTTPS?** Not "is this host configured for HTTPS",
// not "should it be" — a rule about what the browser will accept has to be
// answered per request, because it is the request the browser judges.
//
// The forwarded headers are believed on purpose, and the reasoning runs the
// opposite way from the usual one. Not believing them is the answer that breaks
// something: a site behind a TLS-terminating proxy — Cloudflare, a load balancer,
// a cPanel front end — arrives at PHP as plain HTTP, and refusing the header there
// means a cookie sent without `Secure` to a browser that is on HTTPS and could have
// had it. Believing a *forged* header costs the forger their own sign-in and
// nobody else's, because these attributes are set on the response to the request
// that carried the header. A per-request header cannot mark another person's
// cookie.
//
// Nothing else in the app may *implement* this question, and one other place asks
// it: `admin_panel.php` builds the absolute viewer address an admin copies onto a
// TV, and it used to answer for itself from the HTTPS variable alone — so a site
// behind a TLS-terminating proxy had `http://` printed for it, which is the same
// defect as the cookie's, one line further from anything that would say so. It
// calls `scheme()` now. Two callers of one answer is the point; two answers is
// how the halves of a rule start disagreeing, which is why `isSecure()` appears
// nowhere else in the tree.

class RequestScheme
{
    /**
     * Is the browser at the far end of this request using HTTPS?
     *
     * Takes the server array rather than reading `$_SERVER`, so the decision can
     * be checked against every shape a host reports it in without a web server.
     * An array that says nothing answers **false**: the safe direction is the one
     * where sign-in works and the cookie is unprotected, not the one where the
     * cookie is perfect and nobody can get in.
     */
    public static function isSecure(array $server)
    {
        // Apache and most others. IIS is the reason for the "off" test: it sets
        // the variable on every request and puts "off" in it, so `!empty()` alone
        // reads every plain-HTTP request there as secure.
        if (isset($server['HTTPS'])) {
            $https = strtolower(trim((string)$server['HTTPS']));
            if ($https !== '' && $https !== 'off' && $https !== '0') { return true; }
        }

        if (isset($server['REQUEST_SCHEME'])
            && strtolower(trim((string)$server['REQUEST_SCHEME'])) === 'https') {
            return true;
        }

        if (isset($server['SERVER_PORT']) && intval($server['SERVER_PORT']) === 443) {
            return true;
        }

        // A proxy that terminated TLS. The header is a list when the request
        // crossed more than one hop, and the first entry is the one the browser
        // made — the later ones describe legs behind the front door, which the
        // browser neither sees nor judges.
        if (isset($server['HTTP_X_FORWARDED_PROTO'])) {
            $hops = explode(',', (string)$server['HTTP_X_FORWARDED_PROTO']);
            if (strtolower(trim($hops[0])) === 'https') { return true; }
        }

        // The same fact, spelled the other way, by proxies that predate the one
        // above.
        if (isset($server['HTTP_X_FORWARDED_SSL'])
            && strtolower(trim((string)$server['HTTP_X_FORWARDED_SSL'])) === 'on') {
            return true;
        }

        return false;
    }

    /**
     * The scheme to put in an absolute URL — `https` or `http`.
     *
     * The same fact as `isSecure()`, spelled for a caller building a link rather
     * than a cookie. Printing the wrong one is not a security failure the way an
     * unprotected cookie is; it is a worse kind of quiet, because what it produces
     * is an address somebody types into a television across the store and cannot
     * work out why it will not load.
     */
    public static function scheme(array $server)
    {
        return self::isSecure($server) ? 'https' : 'http';
    }
}
