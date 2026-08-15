<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// admin/author only, matching the passkey scoping decision.
$user = require_passkey_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

try {
    $args = webauthn_registration_options($user);
} catch (Throwable $e) {
    error_log('webauthn_registration_options failed: ' . $e->getMessage());
    json_response(['error' => 'Could not start passkey registration.'], 500);
}

json_response($args);
