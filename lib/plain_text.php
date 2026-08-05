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

function toPlainText(string $s): string {
    $s = preg_replace('#<\s*br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</\s*(div|p|li|h[1-6])\s*>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t]+\n/", "\n", $s);   // trailing spaces per line
    $s = preg_replace("/\n{3,}/", "\n\n", $s);   // collapse blank-line runs
    return trim($s);
}
