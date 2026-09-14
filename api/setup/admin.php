<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$subjectId = require_current_subject()['id'];
if (!setup_identity_ready($subjectId)) {
    json_response(['error' => 'Site identity is not set up yet.'], 400);
}
if (setup_admin_ready($subjectId)) {
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

// The install's very first subject seeds the shared .env AI_* defaults
// (exactly one subject exists at this point in the flow means this is
// it); every subsequent subject gets its own override on its subjects
// row instead, editable later from admin_settings.php (see
// includes/ai_provider.php) — never touching the install-wide .env.
$isFirstSubjectEver = (int) db()->query('SELECT COUNT(*) FROM subjects')->fetchColumn() === 1;
if ($isFirstSubjectEver) {
    setup_write_env([
        'AI_PROVIDER' => $provider,
        'AI_API_KEY' => $apiKey,
        'AI_BASE_URL' => $provider === 'openai' ? $baseUrl : '',
        'AI_MODEL' => $model,
    ]);
} elseif ($apiKey !== '') {
    db()->prepare('UPDATE subjects SET ai_provider = ?, ai_api_key = ?, ai_base_url = ?, ai_model = ? WHERE id = ?')
        ->execute([$provider, $apiKey, $provider === 'openai' ? $baseUrl : null, $model !== '' ? $model : null, $subjectId]);
}

// Log the new admin in immediately — the "advanced" stage that follows
// is optional/skippable, and it's a better experience to already be
// signed in once setup finishes rather than needing to log in again.
auth_login($admin);

json_response(['ok' => true]);
