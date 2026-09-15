<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_master_admin_api();

$scope = (string) ($_GET['scope'] ?? '');
$filename = (string) ($_GET['filename'] ?? '');

if ($scope === 'full') {
    $path = backup_resolve_path_full($filename);
} elseif ($scope === 'subject') {
    $subject = subject_find_by_id((string) ($_GET['subject'] ?? ''));
    if (!$subject) {
        json_response(['error' => 'Subject not found.'], 404);
    }
    $path = backup_resolve_path_for_subject($subject, $filename);
} else {
    json_response(['error' => 'scope must be "full" or "subject".'], 400);
}

if ($path === null) {
    json_response(['error' => 'Backup not found.'], 404);
}

header('Content-Type: application/zip');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
readfile($path);
