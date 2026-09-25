<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Admins see everything; a permitted non-admin only sees their own.
    $items = content_all($user['role'] === 'admin' ? null : $user['id']);
    // hasCaptions (video only) mirrors api/data.php's "media" dataset —
    // computed here rather than in content_public() (includes/content.php)
    // so that lower-level shaping function doesn't have to depend on
    // includes/video_seek.php's transcript-linking logic. Drives the row
    // "View" modal's CC button (js/admin_content.js's FileViewer).
    json_response(['items' => array_map(static function (array $item): array {
        $public = content_public($item);
        if ($item['type'] === 'video') {
            $fileId = $public['files'][0]['id'] ?? null;
            $public['hasCaptions'] = $fileId !== null && !empty(video_seek_transcript_segments_for_file($fileId));
        }
        return $public;
    }, $items)]);
}

if ($method === 'POST') {
    $type = (string) ($_POST['type'] ?? '');
    $title = (string) ($_POST['title'] ?? '');
    $description = $_POST['description'] ?? null;
    $files = content_normalize_multi_files($_FILES['files'] ?? ['name' => []]);
    $tags = is_array($_POST['tags'] ?? null) ? $_POST['tags'] : [];
    $mediaUrl = trim((string) ($_POST['mediaUrl'] ?? ''));

    try {
        if ($type === 'recording') {
            // The combined "Video/Audio + Transcript" add-content flow —
            // a media file/URL and transcript text are both optional
            // (transcript-only, and — new here — media-only, are both
            // still valid), but at least one of the two must be given.
            // Reuses content_create_video()/content_create_audio()/
            // content_create_transcript() unchanged rather than a new
            // combined creation function, so each sub-item gets exactly
            // the same narrative-note/suggestion-extraction/auto-link
            // behavior it would get submitted standalone — then links
            // the two explicitly with the SAME $title used for both,
            // rather than relying only on content_auto_link_attempt()'s
            // incidental title-match (which would likely catch this too,
            // since both share the literal same title, but doing it
            // explicitly here is the actual intent, not a side effect).
            $kind = ((string) ($_POST['kind'] ?? 'video')) === 'audio' ? 'audio' : 'video';
            $text = trim((string) ($_POST['text'] ?? ''));
            $hasText = $text !== '';
            $hasFile = count($files) === 1;
            $hasUrl = $mediaUrl !== '';
            if (count($files) > 1) {
                json_response(['error' => 'Provide at most one file.'], 400);
            }
            if ($hasFile && $hasUrl) {
                json_response(['error' => 'Provide exactly one: a file, or a URL to download from, not both.'], 400);
            }
            $hasMedia = $hasFile || $hasUrl;
            if (!$hasMedia && !$hasText) {
                json_response(['error' => 'Provide a recording (file or URL) and/or a transcript.'], 400);
            }

            $mediaItem = null;
            $transcriptItem = null;
            if ($hasMedia) {
                $mediaItem = $kind === 'audio'
                    ? content_create_audio($title, $description, $hasFile ? $files[0] : null, $user['id'], $tags, $hasUrl ? $mediaUrl : null)
                    : content_create_video($title, $description, $hasFile ? $files[0] : null, $user['id'], $tags, $hasUrl ? $mediaUrl : null);
            }
            if ($hasText) {
                $transcriptItem = content_create_transcript($title, $text, $user['id'], $tags);
            }
            $item = ($mediaItem && $transcriptItem)
                ? content_link_items($mediaItem['id'], $transcriptItem['id'], $user['id'])
                : ($mediaItem ?? $transcriptItem);
        } elseif ($type === 'transcript') {
            $item = content_create_transcript($title, (string) ($_POST['text'] ?? ''), $user['id'], $tags);
        } elseif ($type === 'video') {
            if (count($files) === 1 && $mediaUrl === '') {
                $item = content_create_video($title, $description, $files[0], $user['id'], $tags);
            } elseif (count($files) === 0 && $mediaUrl !== '') {
                $item = content_create_video($title, $description, null, $user['id'], $tags, $mediaUrl);
            } else {
                json_response(['error' => 'Provide exactly one: a video file or a URL to download from.'], 400);
            }
        } elseif ($type === 'photo') {
            if ($files && $mediaUrl === '') {
                $item = content_create_photo_album($title, $description, $files, $user['id'], $tags);
            } elseif (!$files && $mediaUrl !== '') {
                $item = content_create_photo_album($title, $description, [], $user['id'], $tags, $mediaUrl);
            } else {
                json_response(['error' => 'Provide either photo file(s) or a URL to download from, not both.'], 400);
            }
        } elseif ($type === 'document') {
            if (count($files) > 1) {
                json_response(['error' => 'Attach at most one file.'], 400);
            }
            $item = content_create_document($title, (string) ($_POST['text'] ?? ''), $files[0] ?? null, $user['id'], $tags);
        } elseif ($type === 'url') {
            $item = content_create_url($title, (string) ($_POST['url'] ?? ''), $user['id'], $tags);
        } elseif ($type === 'story') {
            $item = content_create_story($title, (string) ($_POST['text'] ?? ''), $user['id'], $tags);
            // An admin submitting their own story doesn't need an email
            // nudge — they can just approve it themselves right away.
            // Only an author's submission actually needs the admin's
            // attention. Same fail-quiet posture as the signup
            // notification this mirrors: a mail hiccup never blocks the
            // actual action, which already succeeded above.
            if ($user['role'] !== 'admin') {
                content_notify_story_pending($item, $user);
            }
        } else {
            json_response(['error' => 'Unknown content type.'], 400);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['item' => content_public($item)], 201);
}

json_response(['error' => 'Method not allowed'], 405);
