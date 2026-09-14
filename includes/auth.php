<?php
declare(strict_types=1);

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $isHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30, // 30 days
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
    ]);
    session_start();
}

// Returns the current approved user's row, or null. Re-checks status on
// every call (not just at login) so a revoked account is locked out on
// its very next request, not just its next login. A logged-in master
// admin (see includes/master_admin.php) resolves here to a real,
// ordinary per-subject users row for whichever subject the current
// request belongs to — every caller of current_user() needs no
// awareness that master admins exist at all.
function current_user(): ?array
{
    auth_start_session();
    if (!empty($_SESSION['master_admin_id'])) {
        $master = master_admin_find_by_id($_SESSION['master_admin_id']);
        $subjectId = current_subject_id();
        if (!$master || $subjectId === null) {
            return null;
        }
        return master_admin_ensure_shadow_user($master, $subjectId);
    }
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    $user = user_find_by_id($id);
    if (!$user || $user['status'] !== 'approved') {
        return null;
    }
    // Defense-in-depth: subdomain-scoped session cookies already
    // prevent a session from one subject being presented on another's
    // hostname in practice, but this makes it impossible regardless of
    // cookie-scope misconfiguration.
    if ($user['subject_id'] !== current_subject_id()) {
        return null;
    }
    return $user;
}

function auth_login(array $user): void
{
    auth_start_session();
    session_regenerate_id(true);
    unset($_SESSION['master_admin_id']);
    $_SESSION['user_id'] = $user['id'];
    user_record_login($user['id']);
}

function auth_login_master(array $master): void
{
    auth_start_session();
    session_regenerate_id(true);
    unset($_SESSION['user_id']);
    $_SESSION['master_admin_id'] = $master['id'];
    master_admin_record_login($master['id']);
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

// For .php pages: redirects to login.php if not authenticated.
function require_auth_page(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

// For api/*.php endpoints: responds 401 JSON instead of redirecting.
function require_auth_api(): array
{
    $user = current_user();
    if (!$user) {
        json_response(['error' => 'Not authenticated'], 401);
    }
    return $user;
}

function require_admin_page(): array
{
    $user = require_auth_page();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        simple_page('Admins only', '<h1>Admins only</h1><p>This page is restricted.</p>');
        exit;
    }
    return $user;
}

function require_admin_api(): array
{
    $user = require_auth_api();
    if ($user['role'] !== 'admin') {
        json_response(['error' => 'Admins only'], 403);
    }
    return $user;
}

// True when the CURRENT session is logged in as a master admin —
// independent of current_user()'s per-subject shadow-user resolution,
// for UI code (nav links) that needs to know "is this person a master
// admin," not just "what subject-scoped identity do they have here."
function is_master_admin_session(): bool
{
    auth_start_session();
    return !empty($_SESSION['master_admin_id']);
}

// For master_admin.php only — independent of current_subject(), since
// the cross-subject dashboard itself needs no resolved subject to list
// every subject that exists.
function require_master_admin_page(): array
{
    auth_start_session();
    $id = $_SESSION['master_admin_id'] ?? null;
    $master = $id ? master_admin_find_by_id($id) : null;
    if (!$master) {
        header('Location: /login.php');
        exit;
    }
    return $master;
}

// admin and author can both add content; reader cannot. See the `role`
// column comment in sql/schema.sql.
function user_can_add_content(array $user): bool
{
    return $user['role'] === 'admin' || $user['role'] === 'author';
}

function require_content_page(): array
{
    $user = require_auth_page();
    if (!user_can_add_content($user)) {
        http_response_code(403);
        simple_page('Not authorized', '<h1>Not authorized</h1><p>You don\'t have permission to add content.</p>');
        exit;
    }
    return $user;
}

function require_content_api(): array
{
    $user = require_auth_api();
    if (!user_can_add_content($user)) {
        json_response(['error' => "You don't have permission to add content."], 403);
    }
    return $user;
}

// admin/author only, per the scoping decision when passkeys were added
// — readers keep password-only login. Also requires an approved
// account, matching every other login path.
function user_can_use_passkey(array $user): bool
{
    return $user['status'] === 'approved' && in_array($user['role'], ['admin', 'author'], true);
}

function require_passkey_page(): array
{
    $user = require_auth_page();
    if (!user_can_use_passkey($user)) {
        http_response_code(403);
        simple_page('Not authorized', '<h1>Not authorized</h1><p>Passkeys are available to admin and author accounts.</p>');
        exit;
    }
    return $user;
}

function require_passkey_api(): array
{
    $user = require_auth_api();
    if (!user_can_use_passkey($user)) {
        json_response(['error' => 'Passkeys are available to admin and author accounts.'], 403);
    }
    return $user;
}
