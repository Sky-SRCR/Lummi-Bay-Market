# Lummi Bay Market — Digital Signage

A PHP + MySQL display-builder for the Lummi Bay Market's pricing signs: someone
designs a layout on a Display's canvas, publishes it, and a fullscreen Viewer on
a Screen auto-refreshes to show it. One installation drives any number of signs,
each with its own canvas size and its own list of people allowed to edit it.

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
Screen depends on. It is an *address*, not an identity: rename a Display and the
word it gave up can be handed to another sign, so a page that has been open a while
may hold a tag that now means somebody else's screen. That is why the Builder sends
the Display's id alongside its tag and the server refuses any request where the two
disagree.
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

**Edit lock**:
The right to edit one Display, held by a single account at a time. Taken on opening
the Display in the Builder and released by leaving or by 15 minutes without
interaction. Anyone else may open that Display read-only meanwhile — read-only means
the editing controls are not rendered at all, not that they are rendered and refuse.
_Avoid_: checkout, reservation, session

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

**Closed account**:
An account retired permanently. It cannot sign in, holds no grant and no edit lock,
and is out of the user list — but the row stays forever, so its account number can
never be handed to somebody new and quietly inherit what pointed at it. The username
and email stay reserved, and anything the person published still names them. There is
no reopening: a returning employee gets a new account.
_Avoid_: deleted, removed, deactivated

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
(font, size, colour, weight, alignment) comes from the Brand Standards of the
Display's Brand and from the block's own properties, never from markup inside
the text. See docs/adr/0002.
_Avoid_: label, caption, rich text

**Asset**:
A reusable library entry — a text snippet or an uploaded/referenced image or
video — that a canvas element can point to instead of carrying its own content.
_Avoid_: media, resource, file

**Auto-saved entry**:
An Asset that publishing created, by copying a text block's words into the
library and pointing the block at the copy. Labelled `Auto: …` and badged
**auto** in the Asset Library. It belongs to the one block that caused it —
publishing never gives two Displays the same entry. Renaming one makes it an
ordinary Asset, which is how somebody adopts it.
_Avoid_: pooled row, duplicate, orphan, cache entry

**Tidy up**:
Removing auto-saved entries that no block on any Display uses. Never touches an
Asset a person made, renamed or uploaded, even when nothing uses it. Changes
nothing on any sign — which is why it needs no publish and no confirmation
beyond the count.
_Avoid_: clean up, garbage collect, prune, purge

### Branding & appearance

Two words that must never merge. A **Brand** is what a customer sees on a TV; a
**Workspace Theme** is what an employee sees while working. Nothing named
"theme" ever reaches a Screen.

**Brand**:
A named visual identity for signs — one venue's typography, colours, logo and
default canvas background. Reusable: every Display carries exactly one, and the
several Displays of a single venue share it, so the identity is edited in one
place rather than copied.
_Avoid_: theme, skin, style set, template, brand standard

**Editing a Brand** and **assigning** one are different acts, and only one of
them can be looked at first. Editing is immediate: a saved change to a Brand
reaches every Screen wearing it within thirty seconds, with no publish and no
undo. Assigning is **staged in the Builder** — picking a venue there repaints
the canvas in the browser and is written by Publish — and immediate from the
Admin Panel, which turns Displays off and deletes them from the same page.
_Avoid_: applying a brand, switching branding, brand preview mode

**Brand Standards**:
The typography half of a Brand — one setting per branded block type, applied to
every block of that type on any Display using that Brand. A block cannot
override them, which is what makes a price on one sign look like a price on the
next.
_Avoid_: block styles, global fonts, defaults

**Brand palette**:
The colours a Brand carries — up to six, in the order they are shown — offered
as swatches wherever a colour is picked for a Display. Offered and never
enforced: a block with its own colour keeps it. Deliberately an ordered list
rather than named roles, because a role ("this is the heading colour") is an
instruction, and an enforced palette is the option ADR-0011 rejected.
_Avoid_: theme colours, colour scheme, swatch set

**Workspace Theme**:
The appearance of the application itself for one signed-in person — navigation,
panels, work area, status colours and the canvas selection outline. Created by
an admin, chosen by anyone, and never visible on a Screen or on the sign-in
page. It never paints the canvas, which is a picture of the sign rather than
part of the application.
_Avoid_: builder theme, brand, skin, dark mode, colour preference

**The store default**:
What the application looks like to everybody who has not chosen a theme — Site
Branding's four colours plus the documented defaults for the other nine roles.
It is not a theme and deliberately not a row in the themes table: a copy of the
Site Branding file would disagree with it the first time somebody edited one.
Say "the store default", never "the default theme", and note that it is always
on the picker — including for somebody already on it, because a preference you
cannot reverse is not a preference. It is also what a theme falls back to one
role at a time: a value nobody can read leaves the store default in that place,
not the colour the app ships with, so the phrase means the same thing wherever
it is used.
_Avoid_: default theme, base theme, no theme, factory colours

**Chrome role**:
One of the sixteen things a Workspace Theme names a colour for. A role is a
place in the application — the navigation background, the work area, the panel
border, the outline around a selected block — never a mood or a shade. Thirteen
of them are surfaces and three are the **text** drawn on those surfaces, one per
surface: a theme that can repaint a panel has to be able to say what colour the
labels on it are, or it can make its own side navigation unreadable. Two of them
are worth keeping apart in speech: the **work area** is the dark space a
canvas sits on, and the **canvas** is the sign itself and belongs to the Brand.
_Avoid_: theme slot, variable, token, colour name
