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
$passwordOk = $user && $password && user_verify_password($password, $user['password_hash']);

// Checked ahead of the generic failure below so a correct password can
// surface this specific reason — someone who doesn't already know the
// password still just gets "Incorrect email or password", so this can't
// be used to enumerate which emails have an account. Doesn't count
// against the lockout either: a pending account isn't a guessing
// attempt, and locking someone out before they've even been approved
// (as happened here) served no one.
if ($passwordOk && $user['status'] !== 'approved') {
    rate_limit_reset($emailKey);
    rate_limit_reset($ipKey);
    json_response(['ok' => false, 'error' => "Your account is still awaiting approval. You'll get an email once it's approved."], 403);
}

if (!$passwordOk) {
    rate_limit_hit($emailKey, LOGIN_LOCKOUT_SECONDS);
    rate_limit_hit($ipKey, LOGIN_LOCKOUT_SECONDS);
    json_response(['ok' => false, 'error' => 'Incorrect email or password.'], 401);
}

rate_limit_reset($emailKey);
rate_limit_reset($ipKey);
auth_login($user);
json_response(['ok' => true]);
