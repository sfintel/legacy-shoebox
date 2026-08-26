<?php
declare(strict_types=1);

// Entry point for a host cron job to trigger automatic backups — see
// README's "Backup & Restore" section. This app has no long-running
// process of its own (shared hosting assumption throughout this repo),
// so "run every N hours" has to come from outside: cPanel's Cron Jobs
// feature (or any other scheduler your host gives you) calling this
// script on a short, frequent schedule (e.g. hourly). Each run is a
// no-op unless a backup is actually due per BACKUP_AUTO_INTERVAL_HOURS
// (see includes/backup.php's backup_is_due()) — so it's safe to point
// cron at this far more often than you actually want backups.
//
// Two ways to trigger it, matching what different hosts offer:
//   - CLI (preferred): `php /home/you/public_html/cron_backup.php` as a
//     cPanel "Cron Job" command. No secret needed — a shell-level cron
//     job isn't reachable over HTTP at all.
//   - HTTP (for hosts that only offer "cron via URL"/wget pinging): set
//     BACKUP_CRON_SECRET in .env, then have cron hit
//     https://yoursite.example/cron_backup.php?token=that-secret
//     Without a configured secret, HTTP access is refused outright —
//     this creates backups and touches disk, so it must never be a
//     publicly-triggerable no-auth endpoint by default.
require_once __DIR__ . '/config.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    header('Content-Type: text/plain');
    $secret = env('BACKUP_CRON_SECRET');
    $given = (string) ($_GET['token'] ?? '');
    if ($secret === null || $secret === '' || !hash_equals($secret, $given)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
}

try {
    $created = backup_run_scheduled();
    $pruned = backup_prune();
} catch (Throwable $e) {
    error_log('cron_backup: ' . $e->getMessage());
    echo "Backup cron failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo $created !== null ? "Created backup: $created\n" : "No automatic backup due.\n";
if (!empty($pruned)) {
    echo 'Pruned ' . count($pruned) . ' old backup(s): ' . implode(', ', $pruned) . "\n";
}
