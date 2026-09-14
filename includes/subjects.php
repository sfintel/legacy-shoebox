<?php
declare(strict_types=1);

// Resolves which "subject" (an independent family archive sharing this
// one codebase+database — see sql/schema.sql's top-of-file comment) the
// current request belongs to, from the HTTP Host header. One request =
// one subject, resolved once and cached for the life of the request via
// the statics below — every other file that needs subject-scoped data
// calls current_subject()/current_subject_id() rather than resolving it
// again itself.
//
// CLI scripts (upgrade.php, cron_backup.php) have no Host header at
// all, so they can't use subject_resolve_from_request() — they call
// set_current_subject() directly instead, after looking up whichever
// subject they're currently operating on. See cron_backup.php for the
// per-subject-child-process pattern this requires (ARCHIVE_ROOT is a
// PHP define()'d constant, immutable for the life of a process, so a
// single long-lived CLI process can't just loop across subjects).

// Deliberately does NOT use includes/db.php's db() — that singleton
// dies (by design) on connection failure, which is correct once setup
// is known complete but wrong here: this runs unconditionally on EVERY
// request, including a fresh install with no DB_* env vars set at all,
// and must degrade to "no subject resolved" rather than fatally break
// the very first visit to /setup.php. Mirrors setup_try_connect()'s
// same defensive posture (includes/setup.php), duplicated rather than
// shared because includes/setup.php isn't loaded yet at the point
// config.php needs subject resolution.
function subject_db_connect_safely(): ?PDO
{
    if (!env('DB_HOST') || !env('DB_NAME') || !env('DB_USER')) {
        return null;
    }
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', 'localhost'),
            env('DB_PORT', '3306'),
            env('DB_NAME')
        );
        return new PDO($dsn, env('DB_USER'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

function subject_find_by_hostname(string $host): ?array
{
    $pdo = subject_db_connect_safely();
    if ($pdo === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM subjects WHERE hostname = ? AND status = 'active'");
        $stmt->execute([strtolower(trim($host))]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        // A missing `subjects` table (pre-migration database) must also
        // degrade to "no subject resolved," not throw.
        return null;
    }
}

function subject_find_by_id(string $id): ?array
{
    $pdo = subject_db_connect_safely();
    if ($pdo === null) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// Strips a :port suffix and lowercases — the canonical form every
// hostname is compared/stored in (IPv6 literals are bracketed, e.g.
// "[::1]:8080" — split only on the last colon after the closing
// bracket, if any, so a bare IPv6 host isn't mangled). Shared by
// subject_resolve_from_request() and includes/setup.php's
// setup_create_subject_for_host(), so a newly-created subject's
// hostname is always stored in exactly the form later lookups compare
// against.
function subject_normalize_host(string $host): string
{
    $host = trim($host);
    if (str_starts_with($host, '[')) {
        $closeBracket = strpos($host, ']');
        $host = $closeBracket !== false ? substr($host, 0, $closeBracket + 1) : $host;
    } else {
        $colon = strrpos($host, ':');
        if ($colon !== false) {
            $host = substr($host, 0, $colon);
        }
    }
    return strtolower($host);
}

function subject_resolve_from_request(): ?array
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return null;
    }
    return subject_find_by_hostname(subject_normalize_host($host));
}

/** @var array|null Request-scoped "current subject" row, or null if unresolved. */
$GLOBALS['__current_subject'] = null;

function set_current_subject(array $subject): void
{
    $GLOBALS['__current_subject'] = $subject;
}

function current_subject(): ?array
{
    return $GLOBALS['__current_subject'];
}

function current_subject_id(): ?string
{
    return $GLOBALS['__current_subject']['id'] ?? null;
}

// For code paths that must never silently run unscoped — dies with a
// clear message rather than executing a query with a null subject_id,
// which would either violate a NOT NULL column or (worse) silently
// match nothing.
function require_current_subject(): array
{
    $subject = current_subject();
    if ($subject === null) {
        http_response_code(500);
        error_log('require_current_subject() called with no subject resolved for this request.');
        die('Server misconfigured. Check the error log.');
    }
    return $subject;
}
