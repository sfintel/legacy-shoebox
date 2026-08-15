<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_passkey_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$credentials = array_map('webauthn_credential_public', webauthn_credentials_for_user($user['id']));
json_response(['credentials' => $credentials]);
