<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $itemId = (string) ($_GET['itemId'] ?? '');
    $item = content_find($itemId);
    if (!$item || ($user['role'] !== 'admin' && $item['created_by'] !== $user['id'])) {
        json_response(['error' => 'Content item not found.'], 404);
    }
    json_response(['suggestions' => content_suggestions_for_item($itemId)]);
}

if ($method === 'POST') {
    // Approving/dismissing mutates the shared canonical archive —
    // admin-only, unlike adding the URL content item itself.
    $admin = require_admin_api();

    $body = read_json_body();
    $id = (string) ($body['id'] ?? '');
    $action = (string) ($body['action'] ?? '');
    if (!in_array($action, ['approve', 'dismiss'], true)) {
        json_response(['error' => 'Unknown action.'], 400);
    }

    $suggestion = content_suggestion_find($id);
    if (!$suggestion) {
        json_response(['error' => 'Suggestion not found.'], 404);
    }
    if ($suggestion['status'] !== 'pending') {
        json_response(['error' => 'This suggestion was already decided.'], 400);
    }

    $result = ['ok' => true];
    if ($action === 'approve') {
        $fields = json_decode($suggestion['payload'], true);
        try {
            $result['applied'] = kw_apply_suggestion($suggestion['kind'], $fields, $suggestion['content_item_id']);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], 400);
        }
    }

    content_suggestion_set_status($id, $action === 'approve' ? 'approved' : 'dismissed', $admin['id']);
    json_response($result);
}

json_response(['error' => 'Method not allowed'], 405);
