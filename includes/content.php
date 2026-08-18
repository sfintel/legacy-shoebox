<?php
declare(strict_types=1);

// Admin-added archive material (transcripts, photos, videos) beyond the
// fixed knowledge/ files, backed by content_items (one row per logical
// item — a transcript, a video, or a photo "album" sharing one title/
// caption) plus content_files (one row per physical file — always
// exactly one for transcripts/videos, one-or-more for photo albums),
// with the actual bytes under ARCHIVE_ROOT/uploads/{type}/. Mirrors
// includes/users.php's style. See includes/knowledge.php for how these
// feed the Ask tab's context.

// archive_normalize_tags() (shared with quotes.tags) only trims and
// drops empties — fine there, but a content-item keyword picker should
// never be able to produce visible duplicates in its own suggestion
// pool, so dedupe here rather than changing the shared helper's
// behavior for its other callers.
function content_normalize_tags(array $tags): array
{
    return array_values(array_unique(archive_normalize_tags($tags)));
}

function content_allowed_extensions(string $type): array
{
    return match ($type) {
        'photo' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'],
        'video' => ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'm4v' => 'video/x-m4v'],
        default => [],
    };
}

function content_upload_dir(string $type): string
{
    $dir = ARCHIVE_ROOT . "/uploads/$type";
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

// $ownerId scopes the list to items a specific user created — used for
// non-admin contributors, who can only see their own items (admins call
// this with no argument to see everything).
function content_all(?string $ownerId = null): array
{
    if ($ownerId !== null) {
        $stmt = db()->prepare('SELECT * FROM content_items WHERE created_by = ? ORDER BY created_at DESC');
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll();
    }
    return db()->query('SELECT * FROM content_items ORDER BY created_at DESC')->fetchAll();
}

function content_files_for_item(string $itemId): array
{
    $stmt = db()->prepare('SELECT * FROM content_files WHERE content_item_id = ? ORDER BY sort_order ASC');
    $stmt->execute([$itemId]);
    return $stmt->fetchAll();
}

function content_file_find(string $fileId): ?array
{
    $stmt = db()->prepare('SELECT * FROM content_files WHERE id = ?');
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Used to gate api/admin/content_file.php for non-admin contributors —
// they can only stream files belonging to items they created.
function content_file_belongs_to(array $file, string $ownerId): bool
{
    $item = content_find($file['content_item_id']);
    return $item !== null && $item['created_by'] === $ownerId;
}

// Shape returned to the admin UI — omits file_name (an internal disk
// path) and created_by.
function content_public(array $item): array
{
    return [
        'id' => $item['id'],
        'type' => $item['type'],
        'title' => $item['title'],
        'description' => $item['description'],
        'sourceUrl' => $item['source_url'] ?? null,
        'narrativeNote' => $item['narrative_note'],
        // `?? null`: a webroot can be deployed before upgrade.sh applies
        // the narrative_note_reviewed_at column (deploy.sh syncs code,
        // then runs the upgrade) — don't fatal in that window.
        'narrativeNoteReviewedAt' => $item['narrative_note_reviewed_at'] ?? null,
        'tags' => json_decode((string) ($item['tags'] ?? '[]'), true) ?: [],
        'createdAt' => $item['created_at'],
        'files' => array_map('content_file_public', content_files_for_item($item['id'])),
        'suggestions' => $item['type'] === 'url' ? content_suggestions_for_item($item['id']) : [],
        'storyApprovedAt' => $item['type'] === 'story' ? $item['story_approved_at'] : null,
    ];
}

function content_file_public(array $file): array
{
    $metadata = $file['metadata'] !== null ? json_decode($file['metadata'], true) : null;
    return [
        'id' => $file['id'],
        'originalName' => $file['original_name'],
        'mimeType' => $file['mime_type'],
        'fileSize' => (int) $file['file_size'],
        'metadata' => $metadata,
        'metadataSummary' => $metadata ? content_format_metadata_summary($metadata) : null,
    ];
}

// Pulls a curated subset of capture metadata (date taken, device, GPS,
// dimensions/duration) out of a photo or video file via exiftool, which
// reports normalized field names for both formats alike (confirmed
// empirically: DateTimeOriginal/CreateDate, Make, Model, GPSLatitude/
// GPSLongitude as plain signed decimals, ImageWidth/Height, Duration in
// seconds for video). Not every shared host allows shell_exec or has
// exiftool installed, so this degrades to no metadata rather than
// failing the upload — a missing camera tag is never an error.
function content_extract_metadata(string $path): ?array
{
    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) {
        return null;
    }

    $output = @shell_exec('exiftool -j -n ' . escapeshellarg($path) . ' 2>/dev/null');
    if (!$output) {
        return null;
    }
    $decoded = json_decode($output, true);
    if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
        return null;
    }
    $tags = $decoded[0];

    $rawDate = $tags['DateTimeOriginal'] ?? $tags['CreateDate'] ?? $tags['MediaCreateDate'] ?? null;
    $metadata = array_filter([
        'takenAt' => is_string($rawDate) ? content_normalize_exif_date($rawDate) : null,
        'make' => is_string($tags['Make'] ?? null) ? $tags['Make'] : null,
        'model' => is_string($tags['Model'] ?? null) ? $tags['Model'] : null,
        'gpsLat' => isset($tags['GPSLatitude']) && is_numeric($tags['GPSLatitude']) ? (float) $tags['GPSLatitude'] : null,
        'gpsLng' => isset($tags['GPSLongitude']) && is_numeric($tags['GPSLongitude']) ? (float) $tags['GPSLongitude'] : null,
        'width' => isset($tags['ImageWidth']) && is_numeric($tags['ImageWidth']) ? (int) $tags['ImageWidth'] : null,
        'height' => isset($tags['ImageHeight']) && is_numeric($tags['ImageHeight']) ? (int) $tags['ImageHeight'] : null,
        'durationSeconds' => isset($tags['Duration']) && is_numeric($tags['Duration']) ? (float) $tags['Duration'] : null,
    ], static fn ($value) => $value !== null);

    return $metadata ?: null;
}

// exiftool renders dates as "2024:06:02 14:31:00" — normalize the date
// portion's colons to hyphens for a standard, sortable timestamp.
function content_normalize_exif_date(string $raw): ?string
{
    if (!preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}:\d{2}:\d{2})/', $raw, $m)) {
        return null;
    }
    return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}";
}

function content_format_duration(float $seconds): string
{
    $total = (int) round($seconds);
    return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
}

function content_format_metadata_summary(array $metadata): string
{
    $parts = [];
    if (!empty($metadata['takenAt'])) {
        $parts[] = "taken {$metadata['takenAt']}";
    }
    $device = trim(($metadata['make'] ?? '') . ' ' . ($metadata['model'] ?? ''));
    if ($device !== '') {
        $parts[] = $device;
    }
    if (isset($metadata['gpsLat'], $metadata['gpsLng'])) {
        $parts[] = sprintf(
            'near %.5f, %.5f (https://www.google.com/maps?q=%.5f,%.5f)',
            $metadata['gpsLat'], $metadata['gpsLng'], $metadata['gpsLat'], $metadata['gpsLng']
        );
    }
    if (!empty($metadata['width']) && !empty($metadata['height'])) {
        $parts[] = "{$metadata['width']}\u{00d7}{$metadata['height']}";
    }
    if (!empty($metadata['durationSeconds'])) {
        $parts[] = content_format_duration((float) $metadata['durationSeconds']);
    }
    return implode(', ', $parts);
}

function content_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM content_items WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Converts PHP's parallel-array $_FILES['files'] shape (['name'][i],
// ['tmp_name'][i], ...) — what a browser produces for a multi-file
// <input>  — into a list of individual file arrays shaped like a normal
// single-file $_FILES entry.
function content_normalize_multi_files(array $filesField): array
{
    $count = count($filesField['name'] ?? []);
    $out = [];
    for ($i = 0; $i < $count; $i++) {
        if ((int) ($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE && $filesField['name'][$i] === '') {
            continue; // an empty file slot, not a real selection
        }
        $out[] = [
            'name' => $filesField['name'][$i],
            'type' => $filesField['type'][$i],
            'tmp_name' => $filesField['tmp_name'][$i],
            'error' => $filesField['error'][$i],
            'size' => $filesField['size'][$i],
        ];
    }
    return $out;
}

// Validates, saves, and extracts metadata for one uploaded photo/video
// file. Shared by the single-file video path and the per-photo loop in
// content_create_photo_album().
function content_store_uploaded_file(string $type, array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(content_upload_error_message($error));
    }

    $allowed = content_allowed_extensions($type);
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        throw new RuntimeException('Unsupported file type — allowed: ' . implode(', ', array_keys($allowed)));
    }

    // Trust the file's actual content over its extension/browser-supplied
    // MIME type — a renamed file shouldn't be able to claim to be a photo.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string) $finfo->file($file['tmp_name']);
    $expectedPrefix = $type === 'photo' ? 'image/' : 'video/';
    if (!str_starts_with($realMime, $expectedPrefix)) {
        throw new RuntimeException("The uploaded file doesn't look like a valid $type.");
    }

    $fileId = make_uuid();
    $fileName = "$fileId.$ext";
    $dir = content_upload_dir($type);
    $destPath = "$dir/$fileName";
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new RuntimeException('Failed to save the uploaded file.');
    }

    return [
        'fileId' => $fileId,
        'fileName' => $fileName,
        'destPath' => $destPath,
        'originalName' => (string) $file['name'],
        'mimeType' => $realMime,
        'size' => (int) $file['size'],
        'metadata' => content_extract_metadata($destPath),
    ];
}

function content_insert_file(PDO $pdo, string $itemId, array $stored, int $sortOrder): void
{
    $pdo->prepare(
        'INSERT INTO content_files (id, content_item_id, file_name, original_name, mime_type, file_size, metadata, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $stored['fileId'], $itemId, $stored['fileName'], $stored['originalName'], $stored['mimeType'],
        $stored['size'], $stored['metadata'] ? json_encode($stored['metadata']) : null, $sortOrder,
    ]);
}

// Basic SSRF guard for content_fetch_url() below — the URL comes from an
// authenticated, content-permitted family member, not the public, but the
// fetch itself is server-initiated, so a compromised/malicious account
// shouldn't be able to use it to probe the host's own network. Resolves
// the hostname and rejects anything that lands in a private/loopback/
// link-local range.
function content_is_safe_host(string $host): bool
{
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        return false; // DNS resolution failed
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Fetches a URL and extracts plain readable text for AI analysis — used
// by content_create_url() below. Unlike content_extract_metadata()'s
// exiftool call, a failed fetch is a real error here (there's nothing
// useful to store without it), so this throws rather than degrading.
function content_fetch_url(string $url): array
{
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('Please enter a valid http(s) URL.');
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' || !content_is_safe_host($host)) {
        throw new RuntimeException("That URL's host can't be reached.");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'FamilyArchiveBot/1.0 (+family archive content fetch)',
        CURLOPT_RANGE => '0-5000000', // cap response size a well-behaved server honors
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // No curl_close() — a no-op since PHP 8.0, deprecated as of PHP 8.5.

    if ($body === false || $httpCode >= 400) {
        throw new RuntimeException("Couldn't fetch that URL (HTTP $httpCode)." . ($curlError ? " $curlError" : ''));
    }
    if (strlen($body) > 5_000_000) {
        $body = substr($body, 0, 5_000_000);
    }

    $title = null;
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
        $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
    }

    $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);
    $text = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", $text)));
    if ($text === '') {
        throw new RuntimeException("That page didn't have any readable text to analyze.");
    }
    $truncated = strlen($text) > 20_000;
    if ($truncated) {
        $text = substr($text, 0, 20_000) . "\n\n[truncated]";
    }

    return ['title' => $title, 'text' => $text];
}

// Pasted transcript text is written to a .md file under
// ARCHIVE_ROOT/uploads/transcript/ so every item has a real file on
// disk — knowledge_context() and content_file.php never need a separate
// "text stored in the DB" code path.
function content_create_transcript(string $title, string $text, string $userId, array $tags = []): array
{
    $title = trim($title);
    $text = trim($text);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    if ($text === '') {
        throw new RuntimeException('Transcript text is required.');
    }

    $fileId = make_uuid();
    $fileName = "$fileId.md";
    $dir = content_upload_dir('transcript');
    file_put_contents("$dir/$fileName", $text);
    $analysis = narrative_analyze(narrative_prompt_for_transcript($title, $text));

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, narrative_note, tags, created_by)
         VALUES (?, \'transcript\', ?, NULL, ?, ?, ?)'
    )->execute([$itemId, $title, $analysis['note'], json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    content_insert_file($pdo, $itemId, [
        'fileId' => $fileId, 'fileName' => $fileName, 'originalName' => "$title.md",
        'mimeType' => 'text/markdown', 'size' => strlen($text), 'metadata' => null,
    ], 0);
    archive_content_links_apply($itemId, $analysis['links']);

    return content_find($itemId);
}

// A family-recounted story — told about the subject, not by them (that's
// what primary_testimony/transcripts are for). Treated as a level below
// direct testimony: held back from the Stories tab and the AI's
// knowledge base until an admin approves it (see content_approve_story()
// below), unlike every other content type which is visible immediately.
// No AI analysis runs at creation time — deliberately: computing
// content_links here would let an unapproved story's title/connections
// leak into other entries' relatedContent before anyone's reviewed it.
function content_create_story(string $title, string $body, string $userId, array $tags = []): array
{
    $title = trim($title);
    $body = trim($body);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    if ($body === '') {
        throw new RuntimeException('Story text is required.');
    }

    $fileId = make_uuid();
    $fileName = "$fileId.md";
    $dir = content_upload_dir('story');
    file_put_contents("$dir/$fileName", $body);

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, narrative_note, tags, story_approved_at, created_by)
         VALUES (?, \'story\', ?, NULL, NULL, ?, NULL, ?)'
    )->execute([$itemId, $title, json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    content_insert_file($pdo, $itemId, [
        'fileId' => $fileId, 'fileName' => $fileName, 'originalName' => "$title.md",
        'mimeType' => 'text/markdown', 'size' => strlen($body), 'metadata' => null,
    ], 0);

    return content_find($itemId);
}

// Same fail-quiet posture as api/signup.php's admin notification email
// (which this mirrors): a mail hiccup is logged, never thrown — the
// story itself is already saved by the time this runs, so a failed
// email must not look like the submission failed.
function content_notify_story_pending(array $item, array $author): void
{
    $notifyEmail = env('NOTIFY_EMAIL') ?: user_first_admin_email();
    if (!$notifyEmail) {
        error_log('NOTIFY_EMAIL not set and no admin account exists yet — skipping story-pending notification email.');
        return;
    }
    $reviewUrl = APP_URL . '/admin_content.php';
    try {
        send_mail(
            $notifyEmail,
            mail_subject('Story pending approval: ' . $item['title']),
            "{$author['name']} ({$author['email']}) submitted a new story: \"{$item['title']}\".\n\n"
                . "It won't appear on the Stories tab or in the Ask tab's knowledge base until you "
                . "approve it at $reviewUrl",
            '<p><strong>' . h($author['name']) . '</strong> (' . h($author['email']) . ') submitted a new story: '
                . '"' . h($item['title']) . '".</p>'
                . '<p>It won\'t appear on the Stories tab or in the Ask tab\'s knowledge base until you approve it '
                . 'at <a href="' . h($reviewUrl) . '">' . h($reviewUrl) . '</a></p>'
        );
    } catch (Throwable $e) {
        error_log('Failed to send story-pending notification email: ' . $e->getMessage());
    }
}

function content_story_body(array $item): string
{
    $file = content_files_for_item($item['id'])[0] ?? null;
    $path = $file ? content_upload_dir('story') . '/' . $file['file_name'] : null;
    return $path && is_file($path) ? (string) file_get_contents($path) : '';
}

// Runs the same narrative-connection analysis every other content type
// gets at creation time, but deferred until now — see the comment on
// content_create_story() above for why. Idempotent-safe to re-run
// (content_links has a UNIQUE KEY, same as content_backfill_narrative_notes()).
function content_approve_story(string $itemId): array
{
    $item = content_find($itemId);
    if ($item === null || $item['type'] !== 'story') {
        throw new RuntimeException('Story not found.');
    }
    $body = content_story_body($item);
    $analysis = narrative_analyze(narrative_prompt_for_story($item['title'], $body));

    $pdo = db();
    // narrative_note_reviewed_at reset to NULL here — this note is fresh
    // unattended AI output, even if an admin had marked a since-replaced
    // note reviewed while the story was still pending. See the schema
    // comment on narrative_note_reviewed_at.
    $pdo->prepare('UPDATE content_items SET narrative_note = ?, story_approved_at = NOW(), narrative_note_reviewed_at = NULL WHERE id = ?')
        ->execute([$analysis['note'], $itemId]);
    archive_content_links_clear_for_item($itemId);
    archive_content_links_apply($itemId, $analysis['links']);

    return content_find($itemId);
}

function content_stories_pending(): array
{
    return db()->query("SELECT * FROM content_items WHERE type = 'story' AND story_approved_at IS NULL ORDER BY created_at ASC")->fetchAll();
}

// Oldest-first, matching content_context()'s own reasoning for why
// family-added material reads oldest-first (so it reads as later
// context, not because recency matters here more than there).
function content_stories_approved(): array
{
    return db()->query("SELECT * FROM content_items WHERE type = 'story' AND story_approved_at IS NOT NULL ORDER BY created_at ASC")->fetchAll();
}

function content_story_public(array $item): array
{
    return [
        'id' => $item['id'],
        'title' => $item['title'],
        'body' => content_story_body($item),
        'tags' => json_decode((string) ($item['tags'] ?? '[]'), true) ?: [],
        'createdAt' => $item['created_at'],
    ];
}

// Fetches $url, stores its extracted text as a .md file (same "always a
// real file on disk" posture as content_create_transcript()), runs the
// existing narrative connection note, and separately asks
// narrative_suggest_additions() (includes/narrative.php) for structured
// timeline/person/place/quote proposals — stored as pending
// content_suggestions rows, never applied until an admin approves each
// one via includes/knowledge_writer.php.
function content_create_url(string $title, string $url, string $userId, array $tags = []): array
{
    $title = trim($title);
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException('URL is required.');
    }
    $fetched = content_fetch_url($url); // throws on failure
    if ($title === '') {
        $title = $fetched['title'] ?? $url;
    }

    $fileId = make_uuid();
    $fileName = "$fileId.md";
    $dir = content_upload_dir('url');
    file_put_contents("$dir/$fileName", "Source: $url\n\n" . $fetched['text']);
    $analysis = narrative_analyze(narrative_prompt_for_url($title, $url, $fetched['text']));

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, source_url, narrative_note, tags, created_by)
         VALUES (?, \'url\', ?, NULL, ?, ?, ?, ?)'
    )->execute([$itemId, $title, $url, $analysis['note'], json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    content_insert_file($pdo, $itemId, [
        'fileId' => $fileId, 'fileName' => $fileName, 'originalName' => "$title.md",
        'mimeType' => 'text/markdown', 'size' => strlen($fetched['text']), 'metadata' => null,
    ], 0);
    archive_content_links_apply($itemId, $analysis['links']);

    $suggestions = narrative_suggest_additions($title, $url, $fetched['text']);
    content_suggestions_insert($itemId, $suggestions);

    return content_find($itemId);
}

// One row per suggestion the model returned, each pending admin review —
// see api/admin/content_suggestions.php and includes/knowledge_writer.php.
function content_suggestions_insert(string $itemId, array $suggestions): void
{
    if (!$suggestions) {
        return;
    }
    $stmt = db()->prepare(
        'INSERT INTO content_suggestions (id, content_item_id, kind, payload) VALUES (?, ?, ?, ?)'
    );
    foreach ($suggestions as $s) {
        $stmt->execute([make_uuid(), $itemId, $s['kind'], json_encode($s['fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
}

function content_suggestions_for_item(string $itemId): array
{
    $stmt = db()->prepare('SELECT * FROM content_suggestions WHERE content_item_id = ? ORDER BY created_at ASC');
    $stmt->execute([$itemId]);
    return array_map('content_suggestion_public', $stmt->fetchAll());
}

function content_suggestion_public(array $row): array
{
    return [
        'id' => $row['id'],
        'kind' => $row['kind'],
        'fields' => json_decode($row['payload'], true),
        'status' => $row['status'],
        'createdAt' => $row['created_at'],
    ];
}

function content_suggestion_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM content_suggestions WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function content_suggestion_set_status(string $id, string $status, string $deciderId): void
{
    $stmt = db()->prepare('UPDATE content_suggestions SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?');
    $stmt->execute([$status, $deciderId, $id]);
}

function content_create_video(string $title, ?string $description, array $file, string $userId, array $tags = []): array
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }

    $stored = content_store_uploaded_file('video', $file);
    $description = $description !== null ? trim($description) : '';
    $analysis = narrative_analyze(
        narrative_prompt_for_media($title, $description !== '' ? $description : null, $stored['metadata'])
    );

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, narrative_note, tags, created_by)
         VALUES (?, \'video\', ?, ?, ?, ?, ?)'
    )->execute([$itemId, $title, $description !== '' ? $description : null, $analysis['note'], json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    content_insert_file($pdo, $itemId, $stored, 0);
    archive_content_links_apply($itemId, $analysis['links']);

    return content_find($itemId);
}

// $files is a list of normalized upload arrays (content_normalize_multi_files()),
// one per selected photo. All photos share one title/caption and get a
// single combined narrative analysis considering them together, rather
// than N independent ones.
function content_create_photo_album(string $title, ?string $description, array $files, string $userId, array $tags = []): array
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    if (!$files) {
        throw new RuntimeException('At least one photo is required.');
    }
    if (count($files) > 10) {
        throw new RuntimeException('Please add at most 10 photos at a time.');
    }

    $stored = [];
    foreach ($files as $file) {
        $stored[] = content_store_uploaded_file('photo', $file);
    }
    $description = $description !== null ? trim($description) : '';

    $imageBlocks = [];
    foreach ($stored as $s) {
        $block = narrative_build_image_block($s['destPath']);
        if ($block) {
            $imageBlocks[] = $block;
        }
    }
    $metadataList = array_map(static fn ($s) => $s['metadata'], $stored);
    $analysis = narrative_analyze(
        narrative_prompt_for_photo_album($title, $description !== '' ? $description : null, $metadataList),
        $imageBlocks
    );

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, narrative_note, tags, created_by)
         VALUES (?, \'photo\', ?, ?, ?, ?, ?)'
    )->execute([$itemId, $title, $description !== '' ? $description : null, $analysis['note'], json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    foreach ($stored as $i => $s) {
        content_insert_file($pdo, $itemId, $s, $i);
    }
    archive_content_links_apply($itemId, $analysis['links']);

    return content_find($itemId);
}

// $ownerId, when given, restricts deletion to an item created by that
// user — used for non-admin contributors, who can only delete their own
// items. Returns false (not an error) for "not found" and "not yours"
// alike, so a non-admin can't tell the two apart via the API response.
function content_delete(string $id, ?string $ownerId = null): bool
{
    $item = content_find($id);
    if (!$item || ($ownerId !== null && $item['created_by'] !== $ownerId)) {
        return false;
    }
    foreach (content_files_for_item($id) as $file) {
        $path = content_upload_dir($item['type']) . '/' . $file['file_name'];
        if (is_file($path)) {
            unlink($path);
        }
    }
    // Deleting the parent row cascades the content_files rows in the DB
    // via fk_content_files_item's ON DELETE CASCADE.
    $stmt = db()->prepare('DELETE FROM content_items WHERE id = ?');
    $stmt->execute([$id]);
    return true;
}

// Merges admin-editable fields into a file's existing metadata, rather
// than replacing it outright — width/height (and anything else not on
// this list) are never part of the edit form, so they must survive
// untouched. An empty string/null for an editable key removes it; a
// non-empty value sets it (numeric keys validated and cast to float).
// takenAt/make/model are deliberately not format-validated — a family
// member may only know an approximate date like "Summer 1975", which is
// more useful than forcing a precise timestamp.
function content_merge_metadata_update(?array $current, array $incoming): ?array
{
    $merged = $current ?? [];
    $numericKeys = ['gpsLat', 'gpsLng', 'durationSeconds'];
    foreach (['takenAt', 'make', 'model', 'gpsLat', 'gpsLng', 'durationSeconds'] as $key) {
        if (!array_key_exists($key, $incoming)) {
            continue;
        }
        $value = $incoming[$key];
        if ($value === null || $value === '') {
            unset($merged[$key]);
            continue;
        }
        if (in_array($key, $numericKeys, true)) {
            if (!is_numeric($value)) {
                throw new RuntimeException(ucfirst($key) . ' must be a number.');
            }
            $merged[$key] = (float) $value;
        } else {
            $merged[$key] = trim((string) $value);
        }
    }
    return $merged ?: null;
}

// $ownerId, when given, restricts editing to an item created by that
// user — same posture as content_delete(). $fileUpdates is a list of
// {id, metadata} — each file id is only honored if it actually belongs
// to $id, so one item's edit request can never touch another item's
// (or another user's) file. $isAdmin is passed explicitly rather than
// inferred from $ownerId === null, so that inference doesn't silently
// become load-bearing for narrative_note_reviewed_at too. Only an
// admin's action ever sets narrative_note_reviewed_at — this feature is
// about admin oversight of AI text, and a non-admin author saving their
// own item says nothing about that. It's set when either the submitted
// note text actually differs from what's stored (a human edit is
// definitionally a review) or $markReviewed is explicitly true (an
// admin confirming a note as-is, via a "Mark reviewed" control) — an
// unrelated save of the same unmodified text does NOT set it, since
// that would turn "unknown" into a false "checked".
function content_update_item(string $id, ?string $ownerId, ?string $narrativeNote, array $fileUpdates, ?array $tags = null, bool $isAdmin = false, bool $markReviewed = false): array
{
    $item = content_find($id);
    if (!$item || ($ownerId !== null && $item['created_by'] !== $ownerId)) {
        throw new RuntimeException('Content item not found.');
    }

    if ($narrativeNote !== null) {
        $note = trim($narrativeNote);
        $note = $note !== '' ? $note : null;
        $noteChanged = $note !== $item['narrative_note'];
        if ($isAdmin && ($noteChanged || $markReviewed)) {
            db()->prepare('UPDATE content_items SET narrative_note = ?, narrative_note_reviewed_at = NOW() WHERE id = ?')
                ->execute([$note, $id]);
        } else {
            db()->prepare('UPDATE content_items SET narrative_note = ? WHERE id = ?')
                ->execute([$note, $id]);
        }
    } elseif ($isAdmin && $markReviewed) {
        db()->prepare('UPDATE content_items SET narrative_note_reviewed_at = NOW() WHERE id = ?')
            ->execute([$id]);
    }

    if ($tags !== null) {
        $stmt = db()->prepare('UPDATE content_items SET tags = ? WHERE id = ?');
        $stmt->execute([json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $id]);
    }

    if ($fileUpdates) {
        $ownFiles = [];
        foreach (content_files_for_item($id) as $file) {
            $ownFiles[$file['id']] = $file;
        }
        foreach ($fileUpdates as $update) {
            $fileId = (string) ($update['id'] ?? '');
            if (!isset($ownFiles[$fileId])) {
                continue;
            }
            $current = $ownFiles[$fileId]['metadata'] !== null ? json_decode($ownFiles[$fileId]['metadata'], true) : null;
            $incoming = is_array($update['metadata'] ?? null) ? $update['metadata'] : [];
            $merged = content_merge_metadata_update($current, $incoming);
            $stmt = db()->prepare('UPDATE content_files SET metadata = ? WHERE id = ?');
            $stmt->execute([$merged ? json_encode($merged) : null, $fileId]);
        }
    }

    return content_find($id);
}

// Runs the same narrative_analyze() pass content_create_*() runs on
// upload, but against an already-persisted row — used for items added
// before this feature existed, or where the original call failed/the
// API key was unset at the time. Works the same for a single-photo item
// and a multi-photo album, since both are just content_files rows.
// Returns the same ['note' => ..., 'links' => ...] shape as
// narrative_analyze() itself.
function content_analyze_existing_item(array $item): array
{
    $empty = ['note' => null, 'links' => []];
    $files = content_files_for_item($item['id']);

    if ($item['type'] === 'transcript') {
        $file = $files[0] ?? null;
        if (!$file) {
            return $empty;
        }
        $path = content_upload_dir('transcript') . '/' . $file['file_name'];
        $text = is_file($path) ? file_get_contents($path) : null;
        return $text !== null && $text !== false
            ? narrative_analyze(narrative_prompt_for_transcript($item['title'], $text))
            : $empty;
    }

    if ($item['type'] === 'video') {
        $file = $files[0] ?? null;
        $metadata = $file && $file['metadata'] !== null ? json_decode($file['metadata'], true) : null;
        return narrative_analyze(narrative_prompt_for_media($item['title'], $item['description'], $metadata));
    }

    if ($item['type'] === 'url') {
        $file = $files[0] ?? null;
        if (!$file) {
            return $empty;
        }
        $path = content_upload_dir('url') . '/' . $file['file_name'];
        $text = is_file($path) ? file_get_contents($path) : null;
        return $text !== null && $text !== false
            ? narrative_analyze(narrative_prompt_for_url($item['title'], (string) $item['source_url'], $text))
            : $empty;
    }

    // photo album
    $imageBlocks = [];
    $metadataList = [];
    foreach ($files as $file) {
        $path = content_upload_dir('photo') . '/' . $file['file_name'];
        if (is_file($path)) {
            $block = narrative_build_image_block($path);
            if ($block) {
                $imageBlocks[] = $block;
            }
        }
        $metadataList[] = $file['metadata'] !== null ? json_decode($file['metadata'], true) : null;
    }
    return narrative_analyze(
        narrative_prompt_for_photo_album($item['title'], $item['description'], $metadataList),
        $imageBlocks
    );
}

// Fills in narrative_note for every existing item that doesn't have one
// yet, and (re-)applies its content_links. Safe to re-run — only
// touches rows where narrative_note IS NULL, so it never overwrites a
// note (or a deliberately-confirmed "no connection found") already
// stored; links are cleared and reapplied each time it processes an
// item, so a second run against the same still-note-less item doesn't
// accumulate duplicate links.
function content_backfill_narrative_notes(): array
{
    // Excludes type='story': a pending (unapproved) story has
    // narrative_note = NULL by design, same as anything else this query
    // targets — but a story is only ever analyzed via
    // content_approve_story(), never backfilled. An approved story
    // already has its narrative_note set by that function, so it's
    // naturally excluded too (nothing left to backfill for it).
    $items = db()->query("SELECT * FROM content_items WHERE narrative_note IS NULL AND type != 'story' ORDER BY created_at ASC")->fetchAll();

    $results = [];
    foreach ($items as $item) {
        $analysis = content_analyze_existing_item($item);
        $note = $analysis['note'];
        if ($note !== null) {
            // Reset (not just leave) narrative_note_reviewed_at — this
            // row's note was NULL a moment ago (this query's own WHERE
            // clause), but reviewed_at can still be non-NULL if an admin
            // cleared the note by hand and that save marked it reviewed.
            // See the schema comment on narrative_note_reviewed_at.
            $update = db()->prepare('UPDATE content_items SET narrative_note = ?, narrative_note_reviewed_at = NULL WHERE id = ?');
            $update->execute([$note, $item['id']]);
        }
        archive_content_links_clear_for_item($item['id']);
        archive_content_links_apply($item['id'], $analysis['links']);
        $results[] = ['id' => $item['id'], 'title' => $item['title'], 'updated' => $note !== null];
    }
    return $results;
}

function content_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded — try again.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        default => 'File upload failed.',
    };
}
