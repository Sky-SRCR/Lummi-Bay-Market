<?php
// ============================================================
// WHAT A COLOUR IS
// ============================================================
// One rule, in one place, for the only colour notation this app has ever stored:
// `#rrggbb`.
//
// It exists because the rule was written out four times and the four copies did
// not agree about what to do when a value failed it. `DisplayAdmin::cleanColor()`
// substituted `#1a1a2e`, `BrandStyles::cleanColor()` substituted `#ffffff`, the
// Branding form substituted whatever was already saved, and the Builder's
// `rgbToHex()` substituted `#000000`. Every one of them then reported success. So
// an admin who submitted a colour the app could not read was told their change was
// saved, and which colour they actually got depended on which form they were on
// (#21) — while a stored value nobody could read came back from the Builder as
// black and overwrote itself on the next publish (#41).
//
// Substituting is the bug, not the notation. This module therefore answers only
// the question, and never picks a colour: read() returns the colour or the empty
// string, and each caller decides what an empty answer means for it. A form
// refuses and says which field. The publish path refuses and says which block. A
// caller with a genuine default — a canvas that has to have *some* background —
// applies it explicitly, at the call site, where it is visible.
//
// Pure, so the self-test can cover it exhaustively: the same reason
// `layout_rules.php` and `schema.php`'s decision half are pure (BUILD-REFERENCE
// §4o).
//
// **Not a normaliser.** `read(' #ffffff ')` is empty, not `#ffffff`. Trimming,
// expanding `#fff`, or accepting `rgb(1,2,3)` would each widen what the app
// stores, and this change exists to stop the app guessing rather than to teach it
// new guesses. The accepted set is exactly the one the three old regexes shared,
// so nothing that used to be stored stops being storable.

class Color
{
    /**
     * The stored form of this colour, or '' when it is not one.
     *
     * Lowercased, because `#FFFFFF` and `#ffffff` are the same colour and storing
     * both makes two rows that differ only in case look like two colours.
     *
     * Non-strings answer '' rather than being cast. `(string)` on an array is the
     * warning "Array to string conversion" and the literal text `Array` — printed
     * above the document on a page that was only trying to validate a form, which
     * is how the old `preg_match('…', (string)$value)` behaved for a hand-built
     * POST of `bg_val[]=x`.
     *
     * @param mixed $value
     * @return string `#rrggbb` lowercased, or ''
     */
    public static function read($value)
    {
        if (!is_string($value)) { return ''; }
        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : '';
    }

    /**
     * Is this a colour this app can store?
     *
     * Blank is **not** a colour. Callers that treat "nothing supplied" as "leave it
     * alone" have to ask that question first and separately — the difference
     * between an absent value and an unreadable one is the whole of #21, and a
     * predicate that answered true for '' would collapse it again.
     */
    public static function isColor($value)
    {
        return self::read($value) !== '';
    }

    /**
     * The ratio below which this app calls two colours hard to read against each
     * other.
     *
     * 4.5:1 is WCAG 2.1's AA threshold for text at ordinary sizes, which is what
     * every string in this application's chrome is. It is a **warning** threshold and
     * never a refusal: decision 13 of the v2 roadmap gives an admin their own
     * legibility policy, and a rule that refused would be the enforced palette
     * ADR-0011 rejected wearing different clothes.
     */
    const READABLE_RATIO = 4.5;

    /**
     * How much these two colours stand apart, as WCAG's contrast ratio: 1.0 for two
     * identical colours, 21.0 for black on white.
     *
     * Throws rather than answering for a value that is not a colour, and that is the
     * same choice `SiteChrome::pick()` makes about an unknown role: both arguments
     * here have already been through `read()` at every call site — the theme form
     * refuses the save and names the field, exactly as the Branding form has since
     * #21 — so a non-colour arriving is a programming error, and an answer would be a
     * number somebody would then draw a warning from. `0.0` would read as "no
     * contrast at all", which is a sentence about the colours rather than about the
     * call.
     *
     * @param mixed $one `#rrggbb`
     * @param mixed $two `#rrggbb`
     * @return float between 1.0 and 21.0
     */
    public static function contrastRatio($one, $two)
    {
        $a = self::luminance($one);
        $b = self::luminance($two);
        $light = max($a, $b);
        $dark  = min($a, $b);
        return ($light + 0.05) / ($dark + 0.05);
    }

    /**
     * Would text in the first colour be hard to read on the second?
     *
     * The predicate the theme form warns through, so the threshold is named once and
     * the form does not carry a number of its own. Symmetric, because the ratio is.
     */
    public static function hardToRead($text, $background)
    {
        return self::contrastRatio($text, $background) < self::READABLE_RATIO;
    }

    /**
     * WCAG relative luminance, 0.0 for black and 1.0 for white.
     *
     * The channel transform is the standard's own: sRGB values are gamma-encoded, so
     * averaging the bytes would call `#808080` half as bright as white when a person
     * sees it as about a fifth. Private because a luminance is not something this app
     * has any other use for — the ratio is the question.
     */
    private static function luminance($value)
    {
        $hex = self::read($value);
        if ($hex === '') {
            throw new InvalidArgumentException(
                'Not a colour, so it has no contrast: ' . self::describe($value) . '.');
        }
        $channels = [];
        foreach ([1, 3, 5] as $at) {
            $c = hexdec(substr($hex, $at, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * The submitted value as a refusal message can safely quote it.
     *
     * A refusal has to name what was wrong or the admin cannot tell a typo from a
     * stale form, but the value came from a request and lands in a sentence that
     * is rendered — so it is described, never echoed whole: shortened, with its
     * type named when it is not a string.
     */
    public static function describe($value)
    {
        if (is_array($value))  { return 'a list of values'; }
        if (is_bool($value))   { return $value ? 'true' : 'false'; }
        if ($value === null)   { return 'nothing'; }
        if (!is_string($value)) { return gettype($value); }
        if ($value === '')     { return 'blank'; }
        return strlen($value) > 20 ? '"' . substr($value, 0, 20) . '…"' : '"' . $value . '"';
    }
}
