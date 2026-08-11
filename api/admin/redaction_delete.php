<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');

if (!redact_remove($id)) {
    json_response(['error' => 'Not found.'], 404);
}

json_response(['ok' => true]);
