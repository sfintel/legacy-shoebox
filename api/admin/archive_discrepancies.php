<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $row = archive_discrepancy_notes();
    json_response(['contentMarkdown' => $row['content_markdown'] ?? null]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $row = archive_discrepancy_notes_update($body);
    json_response(['contentMarkdown' => $row['content_markdown'] ?? null]);
}

json_response(['error' => 'Method not allowed'], 405);
