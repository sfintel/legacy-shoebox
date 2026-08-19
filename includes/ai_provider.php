<?php
declare(strict_types=1);

// Thin provider-adapter layer so api/chat.php and includes/narrative.php
// can ask a generic AI question without caring whether the configured
// backend is Anthropic's Messages API or an OpenAI-shaped Chat
// Completions API. The OpenAI adapter's endpoint is configurable
// (AI_BASE_URL), so it also covers Groq, DeepSeek, OpenRouter, Azure
// OpenAI, and local runners like Ollama/LM Studio for free — they all
// speak the same wire format. Every function here fails quiet (returns
// null on any problem), matching narrative.php's existing "missing key
// / network error just means no AI feature runs" posture.

function ai_provider(): string
{
    return strtolower(trim((string) env('AI_PROVIDER', 'anthropic'))) === 'openai' ? 'openai' : 'anthropic';
}

function ai_api_key(): ?string
{
    $key = env('AI_API_KEY');
    return $key !== null && $key !== '' ? $key : null;
}

function ai_model(): string
{
    $model = (string) env('AI_MODEL', '');
    if ($model !== '') {
        return $model;
    }
    return ai_provider() === 'openai' ? 'gpt-4o' : 'claude-sonnet-5';
}

function ai_base_url(): string
{
    $url = trim((string) env('AI_BASE_URL', ''));
    return $url !== '' ? rtrim($url, '/') : 'https://api.openai.com/v1';
}

// Sampling temperature for every AI call in this app — recall/citation
// tasks (Ask tab, narrative analysis, suggestion extraction), not
// creative composition, so a low default sharply cuts paraphrase drift
// and quote invention. Returns null (= let the provider use its own
// default) when AI_TEMPERATURE is explicitly set to the literal string
// "default" — an escape hatch for OpenAI-shaped backends and reasoning
// models that 400 on any temperature other than their fixed one.
function ai_temperature(): ?float
{
    $raw = trim((string) env('AI_TEMPERATURE', '0.2'));
    if (strtolower($raw) === 'default') {
        return null;
    }
    return is_numeric($raw) ? (float) $raw : 0.2;
}

function ai_configured(): bool
{
    return ai_api_key() !== null;
}

// Generic image content block — narrative_build_image_block() emits
// this shape; ai_chat() translates it into whichever provider's own
// image-block format is needed.
function ai_image_block(string $mediaType, string $base64Data): array
{
    return ['type' => 'image', 'media_type' => $mediaType, 'data' => $base64Data];
}

// $systemParts: list of system-prompt strings, kept separate (rather
// than pre-joined) so the Anthropic adapter can mark each as its own
// ephemeral prompt-cache block, same caching behavior the app has always
// used. $messages: list of ['role' => 'user'|'assistant', 'content' =>
// string | array of ['type' => 'text', 'text' => ...] / ai_image_block()
// blocks]. $temperature: null means "use ai_temperature()"; every call
// site in this app wants the same low, recall-and-cite value, so this
// only exists for a future caller that needs to override it. Returns
// ['text' => string, 'usage' => array|null, 'truncated' => bool] —
// 'truncated' is true when the reply was cut off by hitting $maxTokens
// rather than the model finishing on its own; callers that show text
// directly to a reader (api/chat.php) should surface that rather than
// let a cut-off-mid-word reply pass as complete. Returns null on any
// failure (missing key, network error, timeout, non-2xx, unparsable
// response) — callers never throw.
function ai_chat(array $systemParts, array $messages, int $maxTokens, ?float $temperature = null): ?array
{
    $apiKey = ai_api_key();
    if (!$apiKey) {
        return null;
    }
    $temperature = $temperature ?? ai_temperature();
    return ai_provider() === 'openai'
        ? ai_chat_openai($apiKey, $systemParts, $messages, $maxTokens, $temperature)
        : ai_chat_anthropic($apiKey, $systemParts, $messages, $maxTokens, $temperature);
}

function ai_chat_anthropic(string $apiKey, array $systemParts, array $messages, int $maxTokens, ?float $temperature): ?array
{
    $system = [];
    foreach ($systemParts as $part) {
        if ($part !== '') {
            $system[] = ['type' => 'text', 'text' => $part, 'cache_control' => ['type' => 'ephemeral']];
        }
    }

    $payload = [
        'model' => ai_model(),
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => array_map(static fn (array $m): array => [
            'role' => $m['role'],
            'content' => ai_anthropic_content($m['content']),
        ], $messages),
    ];
    if ($temperature !== null) {
        $payload['temperature'] = $temperature;
    }

    $response = ai_http_post(
        'https://api.anthropic.com/v1/messages',
        ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
        $payload
    );
    if ($response === null) {
        return null;
    }

    $text = '';
    foreach ($response['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= ($text === '' ? '' : "\n") . $block['text'];
        }
    }
    return $text !== '' ? [
        'text' => trim($text),
        'usage' => $response['usage'] ?? null,
        'truncated' => ($response['stop_reason'] ?? null) === 'max_tokens',
    ] : null;
}

function ai_anthropic_content($content): array
{
    if (is_string($content)) {
        return [['type' => 'text', 'text' => $content]];
    }
    $blocks = [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'image') {
            $blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $block['media_type'], 'data' => $block['data']]];
        } else {
            $blocks[] = ['type' => 'text', 'text' => $block['text'] ?? ''];
        }
    }
    return $blocks;
}

function ai_chat_openai(string $apiKey, array $systemParts, array $messages, int $maxTokens, ?float $temperature): ?array
{
    $systemText = trim(implode("\n\n", array_filter($systemParts, static fn (string $p): bool => $p !== '')));
    $chatMessages = [];
    if ($systemText !== '') {
        $chatMessages[] = ['role' => 'system', 'content' => $systemText];
    }
    foreach ($messages as $m) {
        $chatMessages[] = ['role' => $m['role'], 'content' => ai_openai_content($m['content'])];
    }

    $payload = [
        'model' => ai_model(),
        'max_tokens' => $maxTokens,
        'messages' => $chatMessages,
    ];
    if ($temperature !== null) {
        $payload['temperature'] = $temperature;
    }

    $response = ai_http_post(
        ai_base_url() . '/chat/completions',
        ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        $payload
    );
    if ($response === null) {
        return null;
    }

    $text = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
    return $text !== '' ? [
        'text' => $text,
        'usage' => $response['usage'] ?? null,
        'truncated' => ($response['choices'][0]['finish_reason'] ?? null) === 'length',
    ] : null;
}

function ai_openai_content($content)
{
    if (is_string($content)) {
        return $content;
    }
    $blocks = [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'image') {
            $blocks[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $block['media_type'] . ';base64,' . $block['data']]];
        } else {
            $blocks[] = ['type' => 'text', 'text' => $block['text'] ?? ''];
        }
    }
    return $blocks;
}

// Shared cURL POST — returns the decoded JSON response body, or null on
// any transport/HTTP/parse failure (always error_log()'d so it's
// diagnosable server-side without surfacing provider details to the
// client).
function ai_http_post(string $url, array $headers, array $payload): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // No curl_close() — a no-op since PHP 8.0 (curl handles are regular
    // garbage-collected objects now) and deprecated as of PHP 8.5.

    if ($body === false || $httpCode >= 400) {
        error_log("ai_http_post: AI provider error (HTTP $httpCode) for $url: " . ($curlError ?: $body));
        return null;
    }
    $data = json_decode((string) $body, true);
    return is_array($data) ? $data : null;
}
