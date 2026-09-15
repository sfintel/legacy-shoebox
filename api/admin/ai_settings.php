<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

require_admin_api();
$subjectId = require_current_subject()['id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['settings' => ai_settings_admin_shape()]);
}

if ($method === 'POST') {
    $body = read_json_body();
    $provider = (string) ($body['ai_provider'] ?? '') === 'openai' ? 'openai' : 'anthropic';
    $apiKey = trim((string) ($body['ai_api_key'] ?? ''));
    $baseUrl = trim((string) ($body['ai_base_url'] ?? ''));
    $model = trim((string) ($body['ai_model'] ?? ''));
    $temperature = trim((string) ($body['ai_temperature'] ?? ''));

    // Blank api key means "clear this subject's override, inherit the
    // install default again" — every override column resets to NULL
    // together so a cleared key can't leave a stale provider/model
    // silently pinned with no key to use it.
    if ($apiKey === '') {
        db()->prepare('UPDATE subjects SET ai_provider = NULL, ai_api_key = NULL, ai_base_url = NULL, ai_model = NULL, ai_temperature = NULL WHERE id = ?')
            ->execute([$subjectId]);
    } else {
        db()->prepare('UPDATE subjects SET ai_provider = ?, ai_api_key = ?, ai_base_url = ?, ai_model = ?, ai_temperature = ? WHERE id = ?')
            ->execute([
                $provider,
                $apiKey,
                $provider === 'openai' && $baseUrl !== '' ? $baseUrl : null,
                $model !== '' ? $model : null,
                $temperature !== '' ? $temperature : null,
                $subjectId,
            ]);
    }

    // ai_settings_admin_shape() reads current_subject(), which is
    // request-cached — refresh it from the row we just wrote, or the
    // response would echo back the pre-save values.
    $refreshed = subject_find_by_id($subjectId);
    if ($refreshed !== null) {
        set_current_subject($refreshed);
    }

    json_response(['settings' => ai_settings_admin_shape()]);
}

json_response(['error' => 'Method not allowed'], 405);

function ai_settings_admin_shape(): array
{
    $subject = current_subject();
    return [
        'hasOverride' => ($subject['ai_api_key'] ?? null) !== null && $subject['ai_api_key'] !== '',
        'aiProvider' => $subject['ai_provider'] ?? '',
        'aiApiKey' => $subject['ai_api_key'] ?? '',
        'aiBaseUrl' => $subject['ai_base_url'] ?? '',
        'aiModel' => $subject['ai_model'] ?? '',
        'aiTemperature' => $subject['ai_temperature'] ?? '',
        'effectiveProvider' => ai_provider(),
        'effectiveModel' => ai_model(),
        'installConfigured' => env('AI_API_KEY') !== null && env('AI_API_KEY') !== '',
    ];
}
