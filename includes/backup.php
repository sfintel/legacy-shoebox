<?php
declare(strict_types=1);

// Whole-archive backup/restore — see admin_backup.php and
// api/admin/backup.php. A backup is a single .zip containing a full
// database dump (database.sql — real INSERT statements, not just a
// schema, so it's a complete standalone restore point) plus a copy of
// ARCHIVE_ROOT/uploads/ (the actual family-contributed files). Pure PHP
// throughout (PDO for the dump, ZipArchive for packaging) — no
// mysqldump/shell_exec dependency, since shared hosts don't reliably
// allow either (see includes/narrative.php's own shell_exec fallback
// for the same reason).
//
// Stored under ARCHIVE_ROOT/backups/ — outside the webroot, same
// protection as ARCHIVE_ROOT/uploads/ already relies on (see
// content_upload_dir()) — never served by a direct URL, only through
// api/admin/backup_download.php's authenticated stream.

function backup_dir(): string
{
    $dir = ARCHIVE_ROOT . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

// A plain, human-readable SQL dump — DROP TABLE IF EXISTS + CREATE
// TABLE (from SHOW CREATE TABLE) + INSERT statements, per table, so
// restoring never depends on also having a matching sql/schema.sql on
// hand. Table/column names are validated to be simple identifiers
// (this app's own schema, never user input) before being interpolated,
// since PDO can't parameterize identifiers; values always go through
// PDO::quote().
function backup_dump_sql(PDO $pdo): string
{
    $out = "-- Family Legacy Archive backup\n-- Generated " . date('c') . "\n\n";
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

// Creates a new backup .zip and returns its filename. Throws (rather
// than failing quiet, unlike this app's AI calls) since a backup that
// silently didn't happen is exactly the failure mode worth surfacing.
function backup_create(): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP\'s ZipArchive extension is not available on this host — backups need it.');
    }

    @set_time_limit(120); // best-effort — some hosts disable changing this

    $filename = 'backup_' . date('Y-m-d_His') . '.zip';
    $path = backup_dir() . '/' . $filename;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the backup archive on disk.');
    }

    $zip->addFromString('database.sql', backup_dump_sql(db()));
    backup_zip_add_directory($zip, ARCHIVE_ROOT . '/uploads', 'uploads');
    $zip->addFromString('manifest.json', json_encode([
        'created_at' => date('c'),
        'site_name' => site_name(),
        'app' => 'family-legacy-archive',
    ], JSON_PRETTY_PRINT));

    $zip->close();
    return $filename;
}

// Newest first. Every entry is just what's on disk under
// ARCHIVE_ROOT/backups/ — no separate DB table to keep in sync with
// reality.
function backup_list(): array
{
    $dir = backup_dir();
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

// Rejects anything that isn't a bare filename already known to
// backup_list() — the only defense that matters here, since this
// filename later gets concatenated into a filesystem path.
function backup_resolve_path(string $filename): ?string
{
    if (!preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.zip$/', $filename)) {
        return null;
    }
    $path = backup_dir() . '/' . $filename;
    return is_file($path) ? $path : null;
}

function backup_delete(string $filename): bool
{
    $path = backup_resolve_path($filename);
    if ($path === null) {
        return false;
    }
    return unlink($path);
}

// Naive but sufficient statement-splitter — same approach
// includes/setup.php's setup_run_schema() already uses for
// sql/schema.sql, extended here to also cover the plain INSERT
// statements backup_dump_sql() produces (which never contain embedded
// semicolons, same as CREATE TABLE never does in this app's own
// schema).
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

// Restores from an uploaded backup .zip: replaces every table (via the
// dump's own DROP TABLE IF EXISTS + CREATE TABLE + INSERT statements)
// and replaces ARCHIVE_ROOT/uploads/ wholesale — this is a full
// point-in-time restore, not a merge. $tmpZipPath is the PHP upload's
// tmp_name; validated before anything destructive happens.
function backup_restore(string $tmpZipPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($tmpZipPath) !== true) {
        throw new RuntimeException('That file is not a valid backup archive.');
    }
    $sql = $zip->getFromName('database.sql');
    if ($sql === false) {
        $zip->close();
        throw new RuntimeException('That archive does not contain a database.sql — it does not look like a backup made by this app.');
    }

    $extractDir = sys_get_temp_dir() . '/restore_' . bin2hex(random_bytes(8));
    mkdir($extractDir, 0770, true);
    $zip->extractTo($extractDir);
    $zip->close();

    @set_time_limit(120);

    backup_run_sql(db(), $sql);

    $uploadsSource = $extractDir . '/uploads';
    if (is_dir($uploadsSource)) {
        $uploadsTarget = ARCHIVE_ROOT . '/uploads';
        backup_replace_directory($uploadsTarget, $uploadsSource);
    }

    backup_rrmdir($extractDir);
}

// Deletes every file/subdirectory under $target, then moves everything
// from $source into it — used to make ARCHIVE_ROOT/uploads/ match the
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
