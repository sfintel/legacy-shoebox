<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();
$subject = require_current_subject();

$filename = (string) ($_GET['filename'] ?? '');
$path = backup_resolve_path_for_subject($subject, $filename);
if ($path === null) {
    json_response(['error' => 'Backup not found.'], 404);
}

header('Content-Type: application/zip');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
readfile($path);
