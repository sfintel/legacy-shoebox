<?php
// Loads .env into $_ENV/getenv() (no Composer/dependency needed — most
// shared hosts won't have a package manager available at all), then
// exposes a few derived constants used throughout the app.

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // never leak errors to visitors
ini_set('log_errors', '1');

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        // Strip matching surrounding quotes, if present.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

load_env_file(__DIR__ . '/.env');

define('CHAT_RATE_LIMIT', (int) env('CHAT_RATE_LIMIT', '60'));
define('SIGNUP_RATE_LIMIT', (int) env('SIGNUP_RATE_LIMIT', '10'));
define('LOGIN_ATTEMPT_LIMIT', (int) env('LOGIN_ATTEMPT_LIMIT', '5'));
define('LOGIN_LOCKOUT_SECONDS', (int) env('LOGIN_LOCKOUT_SECONDS', '900'));
define('FORGOT_PASSWORD_RATE_LIMIT', (int) env('FORGOT_PASSWORD_RATE_LIMIT', '5'));
define('SIGNUP_REASON_MAX_LENGTH', 1000);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/subjects.php';

// --- Subject resolution ---
// One request = one subject (see includes/subjects.php for the full
// rationale). An HTTP request resolves it from the Host header; a CLI
// invocation has no Host header at all, so it instead accepts an
// explicit --subject=<id> argument (used by cron_backup.php's
// per-subject dispatcher — see that file). Neither path is guaranteed
// to resolve anything (unconfigured DB, pre-migration schema, an
// unrecognized host, or a CLI script not passed --subject) — every
// consumer of current_subject()/ARCHIVE_ROOT/APP_URL below must treat
// "unresolved" as a normal, expected state, not an error, until
// setup_is_complete() says otherwise.
if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--subject=')) {
            $cliSubject = subject_find_by_id(substr($arg, strlen('--subject=')));
            if ($cliSubject !== null) {
                set_current_subject($cliSubject);
            }
            break;
        }
    }
} else {
    $resolvedSubject = subject_resolve_from_request();
    if ($resolvedSubject !== null) {
        set_current_subject($resolvedSubject);
    }
}

// APP_URL is derived per-subject from its own hostname (with an https
// scheme) rather than a single flat .env value — every subject has its
// own hostname, and outbound email (approve/reject links, "log in now
// at ..." links) must point at the subject the recipient actually
// belongs to, never at whichever hostname happened to be in .env. Falls
// back to the .env value only when no subject is resolved (CLI
// contexts without --subject, or mid-setup on an unrecognized host).
$resolvedHostname = current_subject()['hostname'] ?? null;
define('APP_URL', $resolvedHostname !== null
    ? 'https://' . $resolvedHostname
    : rtrim((string) env('APP_URL', 'http://localhost'), '/'));

// ARCHIVE_ROOT_BASE is the parent directory holding every subject's
// upload/backup data; ARCHIVE_ROOT itself resolves to that subject's
// own subdirectory (ARCHIVE_ROOT_BASE/{slug}) — includes/content.php
// and includes/backup.php consume the ARCHIVE_ROOT constant exactly as
// before multi-subject support, unaware anything changed.
//
// When no subject is resolved, fall back to the pre-multi-subject
// behavior (env('ARCHIVE_ROOT', '..')) rather than inventing a
// per-request placeholder path — this matters for real, not just
// defensively: upgrade.php's own pre-migration safety backup runs as
// CLI with no --subject (there's no subject to pass yet — the
// `subjects` table itself doesn't exist before the 2.0.0 migration
// creates it), and it must find this install's actual, existing
// uploads/backups directory under the OLD single-tenant ARCHIVE_ROOT
// value, not a nonexistent ARCHIVE_ROOT_BASE/{placeholder} path.
if (current_subject() !== null) {
    $archiveRootBase = (string) env('ARCHIVE_ROOT_BASE', '..');
    $archiveRootBase = rtrim(str_starts_with($archiveRootBase, '/') ? $archiveRootBase : __DIR__ . '/' . $archiveRootBase, '/');
    define('ARCHIVE_ROOT', $archiveRootBase . '/' . current_subject()['slug']);
} else {
    $legacyArchiveRoot = (string) env('ARCHIVE_ROOT', '..');
    define('ARCHIVE_ROOT', rtrim(str_starts_with($legacyArchiveRoot, '/') ? $legacyArchiveRoot : __DIR__ . '/' . $legacyArchiveRoot, '/'));
}

require_once __DIR__ . '/includes/ai_provider.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/tokens.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/master_admin.php';
require_once __DIR__ . '/includes/content.php';
require_once __DIR__ . '/includes/redaction.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/transcript.php';
require_once __DIR__ . '/includes/markdown_lite.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/keywords.php';
require_once __DIR__ . '/includes/knowledge.php';
require_once __DIR__ . '/includes/quote_check.php';
require_once __DIR__ . '/includes/video_seek.php';
require_once __DIR__ . '/includes/narrative.php';
require_once __DIR__ . '/includes/knowledge_writer.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webauthn_helper.php';
require_once __DIR__ . '/includes/setup.php';
require_once __DIR__ . '/includes/backup.php';

// --- First-run setup gate ---
// A CLI invocation (test scripts, one-off maintenance) has no HTTP
// routing concept — skip the gate entirely rather than trying to guess
// intent. /setup.php and everything under /api/setup/ are always
// reachable (setup.php itself refuses to do anything once
// setup_is_complete() is true, so it can't be re-run after the fact —
// see includes/setup.php). Every other request redirects to /setup.php
// until the wizard has finished.
if (PHP_SAPI !== 'cli') {
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $isSetupRoute = str_ends_with($requestPath, '/setup.php') || str_starts_with($requestPath, '/api/setup/');

    if (!$isSetupRoute && !setup_is_complete()) {
        header('Location: /setup.php');
        exit;
    }

    if (!$isSetupRoute) {
        // Only required once setup is confirmed complete — a fresh
        // install has no .env at all, so this must never run before
        // that's known. ADMIN_EMAIL/ADMIN_PASSWORD are deliberately not
        // required here: the wizard creates the admin account directly
        // in the database (see setup_create_admin()), not via env vars.
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'SESSION_SECRET'] as $required) {
            if (env($required) === null) {
                http_response_code(500);
                error_log("app-lamp: missing required env var $required — this shouldn't happen once setup is complete.");
                die('Server misconfigured. Check the error log.');
            }
        }
    }
}
