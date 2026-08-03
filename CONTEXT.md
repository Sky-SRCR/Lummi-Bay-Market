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
