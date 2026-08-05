<?php
// ============================================================
// SCHEMA CONVERGENCE
// ============================================================
// One entry point — ensureSignageSchema($pdo) — brings whatever structure the
// live database currently has up to what this code expects. There is no
// migration tool and no version table: the live database is edited in place and
// is known to lag the repo (see docs/BUILD-REFERENCE.md §2.10), so every
// statement here must be safe to run against a database that has already had it
// applied, and safe to run in any order relative to the others.
//
// Callers know one fact: call this on an authenticated request before touching
// signage tables. Everything else — which columns are new, which statement
// fails harmlessly when it has already run, how the drive-thru Display is
// created out of the single-row canvas_settings it replaces — is in here.
//
// The public get_layout poll must NOT run this: every Screen hits it every 30
// seconds forever. See DisplayStore, which converges once on a genuinely absent
// schema and then never again.

// Nothing is required here on purpose: this file must be includable without
// starting a session or loading page-level helpers (see BUILD-REFERENCE.md §1).
// The login-lockout columns stay where ADR-0001 put them — added by the pre-auth
// login/reset pages, never from a data-access path.

// The name tag of the Display that the pre-multi-display layout becomes.
// Referenced by the Phase 2 cutover ("…/viewer.php?display=drive-thru").
if (!defined('LEGACY_DISPLAY_TAG')) {
    define('LEGACY_DISPLAY_TAG', 'drive-thru');
}

/**
 * Converge the signage schema. Idempotent, and runs its statements at most once
 * per request. Never throws: a statement that cannot apply (already applied, or
 * blocked by data) leaves the database as it was.
 */
function ensureSignageSchema(PDO $pdo)
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    // ---- canvas_elements: columns added since the original install ----------
    // Carried over verbatim from the inline ALTERs that used to sit at the top
    // of api.php, so behaviour on an out-of-date database is unchanged.
    schemaTry($pdo, "ALTER TABLE canvas_elements ADD COLUMN text_align VARCHAR(16) NOT NULL DEFAULT ''");
    schemaTry($pdo, "ALTER TABLE canvas_elements ADD COLUMN z_index INT NOT NULL DEFAULT 1");
    schemaTry($pdo, "ALTER TABLE canvas_elements ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0");
    schemaTry($pdo, "ALTER TABLE canvas_elements MODIFY COLUMN type
                     ENUM('section','text','image','video','carousel','marquee','table') NOT NULL");

    // The Builder offers Title 2 and Price 2 as block subtypes and block_styles
    // seeds both, but the ENUM never listed them — so publishing a layout that
    // uses one either fails the whole transaction (strict mode) or silently
    // blanks the subtype. Widening an ENUM changes no stored data.
    schemaTry($pdo, "ALTER TABLE canvas_elements MODIFY COLUMN block_subtype
                     ENUM('free','section_header','item_title','item_title_2','price','price_2','description')
                     DEFAULT 'free'");

    // One row per branded block type must exist for Brand Standards to be
    // editable at all: that form saves with UPDATE … WHERE block_type = ?, so a
    // missing row makes the save a silent no-op — the field reverts on reload and
    // nothing says why. INSERT IGNORE, so the store's own numbers are never
    // touched; this only fills gaps. All six are listed rather than just the two
    // this build added, because the four originals are missing on a fresh install
    // (schema.sql seeds the same set).
    schemaTry($pdo, "INSERT IGNORE INTO block_styles
                     (block_type,font_family,font_size,font_color,font_weight,font_style,line_height) VALUES
                     ('section_header','Arial',36,'#ffffff','bold','normal',1.30),
                     ('item_title','Arial',24,'#ffffff','bold','normal',1.30),
                     ('item_title_2','Arial',24,'#27ae60','bold','normal',1.30),
                     ('price','Arial',30,'#e74c3c','bold','normal',1.20),
                     ('price_2','Arial',30,'#e74c3c','bold','normal',1.20),
                     ('description','Arial',16,'#bdc3c7','normal','normal',1.40)");

    // ---- displays -----------------------------------------------------------
    // One row per configured sign. Absorbs canvas_settings (background) and
    // carries the canvas dimensions that were hardcoded as 1920×1080, the
    // publish stamp (ADR-0006) and the edit-lock columns (ADR-0007).
    schemaTry($pdo, "CREATE TABLE IF NOT EXISTS displays (
        id                INT(11)      NOT NULL AUTO_INCREMENT,
        tag               VARCHAR(32)  NOT NULL COMMENT 'screen name tag — the Viewer URL contract',
        title             VARCHAR(120) NOT NULL,
        location          VARCHAR(160) DEFAULT NULL,
        canvas_width      INT(11)      NOT NULL,
        canvas_height     INT(11)      NOT NULL,
        bg_type           ENUM('color','image') NOT NULL DEFAULT 'color',
        bg_val            VARCHAR(255) NOT NULL DEFAULT '#1a1a2e',
        is_active         TINYINT(1)   NOT NULL DEFAULT 1,
        layout_revision   INT(11)      NOT NULL DEFAULT 0 COMMENT 'publish stamp; increments on every publish',
        last_published_at DATETIME     DEFAULT NULL,
        last_published_by INT(11)      DEFAULT NULL,
        lock_holder_id    INT(11)      DEFAULT NULL COMMENT 'edit lock holder',
        lock_taken_at     DATETIME     DEFAULT NULL COMMENT 'when the holder started editing',
        lock_activity_at  DATETIME     DEFAULT NULL COMMENT 'last real interaction by the holder',
        created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY tag (tag),
        KEY last_published_by (last_published_by),
        KEY lock_holder_id (lock_holder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // CREATE TABLE above is a no-op on a database that already has `displays`, so
    // a column added to it after that database converged needs its own statement.
    // lock_taken_at is what lets a read-only Builder say *since when* somebody has
    // been editing, rather than only that they are.
    schemaTry($pdo, "ALTER TABLE displays ADD COLUMN lock_taken_at DATETIME DEFAULT NULL");

    // Both point at accounts and must survive an account being deleted, so the
    // Display is never taken with it. Added separately for the same reason.
    schemaTry($pdo, "ALTER TABLE displays ADD CONSTRAINT displays_ibfk_1
                     FOREIGN KEY (last_published_by) REFERENCES users (id) ON DELETE SET NULL");
    schemaTry($pdo, "ALTER TABLE displays ADD CONSTRAINT displays_ibfk_2
                     FOREIGN KEY (lock_holder_id) REFERENCES users (id) ON DELETE SET NULL");

    seedLegacyDisplay($pdo);

    // ---- canvas_elements.display_id ----------------------------------------
    // Added nullable, backfilled, then tightened — in that order, because the
    // live table already holds the drive-thru layout and a NOT NULL column with
    // no default cannot be added to it.
    schemaTry($pdo, "ALTER TABLE canvas_elements ADD COLUMN display_id INT(11) DEFAULT NULL");

    // Backfill: everything that predates Display scoping is the drive-thru sign.
    // Runs unconditionally — a row that arrives unscoped later (a partly applied
    // migration, a hand edit) would otherwise be invisible to every scoped query
    // while still occupying the canvas.
    $legacyId = legacyDisplayId($pdo);
    if ($legacyId) {
        schemaTry($pdo, "UPDATE canvas_elements SET display_id = " . intval($legacyId) . " WHERE display_id IS NULL");
    }

    // Only succeeds once nothing is NULL, which is exactly the condition we want
    // it to enforce. An unscoped row left behind keeps the column nullable, and
    // rehearse/selftest surfaces it rather than the app silently dropping rows.
    schemaTry($pdo, "ALTER TABLE canvas_elements MODIFY COLUMN display_id INT(11) NOT NULL");
    schemaTry($pdo, "ALTER TABLE canvas_elements ADD KEY display_id (display_id)");

    // ON DELETE CASCADE is the Phase 3 "delete destroys the Display and its
    // layout" rule, enforced by the database rather than by remembering to
    // delete elements first.
    schemaTry($pdo, "ALTER TABLE canvas_elements ADD CONSTRAINT canvas_elements_ibfk_3
                     FOREIGN KEY (display_id) REFERENCES displays (id) ON DELETE CASCADE");

    // ---- display_permissions ------------------------------------------------
    // One row per grant: this account may edit this Display (ADR-0005). A grant
    // is the row's existence — there is deliberately no "level" column, because
    // what an account may do once inside comes from users.role.
    //
    // Admins are never granted anything; they hold every Display by role. So an
    // empty table means "no basic account can edit anything yet", which is the
    // right default for a store that has been running on admin accounts only.
    schemaTry($pdo, "CREATE TABLE IF NOT EXISTS display_permissions (
        id         INT(11)   NOT NULL AUTO_INCREMENT,
        display_id INT(11)   NOT NULL,
        user_id    INT(11)   NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY display_user (display_id, user_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // A grant is meaningless once either side is gone, and a stale row pointing
    // at a reused id would hand someone access they were never given. Added
    // separately, because CREATE TABLE above is a no-op on an existing table.
    schemaTry($pdo, "ALTER TABLE display_permissions ADD CONSTRAINT display_permissions_ibfk_1
                     FOREIGN KEY (display_id) REFERENCES displays (id) ON DELETE CASCADE");
    schemaTry($pdo, "ALTER TABLE display_permissions ADD CONSTRAINT display_permissions_ibfk_2
                     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE");
}

/**
 * Create the Display that the single pre-multi-display layout belongs to,
 * inheriting the background from the canvas_settings row it replaces.
 *
 * Does nothing once any Display exists — including when an admin has already
 * renamed this one, since the tag is theirs to change (ADR-0003) and a second
 * "drive-thru" must not appear behind their back.
 */
function seedLegacyDisplay(PDO $pdo)
{
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM displays")->fetchColumn();
    } catch (Exception $e) {
        return;   // table missing — CREATE TABLE above failed; nothing to seed into
    }
    if (intval($count) > 0) { return; }

    // canvas_settings is retired by this build, so this is the one and only
    // place that still reads it: to carry the background forward.
    $bgType = 'color';
    $bgVal  = '#1a1a2e';
    try {
        $row = $pdo->query("SELECT bg_type, bg_val FROM canvas_settings ORDER BY id ASC LIMIT 1")->fetch();
        if ($row) {
            $bgType = ($row['bg_type'] === 'image') ? 'image' : 'color';
            if (isset($row['bg_val']) && $row['bg_val'] !== '') { $bgVal = $row['bg_val']; }
        }
    } catch (Exception $e) {
        // No canvas_settings (fresh install) — defaults are the same ones it held.
    }

    try {
        $pdo->prepare(
            "INSERT INTO displays (tag, title, location, canvas_width, canvas_height, bg_type, bg_val, is_active)
             VALUES (?, ?, NULL, 1920, 1080, ?, ?, 1)"
        )->execute([LEGACY_DISPLAY_TAG, 'Drive-Thru', $bgType, $bgVal]);
    } catch (Exception $e) {
        // Unique tag collision — another request seeded it first. Fine.
    }
}

/**
 * The Display that unscoped rows belong to: the one carrying LEGACY_DISPLAY_TAG,
 * or failing that the oldest Display, so a renamed tag still backfills correctly.
 * Returns 0 when there is nothing to backfill to.
 */
function legacyDisplayId(PDO $pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM displays WHERE tag = ? LIMIT 1");
        $stmt->execute([LEGACY_DISPLAY_TAG]);
        $id = $stmt->fetchColumn();
        if ($id) { return intval($id); }

        $id = $pdo->query("SELECT id FROM displays ORDER BY id ASC LIMIT 1")->fetchColumn();
        return $id ? intval($id) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Run one convergence statement, swallowing the failure that means "already
 * applied". Deliberately silent: there is nowhere to log to on this host, the
 * pattern predates this build, and a convergence failure must never break the
 * request that happened to trigger it — the statement is simply re-attempted on
 * the next authenticated request.
 */
function schemaTry(PDO $pdo, $sql)
{
    try {
        $pdo->exec($sql);
        return true;
    } catch (Exception $e) {
        return false;
    }
}
