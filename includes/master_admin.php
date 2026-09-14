<?php
declare(strict_types=1);

// Install-wide trusted-operator accounts — see sql/schema.sql's
// master_admins comment for the full rationale. A master admin logs in
// through the same login.php as everyone else (api/login.php checks
// this table before the per-subject users table); includes/auth.php's
// current_user() then transparently resolves them to a real per-subject
// users row via master_admin_ensure_shadow_user() below, so every other
// part of the app (content-authorship FKs, admin checks, admin.php's
// user list) needs no awareness that master admins exist at all.

function master_admin_find_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM master_admins WHERE email = ?');
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

function master_admin_find_by_id(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM master_admins WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function master_admin_record_login(string $id): void
{
    db()->prepare('UPDATE master_admins SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
}

// Finds (or lazily creates) the users row backing this master admin's
// access to $subjectId. Only ever treats an existing row as "theirs" if
// it's already flagged is_master_admin_shadow=1 — if a genuine,
// independent per-subject account happens to share the master admin's
// email (a rare collision), this refuses to silently take it over,
// returning null instead (the master admin simply can't access that one
// subject under that email).
function master_admin_ensure_shadow_user(array $master, string $subjectId): ?array
{
    $email = strtolower(trim($master['email']));
    $stmt = db()->prepare('SELECT * FROM users WHERE subject_id = ? AND email = ? AND is_master_admin_shadow = 1');
    $stmt->execute([$subjectId, $email]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing;
    }

    $id = make_uuid();
    try {
        $stmt = db()->prepare(
            "INSERT INTO users (id, subject_id, name, email, password_hash, role, status, approved_at, is_master_admin_shadow)
             VALUES (?, ?, ?, ?, ?, 'admin', 'approved', NOW(), 1)"
        );
        // Random, never-used password hash — this row is never logged
        // into directly; access always goes through the master_admins
        // credential check in api/login.php.
        $stmt->execute([$id, $subjectId, $master['name'], $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
    } catch (PDOException $e) {
        // uniq_subject_email collision — a genuine independent account
        // already owns this email on this subject (see doc comment).
        if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
            return null;
        }
        throw $e;
    }
    return user_find_by_id($id);
}
