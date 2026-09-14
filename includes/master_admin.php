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

// Lowercased set of every master admin's email — used to label a
// users row as "master admin" in the admin UI by live email match
// (not just the is_master_admin_shadow flag, which is only set on a
// freshly-created row — a master admin's login just as often resolves
// to a pre-existing real admin account that happens to share their
// email, and that should be labeled too).
function master_admin_emails(): array
{
    static $emails = null;
    if ($emails === null) {
        $emails = array_map('strtolower', db()->query('SELECT email FROM master_admins')->fetchAll(PDO::FETCH_COLUMN));
    }
    return $emails;
}

// Finds (or lazily creates) the users row backing this master admin's
// access to $subjectId. Any existing row for this email on this
// subject — shadow-flagged or not — IS the master admin logging in:
// only someone who already has server/CLI access can create a
// master_admins row in the first place (see create_master_admin.php),
// so there's no real privilege-escalation risk in treating a same-email
// match as "this is them," and a real person's master-admin email very
// commonly already matches their own pre-existing per-subject admin
// account. Always resolves to full, active admin rights — promoting an
// existing non-admin/non-approved row rather than refusing, since
// logging in with the master credential should never be blocked by
// whatever role/status that row happened to have before.
function master_admin_ensure_shadow_user(array $master, string $subjectId): array
{
    $email = strtolower(trim($master['email']));
    $stmt = db()->prepare('SELECT * FROM users WHERE subject_id = ? AND email = ?');
    $stmt->execute([$subjectId, $email]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['role'] !== 'admin' || $existing['status'] !== 'approved') {
            db()->prepare("UPDATE users SET role = 'admin', status = 'approved', approved_at = COALESCE(approved_at, NOW()) WHERE id = ?")
                ->execute([$existing['id']]);
            return user_find_by_id($existing['id']);
        }
        return $existing;
    }

    $id = make_uuid();
    $stmt = db()->prepare(
        "INSERT INTO users (id, subject_id, name, email, password_hash, role, status, approved_at, is_master_admin_shadow)
         VALUES (?, ?, ?, ?, ?, 'admin', 'approved', NOW(), 1)"
    );
    // Random, never-used password hash — this row is never logged into
    // directly; access always goes through the master_admins credential
    // check in api/login.php.
    $stmt->execute([$id, $subjectId, $master['name'], $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
    return user_find_by_id($id);
}
