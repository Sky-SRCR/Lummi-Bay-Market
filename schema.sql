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
--     closed_at is in that plan too, as of the same section. It used to be one
--     ungated ALTER per admin-panel load from AccountStore::ensureSchema().
--   * ResetTokenStore::ensureSchema() in lib/password_resets.php, from the
--     password-reset page — the password_resets table and its `attempts` column.
--     The one convergence still running unauthenticated, and deliberately: the
--     table is not optional the way a lockout counter is, and the person who needs
--     it is by definition the person who cannot sign in.
--
-- So the order of authority is: lib/schema.php and lib/accounts.php decide what the
-- live database becomes, and this file has to agree with them. If the two ever
-- disagree, they are both wrong until someone reconciles them — start with
-- `php tools/rehearse_phase1.php`, which reports what a real MySQL database
-- actually ended up with.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────
-- workspace_themes — what the application itself is painted in.
--
-- The other noun (v2 roadmap decision 1): a Brand is what a customer sees on a TV,
-- a Workspace Theme is what an employee's screen is painted in. Nothing here ever
-- reaches a Screen. Sixteen roles, each NOT NULL with today's colour as its
-- default, so a theme is never half a set of colours — and no column for anything
-- drawn on the canvas, which belongs to the Brand (decision 11). Thirteen of them
-- are surfaces; the last three are the colour of the text drawn on those surfaces,
-- which were literals in three stylesheets until a theme lightened a panel and left
-- its own labels unreadable.
--
-- Created before `users`, because `users` points at it. There is deliberately no
-- seeded row: the store default is `branding_config.php` plus these defaults,
-- answered by SiteChrome when no theme is worn, so a fresh install with no themes
-- at all looks exactly as it always has.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS workspace_themes (
    id            INT(11)     NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80) NOT NULL,
    nav_bg        VARCHAR(7)  NOT NULL DEFAULT '#1a252f',
    nav_border    VARCHAR(7)  NOT NULL DEFAULT '#0d1b24',
    nav_text      VARCHAR(7)  NOT NULL DEFAULT '#ffffff',
    accent        VARCHAR(7)  NOT NULL DEFAULT '#207ab6',
    work_area     VARCHAR(7)  NOT NULL DEFAULT '#2c3e50',
    panel         VARCHAR(7)  NOT NULL DEFAULT '#1a252f',
    panel_border  VARCHAR(7)  NOT NULL DEFAULT '#34495e',
    status_good   VARCHAR(7)  NOT NULL DEFAULT '#1e8449',
    status_warn   VARCHAR(7)  NOT NULL DEFAULT '#7d6608',
    status_bad    VARCHAR(7)  NOT NULL DEFAULT '#7b3f3f',
    status_busy   VARCHAR(7)  NOT NULL DEFAULT '#4b3869',
    status_note   VARCHAR(7)  NOT NULL DEFAULT '#7a4a12',
    selection     VARCHAR(7)  NOT NULL DEFAULT '#e74c3c',
    panel_text     VARCHAR(7) NOT NULL DEFAULT '#dfe6ec',
    work_area_text VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    fill_text      VARCHAR(7) NOT NULL DEFAULT '#ffffff',
    PRIMARY KEY (id),
    UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    -- Account closure — added at runtime by signageSchemaPlan().
    -- An account is never deleted: the row stays so its id can never be handed to
    -- somebody else, which is what would let a stale grant, a held edit lock or a
    -- publish record silently change whose they are. Distinct from is_active on
    -- purpose — inactive is a suspension an admin can undo, closed is permanent.
    closed_at       DATETIME     NULL DEFAULT NULL,
    -- Which Workspace Theme this account chose — added at runtime by
    -- signageSchemaPlan(). NULL is not a missing answer: it means "use the store
    -- default", which is `branding_config.php` plus the documented defaults, and it
    -- is what every account means until somebody picks something else. The foreign
    -- key is declared with the table it points at, below, because this file creates
    -- `users` first.
    workspace_theme_id INT(11)   DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY username (username),
    UNIQUE KEY email (email),
    KEY workspace_theme_id (workspace_theme_id),
    -- No ON DELETE clause, so RESTRICT: deleting a theme somebody chose is refused.
    -- SET NULL would move people back to the store default on one click without
    -- telling them, which is the merge invariant 5 exists to prevent.
    CONSTRAINT users_ibfk_1 FOREIGN KEY (workspace_theme_id) REFERENCES workspace_themes (id)
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
-- brands — a named, reusable visual identity a Display wears (ADR-0011).
--
-- The six branded block-type standards (in block_styles, keyed by brand), a
-- palette of colours offered as swatches, a logo asset and a default canvas
-- background. Several Displays share one: a venue with three boards has one red,
-- edited once.
--
-- This replaces the "one set of standards for the whole install" of roadmap
-- decision C. The installation stopped being one store — it drives signs in
-- several venues on one property — and one set of colours across a restaurant, a
-- bar and a casino floor is not a shared look but a defect that reaches every
-- screen.
--
-- `name` is UNIQUE so a person picking one on a form can tell them apart. The
-- palette is six ordered slots and deliberately not named roles: it is offered and
-- never enforced, and a role is an instruction. The logo is a library row the
-- Builder can place in one click — the Viewer never draws it by itself, because a
-- fixed corner and size cannot be right for both a landscape menu board and a
-- portrait specials board (ADR-0004).
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS brands (
    id            INT(11)      NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80)  NOT NULL,
    logo_asset_id INT(11)      DEFAULT NULL COMMENT 'the venue logo, placed by the Builder in one click',
    bg_type       ENUM('color','image') NOT NULL DEFAULT 'color',
    bg_val        VARCHAR(255) NOT NULL DEFAULT '#1a1a2e',
    palette_1     VARCHAR(7)   DEFAULT NULL,
    palette_2     VARCHAR(7)   DEFAULT NULL,
    palette_3     VARCHAR(7)   DEFAULT NULL,
    palette_4     VARCHAR(7)   DEFAULT NULL,
    palette_5     VARCHAR(7)   DEFAULT NULL,
    palette_6     VARCHAR(7)   DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY name (name),
    KEY logo_asset_id (logo_asset_id),
    -- SET NULL: a logo tidied out of the Asset Library must not take the venue's
    -- colours and typography with it.
    CONSTRAINT brands_ibfk_1 FOREIGN KEY (logo_asset_id) REFERENCES assets (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The Brand a fresh install starts with, so `displays.brand_id NOT NULL` has
-- something to point at before anybody has opened the Admin Panel. Named
-- generically because this file cannot read SITE_NAME; setup.php asks for the
-- venue's name and renames this one, and on an existing database convergence
-- creates the first Brand named after SITE_NAME instead (lib/schema.php).
-- The id is explicit so the standards below can reference it.
INSERT IGNORE INTO brands (id, name) VALUES (1, 'Store Brand');

-- ─────────────────────────────────────────────────────────────
-- block_styles — Brand Standards: typography per branded block type, per Brand.
--
-- Keyed on (brand_id, block_type). It was keyed on block_type alone and shared by
-- every Display until ADR-0011 reversed that; lib/schema.php re-keys a live
-- database in place, adding the column nullable, backfilling every row to the
-- first Brand, tightening it, and only then swapping the primary key.
--
-- One row per branded block type has to exist **for each Brand**. The Brand
-- Standards form saves with `UPDATE … WHERE brand_id = ? AND block_type = ?`, so a
-- missing row is not created by using the UI — the save silently does nothing. The
-- seed below is why a fresh install has something to edit; BrandStyles::seedFor()
-- does it for every Brand created afterwards, and lib/schema.php does it at runtime
-- for a database that predates them. All three take their values from
-- BrandStyles::STARTING_POINTS, which is the only copy.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS block_styles (
    brand_id    INT(11)      NOT NULL,
    block_type  VARCHAR(50)  NOT NULL,
    font_family VARCHAR(100) DEFAULT 'Arial',
    font_size   INT(11)      DEFAULT 16,
    font_color  VARCHAR(50)  DEFAULT '#000000',
    font_weight VARCHAR(20)  DEFAULT 'normal',
    font_style  VARCHAR(20)  DEFAULT 'normal',
    line_height DECIMAL(4,2) DEFAULT 1.40,
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (brand_id, block_type),
    -- CASCADE, unlike the logo above: a Brand's standards are part of the Brand and
    -- mean nothing without it. Deleting a Brand any Display still wears is refused
    -- by BrandAdmin long before this, naming the signs.
    CONSTRAINT block_styles_ibfk_1 FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starting points for a fresh install, not a copy of the live values — the store
-- edits these in Admin Panel → Display Branding and its own numbers win.
-- INSERT IGNORE, so re-running this file never overwrites what is there.
INSERT IGNORE INTO block_styles
    (brand_id, block_type, font_family, font_size, font_color, font_weight, font_style, line_height) VALUES
    (1, 'section_header', 'Arial', 36, '#ffffff', 'bold',   'normal', 1.30),
    (1, 'item_title',     'Arial', 24, '#ffffff', 'bold',   'normal', 1.30),
    (1, 'item_title_2',   'Arial', 24, '#27ae60', 'bold',   'normal', 1.30),
    (1, 'price',          'Arial', 30, '#e74c3c', 'bold',   'normal', 1.20),
    (1, 'price_2',        'Arial', 30, '#e74c3c', 'bold',   'normal', 1.20),
    (1, 'description',    'Arial', 16, '#bdc3c7', 'normal', 'normal', 1.40);

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
    brand_id          INT(11)      NOT NULL COMMENT 'the Brand this sign wears (ADR-0011)',
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY tag (tag),
    KEY last_published_by (last_published_by),
    KEY lock_holder_id (lock_holder_id),
    KEY brand_id (brand_id),
    CONSTRAINT displays_ibfk_1 FOREIGN KEY (last_published_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT displays_ibfk_2 FOREIGN KEY (lock_holder_id) REFERENCES users (id) ON DELETE SET NULL,
    -- No ON DELETE clause, so RESTRICT stands: deleting a Brand a sign still wears is
    -- refused by BrandAdmin with a sentence naming the signs, and this is the database
    -- saying the same thing to anything that reaches the table another way. SET NULL or
    -- CASCADE would repaint or destroy three signs in a restaurant on one click.
    CONSTRAINT displays_ibfk_3 FOREIGN KEY (brand_id) REFERENCES brands (id)
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
