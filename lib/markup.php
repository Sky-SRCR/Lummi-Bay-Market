<?php
// ============================================================
// WHAT IT TAKES TO PUT A STORED VALUE ON A PAGE
// ============================================================
// One door, for the same reason `lib/color.php` is one door: the rule was written
// out 159 times and every one of the 159 left the decision to a default.
//
// `htmlspecialchars($v)` is not one behaviour. Before PHP 8.1 the default flag set
// was `ENT_COMPAT | ENT_HTML401`, which escapes `"` and leaves `'` alone; from 8.1
// it is `ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401`, which escapes both. So an
// attribute written as `value='{{ htmlspecialchars($x) }}'` is either safe or an
// injection depending on which PHP the host is running, and nothing in the source
// says which was meant. Which PHP this app is on is unverified (#51), and the repo
// is written 7.1-compatible for that reason (BUILD-REFERENCE §5) — so "the default is
// fine now" is exactly the assumption not to leave lying around. Both flags are named
// here so the behaviour is the same on either side of 8.1 and does not depend on
// answering that question first.
//
// The second half of the default is quieter and worse. Without `ENT_SUBSTITUTE`,
// one byte of invalid UTF-8 makes `htmlspecialchars()` return **the empty string**
// — not the value, not an error, nothing. That is #26's shape again: a stored
// character nobody typed on purpose, and a page that silently stops showing a
// price. With it, the bad byte becomes U+FFFD and the rest of the value survives.
//
// The short-echo tags in the examples below are written `{{ … }}` rather than as
// themselves, because a real `?` followed by `>` inside a `//` comment ends PHP mode
// — the rest of this header would print onto the page, and the file would still lint
// clean. Caught here by the self-test simply loading the file, which is the only gate
// that could have.
//
// So the flags are named here once and not spelled at a call site again;
// `tools/check_invariants.php` holds `htmlspecialchars(` to this file.
//
// **`text()` is not a sanitiser.** It does not strip tags, it escapes them. What
// this app stores is plain text by ADR-0002 and `lib/plain_text.php` is what makes
// it so; this file is about the last inch, where text becomes markup.

require_once __DIR__ . '/http_reply.php';

class Markup
{
    /**
     * Every flag this app's escaping depends on, stated rather than defaulted.
     *
     * `ENT_QUOTES` because both quote characters have to go: an attribute may be
     * written with either, and which one is at the call site is not this function's
     * business. `ENT_SUBSTITUTE` because a value must not vanish over one bad byte.
     * `ENT_HTML401` is left to the default deliberately — under `ENT_HTML5` a
     * single quote comes out as `&apos;`, which an older parser does not know, and
     * the numeric `&#039;` this produces is understood everywhere.
     */
    const FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    /**
     * A stored value as page text or as an attribute value.
     *
     * One function for both, because with `ENT_QUOTES` there is no difference in
     * what has to be escaped, and a second name would imply a second rule that does
     * not exist. Where the two genuinely differ is inside a `<script>` or an event
     * attribute, and that is `jsInAttr()` below and `HttpReply::jsValue()`.
     *
     * Non-strings answer '' rather than being cast, for the reason `Color::read()`
     * does: `(string)` on an array is the warning "Array to string conversion" and
     * the literal text `Array`, printed into the page. A number is text and passes
     * through; anything else is a value with nothing to escape, and printing
     * nothing is the answer that cannot be a surprise. `null` in particular reaches
     * here from every nullable column — a Display with no location — and passing it
     * to `htmlspecialchars()` is a deprecation notice on 8.1 and later, logged on
     * every page load, saying nothing anybody can act on.
     *
     * @param mixed $value
     * @return string safe to place in an element or inside either kind of quote
     */
    public static function text($value)
    {
        if (is_string($value)) {
            return htmlspecialchars($value, self::FLAGS, 'UTF-8');
        }
        if (is_int($value) || is_float($value)) {
            return htmlspecialchars((string)$value, self::FLAGS, 'UTF-8');
        }
        return '';
    }

    /**
     * A stored value as a JavaScript expression inside an HTML attribute.
     *
     * Two escapings, in the order the browser undoes them, which is the part that
     * is easy to get wrong and was got wrong: `onsubmit="return confirm('Close the
     * account for {{ htmlspecialchars($name) }}?')"` looks escaped and is not.
     * The HTML parser decodes the attribute *before* the JavaScript parser sees it,
     * so `&#039;` — the very entity `ENT_QUOTES` produced — is back to a plain `'`
     * by the time it is a string literal, and the string ends there. A username of
     * `o'brien` breaks the page; one chosen with more intent does worse. That was
     * the confirm box #15 names, and the flags were never the half that mattered.
     *
     * `HttpReply::jsValue()` produces the literal — a JSON string with `'`, `"`,
     * `<`, `>` and `&` all as `\uXXXX` escapes, so nothing in it can end anything —
     * and `text()` then escapes the quotes that delimit it, which are the only
     * characters left that the attribute cares about.
     *
     * Used as the whole argument, never spliced into a longer string:
     *
     *     onsubmit="return confirmCloseAccount({{ Markup::jsInAttr($name) }})"
     *
     * The sentence belongs in the function, in JavaScript, where no escaping
     * question arises at all.
     */
    public static function jsInAttr($value)
    {
        return self::text(HttpReply::jsValue($value));
    }
}
