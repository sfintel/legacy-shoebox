<?php
declare(strict_types=1);

// The versioned step list upgrade.php walks through. Add a new entry
// here in the same commit that bumps VERSION and adds a CHANGELOG.md
// "Database changes"/"Environment changes" section — the two should
// always say the same thing; this is what actually applies it, the
// changelog is what a human reads.
//
// Every 'db' closure MUST check before it alters (information_schema,
// SHOW COLUMNS, etc.) rather than assuming a clean starting state —
// upgrade.php replays every step newer than the recorded version, and a
// fresh install or an install that skipped several versions needs every
// step to be safe to run against a database that may already be
// partway there. See the "tags" column precedent in this project's own
// history for the pattern (checked information_schema.columns before
// ALTER TABLE).
//
// 'env' is a plain list of human-readable instructions — upgrade.php
// can't safely rewrite .env itself (it holds real secrets and the
// script has no way to know what value belongs on a new line), so it
// just prints these at the end for the admin to apply by hand.
function migrations_steps(): array
{
    return [
        '1.1.0' => [
            'description' => 'Backup & Restore admin functionality',
            'db' => null,
            'env' => [],
        ],
        '1.2.0' => [
            'description' => 'Automated upgrade runner (upgrade.sh/upgrade.php) + schema_version tracking',
            'db' => static function (PDO $pdo): void {
                $exists = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'site_settings' AND column_name = 'schema_version'"
                )->fetchColumn();
                if ((int) $exists === 0) {
                    $pdo->exec("ALTER TABLE site_settings ADD COLUMN schema_version VARCHAR(20) NOT NULL DEFAULT '1.0.0' AFTER setup_completed_at");
                }
            },
            'env' => [],
        ],
        '1.2.1' => [
            'description' => 'Show app version on the About tab',
            'db' => null,
            'env' => [],
        ],
        '1.3.0' => [
            'description' => 'deploy.sh — one-command git pull + sync + upgrade.sh',
            'db' => null,
            'env' => [],
        ],
        '1.3.1' => [
            'description' => 'Fix deploy.sh rsync failure on root-owned webroots (--omit-dir-times)',
            'db' => null,
            'env' => [],
        ],
        '1.3.2' => [
            'description' => '--omit-dir-times alone was not enough (rsync also failed on --perms) — deploy.sh now skips times/perms/owner/group entirely and uses checksums',
            'db' => null,
            'env' => [],
        ],
        '1.4.0' => [
            'description' => 'Fix related-content links/icons for URL sources, network-first api/data.php caching, keyword-picker live filtering, admin/author/reader roles',
            'db' => static function (PDO $pdo): void {
                // Widen role to accept the new values alongside the old
                // 'member' one, migrate every row off 'member' using
                // whatever can_add_content said (if that column is still
                // there — a from-scratch 1.4.0+ install never has it),
                // then narrow the enum back down. Checking for 'member'
                // in the current COLUMN_TYPE is what makes this safe to
                // replay: a database already migrated has no 'member'
                // left, so this whole block is skipped on a second run.
                $roleType = (string) $pdo->query(
                    "SELECT COLUMN_TYPE FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'role'"
                )->fetchColumn();
                if (str_contains($roleType, "'member'")) {
                    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','author','reader','member') NOT NULL DEFAULT 'reader'");
                    $hasCanAddContent = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'can_add_content'"
                    )->fetchColumn() > 0;
                    if ($hasCanAddContent) {
                        $pdo->exec("UPDATE users SET role = 'author' WHERE role = 'member' AND can_add_content = 1");
                    }
                    $pdo->exec("UPDATE users SET role = 'reader' WHERE role = 'member'");
                    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','author','reader') NOT NULL DEFAULT 'reader'");
                }
                $hasCanAddContent = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'can_add_content'"
                )->fetchColumn();
                if ($hasCanAddContent > 0) {
                    $pdo->exec('ALTER TABLE users DROP COLUMN can_add_content');
                }
            },
            'env' => [],
        ],
        '1.4.1' => [
            'description' => 'Fix upgrade.php: DDL causes an implicit MySQL commit, breaking the per-step transaction wrapper on any migration mixing ALTER TABLE with other statements',
            'db' => null,
            'env' => [],
        ],
        '1.5.0' => [
            'description' => 'Family Stories: a new content type for family-recounted stories, held for admin approval before appearing on the new Stories tab / in the AI knowledge base',
            'db' => static function (PDO $pdo): void {
                $typeCol = (string) $pdo->query(
                    "SELECT COLUMN_TYPE FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'type'"
                )->fetchColumn();
                if (!str_contains($typeCol, "'story'")) {
                    $pdo->exec("ALTER TABLE content_items MODIFY COLUMN type ENUM('transcript','photo','video','url','story') NOT NULL");
                }
                $hasCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'story_approved_at'"
                )->fetchColumn();
                if ($hasCol === 0) {
                    $pdo->exec('ALTER TABLE content_items ADD COLUMN story_approved_at DATETIME NULL AFTER tags');
                }
            },
            'env' => [],
        ],
        '1.5.1' => [
            'description' => 'Email the admin when an author submits a new story pending approval',
            'db' => null,
            'env' => [],
        ],
        '1.5.2' => [
            'description' => 'Add subject_death_date (About tab, Ask tab context, setup wizard, admin settings)',
            'db' => static function (PDO $pdo): void {
                $hasCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'site_settings' AND column_name = 'subject_death_date'"
                )->fetchColumn();
                if ($hasCol === 0) {
                    $pdo->exec('ALTER TABLE site_settings ADD COLUMN subject_death_date VARCHAR(100) NULL AFTER subject_birthplace');
                }
            },
            'env' => [],
        ],
        '1.5.3' => [
            'description' => 'Fix archive_site_settings_update(): REPLACE INTO silently reset schema_version (and would have reset any other unlisted column) on every settings save — now a real UPDATE',
            'db' => null,
            'env' => [],
        ],
        '1.5.4' => [
            'description' => 'Sync the site identity date of passing into the subject-role person\'s People-tab fate field',
            'db' => null,
            'env' => [],
        ],
        '1.6.0' => [
            'description' => 'Add "Make admin" action on /admin.php, promoting a reader/author straight to admin',
            'db' => null,
            'env' => [],
        ],
        '1.7.0' => [
            'description' => 'Track last login per user (/admin.php) + client-side user search',
            'db' => static function (PDO $pdo): void {
                $hasCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_login_at'"
                )->fetchColumn();
                if ($hasCol === 0) {
                    $pdo->exec('ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER approved_at');
                }
            },
            'env' => [],
        ],
        '1.8.0' => [
            'description' => 'Passkey (WebAuthn) login for admin/author accounts, additive to password login',
            'db' => static function (PDO $pdo): void {
                // CREATE TABLE IF NOT EXISTS is safe unconditionally
                // here (a genuinely new table, not an ALTER on an
                // existing one) — matches sql/schema.sql's own copy of
                // this statement exactly, so both stay in sync.
                $pdo->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
                    id             CHAR(36)       NOT NULL PRIMARY KEY,
                    user_id        CHAR(36)       NOT NULL,
                    credential_id  VARBINARY(1023) NOT NULL,
                    public_key     TEXT           NOT NULL,
                    sign_count     INT UNSIGNED   NOT NULL DEFAULT 0,
                    label          VARCHAR(255)   NULL,
                    created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_used_at   DATETIME       NULL,
                    UNIQUE KEY uniq_credential_id (credential_id),
                    CONSTRAINT fk_webauthn_credentials_user FOREIGN KEY (user_id)
                        REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            },
            'env' => [],
        ],
        '1.8.1' => [
            'description' => 'Fix passkey registration crash: credentialId is a raw string from the library, not a ByteBuffer',
            'db' => null,
            'env' => [],
        ],
        '1.9.0' => [
            'description' => 'Self-service password change + fix pending-account login lockout',
            'db' => null,
            'env' => [],
        ],
        '1.10.0' => [
            'description' => 'Forgot-password email reset flow',
            'db' => null,
            'env' => ['FORGOT_PASSWORD_RATE_LIMIT (optional, defaults to 5)'],
        ],
        '1.10.1' => [
            'description' => 'Add "Back to login" link to the expired/used reset-link screen',
            'db' => null,
            'env' => [],
        ],
        '1.11.0' => [
            'description' => 'Lower AI sampling temperature by default (Ask tab accuracy safeguard)',
            'db' => null,
            'env' => ['AI_TEMPERATURE (optional, defaults to 0.2; set to "default" to use the provider\'s own default)'],
        ],
        '1.12.0' => [
            'description' => 'Deterministic quote verification on Ask-tab replies (Ask tab accuracy safeguard)',
            'db' => null,
            'env' => [],
        ],
        '1.13.0' => [
            'description' => 'Surface unreviewed status of AI-generated narrative notes on the Content page (Ask tab accuracy safeguard)',
            'db' => static function (PDO $pdo): void {
                $hasCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'narrative_note_reviewed_at'"
                )->fetchColumn();
                if ($hasCol === 0) {
                    $pdo->exec('ALTER TABLE content_items ADD COLUMN narrative_note_reviewed_at DATETIME NULL AFTER narrative_note');
                }
            },
            'env' => [],
        ],
        '1.14.0' => [
            'description' => 'Structured suggestion-extraction pass (timeline/people/places/quotes) now also runs on Transcript and (approved) Story content, not just URL',
            'db' => null,
            'env' => [],
        ],
        '1.15.0' => [
            'description' => 'Animated typing indicator while the Ask tab is waiting on a reply; fix "Mark reviewed" checkbox layout and cap the Captured column to 3 lines on the Content page',
            'db' => null,
            'env' => [],
        ],
        '1.16.0' => [
            'description' => 'Raise Ask-tab reply token budget and surface truncated replies; fix Captured column truncation to clamp actual rendered lines, not just entry count',
            'db' => null,
            'env' => [],
        ],
        '1.17.0' => [
            'description' => 'Add "download from URL" option for Photo/Video content, alongside the existing browser upload; fix a table-layout bug in the 1.16.0 Captured-column clamp',
            'db' => null,
            'env' => [
                'Not a .env change: if a large photo/video upload fails, your host\'s PHP post_max_size/upload_max_filesize (and possibly PHP-FPM\'s request_terminate_timeout) may need raising — see README\'s "Uploading large files". The new "download from URL" option sidesteps this entirely since it is not subject to either upload limit.',
            ],
        ],
        '1.17.1' => [
            'description' => 'Animated typing-dots indicator on the Add-content submit button while a URL download is in progress, matching the Ask tab; fix four Content-page hint paragraphs rendering in the wrong (bright, not muted) text color; fix narrative_parse_analysis() leaking raw prose+JSON into a stored note when the model prefaces its required JSON with prose',
            'db' => null,
            'env' => [],
        ],
        '1.18.1' => [
            'description' => 'Fix Ask-tab video seek always landing at 0:00: video_seek_match_segment() required the entire quoted passage to be one exact substring of the transcript, but the model often assembles a quote from spoken testimony using "..." to skip filler/cross-talk, sometimes spanning two speakers\' turns — now matches on the quote\'s most distinctive "..."-delimited fragment instead',
            'db' => null,
            'env' => [],
        ],
        '1.18.0' => [
            'description' => 'Link a video content item to its companion transcript so quoted Ask-tab passages can seek the video to ~5s before that moment; new per-segment-timecode transcript format; manual link/relink control on the Content page',
            'db' => static function (PDO $pdo): void {
                $hasCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'linked_item_id'"
                )->fetchColumn();
                if ($hasCol === 0) {
                    $pdo->exec('ALTER TABLE content_items ADD COLUMN linked_item_id CHAR(36) NULL AFTER story_approved_at');
                }
                $hasFk = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.table_constraints
                     WHERE table_schema = DATABASE() AND table_name = 'content_items'
                       AND constraint_name = 'fk_content_items_linked'"
                )->fetchColumn();
                if ($hasFk === 0) {
                    $pdo->exec('ALTER TABLE content_items ADD CONSTRAINT fk_content_items_linked
                        FOREIGN KEY (linked_item_id) REFERENCES content_items(id) ON DELETE SET NULL');
                }
            },
            'env' => [],
        ],
    ];
}

// Ascending semver order, regardless of the array's literal order above.
function migrations_ordered(): array
{
    $steps = migrations_steps();
    uksort($steps, 'version_compare');
    return $steps;
}
