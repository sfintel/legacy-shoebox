<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

if (!check_rate_limit('forgot_password:' . client_ip(), FORGOT_PASSWORD_RATE_LIMIT)) {
    json_response(['error' => 'Too many requests. Try again later.'], 429);
}

$body = read_json_body();
$email = trim((string) ($body['email'] ?? ''));

// Always the same response whether or not the address has an account —
// otherwise this endpoint becomes a way to test which emails are
// registered. The email itself (sent only when an account exists) is
// where the real "nothing happened" signal lives.
$genericResponse = ['ok' => true, 'message' => "If that email has an account, we've sent a reset link."];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response($genericResponse);
}

$user = user_find_by_email($email);
if (!$user) {
    json_response($genericResponse);
}

$resetToken = create_action_token($user['id'], 'reset_password', env('SESSION_SECRET'), 3600);
$resetUrl = APP_URL . '/reset_password.php?token=' . urlencode($resetToken);

try {
    send_mail(
        $user['email'],
        mail_subject('Reset your password'),
        "Hi {$user['name']},\n\nSomeone (hopefully you) asked to reset the password for your "
            . site_name() . " account.\n\nReset it here: $resetUrl\n\nThis link expires in 1 hour. "
            . "If you didn't request this, you can ignore this email — your password won't change.",
        '<p>Hi ' . h($user['name']) . ',</p><p>Someone (hopefully you) asked to reset the password for your '
            . h(site_name()) . ' account.</p><p><a href="' . h($resetUrl) . '">Reset your password</a></p>'
            . '<p style="color:#888;font-size:.85em">This link expires in 1 hour. If you didn\'t request this, '
            . 'you can ignore this email — your password won\'t change.</p>'
    );
} catch (Throwable $e) {
    error_log('Failed to send password reset email: ' . $e->getMessage());
}

json_response($genericResponse);
