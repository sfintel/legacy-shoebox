<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['people' => array_map('archive_person_admin', archive_people())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['person' => archive_person_admin(archive_person_create($body))], 201);
        }
        if ($action === 'update') {
            json_response(['person' => archive_person_admin(archive_person_update($id, $body))]);
        }
        if ($action === 'delete') {
            if (!archive_person_delete($id)) {
                json_response(['error' => 'Person not found.'], 404);
            }
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);

function archive_person_admin(array $row): array
{
    return [
        'id' => $row['id'],
        'names' => json_decode((string) $row['names'], true) ?: [],
        'role' => $row['role'],
        'fate' => $row['fate'],
        'notes' => $row['notes'],
        'nameNote' => $row['name_note'],
        'sourceNote' => $row['source_note'],
        'citation' => $row['citation'],
        'createdAt' => $row['created_at'],
    ];
}
