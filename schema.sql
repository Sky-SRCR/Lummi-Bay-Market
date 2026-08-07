-- Lummi Bay Market — Digital Signage · Database schema
--
-- Verified against a phpMyAdmin dump of the live database
-- (`silverad_lummi_market_drive_thru`, server 5.7) on 2026-08-04, then brought
-- up to what the multi-display build expects (Phases 1–5): the `displays` table,
-- `canvas_elements.display_id`, `display_permissions`, the publish stamp and the
-- edit-lock columns. Structure only — no store content and no accounts.
--
-- This file is what a **fresh rebuild** should produce. It is not a description
-- of the live server, which lags it: the live database still has none of the
-- multi-display tables or columns, and none of the three login-lockout columns
-- on `users`. That gap is deliberate and self-closing — there is no migration
-- tool here, so the application converges the schema at runtime with idempotent
-- statements:
--
--   * ensureSignageSchema() in lib/schema.php, on every authenticated request —
--     the newer canvas_elements columns, the widened ENUMs, `displays`,
--     `display_permissions`, the assets pool marker, and the display_id backfill
--     that hands every pre-existing element to the drive-thru Display. Never run
--     on the public get_layout poll, with one bounded exception: a table that is
--     genuinely absent is repaired from wherever the failure happened, through
--     repairSchemaAfterFailure(), which will not do it inside a transaction, will
--     not do it twice at once, and will not retry for five minutes (§4q). It
--     reads information_schema first and sends
--     only the statements that are actually missing, so a database matching this
--     file is not altered at all — see docs/BUILD-REFERENCE.md §4o for why an
--     ALTER that "does nothing" is not free. A statement it said was needed and
--     which is then refused is logged and emailed to the admins (§4p), so a
--     column that never landed is no longer silent.
--     The three login-lockout columns — failed_attempts, last_failed_at,
--     locked_until — are part of that plan as of BUILD-REFERENCE section 4v. They
--     used to be three unconditional ALTERs fired from the login page on every
--     sign-in POST, which made them the one piece of DDL in the app reachable with
--     no account at all; see docs/adr/0001-account-keyed-login-lockout.md.
--   * AccountStore::ensureSchema() in lib/accounts.php, from the admin panel —
--     closed_at. Still ungated, and still on the list.
--
-- So the order of authority is: lib/schema.php and lib/accounts.php decide what the
-- live database becomes, and this file has to agree with them. If the two ever
-- disagree, they are both wrong until someone reconciles them — start with
-- `php tools/rehearse_phase1.php`, which reports what a real MySQL database
-- actually ended up with.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- users — builder accounts (+ account-keyed login lockout state).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT(11)      NOT NULL AUTO_INCREMENT,
    username        VARCHAR(100) NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','basic') NOT NULL DEFAULT 'basic',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Login lockout — added at runtime by signageSchemaPlan() on the live server
    -- (see the header note above).
    failed_attempts INT(11)      NOT NULL DEFAULT 0,
    last_failed_at  DATETIME     NULL DEFAULT NULL,
    locked_until    DATETIME     NULL DEFAULT NULL,
    -- Account closure — added at runtime by lib/accounts.php via the admin panel.
    -- An account is never deleted: the row stays so its id can never be handed to
    -- somebody else, which is what would let a stale grant, a held edit lock or a
    -- publish record silently change whose they are. Distinct from is_active on
    -- purpose — inactive is a suspension an admin can undo, closed is permanent.
    closed_at       DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY username (username),
    UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- assets — reusable content library (text snippets + uploaded media).
-- `content` holds either the text or an `uploads/…` relative path.
-- ─────────────────────────────────────────────────────────────
-- `auto_pooled` marks a row a publish created by copying a text block's content
-- out of the canvas, rather than one a person typed or uploaded. Only pooled rows
-- are ever swept when nothing points at them: an unused row somebody made on
-- purpose is their next job, not junk. Editing a row clears the marker — naming
-- it is how an admin adopts it. See lib/assets.php.
CREATE TABLE IF NOT EXISTS assets (
    id          INT(11)      NOT NULL AUTO_INCREMENT,
    type        ENUM('text','image','video') NOT NULL,
    content     TEXT         NOT NULL,
    label       VARCHAR(255) DEFAULT NULL,
    auto_pooled TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- block_styles — Brand Standards: typography per branded block type, keyed by
-- block_type and shared by every Display (a deliberate choice — per-Display
-- typography is a clean later addition, see the roadmap's "out of scope").
--
-- One row per branded block type has to exist. The Brand Standards form saves
-- with `UPDATE … WHERE block_type = ?`, so a missing row is not created by using
-- the UI — the save silently does nothing. The seed below is why a fresh install
-- has something to edit; lib/schema.php seeds the same rows at runtime for a
-- database that predates them.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS block_styles (
    block_type  VARCHAR(50)  NOT NULL,
    font_family VARCHAR(100) DEFAULT 'Arial',
    font_size   INT(11)      DEFAULT 16,
    font_color  VARCHAR(50)  DEFAULT '#000000',
    font_weight VARCHAR(20)  DEFAULT 'normal',
    font_style  VARCHAR(20)  DEFAULT 'normal',
    line_height DECIMAL(4,2) DEFAULT 1.40,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (block_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starting points for a fresh install, not a copy of the live values — the store
-- edits these in Admin Panel → Display Branding and its own numbers win.
-- INSERT IGNORE, so re-running this file never overwrites what is there.
INSERT IGNORE INTO block_styles
    (block_type, font_family, font_size, font_color, font_weight, font_style, line_height) VALUES
    ('section_header', 'Arial', 36, '#ffffff', 'bold',   'normal', 1.30),
    ('item_title',     'Arial', 24, '#ffffff', 'bold',   'normal', 1.30),
    ('item_title_2',   'Arial', 24, '#27ae60', 'bold',   'normal', 1.30),
    ('price',          'Arial', 30, '#e74c3c', 'bold',   'normal', 1.20),
    ('price_2',        'Arial', 30, '#e74c3c', 'bold',   'normal', 1.20),
    ('description',    'Arial', 16, '#bdc3c7', 'normal', 'normal', 1.40);

-- ─────────────────────────────────────────────────────────────
-- displays — one row per configured sign (a Display). Carries the canvas
-- dimensions that used to be hardcoded as 1920×1080, the background that used
-- to live in the single-row canvas_settings, the publish stamp (ADR-0006) and
-- the edit-lock columns (ADR-0007).
--
-- `tag` is the screen name tag, and it is a URL contract: every Viewer names its
-- Display (ADR-0003), so renaming a tag changes the address the Screen must be
-- pointed at. The canvas dimensions are fixed when the row is created and never
-- edited afterwards (ADR-0004) — every element position is an absolute integer,
-- and shrinking a canvas would hide rows that still exist.
--
-- Both lock columns are read together and neither is authoritative alone: a
-- holder with lock_activity_at older than the idle window is a **lapsed** lock,
-- which is free. Nothing clears it on a schedule; LockState decides on read.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS displays (
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
    KEY lock_holder_id (lock_holder_id),
    CONSTRAINT displays_ibfk_1 FOREIGN KEY (last_published_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT displays_ibfk_2 FOREIGN KEY (lock_holder_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- canvas_elements — every element on one Display's canvas. Sections are
-- root-level (section_id IS NULL); all other elements belong to a section
-- and cascade-delete with it. Deleting a Display destroys its layout.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS canvas_elements (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    display_id     INT(11) NOT NULL COMMENT 'Owning Display; every query is scoped by this',
    section_id     INT(11) DEFAULT NULL COMMENT 'Parent section ID; NULL = root level',
    type           ENUM('section','text','image','video','carousel','marquee','table') NOT NULL,
    block_subtype  ENUM('free','section_header','item_title','item_title_2','price','price_2','description') DEFAULT 'free',
    x_pos          INT(11) NOT NULL DEFAULT 0,
    y_pos          INT(11) NOT NULL DEFAULT 0,
    width          INT(11) NOT NULL DEFAULT 200,
    height         INT(11) NOT NULL DEFAULT 100,
    manual_content TEXT,
    asset_id       INT(11) DEFAULT NULL,
    section_bg     VARCHAR(255) DEFAULT NULL COMMENT 'Background image path for section blocks',
    font_family    VARCHAR(100) DEFAULT 'Arial',
    font_size      INT(11) DEFAULT 16,
    font_color     VARCHAR(50) DEFAULT '#000000',
    font_weight    VARCHAR(20) DEFAULT 'normal',
    font_style     VARCHAR(20) DEFAULT 'normal',
    line_height    DECIMAL(4,2) DEFAULT 1.40,
    locked         TINYINT(1) NOT NULL DEFAULT 0,
    sort_order     INT(11) NOT NULL DEFAULT 0,
    text_align     VARCHAR(16) NOT NULL DEFAULT '',
    z_index        INT(11) NOT NULL DEFAULT 1,
    hidden         TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY asset_id (asset_id),
    KEY section_id (section_id),
    KEY display_id (display_id),
    CONSTRAINT canvas_elements_ibfk_1 FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT canvas_elements_ibfk_2 FOREIGN KEY (section_id) REFERENCES canvas_elements (id) ON DELETE CASCADE,
    CONSTRAINT canvas_elements_ibfk_3 FOREIGN KEY (display_id) REFERENCES displays (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- display_permissions — one row per grant: this account may edit this Display
-- (ADR-0005). A grant *is* the row's existence; there is deliberately no "level"
-- column, because what an account may do once inside a Display comes from
-- users.role. Two axes: the grant says which Displays, the role says how much.
--
-- Admins are never granted anything — they hold every Display by role — so an
-- empty table means "no basic account can edit anything yet", which is the right
-- default for a store that has been running on admin accounts only.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS display_permissions (
    id         INT(11)   NOT NULL AUTO_INCREMENT,
    display_id INT(11)   NOT NULL,
    user_id    INT(11)   NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY display_user (display_id, user_id),
    KEY user_id (user_id),
    -- Both cascade: a grant is meaningless once either side is gone, and a stale
    -- row pointing at a reused id would hand someone access nobody gave them.
    CONSTRAINT display_permissions_ibfk_1 FOREIGN KEY (display_id) REFERENCES displays (id) ON DELETE CASCADE,
    CONSTRAINT display_permissions_ibfk_2 FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- canvas_settings — RETIRED by Phase 1. It held one row (id = 1) with the
-- single sign's background; that now lives on the Display. No application code
-- reads or writes this table any more — lib/schema.php reads it once, when it
-- creates the first Display, to carry the background forward.
--
-- Left in place on the live server deliberately, as a rollback artefact for the
-- one deploy where it might matter. A fresh install does not need it, so it is
-- no longer created here.
-- ─────────────────────────────────────────────────────────────

-- ─────────────────────────────────────────────────────────────
-- password_resets — one-time emailed 6-digit passcodes (30-min expiry).
--
-- `attempts` is the guess budget, and it is on this row rather than in the
-- visitor's session on purpose: session state belongs to whoever is guessing, so
-- a counter kept there was reset by clearing a cookie and five tries became as
-- many as anyone wanted. See lib/password_resets.php.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT(11) NOT NULL AUTO_INCREMENT,
    user_id    INT(11) NOT NULL,
    passcode   CHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    attempts   INT(11) NOT NULL DEFAULT 0 COMMENT 'guesses spent against this code, all browsers',
    PRIMARY KEY (id),
    KEY user_id (user_id),
    CONSTRAINT password_resets_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
