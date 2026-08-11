<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

$body = read_json_body();
$token = $body['token'] ?? '';
$payload = token_verify($token, env('SESSION_SECRET'));

if (!$payload || !in_array($payload['action'] ?? '', ['approve', 'reject'], true)) {
    json_response(['error' => 'Link expired or invalid.'], 400);
}

$user = user_find_by_id($payload['uid']);
if (!$user) {
    json_response(['error' => 'Request not found.'], 404);
}

if (!user_consume_token_nonce($user['id'], $payload['nonce'])) {
    json_response(['error' => "Already {$user['status']}."], 409);
}

$status = $payload['action'] === 'approve' ? 'approved' : 'rejected';
user_set_status($user['id'], $status);

try {
    if ($status === 'approved') {
        send_mail(
            $user['email'],
            mail_subject("You're approved"),
            "Hi {$user['name']},\n\nYour access has been approved. You can log in now at " . APP_URL
                . "/login.php with the email and password you chose when you signed up.",
            '<p>Hi ' . h($user['name']) . ',</p><p>Your access has been approved. You can log in now at '
                . '<a href="' . h(APP_URL . '/login.php') . '">' . h(APP_URL . '/login.php') . '</a> '
                . 'with the email and password you chose when you signed up.</p>'
        );
    } else {
        send_mail(
            $user['email'],
            mail_subject('Regarding your access request'),
            "Hi {$user['name']},\n\nYour access request wasn't approved. If you think this is a mistake, reply to this email.",
            '<p>Hi ' . h($user['name']) . ',</p><p>Your access request wasn\'t approved. If you think this is a mistake, reply to this email.</p>'
        );
    }
} catch (Throwable $e) {
    error_log('Failed to send follow-up email: ' . $e->getMessage());
}

json_response([
    'ok' => true,
    'message' => $status === 'approved' ? "Approved — they've been emailed." : "Rejected — they've been notified.",
]);
