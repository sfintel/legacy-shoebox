<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

// Same visibility level as api/file.php — a video's custom thumbnail is
// just another view of a video any approved user can already watch, not
// a new access tier.
require_auth_api();

$itemId = (string) ($_GET['itemId'] ?? '');
$item = content_find($itemId);
if (!$item || empty($item['thumbnail_file_name'])) {
    json_response(['error' => 'No thumbnail.'], 404);
}

$path = content_upload_dir('thumbnails') . '/' . $item['thumbnail_file_name'];
if (!is_file($path)) {
    json_response(['error' => 'Thumbnail missing on disk.'], 404);
}

content_stream_file($path, 'image/jpeg', $item['title'] . '-thumbnail.jpg');
