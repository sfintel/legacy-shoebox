<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// Available to every logged-in user, not just admin/author — unlike
// passkeys, password login (and therefore changing it) is universal,
// including readers.
$user = require_auth_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

// Keyed by user id (already authenticated, so email enumeration isn't a
// concern here) — guards against a hijacked/shared session being used to
// brute-force the current password before it can set a new one.
$rateKey = 'change_password:' . $user['id'];
if (rate_limit_exceeded($rateKey, LOGIN_ATTEMPT_LIMIT, LOGIN_LOCKOUT_SECONDS)) {
    json_response(['error' => 'Too many attempts. Try again later.'], 429);
}

$body = read_json_body();
$currentPassword = (string) ($body['currentPassword'] ?? '');
$newPassword = (string) ($body['newPassword'] ?? '');
$confirmPassword = (string) ($body['confirmPassword'] ?? '');

if (!user_verify_password($currentPassword, $user['password_hash'])) {
    rate_limit_hit($rateKey, LOGIN_LOCKOUT_SECONDS);
    json_response(['error' => 'Current password is incorrect.'], 401);
}
if (strlen($newPassword) < 8) {
    json_response(['error' => 'New password must be at least 8 characters.'], 400);
}
if ($newPassword !== $confirmPassword) {
    json_response(['error' => "New passwords don't match."], 400);
}

rate_limit_reset($rateKey);
user_set_password($user['id'], $newPassword);
json_response(['ok' => true]);
