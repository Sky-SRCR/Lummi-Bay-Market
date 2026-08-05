# Lummi Bay Market — Digital Signage

A PHP + MySQL display-builder for the Lummi Bay Market drive-thru pricing
sign: admins design a 1920×1080 layout, publish it, and a fullscreen viewer
on a TV auto-refreshes to show it.

## Language

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
