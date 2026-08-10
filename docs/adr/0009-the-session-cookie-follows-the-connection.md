# The session cookie's Secure flag follows the connection

## Status

accepted

## Decision drivers

`auth.php` marked the session cookie `Secure` unconditionally. Secure is not a
preference for HTTPS; it is an instruction that the browser must not store or send
the cookie over plain HTTP. On an install served over `http://`, every part of
signing in worked and none of it stuck:

```
login.php verifies the password, writes $_SESSION, sends 302 → builder.php
the browser discards the Set-Cookie
builder.php sees no session, requireLogin() sends 302 → login.php
login.php sees no session and prints the empty form
```

No warning, no log line, no wrong password — the form returns, and it returns
forever. Nothing in the app can be reached, by anybody, ever.

It survived because the live site's `.htaccess` redirects to HTTPS before PHP
runs, so on the one server that matters the flag was always right. Every other
copy — an intranet install, a staging box, the afternoon a certificate is being
sorted out, a laptop — cannot be signed into at all.

## Decision

The flag follows the request. `RequestScheme::isSecure($_SERVER)` answers from
`HTTPS`, `REQUEST_SCHEME`, `SERVER_PORT`, `X-Forwarded-Proto` or the older
`X-Forwarded-SSL`, and `auth.php` turns that into the four attributes — `Secure`
only over TLS; `HttpOnly`, `SameSite=Lax` and the path unconditionally.

Which has to be written twice, and is: the options-array form of
`session_set_cookie_params()` is PHP 7.3+, and on 7.1 it does not merely ignore
`samesite` — it sets **nothing at all**, losing `HttpOnly` and `Secure` with it,
and emits a warning ahead of `session_start()` that can break sign-in outright.
The pre-7.3 branch appends the attribute to the path (`'/; SameSite=Lax'`), which
the header carries verbatim. The store was stated to run 8.2 (decision #51), so the
modern branch is the one that runs today — but the pre-7.3 branch stays rather than
being deleted as a formality, because nothing here has *observed* that version and
what its absence would cost is a sign-in cookie silently missing `HttpOnly`,
`Secure` and `SameSite`.

`Settings → This Server` reports Secure against the scheme, so an admin reading
"Secure: no" over plain HTTP is told the site is not on HTTPS rather than that a
protection failed to apply.

A forwarded header is **believed**. A stranger can set it only on their own
request, and the only thing it buys them is their own browser discarding their own
cookie — these attributes go on the response to the request that carried the
header, so a per-request header cannot mark another person's cookie.
Disbelieving it on a host that terminates TLS at a proxy would hand every
signed-in member of staff a cookie with no Secure flag on a site that genuinely is
HTTPS: the forgeable direction costs the forger, the cautious direction costs the
store.

## Considered options

- **A configuration constant** (`define('SECURE_COOKIES', true)`) — rejected. It
  moves the same failure somewhere nothing sets correctly by default, a wrong
  value reproduces this exact invisible loop, and the request already knows the
  answer. Autodetection also needs no edit the day the site moves to HTTPS.
- **Leave `Secure` hardcoded and require HTTPS everywhere** — rejected as a thing
  this repo can enforce. It is what the live server does, by a redirect this repo
  does not deploy; asserting it in code turns any install without that redirect
  into an app with no way in and no explanation.
- **Ignore `X-Forwarded-Proto`** — rejected above.
- **Keep the cookie's answer private to `RequestScheme` and let the admin panel
  go on asking its own way** — rejected, and it was the shipped position for a
  short while. The rule worth keeping is one *implementation* of the question, not
  one caller: a second answer is what makes the two halves disagree, and reusing
  the first is the opposite of that. See the consequence below for what the
  admin panel's own copy was getting wrong.

## Consequences

- On the live site nothing changes: `.htaccess` redirects to HTTPS, so PHP only
  ever sees `https` and the cookie is Secure exactly as before.
- On a plain-HTTP install the cookie is now storable, and readable by anything on
  the wire. That is the honest state of an app served over HTTP, and it is the
  state the Settings page names.
- `RequestScheme::scheme()` is also the answer for the viewer address an admin
  copies onto a TV. That code had its own copy of the question, which knew only
  about `$_SERVER['HTTPS']` and so printed `http://` for an HTTPS site behind a
  proxy — the same defect as the cookie's, failing somebody standing at a
  television with no way to tell why the address will not load.
- A second, unrelated cause of the same invisible loop — a browser that keeps no
  cookies at all — is now *said* rather than looped. The CSRF gate ADR-0008's
  ordering puts ahead of the account lookup is what catches it: a POST arriving
  with no session has no token to send and never will, so it is refused softly,
  in words that name the browser rather than the password.
