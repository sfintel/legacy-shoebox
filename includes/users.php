<?php
declare(strict_types=1);

// User CRUD against MySQL via PDO. Mirrors the Node version's
// lib/users.js, but backed by a real table instead of a JSON file since
// that's what a LAMP stack gives us for free.

// Only ever called for the install's very first subject during setup —
// retained for any script/test that still calls it directly, but
// includes/setup.php's setup_create_admin() (subject-scoped) is the
// real path every wizard run uses.
function user_seed_admin(string $name, string $email, string $password): void
{
    $subjectId = require_current_subject()['id'];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return; // this subject already has users — never overwrite
    }
    $stmt = $pdo->prepare(
        'INSERT INTO users (id, subject_id, name, email, password_hash, role, status, approved_at)
         VALUES (?, ?, ?, ?, ?, \'admin\', \'approved\', NOW())'
    );
    $stmt->execute([
        make_uuid(),
        $subjectId,
        $name,
        strtolower(trim($email)),
        password_hash($password, PASSWORD_DEFAULT),
    ]);
}

// Scoped to the current request's subject — the same email can be a
// separate, independent account on a different subject (see
// sql/schema.sql's users.uniq_subject_email).
function user_find_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE subject_id = ? AND email = ?');
    $stmt->execute([current_subject_id(), strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Deliberately NOT subject-scoped — this is the session-lookup
// primitive (current_user() in includes/auth.php), which already
// double-checks the returned row's subject_id against
// current_subject_id() itself as defense-in-depth. A user id is a
// globally unique UUID regardless of subject, so no ambiguity exists
// either way.
function user_find_by_id(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function user_all(): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE subject_id = ? ORDER BY created_at DESC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

// Default signup-notification target when NOTIFY_EMAIL isn't set —
// replaces the retired ADMIN_EMAIL env var, since the wizard creates the
// admin account directly in the database rather than via .env (see
// includes/setup.php). Scoped to the current subject, same as
// user_all() above.
function user_first_admin_email(): ?string
{
    $stmt = db()->prepare("SELECT email FROM users WHERE subject_id = ? AND role = 'admin' ORDER BY created_at ASC LIMIT 1");
    $stmt->execute([current_subject_id()]);
    $email = $stmt->fetchColumn();
    return $email !== false ? (string) $email : null;
}

// Throws a RuntimeException with a user-facing message if the email is
// already taken on this subject (matches the Node version's
// createPending() behaviour). $audienceMode is validated against
// audience_mode_values() by the caller (api/signup.php) — falls back to
// audience_mode_default() here too, defensively, in case a future
// caller forgets to.
function user_create_pending(string $name, string $email, string $password, ?string $audienceMode = null, ?string $reason = null): array
{
    $normalizedEmail = strtolower(trim($email));
    if (user_find_by_email($normalizedEmail)) {
        throw new RuntimeException('An account with that email already exists.');
    }
    $mode = in_array($audienceMode, audience_mode_values(), true) ? $audienceMode : audience_mode_default();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO users (id, subject_id, name, email, password_hash, role, status, default_audience_mode, signup_reason)
         VALUES (?, ?, ?, ?, ?, \'reader\', \'pending\', ?, ?)'
    );
    $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
    $stmt->execute([$id, current_subject_id(), trim($name), $normalizedEmail, password_hash($password, PASSWORD_DEFAULT), $mode, $reason]);
    return user_find_by_id($id);
}

// Called from auth_login() (includes/auth.php) — the single choke point
// every successful login already goes through, password or otherwise —
// so every login method records this the same way with no extra
// call-site wiring needed.
function user_record_login(string $id): void
{
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
}

function user_set_status(string $id, string $status): ?array
{
    $pdo = db();
    if ($status === 'approved') {
        $stmt = $pdo->prepare('UPDATE users SET status = ?, approved_at = NOW() WHERE id = ?');
    } else {
        $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE id = ?');
    }
    $stmt->execute([$status, $id]);
    return user_find_by_id($id);
}

// Only ever moves a user between 'author' and 'reader' — never touches
// an admin's role (see the guard in api/admin/users.php, which is the
// only caller).
function user_set_role(string $id, string $role): ?array
{
    if (!in_array($role, ['author', 'reader'], true)) {
        throw new InvalidArgumentException("Invalid role: $role");
    }
    $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
    $stmt->execute([$role, $id]);
    return user_find_by_id($id);
}

// Deliberately a separate function from user_set_role() rather than
// just widening its allowed-role list — promoting someone TO admin is a
// meaningfully different, higher-stakes operation (full user management
// + access to every part of the site) than moving between author and
// reader, and keeping it distinct makes that a one-line, easy-to-audit
// call site (see api/admin/users.php's set_admin action) rather than
// something a future caller could pass by accident.
function user_promote_to_admin(string $id): ?array
{
    $stmt = db()->prepare("UPDATE users SET role = 'admin' WHERE id = ?");
    $stmt->execute([$id]);
    return user_find_by_id($id);
}

function user_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// Returns true (and records the nonce) the first time it's called for a
// given user+nonce pair; false on any replay. A unique key on
// (user_id, nonce) makes this safe even under concurrent requests — the
// second INSERT just fails.
function user_consume_token_nonce(string $userId, string $nonce): bool
{
    try {
        $stmt = db()->prepare('INSERT INTO consumed_tokens (user_id, nonce) VALUES (?, ?)');
        $stmt->execute([$userId, $nonce]);
        return true;
    } catch (PDOException $e) {
        // Duplicate-key = already consumed. Any other error is unexpected.
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            return false;
        }
        throw $e;
    }
}

function user_verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

function user_set_password(string $id, string $newPassword): void
{
    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
}
