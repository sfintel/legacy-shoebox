<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

require_auth_api();

$allowed = ['quotes', 'people', 'places', 'timeline', 'transcript', 'discrepancies', 'stories', 'media'];
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
    'stories' => array_map('content_story_public', content_stories_approved()),
    // The Media tab's full library — every video/audio/photo item that
    // actually has a playable file, newest first (content_all()'s own
    // ordering), regardless of whether an admin's AI analysis pass linked
    // it to any Timeline/Quotes/People/Places entry. A video/audio item
    // can exist with no file at all (just a transcript — see
    // content_form.js's "optional if a transcript is given"), so that's
    // filtered here rather than left for js/app.js to handle a fileless
    // "video" it can't actually play.
    'media' => array_values(array_filter(
        array_map('content_media_public', array_filter(
            content_all(),
            static fn (array $item): bool => in_array($item['type'], ['video', 'audio', 'photo'], true)
        )),
        static fn (array $item): bool => !empty($item['files'])
    )),
};

$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$json = redact_text($json, redacted_names());

header('Content-Type: application/json');
echo $json;
