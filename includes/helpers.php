<?php
declare(strict_types=1);

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function client_ip(): string
{
    // Behind a reverse proxy/CDN, X-Forwarded-For may be set — take the
    // first (client) address if present, otherwise fall back to the
    // direct connection. Not spoof-proof, but fine for a rate-limit key
    // on a small private app rather than a security boundary.
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    if ($fwd) {
        return trim(explode(',', $fwd)[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Shared by api/login.php (checks/records/resets it) and
// api/admin/users.php (the admin "Unlock" action resets it) — kept in
// one place so the two can't drift apart on the key format.
function login_email_key(string $email): string
{
    return 'login:email:' . strtolower(trim($email));
}

// Simple DB-backed rate limiter (a plain in-memory Map won't survive
// between requests here the way it does in the Node version, since each
// PHP request is its own process). Built from the three primitives
// below — kept as its own function since "check and count this attempt"
// is the common case (signup-by-IP, chat-by-session); login lockout
// needs the primitives separately, since only *failed* attempts should
// count and a lockout must be checked before doing any work.
function check_rate_limit(string $key, int $limit, int $windowSeconds = 3600): bool
{
    if (rate_limit_exceeded($key, $limit, $windowSeconds)) {
        return false;
    }
    rate_limit_hit($key, $windowSeconds);
    return true;
}

// Read-only — does not create or modify a row. True if $key has already
// hit $limit within the still-current window.
function rate_limit_exceeded(string $key, int $limit, int $windowSeconds): bool
{
    $stmt = db()->prepare('SELECT hit_count, window_start FROM rate_limits WHERE rkey = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    if ((new DateTimeImmutable($row['window_start']))->modify("+{$windowSeconds} seconds") < new DateTimeImmutable()) {
        return false; // window expired — treated as a fresh start, not exceeded
    }
    return (int) $row['hit_count'] >= $limit;
}

function rate_limit_hit(string $key, int $windowSeconds): void
{
    $pdo = db();
    $now = new DateTimeImmutable();
    $stmt = $pdo->prepare('SELECT hit_count, window_start FROM rate_limits WHERE rkey = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if (!$row || (new DateTimeImmutable($row['window_start']))->modify("+{$windowSeconds} seconds") < $now) {
        $stmt = $pdo->prepare('REPLACE INTO rate_limits (rkey, hit_count, window_start) VALUES (?, 1, ?)');
        $stmt->execute([$key, $now->format('Y-m-d H:i:s')]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE rate_limits SET hit_count = hit_count + 1 WHERE rkey = ?');
    $stmt->execute([$key]);
}

function rate_limit_reset(string $key): void
{
    $stmt = db()->prepare('DELETE FROM rate_limits WHERE rkey = ?');
    $stmt->execute([$key]);
}

// Single source of truth for the Ask tab's "Telling for:" options — used
// to render that dropdown (index.php), the signup page's matching
// dropdown (signup.php), and to validate the value both api/signup.php
// and api/chat.php receive. Data-driven from the audience_modes table
// (see includes/archive.php) rather than a hardcoded list — categories
// are admin/wizard-editable. Cached per-request since this is read from
// many places. Each category's `ai_guidance` text (how the AI should
// adjust register for it) lives on the same row and is woven into
// knowledge_system_role()'s AUDIENCE MODE section — that part can't be
// auto-derived, which is why creating a category requires writing that
// guidance, not just picking a label.
function audience_modes(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = array_map(
        static fn (array $r) => ['value' => $r['slug'], 'label' => $r['label']],
        archive_audience_modes_rows()
    );
    // Defensive fallback only — the setup wizard always seeds at least
    // one category, so this shouldn't normally be reached, but the Ask
    // tab and signup page must never break if it somehow is empty.
    if (!$cached) {
        $cached = [['value' => 'adult', 'label' => 'Adults / family']];
    }
    return $cached;
}

function audience_mode_values(): array
{
    return array_column(audience_modes(), 'value');
}

function audience_mode_default(): string
{
    foreach (archive_audience_modes_rows() as $row) {
        if ($row['is_default']) {
            return $row['slug'];
        }
    }
    $values = audience_mode_values();
    return $values[0] ?? 'adult';
}

// Human-readable label for a stored value — used by api/admin/users.php
// so admin.js never needs its own copy of the label list. Falls back to
// the raw value if it doesn't match a current mode (e.g. a mode that
// existed when the user signed up but was since removed).
function audience_mode_label(string $value): string
{
    foreach (audience_modes() as $mode) {
        if ($mode['value'] === $value) {
            return $mode['label'];
        }
    }
    return $value;
}

// Renders the shared <option> list, HTML-escaped, with $selected marked.
// Used by both index.php and signup.php so the two dropdowns can never
// drift out of sync.
function audience_mode_options(string $selected): string
{
    $out = '';
    foreach (audience_modes() as $mode) {
        $isSelected = $mode['value'] === $selected ? ' selected' : '';
        $out .= '<option value="' . h($mode['value']) . '"' . $isSelected . '>' . h($mode['label']) . "</option>\n";
    }
    return $out;
}

// Read once per request — VERSION never changes mid-request, and this
// gets called from the About panel on every page load.
function app_version(): string
{
    static $version = null;
    if ($version === null) {
        $version = trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: 'unknown';
    }
    return $version;
}

// Renders the header nav shared identically by all six admin_*.php
// pages — every section always listed (including a link back to
// whichever page you're already on, unlike the old per-page markup that
// omitted the self-link as its only "you are here" cue), with the
// current one highlighted instead. $current is one of
// 'users'|'content'|'archive'|'settings'|'redactions'|'backup'; kept
// here rather than duplicated six times so the list of sections and the
// highlight logic can't drift between pages (see also index.php's
// "Admin" dropdown menu, which links to the same six URLs).
//
// Every link except Content carries an id (e.g. "usersLink") — used
// only by admin_content.php's own JS, the one admin_* page a non-admin
// author can also reach (require_content_page(), not
// require_admin_page()), to hide every admin-only section for them at
// runtime. The other five pages render these same ids but never
// reference them, since require_admin_page() already means only an
// admin ever sees that markup at all.
function admin_nav_html(string $current): string
{
    $sections = [
        'users' => ['/admin.php', 'Users'],
        'content' => ['/admin_content.php', 'Content'],
        'archive' => ['/admin_archive.php', 'Archive'],
        'settings' => ['/admin_settings.php', 'Settings'],
        'redactions' => ['/admin_redactions.php', 'Redactions'],
        'backup' => ['/admin_backup.php', 'Backup'],
    ];
    $out = '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
    foreach ($sections as $key => [$href, $label]) {
        $isCurrent = $key === $current;
        $class = 'ghost-btn' . ($isCurrent ? ' active' : '');
        $aria = $isCurrent ? ' aria-current="page"' : '';
        $id = $key !== 'content' ? ' id="' . $key . 'Link"' : '';
        $out .= '<a' . $id . ' class="' . $class . '" href="' . h($href) . '" style="text-decoration:none; display:inline-block;"' . $aria . '>' . h($label) . "</a>\n";
    }
    $out .= '<a class="ghost-btn" href="/" style="text-decoration:none; display:inline-block;">Back to app</a>' . "\n";
    $out .= '<button id="logoutBtn" class="ghost-btn">Sign out</button>' . "\n";
    $out .= '</div>';
    return $out;
}

function simple_page(string $title, string $body): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . h($title) . '</title><style>'
        . ':root{--bg:#1b1a17;--card:#242320;--accent:#b8863b;--text:#f3ede2;--muted:#a89f8f;}'
        . '*{box-sizing:border-box;}'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:var(--bg);color:var(--text);font-family:Georgia,\'Times New Roman\',serif;padding:20px;}'
        . '.card{background:var(--card);border:1px solid #38352f;border-radius:12px;padding:36px 32px;'
        . 'width:100%;max-width:420px;box-shadow:0 10px 40px rgba(0,0,0,.4);text-align:center;}'
        . 'h1{font-size:1.2rem;margin:0 0 12px;}'
        . 'p{color:var(--muted);line-height:1.5;font-size:.95rem;}'
        . 'button, a.btn{display:inline-block;margin-top:14px;padding:11px 22px;border:none;border-radius:8px;'
        . 'background:var(--accent);color:#1b1a17;font-weight:700;font-size:.95rem;cursor:pointer;text-decoration:none;}'
        . '.muted{color:var(--muted);font-size:.85rem;}'
        . '</style></head><body><div class="card">' . $body . '</div></body></html>';
}
