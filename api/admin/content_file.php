<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

$fileId = (string) ($_GET['fileId'] ?? '');
$file = content_file_find($fileId);
if (!$file) {
    json_response(['error' => 'File not found.'], 404);
}

// A permitted non-admin can only stream files belonging to their own
// items — not just hidden from the list, genuinely inaccessible by id.
if ($user['role'] !== 'admin' && !content_file_belongs_to($file, $user['id'])) {
    json_response(['error' => 'File not found.'], 404);
}

$item = content_find($file['content_item_id']);
if (!$item) {
    json_response(['error' => 'File not found.'], 404);
}

$path = content_upload_dir($item['type']) . '/' . $file['file_name'];
if (!is_file($path)) {
    json_response(['error' => 'File missing on disk.'], 404);
}

$safeName = str_replace(['"', "\r", "\n"], '', $file['original_name']);
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . $safeName . '"');
readfile($path);
