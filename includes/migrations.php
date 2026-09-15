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
//
// Multi-subject note (added at 2.0.0, see that step below): every table
// added there gains a subject_id column. A future migration step's data
// backfill (e.g. reassigning sort_order for rows still at its column
// default) MUST partition its logic by subject_id — blending every
// subject's rows into one global ordering/computation would visibly
// scramble every subject except the one the migration's author happened
// to be looking at while writing it.
function migrations_steps(): array
{
    return [
        '2.4.1' => [
            'description' => 'json_response() (every JSON API endpoint) now sends Cache-Control: no-store — fixes a deleted passkey (or any other list-then-mutate UI) sometimes still showing the old row until a hard page refresh, since the browser could cache the GET response with no cache headers of its own. No schema change',
            'db' => null,
            'env' => [],
        ],
        '2.4.0' => [
            'description' => 'A master admin can now register their own passkey and sign in with it directly as master admin (not just as a shadow admin of one subject) — new "Master admin passkey" section on account.php, gated to a master-admin session. webauthn_credentials.user_id becomes nullable and gains a master_admin_id column (FK to master_admins, ON DELETE CASCADE) — a row belongs to exactly one of the two',
            'db' => static function (PDO $pdo): void {
                $hasCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'webauthn_credentials' AND column_name = 'master_admin_id'"
                )->fetchColumn();
                if ($hasCol === 0) {
                    $pdo->exec("ALTER TABLE webauthn_credentials ADD COLUMN master_admin_id CHAR(36) NULL AFTER user_id");
                }
                $userIdNullable = (string) $pdo->query(
                    "SELECT IS_NULLABLE FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'webauthn_credentials' AND column_name = 'user_id'"
                )->fetchColumn();
                if ($userIdNullable === 'NO') {
                    $pdo->exec('ALTER TABLE webauthn_credentials MODIFY COLUMN user_id CHAR(36) NULL');
                }
                $hasFk = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.table_constraints
                     WHERE table_schema = DATABASE() AND table_name = 'webauthn_credentials' AND constraint_name = 'fk_webauthn_credentials_master_admin'"
                )->fetchColumn();
                if ($hasFk === 0) {
                    $pdo->exec('ALTER TABLE webauthn_credentials ADD CONSTRAINT fk_webauthn_credentials_master_admin FOREIGN KEY (master_admin_id) REFERENCES master_admins(id) ON DELETE CASCADE');
                }
            },
            'env' => [],
        ],
        '2.3.0' => [
            'description' => 'Added a "Send test email" button to the setup wizard\'s Advanced/SMTP stage, so SMTP settings can be verified before finishing setup instead of only on the first real signup-approval email. New api/setup/test_smtp.php; includes/mailer.php refactored into a parameterized smtp_send_with_config(). No schema change',
            'db' => null,
            'env' => [],
        ],
        '2.2.2' => [
            'description' => 'Fix the Ask tab failing on every subject with "The AI backend failed to respond" — Anthropic started rejecting the `temperature` param on this app\'s default model (claude-sonnet-5) with "temperature is deprecated for this model"; ai_chat_anthropic() now detects that specific error and transparently retries once without it. No schema change',
            'db' => null,
            'env' => [],
        ],
        '2.2.1' => [
            'description' => 'Removed BACKUP_DIR from the setup wizard\'s "advanced" stage — it wrote to the shared install-wide .env, so filling it in during any subject\'s setup silently redirected every other subject\'s backup location too (found live on production after a second subject\'s setup orphaned the first subject\'s existing backups). BACKUP_DIR remains a valid hand-edit-only .env setting. No schema change',
            'db' => null,
            'env' => [],
        ],
        '2.2.0' => [
            'description' => 'A subject\'s own admin can now view/edit that subject\'s AI provider/key/base-url/model/temperature after setup, from a new "AI settings" tab on admin_settings.php (backed by api/admin/ai_settings.php) — previously only settable once, during the setup wizard, with no way to change it afterward short of a direct SQL UPDATE. Blank fields inherit the install-wide .env default, same semantics ai_provider.php already used. No schema change — subjects.ai_* columns already existed since 2.0.0',
            'db' => null,
            'env' => [],
        ],
        '2.1.0' => [
            'description' => 'Per-subject and whole-site backup/restore: /admin_backup.php now scopes to only the current subject (never able to see/restore any other subject\'s data); new /master_admin_backup.php offers both whole-site (every subject at once) and per-subject backup/restore for any subject; cron_backup.php dispatches across every active subject in one cron line. Also fixes upgrade.php\'s own pre-migration safety backup silently failing since 2.0.0 (no schema change, this is purely code)',
            'db' => null,
            'env' => [],
        ],
        '2.0.4' => [
            'description' => 'create_master_admin.php now also refuses an email already used by a regular per-subject account (not just an existing master admin) — prompted by two accidentally-overlapping master admins created on production after the 2.0.1 login bug made the first one look like it hadn\'t worked',
            'db' => null,
            'env' => [],
        ],
        '2.0.3' => [
            'description' => 'Backfill missing tracking entries for 2.0.1 and 2.0.2 — both shipped with no migrations.php entry at all (no DB change, so it seemed unnecessary), but every entry in this file — even a no-op \'db\' => null one — is what actually advances the tracked schema_version; skipping it left the tracker permanently stuck reporting "2.0.0" through both releases',
            'db' => null,
            'env' => [],
        ],
        '2.0.2' => [
            'description' => 'A logged-in master admin now sees a "Master admin" link in the main app\'s Admin dropdown and every admin_*.php page\'s nav bar; /admin.php labels a row "master admin" by live email match, not just the is_master_admin_shadow flag',
            'db' => null,
            'env' => [],
        ],
        '2.0.1' => [
            'description' => 'Fix master admin login bouncing back to login.php whenever the master admin\'s email already matched an existing real admin account on that subject — master_admin_ensure_shadow_user() now resolves to that row directly (promoting it to an active admin if needed) instead of refusing to touch it',
            'db' => null,
            'env' => [],
        ],
        '2.0.0' => [
            'description' => 'Multi-subject support: one installation/database can now host several independent subjects (e.g. one family archive per relative), each resolved by hostname, with its own logins, testimony, upload directory (ARCHIVE_ROOT_BASE/{slug}), and optional AI connection. New subjects/schema_meta/master_admins tables; every per-subject table gains a subject_id column; users/redacted_names/audience_modes/people/places/keywords unique keys become subject-scoped; site_settings/primary_testimony/discrepancy_notes convert from a fixed id=1 singleton to one row per subject. This install\'s existing single-tenant data becomes "subject #1" automatically — zero data loss, zero re-entry — but see the environment notes below for two manual steps this migration cannot safely do for you.',
            'db' => static function (PDO $pdo): void {
                // --- New tables ---
                $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
                    id             CHAR(36)     NOT NULL PRIMARY KEY,
                    slug           VARCHAR(64)  NOT NULL,
                    hostname       VARCHAR(255) NOT NULL,
                    display_name   VARCHAR(255) NOT NULL DEFAULT '',
                    status         ENUM('active','disabled') NOT NULL DEFAULT 'active',
                    ai_provider    VARCHAR(20)  NULL,
                    ai_api_key     VARCHAR(255) NULL,
                    ai_base_url    VARCHAR(255) NULL,
                    ai_model       VARCHAR(100) NULL,
                    ai_temperature VARCHAR(10)  NULL,
                    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_hostname (hostname),
                    UNIQUE KEY uniq_slug (slug)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS master_admins (
                    id            CHAR(36)     NOT NULL PRIMARY KEY,
                    name          VARCHAR(255) NOT NULL,
                    email         VARCHAR(255) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    last_login_at DATETIME     NULL,
                    UNIQUE KEY uniq_email (email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // --- Backfill exactly one subjects row from this install's
                // existing single-tenant data, if none exists yet ---
                $subjectId = $pdo->query('SELECT id FROM subjects ORDER BY created_at ASC LIMIT 1')->fetchColumn();
                if ($subjectId === false) {
                    $appUrl = (string) env('APP_URL', '');
                    $host = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: 'localhost'));
                    $slugBase = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', explode('.', $host)[0] ?? $host));
                    $slugBase = trim($slugBase, '-') ?: 'default';
                    $slug = $slugBase;
                    $suffix = 1;
                    $slugCheck = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE slug = ?');
                    $slugCheck->execute([$slug]);
                    while ((int) $slugCheck->fetchColumn() > 0) {
                        $suffix++;
                        $slug = $slugBase . '-' . $suffix;
                        $slugCheck->execute([$slug]);
                    }
                    $subjectId = make_uuid();
                    $pdo->prepare('INSERT INTO subjects (id, slug, hostname, display_name) VALUES (?, ?, ?, ?)')
                        ->execute([$subjectId, $slug, $host, $host]);
                }

                // --- subject_id on every per-subject content/account table ---
                $subjectScopedTables = [
                    'content_items', 'content_files', 'content_suggestions', 'content_links',
                    'redacted_names', 'sources', 'audience_modes', 'people', 'places',
                    'timeline_entries', 'quotes', 'keywords', 'users',
                ];
                foreach ($subjectScopedTables as $table) {
                    $hasCol = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = 'subject_id'"
                    )->fetchColumn();
                    if ($hasCol === 0) {
                        $pdo->exec("ALTER TABLE $table ADD COLUMN subject_id CHAR(36) NULL AFTER id");
                        $pdo->prepare("UPDATE $table SET subject_id = ? WHERE subject_id IS NULL")->execute([$subjectId]);
                        $pdo->exec("ALTER TABLE $table MODIFY COLUMN subject_id CHAR(36) NOT NULL");
                    }
                    $hasIndex = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.statistics
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND index_name = 'idx_subject'"
                    )->fetchColumn();
                    if ($hasIndex === 0) {
                        $pdo->exec("ALTER TABLE $table ADD KEY idx_subject (subject_id)");
                    }
                    $hasFk = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.table_constraints
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND constraint_name = 'fk_{$table}_subject'"
                    )->fetchColumn();
                    if ($hasFk === 0) {
                        $pdo->exec("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE");
                    }
                }

                // users gains the master-admin shadow-account marker (see
                // includes/master_admin.php) alongside its subject_id above.
                $hasShadowCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'is_master_admin_shadow'"
                )->fetchColumn();
                if ($hasShadowCol === 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN is_master_admin_shadow TINYINT(1) NOT NULL DEFAULT 0 AFTER signup_reason");
                }

                // --- Unique keys that must become subject-scoped ---
                $uniqueKeyRenames = [
                    'users' => ['uniq_email', 'uniq_subject_email', 'email'],
                    'redacted_names' => ['uniq_name', 'uniq_subject_name', 'name'],
                    'audience_modes' => ['uniq_slug', 'uniq_subject_slug', 'slug'],
                    'people' => ['uniq_slug', 'uniq_subject_slug', 'slug'],
                    'places' => ['uniq_slug', 'uniq_subject_slug', 'slug'],
                    'keywords' => ['uniq_label', 'uniq_subject_label', 'label'],
                ];
                foreach ($uniqueKeyRenames as $table => [$oldKey, $newKey, $column]) {
                    $hasOld = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.statistics
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND index_name = '$oldKey'"
                    )->fetchColumn();
                    if ($hasOld > 0) {
                        $pdo->exec("ALTER TABLE $table DROP INDEX $oldKey");
                    }
                    $hasNew = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.statistics
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND index_name = '$newKey'"
                    )->fetchColumn();
                    if ($hasNew === 0) {
                        $pdo->exec("ALTER TABLE $table ADD UNIQUE KEY $newKey (subject_id, $column)");
                    }
                }

                // --- Singleton tables: id=1 -> one row per subject (subject_id as PK) ---
                foreach (['site_settings', 'primary_testimony', 'discrepancy_notes'] as $table) {
                    $hasSubjectCol = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = 'subject_id'"
                    )->fetchColumn();
                    if ($hasSubjectCol === 0) {
                        $pdo->exec("ALTER TABLE $table ADD COLUMN subject_id CHAR(36) NULL AFTER id");
                        $pdo->prepare("UPDATE $table SET subject_id = ? WHERE id = 1")->execute([$subjectId]);
                    }
                    $hasIdCol = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.columns
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND column_name = 'id'"
                    )->fetchColumn();
                    if ($hasIdCol > 0) {
                        $pdo->exec("ALTER TABLE $table MODIFY COLUMN subject_id CHAR(36) NOT NULL");
                        $pdo->exec("ALTER TABLE $table DROP PRIMARY KEY, ADD PRIMARY KEY (subject_id)");
                        $pdo->exec("ALTER TABLE $table DROP COLUMN id");
                    }
                    $hasFk = (int) $pdo->query(
                        "SELECT COUNT(*) FROM information_schema.table_constraints
                         WHERE table_schema = DATABASE() AND table_name = '$table' AND constraint_name = 'fk_{$table}_subject'"
                    )->fetchColumn();
                    if ($hasFk === 0) {
                        $pdo->exec("ALTER TABLE $table ADD CONSTRAINT fk_{$table}_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE");
                    }
                }

                // site_settings.schema_version is now redundant — version
                // tracking moved to its own install-wide schema_meta table
                // (see upgrade.php's upgrade_ensure_schema_meta_table(),
                // which already carried this column's value over before
                // this step ever runs).
                $hasSchemaVersionCol = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'site_settings' AND column_name = 'schema_version'"
                )->fetchColumn();
                if ($hasSchemaVersionCol > 0) {
                    $pdo->exec('ALTER TABLE site_settings DROP COLUMN schema_version');
                }
            },
            'env' => [
                'ARCHIVE_ROOT replaced by ARCHIVE_ROOT_BASE — the parent directory holding EVERY subject\'s uploads/backups; each subject\'s own root is now computed automatically as ARCHIVE_ROOT_BASE/{slug}. Your existing uploads must be MOVED by hand: find your new subject\'s slug (SELECT slug FROM subjects), then move your current ARCHIVE_ROOT\'s contents into <ARCHIVE_ROOT_BASE>/<slug>/, add ARCHIVE_ROOT_BASE=<parent dir> to .env, and remove the old ARCHIVE_ROOT line. Do this before anyone uses the site again — until you do, uploaded files will appear missing.',
                'SUBJECT_SETUP_SECRET is required before a second subject can be added on a new hostname (a brand-new install\'s wizard generates this automatically; an install upgrading from an earlier version must add it by hand — e.g. `openssl rand -hex 24`).',
                'Verify the auto-derived hostname/slug for your existing subject (SELECT hostname, slug FROM subjects) matches this site\'s real public hostname — a wrong guess makes the site unreachable at its real hostname until corrected by direct SQL (UPDATE subjects SET hostname = \'your-real-hostname\' WHERE id = \'...\').',
            ],
        ],
        '1.37.0' => [
            'description' => 'signup.php now has an optional "Why are you requesting access?" text field — included verbatim (control characters stripped, HTML-escaped at render time) in the admin approval-request email and shown on /admin.php. users gains a signup_reason TEXT column',
            'db' => static function (PDO $pdo): void {
                $exists = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'signup_reason'"
                )->fetchColumn();
                if ($exists === 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN signup_reason TEXT NULL AFTER default_audience_mode");
                }
            },
            'env' => [],
        ],
        '1.36.0' => [
            'description' => 'A URL typed into a Story\'s text is now auto-linked (opens in a new tab) and gets an AI-generated one-sentence tooltip describing the linked page, generated once at story-save time. content_items gains a link_summaries JSON column; Backfill AI analysis now also fills it in for any pre-existing story',
            'db' => static function (PDO $pdo): void {
                $exists = (int) $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'link_summaries'"
                )->fetchColumn();
                if ($exists === 0) {
                    $pdo->exec("ALTER TABLE content_items ADD COLUMN link_summaries JSON NULL AFTER story_approved_at");
                }
            },
            'env' => [],
        ],
        '1.35.0' => [
            'description' => 'A Timeline/People/Places/Quotes entry\'s related-content link to a multi-page scanned document (imported as a photo album) now links every page, not just the first — and clicking any page opens a shared lightbox to page through all of them (arrow keys/buttons, Escape to close) instead of opening each page in a new tab',
            'db' => null,
            'env' => [],
        ],
        '1.34.3' => [
            'description' => 'Photo now gets the same "propose new timeline/people/places/quotes entries" AI pass as Transcript/Document/Story/URL, whenever a caption is present — covers a photo whose caption is really substantive source text (e.g. a scanned citation imported as a photo for its image rather than as a Document). Skipped entirely for a photo with no caption, so the common case costs nothing extra. Backfill AI analysis also now covers any existing captioned photo that predates this fix',
            'db' => null,
            'env' => [],
        ],
        '1.34.2' => [
            'description' => 'Fix an admin-approved AI suggestion (Timeline/People/Places/Quotes) never recording a real link back to its source content item — now inserts the same content_links row narrative_analyze()\'s "related content" already uses, so the new entry shows a clickable link to the source, and that link (and only that link) disappears on its own via content_links\' existing ON DELETE CASCADE if the source is later deleted. Forward-only: entries approved before this fix keep their old dangling plain-text citation and need a manual edit',
            'db' => null,
            'env' => [],
        ],
        '1.34.1' => [
            'description' => 'Fix the Content table\'s "View" button on a Document with an attached scan/PDF always opening the pasted text instead of the actual attachment',
            'db' => null,
            'env' => [],
        ],
        '1.34.0' => [
            'description' => 'PDF text extraction for Document (via poppler-utils pdftotext) and PDF-to-photos import for Photo (via pdftoppm, one photo per page) — both optional, degrading to a clear error (not a silent no-op, since these are explicit user actions) if poppler-utils isn\'t installed on the host',
            'db' => null,
            'env' => [],
        ],
        '1.33.0' => [
            'description' => 'New Audio content type, and a combined "Video/Audio + Transcript" add-content flow replacing the separate Transcript/Video type choices — a media file/URL and a transcript can be added together in one submission (either alone is still valid too) and are linked automatically. content_items.type ENUM gains \'audio\'; linking/auto-link-matching now cover video-or-audio <-> transcript pairs (Ask-tab citation-seek stays video-only)',
            'db' => static function (PDO $pdo): void {
                $typeCol = (string) $pdo->query(
                    "SELECT COLUMN_TYPE FROM information_schema.columns
                     WHERE table_schema = DATABASE() AND table_name = 'content_items' AND column_name = 'type'"
                )->fetchColumn();
                if (!str_contains($typeCol, "'audio'")) {
                    $pdo->exec("ALTER TABLE content_items MODIFY COLUMN type ENUM('transcript','photo','video','url','story','document','audio') NOT NULL");
                }
            },
            'env' => [],
        ],
        '1.32.0' => [
            'description' => 'Distinct steel-blue accent color for the six admin_*.php pages (body.admin-theme overriding --accent/--accent-2), so admin operations are visually unmistakable from the main app at a glance',
            'db' => null,
            'env' => [],
        ],
        '1.31.6' => [
            'description' => 'Fix narrative_suggest_additions() JSON-array parsing logging a correct "no suggestions" answer as a parse failure whenever the model prefixed its JSON array with explanatory prose — now finds the array anywhere in the response instead of requiring an exact whole-string match',
            'db' => null,
            'env' => [],
        ],
        '1.31.5' => [
            'description' => 'Fix keyword pickers (Content add/edit, Quotes add/edit) silently dropping a newly-typed keyword when Save/Add was clicked directly instead of pressing Enter/comma first to commit it',
            'db' => null,
            'env' => [],
        ],
        '1.31.4' => [
            'description' => 'Fix sticky table header (1.31.3) not actually sticking on the Content admin table — .table-wrap now uses a bounded max-height with overflow:auto on both axes instead of mixing overflow-x:auto with overflow-y:visible, which a CSS spec quirk silently forced back to auto anyway, making it a broken sticky container with no scrolling distance',
            'db' => null,
            'env' => [],
        ],
        '1.31.3' => [
            'description' => 'Admin table header rows now stick to the top of the page while scrolling a long list, instead of scrolling away with the rest of the table',
            'db' => null,
            'env' => [],
        ],
        '1.31.2' => [
            'description' => 'Add a site-wide baseline color for plain <a> links (previously unstyled, falling back to the browser default blue/purple which had very poor contrast against this theme\'s dark background) — every existing more-specific link style is unaffected',
            'db' => null,
            'env' => [],
        ],
        '1.31.1' => [
            'description' => 'Fix reorder-arrow buttons stacking vertically instead of side by side on People/Places (and, pre-emptively, Timeline/Quotes/Sources/Audience categories) when the column gets squeezed by real content — pin the column width and stop the buttons wrapping',
            'db' => null,
            'env' => [],
        ],
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
