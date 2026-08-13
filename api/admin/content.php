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

    try {
        if ($type === 'transcript') {
            $item = content_create_transcript($title, (string) ($_POST['text'] ?? ''), $user['id'], $tags);
        } elseif ($type === 'video') {
            if (count($files) !== 1) {
                json_response(['error' => 'A video upload needs exactly one file.'], 400);
            }
            $item = content_create_video($title, $description, $files[0], $user['id'], $tags);
        } elseif ($type === 'photo') {
            $item = content_create_photo_album($title, $description, $files, $user['id'], $tags);
        } elseif ($type === 'url') {
            $item = content_create_url($title, (string) ($_POST['url'] ?? ''), $user['id'], $tags);
        } elseif ($type === 'story') {
            $item = content_create_story($title, (string) ($_POST['text'] ?? ''), $user['id'], $tags);
        } else {
            json_response(['error' => 'Unknown content type.'], 400);
        }
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 400);
    }

    json_response(['item' => content_public($item)], 201);
}

json_response(['error' => 'Method not allowed'], 405);
