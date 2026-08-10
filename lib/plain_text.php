<?php
// ============================================================
// SIGNAGE TEXT SANITISER
// ============================================================
// Text-block content is plain text everywhere (docs/adr/0002). This strips any
// markup a browser could execute before it is stored, while keeping intended
// line breaks; rendering then uses textContent, so stored text is always shown
// literally — belt and suspenders against stored XSS.
//
// Lives in lib/ so the layout store can include it without pulling in a session
// (see docs/BUILD-REFERENCE.md §1). auth.php includes it too, so every existing
// caller of toPlainText() keeps working unchanged.
//
// The order of the six lines is the whole of it, and two steps of it are
// load-bearing in a way nothing about them says (§4am):
//
//   * **Breaks are rewritten before the strip**, or strip_tags takes the line break
//     away with the `<br>` and runs two prices together on the sign.
//   * **Entities are decoded after the strip, never before.** A browser sends a
//     typed "<" back as `&lt;`, and strip_tags removes everything from a "<" to the
//     end of the value when nothing closes it. Decoding first would therefore
//     delete the rest of the line — on the sign, silently, for a price nobody
//     mistyped. The cost of that order is that markup which arrives encoded
//     decodes into text that *looks* like markup and is stored that way. It is
//     inert because every renderer draws stored text with textContent
//     (viewer.php:502, builder.php:1495) and never innerHTML, which is ADR-0002 and
//     is the thing that has to stay true.
//
// Both are pinned by checks in tools/selftest_layout.php rather than left to be
// rediscovered: every line here could be deleted in silence until decision #49.

function toPlainText(string $s): string {
    $s = preg_replace('#<\s*br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</\s*(div|p|li|h[1-6])\s*>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t]+\n/", "\n", $s);   // trailing spaces per line
    $s = preg_replace("/\n{3,}/", "\n\n", $s);   // collapse blank-line runs
    return trim($s);
}
