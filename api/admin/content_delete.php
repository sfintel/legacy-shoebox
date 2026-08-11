<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');

// Admins can delete any item; a permitted non-admin only their own.
$ownerId = $user['role'] === 'admin' ? null : $user['id'];
if (!content_delete($id, $ownerId)) {
    json_response(['error' => 'Content item not found.'], 404);
}

json_response(['ok' => true]);
