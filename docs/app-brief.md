# Lummi Bay Market Digital Signage — Application Brief

> A self-contained description of what this application is, what it can do, what it
> deliberately does not do, and what it takes to install and run. Written to be pasted
> into a conversation as background for a marketing plan, cost analysis, competitor
> report or SWOT analysis. It contains no build instructions and cites no internal
> section numbers.
>
> Status date: 2026-08-20.

---

## 1. In one paragraph

Lummi Bay Market Digital Signage is a self-hosted, browser-based digital-signage
system built for retail pricing boards. A staff member designs a sign in a
drag-and-drop **Builder**, presses **Publish**, and a fullscreen **Viewer** page
running on a TV, kiosk or embedded signage widget picks up the new layout within 30
seconds. One installation drives any number of signs, each with its own canvas size,
its own layout, its own venue branding and its own list of people allowed to edit it.
It runs on ordinary shared web hosting — PHP and MySQL, no framework, no build step,
no cloud service, no per-screen licence — and the only screen-side requirement is a
browser that can open a URL.

It was built for a specific customer (a tobacco/nicotine pricing display at the Lummi
Bay Market, on the Silver Reef property) and has since generalised: the property has
signs in several venues — restaurants, bars, a casino floor — each already branded in
the physical world, and the system now models that directly.

---

## 2. The problem it solves

The starting situation is the common one in owner-operated retail and hospitality:

- Prices and menus change often; the sign is a TV showing something someone made once.
- Updating it means finding the person who has the file, or the software, or the login.
- Commercial signage platforms charge per screen per month, ship a player appliance,
  and expect a media-playlist mental model ("a schedule of assets") rather than a
  pricing-board mental model ("a layout of priced items").
- Multi-venue properties end up either with one look across everything, or with a
  separate silo per venue and nobody able to see all of it.

This system's answer: the sign is a web page, the layout lives in one database, the
people who own the prices edit them directly, and a venue's identity is a reusable
object rather than a copy-paste job.

---

## 3. How it works

Four nouns carry the whole model.

| Noun | What it is |
|---|---|
| **Display** | One configured sign — its name tag, canvas size in pixels, title, location, background, layout, venue Brand, and the list of accounts allowed to edit it. The unit an admin creates, edits and retires. |
| **Screen** | The physical hardware — TV, kiosk, embedded widget. Configured once, on site, with the URL of one Display. |
| **Viewer** | The single public page that renders one Display fullscreen. Login-free. Which sign it shows comes from the name tag in its URL. |
| **Builder** | The drag-and-drop canvas editor for one Display, working at exactly that Display's pixel dimensions. |

The working loop:

1. An admin creates a Display, choosing its dimensions and its short **screen name
   tag** (the word that appears in the Viewer URL). Dimensions are fixed at creation;
   a different size means a new Display. Sizes are arbitrary and include portrait.
2. Someone opens the Builder for that Display, which takes its **edit lock**. Anyone
   else who opens the same Display gets it read-only — the editing controls are not
   drawn at all, rather than drawn and refusing.
3. They lay out **sections** (top-level containers) and put text, image, video,
   carousel, marquee and table blocks inside them. Undo reaches back a configurable
   number of steps (default 5, maximum 20) over the canvas, in that browser tab.
4. **Publish** replaces that one Display's layout in a single database transaction. It
   refuses the write if the Display changed since the tab was opened, or if the edit
   lock has moved on. Nothing published is versioned and nothing can be taken back, so
   the system refuses conflicting writes rather than merging them.
5. Every Screen polls its Display's layout every 30 seconds and repaints. The canvas is
   scaled to whatever screen it is on; a shape mismatch letterboxes rather than
   distorting.

A Viewer URL always names its Display. A bare or unknown tag shows a plain notice
rather than some other sign's content, and a Display retired from service shows "this
display is turned off" with its layout preserved.

---

## 4. Capability inventory

### 4.1 Signs and canvases

- Unlimited Displays per installation; no per-screen licence or registration step.
- Arbitrary canvas dimensions per Display, portrait or landscape. The original
  drive-thru board's 1920×1080 is one Display's dimensions, not a system property.
- Per-Display background: a solid colour or an image.
- Per-Display title and location, for the humans running the property.
- Create a Display blank, or **duplicate** an existing Display of identical dimensions
  (positions, hidden/locked flags and section backgrounds are copied; the copy points
  at the same shared library assets rather than duplicating media).
- Rename a screen name tag behind a confirmation that shows the new URL to enter on the
  Screen.
- **Deactivate** a Display to retire it with its layout intact, or delete it (which
  requires typing the tag to confirm and destroys its layout and grants).
- Builder opens at zoom-to-fit, with Fit / 100% / − / + controls so a portrait canvas
  fits an editor window.

### 4.2 Content block types

- **Section** — the top-level container. Sections carry their own background and are
  the boundary the permission model uses.
- **Text** — plain text by design. Styling comes from the venue's Brand Standards and
  the block's own properties, never from markup pasted into the words. Text blocks
  carry a subtype (section header, item title, second item title, price, second price,
  description, or free), which is what makes a price on one sign look like a price on
  the next.
- **Image** — uploaded or referenced. SVG is rejected outright.
- **Video** — uploaded, for looping background or feature use.
- **Carousel** — a slideshow of library images.
- **Marquee** — a scrolling ticker line.
- **Table** — a grid, fillable from a pasted or imported file this application did not
  write.

Per-block controls: position and size by drag/resize with edge snapping and a floor,
multi-select and alignment tools, layer order (front/forward/backward/back), lock a
block against accidental movement, hide a block from the sign without deleting it, and
per-block colour, size, weight and alignment where the Brand does not fix them.

### 4.3 Asset library

- One shared library behind every Display: text snippets, uploaded images and videos,
  and referenced images.
- A canvas block can point at a library entry instead of carrying its own content, so
  one change updates every block pointing at it.
- Publishing auto-saves a text block's words into the library as an `Auto:` entry
  belonging to that one block — renaming it promotes it to an ordinary asset, which is
  how a person adopts it.
- **Tidy up** removes only auto-saved entries that no block on any Display uses. It
  never touches an asset a person made, renamed or uploaded, even when nothing uses it,
  and it changes nothing on any sign.
- The library reports, on hover, when a stored row holds something today's rules would
  refuse or change — because nothing silently rewrites old rows.
- Upload ceiling is the smallest of the application's 50 MB limit and the host's own two
  PHP upload settings, and the page states the real number rather than the application's
  opinion.

### 4.4 Brands — the customer-facing identity

- A **Brand** is a named, reusable visual identity for signs: typography standards,
  a colour palette, a venue logo and a default canvas background.
- Every Display wears exactly one Brand; the several Displays of one venue share it, so
  the identity is edited in one place rather than copied. Deleting a Brand still in use
  is refused, naming the signs that would be affected.
- **Brand Standards** are one typography setting per branded block type, applied to every
  block of that type on any Display using that Brand. A block cannot override them.
- **Brand palette** — up to six colours, offered as swatches wherever a colour is picked
  for that Display. Offered, never enforced: a block with its own colour keeps it.
- The Brand's logo is a named asset the Builder places in one click. The Viewer never
  draws it automatically, because a fixed corner and size cannot be right for both a
  landscape menu board and a portrait specials board.
- Editing a Brand is immediate — a saved change reaches every Screen wearing it within
  30 seconds, with no publish. Assigning a Brand to a Display is staged in the Builder:
  picking a venue repaints the canvas in the browser and is written by Publish.

### 4.5 Workspace Themes — the employee-facing appearance

- A **Workspace Theme** is the appearance of the application itself for one signed-in
  person: navigation, panels, work area, status colours and the canvas selection
  outline — 13 named roles in total.
- Admins create themes; anyone chooses one. "Use the store default" works from any
  state, and the picker always renders un-themed so it can never become unreachable.
- A theme never paints the canvas and never reaches a Screen or the sign-in page. The
  canvas is a picture of the sign and belongs to the Brand.
- Contrast problems are warned about, not refused — admins own their own legibility
  policy.

### 4.6 Accounts, roles and per-sign access

Two independent axes:

| Axis | Question it answers |
|---|---|
| **Grant** | *Which* Displays may this account open? One row per grant. Admins hold every Display by role and are never granted anything. |
| **Role** | *How much* may it do once inside? |

| Role | Can do |
|---|---|
| `admin` | Everything: Displays, accounts, grants, Brands, Workspace Themes, store branding, section layout, publish, assets, settings. |
| `basic` | Add and edit content *inside existing sections* only — not the section layout itself — and only on Displays granted to it. |

- Enforcement is server-side on every read and write, not only in the Builder's picker:
  a forged request naming an ungranted Display is refused, as is uploading to the shared
  library from an account holding no sign at all.
- The grant matrix save only changes what was actually on screen, so a save can never
  silently revoke access it was never shown.
- **Closing an account** is permanent and transactional: grants surrendered, edit lock
  released, out of the user list, but the row stays forever so its number can never be
  handed to somebody new. Username and email stay reserved; published work still names
  them. A returning employee gets a new account. Closing your own account, or the last
  admin who can still sign in, is refused.
- Any change to what somebody may reach — a revoked grant, a closed or suspended
  account, a demotion, a Display turned off — frees the edit lock they were holding in
  the same operation, and by holder, so a colleague on the same sign keeps theirs.

### 4.7 Collaboration and conflict handling

- **Edit lock** per Display, held by one account at a time, taken on opening the Builder
  and released by leaving or by 15 minutes without interaction. Everyone else gets
  read-only meanwhile, with an admin able to take the lock over.
- **Publish refuses stale writes.** If the Display moved on since the tab opened, or the
  lock has changed hands, the publish is refused with a sentence naming who published
  last and when — rather than merging two people's work.
- Refusals that can never come back (the account was closed, the grant was revoked, the
  Display was deleted) each get their own sentence, because what to do next differs.

### 4.8 Playback on the Screen

- The Viewer is a single public page, login-free, with no player software, no appliance
  and no device registration. Setting up a Screen means opening a URL.
- Polls every 30 seconds; picks up a publish unattended.
- Scales the canvas to the screen it is on, letterboxing rather than distorting.
- Works inside an embedded signage widget as well as a plain browser (the original
  installation drives both a TV and a third-party embedded widget from the same URL).
- If the server stops answering, the Screen shows a self-re-checking notice instead of
  a stack trace or a blank sign.
- Distinct, plain notices for: no display specified, unknown tag, Display turned off.

### 4.9 Store branding and administration

- **Site branding** — the store name, navigation colours and the address that password
  resets and alerts are sent from, edited in the admin panel.
- **Settings** — the store's timezone (chosen from real region names, so daylight saving
  is handled), and how many Undo steps the Builder offers.
- **This Server** card — what machine this is: PHP version, install path, which database
  it is connected to, the server's own clock, upload ceilings, with a note beside each
  reading that could be a problem.
- **Database Structure** card — whether the schema is actually in the state the code
  expects, table by table.
- **Schema convergence** — the application brings its own database up to date on the
  first authenticated request after an update, checking the catalogue first and sending
  only what is missing. There is no separate migration tool or maintenance window. A
  Screen's own failed read can converge the schema once after a deploy, so a sign comes
  up without waiting for somebody to sign in.
- **Error log and email alerts** — errors are logged to a rotating file and an admin is
  emailed about problems worth hearing about, rate-limited to one email per problem per
  hour so a recurring fault cannot flood an inbox.
- **In-app user guide** (`help.php`) covering every surface, written for the staff who
  edit signs rather than for developers.

### 4.10 Security posture

- Sessions with per-request cookie flags, CSRF protection on every state-changing form
  and endpoint.
- **Account-keyed login lockout**: 5 failed attempts inside a 15-minute window locks the
  account for 15 minutes, cleared by a successful login or a completed password reset.
  The front door answers closed / suspended / locked-out *before* it checks the password,
  so the message a person reads never varies with the password and a guesser is never
  told when they have got it right.
- **Password reset** by emailed 6-digit passcode, 30-minute lifetime, five guesses per
  code counted on the code's own row. Wrong code, no such account and budget spent are
  answered identically, so the page cannot be used to discover whether a username exists.
- Database credentials live outside the webroot and are never in the repository.
- Uploads validated by extension and MIME; SVG rejected to close stored-XSS.
- All data access is through prepared statements; the library and tools directories are
  denied to the browser.
- First-run setup page self-disables as soon as any account exists and then deletes
  itself, verifying the deletion rather than reporting success.
- The public poll runs no schema work, so the one endpoint reachable without an account
  cannot be used to make the database do work.

---

## 5. What it deliberately does not do

This list matters as much as the capability list for positioning, and every entry is a
recorded decision rather than an omission.

- **No version history and no rollback.** Publishing overwrites. Undo is a few steps
  over the canvas in one browser tab before a publish, and stops at the publish.
- **No preview.** Publish is the only path to a Screen.
- **No scheduling, dayparting or playlists.** A Display shows its current layout. There
  is no "breakfast menu until 11am" and no timed sequence of layouts.
- **No proof-of-play, analytics or audience measurement.**
- **No device management.** No player agent, no remote reboot, no screenshot-back, no
  "is the TV on?" monitoring. A Screen is a browser pointed at a URL, and the system
  does not know whether anyone is looking at it.
- **No offline playback.** A Screen that loses the network keeps showing its last
  successful render and displays a notice; it holds no local cache of media.
- **No rich text.** Sign content is plain text by design; styling comes from the Brand.
- **No canvas resizing.** A different size is a different Display.
- **No multi-tenancy.** One installation is one property. Venues are modelled as Brands,
  not as separate tenants with separate administrators.
- **No content approval workflow.** A `basic` account with a grant can publish to the
  sign it holds.
- **No integrations.** No POS, price-file, inventory, spreadsheet-sync or API-in feed;
  the table block importing a file is the closest thing to one. There is no outbound API
  or webhook.
- **No mobile editing story.** The Builder is a desktop-sized canvas editor.
- **No deploy pipeline.** Every code change reaches the sign by hand.

---

## 6. Technical profile

| Aspect | Detail |
|---|---|
| Server language | PHP, 8.2 floor, no framework |
| Database | MySQL (the production host runs 5.7); 9 tables |
| Front end | Inline CSS and JavaScript, no build step, no bundler |
| Third-party runtime dependency | one drag/resize library (`interact.js`) in the Builder; nothing else |
| Screen requirement | any browser that can open a URL |
| Hosting requirement | ordinary cPanel-class shared hosting with PHP, MySQL and mail |
| Code size | ~49,000 lines across the application, its library modules and its tooling; the Builder alone is ~6,200 lines, mostly inline JavaScript |
| Architecture | thin page scripts over ~30 single-responsibility library modules, one per table or concern; each table has exactly one module allowed to write SQL against it |
| Data access | PDO, real prepared statements, exceptions on error |
| Deployment | file upload plus one sign-in to converge the schema; no migration tool, no downtime window, no container, no queue, no cron |
| Verification | ~2,300 automated checks against six install configurations, ~960 checks that execute the Builder's and Viewer's own JavaScript, ~61 structural invariant checks, a deprecation compiler pass, a mutation-testing harness, and continuous integration across three PHP versions against both MySQL and SQLite |
| The one thing automation cannot cover | what a browser actually renders — closed by a documented manual walkthrough performed by a person |

---

## 7. Installation and setup

### 7.1 What an installation needs

- A web host running PHP 8.2 or newer with MySQL, PDO and `mail()`.
- One MySQL database and user.
- A writable directory for uploads and one for the error log.
- A directory outside the webroot for the database credentials file.
- Outbound mail from the host, for password resets and admin alerts.

No node, no composer, no build tooling, no external services, no API keys.

### 7.2 Standing it up

1. **Place the files** in a folder the web server serves — a subfolder such as
   `/lbm/` is fine and is how the production installation runs. Two of the folders
   ship with their own access rules that keep them unreachable from a browser; those
   must be kept.
2. **Write the credentials file** outside the webroot, defining host, database name,
   user and password. It is deliberately not part of the codebase.
3. **Create the schema** by loading the shipped schema file, which is safe to re-run —
   every statement is conditional. On an existing database, signing in once as an admin
   is what applies any newer structure and hands existing content to the right Display.
4. **Create the first admin** by visiting the setup page once. It disables itself as
   soon as any account exists and then deletes itself.
5. **Set the store's timezone and name** in the admin panel, and the address resets and
   alerts are sent from.
6. **Create the first Brand** (a fresh installation cannot finish without one) — its
   typography, palette, logo and default canvas background.
7. **Create the first Display**, choosing its pixel dimensions and its screen name tag.
8. **Configure the Screen** once, on site, with that Display's Viewer URL.
9. **Create staff accounts** and grant each one the Displays it should be able to open.

Typical time to a working first sign on a host that already exists: under an hour,
most of it deciding the Brand and the layout rather than installing anything.

### 7.3 Updating an existing installation

- Upload the changed files; sign in once as an admin, which converges the database.
- Three things live only on the server and must not be overwritten or deleted by an
  upload: the generated branding file, the uploads folder, and the log folder — plus the
  setup page, which was deleted on purpose and must not be restored. The project keeps a
  written skip-list and a post-upload check-list for exactly this, because a mirroring
  upload client will silently undo all four.
- There is no maintenance window: existing Screens keep rendering their last published
  layout throughout, and pick the new one up on their next poll.

### 7.4 Scaling a second sign, or a second venue

- A new sign: create a Display, point a Screen at its URL. Nothing to install, no
  licence to buy, no device to enrol.
- A new venue: create a Brand, create that venue's Displays against it. Editing the
  Brand once repaints every sign in that venue within 30 seconds.

---

## 8. Current status and maturity

Honest reading, as of 2026-08-20:

- **In production, in its original form.** The single-sign application has driven the
  Lummi Bay Market drive-thru pricing board for over a year, on the live host, and
  continues to.
- **The multi-sign, multi-venue build is complete, merged and running — on a rehearsal
  installation.** It sits beside the live sign on the same production host, against a
  copy of the live database, and has been driving a screen and walked through in a
  browser by the store owner. The live sign itself has **not** yet been cut over; that
  is a single on-site deployment visit, documented step by step, and it is the one
  substantial item outstanding.
- **Verification is unusually thorough for an application of this size** — thousands of
  automated checks, mutation testing, structural invariant enforcement, continuous
  integration across three PHP versions and both database engines — and the project is
  explicit that none of that is a substitute for a person opening the pages, which is why
  a manual browser walkthrough is a tracked, repeatable artefact with its own outcome
  table.
- **Documentation is a deliverable, not an afterthought.** A domain glossary, a standing
  architectural contract, eleven decision records with their rejected alternatives, a
  deployment skip-list, a parallel-work guide, an in-app user guide, and a written
  history of every defect found and what it taught.
- **Known outstanding manual verification:** the walkthrough is owed again against the
  live sign after cutover, and three of the newest surfaces (venue branding admin, the
  Builder's Brand control, Workspace Themes) have never been walked by a person at all.

---

## 9. Cost structure

### 9.1 What it costs to run

| Line | Detail |
|---|---|
| Software licence | none — no per-screen, per-seat or per-installation fee |
| Hosting | one cPanel-class shared-hosting account with PHP + MySQL; in this case the property already had one, so marginal cost is effectively zero |
| Database | included in the hosting account |
| Media/CDN | none — media is served from the same host; a CDN sits in front of the production domain already |
| Third-party services | none. No SaaS dependency, no API keys, no subscription |
| Screen hardware | a TV or kiosk plus something that runs a browser. No proprietary player appliance, no signage stick, no enrolment fee |
| Network | ordinary broadband at each screen location. Bandwidth per screen is one small layout request every 30 seconds plus media, cached |
| Mail | the host's own `mail()`; no transactional-email provider |
| Cost per additional screen | the hardware, and nothing else |
| Cost per additional venue | nothing |

### 9.2 What it costs to own

- **No deploy pipeline**: every code change reaches the sign by hand, by someone who can
  upload files and knows the skip-list. This is the main recurring operational cost.
- **No device management**: nobody is told when a screen goes dark. Discovery is someone
  walking past it.
- **Manual browser verification** before a change reaches a sign — deliberately a human
  step, and the one that does not scale with more automation.
- **Bus factor**: the codebase is small, documented and framework-free, which makes it
  cheap for one competent PHP developer to hold and expensive to hand to a team that
  expects a framework.
- **The database is edited in place** and there is no version history of published
  layouts, so backups are the only recovery path for content.

### 9.3 What a commercial alternative would cost instead

For comparison work, the relevant shape is: mainstream cloud signage platforms charge per
screen per month, generally with a player appliance or a certified smart-TV app, plus
setup. A property with several venues and a dozen boards is therefore a recurring
four-figure annual line under that model and a zero-recurring line under this one — in
exchange for the capability gaps in section 5 (no scheduling, no proof-of-play, no device
monitoring, no offline cache, no vendor support contract).

---

## 10. Positioning material

### 10.1 Who it fits

- Owner-operated retail with prices that change: convenience stores, smoke shops,
  bottle shops, delis, cafés, drive-thrus.
- Multi-venue single-property operators — a casino, resort, hotel or campus with
  restaurants, bars and floor signage that each need their own look.
- Organisations that already run their own web hosting and would rather own the system
  than rent it.
- Situations where the people who know the prices should edit the sign directly, with
  per-sign access control keeping them out of everyone else's boards.

### 10.2 Who it does not fit

- Anyone who needs scheduled or dayparted content, playlists, or campaign flighting.
- Anyone who needs proof-of-play or audience analytics — advertising networks especially.
- Anyone with screens on unreliable networks that must keep playing offline.
- Distributed estates across many sites and time zones needing device fleet monitoring.
- Buyers who require a vendor support contract, an SLA, or a certified smart-TV app.
- Anyone wanting to edit signs from a phone.

### 10.3 The competitive landscape, in categories

- **Cloud signage platforms** (per-screen subscription, player app or appliance,
  scheduling, playlists, device monitoring, proof-of-play).
- **Open-source self-hosted signage servers** (free software, but a server component plus
  a player component to install, maintain and pair; media-playlist model).
- **Smart-TV built-in signage modes** (no extra hardware; limited layout control, vendor
  lock-in per TV brand).
- **Nothing / PowerPoint on a USB stick** — realistically the incumbent in most of the
  target segment, and the actual competitor to displace.
- **Custom web pages on a TV** — the same idea as this system without the builder,
  branding, access control or conflict handling.

### 10.4 Differentiators to lead with

1. **Zero recurring cost and zero per-screen fee.** Runs on hosting the customer likely
   already pays for.
2. **No player software of any kind.** A Screen is a browser opening a URL. Setup is one
   URL, once, on site.
3. **Built as a pricing board, not a media playlist.** Text blocks carry roles — section
   header, item title, price, description — and a venue's typography is enforced across
   every sign wearing that Brand, so a price looks like a price everywhere.
4. **Brands as first-class objects.** Multi-venue identity is edited once and reaches
   every sign in that venue within 30 seconds, with no publish step.
5. **Per-sign access control on two axes** — which signs, and how much power — enforced
   server-side, so the bar manager can be given the bar's boards and nothing else.
6. **Conflict safety over convenience.** One editor per sign, stale publishes refused
   with a sentence naming who published last, read-only mode that hides controls rather
   than disabling them.
7. **Runs anywhere PHP runs**, including the shared hosting that small operators already
   have — no container, no queue, no cron, no cloud account.
8. **Self-updating database.** Uploading new files and signing in once is the whole
   upgrade; no migration tool and no maintenance window.

### 10.5 Objections to expect

- "What happens when the screen goes dark and nobody notices?" — nothing monitors it.
- "Can I schedule the happy-hour board?" — not today.
- "Can I roll back a bad publish?" — no; publishing overwrites.
- "Who supports this?" — whoever owns the code. There is no vendor.
- "Is it secure enough to be public?" — the Viewer is deliberately login-free; anyone
  with a sign's URL can view that sign, and the admin screen says so plainly.

---

## 11. SWOT raw material

**Strengths**

- Zero licence and near-zero marginal hosting cost; no per-screen fee.
- No player software, appliance or device enrolment — the lowest possible screen-side
  setup cost.
- Purpose-built for pricing boards, with enforced typography roles rather than generic
  media placement.
- Reusable venue Brands: multi-venue identity edited in one place, live in 30 seconds.
- Genuine per-sign, two-axis access control enforced on the server.
- Deliberate conflict-safety model (edit lock, stale-publish refusal, read-only by
  omission) that most small tools do not attempt.
- Unusually strong verification and documentation for its size: thousands of automated
  checks, mutation testing, invariant enforcement, CI across PHP and database versions,
  decision records, a domain glossary, an in-app user guide.
- Small, framework-free, dependency-light codebase — cheap to host, cheap to read, no
  supply chain to patch.
- Self-converging database: upgrades are an upload plus a sign-in, with no downtime.
- Already proven in production for over a year in its original form, on real hardware,
  with a real owner using it.

**Weaknesses**

- No scheduling, playlists or dayparting.
- No version history and no rollback of a publish.
- No preview before publish.
- No device monitoring, remote screenshot or alerting when a screen dies.
- No offline playback or local media cache.
- No integrations, no inbound price feed, no outbound API.
- No approval workflow — a granted `basic` account publishes straight to the sign.
- Desktop-only editing; no mobile Builder.
- Single-tenant: one installation per property, and no separation of administrators.
- Every deployment is manual, with a documented but human skip-list; no deploy pipeline.
- The newest surfaces have not yet been verified by a person in a browser, and the
  multi-sign build has not yet been cut over to the live sign.
- Bus factor of one; no vendor, no SLA, no support contract.
- Tied to PHP + MySQL shared hosting, which is a liability with buyers who standardise
  on managed cloud.

**Opportunities**

- The realistic incumbent in the target segment is a static image or nothing, which is
  an easy displacement.
- Multi-venue single-property operators (casinos, resorts, hotels, campuses, food halls)
  are underserved by both per-screen SaaS pricing and one-look-fits-all tools.
- The missing capabilities are individually small and well-scoped against a clean
  architecture: scheduling, publish history, preview, a heartbeat/health check, a price
  import, and a mobile-friendly read view are all additive.
- A screen-health heartbeat plus email alerting would close the single most common
  objection cheaply, and the alerting infrastructure already exists.
- Publish history is a natural paid or premium differentiator against free self-hosted
  alternatives.
- Regulated-category retail (tobacco, nicotine, cannabis, alcohol) has pricing-display
  compliance pressure and often cannot use ad-network-oriented platforms.
- The system is already generalised beyond its original store; packaging it as a
  self-hosted product or an installed-and-managed service for similar operators is a
  short step.
- Documentation quality makes it unusually credible to hand to another developer or to
  sell as a maintainable asset.

**Threats**

- Smart TVs shipping capable built-in signage modes erode the "no player hardware"
  advantage.
- Cloud platforms discounting entry tiers narrows the cost gap for very small estates.
- Shared-hosting PHP as a platform is in slow decline; a host changing its PHP version or
  retiring `mail()` is an operational risk.
- Public login-free Viewer URLs are guessable by design; a competitor or a customer's
  security reviewer may object.
- No rollback plus in-place database editing means one bad publish or one bad backup is
  unrecoverable content loss.
- Manual deployment is the most likely source of a live outage, and the failure mode is a
  blank sign in a shop.
- Buyers who require an SLA, certifications or vendor indemnity are structurally
  unreachable without becoming a vendor.
- Key-person dependency: the value is concentrated in one codebase held by one person.
