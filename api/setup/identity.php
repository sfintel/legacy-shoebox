<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!setup_schema_ready()) {
    json_response(['error' => 'The database is not set up yet.'], 400);
}

$body = read_json_body();

if (!empty($body['load_demo'])) {
    setup_load_demo_archive();
    manifest_regenerate();
    json_response(['ok' => true]);
}

$siteName = trim((string) ($body['site_name'] ?? ''));
$subjectName = trim((string) ($body['subject_name'] ?? ''));
if ($siteName === '' || $subjectName === '') {
    json_response(['error' => 'Site name and subject name are required.'], 400);
}

setup_seed_default_audience_modes();
archive_site_settings_update($body);
manifest_regenerate();

json_response(['ok' => true]);
