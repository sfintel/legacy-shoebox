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
// its very next request, not just its next login.
function current_user(): ?array
{
    auth_start_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    $user = user_find_by_id($id);
    if (!$user || $user['status'] !== 'approved') {
        return null;
    }
    return $user;
}

function auth_login(array $user): void
{
    auth_start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
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
