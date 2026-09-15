<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$master = require_master_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$credentials = array_map('webauthn_credential_public', webauthn_credentials_for_master_admin($master['id']));
json_response(['credentials' => $credentials]);
