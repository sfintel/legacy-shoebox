<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$master = require_master_admin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');

// Ownership-checked inside webauthn_credential_delete_for_master_admin()
// itself (WHERE id = ? AND master_admin_id = ?).
if (!webauthn_credential_delete_for_master_admin($id, $master['id'])) {
    json_response(['error' => 'Passkey not found.'], 404);
}

json_response(['ok' => true]);
