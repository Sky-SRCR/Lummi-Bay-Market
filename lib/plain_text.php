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
// ---- Why it is seven statements and not four --------------------------------
// The order is the whole of it, and three steps are load-bearing in a way nothing
// about them would say (§4bb):
//
//   * **Breaks are rewritten before the strip**, or strip_tags takes the line break
//     away with the `<br>` and runs two prices together on the sign.
//   * **A "<" that cannot begin a tag is neutralised first.** strip_tags is not a
//     parser: it enters tag mode at any "<" that is not followed by whitespace and,
//     if nothing closes it, deletes the rest of the value. `Kids <12 eat free`
//     reached the sign as `Kids` — silently, with no error and nothing to see in
//     the Builder, because the loss happened on the way into the database. So the
//     "<"s that HTML could never open a tag with are escaped before strip_tags is
//     allowed to look, and the decode below turns them back into characters. What
//     is left for strip_tags is exactly what a browser would treat as a tag.
//   * **Entities are decoded after the strip, never before.** A browser sends a
//     typed "<" back as `&lt;`, so decoding first would hand strip_tags the very
//     character the step above exists to keep away from it. The cost of that order
//     is that markup which arrives encoded decodes into text that *looks* like
//     markup and is stored that way. It is inert because every renderer draws
//     stored text with textContent (viewer.php:427, builder.php:1487) and never
//     innerHTML, which is ADR-0002 and is the thing that has to stay true.
//
// All three are pinned by checks in tools/selftest_layout.php rather than left to
// be rediscovered: every line here could be deleted in silence until decision #49.

// A "<" begins a tag only when a letter, "/", "!" or "?" follows it *and* something
// closes it before the next "<". Everything else — `<10`, `<$4`, `< 10`, a stray
// `<` at the end of a line — is a character somebody typed. Written as one negative
// lookahead so there is a single answer to "is this markup?", used nowhere else:
// two opinions about that question is how the class of bug above comes back.
if (!defined('PLAIN_TEXT_NOT_A_TAG')) {
    define('PLAIN_TEXT_NOT_A_TAG', '#<(?![a-zA-Z!/?][^<>]*>)#');
}

function toPlainText(string $s): string {
    $s = preg_replace('#<\s*br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</\s*(div|p|li|h[1-6])\s*>#i', "\n", $s);
    $s = preg_replace(PLAIN_TEXT_NOT_A_TAG, '&lt;', $s);   // keep "<10" out of strip_tags
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t]+\n/", "\n", $s);   // trailing spaces per line
    $s = preg_replace("/\n{3,}/", "\n\n", $s);   // collapse blank-line runs
    return trim($s);
}
