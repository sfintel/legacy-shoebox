<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['settings' => settings_admin_shape(site_settings())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $updated = archive_site_settings_update($body);
    // Page titles, About tab, manifest.json, and every AI prompt read
    // site identity live — but the manifest is a static file (see
    // manifest_regenerate()'s comment), so it needs an explicit
    // regenerate call whenever identity changes, unlike everything else.
    manifest_regenerate();
    json_response(['settings' => settings_admin_shape($updated)]);
}

json_response(['error' => 'Method not allowed'], 405);

function settings_admin_shape(array $s): array
{
    return [
        'siteName' => $s['site_name'],
        'subjectName' => $s['subject_name'],
        'subjectPronounSubject' => $s['subject_pronoun_subject'],
        'subjectPronounObject' => $s['subject_pronoun_object'],
        'subjectPronounPossessive' => $s['subject_pronoun_possessive'],
        'subjectBirthDate' => $s['subject_birth_date'],
        'subjectBirthplace' => $s['subject_birthplace'],
        'subjectShortBio' => $s['subject_short_bio'],
        'closingQuote' => $s['closing_quote'],
        'closingQuoteAttribution' => $s['closing_quote_attribution'],
        'askPlaceholderText' => $s['ask_placeholder_text'],
        'setupCompletedAt' => $s['setup_completed_at'],
    ];
}
