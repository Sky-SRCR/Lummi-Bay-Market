<?php
// ============================================================
// WHAT A NAME ON A PICKER IS
// ============================================================
// A Brand and a Workspace Theme are unrelated nouns — one is what a customer sees
// on a TV, the other is what an employee's screen is painted in, and `CONTEXT.md`
// keeps them apart on purpose. What they share is the shape of their *name*: a
// person picks one out of a list of them, so the name has to be something that
// person can tell apart from the one below it.
//
// That rule was written for Brands in step 3 and would have been written a second
// time for themes in step 5. Two copies of a rule is how the two of them come to
// disagree — the reason `schema.php` builds its ENUMs out of `LayoutRules` and the
// reason `BrandStyles::STARTING_POINTS` has three readers and one home. So it is
// here once, and `BrandStore` asks rather than answering: its two method names stay
// exactly as they were, because they are what every caller and every check already
// says.
//
// It is deliberately **not** a general "clean this string" helper. The two questions
// below are the ones a picker asks and nothing else: fold a typed name toward a
// usable one without inventing one, and say whether what is left can be told apart
// on a list. Anything more and it becomes the fourth place colours got substituted.
//
// Pure, for the reason `layout_rules.php` is (BUILD-REFERENCE §4o): the self-test can
// put every shape through it without a database.

class PickerName
{
    /**
     * How long a name may be, in bytes.
     *
     * Both tables declare `VARCHAR(80)`, and the check is `strlen` while the column
     * counts *characters*, so this is stricter than either column for a name with
     * accents in it and never looser. Being refused at 80 bytes is a sentence; being
     * truncated at 80 characters by MySQL is a name nobody chose.
     */
    const MAX = 80;

    /**
     * Fold input toward a usable name without inventing one: trim, collapse runs of
     * whitespace.
     *
     * Anything that is not a string is not a name badly written — it is not a name
     * (#27). `(string)$array` yields the word "Array", which is a perfectly valid
     * name, so the caller would go on to create a row nobody asked for.
     */
    public static function clean($name)
    {
        if (!is_string($name)) { return ''; }
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * A name a person can tell apart from another one on a picker.
     *
     * Deliberately permissive about *which* characters — these are venue names and
     * theme names, and "Tavern & Grill", "Café 12" and "Night shift (dim)" are the
     * ordinary case. What is refused is the empty name, one longer than the column,
     * and control characters, which are invisible and would make two names that look
     * identical be different rows.
     */
    public static function isValid($name)
    {
        if (!is_string($name) || $name === '') { return false; }
        if (strlen($name) > self::MAX)         { return false; }
        return preg_match('/[\x00-\x1f\x7f]/', $name) !== 1;
    }

    /**
     * The *other* row already using this name, out of a list of things that have a
     * `name()`, or null.
     *
     * Compared case-insensitively in PHP rather than left to the database, because
     * the two engines disagree: MySQL's default collation makes "Salmon House" and
     * "salmon house" the same name and refuses the second with a unique-key error,
     * and SQLite's does not. A rule that answers differently per engine is a rule the
     * self-test cannot state.
     *
     * Answers the row rather than a yes/no so the refusal can quote **its** name
     * instead of the one that was typed. Those differ exactly when the comparison did
     * the work it exists for — somebody typing "salmon house" is told the clash is
     * with "Salmon House", which is the string they will actually find in the list.
     * A predicate could only ever echo the input back.
     *
     * @param array $rows   things with id() and name()
     * @param mixed $name   what was typed
     * @param int   $except an id that may keep its own name (a rename)
     * @return mixed the clashing row, or null
     */
    public static function clashIn(array $rows, $name, $except = 0)
    {
        $name = self::clean($name);
        if ($name === '') { return null; }
        foreach ($rows as $row) {
            if ($row->id() === intval($except)) { continue; }
            if (strcasecmp($row->name(), $name) === 0) { return $row; }
        }
        return null;
    }
}
