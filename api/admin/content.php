<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_content_api();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Admins see everything; a permitted non-admin only sees their own.
    $items = content_all($user['role'] === 'admin' ? null : $user['id']);
    json_response(['items' => array_map('content_public', $items)]);
}

if ($method === 'POST') {
    $type = (string) ($_POST['type'] ?? '');
    $title = (string) ($_POST['title'] ?? '');
    $description = $_POST['description'] ?? null;
    $files = content_normalize_multi_files($_FILES['files'] ?? ['name' => []]);
    $tags = is_array($_POST['tags'] ?? null) ? $_POST['tags'] : [];
    $mediaUrl = trim((string) ($_POST['mediaUrl'] ?? ''));

    try {
        if ($type === 'transcript') {
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
