<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['sources' => array_map('source_admin', archive_sources())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['source' => source_admin(archive_source_create($body))], 201);
        }
        if ($action === 'update') {
            json_response(['source' => source_admin(archive_source_update($id, $body))]);
        }
        if ($action === 'delete') {
            if (!archive_source_delete($id)) {
                json_response(['error' => 'Source not found.'], 404);
            }
            json_response(['ok' => true]);
        }
        if ($action === 'move') {
            archive_source_move($id, (string) ($body['direction'] ?? ''));
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);

function source_admin(array $row): array
{
    return [
        'id' => $row['id'],
        'label' => $row['label'],
        'details' => $row['details'],
        'isDramatization' => (bool) $row['is_dramatization'],
        'permissionNote' => $row['permission_note'],
        'createdAt' => $row['created_at'],
    ];
}
