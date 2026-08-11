<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['testimony' => archive_testimony_admin(archive_primary_testimony())]);
}

if ($method === 'POST') {
    $body = read_json_body();
    json_response(['testimony' => archive_testimony_admin(archive_primary_testimony_update($body))]);
}

json_response(['error' => 'Method not allowed'], 405);

function archive_testimony_admin(?array $row): array
{
    return [
        'interviewLabel' => $row['interview_label'] ?? null,
        'interviewDate' => $row['interview_date'] ?? null,
        'location' => $row['location'] ?? null,
        'interviewer' => $row['interviewer'] ?? null,
        'videographer' => $row['videographer'] ?? null,
        'lengthLabel' => $row['length_label'] ?? null,
        'rawMarkdown' => $row['raw_markdown'] ?? null,
    ];
}
