<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_master_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$scope = (string) ($_POST['scope'] ?? '');
$subject = null;
if ($scope === 'subject') {
    $subjectId = (string) ($_POST['subject'] ?? '');
    $subject = $subjectId !== '' ? subject_find_by_id($subjectId) : null;
    if (!$subject) {
        json_response(['error' => 'Subject not found.'], 404);
    }
} elseif ($scope !== 'full') {
    json_response(['error' => 'scope must be "full" or "subject".'], 400);
}

$file = $_FILES['backup'] ?? null;
if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
    $message = $file !== null && $file['error'] === UPLOAD_ERR_INI_SIZE
        ? 'That file is larger than this server allows uploading in one request.'
        : 'No backup file was uploaded.';
    json_response(['error' => $message], 400);
}

try {
    if ($scope === 'full') {
        backup_restore_full($file['tmp_name']);
    } else {
        backup_restore_for_subject($file['tmp_name'], $subject);
    }
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}

json_response(['ok' => true]);
