-- Family Legacy Archive — LAMP edition
-- Run this once against a fresh MySQL/MariaDB database (via phpMyAdmin,
-- or `mysql -u USER -p DBNAME < schema.sql`).
--
-- Multi-subject: one installation (one codebase, one database) can host
-- several independent "subjects" — e.g. one family archive per relative
-- — each resolved by hostname (see includes/subjects.php) and fully
-- separated: its own logins, its own archive content, its own upload
-- directory (ARCHIVE_ROOT_BASE/{slug}), its own optional AI connection.
-- Every per-subject table below carries a `subject_id` FK to `subjects`
-- with ON DELETE CASCADE — deleting a subject row deletes everything it
-- owns. `users`, `webauthn_credentials` (via user_id), and
-- `consumed_tokens` (via user_id) are the only tables scoped to an
-- individual account rather than shared archive content, but still
-- ultimately belong to exactly one subject each — there is no
-- cross-subject account or superadmin role anywhere in this schema.
-- `rate_limits` is the one genuinely install-wide table (a shared
-- IP-keyed abuse-prevention bucket, not subject data).

CREATE TABLE IF NOT EXISTS subjects (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  -- Used to build that subject's upload root: ARCHIVE_ROOT_BASE/{slug}.
  -- Set once at creation time — never exposed as editable afterward,
  -- since renaming it would orphan the uploads directory without also
  -- moving it on disk.
  slug           VARCHAR(64)  NOT NULL,
  -- Matched against $_SERVER['HTTP_HOST'] (port stripped, lowercased)
  -- to resolve which subject a request belongs to — see
  -- includes/subjects.php's subject_resolve_from_request().
  hostname       VARCHAR(255) NOT NULL,
  display_name   VARCHAR(255) NOT NULL DEFAULT '',
  -- 'disabled' hides a subject without deleting its data — hard
  -- deletion (which cascades everything below) is a manual/CLI-only
  -- action, never exposed in any web UI.
  status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
  -- Per-subject AI override — NULL means "inherit the install-wide
  -- .env AI_* default" (see includes/ai_provider.php). Lets each
  -- subject's admin use their own provider/key/model without shell
  -- access to the server, editable post-setup via admin_settings.php.
  ai_provider    VARCHAR(20)  NULL,
  ai_api_key     VARCHAR(255) NULL,
  ai_base_url    VARCHAR(255) NULL,
  ai_model       VARCHAR(100) NULL,
  ai_temperature VARCHAR(10)  NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_hostname (hostname),
  UNIQUE KEY uniq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Install-wide (not per-subject) trusted operator accounts — deliberately
-- separate from `users`, not scoped to any one subject. A master admin
-- logs in via the same login.php on any subject's hostname (see
-- api/login.php, checked before the per-subject users lookup) and gets
-- admin rights on whichever subject they're currently on, via a
-- lazily-provisioned per-subject `users` row (see
-- master_admin_ensure_shadow_user() in includes/master_admin.php) —
-- never a schema-level bypass of the per-subject FK/uniqueness rules
-- everything else in this app relies on. Bootstrapped only via the
-- CLI-only create_master_admin.php — there is deliberately no
-- HTTP-reachable way to create one, since this is the single most
-- powerful account in the system.
CREATE TABLE IF NOT EXISTS master_admins (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  name          VARCHAR(255) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Install-wide (not per-subject) schema version tracker for upgrade.php
-- — see that file's upgrade_current_version()/upgrade_set_version().
-- Split out from site_settings because that table is now one row PER
-- SUBJECT rather than a single install-wide row, and the schema itself
-- (table structure) is shared by every subject in one database, so its
-- version needs exactly one place to live regardless of how many
-- subjects exist.
CREATE TABLE IF NOT EXISTS schema_meta (
  id             TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  schema_version VARCHAR(20) NOT NULL DEFAULT '1.0.0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              CHAR(36)      NOT NULL PRIMARY KEY,
  subject_id      CHAR(36)      NOT NULL,
  name            VARCHAR(255)  NOT NULL,
  email           VARCHAR(255)  NOT NULL,
  password_hash   VARCHAR(255)  NOT NULL,
  -- admin: full access, including user management. author: can add
  -- content and edit/delete their own (see includes/content.php).
  -- reader: can view the archive and use the Ask tab, nothing more. See
  -- user_can_add_content() in includes/auth.php. Scoped entirely to
  -- this user's own subject — there is no role that spans subjects.
  role            ENUM('admin','author','reader') NOT NULL DEFAULT 'reader',
  -- Chosen at signup from the same options as the Ask tab's "Telling
  -- for:" dropdown (see audience_modes() in includes/helpers.php) —
  -- VARCHAR rather than ENUM so adding a new mode never needs a schema
  -- change, only an edit to that one function. Pre-selects the Ask tab's
  -- dropdown on login; the user can still change it per-session.
  default_audience_mode VARCHAR(32) NOT NULL DEFAULT 'adult',
  -- Free-text "why are you requesting access?" from signup.php, shown
  -- verbatim in the admin approval-request email and on /admin.php.
  -- Optional; NULL if left blank.
  signup_reason   TEXT          NULL,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  -- True only for a row auto-provisioned by
  -- master_admin_ensure_shadow_user() (includes/master_admin.php) to
  -- back a master admin's access to this specific subject — never set
  -- by any other code path. Purely a UI label (see admin.js); this row
  -- is otherwise a completely ordinary admin account for this subject,
  -- already protected from deletion/role-change by the same guards
  -- that protect any admin row (api/admin/users.php).
  is_master_admin_shadow TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at     DATETIME      NULL,
  last_login_at   DATETIME      NULL,
  -- Per-subject uniqueness, not global — the same email can be a
  -- separate, independent account on two different subjects.
  UNIQUE KEY uniq_subject_email (subject_id, email),
  CONSTRAINT fk_users_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registered passkeys (WebAuthn credentials) — admin/author only (see
-- includes/webauthn_helper.php), additive to password login, never a
-- replacement for it. One row per registered device/authenticator; a
-- user can have several. credential_id and public_key come straight
-- from the authenticator via the vendored includes/webauthn/ library —
-- never generated or interpreted by this app itself. No subject_id
-- column needed: scoped indirectly via user_id -> users.subject_id, and
-- credential_id is cryptographically unique per device regardless of
-- subject.
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id             CHAR(36)       NOT NULL PRIMARY KEY,
  user_id        CHAR(36)       NOT NULL,
  credential_id  VARBINARY(1023) NOT NULL,
  public_key     TEXT           NOT NULL,
  sign_count     INT UNSIGNED   NOT NULL DEFAULT 0,
  -- User-chosen at registration time (e.g. "MacBook Touch ID") so a
  -- person with several passkeys can tell them apart later.
  label          VARCHAR(255)   NULL,
  created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at   DATETIME       NULL,
  UNIQUE KEY uniq_credential_id (credential_id),
  CONSTRAINT fk_webauthn_credentials_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Records which single-use approve/reject email links have already been
-- acted on, so replaying an old link is a harmless no-op. No subject_id
-- column needed — scoped indirectly via user_id -> users.subject_id.
CREATE TABLE IF NOT EXISTS consumed_tokens (
  user_id  CHAR(36)     NOT NULL,
  nonce    VARCHAR(64)  NOT NULL,
  used_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, nonce),
  CONSTRAINT fk_consumed_tokens_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Simple per-key rate limiting (signup-by-IP, chat-by-session) since PHP
-- has no long-lived process to hold this in memory between requests the
-- way the Node version does. Deliberately install-wide, not per-subject
-- — a shared IP-keyed abuse-prevention bucket, not subject data.
CREATE TABLE IF NOT EXISTS rate_limits (
  rkey         VARCHAR(191) NOT NULL PRIMARY KEY,
  hit_count    INT          NOT NULL DEFAULT 0,
  window_start DATETIME     NOT NULL,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin-added archive material (transcripts, photos, videos) beyond the
-- fixed knowledge/ files. One row per logical item — a transcript, a
-- video, or a photo "album" of one-or-more related photos sharing a
-- title/caption — with the actual file(s) in content_files below.
-- `narrative_note` is a short AI-generated note (see
-- includes/narrative.php) on how the item connects to the existing
-- archive — grounded and citing specific existing elements, or NULL
-- when no connection was found or analysis wasn't available. For photo
-- albums, this one note reflects all the photos considered together in
-- a single analysis pass, not per-photo.
CREATE TABLE IF NOT EXISTS content_items (
  id            CHAR(36)      NOT NULL PRIMARY KEY,
  subject_id    CHAR(36)      NOT NULL,
  type          ENUM('transcript','photo','video','url','story','document','audio') NOT NULL,
  title         VARCHAR(255)  NOT NULL,
  description   TEXT          NULL,
  -- Set only for type='url' — the source page the family member submitted.
  source_url    VARCHAR(2048) NULL,
  narrative_note TEXT         NULL,
  -- NULL means no admin has ever reviewed the current narrative_note —
  -- set to NOW() only by an explicit admin action on the Content page
  -- (see content_update_item() in includes/content.php), and cleared
  -- back to NULL any time narrative_note is (re)written by an unattended
  -- AI pass (content_approve_story(), content_backfill_ai_analysis()),
  -- since that text is new and unreviewed again. Visibility only, not a
  -- gate — an unreviewed note still feeds the Ask tab's knowledge base.
  narrative_note_reviewed_at DATETIME NULL,
  -- Admin-picked keywords (see the Content page's keyword picker) — a
  -- separate, human-curated concept from content_links (AI-suggested,
  -- points at specific archive entries); tags are just free labels for
  -- browsing/filtering content itself.
  tags          JSON          NULL,
  -- Only meaningful for type='story': NULL until an admin approves it
  -- (see content_approve_story() in includes/content.php) — a pending
  -- story isn't shown on the Stories tab and isn't in the AI's
  -- knowledge base yet. NULL for every other type (always immediately
  -- visible, same as before this column existed).
  story_approved_at DATETIME  NULL,
  -- Only meaningful for type='story': a JSON object mapping each URL
  -- found in the story's text to a one-sentence AI-generated summary of
  -- that page, shown as a hover tooltip on the auto-linked URL (see
  -- content_link_summaries() in includes/content.php and
  -- bodyParagraphs() in js/app.js). NULL until content_create_story()
  -- (or content_backfill_ai_analysis() for a pre-existing story) has run
  -- once; an empty JSON object means it ran but the story had no URLs
  -- (or none were fetchable) — both are treated the same by the reader,
  -- but the distinction lets backfill skip rows it's already processed.
  link_summaries JSON        NULL,
  -- Companion video<->transcript pairing (see includes/video_seek.php) —
  -- only ever set between a video item and a transcript item. Kept
  -- symmetric (both rows point at each other) entirely by
  -- content_link_items()/content_unlink_item() in includes/content.php,
  -- never by SQL alone — a plain FK can only guarantee the pointed-to row
  -- exists, not that it points back. ON DELETE SET NULL so deleting
  -- either half of a pair never leaves the other half pointing at a
  -- missing row.
  linked_item_id CHAR(36)     NULL,
  created_by    CHAR(36)      NOT NULL,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_content_items_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_items_user FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_items_linked FOREIGN KEY (linked_item_id)
    REFERENCES content_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per physical file belonging to a content_items row — always
-- exactly one for transcripts/videos, one-or-more for photo albums. The
-- underlying file lives under ARCHIVE_ROOT/uploads/{type}/{file_name}
-- (transcripts are pasted text written to a .md file server-side, so
-- every row has a real file on disk, no nullable special case) — and
-- ARCHIVE_ROOT itself already resolves to that subject's own directory
-- (ARCHIVE_ROOT_BASE/{slug}), so file storage needs no subject-specific
-- path segment beyond that. See includes/content.php. `subject_id` here
-- is denormalized from the parent content_items row (set once at
-- insert time, not just reachable via join) so per-subject backups can
-- filter this table directly. `metadata` is a small curated object
-- (takenAt/make/model/gpsLat/gpsLng/width/height/durationSeconds)
-- auto-extracted from photo/video EXIF via exiftool where available —
-- per-file, since different photos in one album can have different
-- capture data; NULL for transcripts or when extraction yields nothing.
CREATE TABLE IF NOT EXISTS content_files (
  id              CHAR(36)      NOT NULL PRIMARY KEY,
  subject_id      CHAR(36)      NOT NULL,
  content_item_id CHAR(36)      NOT NULL,
  file_name       VARCHAR(255)  NOT NULL,
  original_name   VARCHAR(255)  NOT NULL,
  mime_type       VARCHAR(127)  NOT NULL,
  file_size       INT UNSIGNED  NOT NULL,
  metadata        JSON          NULL,
  sort_order      INT UNSIGNED  NOT NULL DEFAULT 0,
  CONSTRAINT fk_content_files_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_files_item FOREIGN KEY (content_item_id)
    REFERENCES content_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Names to redact from everything served to family members (Ask tab
-- context+replies, Browse tab JSON) — see includes/redaction.php. A
-- serve-time filter only; never mutates knowledge/*.yaml or
-- transcript.md, so removing a row here immediately un-redacts. Never
-- applied to admin-facing views (content management, this list itself),
-- since the admin needs full visibility to manage the archive.
CREATE TABLE IF NOT EXISTS redacted_names (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id CHAR(36)     NOT NULL,
  name       VARCHAR(255) NOT NULL,
  created_by CHAR(36)     NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subject_name (subject_id, name),
  CONSTRAINT fk_redacted_names_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_redacted_names_user FOREIGN KEY (created_by)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI-proposed additions to the canonical knowledge/*.yaml files, derived
-- from a content_items row of type='url' (see includes/narrative.php's
-- narrative_suggest_additions() and includes/knowledge_writer.php). Never
-- applied automatically — an admin must approve each one individually,
-- which is what actually writes into knowledge/*.yaml and data/*.json.
-- `payload` holds the kind-specific fields proposed by the model, plus
-- the citation PHP attaches itself (never trusted from the model).
-- `subject_id` denormalized from the parent content_items row, same
-- rationale as content_files above.
CREATE TABLE IF NOT EXISTS content_suggestions (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id      CHAR(36)     NOT NULL,
  content_item_id CHAR(36)     NOT NULL,
  kind            ENUM('timeline','person','place','quote') NOT NULL,
  payload         JSON         NOT NULL,
  status          ENUM('pending','approved','dismissed') NOT NULL DEFAULT 'pending',
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at      DATETIME     NULL,
  decided_by      CHAR(36)     NULL,
  CONSTRAINT fk_content_suggestions_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_suggestions_item FOREIGN KEY (content_item_id)
    REFERENCES content_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_suggestions_user FOREIGN KEY (decided_by)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Links a content_items row (a photo/video/transcript/URL) to a
-- specific existing archive entry it's relevant to — e.g. a photo that
-- clearly depicts a named person, so the Timeline/People/Places/Quotes
-- tabs can surface "related content" alongside that entry, not just the
-- Ask tab (whose [[photo:ID]] tokens are a separate, chat-time-only
-- mechanism — see knowledge_system_role()). AI-suggested at the same
-- time as narrative_note (see includes/narrative.php's
-- narrative_analyze()), applied by includes/content.php after
-- validating the referenced entity still exists — never trusted blindly
-- from the model. entity_id has no FK constraint since it points at one
-- of four different tables depending on entity_type (no polymorphic FK
-- support in MySQL); orphaned rows are simply skipped at render time if
-- the referenced entity was since deleted, rather than requiring every
-- delete function to also clean this table. `subject_id` denormalized
-- from the parent content_items row, same rationale as content_files.
CREATE TABLE IF NOT EXISTS content_links (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id      CHAR(36)     NOT NULL,
  content_item_id CHAR(36)     NOT NULL,
  entity_type     ENUM('person','place','timeline','quote') NOT NULL,
  entity_id       CHAR(36)     NOT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_content_links_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_content_links_item FOREIGN KEY (content_item_id)
    REFERENCES content_items(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_content_link (content_item_id, entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Core archive tables — the database is the source of truth for
-- the archive itself (not knowledge/*.yaml files). Approving a
-- content_suggestions row (above) inserts directly into these
-- tables; the admin Archive Editor does the same. knowledge_context()
-- (includes/knowledge.php) and api/data.php read from these tables
-- to build the Ask tab's grounding and the Browse tab's JSON — always
-- filtered to the current request's subject (see includes/subjects.php)
-- so one subject's Ask tab can never see another's testimony.
-- ============================================================

-- One row PER SUBJECT (subject_id is the primary key — previously a
-- fixed-id=1 singleton before multi-subject support). Archive
-- identity/branding only — NOT infrastructure config, which stays in
-- .env or on the subjects row. setup_completed_at is the authoritative
-- "has this subject finished the wizard" flag config.php checks on
-- every request; see includes/setup.php.
CREATE TABLE IF NOT EXISTS site_settings (
  subject_id                         CHAR(36)     NOT NULL PRIMARY KEY,
  site_name                          VARCHAR(255) NOT NULL DEFAULT '',
  subject_name                       VARCHAR(255) NOT NULL DEFAULT '',
  -- Used to keep AI-prompt/UI prose grammatical without assuming a
  -- gender — defaults to they/them, editable at setup or in
  -- admin_settings.php afterward.
  subject_pronoun_subject            VARCHAR(20)  NOT NULL DEFAULT 'they',
  subject_pronoun_object             VARCHAR(20)  NOT NULL DEFAULT 'them',
  subject_pronoun_possessive         VARCHAR(20)  NOT NULL DEFAULT 'their',
  -- Free text, not DATE — historical dates are often approximate
  -- ("~1930") and should be preserved as the subject/family stated them.
  subject_birth_date                 VARCHAR(100) NULL,
  subject_birthplace                 VARCHAR(255) NULL,
  -- NULL is the common case (the subject is living) — not an omission
  -- to flag, unlike a missing birth date.
  subject_death_date                 VARCHAR(100) NULL,
  subject_short_bio                  TEXT         NULL,
  closing_quote                      TEXT         NULL,
  closing_quote_attribution          VARCHAR(255) NULL,
  ask_placeholder_text               TEXT         NULL,
  setup_completed_at                 DATETIME     NULL,
  created_at                         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_site_settings_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The archive's source material — a real list rather than a fixed
-- primary/secondary pair, since some archives draw on more than two
-- (a recorded interview, a memoir, a documentary, a relative's written
-- account...). sort_order's first entry is treated as the "primary"
-- source referenced by knowledge_system_role()'s main citation
-- instruction; every other entry gets its own citation rule — flagged
-- as a dramatisation if is_dramatization is set, otherwise just noted
-- as a distinct source to cite by name. Admin-managed via
-- admin_settings.php's Sources tab.
CREATE TABLE IF NOT EXISTS sources (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id        CHAR(36)     NOT NULL,
  label             VARCHAR(255) NOT NULL,
  details           TEXT         NULL,
  is_dramatization  TINYINT(1)   NOT NULL DEFAULT 0,
  -- e.g. "used with the author's permission" — shown on the About tab
  -- alongside this source, most relevant for a dramatization.
  permission_note   TEXT         NULL,
  sort_order        INT UNSIGNED NOT NULL DEFAULT 0,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sources_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Replaces the hardcoded audience_modes() array in includes/helpers.php.
-- ai_guidance is the prose telling the AI how to adjust register for
-- this category — previously hardcoded per-category text inside
-- knowledge_system_role()'s AUDIENCE MODE section, now per-row and
-- editable from admin_settings.php (and at setup time).
CREATE TABLE IF NOT EXISTS audience_modes (
  id           CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id   CHAR(36)     NOT NULL,
  slug         VARCHAR(64)  NOT NULL,
  label        VARCHAR(255) NOT NULL,
  ai_guidance  TEXT         NOT NULL,
  sort_order   INT UNSIGNED NOT NULL DEFAULT 0,
  is_default   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subject_slug (subject_id, slug),
  CONSTRAINT fk_audience_modes_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Core archive: named people. `slug` is a leftover optional short
-- identifier (e.g. "jane_doe") from the retired legacy-YAML importer
-- (removed in 1.24.0) — nothing writes it anymore, but the column stays
-- since dropping it buys nothing. The real primary key is the UUID like
-- every other table in this app. `source_note`/`citation` are free
-- text, replacing the old source_vha/source_book boolean flags —
-- generic enough for any archive's own mix of sources. `sort_order` is
-- an explicit, admin-adjustable display order (same up/down-arrow
-- convention as timeline_entries.sort_order below) — added in 1.31.0,
-- replacing the previous fixed "subject role first, then by
-- created_at" ordering; the migration backfills sort_order to match
-- that same order so existing archives don't visibly reshuffle.
CREATE TABLE IF NOT EXISTS people (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id  CHAR(36)     NOT NULL,
  slug        VARCHAR(128) NULL,
  sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
  names       JSON         NOT NULL,
  role        VARCHAR(255) NULL,
  fate        TEXT         NULL,
  notes       TEXT         NULL,
  -- Disambiguation note — e.g. two people sharing a similar name.
  name_note   TEXT         NULL,
  source_note TEXT         NULL,
  citation    TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subject_slug (subject_id, slug),
  CONSTRAINT fk_people_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS places (
  id               CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id       CHAR(36)     NOT NULL,
  slug             VARCHAR(128) NULL,
  sort_order       INT UNSIGNED NOT NULL DEFAULT 0,
  names            JSON         NOT NULL,
  wartime_country  VARCHAR(255) NULL,
  modern_country   VARCHAR(255) NULL,
  approx_coords    VARCHAR(100) NULL,
  role             VARCHAR(255) NULL,
  notes            TEXT         NULL,
  source_note      TEXT         NULL,
  citation         TEXT         NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subject_slug (subject_id, slug),
  CONSTRAINT fk_places_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sort_order is an explicit, admin-adjustable display order (same
-- pattern as content_files.sort_order above) rather than trying to sort
-- by date_label — historical dates are often hedged/approximate
-- ("~1941 fall") and aren't reliably machine-sortable. date_label is
-- purely the displayed string. historical_date/historical_source hold
-- an externally-documented date for the same event, kept ALONGSIDE —
-- never replacing — the testimony's own hedged date_label.
CREATE TABLE IF NOT EXISTS timeline_entries (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id        CHAR(36)     NOT NULL,
  sort_order        INT UNSIGNED NOT NULL DEFAULT 0,
  date_label        VARCHAR(100) NOT NULL,
  event             TEXT         NOT NULL,
  source_note       VARCHAR(255) NULL,
  confidence        ENUM('high','medium','low') NULL,
  note              TEXT         NULL,
  historical_date   VARCHAR(100) NULL,
  historical_source TEXT         NULL,
  citation          TEXT         NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_timeline_entries_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Verbatim quote bank. quote_text must be copied exactly from the
-- source — never paraphrased (enforced by convention/prompt, same as
-- today's quotes.yaml header comment, not by the schema). sort_order —
-- see people.sort_order's comment above for the convention; added in
-- 1.31.0, backfilled to match the previous created_at ordering.
CREATE TABLE IF NOT EXISTS quotes (
  id          CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id  CHAR(36)     NOT NULL,
  sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
  speaker     VARCHAR(255) NOT NULL,
  source_note VARCHAR(255) NULL,
  tags        JSON         NULL,
  quote_text  TEXT         NOT NULL,
  citation    TEXT         NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_quotes_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The app-wide keyword list — see includes/keywords.php's own comment
-- for the full rationale. NOT a foreign key target for quotes.tags or
-- content_items.tags (both stay plain JSON string arrays, unchanged) —
-- this is a curated suggestion list those pickers draw from, populated
-- automatically as a side effect of tagging, editable afterward
-- (admin_settings.php's Keywords tab) to fix a typo or merge duplicate
-- spellings without needing every existing tagged item touched by hand.
CREATE TABLE IF NOT EXISTS keywords (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  subject_id CHAR(36)     NOT NULL,
  label      VARCHAR(100) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subject_label (subject_id, label),
  CONSTRAINT fk_keywords_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row PER SUBJECT (subject_id is the primary key — previously a
-- fixed-id=1 singleton before multi-subject support). The primary
-- testimony transcript, if the family has one (e.g. a recorded
-- interview). raw_markdown uses the convention "## Tape N" for section
-- breaks and "**SUBJECT:**" / "**INTERVIEWER:**" for speaker turns —
-- parsed at render time by includes/transcript.php's
-- transcript_parse_tapes().
CREATE TABLE IF NOT EXISTS primary_testimony (
  subject_id      CHAR(36)     NOT NULL PRIMARY KEY,
  interview_label VARCHAR(255) NULL,
  interview_date  VARCHAR(100) NULL,
  location        VARCHAR(255) NULL,
  interviewer     VARCHAR(255) NULL,
  videographer    VARCHAR(255) NULL,
  length_label    VARCHAR(50)  NULL,
  raw_markdown    LONGTEXT     NULL,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_primary_testimony_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row PER SUBJECT (subject_id is the primary key — previously a
-- fixed-id=1 singleton before multi-subject support). Freeform curator's
-- notes on discrepancies between sources, naming conventions, etc. —
-- rendered through a small markdown-subset converter
-- (headers/bold/inline-code/paragraphs only), see
-- includes/markdown_lite.php.
CREATE TABLE IF NOT EXISTS discrepancy_notes (
  subject_id       CHAR(36) NOT NULL PRIMARY KEY,
  content_markdown LONGTEXT NULL,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_discrepancy_notes_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
