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
    ];
}

// Ascending semver order, regardless of the array's literal order above.
function migrations_ordered(): array
{
    $steps = migrations_steps();
    uksort($steps, 'version_compare');
    return $steps;
}
