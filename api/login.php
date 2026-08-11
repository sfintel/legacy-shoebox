<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$body = read_json_body();
$email = $body['email'] ?? '';
$password = $body['password'] ?? '';

// Tracked independently by email (catches one account being targeted
// from anywhere) and by IP (catches one source spraying attempts across
// many accounts) — locked out if either hits the limit. The email key
// is set regardless of whether an account exists for it, so the
// lockout itself never reveals which emails are registered. Only
// failed attempts count — a successful login resets both.
$emailKey = login_email_key((string) $email);
$ipKey = 'login:ip:' . client_ip();
if (rate_limit_exceeded($emailKey, LOGIN_ATTEMPT_LIMIT, LOGIN_LOCKOUT_SECONDS)
    || rate_limit_exceeded($ipKey, LOGIN_ATTEMPT_LIMIT, LOGIN_LOCKOUT_SECONDS)) {
    json_response(['ok' => false, 'error' => 'Too many failed login attempts. Try again later.'], 429);
}

$user = $email ? user_find_by_email($email) : null;
if (!$user || $user['status'] !== 'approved' || !$password || !user_verify_password($password, $user['password_hash'])) {
    rate_limit_hit($emailKey, LOGIN_LOCKOUT_SECONDS);
    rate_limit_hit($ipKey, LOGIN_LOCKOUT_SECONDS);
    json_response(['ok' => false, 'error' => 'Incorrect email or password.'], 401);
}

rate_limit_reset($emailKey);
rate_limit_reset($ipKey);
auth_login($user);
json_response(['ok' => true]);
