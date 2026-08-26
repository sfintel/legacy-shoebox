<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['backups' => backup_list(), 'auto' => backup_auto_status()]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $action = (string) ($body['action'] ?? '');

    if ($action === 'create') {
        try {
            $filename = backup_create();
        } catch (Throwable $e) {
            json_response(['error' => $e->getMessage()], 500);
        }
        json_response(['filename' => $filename], 201);
    }

    if ($action === 'delete') {
        $filename = (string) ($body['filename'] ?? '');
        if (!backup_delete($filename)) {
            json_response(['error' => 'Backup not found.'], 404);
        }
        json_response(['ok' => true]);
    }

    if ($action === 'prune') {
        $deleted = backup_prune();
        json_response(['deleted' => $deleted]);
    }

    json_response(['error' => 'Unknown action.'], 400);
}

json_response(['error' => 'Method not allowed'], 405);
