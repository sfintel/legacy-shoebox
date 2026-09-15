<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// Also unauthenticated, for the same reason as login_options.php.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$credentialId = (string) ($body['credentialId'] ?? '');
$clientDataJSON = (string) ($body['clientDataJSON'] ?? '');
$authenticatorData = (string) ($body['authenticatorData'] ?? '');
$signature = (string) ($body['signature'] ?? '');

if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
    json_response(['error' => 'Missing sign-in data.'], 400);
}

try {
    $result = webauthn_login_verify(
        webauthn_b64url_decode($credentialId),
        webauthn_b64url_decode($clientDataJSON),
        webauthn_b64url_decode($authenticatorData),
        webauthn_b64url_decode($signature)
    );
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}

if ($result['type'] === 'master_admin') {
    // No analogous role/status re-check here — master_admins carries no
    // such column (see sql/schema.sql); the row still existing (already
    // checked inside webauthn_login_verify()) is the only precondition.
    auth_login_master($result['master_admin']);
    json_response(['ok' => true]);
}

$user = $result['user'];
// Re-check eligibility at verify time too, not just at options time —
// role/status could have changed in between (e.g. an admin revoked
// this account moments ago).
if (!user_can_use_passkey($user)) {
    json_response(['error' => 'This account can no longer sign in with a passkey.'], 403);
}

auth_login($user);
json_response(['ok' => true]);
