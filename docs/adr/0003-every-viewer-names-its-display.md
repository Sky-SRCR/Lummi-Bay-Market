# Every Viewer URL names its Display; there is no default

## Status

accepted

## Context

The app is growing from one sign to many. Each configured sign is a **Display**;
the single public page `viewer.php` renders one of them, chosen by a **screen name
tag** in its URL (`viewer.php?display=lobby`). Until now `viewer.php` took no
parameters and rendered the only layout that existed, and the live drive-thru
Screen is configured with exactly that bare URL.

That raised the question of whether a bare `viewer.php` should keep working as the
drive-thru sign. It would have made the cutover free — the TV and the
SmartSign2Go widget would need no changes at all.

## Decision

**A Viewer URL must name its Display.** A bare `viewer.php`, an unknown tag, or a
tag belonging to a deactivated Display each render a plain notice on the kiosk
background — never a layout. There is no default, master, or fallback Display.

The wording differs per case on purpose, so someone standing in front of the Screen
can tell a configuration mistake from an intentional retirement: *"No display
specified"* versus *"This display is turned off."*

The name tag is editable after creation, behind a confirm that states the
consequence and shows the new URL to enter on the Screen.

## Considered options

- **Bare `viewer.php` keeps serving the drive-thru sign** — rejected. A URL
  truncated or mistyped by whoever configures a Screen would then quietly display
  the drive-thru sign's prices on some other Screen, and nothing about the picture
  would say anything was wrong. Silently showing the wrong content is worse than
  visibly showing none.
- **One PHP file per Display** (`viewer_lobby.php`) — rejected. Prettier URLs, but
  each new Display would need a file placed on the server plus its own `.htaccess`
  framing rule, which defeats adding Displays from the admin panel. The app
  deliberately cannot write PHP files (see the upload hardening in `api.php` /
  `crud.php`).
- **Retired tags redirect to their replacement** — rejected as premature for a
  handful of Screens; it trades a table and a lookup for a confirm dialog.

## Consequences

- **One-time on-site cutover.** The drive-thru Screen and the SmartSign2Go widget
  must be re-pointed from `…/viewer.php` to `…/viewer.php?display=drive-thru`.
  Between deploying and re-pointing, that Screen shows the "No display specified"
  notice — so deploy and re-point in the same visit, or outside opening hours.
- Keeping the single filename means the existing `<Files "viewer.php">` block in
  `.htaccess` — which drops `X-Frame-Options` so external signage widgets can
  embed the sign, alongside the kiosk scroll lock — applies to every Display
  automatically, with no per-Display server work.
- Renaming a tag breaks exactly one Screen, recoverably: re-enter the URL on the
  device. Titles and locations are reference-only and carry no such risk.
