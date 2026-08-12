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
    ];
}

// Ascending semver order, regardless of the array's literal order above.
function migrations_ordered(): array
{
    $steps = migrations_steps();
    uksort($steps, 'version_compare');
    return $steps;
}
