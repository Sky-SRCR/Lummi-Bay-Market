<?php
// ============================================================
// ADMINISTERING DISPLAYS
// ============================================================
// Adding, editing, retiring and destroying a Display — the Phase 3 admin screen's
// whole vocabulary, behind four methods.
//
//   create(fields)                  → DisplayResult   blank, or a duplicate of another Display
//   updateDetails(Display, fields)  → DisplayResult   title, screen name tag, location
//   setActive(Display, bool)        → DisplayResult   retire without losing the layout
//   destroy(Display, typedTag, who) → DisplayResult   the layout and its grants go with it
//   setAccess(accounts, wanted)     → DisplayResult   who may edit what, in one write
//
// Why this exists rather than more methods on DisplayStore: administering a
// Display spans three tables. Creating one as a duplicate writes a `displays` row
// *and* a set of `canvas_elements` rows; destroying one removes both plus every
// grant on it; the access matrix writes only grants but has to know which Displays
// exist. Those tables have one gatekeeper each and none may reach into another, so
// the composition — with the validation and the transaction that make it safe —
// needs somewhere of its own. Without this module all of it would sit in
// `admin_panel.php`, in two copies by now.
//
// This module writes no SQL. It holds a PDO only to open and close the
// transaction that makes "create as a duplicate" and "destroy" all-or-nothing;
// every statement is still DisplayStore's or LayoutStore's.
//
// Rules enforced here, so that every path to a Display agrees on them:
//   · A Display needs a title, a valid unused screen name tag, and dimensions
//     inside the bounds. A tag left blank is suggested from the title.
//   · Dimensions are set once and never again (ADR-0004) — there is no method
//     here that changes them.
//   · A layout may be duplicated only from a Display of identical dimensions
//     (ADR-0004), and only into an empty one.
//   · Destroying a Display requires its screen name tag typed back. There is no
//     undo anywhere in this app, and this is the most destructive button in it.
//   · Destroying a Display is refused outright while somebody else is editing it
//     (#19). Every other change of reach in this module frees the holder's lock and
//     tells them; this is the one that cannot, because there is nothing left to hold
//     and nobody left to tell. So it asks first instead.
//   · A grant is only ever "this account may edit this Display" (ADR-0005). This
//     module does not know what an account's *role* is — that is the panel's
//     business, and it is why granting is offered for `basic` accounts only.
//   · A background colour that cannot be read is refused, not replaced (#21). The
//     rule itself is lib/color.php's; what this module adds is that both paths to
//     a background refuse with the same sentence.

require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/layout_store.php';
require_once __DIR__ . '/grants.php';
require_once __DIR__ . '/color.php';

/**
 * The outcome of an administrative change, as a value.
 *
 * Adapters branch on kind() and show message(); field() names the input to point
 * at when there is one. Never parse the message to work out what happened.
 */
class DisplayResult
{
    const OK       = 'ok';
    const INVALID  = 'invalid';    // the input cannot be used
    // The input is fine; the state of things refuses it. A screen name tag another
    // Display already has, or a Display somebody else is in the middle of editing.
    const CONFLICT = 'conflict';
    const FAILED   = 'failed';     // the database refused; nothing was changed

    private $kind;
    private $display;
    private $message;
    private $field;

    private function __construct($kind, $display, $message, $field)
    {
        $this->kind    = $kind;
        $this->display = $display;
        $this->message = $message;
        $this->field   = $field;
    }

    public static function ok(Display $display, $message)
    {
        return new self(self::OK, $display, $message, '');
    }

    /**
     * A change with no single Display as its subject: one that has just been
     * destroyed, or a grant matrix that spans all of them.
     */
    public static function done($message)
    {
        return new self(self::OK, null, $message, '');
    }

    public static function invalid($field, $message)
    {
        return new self(self::INVALID, null, $message, $field);
    }

    public static function conflict($field, $message)
    {
        return new self(self::CONFLICT, null, $message, $field);
    }

    public static function failed($message)
    {
        return new self(self::FAILED, null, $message, '');
    }

    public function isOk()    { return $this->kind === self::OK; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }
    public function field()   { return $this->field; }

    /** The Display as stored after the change, or null after a destroy or a failure. */
    public function display() { return $this->display; }
}

class DisplayAdmin
{
    /**
     * The background a new Display gets when the form did not name one.
     *
     * The dark navy the canvas has always had. It is a value applied at one visible
     * decision — "nothing was supplied" — and never a substitute for a value that
     * was supplied and could not be read; see create().
     */
    const DEFAULT_BACKGROUND = '#1a1a2e';

    private $pdo;
    private $displays;
    private $layouts;
    private $grants;

    /**
     * @param PDO $pdo transaction boundaries only — this module writes no SQL
     */
    public function __construct(PDO $pdo, DisplayStore $displays, LayoutStore $layouts, GrantStore $grants)
    {
        $this->pdo      = $pdo;
        $this->displays = $displays;
        $this->layouts  = $layouts;
        $this->grants   = $grants;
    }

    /**
     * Add a Display, blank or as a duplicate of one the same shape.
     *
     * Fields: title, tag (blank suggests from title), location, canvas_width,
     * canvas_height, bg_val, duplicate_from (a screen name tag, or '' for blank).
     *
     * Active from the moment it exists: a Display nobody has pointed a Screen at
     * yet is harmless, and one that silently starts retired is a support call.
     */
    public function create(array $fields)
    {
        $clean = [];
        $bad = $this->validateDetails($fields, 0, $clean);
        if ($bad) { return $bad; }

        $width  = isset($fields['canvas_width'])  ? $fields['canvas_width']  : '';
        $height = isset($fields['canvas_height']) ? $fields['canvas_height'] : '';
        if (!DisplayStore::isValidCanvasSize($width, $height)) {
            return DisplayResult::invalid('canvas_width',
                'Choose a canvas size between ' . DisplayStore::CANVAS_MIN . ' and '
                . DisplayStore::CANVAS_MAX . ' pixels on each side. '
                . 'It cannot be changed after the display is created.');
        }
        $clean['canvas_width']  = intval($width);
        $clean['canvas_height'] = intval($height);
        $clean['bg_type']       = 'color';
        $clean['is_active']     = 1;

        // Blank and unreadable are two different answers (#21). Nothing supplied
        // means the admin never touched the swatch, and a new canvas has to have
        // *some* background, so the default applies. A value that is not a colour
        // means the form said something this app cannot store, and substituting the
        // default there is how "created" used to be reported for a Display that is
        // not the colour anybody chose.
        $rawBg = isset($fields['bg_val']) ? $fields['bg_val'] : '';
        if ($rawBg === '' || $rawBg === null) {
            $clean['bg_val'] = self::DEFAULT_BACKGROUND;
        } else {
            $bg = Color::read($rawBg);
            if ($bg === '') {
                return DisplayResult::invalid('bg_val', self::colorRefusal($rawBg));
            }
            $clean['bg_val'] = $bg;
        }

        // "Duplicate of" is resolved before anything is written, so a stale form
        // naming a Display that has since been deleted or is the wrong shape
        // fails without leaving a half-made Display behind.
        $source = null;
        $sourceTag = isset($fields['duplicate_from']) ? trim((string)$fields['duplicate_from']) : '';
        if ($sourceTag !== '') {
            $source = $this->displays->forTag($sourceTag);
            if (!$source) {
                return DisplayResult::invalid('duplicate_from',
                    'The display you chose to duplicate no longer exists. Reload and try again.');
            }
            if ($source->canvasWidth() !== $clean['canvas_width']
                || $source->canvasHeight() !== $clean['canvas_height']) {
                return DisplayResult::invalid('duplicate_from',
                    'A layout can only be duplicated between displays of exactly the same size. "'
                    . $source->title() . '" is ' . $source->dimensionsLabel() . '. '
                    . 'Start from blank instead.');
            }
        }

        try {
            $this->pdo->beginTransaction();
            $display = $this->displays->insert($clean);
            if (!$display) {
                $this->pdo->rollBack();
                return DisplayResult::failed('The display could not be created. Nothing was changed.');
            }

            $copied = 0;
            if ($source) {
                $copied = $this->layouts->copyLayout($source, $display);
                if ($copied === false) {
                    // Shapes matched a moment ago; something changed underneath.
                    $this->pdo->rollBack();
                    return DisplayResult::failed(
                        'That layout could not be duplicated. The display was not created.');
                }
            }

            $this->pdo->commit();

            $what = $source
                ? ' with ' . $copied . ' element' . ($copied === 1 ? '' : 's') . ' copied from ' . $source->title()
                : ' as a blank canvas';
            return DisplayResult::ok($display,
                'Display "' . $display->title() . '" created' . $what . '. '
                . 'Point a screen at ' . $this->viewerPath($display) . '.');
        } catch (Throwable $e) {
            $this->abandon();
            // The one expected failure is a tag that was taken between the check
            // and the insert; anything else is reported the same way, because
            // either way nothing was written.
            return DisplayResult::failed('The display could not be created. Nothing was changed.');
        }
    }

    /**
     * Change the reference details, and possibly the screen name tag.
     *
     * Renaming the tag changes the Viewer URL: whatever is pointed at the old one
     * stops working. That is the admin's call (ADR-0003) — the panel confirms it
     * and shows the new URL — so this method carries the consequence in its
     * message rather than refusing.
     *
     * A Builder open on the old address stops working too, and deliberately keeps its
     * lock. A rename changes where the sign answers, not who may edit it, so taking
     * the lock away would punish somebody for an admin's retyping. Their page says the
     * address changed and offers a reload; reloading finds the new tag and picks the
     * same lock back up, because it is still theirs.
     */
    public function updateDetails(Display $display, array $fields)
    {
        $clean = [];
        $bad = $this->validateDetails($fields, $display->id(), $clean);
        if ($bad) { return $bad; }

        $renamed  = $clean['tag'] !== $display->tag();
        $wasBeing = $renamed && $display->lockState()->isHeld();

        try {
            $updated = $this->displays->updateDetails($display, $clean);
        } catch (Throwable $e) {
            return DisplayResult::failed('That display could not be updated. Nothing was changed.');
        }
        if (!$updated) {
            return DisplayResult::failed('That display no longer exists.');
        }

        $note = $renamed
            ? ' Its address changed — any screen showing it must now be pointed at '
              . $this->viewerPath($updated) . '.'
              . ($wasBeing
                  ? ' Somebody has it open in the builder: their page says the address changed and asks'
                    . ' them to reload, within a minute. The display is still theirs and their work is'
                    . ' still on their screen.'
                  : '')
            : '';
        return DisplayResult::ok($updated, 'Display "' . $updated->title() . '" updated.' . $note);
    }

    /**
     * Retire a Display, or bring it back.
     *
     * Retiring keeps the layout and keeps it editable; the Screens show "This
     * display is turned off" within one poll (≤30s).
     *
     * "Editable" is not "editable by everyone", and that is what makes retiring a
     * change of reach: a retired Display stays an admin's to work on and stops being
     * a `basic` account's (Actor::mayOpen). So a clerk holding the edit lock loses the
     * sign the moment this runs — and cannot hand the lock back, because releasing goes
     * through the seam that has just started refusing them. Their lock is freed here,
     * by holder, so an admin working on the same Display keeps theirs. Their Builder is
     * told within a minute (builder.php's terminal lock answers).
     */
    public function setActive(Display $display, $active)
    {
        // Whose lock, and does this change take it off them. Two questions of the
        // Display as it stands, asked before anything is written so the answer cannot
        // depend on the order of the two statements below.
        $lock       = $display->lockState();
        $freeHolder = (!$active && $lock->isHeld() && !$display->lockHolderIsAdmin())
            ? $lock->holderId()
            : 0;

        $freed = false;
        try {
            $this->pdo->beginTransaction();
            $updated = $this->displays->setActive($display, $active);
            if ($updated && $freeHolder > 0) {
                $freed = $this->displays->releaseLockOn($display->id(), $freeHolder);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->abandon();
            return DisplayResult::failed('That display could not be changed. Nothing was changed.');
        }
        if (!$updated) {
            return DisplayResult::failed('That display no longer exists.');
        }

        if ($active) {
            return DisplayResult::ok($updated,
                'Display "' . $updated->title() . '" is on. Screens showing it update within 30 seconds.');
        }
        return DisplayResult::ok($updated,
            'Display "' . $updated->title() . '" is turned off. Its layout is kept and stays editable by '
            . 'admins; any screen showing it now says so within 30 seconds.'
            . ($freed
                ? ' Somebody without admin access was editing it — the edit lock has been released and their'
                  . ' builder says so within a minute. Nothing they had not published reached a screen.'
                : ''));
    }

    /**
     * Set a Display's background colour.
     *
     * Advances the layout stamp, because the background is part of what the Screen
     * shows: an admin with the Builder open still holding the old stamp would
     * otherwise publish their stale colour straight back over this one. They get
     * the refusal instead (ADR-0006).
     *
     * Only a colour. Background *images* are set from the Builder, where the
     * upload is already validated in one place and you can see the canvas you are
     * changing.
     *
     * A value that is not a colour is refused rather than replaced (#21). This is
     * the harsher of the two places it mattered: the old code substituted the dark
     * default, advanced the layout stamp anyway, and reported the background
     * "set" — so an admin got a colour they had not chosen, every Screen showing
     * that Display took it within 30 seconds, and every Builder tab open at the
     * time was invalidated on the way past. There is no undo for any of that.
     */
    public function setBackgroundColor(Display $display, $hex)
    {
        $color = Color::read($hex);
        if ($color === '') {
            return DisplayResult::invalid('bg_val', self::colorRefusal($hex));
        }

        try {
            $this->displays->applyBackground($display, Background::color($color));
            $this->displays->advanceLayoutRevision($display);
            $updated = $this->displays->forId($display->id());
        } catch (Throwable $e) {
            return DisplayResult::failed('That background could not be changed. Nothing was changed.');
        }
        if (!$updated) {
            return DisplayResult::failed('That display no longer exists.');
        }

        return DisplayResult::ok($updated,
            'Background colour set for "' . $updated->title() . '". Screens showing it update within '
            . '30 seconds, and any builder tab opened before now must reload before it can publish.');
    }

    /**
     * Destroy a Display and its layout, behind the screen name tag typed back.
     *
     * There is no undo in this app and nothing is versioned, so this is the one
     * action that can lose work with no way back. The typed tag is one safeguard —
     * an admin who mistypes it gets a refusal, not a deletion — but it only proves
     * the admin meant *this* sign. It says nothing about whether anybody was using it.
     *
     * **So this refuses while somebody else holds the edit lock (#19).** Every other
     * change of reach in this app frees the holder's lock in the same transaction and
     * lets their Builder say so; deletion is the one that cannot, because afterwards
     * there is no row to free a lock on and no Display for their page to ask about.
     * A clerk mid-layout simply lost the canvas under them, and the admin who did it
     * was never told there was anybody there.
     *
     * `heldByOther()`, not `isHeld()` — the same predicate that makes a Builder
     * read-only and refuses a publish. An admin deleting a sign they have open
     * themselves is deleting their own work, knowingly, and is not somebody to
     * protect from it. A lapsed lock does not block either, because LockState
     * already rules that a Builder left open on a back-office monitor is nobody.
     *
     * The question is asked twice, deliberately:
     *
     *   · Before the typed tag, so an admin learns who is editing without first
     *     being sent away to retype a tag for a deletion that was never going to
     *     happen. The immovable fact goes first; the confirmation gate is for a
     *     deletion that could actually go through.
     *   · Again inside the transaction, against a row this module reads itself.
     *     Otherwise the guarantee is "the caller handed me a Display it read
     *     recently" — true of both callers today, and not a thing a module should
     *     rest an irreversible write on.
     *
     * What stays open, and why it is left open: the re-read is a plain SELECT, so
     * somebody can still claim the lock in the moment between it and the delete.
     * What they lose then is that moment, not the twenty minutes this refusal exists
     * for, and their Builder already has a sentence for a Display that has gone
     * (builder.php's terminal lock answers). Closing it would mean a second
     * `FOR UPDATE`, a second SQLite seam for it, and a second encounter with #35's
     * lock-wait timeout — spent on the rarest write in the app, when the publish path
     * that carries the first one has two people colliding on an ordinary Tuesday.
     *
     * @param int|string $actorId the admin doing the deleting. A value naming nobody
     *                            errs towards refusing, not towards deleting: an
     *                            unknown actor is "somebody else" to every held lock.
     */
    public function destroy(Display $display, $typedTag, $actorId)
    {
        $busy = self::editingRefusal($display, $actorId);
        if ($busy) { return $busy; }

        if (DisplayStore::normalizeTag($typedTag) !== $display->tag()) {
            return DisplayResult::invalid('confirm_tag',
                'Type the screen name tag "' . $display->tag() . '" exactly to delete this display. '
                . 'Nothing was deleted.');
        }

        try {
            $this->pdo->beginTransaction();

            // Two things can have changed since the page carrying this form was
            // drawn: another admin can have deleted the Display, and somebody can
            // have started editing it. Both are read here rather than trusted from
            // the argument, and both refuse before a single row is removed.
            $fresh = $this->displays->forId($display->id());
            if (!$fresh) {
                $this->abandon();
                return DisplayResult::failed('That display no longer exists. Nothing was changed.');
            }
            $busy = self::editingRefusal($fresh, $actorId);
            if ($busy) {
                $this->abandon();
                return $busy;
            }

            // Elements and grants first, and explicitly: both `ON DELETE CASCADE`
            // constraints may never have applied on a live database that is behind
            // the repo. A layout orphaned by a deleted Display is invisible to
            // every scoped query while still occupying the table, and an orphaned
            // grant is one id reuse away from granting the wrong sign.
            $lost    = $this->layouts->deleteAllElements($fresh);
            $revoked = $this->grants->revokeAllForDisplay($fresh);
            $this->displays->deleteRow($fresh);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->abandon();
            return DisplayResult::failed('That display could not be deleted. Nothing was changed.');
        }

        return DisplayResult::done(
            'Display "' . $display->title() . '" (' . $display->tag() . ') and its '
            . $lost . ' element' . ($lost === 1 ? '' : 's') . ' were deleted'
            . ($revoked > 0
                ? ', along with ' . $revoked . ' account' . ($revoked === 1 ? '\'s' : 's\'')
                  . ' access to it'
                : '')
            . '. Any screen still pointed at it now shows "Display not found".');
    }

    /**
     * "Somebody else is editing this" as a refusal, or null when nobody is.
     *
     * One helper, because destroy() asks the question twice — once of the Display it
     * was handed and once of the row it re-reads — and two sentences that disagreed
     * about who was editing would look like the app guessing rather than checking.
     *
     * The refusal names the holder, when they started, and both ways out: ask them,
     * or wait for the idle window. The window is read from LockState rather than
     * written here, so a change to ADR-0007's fifteen minutes cannot leave this
     * sentence quoting the old number.
     */
    private static function editingRefusal(Display $display, $actorId)
    {
        $lock = $display->lockState();
        if (!$lock->heldByOther($actorId)) { return null; }

        $who   = $lock->holderName() !== '' ? $lock->holderName() : 'Somebody';
        $since = $lock->takenAtLabel();
        $mins  = intdiv(LockState::IDLE_LAPSE_SECONDS, 60);

        return DisplayResult::conflict('',
            $who . ' has "' . $display->title() . '" open in the builder'
            . ($since === '' ? '' : ', since ' . $since)
            . '. Deleting it would take the canvas out from under them and lose whatever '
            . 'they have not published yet. Nothing was deleted. Ask them to close it, or '
            . 'wait — the lock lapses ' . $mins . ' minutes after their last change.');
    }

    /**
     * Set exactly which Displays each of these accounts may edit — within the part
     * of the matrix the form actually covered.
     *
     * **Both axes are declared, and neither is inferred from an absence.** A tick
     * missing from the submission means "revoke" only for an account *and* a Display
     * the form carried. That is the difference between a grid that saves what an
     * admin saw and one that saves what they saw over the top of what they did not:
     *
     *   · An account the form did not list is untouched — so a page rendered before
     *     a new account existed cannot strip the new account's access.
     *   · A **Display** the form did not list is untouched — so a page rendered
     *     before a new Display existed cannot silently undo the grants another admin
     *     made on it a minute ago. This is the half that was missing: every grant on
     *     a Display outside the submitted columns read as an unticked box.
     *
     * Ids naming a Display that does not exist are dropped rather than refused —
     * the one way to send one is a form left open while the Display was deleted,
     * and losing an unreachable grant is not worth making an admin retype the
     * matrix for.
     *
     * A revoke also frees the edit lock, if the account it takes the Display from was
     * holding it. Without that the sign stays locked for a full idle window to
     * somebody who can no longer open it to release it, with their name on the
     * banner — and only an admin's force-unlock could take it back. The lock is
     * released *by holder*, so a colleague working on the same Display keeps theirs.
     *
     * @param array $accountIds the accounts this write covers. The caller decides
     *                          which: grants are meaningless for an admin, who
     *                          holds every Display by role (ADR-0005), so the
     *                          panel passes `basic` accounts only.
     * @param array $displayIds the Displays this write covers — the form's columns.
     * @param array $wanted     accountId => [displayId, …], as submitted
     */
    public function setAccess(array $accountIds, array $displayIds, array $wanted)
    {
        $exists = [];
        foreach ($this->displays->all() as $display) { $exists[] = $display->id(); }

        // The columns this save is allowed to have an opinion about: what the form
        // said it showed, narrowed to what is still there.
        $covered = [];
        foreach ($displayIds as $rawDisplayId) {
            $displayId = intval($rawDisplayId);
            if (in_array($displayId, $exists, true) && !in_array($displayId, $covered, true)) {
                $covered[] = $displayId;
            }
        }

        $granted = 0;
        $revoked = 0;
        $freed   = 0;

        try {
            $this->pdo->beginTransaction();
            foreach ($accountIds as $rawAccountId) {
                $accountId = intval($rawAccountId);
                if ($accountId <= 0) { continue; }

                $want = [];
                if (isset($wanted[$accountId]) && is_array($wanted[$accountId])) {
                    foreach ($wanted[$accountId] as $rawDisplayId) {
                        $displayId = intval($rawDisplayId);
                        if (in_array($displayId, $covered, true) && !in_array($displayId, $want, true)) {
                            $want[] = $displayId;
                        }
                    }
                }

                $held = $this->grants->displayIdsFor($accountId);
                foreach ($want as $displayId) {
                    if (!in_array($displayId, $held, true)) {
                        $this->grants->grant($displayId, $accountId);
                        $granted++;
                    }
                }
                foreach ($held as $displayId) {
                    // Held, covered by this form, and not ticked. All three, or it is
                    // not a revoke — it is a column the admin was never shown.
                    if (in_array($displayId, $covered, true) && !in_array($displayId, $want, true)) {
                        $this->grants->revoke($displayId, $accountId);
                        $revoked++;
                        if ($this->displays->releaseLockOn($displayId, $accountId)) { $freed++; }
                    }
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->abandon();
            return DisplayResult::failed('Access could not be changed. Nothing was changed.');
        }

        if ($granted === 0 && $revoked === 0) {
            return DisplayResult::done('Access is unchanged.');
        }

        // Revoking is the half worth spelling out, and what to say depends on whether
        // anybody was actually in there: a released lock means somebody's editing
        // session just ended, and they find out from their own screen rather than
        // from whoever pressed this button.
        $note = '';
        if ($freed > 0) {
            $note = ' ' . $freed . ' display' . ($freed === 1 ? ' was' : 's were')
                  . ' being edited by somebody who just lost access — the edit lock has been released and '
                  . 'their builder says so within a minute. Nothing they had not published reached a screen.';
        } elseif ($revoked > 0) {
            $note = ' Anyone who lost access can no longer open that display, and nothing they had'
                  . ' unpublished reaches its screen.';
        }
        return DisplayResult::done(
            'Access updated — ' . $granted . ' display' . ($granted === 1 ? '' : 's') . ' newly assigned and '
            . $revoked . ' taken away.' . $note);
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * The rules a Display's details must satisfy, checked once for create and
     * edit alike so the two cannot drift apart.
     *
     * @param array $clean out: title, tag and location, ready for the store
     * @return DisplayResult|null null when the input is usable
     */
    private function validateDetails(array $in, $exceptId, array &$clean)
    {
        $title = trim((string)(isset($in['title']) ? $in['title'] : ''));
        if ($title === '') {
            return DisplayResult::invalid('title',
                'Give the display a title — it is how everyone will refer to this screen.');
        }
        if (strlen($title) > DisplayStore::TITLE_MAX) {
            return DisplayResult::invalid('title',
                'That title is too long (limit ' . DisplayStore::TITLE_MAX . ' characters).');
        }

        $location = trim((string)(isset($in['location']) ? $in['location'] : ''));
        if (strlen($location) > DisplayStore::LOCATION_MAX) {
            return DisplayResult::invalid('location',
                'That location is too long (limit ' . DisplayStore::LOCATION_MAX . ' characters).');
        }

        // A blank tag is filled in from the title rather than refused: the tag is
        // a URL detail, and the title is the thing an admin actually has in mind.
        $tag = DisplayStore::normalizeTag(isset($in['tag']) ? $in['tag'] : '');
        if ($tag === '') { $tag = DisplayStore::suggestTag($title); }

        if (!DisplayStore::isValidTag($tag)) {
            return DisplayResult::invalid('tag',
                'The screen name tag must be ' . DisplayStore::TAG_MIN . '–' . DisplayStore::TAG_MAX
                . ' characters of lowercase letters, numbers and hyphens — it goes in the display\'s '
                . 'web address, like viewer.php?display=drive-thru.');
        }
        if ($this->displays->tagExists($tag, $exceptId)) {
            return DisplayResult::conflict('tag',
                'Another display already uses the screen name tag "' . $tag . '". Choose a different one.');
        }

        $clean = ['title' => $title, 'tag' => $tag, 'location' => $location];
        return null;
    }

    /**
     * Roll back if there is anything to roll back, and never throw doing it.
     *
     * inTransaction() is PDO's own bookkeeping, so it still reports true after the
     * connection has gone — and rollBack() would then throw from inside the catch
     * that exists to turn a failure into a returned result.
     */
    private function abandon()
    {
        try {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        } catch (Throwable $e) {
            // Nothing was committed either way, and the caller is already
            // returning a refusal.
        }
    }

    /** The Viewer address to put on a device, relative to the app root. */
    private function viewerPath(Display $display)
    {
        return 'viewer.php?display=' . $display->tag();
    }

    /**
     * The one sentence both background paths refuse with.
     *
     * Written once because create() and setBackgroundColor() must say the same
     * thing: an admin who sees two different explanations for the same rejected
     * swatch will reasonably conclude the two forms accept different colours.
     */
    private static function colorRefusal($value)
    {
        return 'That background colour could not be read (' . Color::describe($value) . '). '
             . 'Colours are written as six hexadecimal digits after a hash, like #1a1a2e. '
             . 'Nothing was changed.';
    }
}
