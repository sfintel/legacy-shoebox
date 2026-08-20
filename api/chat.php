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

$result = ai_chat([$charter, $context], $history, 8192);
if ($result === null) {
    json_response(['error' => 'The AI backend failed to respond. Please try again.'], 502);
}

// Defense in depth — knowledge_context() already redacted what the
// model was given, but the client-supplied `history` above isn't run
// back through that, so redact the outgoing reply too.
$text = redact_text($result['text'], redacted_names());

// Deterministic post-check: does every direct quote the reply claims
// actually appear in what the model was given? Haystack is $charter +
// $context, not $context alone — the charter is where the model is told
// to use stock phrases verbatim (e.g. "the record doesn't say."), and
// those are real, non-fabricated quotes that only verify against it.
// $charter is intentionally NOT run through redact_text() here (it
// carries no archive prose, so there's nothing to redact) — verifying
// the redacted reply against an unredacted charter is still correct
// since redaction never touches the charter's own fixed phrases. Both
// $text and $context are already redacted, so a quote containing a
// redacted name matches on both sides instead of always failing.
$unverifiedQuotes = quote_check_unverified($text, $charter . "\n\n" . $context);

// Deterministic (never model-computed) resolution of any [[video:ID]]
// tokens in the reply to a "seek 5s before the quoted passage" time —
// see includes/video_seek.php. Additive only; never alters $text.
$videoSeeks = video_seek_resolve_for_reply($text);

if ($unverifiedQuotes) {
    // Post-redaction text only, so no redacted name can leak into the log.
    error_log(
        'chat.php: unverified quote span(s) (' . count($unverifiedQuotes) . '): '
        . implode(' | ', array_map(static fn (string $q): string => substr($q, 0, 200), $unverifiedQuotes))
    );
}

// The provider hit the max_tokens ceiling mid-generation — the reply is
// real (never fabricated) but cut off, possibly mid-word/mid-sentence.
// Surfaced to the client rather than silently shown as if complete.
if (!empty($result['truncated'])) {
    error_log('chat.php: reply truncated at max_tokens');
}

json_response([
    'reply' => $text,
    'usage' => $result['usage'],
    'truncated' => !empty($result['truncated']),
    'unverifiedQuotes' => array_map(
        static fn (string $q): string => mb_substr($q, 0, 120, 'UTF-8'),
        array_slice($unverifiedQuotes, 0, 3)
    ),
    'videoSeeks' => $videoSeeks,
]);
