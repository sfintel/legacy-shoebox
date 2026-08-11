<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

require_auth_api();
auth_start_session();

if (!ai_configured()) {
    json_response(['error' => 'No AI provider is configured on the server (set AI_API_KEY).'], 503);
}

if (!check_rate_limit('chat:' . session_id(), CHAT_RATE_LIMIT)) {
    json_response(['error' => 'Rate limit reached. Try again in a bit.'], 429);
}

$body = read_json_body();
$message = $body['message'] ?? null;
if (!is_string($message) || $message === '') {
    json_response(['error' => 'message is required'], 400);
}

$mode = in_array($body['audienceMode'] ?? '', audience_mode_values(), true) ? $body['audienceMode'] : audience_mode_default();

$history = [];
if (is_array($body['history'] ?? null)) {
    foreach ($body['history'] as $m) {
        if (is_array($m) && in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_string($m['content'] ?? null)) {
            $history[] = ['role' => $m['role'], 'content' => $m['content']];
        }
    }
    $history = array_slice($history, -20);
}
$history[] = ['role' => 'user', 'content' => "[Audience mode: $mode]\n\n$message"];

try {
    $charter = knowledge_system_role();
    $context = knowledge_context();
} catch (Throwable $e) {
    error_log('Knowledge base load failed: ' . $e->getMessage());
    json_response(['error' => 'The knowledge base could not be loaded on the server.'], 500);
}

$result = ai_chat([$charter, $context], $history, 1200);
if ($result === null) {
    json_response(['error' => 'The AI backend failed to respond. Please try again.'], 502);
}

// Defense in depth — knowledge_context() already redacted what the
// model was given, but the client-supplied `history` above isn't run
// back through that, so redact the outgoing reply too.
$text = redact_text($result['text'], redacted_names());

json_response(['reply' => $text, 'usage' => $result['usage']]);
