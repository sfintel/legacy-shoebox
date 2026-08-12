<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$file = $_FILES['backup'] ?? null;
if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
    $message = $file !== null && $file['error'] === UPLOAD_ERR_INI_SIZE
        ? 'That file is larger than this server allows uploading in one request.'
        : 'No backup file was uploaded.';
    json_response(['error' => $message], 400);
}

try {
    backup_restore($file['tmp_name']);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}

json_response(['ok' => true]);
