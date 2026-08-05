-- Lummi Bay Market — Digital Signage · Database schema
--
-- Verified against a phpMyAdmin dump of the live database
-- (`silverad_lummi_market_drive_thru`, server 5.7) on 2026-08-04, then extended
-- by Phase 1 of the multi-display build (`displays`, `canvas_elements.display_id`).
-- Structure only — contains no application/user data.
--
-- NOTE on Phase 1: the live server does not have the `displays` table or the
-- `display_id` column yet either. ensureSignageSchema() in lib/schema.php adds
-- them at runtime on the first authenticated request, backfills every existing
-- element to the drive-thru Display, and carries the background over from
-- canvas_settings. This file is what a fresh rebuild should produce.
--
-- NOTE on the login-lockout columns: the three columns on `users`
-- (failed_attempts, last_failed_at, locked_until) are NOT yet present on the
-- live server. The application adds them at runtime via an idempotent
-- ALTER TABLE (see ensureLockoutColumns() in auth.php) on the first login or
-- password reset. They are included here so a fresh rebuild matches what the
-- current code expects. See docs/adr/0001-account-keyed-login-lockout.md.

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
    -- Login lockout — added at runtime by auth.php on the live server
    -- (see the header note above).
    failed_attempts INT(11)      NOT NULL DEFAULT 0,
    last_failed_at  DATETIME     NULL DEFAULT NULL,
    locked_until    DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY username (username),
    UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- assets — reusable content library (text snippets + uploaded media).
-- `content` holds either the text or an `uploads/…` relative path.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assets (
    id         INT(11)      NOT NULL AUTO_INCREMENT,
    type       ENUM('text','image','video') NOT NULL,
    content    TEXT         NOT NULL,
    label      VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- block_styles — default typography per block type, keyed by block_type.
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

-- ─────────────────────────────────────────────────────────────
-- displays — one row per configured sign (a Display). Carries the canvas
-- dimensions that used to be hardcoded as 1920×1080, the background that used
-- to live in the single-row canvas_settings, the publish stamp (ADR-0006) and
-- the edit-lock columns (ADR-0007, behaviour arrives in Phase 5).
-- Added by Phase 1; created at runtime by ensureSignageSchema() in lib/schema.php.
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
    lock_holder_id    INT(11)      DEFAULT NULL COMMENT 'edit lock holder (Phase 5)',
    lock_activity_at  DATETIME     DEFAULT NULL COMMENT 'last real interaction by the holder (Phase 5)',
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
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT(11) NOT NULL AUTO_INCREMENT,
    user_id    INT(11) NOT NULL,
    passcode   CHAR(6) NOT NULL,
    expires_at DATETIME NOT NULL,
    used       TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY user_id (user_id),
    CONSTRAINT password_resets_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
