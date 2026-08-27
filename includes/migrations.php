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
        '1.31.0' => [
            'description' => 'Add up/down reorder arrows to People, Places, and Quotes on admin_archive.php (already existed on Timeline) — new sort_order column on people/places/quotes, backfilled to match each table\'s previous display order (people: subject role first, then created_at; places/quotes: created_at) so nothing visibly reshuffles on upgrade',
            'db' => static function (PDO $pdo): void {
                foreach (['people', 'places', 'quotes'] as $table) {
                    $exists = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = 'sort_order'"
                    )->fetchColumn();
                    if ($exists === 0) {
                        $pdo->exec("ALTER TABLE $table ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0");
                    }
                }

                // Backfill only matters for rows still at the column's
                // DEFAULT 0 — re-running this step (a fresh ALTER TABLE
                // above, or a second pass for any other reason) never
                // clobbers an order an admin has since set by hand via
                // the new up/down arrows.
                $peopleIds = $pdo->query(
                    "SELECT id FROM people WHERE sort_order = 0 ORDER BY (role = 'subject') DESC, created_at ASC"
                )->fetchAll(PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare('UPDATE people SET sort_order = ? WHERE id = ?');
                foreach ($peopleIds as $i => $id) {
                    $stmt->execute([$i + 1, $id]);
                }

                foreach (['places', 'quotes'] as $table) {
                    $ids = $pdo->query(
                        "SELECT id FROM $table WHERE sort_order = 0 ORDER BY created_at ASC"
                    )->fetchAll(PDO::FETCH_COLUMN);
                    $stmt = $pdo->prepare("UPDATE $table SET sort_order = ? WHERE id = ?");
                    foreach ($ids as $i => $id) {
                        $stmt->execute([$i + 1, $id]);
                    }
                }
            },
            'env' => [],
        ],
        '1.30.0' => [
            'description' => 'Replace the always-visible "Add ___" form on Content, Archive (People/Places/Timeline/Quotes), and Settings\' Sources tab with a button + modal (6 forms); new shared js/admin_modal.js also backs the main app\'s existing Add Content modal, replacing its own hand-rolled open/close logic',
            'db' => null,
            'env' => [],
        ],
        '1.29.3' => [
            'description' => 'Add-content submit button now shows the animated typing-dots "working" indicator for every submission (local file upload, transcript/document/story/URL with their synchronous AI narrative-note pass), not just the Photo/Video download-from-URL case that previously was the only one to show any feedback at all',
            'db' => null,
            'env' => [],
        ],
        '1.29.2' => [
            'description' => 'Content page "Mark reviewed" checkbox now reflects actual review status (pre-checked when already reviewed, instead of always unchecked) and unchecking it is a real un-review action',
            'db' => null,
            'env' => [],
        ],
        '1.29.1' => [
            'description' => 'Fix admin dropdown hover dead-zone, clear stale file selection on type change + add a Remove-file button, enlarge sort-direction arrows, replace the Keywords info-icon\'s native title tooltip with a real hover/tap tooltip, make the login page\'s existing Forgot-password link more visible',
            'db' => null,
            'env' => [],
        ],
        '1.29.0' => [
            'description' => 'Add app-wide keywords table — a single suggestion list Quotes and Content tag pickers both draw from, plus a new admin_settings.php Keywords tab to rename/merge/delete entries. Backfills the table from every tag already in use so an existing archive does not start with an empty list.',
            'db' => static function (PDO $pdo): void {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS keywords (
                        id CHAR(36) NOT NULL PRIMARY KEY,
                        label VARCHAR(100) NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_label (label)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
                );

                // CREATE TABLE IF NOT EXISTS makes the table itself safe to
                // replay, but this backfill loop is NOT idempotent-by-
                // skipping the way most other steps here are — it's just
                // INSERT IGNORE against a UNIQUE column, so re-running it
                // (e.g. an install re-applying 1.29.0 for any reason) is
                // still safe: every tag either already has a row or gets
                // one, never a duplicate.
                $insert = $pdo->prepare('INSERT IGNORE INTO keywords (id, label) VALUES (?, ?)');
                $seen = [];
                foreach (['content_items', 'quotes'] as $table) {
                    $rows = $pdo->query("SELECT tags FROM $table WHERE tags IS NOT NULL AND tags != '[]'")->fetchAll();
                    foreach ($rows as $row) {
                        foreach ((json_decode((string) $row['tags'], true) ?: []) as $tag) {
                            $tag = trim((string) $tag);
                            if ($tag === '' || isset($seen[$tag])) {
                                continue;
                            }
                            $seen[$tag] = true;
                            $insert->execute([make_uuid(), $tag]);
                        }
                    }
                }
            },
            'env' => [],
        ],
        '1.28.0' => [
            'description' => 'Main app "Add Content" opens a lightweight modal instead of navigating to the full admin_content.php page; extracted the add-content form (markup + JS) into a shared implementation (content_add_form_html(), js/content_form.js) used by both',
            'db' => null,
            'env' => [],
        ],
        '1.27.0' => [
            'description' => 'Content admin table: add Linked/Keywords columns, type filter, column sort, truncate the Narrative connection cell to one line, distinct color for the unreviewed badge, bigger Backfill AI analysis button with a help tooltip, grey out the unused file/URL field once a choice is made',
            'db' => null,
            'env' => [],
        ],
        '1.26.0' => [
            'description' => 'Admin nav overhaul: main-app "Admin" link is now a hover dropdown listing all six sections; every admin_*.php page shows all six with the current one highlighted; fix admin_content.php not hiding the Backup link from non-admin authors',
            'db' => null,
            'env' => [],
        ],
        '1.25.0' => [
            'description' => 'Hide move-up/move-down arrows on the first/last row of reorderable admin tables (Timeline, Sources, Audience categories) instead of always showing both',
            'db' => null,
            'env' => [],
        ],
        '1.24.0' => [
            'description' => 'Remove the legacy YAML importer (admin_archive.php Import tab, includes/legacy_import.php, api/admin/legacy_import.php) — deprecated, no longer used by any active deployment',
            'db' => null,
            'env' => [],
        ],
        '1.23.1' => [
            'description' => 'cron_backup.php: add #!/usr/local/bin/php.cli shebang line for hosting panels (e.g. Plesk) whose Scheduled Tasks UI requires a directly-executable script',
            'db' => null,
            'env' => [],
        ],
        '1.23.0' => [
            'description' => 'Backup retention (auto-prune oldest beyond BACKUP_RETENTION_COUNT), optional automatic backups via new cron_backup.php + BACKUP_AUTO_INTERVAL_HOURS, optional BACKUP_DIR storage-location override',
            'db' => null,
            'env' => [
                'BACKUP_RETENTION_COUNT (optional, defaults to 14 — how many backups to keep; 0 keeps all)',
                'BACKUP_AUTO_INTERVAL_HOURS (optional, defaults to 0/off — also needs a host cron job pointed at cron_backup.php to actually run; see README)',
                'BACKUP_DIR (optional, defaults to ARCHIVE_ROOT/backups)',
                'BACKUP_CRON_SECRET (optional — only needed if your host can only cron a URL, not a CLI command)',
            ],
        ],
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
        '1.19.0' => [
            'description' => 'Raise Ask-tab reply length ceiling from 4096 to 8192 tokens to reduce how often replies get cut short and need "ask to continue"; raise the AI provider HTTP timeout from 60s to 120s to match, so a genuinely long reply has time to finish generating instead of hard-failing',
            'db' => null,
            'env' => [],
        ],
        '1.20.0' => [
            'description' => 'New "Document" content type for permission letters, correspondence, and similar non-testimony written material (paste text + optional attached scan/PDF for provenance) — gets the same AI suggestion-extraction pass as Transcript/URL/Story',
            'db' => static function (PDO $pdo): void {
                $typeCol = (string) $pdo->query(
                    "SELECT COLUMN_TYPE FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'type'"
                )->fetchColumn();
                if (!str_contains($typeCol, "'document'")) {
                    $pdo->exec("ALTER TABLE content_items MODIFY COLUMN type ENUM('transcript','photo','video','url','story','document') NOT NULL");
                }
            },
            'env' => [],
        ],
        '1.19.2' => [
            'description' => 'Ask tab: Enter in the question box now sends the question instead of inserting a newline; Shift+Enter still inserts a newline',
            'db' => null,
            'env' => [],
        ],
        '1.19.1' => [
            'description' => 'Fix Ask-tab video seek regressing to 0:00 on a citation phrasing variant video_seek_is_citation_span() still missed after 1.18.2 and 1.18.6: (Family-contributed transcript, "Title," date) puts text between the ( and the quote, so the citation title won the nearest-quote search over the real testimony quote and never matched the transcript — replaced the check with a bounded backward scan for the nearest unclosed ( before the quote, covering this and the earlier phrasings in one rule',
            'db' => null,
            'env' => [],
        ],
        '1.18.9' => [
            'description' => 'Fix Ask-tab video seek missing short-but-genuine spoken quotes: quote_check_extract_spans_with_offsets() hardcoded the 25-character unverified-quote-caution threshold, so quotes like "eating us alive" (16 chars) were discarded before video_seek.php\'s own fragment-matching logic ever saw them — the function now takes an optional $minLength, and video_seek.php passes its own shorter VIDEO_SEEK_MIN_FRAGMENT_LENGTH (8) since an exact-substring match against the real transcript makes even short quotes a safe seek anchor; the unverified-quote caution itself is unaffected and still uses the 25-char default',
            'db' => null,
            'env' => [],
        ],
        '1.18.8' => [
            'description' => 'Fix two silent-failure gaps in ai_provider.php found while diagnosing a one-off "AI backend failed to respond" report: ai_chat_anthropic() with no extractable text block, and ai_http_post() with a 2xx response that is not valid JSON, both used to return null with nothing logged, contradicting ai_http_post()\'s own "always error_log()\'d" contract — now both log a short diagnosable line',
            'db' => null,
            'env' => [],
        ],
        '1.18.7' => [
            'description' => 'Further strengthen the Ask-tab video-citation prompt rule: when narrating several distinct incidents from the same transcript (e.g. "tell me about all the times..."), ask for a short direct quote for EACH incident, not just the first/most vivid one, so more incidents get a correctly-seeking video link — a probabilistic prompt improvement, not a guarantee, like 1.18.4',
            'db' => null,
            'env' => [],
        ],
        '1.18.6' => [
            'description' => 'Fix Ask-tab video seek still picking a citation instead of the real quote in a phrasing variant 1.18.2 missed: video_seek_is_citation_span() required the citation quote to be immediately followed by ")", but the model sometimes puts plain text between the closing quote and the ")" — now detected by "immediately preceded by (" alone, which is reliable regardless of how the rest of the citation is phrased',
            'db' => null,
            'env' => [],
        ],
        '1.18.5' => [
            'description' => 'Fix Ask-tab video seek collapsing all occurrences of a repeated [[video:ID]] token to one shared time: video_seek_resolve_for_reply() was keyed by file id, so citing the same video twice at two different moments applied whichever one matched first to BOTH — now returns one seek result per token occurrence, in order, and js/app.js consumes it the same way',
            'db' => null,
            'env' => [],
        ],
        '1.18.4' => [
            'description' => 'Strengthen the Ask-tab prompt rule for video citations: require a verbatim quote (not a paraphrase) next to a [[video:ID]] token, since the video seek can only anchor to a real quoted passage — a probabilistic prompt improvement, not a deterministic guarantee, unlike the 1.18.1-1.18.3 fixes',
            'db' => null,
            'env' => [],
        ],
        '1.18.3' => [
            'description' => 'Fix video seeking (both Ask-tab #t=N and normal user scrubbing) always landing at 0:00: api/file.php and api/admin/content_file.php streamed every photo/video with readfile() and no HTTP Range support, so a browser could never seek within a served video at all, regardless of what a #t=N URL fragment asked for — new shared content_stream_file() helper (includes/content.php) adds proper 206 Partial Content / Accept-Ranges support',
            'db' => null,
            'env' => [],
        ],
        '1.18.2' => [
            'description' => 'Fix Ask-tab video seek still landing at 0:00 in a second case: the "nearest quote to the [[video:ID]] token" search was picking up the source-citation string (e.g. ("Some Title")) instead of the real testimony quote, since the citation is itself quoted and often sits textually closer to the token — now excludes any quote span structurally wrapped in parentheses',
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
