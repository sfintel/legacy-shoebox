<?php
declare(strict_types=1);

// Thin app-specific wrapper around the vendored includes/webauthn/
// library (see its NOTICE.md) — translates between that library's
// stdClass/ByteBuffer shapes and this app's session/database
// conventions. Passkeys are additive to password login (never a
// replacement) and only ever offered to admin/author accounts — see
// account.php (registration UI) and login.php (sign-in UI).

require_once __DIR__ . '/webauthn/WebAuthn.php';

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\Binary\ByteBuffer;

// RP ID must be a bare domain (no scheme/port) — derived from APP_URL
// rather than duplicated in .env. $useBase64UrlEncoding=true makes the
// library's ByteBuffer values serialize as base64url strings when
// json_encode()'d, which is what the browser-side helpers in
// js/webauthn.js expect (and is the standard WebAuthn-over-JSON
// convention — raw binary has no sane JSON representation).
function webauthn_server(): WebAuthn
{
    static $server = null;
    if ($server !== null) {
        return $server;
    }
    $host = (string) parse_url(APP_URL, PHP_URL_HOST);
    $server = new WebAuthn(site_name(), $host, null, true);
    return $server;
}

// Reuses the vendored library's own base64url decoder (ByteBuffer)
// rather than hand-rolling padding/charset handling again — every
// binary field the browser sends back (clientDataJSON, attestationObject,
// authenticatorData, signature, credential id) arrives as a base64url
// string over JSON and needs this same decode step.
function webauthn_b64url_decode(string $s): string
{
    return ByteBuffer::fromBase64Url($s)->getBinaryString();
}

function webauthn_credentials_for_user(string $userId): array
{
    $stmt = db()->prepare('SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// A master admin's own passkey (see includes/master_admin.php) — distinct
// from any per-subject shadow user's passkeys, even though both can
// exist for the same person. Signing in with one of these calls
// auth_login_master() instead of auth_login(), granting real
// cross-subject master-admin session rights (see webauthn_login_verify()).
function webauthn_credentials_for_master_admin(string $masterAdminId): array
{
    $stmt = db()->prepare('SELECT * FROM webauthn_credentials WHERE master_admin_id = ? ORDER BY created_at ASC');
    $stmt->execute([$masterAdminId]);
    return $stmt->fetchAll();
}

function webauthn_credential_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM webauthn_credentials WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// $credentialIdBinary is the raw bytes, not base64url — see
// webauthn_login_verify()'s caller for the decode step.
function webauthn_credential_find_by_credential_id(string $credentialIdBinary): ?array
{
    $stmt = db()->prepare('SELECT * FROM webauthn_credentials WHERE credential_id = ?');
    $stmt->execute([$credentialIdBinary]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function webauthn_credential_public(array $c): array
{
    return [
        'id' => $c['id'],
        'label' => $c['label'] ?: 'Unnamed passkey',
        'createdAt' => $c['created_at'],
        'lastUsedAt' => $c['last_used_at'],
    ];
}

// Only ever deletes a credential the caller actually owns — mirrors
// content_delete()'s ownership-check pattern, since this is reachable
// from a self-service page (account.php), not an admin-only one.
function webauthn_credential_delete(string $id, string $ownerId): bool
{
    $stmt = db()->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $ownerId]);
    return $stmt->rowCount() > 0;
}

// Same ownership-checked delete, for a master admin's own passkey.
function webauthn_credential_delete_for_master_admin(string $id, string $masterAdminId): bool
{
    $stmt = db()->prepare('DELETE FROM webauthn_credentials WHERE id = ? AND master_admin_id = ?');
    $stmt->execute([$id, $masterAdminId]);
    return $stmt->rowCount() > 0;
}

// --- Registration (adding a new passkey to an already-logged-in account) ---

function webauthn_registration_options(array $user): object
{
    $server = webauthn_server();
    $excludeIds = array_map(
        static fn (array $c) => new ByteBuffer($c['credential_id']),
        webauthn_credentials_for_user($user['id'])
    );
    // 'preferred' user verification (biometric/PIN), not 'required' —
    // required would reject plain FIDO U2F security keys that only do
    // presence (a button press), not verification.
    $args = $server->getCreateArgs($user['id'], $user['email'], $user['name'], 60, false, 'preferred', null, $excludeIds);
    auth_start_session();
    $_SESSION['webauthn_challenge'] = base64_encode($server->getChallenge()->getBinaryString());
    return $args;
}

// $clientDataJSON/$attestationObject are raw binary (already decoded
// from the base64url the browser sent — see api/webauthn/register_verify.php).
function webauthn_registration_verify(string $userId, string $clientDataJSON, string $attestationObject, string $label): array
{
    auth_start_session();
    $challengeB64 = $_SESSION['webauthn_challenge'] ?? null;
    if ($challengeB64 === null) {
        throw new RuntimeException('No pending passkey registration for this session — try again.');
    }
    unset($_SESSION['webauthn_challenge']);

    $server = webauthn_server();
    $data = $server->processCreate($clientDataJSON, $attestationObject, base64_decode($challengeB64), 'preferred');

    $id = make_uuid();
    db()->prepare(
        'INSERT INTO webauthn_credentials (id, user_id, credential_id, public_key, sign_count, label)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        $userId,
        $data->credentialId,
        $data->credentialPublicKey,
        (int) ($data->signatureCounter ?? 0),
        trim($label) !== '' ? trim($label) : null,
    ]);

    return webauthn_credential_public(webauthn_credential_find($id));
}

// Same registration flow, for a master admin's own passkey rather than a
// per-subject user's — see api/master_admin/webauthn_register_options.php.
function webauthn_registration_options_for_master_admin(array $master): object
{
    $server = webauthn_server();
    $excludeIds = array_map(
        static fn (array $c) => new ByteBuffer($c['credential_id']),
        webauthn_credentials_for_master_admin($master['id'])
    );
    $args = $server->getCreateArgs($master['id'], $master['email'], $master['name'], 60, false, 'preferred', null, $excludeIds);
    auth_start_session();
    $_SESSION['webauthn_challenge'] = base64_encode($server->getChallenge()->getBinaryString());
    return $args;
}

// $clientDataJSON/$attestationObject are raw binary, same as
// webauthn_registration_verify() above.
function webauthn_registration_verify_for_master_admin(string $masterAdminId, string $clientDataJSON, string $attestationObject, string $label): array
{
    auth_start_session();
    $challengeB64 = $_SESSION['webauthn_challenge'] ?? null;
    if ($challengeB64 === null) {
        throw new RuntimeException('No pending passkey registration for this session — try again.');
    }
    unset($_SESSION['webauthn_challenge']);

    $server = webauthn_server();
    $data = $server->processCreate($clientDataJSON, $attestationObject, base64_decode($challengeB64), 'preferred');

    $id = make_uuid();
    db()->prepare(
        'INSERT INTO webauthn_credentials (id, master_admin_id, credential_id, public_key, sign_count, label)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        $masterAdminId,
        $data->credentialId,
        $data->credentialPublicKey,
        (int) ($data->signatureCounter ?? 0),
        trim($label) !== '' ? trim($label) : null,
    ]);

    return webauthn_credential_public(webauthn_credential_find($id));
}

// --- Authentication (signing in with an already-registered passkey) ---

// Only offered for an account that could actually use it (approved,
// admin/author — see user_can_use_passkey()) and that has at least one
// registered credential; api/webauthn/login_options.php enforces both
// before calling this, so this function assumes the caller already
// checked.
function webauthn_login_options(array $user): object
{
    $server = webauthn_server();
    $credentialIds = array_map(
        static fn (array $c) => new ByteBuffer($c['credential_id']),
        webauthn_credentials_for_user($user['id'])
    );
    $args = $server->getGetArgs($credentialIds, 60, true, true, true, true, true, 'preferred');
    auth_start_session();
    $_SESSION['webauthn_challenge'] = base64_encode($server->getChallenge()->getBinaryString());
    $_SESSION['webauthn_login_user_id'] = $user['id'];
    unset($_SESSION['webauthn_login_master_admin_id']);
    return $args;
}

// Same login-options flow, for a master admin signing in with their own
// passkey rather than a per-subject user's — api/webauthn/login_options.php
// tries this first (mirroring api/login.php's own password-check order,
// see includes/master_admin.php).
function webauthn_login_options_for_master_admin(array $master): object
{
    $server = webauthn_server();
    $credentialIds = array_map(
        static fn (array $c) => new ByteBuffer($c['credential_id']),
        webauthn_credentials_for_master_admin($master['id'])
    );
    $args = $server->getGetArgs($credentialIds, 60, true, true, true, true, true, 'preferred');
    auth_start_session();
    $_SESSION['webauthn_challenge'] = base64_encode($server->getChallenge()->getBinaryString());
    $_SESSION['webauthn_login_master_admin_id'] = $master['id'];
    unset($_SESSION['webauthn_login_user_id']);
    return $args;
}

// Returns a tagged result — ['type' => 'user', 'user' => [...]] or
// ['type' => 'master_admin', 'master_admin' => [...]] — since a single
// login attempt can be completing either flow (whichever of
// webauthn_login_options()/webauthn_login_options_for_master_admin()
// this session's pending challenge was actually created by). The caller
// (api/webauthn/login_verify.php) picks auth_login() vs.
// auth_login_master() based on which came back, matching how
// api/login.php's own password check is separate from the auth_login*()
// call it makes. This function only verifies the assertion and updates
// the credential's bookkeeping.
function webauthn_login_verify(string $credentialIdBinary, string $clientDataJSON, string $authenticatorData, string $signature): array
{
    auth_start_session();
    $challengeB64 = $_SESSION['webauthn_challenge'] ?? null;
    $pendingUserId = $_SESSION['webauthn_login_user_id'] ?? null;
    $pendingMasterAdminId = $_SESSION['webauthn_login_master_admin_id'] ?? null;
    if ($challengeB64 === null || ($pendingUserId === null && $pendingMasterAdminId === null)) {
        throw new RuntimeException('No pending passkey sign-in for this session — try again.');
    }
    unset($_SESSION['webauthn_challenge'], $_SESSION['webauthn_login_user_id'], $_SESSION['webauthn_login_master_admin_id']);

    $credential = webauthn_credential_find_by_credential_id($credentialIdBinary);
    $matchesPending = $credential !== null && (
        ($pendingUserId !== null && $credential['user_id'] === $pendingUserId)
        || ($pendingMasterAdminId !== null && $credential['master_admin_id'] === $pendingMasterAdminId)
    );
    if (!$matchesPending) {
        throw new RuntimeException('That passkey is not registered to this account.');
    }

    $server = webauthn_server();
    $ok = $server->processGet(
        $clientDataJSON,
        $authenticatorData,
        $signature,
        $credential['public_key'],
        base64_decode($challengeB64),
        (int) $credential['sign_count'],
        'preferred'
    );
    if (!$ok) {
        throw new RuntimeException('Passkey verification failed.');
    }

    $newSignCount = $server->getSignatureCounter();
    db()->prepare('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?')
        ->execute([$newSignCount ?? $credential['sign_count'], $credential['id']]);

    if ($credential['master_admin_id'] !== null) {
        $master = master_admin_find_by_id($credential['master_admin_id']);
        if ($master === null) {
            throw new RuntimeException('Account no longer exists.');
        }
        return ['type' => 'master_admin', 'master_admin' => $master];
    }

    $user = user_find_by_id($pendingUserId);
    if ($user === null) {
        throw new RuntimeException('Account no longer exists.');
    }
    return ['type' => 'user', 'user' => $user];
}
