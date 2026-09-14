<?php
declare(strict_types=1);

// CLI-only bootstrap for a master admin account (see sql/schema.sql's
// master_admins comment and includes/master_admin.php) — deliberately
// no HTTP-reachable way to create one, since this is the single most
// powerful account in the system (admin rights on every subject).
// Usage: php create_master_admin.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config.php';

function prompt(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

echo "Create a master admin (admin rights on every subject on this install).\n\n";

$name = prompt('Name: ');
if ($name === '') {
    fwrite(STDERR, "Name is required.\n");
    exit(1);
}

$email = strtolower(prompt('Email: '));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid email is required.\n");
    exit(1);
}
if (master_admin_find_by_email($email)) {
    fwrite(STDERR, "A master admin with that email already exists.\n");
    exit(1);
}

$password = prompt('Password (min 8 characters): ');
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}
$confirm = prompt('Confirm password: ');
if ($password !== $confirm) {
    fwrite(STDERR, "Passwords don't match.\n");
    exit(1);
}

$id = make_uuid();
db()->prepare('INSERT INTO master_admins (id, name, email, password_hash) VALUES (?, ?, ?, ?)')
    ->execute([$id, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);

echo "\nMaster admin created. Log in at any subject's /login.php with this email/password.\n";
