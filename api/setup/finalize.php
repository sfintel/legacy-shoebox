<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!setup_admin_ready()) {
    json_response(['error' => 'The admin account is not set up yet.'], 400);
}
if (setup_is_complete()) {
    json_response(['error' => 'Setup has already been completed.'], 400);
}

$body = read_json_body();
$fields = [];
foreach (['APP_URL', 'NOTIFY_EMAIL', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURE', 'SMTP_USER', 'SMTP_PASS', 'MAIL_FROM',
    'LOGIN_ATTEMPT_LIMIT', 'LOGIN_LOCKOUT_SECONDS', 'CHAT_RATE_LIMIT', 'SIGNUP_RATE_LIMIT'] as $key) {
    if (isset($body[$key]) && trim((string) $body[$key]) !== '') {
        $fields[$key] = trim((string) $body[$key]);
    }
}
// APP_URL defaults to whatever the browser actually used to reach this
// page, if left blank — a reasonable guess most of the time, and
// editable later by hand in .env if it's wrong (e.g. behind a proxy).
if (!isset($fields['APP_URL'])) {
    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fields['APP_URL'] = "$scheme://$host";
}

setup_write_env($fields);
setup_finalize();

json_response(['ok' => true, 'redirect' => '/login.php']);
