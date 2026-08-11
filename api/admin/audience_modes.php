<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['modes' => array_map('audience_mode_admin', archive_audience_modes_rows())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['mode' => audience_mode_admin(archive_audience_mode_create($body))], 201);
        }
        if ($action === 'update') {
            json_response(['mode' => audience_mode_admin(archive_audience_mode_update($id, $body))]);
        }
        if ($action === 'delete') {
            if (!archive_audience_mode_delete($id)) {
                json_response(['error' => 'Category not found.'], 404);
            }
            json_response(['ok' => true]);
        }
        if ($action === 'move') {
            archive_audience_mode_move($id, (string) ($body['direction'] ?? ''));
            json_response(['ok' => true]);
        }
        if ($action === 'set_default') {
            archive_audience_mode_set_default($id);
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);

function audience_mode_admin(array $row): array
{
    return [
        'id' => $row['id'],
        'slug' => $row['slug'],
        'label' => $row['label'],
        'aiGuidance' => $row['ai_guidance'],
        'isDefault' => (bool) $row['is_default'],
        'createdAt' => $row['created_at'],
    ];
}
