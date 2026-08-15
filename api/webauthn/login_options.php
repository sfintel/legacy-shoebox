<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// Deliberately unauthenticated — this IS the login flow. A single
// generic error covers "no such account", "not admin/author", and "no
// passkey registered" so the response can't be used to enumerate which
// emails exist, matching api/login.php's own "Incorrect email or
// password" convention for the same reason.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$genericError = 'No passkey is available for that account.';

$body = read_json_body();
$email = trim((string) ($body['email'] ?? ''));

$user = $email !== '' ? user_find_by_email($email) : null;
if (!$user || !user_can_use_passkey($user) || !webauthn_credentials_for_user($user['id'])) {
    json_response(['error' => $genericError], 400);
}

try {
    $args = webauthn_login_options($user);
} catch (Throwable $e) {
    error_log('webauthn_login_options failed: ' . $e->getMessage());
    json_response(['error' => $genericError], 500);
}

json_response($args);
