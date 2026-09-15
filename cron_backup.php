#!/usr/local/bin/php.cli
<?php
declare(strict_types=1);

// The #!/usr/local/bin/php.cli shebang above is required by some
// hosting panels' scheduled-task UI (e.g. Plesk), which run a cron
// script directly as an executable rather than as `php script.php` —
// PHP's CLI SAPI automatically skips a leading "#!" line, so this
// doesn't change anything about running it the other way (`php
// cron_backup.php`, or a plain URL hit). If a panel run fails, also
// check this file has execute permission (chmod 775) and Unix line
// endings, per that panel's own requirements.
//
// Entry point for a host cron job to trigger automatic backups — see
// README's "Backup & Restore" section. This app has no long-running
// process of its own (shared hosting assumption throughout this repo),
// so "run every N hours" has to come from outside: cPanel's Cron Jobs
// feature (or any other scheduler your host gives you) calling this
// script on a short, frequent schedule (e.g. hourly). Each run is a
// no-op unless a backup is actually due per BACKUP_AUTO_INTERVAL_HOURS
// (see includes/backup.php's backup_is_due_for_subject()) — so it's
// safe to point cron at this far more often than you actually want
// backups.
//
// Multi-subject dispatcher (CLI only): with no --subject=<id> argument,
// this re-execs itself once per active subject, each as a fresh child
// process. That's necessary, not a style choice — ARCHIVE_ROOT is a PHP
// define()'d constant, immutable for the life of a process (see
// config.php), so a single long-lived loop calling set_current_subject()
// per iteration cannot work; each subject needs its own cleanly-defined
// constant. This means the HTTP ("cron via URL") trigger path below is
// necessarily single-subject-only — point one cron/ping line per
// subject if your host can't run a CLI command (each hitting
// https://that-subjects-hostname/cron_backup.php, which resolves its
// own subject from the Host header the normal way).
//
// Two ways to trigger it, matching what different hosts offer:
//   - CLI (preferred): `php /home/you/family-legacy-archive/cron_backup.php`
//     as a cPanel "Cron Job" command — dispatches across every subject
//     in one line. No secret needed — a shell-level cron job isn't
//     reachable over HTTP at all.
//   - HTTP (for hosts that only offer "cron via URL"/wget pinging): set
//     BACKUP_CRON_SECRET in .env, then have cron hit
//     https://<one-subjects-hostname>/cron_backup.php?token=that-secret
//     — one line per subject you want covered this way. Without a
//     configured secret, HTTP access is refused outright.
require_once __DIR__ . '/config.php';

$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    $explicitSubjectId = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--subject=')) {
            $explicitSubjectId = substr($arg, strlen('--subject='));
        }
    }

    if ($explicitSubjectId === null) {
        // Dispatcher mode: loop every active subject, re-exec self once
        // per subject as a fresh child process (see the doc comment
        // above for why a single process can't just loop this itself).
        $subjectIds = db()->query("SELECT id FROM subjects WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$subjectIds) {
            echo "No active subjects on this install.\n";
            exit(0);
        }
        $exitCode = 0;
        foreach ($subjectIds as $subjectId) {
            echo "--- subject $subjectId ---\n";
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg('--subject=' . $subjectId);
            passthru($cmd, $childExit);
            if ($childExit !== 0) {
                $exitCode = 1;
            }
        }
        exit($exitCode);
    }
    // Single-subject child process — config.php already resolved
    // current_subject() from the --subject=<id> argument above.
} else {
    header('Content-Type: text/plain');
    $secret = env('BACKUP_CRON_SECRET');
    $given = (string) ($_GET['token'] ?? '');
    if ($secret === null || $secret === '' || !hash_equals($secret, $given)) {
        http_response_code(403);
        echo "Forbidden.\n";
        exit;
    }
    // HTTP mode resolves its one subject from the Host header the
    // normal way (see config.php) — no dispatch loop possible here.
}

$subject = current_subject();
if ($subject === null) {
    fwrite(STDERR, "cron_backup: no subject resolved for this run.\n");
    echo "Could not determine which subject to back up.\n";
    exit(1);
}

try {
    $created = backup_run_scheduled_for_subject($subject);
    $pruned = backup_prune_for_subject($subject);
} catch (Throwable $e) {
    error_log('cron_backup (' . $subject['slug'] . '): ' . $e->getMessage());
    echo "Backup cron failed for {$subject['slug']}: " . $e->getMessage() . "\n";
    exit(1);
}

echo $created !== null ? "Created backup for {$subject['slug']}: $created\n" : "No automatic backup due for {$subject['slug']}.\n";
if (!empty($pruned)) {
    echo 'Pruned ' . count($pruned) . " old backup(s) for {$subject['slug']}: " . implode(', ', $pruned) . "\n";
}
