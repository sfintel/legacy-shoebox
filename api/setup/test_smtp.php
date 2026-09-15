<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// Reachable only once the wizard's admin stage has run — admin.php
// calls auth_login($admin) immediately after creating the account, so
// by the time the Advanced/SMTP stage renders, require_admin_api()'s
// normal session check already covers this correctly with no
// setup-specific gating needed.
require_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$host = trim((string) ($body['smtp_host'] ?? ''));
if ($host === '') {
    json_response(['error' => 'Enter an SMTP host first.'], 400);
}
$port = (int) ($body['smtp_port'] ?? 587);
$secure = (string) ($body['smtp_secure'] ?? 'false') === 'true';
$user = trim((string) ($body['smtp_user'] ?? ''));
$pass = (string) ($body['smtp_pass'] ?? '');
$from = trim((string) ($body['mail_from'] ?? ''));
if ($from === '') {
    $from = $user !== '' ? $user : 'no-reply@localhost';
}

// Always the currently logged-in admin's own address — this is a
// self-test ("does my typed-in config actually work"), not a general
// mailer, so there's no reason to let the request pick an arbitrary
// recipient.
$to = current_user()['email'];

try {
    smtp_send_with_config([
        'host' => $host,
        'port' => $port,
        'secure' => $secure,
        'user' => $user !== '' ? $user : null,
        'pass' => $pass,
        'from' => $from,
    ], $to, mail_subject('SMTP test'),
        "This is a test email from your archive's setup wizard.\n\nIf you're reading this, your SMTP settings work.",
        "<p>This is a test email from your archive's setup wizard.</p><p>If you're reading this, your SMTP settings work.</p>");
} catch (SmtpException $e) {
    json_response(['error' => $e->getMessage()], 400);
}

json_response(['ok' => true, 'sentTo' => $to]);
