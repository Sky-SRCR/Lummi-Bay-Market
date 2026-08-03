-- Lummi Bay Market — Digital Signage · Database schema
--
-- Reconstructed from the application's queries (no live dump was available
-- at authoring time), so it reflects the shape the code expects. It includes
-- the columns that the app adds lazily at runtime via idempotent
-- `try { ALTER TABLE ... } catch {}` blocks (in api.php and auth.php) — those
-- inline migrations remain the source of truth for incremental changes; this
-- file is for standing up a fresh database or for version-controlled review.
--
-- Engine/charset chosen for foreign-key support and full Unicode.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- users — builder accounts. Login lockout state lives here
-- (see docs/adr/0001-account-keyed-login-lockout.md).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(64)  NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','basic') NOT NULL DEFAULT 'basic',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- brute-force lockout (account-keyed)
    failed_attempts INT          NOT NULL DEFAULT 0,
    last_failed_at  DATETIME     NULL,
    locked_until    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- password_resets — one-time emailed 6-digit passcodes (30-min expiry).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    passcode   CHAR(6)      NOT NULL,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- assets — reusable content library (text snippets + uploaded media).
-- `content` holds either the text or an `uploads/…` relative path.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assets (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type    VARCHAR(16)  NOT NULL,          -- 'text' | 'image' | 'video'
    content TEXT         NOT NULL,
    label   VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- canvas_elements — every element on the 1920×1080 canvas. Sections are
-- top-level (section_id IS NULL); all other elements belong to a section
-- and cascade-delete with it.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS canvas_elements (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id    INT UNSIGNED NULL,
    type          ENUM('section','text','image','video','carousel','marquee','table') NOT NULL,
    block_subtype VARCHAR(50)  NOT NULL DEFAULT '',
    x_pos         INT          NOT NULL DEFAULT 0,
    y_pos         INT          NOT NULL DEFAULT 0,
    width         INT          NOT NULL DEFAULT 200,
    height        INT          NOT NULL DEFAULT 100,
    section_bg    VARCHAR(255) NOT NULL DEFAULT '',
    manual_content TEXT        NULL,
    asset_id      INT UNSIGNED NULL,
    font_family   VARCHAR(100) NOT NULL DEFAULT 'Arial',
    font_size     INT          NOT NULL DEFAULT 16,
    font_color    VARCHAR(20)  NOT NULL DEFAULT '#000000',
    font_weight   VARCHAR(20)  NOT NULL DEFAULT 'normal',
    font_style    VARCHAR(20)  NOT NULL DEFAULT 'normal',
    line_height   DECIMAL(4,2) NOT NULL DEFAULT 1.40,
    text_align    VARCHAR(16)  NOT NULL DEFAULT '',
    locked        TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order    INT          NOT NULL DEFAULT 0,
    z_index       INT          NOT NULL DEFAULT 1,
    hidden        TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_canvas_elements_section (section_id),
    KEY idx_canvas_elements_asset (asset_id),
    CONSTRAINT fk_canvas_elements_section
        FOREIGN KEY (section_id) REFERENCES canvas_elements (id) ON DELETE CASCADE,
    CONSTRAINT fk_canvas_elements_asset
        FOREIGN KEY (asset_id) REFERENCES assets (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────
-- canvas_settings — single-row (id = 1) global canvas config.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS canvas_settings (
    id      INT UNSIGNED NOT NULL,
    bg_type VARCHAR(16)  NOT NULL DEFAULT 'color',   -- 'color' | 'image'
    bg_val  VARCHAR(255) NOT NULL DEFAULT '#1a1a2e',
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO canvas_settings (id, bg_type, bg_val) VALUES (1, 'color', '#1a1a2e');

-- ─────────────────────────────────────────────────────────────
-- block_styles — default typography per block type. Keyed by block_type.
-- Seeded by api.php with item_title_2 / price_2 on authenticated requests.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS block_styles (
    block_type  VARCHAR(50)  NOT NULL,
    font_family VARCHAR(100) NOT NULL DEFAULT 'Arial',
    font_size   INT          NOT NULL DEFAULT 16,
    font_color  VARCHAR(20)  NOT NULL DEFAULT '#000000',
    font_weight VARCHAR(20)  NOT NULL DEFAULT 'normal',
    font_style  VARCHAR(20)  NOT NULL DEFAULT 'normal',
    line_height DECIMAL(4,2) NOT NULL DEFAULT 1.40,
    PRIMARY KEY (block_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
