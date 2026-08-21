<?php
// ============================================================
// WORKSPACE THEMES — WHAT ONE PERSON'S SCREEN IS PAINTED IN
// ============================================================
// The second of `CONTEXT.md`'s two nouns, and never the other one. A **Brand** is
// what a customer sees on a TV; a **Workspace Theme** is what an employee's screen
// is painted in. One reaches every Screen in a venue the moment it is saved; the
// other reaches exactly one person's browser and no sign, ever. Decision 1 of the v2
// roadmap says they are never one word, and this file is the half of that which the
// database can enforce: there is no column here for anything drawn on a canvas.
//
// **Nothing outside this file writes `workspace_themes`** (invariant 36), the same
// rule `brands`, `displays`, `assets`, `block_styles` and `canvas_elements` each
// have. The one thing that looks like an exception is the *choice* — which theme an
// account picked — and that is a column on `users`, written by `AccountStore`,
// because it is a fact about the account rather than about the theme. This file
// reads it, joined, because the read happens on every signed-in page load and two
// round trips to answer one question is a cost paid on every request.
//
// **What resolution this file does not do.** It answers rows and lists. Which colour
// a role ends up being — a theme's value, then the config file's, then the documented
// default — is `SiteChrome`'s, and that layering lives in one function there so that
// a page, a form and a check cannot each hold a version of it. So this module has no
// opinion about what an unreadable colour becomes; it can only say which ones it
// found, which is `unreadable()` below and the same shape `Brand::unreadablePalette()`
// has for the same reason (#21: a substitute nobody is told about).

require_once __DIR__ . '/color.php';
require_once __DIR__ . '/picker_name.php';
// For `ROLES`, which is the list of sixteen this file's columns are named after, and
// for the labels a refusal quotes. The dependency runs one way on purpose:
// `site_chrome.php` names `WorkspaceTheme` in a type hint but requires nothing back,
// exactly as `DisplayStore::applyBrand()` names a `BrandChoice`.
require_once __DIR__ . '/site_chrome.php';
// For `isIdLike()`. A theme id arrives from a posted form like every other id in this
// app, and `intval("7abc")` is 7 — so a mangled one would not fail, it would name a
// different theme.
require_once __DIR__ . '/displays.php';

/**
 * One Workspace Theme, as the app talks about it.
 *
 * A value object built from a `workspace_themes` row, never stored. `colorFor()` is
 * the only interesting method and it deliberately answers **what is in the row**
 * rather than what will render — the opposite of `BrandStyles::readable()`, and for a
 * reason: this is the innermost layer of a resolution `SiteChrome` owns, and a value
 * object that quietly substituted a default here would make the layering unobservable
 * from outside. `unreadable()` is how the row gets named on a screen.
 */
class WorkspaceTheme
{
    private $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function id()   { return intval($this->row['id']); }
    public function name() { return (string)$this->row['name']; }

    /**
     * The stored value for one role, or null when this row has nothing to say about it.
     *
     * Null covers two cases that are the same case: a role this theme's table has no
     * column for — a database that predates a role being added, which is invariant 10's
     * ordinary state — and a column holding SQL NULL. Both mean "this theme does not
     * decide this role", and the layer underneath answers.
     *
     * An unknown role name answers null rather than throwing, which is the opposite of
     * `SiteChrome::varName()`. The asymmetry is deliberate: a role name reaching that
     * function came from this app's own `ROLES`, while a *column* reaching this one
     * came from a database, and a database is allowed to be older than the code.
     */
    public function colorFor($role)
    {
        if (!array_key_exists($role, $this->row)) { return null; }
        $value = $this->row[$role];
        return $value === null ? null : (string)$value;
    }

    // There was a `colors()` here, answering every raw stored value as a map, and the
    // mutation run found it: five of its mutants survived because **nothing called it**.
    // Written on the assumption the theme form would want it, and the form asks
    // `colorFor()` per role instead, because it draws the roles in groups. Deleted rather
    // than covered — an unreachable method is not a check waiting to be written, and
    // §4am's rule about a survivor being load-bearing is about lines that *run*.

    /**
     * Every stored colour this app cannot read, for whoever reports it.
     *
     * Same shape as `SiteChrome::unreadable()` and the findings in `ColorAudit`: role,
     * the label the theme form puts on it, and the value exactly as stored. The form
     * refuses a colour it cannot read and names the field, so nothing an admin can type
     * gets here — this is for the other door, a row edited by hand, and for the state a
     * column default cannot prevent on a database somebody has been in with a client.
     *
     * Absent is not unreadable, for the reason `SiteChrome::unreadable()` gives: a row
     * that simply has no column for a role is not something for anybody to go and fix.
     */
    public function unreadable()
    {
        $bad = [];
        foreach (SiteChrome::ROLES as $role => $meta) {
            $stored = $this->colorFor($role);
            if ($stored === null)            { continue; }
            if (Color::isColor($stored))     { continue; }
            $bad[] = ['key' => $role, 'label' => $meta[0], 'value' => $stored];
        }
        return $bad;
    }

    /**
     * What the Builder's script is handed for one theme: its id, its name, and every
     * role resolved to a colour that will actually render.
     *
     * Resolved here rather than raw, because the browser has no `Color::read()` and the
     * value lands in `style.setProperty()` — where an unreadable one is discarded in
     * silence, which is §4ax's defect in a new place. `SiteChrome::themeColor()` is what
     * decides, so the swatch a person sees in the picker is the colour the page will
     * take — and it is that method rather than `pick()` because a theme picked from this
     * payload has to paint what wearing it would paint, down to which layer an unusable
     * value falls through to.
     */
    public function toClientArray()
    {
        $colors = [];
        foreach (array_keys(SiteChrome::ROLES) as $role) {
            $colors[$role] = SiteChrome::themeColor($role, $this->colorFor($role));
        }
        return ['id' => $this->id(), 'name' => $this->name(), 'colors' => $colors];
    }
}

class WorkspaceThemeStore
{
    /** Column width, so a too-long name is refused rather than truncated by MySQL. */
    const NAME_MAX = PickerName::MAX;

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- Reads --------------------------------------------------------------

    /** Every theme, oldest first — the order they were made in, which is the only
     *  order that means anything for a list nobody ranks. */
    public function all()
    {
        $out = [];
        foreach ($this->rows("ORDER BY id ASC", []) as $row) { $out[] = new WorkspaceTheme($row); }
        return $out;
    }

    public function forId($id)
    {
        if (!DisplayStore::isIdLike($id)) { return null; }
        $id = intval($id);
        if ($id <= 0) { return null; }
        $rows = $this->rows("WHERE id = ? LIMIT 1", [$id]);
        return $rows ? new WorkspaceTheme($rows[0]) : null;
    }

    public function count()
    {
        return count($this->all());
    }

    /**
     * The theme an account chose, or **null meaning the store default**.
     *
     * Null is the answer for four different situations and they are one answer on
     * purpose: the account chose the store default, the account has never chosen
     * anything, the column does not exist yet, or the row it points at is gone. Every
     * one of them means "paint this person's screen the way the shop is painted", and
     * that is a page that renders rather than a page that fails.
     *
     * Which is why the query is wrapped. `ensureSignageSchema()` runs at the top of
     * every entry point that calls this, so on a healthy install the column is there —
     * but an `ALTER` that failed leaves a page that would otherwise throw before it
     * printed anything, and the thing it would have failed to print is a stylesheet.
     * Invariant 10 says the database lags the repo; a colour is not worth a blank page.
     *
     * A join rather than two reads, because this is on the path every signed-in page
     * load takes.
     */
    public function forAccount($accountId)
    {
        if (!DisplayStore::isIdLike($accountId) || intval($accountId) <= 0) { return null; }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT t.* FROM users u
                   JOIN workspace_themes t ON t.id = u.workspace_theme_id
                  WHERE u.id = ? LIMIT 1");
            $stmt->execute([intval($accountId)]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return null;
        }
        return $row ? new WorkspaceTheme($row) : null;
    }

    /**
     * The *other* theme already using this name, or null. See `PickerName::clashIn()`
     * for why the comparison is here rather than in the database, and why it answers
     * the row.
     */
    public function otherThemeNamed($name, $exceptId = 0)
    {
        return PickerName::clashIn($this->all(), $name, $exceptId);
    }

    /**
     * The names of the accounts currently wearing this theme, for a refusal that can
     * say whose screens a delete would have changed.
     *
     * Ordered by name so the sentence reads the same twice. Answers `[]` when the
     * column is missing, which is right rather than convenient: a database with no
     * column has nobody wearing anything, so there is nothing a delete would take
     * away — and the foreign key underneath refuses it again if that answer is somehow
     * wrong.
     */
    public function accountsUsing(WorkspaceTheme $theme)
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT username FROM users WHERE workspace_theme_id = ? ORDER BY username ASC");
            $stmt->execute([$theme->id()]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) { $out[] = (string)$row['username']; }
        return $out;
    }

    // ---- Colour rules -------------------------------------------------------

    /**
     * A role's colour as it should be stored, or '' when the value is not a colour.
     *
     * `Color::read()` with nothing added — no substitute, and no default. The caller
     * refuses the save and names the field, exactly as the Branding form has since #21
     * and the Brand form does. It exists as a method here only so the two writes below
     * and the form all say the same thing.
     */
    public static function cleanColor($value)
    {
        return Color::read($value);
    }

    /**
     * Which of a submitted set of colours this app cannot read: role => the value as
     * submitted.
     *
     * The whole set is checked rather than stopping at the first, because a person who
     * pasted three bad values should be told about three of them and not made to
     * resubmit twice. A role the payload does not mention is **not** a problem here —
     * `WorkspaceThemeAdmin`'s job is to refuse a payload that is not whole, which is
     * the same split the grant matrix draws between "this box was unticked" and "this
     * column was never on the page".
     */
    public static function unreadableIn(array $fields)
    {
        $bad = [];
        foreach (array_keys(SiteChrome::ROLES) as $role) {
            if (!array_key_exists($role, $fields)) { continue; }
            if (self::cleanColor($fields[$role]) !== '') { continue; }
            $bad[$role] = $fields[$role];
        }
        return $bad;
    }

    // ---- Writes -------------------------------------------------------------
    // One table, so there is no use-case module here and no transaction: a theme is a
    // single row and creating one is a single INSERT. That is the difference from
    // `BrandAdmin`, which exists because a Brand with no `block_styles` rows has a
    // typography form that reports success and changes nothing. A theme has no second
    // table to be half of.

    /**
     * Insert a theme and return it as stored.
     *
     * Every role is written. A field the payload does not carry is stored as its
     * documented default rather than left to the column's — the two agree today and
     * `selftest_layout` asserts they do, but only one of them is a value this app can
     * state, and a database somebody has hand-edited the DDL of is exactly the case
     * `ServerReport` exists for.
     *
     * Takes an already-validated set: a caller reaching here with a duplicate name gets
     * the unique-key exception, which is the database enforcing what the use case
     * already checked, not an expected path.
     */
    public function insert(array $fields)
    {
        $columns = ['name'];
        $values  = [PickerName::clean($fields['name'] ?? '')];
        foreach (array_keys(SiteChrome::ROLES) as $role) {
            $columns[] = $role;
            $values[]  = self::colorOrDefault($role, $fields);
        }

        $this->pdo->prepare(
            "INSERT INTO workspace_themes (" . implode(',', $columns) . ")
             VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")"
        )->execute($values);

        return $this->forId($this->pdo->lastInsertId());
    }

    /**
     * Change a theme's name and every one of its colours.
     *
     * Whole-row, for the reason `BrandStore::updateDetails()` is: this is reached from
     * one form that carries all of them, and "absent means untouched" is a promise
     * about a partial payload that a method taking `$fields` cannot keep — it cannot
     * tell a role somebody cleared from one the form never drew.
     */
    public function updateDetails(WorkspaceTheme $theme, array $fields)
    {
        $sets   = ['name = ?'];
        $values = [PickerName::clean($fields['name'] ?? '')];
        foreach (array_keys(SiteChrome::ROLES) as $role) {
            $sets[]   = $role . ' = ?';
            $values[] = self::colorOrDefault($role, $fields);
        }
        $values[] = $theme->id();

        $this->pdo->prepare("UPDATE workspace_themes SET " . implode(', ', $sets) . " WHERE id = ?")
                  ->execute($values);

        return $this->forId($theme->id());
    }

    /**
     * Remove the theme row itself.
     *
     * Whether anybody is still wearing it is checked one level up, before this is
     * called, so the refusal can name them. `users_ibfk_1` refuses it again from
     * underneath with no `ON DELETE` clause of its own, and both are meant: the check
     * produces a sentence, the constraint produces an exception, and the constraint is
     * what covers a database this app is not the only thing writing to.
     */
    public function deleteRow(WorkspaceTheme $theme)
    {
        $this->pdo->prepare("DELETE FROM workspace_themes WHERE id = ?")->execute([$theme->id()]);
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * One role's colour out of a submitted set: the value if it is a colour, otherwise
     * the documented default.
     *
     * Never a substitute for something a person typed — the use case has already
     * refused a payload with an unreadable colour in it and said which field. This is
     * what a role the payload never mentioned gets.
     */
    private static function colorOrDefault($role, array $fields)
    {
        $clean = array_key_exists($role, $fields) ? self::cleanColor($fields[$role]) : '';
        return $clean !== '' ? $clean : SiteChrome::DEFAULTS[$role];
    }

    private function rows($where, array $params)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM workspace_themes " . $where);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
