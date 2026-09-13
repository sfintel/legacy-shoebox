<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

if (!check_rate_limit('signup:' . client_ip(), SIGNUP_RATE_LIMIT)) {
    json_response(['error' => 'Too many requests. Try again later.'], 429);
}

$body = read_json_body();
$name = trim((string) ($body['name'] ?? ''));
$email = trim((string) ($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');
$confirmPassword = (string) ($body['confirmPassword'] ?? '');
$audienceMode = (string) ($body['audienceMode'] ?? '');
$reason = trim((string) ($body['reason'] ?? ''));
// Strip control/non-printable characters (keeping newlines/tabs) before
// this ever reaches storage or the admin notification email — it's
// free text from an unauthenticated visitor. HTML-escaping happens at
// render time instead (h() in the email's HTML part, esc() in
// admin.js), same as every other user-supplied field in this app.
$reason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $reason) ?? '';

if ($name === '') {
    json_response(['error' => 'Name is required.'], 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'A valid email is required.'], 400);
}
if (strlen($password) < 8) {
    json_response(['error' => 'Password must be at least 8 characters.'], 400);
}
if ($password !== $confirmPassword) {
    json_response(['error' => "Passwords don't match."], 400);
}
if (strlen($reason) > SIGNUP_REASON_MAX_LENGTH) {
    json_response(['error' => 'That reason is too long (max ' . SIGNUP_REASON_MAX_LENGTH . ' characters).'], 400);
}

try {
    $user = user_create_pending($name, $email, $password, $audienceMode, $reason);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 409);
}

$notifyEmail = env('NOTIFY_EMAIL') ?: user_first_admin_email();
if (!$notifyEmail) {
    error_log('NOTIFY_EMAIL not set and no admin account exists yet — skipping signup notification email.');
} else {
    $approveToken = create_action_token($user['id'], 'approve', env('SESSION_SECRET'));
    $rejectToken = create_action_token($user['id'], 'reject', env('SESSION_SECRET'));
    $approveUrl = APP_URL . '/admin_confirm.php?token=' . urlencode($approveToken);
    $rejectUrl = APP_URL . '/admin_confirm.php?token=' . urlencode($rejectToken);

    $reasonText = $user['signup_reason'] !== null
        ? "\nReason given: \"{$user['signup_reason']}\"\n"
        : '';
    $reasonHtml = $user['signup_reason'] !== null
        ? '<p><em>Reason given:</em> "' . nl2br(h($user['signup_reason'])) . '"</p>'
        : '';

    try {
        send_mail(
            $notifyEmail,
            mail_subject('Access request from ' . $user['name']),
            "{$user['name']} ({$user['email']}) has requested access to the " . site_name() . " app.\n"
                . $reasonText . "\n"
                . "Approve: $approveUrl\nReject: $rejectUrl\n\n"
                . "Both links expire in 7 days. You can also manage requests any time at " . APP_URL . "/admin.php",
            '<p><strong>' . h($user['name']) . '</strong> (' . h($user['email']) . ') has requested access '
                . 'to the ' . h(site_name()) . ' app.</p>'
                . $reasonHtml
                . '<p><a href="' . h($approveUrl) . '">Approve</a> &nbsp;|&nbsp; <a href="' . h($rejectUrl) . '">Reject</a></p>'
                . '<p style="color:#888;font-size:.85em">Both links expire in 7 days. You can also manage requests '
                . 'any time at <a href="' . h(APP_URL . '/admin.php') . '">' . h(APP_URL . '/admin.php') . '</a></p>'
        );
    } catch (Throwable $e) {
        // Don't fail the signup over an email hiccup — it's still recorded
        // and visible on the admin page.
        error_log('Failed to send admin notification email: ' . $e->getMessage());
    }
}

json_response(['ok' => true]);
