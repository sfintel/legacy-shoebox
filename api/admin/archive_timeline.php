<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['entries' => array_map('archive_timeline_admin', archive_timeline())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['entry' => archive_timeline_admin(archive_timeline_create($body))], 201);
        }
        if ($action === 'update') {
            json_response(['entry' => archive_timeline_admin(archive_timeline_update($id, $body))]);
        }
        if ($action === 'delete') {
            if (!archive_timeline_delete($id)) {
                json_response(['error' => 'Timeline entry not found.'], 404);
            }
            json_response(['ok' => true]);
        }
        if ($action === 'move') {
            archive_timeline_move($id, (string) ($body['direction'] ?? ''));
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);

function archive_timeline_admin(array $row): array
{
    return [
        'id' => $row['id'],
        'dateLabel' => $row['date_label'],
        'event' => $row['event'],
        'sourceNote' => $row['source_note'],
        'confidence' => $row['confidence'],
        'note' => $row['note'],
        'historicalDate' => $row['historical_date'],
        'historicalSource' => $row['historical_source'],
        'citation' => $row['citation'],
        'createdAt' => $row['created_at'],
    ];
}
