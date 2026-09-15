<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$master = require_master_admin_api();

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
    $credential = webauthn_registration_verify_for_master_admin(
        $master['id'],
        webauthn_b64url_decode($clientDataJSON),
        webauthn_b64url_decode($attestationObject),
        $label
    );
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 400);
}

json_response(['credential' => $credential], 201);
