<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$body = read_json_body();
$token = $body['token'] ?? '';
$payload = token_verify($token, env('SESSION_SECRET'));

if (!$payload || ($payload['action'] ?? '') !== 'reset_password') {
    json_response(['error' => 'This link has expired or is invalid.'], 400);
}

$user = user_find_by_id($payload['uid']);
if (!$user) {
    json_response(['error' => 'Account not found.'], 404);
}

$newPassword = (string) ($body['newPassword'] ?? '');
$confirmPassword = (string) ($body['confirmPassword'] ?? '');

// Validated before the nonce is consumed below — otherwise a bad
// password (too short, mismatched) would burn the one-time link on a
// failed attempt, leaving the person stuck re-requesting a new email
// just to fix a typo.
if (strlen($newPassword) < 8) {
    json_response(['error' => 'New password must be at least 8 characters.'], 400);
}
if ($newPassword !== $confirmPassword) {
    json_response(['error' => "New passwords don't match."], 400);
}

if (!user_consume_token_nonce($user['id'], $payload['nonce'])) {
    json_response(['error' => 'This link has already been used.'], 409);
}

user_set_password($user['id'], $newPassword);
rate_limit_reset(login_email_key($user['email']));

try {
    send_mail(
        $user['email'],
        mail_subject('Your password was changed'),
        "Hi {$user['name']},\n\nYour " . site_name() . " password was just reset. If this wasn't you, "
            . 'contact whoever administers your family archive right away.',
        '<p>Hi ' . h($user['name']) . ',</p><p>Your ' . h(site_name()) . ' password was just reset. '
            . "If this wasn't you, contact whoever administers your family archive right away.</p>"
    );
} catch (Throwable $e) {
    error_log('Failed to send password-changed notice: ' . $e->getMessage());
}

json_response(['ok' => true]);
