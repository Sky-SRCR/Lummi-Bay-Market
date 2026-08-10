# Signage text blocks are plain text (no inline HTML)

## Status

accepted

## Context

Text-block content was authored in a `contenteditable` rich-text editor and
stored as raw HTML, then rendered with `innerHTML` in both the public viewer
and the builder. Because the stored string was never sanitised, any
logged-in user (including a `basic` user) could store markup that executed as
script — a stored XSS. The builder sink was the more dangerous of the two: the
payload ran in an **admin's** authenticated session when they opened the
editor, next to the admin's CSRF token, enabling account takeover.

## Decision

Treat text-block content as **plain text everywhere**. Render it with
`textContent` (viewer and builder) so stored text can never execute, and strip
any markup server-side when it is saved (the publish path in `lib/layout_store.php` and `crud.php`
assets) via `toPlainText()`. Author line breaks are preserved with
`white-space: pre-wrap`. The inline rich-text formatting toolbar
(bold/italic/underline/strike) was removed, since that formatting could no
longer survive a save. Per-block typography (font, size, colour, weight,
alignment) is unaffected — it comes from Brand Standards / the `text_align`
column, not from HTML inside the text.

## Considered options

- **Allow-list HTML sanitiser** (keep rich text, strip only dangerous
  tags/attributes) — rejected. It keeps a complex, easy-to-get-wrong parsing
  surface for a feature the store does not use; the live layout contains no
  hand-formatted text. Plain text removes the entire class of bug instead of
  filtering it.

## Consequences

- Editors can no longer bold/italic *part* of the text inside one box. Whole-
  block styling via Brand Standards is unchanged, so the live sign looks
  identical.
- Defence in depth: even legacy HTML already sitting in the database renders
  as inert text, because the output side no longer uses `innerHTML`. Verified
  in a real browser against a payload injected directly into the database.
- Non-text elements (carousel/table/marquee JSON, image/video paths) are NOT
  stripped — only `type = 'text'` content passes through `toPlainText()`.
- **"Strip any markup" is not the same as calling `strip_tags()`, and the
  difference cost a price line.** `strip_tags()` is not a parser: it enters tag
  mode at any `<` not followed by whitespace and, with nothing closing it,
  deletes the rest of the value. `Kids <12 eat free` was stored as `Kids` —
  on the way in, with nothing to see in the builder and no undo. Found by
  mutation-testing this file under decision #49 and fixed there:
  `toPlainText()` now escapes every `<` that HTML could not open a tag with
  before the strip, and the entity decode already at the end restores it. What
  reaches `strip_tags()` is exactly what a browser would treat as markup, so
  nothing about the decision above changes — only the accuracy of "markup".
  See BUILD-REFERENCE §4am; the rejected fix is swapping the decode in front of
  the strip, which is the one that loses the line.
