<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

require_auth_api();

$allowed = ['quotes', 'people', 'places', 'timeline', 'transcript', 'discrepancies'];
$name = $_GET['name'] ?? '';

if (!in_array($name, $allowed, true)) {
    json_response(['error' => 'Unknown dataset.'], 400);
}

// Queries the core archive tables directly (see includes/archive.php) —
// the database is the source of truth, not a static data/*.json file
// copied in from a Node build step. redact_text() is applied to the
// final JSON text the same way the old file-based version did: safe
// because the replacement string has no JSON-structural characters and
// only ever lands inside already-quoted string values.
$data = match ($name) {
    'people' => array_map('archive_person_public', archive_people()),
    'places' => array_map('archive_place_public', archive_places()),
    'timeline' => array_map('archive_timeline_entry_public', archive_timeline()),
    'quotes' => array_map('archive_quote_public', archive_quotes()),
    'transcript' => archive_transcript_payload(),
    'discrepancies' => archive_discrepancies_payload(),
};

$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$json = redact_text($json, redacted_names());

header('Content-Type: application/json');
echo $json;
