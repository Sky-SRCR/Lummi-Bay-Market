# Lummi Bay Market — Digital Signage

A PHP + MySQL display-builder for the Lummi Bay Market drive-thru pricing
sign: admins design a 1920×1080 layout, publish it, and a fullscreen viewer
on a TV auto-refreshes to show it.

## Language

### Displays

**Display**:
One configured sign — its name tag, canvas size, title, location, layout, and the
list of accounts allowed to edit it. The unit an admin adds, modifies, and deletes.
_Avoid_: viewer page, screen, canvas

**Viewer**:
The single public page that renders one Display fullscreen for a Screen. One Viewer
page serves every Display; which one it shows comes from the name tag in its URL.
_Avoid_: display page, kiosk page

**Screen**:
The physical hardware — a TV, kiosk, or embedded signage widget — showing a Viewer.
Configured once, on site, with the URL of one Display.
_Avoid_: monitor, device, display

**Screen name tag**:
The short word that names a Display in a Viewer URL (`lobby` in
`viewer.php?display=lobby`). Unique, admin-editable, and the contract a configured
Screen depends on.
_Avoid_: slug, id, handle

**Canvas**:
A Display's design surface, in pixels. Fixed when the Display is created and never
changed afterwards — the Builder edits at exactly these dimensions and the Viewer
renders at exactly these dimensions. A different size means a new Display.
_Avoid_: stage, artboard, resolution

**Deactivated Display**:
A Display retired from service with its layout preserved. Still editable by admins;
its Viewer states that the Display is turned off rather than rendering a layout.
_Avoid_: disabled, archived, hidden

**Grant**:
Permission for one account to edit one Display. A single flag — what the account may
do inside that Display comes from its role, and publishing is included. Admins hold
every Display without a grant.
_Avoid_: assignment, share, access level

### Authentication & access

**Account**:
A named user of the builder, stored as a row in `users` with a role. The unit
that a login lockout applies to.
_Avoid_: login, profile

**Failed login attempt**:
A sign-in POST for an existing account where the password does not verify.
Attempts against a username that matches no account are not counted.
_Avoid_: bad login, failure

**Attempt window**:
The single 15-minute span that governs brute-force protection. Failures older
than one window age out to zero, and a tripped lockout lasts exactly one
window.
_Avoid_: cooldown, timeout, rate-limit period

**Lockout**:
The state of an account frozen from signing in after 5 failed attempts inside
one attempt window. Absolute while active — even a correct password is
refused — and cleared by a successful login or a completed password reset.
_Avoid_: ban, block, suspension

### Canvas & content

**Text block**:
A canvas element that displays words. Its content is **plain text** — styling
(font, size, colour, weight, alignment) comes from Brand Standards and the
block's own properties, never from markup inside the text. See
docs/adr/0002.
_Avoid_: label, caption, rich text

**Asset**:
A reusable library entry — a text snippet or an uploaded/referenced image or
video — that a canvas element can point to instead of carrying its own content.
_Avoid_: media, resource, file
