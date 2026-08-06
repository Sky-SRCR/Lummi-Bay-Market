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

The flag follows the request. `RequestScheme::isHttps($_SERVER)` answers from
`HTTPS`, `REQUEST_SCHEME`, `SERVER_PORT` or `X-Forwarded-Proto`, and
`RequestScheme::sessionCookie()` turns that into the four attributes — `Secure`
only over TLS; `HttpOnly`, `SameSite=Lax` and the path unconditionally. One module
owns it, including the two PHP call signatures it has to be expressed in (the
options array is 7.3+; on 7.1 it sets *nothing*), and `Settings → This Server`
reports Secure against the scheme so an admin reading "Secure no" over plain HTTP
is told the site is not on HTTPS rather than that a protection failed to apply.

A forwarded header is **believed**. A stranger can set it only on their own
request, and the only thing it buys them is their own browser discarding their own
cookie. Disbelieving it on a host that terminates TLS at a proxy would hand every
signed-in member of staff a cookie with no Secure flag on a site that genuinely is
HTTPS — the forgeable direction costs the forger, the cautious direction costs the
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

## Consequences

- On the live site nothing changes: `.htaccess` redirects to HTTPS, so PHP only
  ever sees `https` and the cookie is Secure exactly as before.
- On a plain-HTTP install the cookie is now storable, and readable by anything on
  the wire. That is the honest state of an app served over HTTP, and it is the
  state the Settings page names.
- `RequestScheme::scheme()` is also the answer for the viewer address an admin
  copies to a TV. That code had its own copy of the question which knew only about
  `$_SERVER['HTTPS']`, and printed `http://` for an HTTPS site behind a proxy.
- A second, unrelated cause of the same loop — a browser that keeps no cookies —
  is now *said* rather than looped: a POST to `login.php` carrying no session
  cookie is refused with an explanation, before the password is looked at (the
  ordering is ADR-0008's, not an aesthetic choice).
