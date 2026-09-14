<?php
declare(strict_types=1);

// The engine behind upgrade.sh — applies every versioned step in
// includes/migrations.php newer than what's recorded in
// site_settings.schema_version, up to VERSION. CLI-only: this touches
// the live database directly and must never be reachable over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/migrations.php';

// The tracking table has to exist before ANY version can be recorded as
// applied — including versions before the one that nominally "adds" it
// in migrations.php (e.g. 1.1.0 needs to be markable as done before the
// runner ever reaches the 2.0.0 step that owns this table in the
// changelog). So the runner bootstraps it unconditionally up front,
// rather than treating it as just another step in the loop below.
//
// Before 2.0.0, the version lived on site_settings.schema_version — a
// single row, back when site_settings itself was a single-row table.
// Once site_settings became one row PER SUBJECT (multi-subject support,
// see sql/schema.sql), there's no longer one row to hold an install-
// wide version, so tracking moved to its own dedicated schema_meta
// table. This one-time carry-over copies whatever version was already
// recorded there (if any) so an existing install's upgrade history
// isn't lost.
function upgrade_ensure_schema_meta_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS schema_meta (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            schema_version VARCHAR(20) NOT NULL DEFAULT '1.0.0'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $hasRow = (int) $pdo->query('SELECT COUNT(*) FROM schema_meta')->fetchColumn();
    if ($hasRow > 0) {
        return;
    }
    $carriedOver = null;
    $hasOldColumn = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'site_settings' AND column_name = 'schema_version'"
    )->fetchColumn();
    if ($hasOldColumn > 0) {
        // Pre-2.0.0 site_settings was still a single row (id = 1) at
        // this point — the 2.0.0 step itself is what later converts it
        // to one-row-per-subject and drops this column.
        $carriedOver = $pdo->query('SELECT schema_version FROM site_settings WHERE id = 1')->fetchColumn() ?: null;
    }
    $pdo->prepare('INSERT INTO schema_meta (id, schema_version) VALUES (1, ?)')
        ->execute([$carriedOver ?? '1.0.0']);
}

function upgrade_current_version(PDO $pdo): string
{
    $version = $pdo->query('SELECT schema_version FROM schema_meta WHERE id = 1')->fetchColumn();
    return $version !== false && $version !== null ? (string) $version : '1.0.0';
}

function upgrade_set_version(PDO $pdo, string $version): void
{
    $pdo->prepare('UPDATE schema_meta SET schema_version = ? WHERE id = 1')->execute([$version]);
}

$targetVersion = trim((string) file_get_contents(__DIR__ . '/VERSION'));
if ($targetVersion === '') {
    fwrite(STDERR, "Could not read VERSION file.\n");
    exit(1);
}

$pdo = db();
upgrade_ensure_tracking_column($pdo);
$currentVersion = upgrade_current_version($pdo);

echo "Currently at: $currentVersion\n";
echo "Target:       $targetVersion\n\n";

if (version_compare($currentVersion, $targetVersion, '>=')) {
    echo "Already up to date. Nothing to do.\n";
    exit(0);
}

echo "Creating a safety backup before making any changes...\n";
try {
    $backupFile = backup_create();
    echo "Backup created: backups/$backupFile\n";
    echo "(If anything below goes wrong, restore it from /admin_backup.php or by hand — see UPGRADE.md.)\n\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Backup failed, aborting before touching anything: " . $e->getMessage() . "\n");
    exit(1);
}

$envNotes = [];
$applied = [];

foreach (migrations_ordered() as $version => $step) {
    if (version_compare($version, $currentVersion, '<=')) {
        continue;
    }
    if (version_compare($version, $targetVersion, '>')) {
        break;
    }

    echo "Applying $version — {$step['description']}\n";

    if ($step['db'] !== null) {
        try {
            // Deliberately NOT wrapped in beginTransaction()/commit():
            // MySQL's ALTER TABLE (and other DDL) causes an implicit
            // commit, silently ending any transaction the moment the
            // first ALTER runs — a step that mixes DDL with other
            // statements would then hit "There is no active
            // transaction" on PDO's own commit() call, since the
            // transaction MySQL thinks it's tracking no longer exists.
            // DDL can't be rolled back in MySQL anyway, so the wrapping
            // was never real protection — the actual safety net is the
            // backup taken above.
            $step['db']($pdo);
        } catch (Throwable $e) {
            fwrite(STDERR, "  FAILED: " . $e->getMessage() . "\n");
            fwrite(STDERR, "\nStopped at $version. Versions before this one were applied successfully");
            fwrite(STDERR, " and schema_version reflects that. Fix the problem above, or restore\n");
            fwrite(STDERR, "the backup created at the start of this run, then re-run upgrade.sh.\n");
            exit(1);
        }
    }

    upgrade_set_version($pdo, $version);
    $applied[] = $version;
    if (!empty($step['env'])) {
        $envNotes[$version] = $step['env'];
    }
    echo "  done.\n";
}

if (empty($applied)) {
    echo "Nothing to apply between $currentVersion and $targetVersion.\n";
} else {
    echo "\nApplied: " . implode(', ', $applied) . "\n";
}

if (!empty($envNotes)) {
    echo "\n=== Manual .env changes still required ===\n";
    foreach ($envNotes as $version => $notes) {
        echo "\n$version:\n";
        foreach ($notes as $note) {
            echo "  - $note\n";
        }
    }
    echo "\nApply these by hand, then your upgrade is complete.\n";
}

echo "\nNow at: $targetVersion\n";
