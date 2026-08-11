<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['places' => array_map('archive_place_admin', archive_places())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['place' => archive_place_admin(archive_place_create($body))], 201);
        }
        if ($action === 'update') {
            json_response(['place' => archive_place_admin(archive_place_update($id, $body))]);
        }
        if ($action === 'delete') {
            if (!archive_place_delete($id)) {
                json_response(['error' => 'Place not found.'], 404);
            }
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);

function archive_place_admin(array $row): array
{
    return [
        'id' => $row['id'],
        'names' => json_decode((string) $row['names'], true) ?: [],
        'wartimeCountry' => $row['wartime_country'],
        'modernCountry' => $row['modern_country'],
        'approxCoords' => $row['approx_coords'],
        'role' => $row['role'],
        'notes' => $row['notes'],
        'sourceNote' => $row['source_note'],
        'citation' => $row['citation'],
        'createdAt' => $row['created_at'],
    ];
}
