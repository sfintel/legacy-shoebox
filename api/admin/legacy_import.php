<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$str = static fn ($key) => trim((string) ($body[$key] ?? ''));

$counts = ['people' => 0, 'places' => 0, 'timeline' => 0, 'quotes' => 0, 'testimony' => false, 'discrepancies' => false];

try {
    $peopleYaml = $str('people_yaml');
    if ($peopleYaml !== '') {
        foreach (legacy_import_parse_people($peopleYaml) as $fields) {
            archive_person_upsert_by_slug($fields);
            $counts['people']++;
        }
    }

    $placesYaml = $str('places_yaml');
    if ($placesYaml !== '') {
        foreach (legacy_import_parse_places($placesYaml) as $fields) {
            archive_place_upsert_by_slug($fields);
            $counts['places']++;
        }
    }

    $timelineYaml = $str('timeline_yaml');
    if ($timelineYaml !== '') {
        foreach (legacy_import_parse_timeline($timelineYaml) as $fields) {
            archive_timeline_create($fields);
            $counts['timeline']++;
        }
    }

    $quotesYaml = $str('quotes_yaml');
    if ($quotesYaml !== '') {
        foreach (legacy_import_parse_quotes($quotesYaml) as $fields) {
            archive_quote_create($fields);
            $counts['quotes']++;
        }
    }

    $transcriptMd = $str('transcript_md');
    if ($transcriptMd !== '') {
        $rawMarkdown = legacy_import_transform_transcript($transcriptMd, $str('subject_marker'), $str('interviewer_marker'));
        archive_primary_testimony_update([
            'interview_label' => $str('interview_label') ?: null,
            'interview_date' => $str('interview_date') ?: null,
            'location' => $str('location') ?: null,
            'interviewer' => $str('interviewer') ?: null,
            'videographer' => $str('videographer') ?: null,
            'length_label' => $str('length_label') ?: null,
            'raw_markdown' => $rawMarkdown,
        ]);
        $counts['testimony'] = true;
    }

    $discrepanciesMd = $str('discrepancies_md');
    if ($discrepanciesMd !== '') {
        archive_discrepancy_notes_update(['content_markdown' => $discrepanciesMd]);
        $counts['discrepancies'] = true;
    }
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage(), 'counts' => $counts], 400);
}

json_response(['ok' => true, 'counts' => $counts]);
