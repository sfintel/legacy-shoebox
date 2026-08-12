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

// The tracking column has to exist before ANY version can be recorded
// as applied — including versions before the one that nominally "adds"
// it in migrations.php (e.g. 1.1.0 needs to be markable as done before
// the runner ever reaches the 1.2.0 step that owns that column in the
// changelog). So the runner bootstraps it unconditionally up front,
// rather than treating it as just another step in the loop below.
function upgrade_ensure_tracking_column(PDO $pdo): void
{
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'site_settings' AND column_name = 'schema_version'"
    )->fetchColumn();
    if ((int) $exists === 0) {
        // DEFAULT '1.0.0' backfills the existing row automatically —
        // any database reaching this line predates version tracking
        // entirely, i.e. it's genuinely at the 1.0.0 baseline.
        $pdo->exec("ALTER TABLE site_settings ADD COLUMN schema_version VARCHAR(20) NOT NULL DEFAULT '1.0.0' AFTER setup_completed_at");
    }
}

function upgrade_current_version(PDO $pdo): string
{
    $version = $pdo->query('SELECT schema_version FROM site_settings WHERE id = 1')->fetchColumn();
    return $version !== false && $version !== null ? (string) $version : '1.0.0';
}

function upgrade_set_version(PDO $pdo, string $version): void
{
    $pdo->prepare('UPDATE site_settings SET schema_version = ? WHERE id = 1')->execute([$version]);
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
            $pdo->beginTransaction();
            $step['db']($pdo);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
