<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');
$narrativeNote = array_key_exists('narrativeNote', $body) ? (string) $body['narrativeNote'] : null;
$fileUpdates = is_array($body['files'] ?? null) ? $body['files'] : [];
$tags = is_array($body['tags'] ?? null) ? $body['tags'] : null;
$markReviewed = ($body['markReviewed'] ?? false) === true;

// Admins can edit any item; a permitted non-admin only their own.
$isAdmin = $user['role'] === 'admin';
$ownerId = $isAdmin ? null : $user['id'];

try {
    $item = content_update_item($id, $ownerId, $narrativeNote, $fileUpdates, $tags, $isAdmin, $markReviewed);
} catch (RuntimeException $e) {
    $status = $e->getMessage() === 'Content item not found.' ? 404 : 400;
    json_response(['error' => $e->getMessage()], $status);
}

json_response(['item' => content_public($item)]);
