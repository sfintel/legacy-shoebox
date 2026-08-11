<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!setup_identity_ready()) {
    json_response(['error' => 'Site identity is not set up yet.'], 400);
}
if (setup_admin_ready()) {
    json_response(['error' => 'An admin account already exists.'], 400);
}

$body = read_json_body();
$name = (string) ($body['name'] ?? '');
$email = (string) ($body['email'] ?? '');
$password = (string) ($body['password'] ?? '');
$confirmPassword = (string) ($body['confirm_password'] ?? '');

if ($password !== $confirmPassword) {
    json_response(['error' => "Passwords don't match."], 400);
}

try {
    $admin = setup_create_admin($name, $email, $password);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 400);
}

$provider = (string) ($body['ai_provider'] ?? '') === 'openai' ? 'openai' : 'anthropic';
$apiKey = trim((string) ($body['ai_api_key'] ?? ''));
$baseUrl = trim((string) ($body['ai_base_url'] ?? ''));
$model = trim((string) ($body['ai_model'] ?? ''));
$archiveRoot = trim((string) ($body['archive_root'] ?? ''));
$envFields = [
    'AI_PROVIDER' => $provider,
    'AI_API_KEY' => $apiKey,
    'AI_BASE_URL' => $provider === 'openai' ? $baseUrl : '',
    'AI_MODEL' => $model,
];
if ($archiveRoot !== '') {
    $envFields['ARCHIVE_ROOT'] = $archiveRoot;
}
setup_write_env($envFields);

// Log the new admin in immediately — the "advanced" stage that follows
// is optional/skippable, and it's a better experience to already be
// signed in once setup finishes rather than needing to log in again.
auth_login($admin);

json_response(['ok' => true]);
