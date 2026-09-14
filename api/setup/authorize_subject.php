<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!setup_schema_ready()) {
    json_response(['error' => 'The database is not set up yet.'], 400);
}
if (current_subject() !== null) {
    json_response(['error' => 'This hostname is already set up.'], 400);
}

$body = read_json_body();
$secret = isset($body['secret']) ? (string) $body['secret'] : null;

if (!setup_new_subject_authorized($secret)) {
    json_response(['error' => 'Incorrect setup secret.'], 403);
}

$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if ($host === '') {
    json_response(['error' => 'Could not determine this hostname.'], 400);
}
setup_create_subject_for_host($host);

json_response(['ok' => true]);
