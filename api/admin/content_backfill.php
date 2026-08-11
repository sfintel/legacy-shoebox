<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

json_response(['results' => content_backfill_narrative_notes()]);
