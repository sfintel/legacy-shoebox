<?php
declare(strict_types=1);

// Backup/restore — two distinct scopes since multi-subject support
// (see includes/subjects.php):
//
// (a) WHOLE-INSTALL ("_full" suffix) — every subject's database rows
//     and uploaded files at once. Used by upgrade.php's own
//     pre-migration safety net (which must protect every subject
//     before a shared-schema change) and the master admin's "whole
//     site" backup button (master_admin_backup.php) — never by a
//     subject's own admin.
// (b) PER-SUBJECT ("_for_subject" suffix) — exactly one subject's rows
//     and uploads. Used by admin_backup.php (a subject's own admin,
//     always scoped to their one subject — see require_current_subject()
//     at every call site) and the master admin's per-subject backup
//     section.
//
// Pure PHP throughout (PDO for the dump, ZipArchive for packaging) — no
// mysqldump/shell_exec dependency, since shared hosts don't reliably
// allow either. Both scopes store files outside the webroot, same
// protection ARCHIVE_ROOT/uploads/ already relies on, and are only ever
// served through an authenticated download endpoint — never a direct
// URL.

// --- Shared helpers ---

function subject_archive_root(array $subject): string
{
    return ARCHIVE_ROOT_BASE . '/' . $subject['slug'];
}

// Tables scoped by a direct subject_id column — including the three
// former id=1 singleton tables, where subject_id IS the primary key.
// Update this list any time a table gains a subject_id column
// elsewhere — a table missing from here silently won't appear in, or
// restore from, a per-subject backup. (webauthn_credentials and
// consumed_tokens are deliberately NOT included: they're reachable only
// via a join through users.subject_id, hold no family testimony/content
// of their own, and a lost passkey/replay-guard row after a restore
// just means re-registering a passkey — not worth the extra join-based
// delete/insert complexity here.)
const BACKUP_SUBJECT_SCOPED_TABLES = [
    'users', 'content_items', 'content_files', 'content_suggestions',
    'content_links', 'redacted_names', 'sources', 'audience_modes',
    'people', 'places', 'timeline_entries', 'quotes', 'keywords',
    'site_settings', 'primary_testimony', 'discrepancy_notes',
];

function backup_zip_add_directory(ZipArchive $zip, string $sourceDir, string $zipPrefix): void
{
    if (!is_dir($sourceDir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $localName = $zipPrefix . '/' . substr($file->getPathname(), strlen($sourceDir) + 1);
        $zip->addFile($file->getPathname(), str_replace('\\', '/', $localName));
    }
}

// Deletes every file/subdirectory under $target, then moves everything
// from $source into it — used to make an uploads directory match the
// backup exactly rather than merging old and restored files together.
function backup_replace_directory(string $target, string $source): void
{
    if (is_dir($target)) {
        backup_rrmdir($target, false); // clear contents, keep the directory itself
    } else {
        mkdir($target, 0770, true);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destPath = $target . '/' . $relative;
        if ($item->isDir()) {
            if (!is_dir($destPath)) {
                mkdir($destPath, 0770, true);
            }
        } else {
            rename($item->getPathname(), $destPath);
        }
    }
}

// Recursively deletes $dir. $removeSelf=false clears the directory's
// contents but leaves the (now-empty) directory in place.
function backup_rrmdir(string $dir, bool $removeSelf = true): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    if ($removeSelf) {
        rmdir($dir);
    }
}

// How many backups to keep — the oldest are deleted once there are more
// than this, applied independently per scope (every subject keeps its
// own last N, and the whole-install scope keeps its own last N).
// Install-wide setting (`.env`, like SMTP/rate-limit settings), not
// configurable per subject. 0 (or unset) means "keep everything."
function backup_retention_count(): int
{
    return max(0, (int) env('BACKUP_RETENTION_COUNT', '14'));
}

function backup_auto_interval_hours(): int
{
    return max(0, (int) env('BACKUP_AUTO_INTERVAL_HOURS', '0'));
}

// ============================================================
// Whole-install ("_full") — every subject's rows and uploads at once.
// ============================================================

// Deliberately NOT under any one subject's own directory (that would
// make the location depend on whichever subject happened to be current
// when this runs, which is meaningless for upgrade.php's CLI safety net
// — no subject is resolved there at all). BACKUP_DIR, if set, still
// applies (namespaced under "_install" so it never collides with a
// per-subject backup dir living alongside it).
function backup_dir_full(): string
{
    $configured = env('BACKUP_DIR');
    $dir = $configured !== null && $configured !== ''
        ? rtrim($configured, '/') . '/_install'
        : ARCHIVE_ROOT_BASE . '/_install_backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

// A plain, human-readable SQL dump of the ENTIRE database, every table
// unconditionally — DROP TABLE IF EXISTS + CREATE TABLE (from SHOW
// CREATE TABLE) + INSERT statements, so restoring never depends on also
// having a matching sql/schema.sql on hand. Table/column names are
// validated to be simple identifiers (this app's own schema, never user
// input) before being interpolated, since PDO can't parameterize
// identifiers; values always go through PDO::quote().
function backup_dump_sql_full(PDO $pdo): string
{
    $out = "-- Family Legacy Archive backup (whole install)\n-- Generated " . date('c') . "\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table)) {
            continue; // defensive — every real table name is a plain identifier
        }
        $createRow = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $out .= "DROP TABLE IF EXISTS `$table`;\n" . $createRow['Create Table'] . ";\n\n";

        $stmt = $pdo->query("SELECT * FROM `$table`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = '`' . implode('`, `', array_keys($row)) . '`';
            $vals = implode(', ', array_map(
                static fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                array_values($row)
            ));
            $out .= "INSERT INTO `$table` ($cols) VALUES ($vals);\n";
        }
        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    return $out;
}

// Creates a whole-install backup .zip (database.sql + every subject's
// own uploads/{slug}/ directory) and returns its filename. Throws
// (rather than failing quiet) since a backup that silently didn't
// happen is exactly the failure mode worth surfacing.
function backup_create_full(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP\'s ZipArchive extension is not available on this host — backups need it.');
    }

    @set_time_limit(300); // best-effort — some hosts disable changing this; every subject's uploads can add up

    $filename = 'backup_' . date('Y-m-d_His') . '.zip';
    $path = backup_dir_full() . '/' . $filename;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the backup archive on disk.');
    }

    $zip->addFromString('database.sql', backup_dump_sql_full(db()));

    $subjects = db()->query('SELECT id, slug, hostname FROM subjects')->fetchAll();
    foreach ($subjects as $subject) {
        backup_zip_add_directory($zip, subject_archive_root($subject) . '/uploads', 'uploads/' . $subject['slug']);
    }

    $zip->addFromString('manifest.json', json_encode([
        'created_at' => date('c'),
        'app' => 'family-legacy-archive',
        'scope' => 'full',
        'subjects' => array_map(static fn (array $s): array => ['slug' => $s['slug'], 'hostname' => $s['hostname']], $subjects),
    ], JSON_PRETTY_PRINT));

    $zip->close();
    backup_prune_full();
    return $filename;
}

function backup_list_full(): array
{
    return backup_list_dir(backup_dir_full());
}

function backup_prune_full(): array
{
    $keep = backup_retention_count();
    if ($keep === 0) {
        return [];
    }
    $backups = backup_list_full();
    $toDelete = array_slice($backups, $keep);
    $deleted = [];
    foreach ($toDelete as $b) {
        if (backup_delete_full($b['filename'])) {
            $deleted[] = $b['filename'];
        }
    }
    return $deleted;
}

function backup_resolve_path_full(string $filename): ?string
{
    if (!preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.zip$/', $filename)) {
        return null;
    }
    $path = backup_dir_full() . '/' . $filename;
    return is_file($path) ? $path : null;
}

function backup_delete_full(string $filename): bool
{
    $path = backup_resolve_path_full($filename);
    if ($path === null) {
        return false;
    }
    return unlink($path);
}

// Naive but sufficient statement-splitter — accumulates lines (skipping
// blank/comment-only ones) until one ends in ";"; safe here since the
// dump never contains embedded semicolons (same approach
// includes/setup.php's setup_run_schema() uses for sql/schema.sql).
function backup_run_sql(PDO $pdo, string $sql): void
{
    $statements = [];
    $buffer = '';
    foreach (explode("\n", $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

// Restores from a whole-install backup .zip: replaces every table (via
// the dump's own DROP TABLE IF EXISTS + CREATE TABLE + INSERT
// statements) and replaces every subject's uploads directory —
// literally every subject on the install at once. This is a full
// point-in-time restore, not a merge. $tmpZipPath is the PHP upload's
// tmp_name; validated before anything destructive happens.
function backup_restore_full(string $tmpZipPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath) !== true) {
        throw new RuntimeException('That file is not a valid backup archive.');
    }
    $sql = $zip->getFromName('database.sql');
    if ($sql === false) {
        $zip->close();
        throw new RuntimeException('That archive does not contain a database.sql — it looks like a per-subject backup, not a whole-site one. Restore it from the subject\'s own Backup page instead.');
    }

    $extractDir = sys_get_temp_dir() . '/restore_' . bin2hex(random_bytes(8));
    mkdir($extractDir, 0770, true);
    $zip->extractTo($extractDir);
    $zip->close();

    @set_time_limit(300);

    backup_run_sql(db(), $sql);

    // The restored database.sql just replaced the `subjects` table
    // itself too — re-read it fresh rather than trusting anything
    // resolved earlier in this request.
    $subjects = db()->query('SELECT id, slug FROM subjects')->fetchAll();
    $uploadsRoot = $extractDir . '/uploads';
    if (is_dir($uploadsRoot)) {
        $hasPerSlugDirs = false;
        foreach ($subjects as $subject) {
            $source = $uploadsRoot . '/' . $subject['slug'];
            if (is_dir($source)) {
                $hasPerSlugDirs = true;
                backup_replace_directory(subject_archive_root($subject) . '/uploads', $source);
            }
        }
        // Legacy fallback: a backup made before per-subject uploads
        // existed (pre-2.0.0, or this project's own oldest backups) has
        // a flat uploads/ tree with no per-slug subdirectory at all —
        // there was only ever one subject at the time, so restore the
        // whole flat tree into whichever one subject exists now.
        if (!$hasPerSlugDirs && count($subjects) === 1) {
            backup_replace_directory(subject_archive_root($subjects[0]) . '/uploads', $uploadsRoot);
        }
    }

    backup_rrmdir($extractDir);
}

// ============================================================
// Per-subject ("_for_subject") — exactly one subject's rows and uploads.
// ============================================================

function backup_dir_for_subject(array $subject): string
{
    $configured = env('BACKUP_DIR');
    $dir = $configured !== null && $configured !== ''
        ? rtrim($configured, '/') . '/' . $subject['slug']
        : subject_archive_root($subject) . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

// Newest-first directory listing shared by both scopes — every entry is
// just what's on disk, no separate DB table to keep in sync with reality.
function backup_list_dir(string $dir): array
{
    $files = glob($dir . '/backup_*.zip') ?: [];
    $out = [];
    foreach ($files as $path) {
        $out[] = [
            'filename' => basename($path),
            'size' => filesize($path),
            'createdAt' => date('c', (int) filemtime($path)),
        ];
    }
    usort($out, static fn ($a, $b) => strcmp($b['filename'], $a['filename']));
    return $out;
}

function backup_list_for_subject(array $subject): array
{
    return backup_list_dir(backup_dir_for_subject($subject));
}

function backup_is_due_for_subject(array $subject): bool
{
    $intervalHours = backup_auto_interval_hours();
    if ($intervalHours === 0) {
        return false;
    }
    $backups = backup_list_for_subject($subject);
    if (empty($backups)) {
        return true;
    }
    $newest = strtotime($backups[0]['createdAt']);
    return $newest === false || $newest <= time() - ($intervalHours * 3600);
}

function backup_run_scheduled_for_subject(array $subject): ?string
{
    return backup_is_due_for_subject($subject) ? backup_create_for_subject($subject) : null;
}

function backup_prune_for_subject(array $subject): array
{
    $keep = backup_retention_count();
    if ($keep === 0) {
        return [];
    }
    $backups = backup_list_for_subject($subject);
    $toDelete = array_slice($backups, $keep);
    $deleted = [];
    foreach ($toDelete as $b) {
        if (backup_delete_for_subject($subject, $b['filename'])) {
            $deleted[] = $b['filename'];
        }
    }
    return $deleted;
}

function backup_resolve_path_for_subject(array $subject, string $filename): ?string
{
    if (!preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.zip$/', $filename)) {
        return null;
    }
    $path = backup_dir_for_subject($subject) . '/' . $filename;
    return is_file($path) ? $path : null;
}

function backup_delete_for_subject(array $subject, string $filename): bool
{
    $path = backup_resolve_path_for_subject($subject, $filename);
    if ($path === null) {
        return false;
    }
    return unlink($path);
}

// Read-only summary of the current .env-configured controls, for
// admin_backup.php to display — there's no admin form for these (they're
// infrastructure config, same as SMTP/rate-limit settings), applied
// independently per subject even though the interval/retention numbers
// themselves are install-wide.
function backup_auto_status_for_subject(array $subject): array
{
    $backups = backup_list_for_subject($subject);
    return [
        'intervalHours' => backup_auto_interval_hours(),
        'retentionCount' => backup_retention_count(),
        'dir' => backup_dir_for_subject($subject),
        'lastBackupAt' => $backups[0]['createdAt'] ?? null,
        'dueNow' => backup_is_due_for_subject($subject),
    ];
}

// Every subject-scoped table's rows for $subjectId, keyed by table
// name — the per-subject dump format. Structured JSON rather than a SQL
// dump: table STRUCTURE is shared across every subject in one database,
// so a per-subject restore must never DROP/CREATE TABLE, only replace
// that one subject's own rows — and the target subject's id must be
// remappable at restore time (re-provisioning a subject under a new
// UUID shouldn't try to write the OLD one).
function backup_dump_data_for_subject(PDO $pdo, string $subjectId): array
{
    $data = [];
    foreach (BACKUP_SUBJECT_SCOPED_TABLES as $table) {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE subject_id = ?");
        $stmt->execute([$subjectId]);
        $data[$table] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
}

function backup_create_for_subject(array $subject): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP\'s ZipArchive extension is not available on this host — backups need it.');
    }

    @set_time_limit(120);

    $filename = 'backup_' . date('Y-m-d_His') . '.zip';
    $path = backup_dir_for_subject($subject) . '/' . $filename;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the backup archive on disk.');
    }

    $data = backup_dump_data_for_subject(db(), $subject['id']);
    $zip->addFromString('subject_data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    backup_zip_add_directory($zip, subject_archive_root($subject) . '/uploads', 'uploads');
    $zip->addFromString('manifest.json', json_encode([
        'created_at' => date('c'),
        'app' => 'family-legacy-archive',
        'scope' => 'subject',
        'slug' => $subject['slug'],
        'hostname' => $subject['hostname'],
    ], JSON_PRETTY_PRINT));

    $zip->close();
    backup_prune_for_subject($subject);
    return $filename;
}

// Restores a per-subject backup .zip onto $targetSubject — replaces
// only that subject's own rows (every table in
// BACKUP_SUBJECT_SCOPED_TABLES, WHERE subject_id = target) and that
// subject's own uploads directory. Every other subject on this install
// is completely untouched. $tmpZipPath is the PHP upload's tmp_name;
// validated before anything destructive happens.
function backup_restore_for_subject(string $tmpZipPath, array $targetSubject): void
{
    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath) !== true) {
        throw new RuntimeException('That file is not a valid backup archive.');
    }
    $json = $zip->getFromName('subject_data.json');
    if ($json === false) {
        $zip->close();
        throw new RuntimeException('That archive does not contain a subject_data.json — it looks like a whole-site backup, not a per-subject one. A master admin can restore it from the master admin Backup page instead.');
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        $zip->close();
        throw new RuntimeException('That backup\'s subject_data.json could not be read.');
    }

    $extractDir = sys_get_temp_dir() . '/restore_' . bin2hex(random_bytes(8));
    mkdir($extractDir, 0770, true);
    $zip->extractTo($extractDir);
    $zip->close();

    @set_time_limit(120);

    $pdo = db();
    $targetId = $targetSubject['id'];
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (BACKUP_SUBJECT_SCOPED_TABLES as $table) {
            $pdo->prepare("DELETE FROM `$table` WHERE subject_id = ?")->execute([$targetId]);
        }
        foreach ($data as $table => $rows) {
            if (!in_array($table, BACKUP_SUBJECT_SCOPED_TABLES, true) || !is_array($rows)) {
                continue; // defensive — ignore anything not in today's known scoped-table list
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row['subject_id'] = $targetId; // remap onto the target subject, whatever the dump's original subject_id was
                $cols = '`' . implode('`, `', array_keys($row)) . '`';
                $placeholders = implode(', ', array_fill(0, count($row), '?'));
                $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($placeholders)")->execute(array_values($row));
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    $uploadsSource = $extractDir . '/uploads';
    if (is_dir($uploadsSource)) {
        backup_replace_directory(subject_archive_root($targetSubject) . '/uploads', $uploadsSource);
    }

    backup_rrmdir($extractDir);
}
