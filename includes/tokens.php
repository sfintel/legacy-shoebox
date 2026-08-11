<?php
declare(strict_types=1);

// Signed, expiring, single-use tokens for the email approve/reject links.
// Mirrors the Node version's lib/tokens.js — plain HMAC over a small JSON
// payload, base64url-encoded. Not a JWT library on purpose; this only
// ever needs to carry {uid, action, nonce, exp}.

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string|false
{
    $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
    return base64_decode(strtr($padded, '-_', '+/'), true);
}

function token_sign(array $payload, string $secret): string
{
    $body = b64url_encode(json_encode($payload));
    $sig = b64url_encode(hash_hmac('sha256', $body, $secret, true));
    return "$body.$sig";
}

function token_verify(?string $token, string $secret): ?array
{
    if (!$token || !str_contains($token, '.')) {
        return null;
    }
    [$body, $sig] = explode('.', $token, 2);
    $expected = b64url_encode(hash_hmac('sha256', $body, $secret, true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $json = b64url_decode($body);
    if ($json === false) {
        return null;
    }
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['exp']) && (time() * 1000) > $payload['exp']) {
        return null; // expired ('exp' is stored in epoch milliseconds, matching the Node version)
    }
    return $payload;
}

// Action tokens live for 7 days.
function create_action_token(string $userId, string $action, string $secret, int $ttlSeconds = 7 * 24 * 60 * 60): string
{
    return token_sign([
        'uid' => $userId,
        'action' => $action,
        'nonce' => bin2hex(random_bytes(8)),
        'exp' => (time() + $ttlSeconds) * 1000,
    ], $secret);
}
