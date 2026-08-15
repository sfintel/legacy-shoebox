<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_passkey_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$clientDataJSON = (string) ($body['clientDataJSON'] ?? '');
$attestationObject = (string) ($body['attestationObject'] ?? '');
$label = (string) ($body['label'] ?? '');

if ($clientDataJSON === '' || $attestationObject === '') {
    json_response(['error' => 'Missing registration data.'], 400);
}

try {
    $credential = webauthn_registration_verify(
        $user['id'],
        webauthn_b64url_decode($clientDataJSON),
        webauthn_b64url_decode($attestationObject),
        $label
    );
} catch (Throwable $e) {
    // Catches both lbuchs\WebAuthn\WebAuthnException (a bad/forged/
    // expired ceremony) and RuntimeException (no pending registration
    // in this session) — same "surface the message directly" pattern
    // this app already uses for RuntimeException elsewhere (e.g.
    // content.php); WebAuthnException's messages are plain protocol-
    // level descriptions ("invalid challenge", "invalid origin"), not
    // anything sensitive.
    json_response(['error' => $e->getMessage()], 400);
}

json_response(['credential' => $credential], 201);
