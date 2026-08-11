<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['quotes' => array_map('archive_quote_admin', archive_quotes())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['quote' => archive_quote_admin(archive_quote_create($body))], 201);
        }
        if ($action === 'update') {
            json_response(['quote' => archive_quote_admin(archive_quote_update($id, $body))]);
        }
        if ($action === 'delete') {
            if (!archive_quote_delete($id)) {
                json_response(['error' => 'Quote not found.'], 404);
            }
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);

function archive_quote_admin(array $row): array
{
    return [
        'id' => $row['id'],
        'speaker' => $row['speaker'],
        'sourceNote' => $row['source_note'],
        'tags' => json_decode((string) ($row['tags'] ?? '[]'), true) ?: [],
        'quoteText' => $row['quote_text'],
        'citation' => $row['citation'],
        'createdAt' => $row['created_at'],
    ];
}
