<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// Admin-only, deliberately — matches the "admin approves before it's
// used" design: an author can submit a story via api/admin/content.php
// (require_content_api(), author-or-admin) but only an admin can move
// it from pending into the Stories tab / AI knowledge base.
require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');

$item = content_find($id);
if ($item === null || $item['type'] !== 'story') {
    json_response(['error' => 'Story not found.'], 404);
}
if ($item['story_approved_at'] !== null) {
    json_response(['error' => 'Already approved.'], 400);
}

$updated = content_approve_story($id);
json_response(['item' => content_public($updated)]);
