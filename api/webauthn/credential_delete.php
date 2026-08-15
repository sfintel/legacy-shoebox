<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

$user = require_passkey_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$id = (string) ($body['id'] ?? '');

// Ownership-checked inside webauthn_credential_delete() itself (WHERE
// id = ? AND user_id = ?) — a user can only ever delete their own
// passkey, admin or not.
if (!webauthn_credential_delete($id, $user['id'])) {
    json_response(['error' => 'Passkey not found.'], 404);
}

json_response(['ok' => true]);
