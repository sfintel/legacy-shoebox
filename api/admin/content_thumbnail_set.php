<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');
$dataUrl = (string) ($body['dataUrl'] ?? '');

// Admins can set any video's thumbnail; a permitted non-admin only their own.
$isAdmin = $user['role'] === 'admin';
$ownerId = $isAdmin ? null : $user['id'];

try {
    $item = content_set_thumbnail($id, $ownerId, $dataUrl);
} catch (RuntimeException $e) {
    $status = $e->getMessage() === 'Content item not found.' ? 404 : 400;
    json_response(['error' => $e->getMessage()], $status);
}

json_response(['item' => content_public($item)]);
