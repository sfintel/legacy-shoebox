<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

// Same visibility level as api/file.php — pseudo closed captions are
// just another view of a video any approved user can already watch, not
// a new access tier. See includes/video_seek.php's
// video_seek_vtt_for_file() for what "pseudo" means here.
require_auth_api();

$fileId = (string) ($_GET['fileId'] ?? '');
$vtt = video_seek_vtt_for_file($fileId);
if ($vtt === null) {
    json_response(['error' => 'No captions available for this file.'], 404);
}

header('Content-Type: text/vtt; charset=utf-8');
echo $vtt;
