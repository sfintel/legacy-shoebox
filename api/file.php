<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

// Any approved user can view a family-contributed photo/video referenced
// by the Ask tab (see [[photo:ID]]/[[video:ID]] in
// includes/knowledge.php) — deliberately not restricted to admins or
// content-permitted contributors like api/admin/content_file.php, since
// content_context() already describes every item's title/caption/
// metadata/narrative-connection to every approved user regardless of
// who added it. Viewing the actual file is the same visibility level,
// not a new one.
require_auth_api();

$fileId = (string) ($_GET['fileId'] ?? '');
$file = content_file_find($fileId);
if (!$file) {
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
