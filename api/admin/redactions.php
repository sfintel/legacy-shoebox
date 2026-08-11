<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$admin = require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = db()->query('SELECT id, name, created_at FROM redacted_names ORDER BY created_at ASC');
    $names = array_map(static function (array $row): array {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'createdAt' => $row['created_at'],
        ];
    }, $stmt->fetchAll());
    json_response(['names' => $names]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $name = (string) ($body['name'] ?? '');

    try {
        $row = redact_add($name, $admin['id']);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['name' => [
        'id' => $row['id'],
        'name' => $row['name'],
        'createdAt' => $row['created_at'],
    ]], 201);
}

json_response(['error' => 'Method not allowed'], 405);
