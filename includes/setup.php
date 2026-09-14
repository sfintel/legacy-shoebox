<?php
declare(strict_types=1);

// The first-run onboarding wizard's backend — see setup.php (the page)
// and api/setup/*.php (its endpoints). config.php's setup gate redirects
// every request except /setup.php and /api/setup/* here until
// setup_is_complete() is true.
//
// Deliberately defensive throughout: a fresh install has no .env at
// all, so nothing in this file may assume a working DB connection or
// even that DB_* env vars exist — every check degrades to "not ready
// yet" rather than throwing.

// --- Completeness / stage detection ---

// The authoritative "has setup finished" check — for THIS hostname's
// subject specifically, not the install as a whole (a multi-subject
// install is never simply "done"; each subject finishes its own wizard
// independently). Does its OWN lightweight PDO connection attempt
// rather than calling db() (includes/db.php), which deliberately dies
// on connection failure for an already-configured deployment — that's
// the wrong behavior here, where "can't connect yet" just means "still
// on an earlier stage," not a fatal error.
function setup_is_complete(): bool
{
    if (!env('DB_HOST') || !env('DB_NAME') || !env('DB_USER')) {
        return false;
    }
    if (!setup_schema_ready()) {
        return false;
    }
    $subject = current_subject();
    if ($subject === null) {
        return false;
    }
    return setup_subject_completed($subject['id']);
}

function setup_subject_completed(string $subjectId): bool
{
    try {
        $pdo = setup_try_connect();
        if (!$pdo) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT setup_completed_at FROM site_settings WHERE subject_id = ?');
        $stmt->execute([$subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (bool) ($row && $row['setup_completed_at'] !== null);
    } catch (Throwable $e) {
        return false;
    }
}

// Returns a PDO connection or null — never throws, never dies. Used only
// by this file's own defensive checks; everywhere else in the app,
// includes/db.php's db() (which DOES die on failure) is correct, since
// by that point setup is already known to be complete.
function setup_try_connect(): ?PDO
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
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (Throwable $e) {
        return null;
    }
}

function setup_db_configured(): bool
{
    return setup_try_connect() !== null;
}

function setup_schema_ready(): bool
{
    $pdo = setup_try_connect();
    if (!$pdo) {
        return false;
    }
    try {
        $pdo->query('SELECT 1 FROM site_settings LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function setup_identity_ready(string $subjectId): bool
{
    $pdo = setup_try_connect();
    if (!$pdo) {
        return false;
    }
    try {
        $stmt = $pdo->prepare('SELECT site_name FROM site_settings WHERE subject_id = ?');
        $stmt->execute([$subjectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (bool) ($row && trim((string) $row['site_name']) !== '');
    } catch (Throwable $e) {
        return false;
    }
}

function setup_admin_ready(string $subjectId): bool
{
    $pdo = setup_try_connect();
    if (!$pdo) {
        return false;
    }
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND subject_id = ?");
        $stmt->execute([$subjectId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// True when the current request is allowed to create a brand-new
// subject for its (unrecognized) hostname: either this is the
// install's very first subject ever (no SUBJECT_SETUP_SECRET needed —
// matches today's ungated first-run UX, since an unconfigured install
// with no DB credentials is already its own gate), a logged-in master
// admin is asking (already a trusted install-wide operator — see
// includes/master_admin.php), or the submitted secret matches
// SUBJECT_SETUP_SECRET.
function setup_new_subject_authorized(?string $submittedSecret): bool
{
    $pdo = setup_try_connect();
    if (!$pdo) {
        return false;
    }
    try {
        $subjectCount = (int) $pdo->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    if ($subjectCount === 0) {
        return true;
    }
    auth_start_session();
    if (!empty($_SESSION['master_admin_id']) && master_admin_find_by_id($_SESSION['master_admin_id'])) {
        return true;
    }
    $expected = (string) env('SUBJECT_SETUP_SECRET', '');
    return $expected !== '' && $submittedSecret !== null && hash_equals($expected, $submittedSecret);
}

// Derives a short, URL/filesystem-safe slug from a hostname's first
// label (e.g. "dad.fintelfamily.com" -> "dad") for use in
// ARCHIVE_ROOT_BASE/{slug} — appends a numeric suffix on collision so
// two similarly-named hostnames never fight over the same directory.
function setup_unique_slug_for_host(PDO $pdo, string $host): string
{
    $label = explode('.', $host)[0] ?? $host;
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $label), '-'));
    $base = $base !== '' ? substr($base, 0, 60) : 'subject';

    $slug = $base;
    $suffix = 1;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM subjects WHERE slug = ?');
        $stmt->execute([$slug]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $suffix++;
        $slug = $base . '-' . $suffix;
    }
}

// Creates (or, idempotently, returns) the subjects row for $host and
// makes it the current request's subject — called once authorization
// (setup_new_subject_authorized()) has already been confirmed. Every
// later request to this same hostname resolves the subject normally via
// subject_resolve_from_request(), so no session state is needed beyond
// this one request.
function setup_create_subject_for_host(string $host): array
{
    $host = subject_normalize_host($host);
    $pdo = db();
    $existing = subject_find_by_hostname($host);
    if ($existing !== null) {
        set_current_subject($existing);
        return $existing;
    }
    $id = make_uuid();
    $slug = setup_unique_slug_for_host($pdo, $host);
    $stmt = $pdo->prepare('INSERT INTO subjects (id, slug, hostname, display_name) VALUES (?, ?, ?, ?)');
    $stmt->execute([$id, $slug, $host, $host]);
    $subject = subject_find_by_id($id);
    set_current_subject($subject);
    return $subject;
}

// Drives which stage setup.php shows — re-derived from actual state on
// every load (not session-tracked), so refreshing, coming back later, or
// bookmarking mid-setup always resumes at the right place.
function setup_current_stage(): string
{
    if (!setup_schema_ready()) {
        return 'db';
    }
    $subject = current_subject();
    if ($subject === null) {
        return 'new_subject_gate';
    }
    if (!setup_identity_ready($subject['id'])) {
        return 'identity';
    }
    if (!setup_admin_ready($subject['id'])) {
        return 'admin';
    }
    return 'advanced';
}

// --- .env read/write ---

function setup_env_path(): string
{
    return __DIR__ . '/../.env';
}

function setup_read_env_values(): array
{
    $path = setup_env_path();
    if (!is_file($path)) {
        return [];
    }
    $values = [];
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
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[-1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        $values[$key] = $value;
    }
    return $values;
}

// Merges $newValues into whatever's already in .env and rewrites the
// whole file. This app's .env is entirely wizard-managed while setup is
// in progress (it never runs again once complete — see
// setup_is_complete()), so regenerating a clean file each time is
// simpler and safer than trying to patch specific lines of a
// hand-editable file in place. Also updates the CURRENT request's
// env()/putenv() state, since config.php already loaded .env before
// setup.php runs — later stages within the same wizard session need the
// values immediately, not just on the next request.
function setup_write_env(array $newValues): void
{
    $values = array_merge(setup_read_env_values(), $newValues);
    $get = static fn (string $k, string $default = '') => $values[$k] ?? $default;

    $lines = [
        '# Generated by the setup wizard. Safe to hand-edit afterward — the',
        '# wizard never runs again once setup is complete.',
        '',
        '# --- Database ---',
        'DB_HOST=' . $get('DB_HOST', 'localhost'),
        'DB_PORT=' . $get('DB_PORT', '3306'),
        'DB_NAME=' . $get('DB_NAME'),
        'DB_USER=' . $get('DB_USER'),
        'DB_PASS=' . $get('DB_PASS'),
        '',
        '# --- AI ---',
        'AI_PROVIDER=' . $get('AI_PROVIDER', 'anthropic'),
        'AI_API_KEY=' . $get('AI_API_KEY'),
        'AI_MODEL=' . $get('AI_MODEL'),
        'AI_BASE_URL=' . $get('AI_BASE_URL'),
        '',
        '# --- Security ---',
        'SESSION_SECRET=' . $get('SESSION_SECRET'),
        '',
        '# --- Site ---',
        'APP_URL=' . $get('APP_URL'),
        'NOTIFY_EMAIL=' . $get('NOTIFY_EMAIL'),
        // Parent directory holding every subject's uploads/backups —
        // each subject's own ARCHIVE_ROOT is computed automatically as
        // ARCHIVE_ROOT_BASE/{slug} (see config.php), never configured
        // per-subject.
        'ARCHIVE_ROOT_BASE=' . $get('ARCHIVE_ROOT_BASE', '..'),
        '',
        '# --- Multi-subject ---',
        // Required before a second (or later) subject can be added on a
        // new hostname — see api/setup/authorize_subject.php. Generated
        // automatically at install time; a logged-in master admin can
        // bypass this prompt entirely (see includes/master_admin.php).
        'SUBJECT_SETUP_SECRET=' . $get('SUBJECT_SETUP_SECRET'),
        '',
        '# --- Outgoing email (optional — leave SMTP_HOST blank to skip) ---',
        'SMTP_HOST=' . $get('SMTP_HOST'),
        'SMTP_PORT=' . $get('SMTP_PORT', '587'),
        'SMTP_SECURE=' . $get('SMTP_SECURE', 'false'),
        'SMTP_USER=' . $get('SMTP_USER'),
        'SMTP_PASS=' . $get('SMTP_PASS'),
        'MAIL_FROM=' . $get('MAIL_FROM'),
        '',
        '# --- Optional tuning ---',
        'CHAT_RATE_LIMIT=' . $get('CHAT_RATE_LIMIT', '60'),
        'SIGNUP_RATE_LIMIT=' . $get('SIGNUP_RATE_LIMIT', '10'),
        'LOGIN_ATTEMPT_LIMIT=' . $get('LOGIN_ATTEMPT_LIMIT', '5'),
        'LOGIN_LOCKOUT_SECONDS=' . $get('LOGIN_LOCKOUT_SECONDS', '900'),
        '',
        '# --- Backup & Restore (/admin_backup.php) ---',
        'BACKUP_RETENTION_COUNT=' . $get('BACKUP_RETENTION_COUNT', '14'),
        'BACKUP_AUTO_INTERVAL_HOURS=' . $get('BACKUP_AUTO_INTERVAL_HOURS', '0'),
        'BACKUP_DIR=' . $get('BACKUP_DIR'),
        'BACKUP_CRON_SECRET=' . $get('BACKUP_CRON_SECRET'),
        '',
    ];

    file_put_contents(setup_env_path(), implode("\n", $lines));

    foreach ($newValues as $k => $v) {
        if ($v !== null) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

// --- Schema import ---

// Naive but sufficient statement-splitter for this app's own schema.sql:
// accumulates lines (skipping blank/comment-only ones) until one ends in
// ";" — safe because the file is entirely CREATE TABLE statements with
// no stored routines/triggers that would contain embedded semicolons.
function setup_run_schema(): void
{
    $sql = file_get_contents(__DIR__ . '/../sql/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read sql/schema.sql.');
    }
    $statements = [];
    $buffer = '';
    foreach (explode("\n", $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $buffer .= $line . "\n";
        if (str_ends_with($trimmed, ';')) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    $pdo = db();
    foreach ($statements as $stmt) {
        if ($stmt !== '') {
            $pdo->exec($stmt);
        }
    }
}

// --- Identity / audience-category seeding ---

// Every deployment needs at least one audience category — seeded with
// sensible generic defaults the admin can edit, reorder, or delete
// afterward via admin_settings.php. Safe to call unconditionally: a
// no-op if categories already exist.
function setup_seed_default_audience_modes(): void
{
    if (archive_audience_modes_rows()) {
        return;
    }
    $defaults = [
        ['label' => 'Young children (6-10)', 'guidance' => 'Keep it simple and reassuring: focus on the arc of someone who was loved, was helped by kind people, and built a good life. Acknowledge hard facts plainly but briefly, withhold graphic detail.'],
        ['label' => "Middle grade / b'nai mitzvah (11-14)", 'guidance' => 'The full arc, told with restraint and age-appropriate context.'],
        ['label' => 'Adults / family', 'guidance' => 'Everything, including the harder emotional aftermath and how the experience shaped later life.'],
        ['label' => 'Researchers / educators', 'guidance' => 'Verbatim transcript access, full citations, discrepancy notes, and historical context.'],
    ];
    $adultId = null;
    foreach ($defaults as $d) {
        $row = archive_audience_mode_create(['label' => $d['label'], 'ai_guidance' => $d['guidance']]);
        if ($d['label'] === 'Adults / family') {
            $adultId = $row['id'];
        }
    }
    if ($adultId) {
        archive_audience_mode_set_default($adultId);
    }
}

// Fills in the same small fictional example archive used throughout
// this project's own testing — offered in the wizard as an optional
// "see how it works" starting point, not applied unless the admin
// explicitly chooses it. Also seeds default audience categories, same
// as the plain identity-form path, since either path needs them.
function setup_load_demo_archive(): void
{
    setup_seed_default_audience_modes();

    archive_site_settings_update([
        'site_name' => 'Example Family Archive',
        'subject_name' => 'Jane Doe',
        'subject_pronoun_subject' => 'she',
        'subject_pronoun_object' => 'her',
        'subject_pronoun_possessive' => 'her',
        'subject_birth_date' => '~1928',
        'subject_birthplace' => 'Kraków, Poland',
        'subject_short_bio' => "A fictional example survivor, created to demonstrate this app's features. "
            . 'Youngest of four children; survived the war in hiding with a family friend before emigrating in 1949.',
        'closing_quote' => 'Every generation that remembers keeps the last one from being erased.',
        'closing_quote_attribution' => 'Jane Doe (example)',
        'ask_placeholder_text' => "Ask me anything about Jane's life, family, or testimony.",
    ]);

    archive_source_create([
        'label' => 'Family-recorded interview, 2010',
        'details' => 'Recorded by a grandchild at home, roughly 90 minutes, one session.',
    ]);

    archive_person_create(['names' => ['Jane Doe', 'Janina Kowalska'], 'role' => 'subject', 'fate' => 'Survived; emigrated 1949.', 'notes' => 'Youngest of four children. Hidden by a family friend, 1943-1945.', 'source_note' => 'primary interview']);
    archive_person_create(['names' => ['Mrs. Nowak'], 'role' => 'rescuer', 'fate' => 'Unknown after the war.', 'notes' => 'Hid Jane in a root cellar for over a year at great personal risk.', 'source_note' => 'primary interview']);

    archive_place_create(['names' => ['Kraków', 'Krakow'], 'wartime_country' => 'Poland (German-occupied)', 'modern_country' => 'Poland', 'role' => 'birthplace; family home', 'notes' => 'Family lived in the Jewish quarter before the ghetto was established.', 'source_note' => 'primary interview']);
    archive_place_create(['names' => ['Nowak family farm'], 'wartime_country' => 'Poland (German-occupied)', 'modern_country' => 'Poland', 'role' => 'hiding place, 1943-1945', 'notes' => 'A small farm outside the city where Jane was hidden.', 'source_note' => 'primary interview']);

    archive_timeline_create(['date_label' => '~1928', 'event' => 'Born in Kraków, youngest of four children.', 'source_note' => 'primary interview', 'confidence' => 'high']);
    archive_timeline_create(['date_label' => '1941', 'event' => 'Family forced into the Kraków ghetto.', 'source_note' => 'primary interview', 'confidence' => 'medium']);
    archive_timeline_create(['date_label' => '1943', 'event' => "Jane taken into hiding by Mrs. Nowak; rest of the family's fate uncertain after this point.", 'source_note' => 'primary interview', 'confidence' => 'medium']);
    archive_timeline_create(['date_label' => '1949', 'event' => 'Emigrated, eventually settling abroad.', 'source_note' => 'primary interview', 'confidence' => 'high']);

    archive_quote_create(['speaker' => 'Jane Doe', 'source_note' => 'Tape 1', 'tags' => ['childhood', 'family'], 'quote_text' => 'We did not have much, but the table was always full when it mattered.']);
    archive_quote_create(['speaker' => 'Jane Doe', 'source_note' => 'Tape 1', 'tags' => ['rescue', 'gratitude'], 'quote_text' => 'I did not have a word for what Mrs. Nowak did for a long time. I still am not sure I do.']);

    archive_primary_testimony_update([
        'interview_label' => 'Example family interview',
        'interview_date' => '2010-05-14',
        'location' => 'Home recording',
        'interviewer' => 'A grandchild',
        'length_label' => '00:22:10',
        'raw_markdown' => "## Tape 1\n\n**INTERVIEWER:** Can you tell me about your family?\n\n"
            . "**SUBJECT:** We did not have much, but the table was always full when it\nmattered. My mother made sure of that.\n\n"
            . "**INTERVIEWER:** And when things changed?\n\n**SUBJECT:** [PAUSES] It happened slowly, and then all at once.",
    ]);

    archive_discrepancy_notes_update([
        'content_markdown' => "## Example discrepancy note\n\nThis is a fictional example of how a discrepancy note might read. If two\n"
            . "sources disagree on a date or spelling, note it here rather than picking\none silently.\n\n"
            . '`~1943` is used deliberately as an approximate date across sources.',
    ]);
}

// --- Admin account creation ---

// Distinct from includes/users.php's user_create_pending() (which
// creates a 'pending' signup) — the wizard's own admin is trusted and
// approved immediately, since whoever completes setup is by definition
// this subject's owner. Guards against ever running once any account
// exists FOR THIS SUBJECT — a different subject already having an admin
// is expected and irrelevant here.
function setup_create_admin(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = strtolower(trim($email));
    if ($name === '') {
        throw new RuntimeException('Name is required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('A valid email is required.');
    }
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }
    $subjectId = require_current_subject()['id'];
    $pdo = db();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new RuntimeException('An account already exists for this subject — setup has already run for it.');
    }
    $id = make_uuid();
    $stmt = $pdo->prepare(
        "INSERT INTO users (id, subject_id, name, email, password_hash, role, status, approved_at) VALUES (?, ?, ?, ?, ?, 'admin', 'approved', NOW())"
    );
    $stmt->execute([$id, $subjectId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    return user_find_by_id($id);
}

// --- Finalize ---

// Marks setup complete without touching any other site_settings field —
// archive_site_settings_update()'s merge semantics (see includes/archive.php)
// mean an empty $fields array here just flips setup_completed_at and
// leaves everything already saved untouched.
function setup_finalize(): void
{
    archive_site_settings_update([], true);
}
