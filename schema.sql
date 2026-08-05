-- Lummi Bay Market — Digital Signage · Database schema
--
-- Verified against a phpMyAdmin dump of the live database
-- (`silverad_lummi_market_drive_thru`, server 5.7) on 2026-08-04.
-- Structure only — contains no application/user data.
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
-- canvas_elements — every element on the 1920×1080 canvas. Sections are
-- root-level (section_id IS NULL); all other elements belong to a section
-- and cascade-delete with it.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS canvas_elements (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    section_id     INT(11) DEFAULT NULL COMMENT 'Parent section ID; NULL = root level',
    type           ENUM('section','text','image','video','carousel','marquee','table') NOT NULL,
    block_subtype  ENUM('free','section_header','item_title','price','description') DEFAULT 'free',
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
    CONSTRAINT canvas_elements_ibfk_1 FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE SET NULL,
    CONSTRAINT canvas_elements_ibfk_2 FOREIGN KEY (section_id) REFERENCES canvas_elements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- canvas_settings — single-row (id = 1) global canvas config.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS canvas_settings (
    id      INT(11) NOT NULL AUTO_INCREMENT,
    bg_type ENUM('color','image') DEFAULT 'color',
    bg_val  VARCHAR(255) DEFAULT '#1a1a2e',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO canvas_settings (id, bg_type, bg_val) VALUES (1, 'color', '#1a1a2e');

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
