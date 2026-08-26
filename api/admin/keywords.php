<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// GET is open to anyone who can add content (admin or author) — both
// Quotes' and Content's tag pickers need the master list for
// suggestions. Managing the list itself (create/rename/delete) is
// admin-only, checked below rather than via require_admin_api() at the
// top, since that would also block the GET an author's picker needs.
$user = require_content_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['keywords' => keywords_all()]);
}

if ($method === 'POST') {
    if ($user['role'] !== 'admin') {
        json_response(['error' => 'Admins only.'], 403);
    }
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');
    $id = (string) ($body['id'] ?? '');

    try {
        if ($action === 'create') {
            json_response(['keyword' => keywords_create((string) ($body['label'] ?? ''))], 201);
        }
        if ($action === 'rename') {
            json_response(['keyword' => keywords_rename($id, (string) ($body['label'] ?? ''))]);
        }
        if ($action === 'delete') {
            if (!keywords_delete($id)) {
                json_response(['error' => 'Keyword not found.'], 404);
            }
            json_response(['ok' => true]);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);
