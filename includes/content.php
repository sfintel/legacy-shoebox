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
// behavior for its other callers. Also the single choke point every
// content_items.tags write passes through, so it doubles as where a
// newly-typed keyword joins the app-wide master list (see
// includes/keywords.php) — every caller already computes this right
// before an INSERT/UPDATE, so there's no separate call site to remember.
function content_normalize_tags(array $tags): array
{
    $tags = array_values(array_unique(archive_normalize_tags($tags)));
    keywords_ensure($tags);
    return $tags;
}

function content_allowed_extensions(string $type): array
{
    return match ($type) {
        'photo' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'],
        'video' => ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'm4v' => 'video/x-m4v'],
        'audio' => ['mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg'],
        // doc/docx deliberately excluded — finfo often can't reliably tell
        // a .docx (a zip container) apart from a plain .zip, which would
        // make the MIME check below either falsely reject real .docx
        // files or falsely accept unrelated zips. pdf/image/txt cover the
        // real use case (permission letters, scanned clippings, plain-text
        // emails) without that risk.
        'document' => ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain'],
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

// Streams a file to the browser with HTTP Range support (api/file.php
// and api/admin/content_file.php both call this for every photo/video
// they serve). Without it, a <video> element cannot seek at all — not
// via user scrubbing, and not via a #t=N media-fragment URL (see
// includes/video_seek.php) — because seeking a served video requires the
// server to support partial (206) byte-range responses; a browser that
// gets a plain 200 with no Accept-Ranges header always starts playback
// at 0:00 and can't jump anywhere else, regardless of what the URL asks
// for. Ends the request itself (same convention as json_response()).
function content_stream_file(string $path, string $mimeType, string $originalName): void
{
    $safeName = str_replace(['"', "\r", "\n"], '', $originalName);
    $size = filesize($path);

    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: inline; filename="' . $safeName . '"');
    header('Accept-Ranges: bytes');

    $start = 0;
    $end = $size - 1;
    $rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;

    if ($rangeHeader !== null && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
        $reqStart = $m[1] === '' ? null : (int) $m[1];
        $reqEnd = $m[2] === '' ? null : (int) $m[2];

        if ($reqStart === null && $reqEnd !== null) {
            // Suffix range ("last N bytes") — rare in practice for video
            // scrubbing, but part of the spec.
            $start = max(0, $size - $reqEnd);
        } else {
            $start = $reqStart ?? 0;
            $end = $reqEnd !== null ? min($reqEnd, $size - 1) : $end;
        }

        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    $length = $end - $start + 1;
    header('Content-Length: ' . (string) $length);

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit;
    }
    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = (int) min(8192, $remaining);
        echo fread($handle, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($handle);
    exit;
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
        'suggestions' => in_array($item['type'], ['url', 'transcript', 'story', 'document', 'photo'], true) ? content_suggestions_for_item($item['id']) : [],
        'storyApprovedAt' => $item['type'] === 'story' ? $item['story_approved_at'] : null,
        // `?? null`: same deploy-before-upgrade window as narrativeNoteReviewedAt above.
        'linkedItemId' => $item['linked_item_id'] ?? null,
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

// Best-effort text extraction from a PDF's own embedded text layer, via
// poppler-utils' pdftotext (shelled out, same optional-binary posture as
// content_extract_metadata()'s exiftool call above — degrades to null,
// never an error, if shell_exec is disabled or the binary isn't
// installed). `-layout` preserves the PDF's rough visual layout
// (columns, spacing) rather than reflowing everything into one run-on
// paragraph, which reads better for a typical letter/document. Deliberately
// NOT OCR — a purely scanned/image-only PDF with no real text layer
// returns empty output here, same as this app's existing "described, not
// OCR'd" limitation for a Document's attached scan (see
// content_create_document()) or a Photo's caption.
function content_extract_pdf_text(string $path): ?string
{
    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) {
        return null;
    }
    // Trailing "-" tells pdftotext to write to stdout instead of a
    // sibling .txt file, so no temp output file needs cleanup.
    $output = @shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' - 2>/dev/null');
    if ($output === null) {
        return null;
    }
    $text = trim($output);
    return $text !== '' ? $text : null;
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
    // MIME type — a renamed file shouldn't be able to claim to be a
    // photo/video. Photo and video each share one MIME prefix, so a
    // prefix check is enough; document's allowed set is heterogeneous
    // (pdf/image/text), so it's checked against the full allowed-MIME
    // list instead.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string) $finfo->file($file['tmp_name']);
    if ($type === 'document') {
        if (!in_array($realMime, $allowed, true)) {
            throw new RuntimeException("The uploaded file doesn't look like a valid document.");
        }
    } else {
        $expectedPrefix = match ($type) {
            'photo' => 'image/',
            'audio' => 'audio/',
            default => 'video/',
        };
        if (!str_starts_with($realMime, $expectedPrefix)) {
            throw new RuntimeException("The uploaded file doesn't look like a valid $type.");
        }
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

// Reverse of content_allowed_extensions() — used when a downloaded file
// has no extension to trust (see content_download_media_from_url()), so
// the real (sniffed) MIME type picks the extension instead of a URL's
// claimed one.
function content_extension_for_mime(string $type, string $mime): ?string
{
    $ext = array_search($mime, content_allowed_extensions($type), true);
    return $ext !== false ? $ext : null;
}

// Server-side download of a photo/video from a URL — for when a family
// member has a link (cloud storage, a CDN) rather than a local file.
// Sidesteps browser upload size/timeout limits entirely, since this is
// an outgoing fetch, not an incoming POST — post_max_size doesn't apply
// here. Streams straight to disk (CURLOPT_FILE) rather than buffering in
// PHP memory, since a video can be hundreds of MB; $maxBytes exists only
// to stop a malicious/misconfigured URL from filling the disk, enforced
// mid-transfer via CURLOPT_XFERINFOFUNCTION (abort, not truncate — a
// truncated video file is useless either way). Returns the same shape as
// content_store_uploaded_file() so both are interchangeable to callers.
// Redirects are walked manually with content_is_safe_host() re-checked
// on every hop — see content_fetch_url_body()'s doc comment for why.
function content_download_media_from_url(string $type, string $url, int $maxRedirects = 5): array
{
    $maxBytes = 1_500_000_000; // 1.5GB

    $tmpPath = tempnam(sys_get_temp_dir(), 'media_dl_');
    $fh = fopen($tmpPath, 'wb');
    if ($tmpPath === false || $fh === false) {
        throw new RuntimeException('Could not create a temporary file for the download.');
    }

    try {
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
                throw new RuntimeException('Please enter a valid http(s) URL.');
            }
            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host === '' || !content_is_safe_host($host)) {
                throw new RuntimeException("That URL's host can't be reached.");
            }

            rewind($fh);
            ftruncate($fh, 0);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 300, // comfortably under FPM's request_terminate_timeout
                CURLOPT_USERAGENT => 'FamilyArchiveBot/1.0 (+family archive content fetch)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_NOPROGRESS => false,
                CURLOPT_XFERINFOFUNCTION => static function ($res, $dlSize, $downloaded) use ($maxBytes): int {
                    return $downloaded > $maxBytes ? 1 : 0; // non-zero aborts the transfer
                },
            ]);
            $ok = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
                if ($location === '') {
                    throw new RuntimeException('The server redirected without a destination.');
                }
                $url = $location;
                continue;
            }
            if ($ok === false || $httpCode >= 400) {
                $reason = $curlError ?: "HTTP $httpCode";
                throw new RuntimeException("Couldn't download that URL ($reason).");
            }

            fclose($fh);
            $size = filesize($tmpPath);
            if ($size === false || $size === 0) {
                throw new RuntimeException('The download completed but the file is empty.');
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $realMime = (string) $finfo->file($tmpPath);
            $expectedPrefix = match ($type) {
                'photo' => 'image/',
                'audio' => 'audio/',
                default => 'video/',
            };
            if (!str_starts_with($realMime, $expectedPrefix)) {
                throw new RuntimeException("That URL doesn't look like a valid $type.");
            }
            $ext = content_extension_for_mime($type, $realMime);
            if ($ext === null) {
                throw new RuntimeException("Unsupported $type format ($realMime).");
            }

            $fileId = make_uuid();
            $fileName = "$fileId.$ext";
            $destPath = content_upload_dir($type) . "/$fileName";
            if (!rename($tmpPath, $destPath)) {
                throw new RuntimeException('Failed to save the downloaded file.');
            }

            $urlPath = (string) parse_url($url, PHP_URL_PATH);
            $originalName = $urlPath !== '' && $urlPath !== '/' ? basename($urlPath) : "downloaded.$ext";

            return [
                'fileId' => $fileId,
                'fileName' => $fileName,
                'destPath' => $destPath,
                'originalName' => $originalName,
                'mimeType' => $realMime,
                'size' => (int) $size,
                'metadata' => content_extract_metadata($destPath),
            ];
        }
        throw new RuntimeException('Too many redirects.');
    } finally {
        if (is_resource($fh)) {
            fclose($fh);
        }
        if (is_file($tmpPath)) {
            @unlink($tmpPath); // no-op once renamed into place; cleans up on any thrown error
        }
    }
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

// Basic SSRF guard shared by every server-initiated fetch in this file
// (content_fetch_url_body(), content_download_media_from_url()) — the
// URL comes from an authenticated, content-permitted family member, not
// the public, but the fetch itself is server-initiated, so a
// compromised/malicious account shouldn't be able to use it to probe
// the host's own network. Resolves the hostname and rejects anything
// that lands in a private/loopback/link-local range. Callers must
// re-check this on every redirect hop, not just the original URL — see
// content_fetch_url_body()'s doc comment.
function content_is_safe_host(string $host): bool
{
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        return false; // DNS resolution failed
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Walks up to $maxRedirects redirect hops for a GET request, buffered in
// memory (fine here — capped at ~5MB via CURLOPT_RANGE, a well-behaved
// server honoring it). CURLOPT_FOLLOWLOCATION is deliberately NOT used:
// it would follow a redirect without content_is_safe_host() ever seeing
// the new host, so an initially-safe URL could 302 to a private/internal
// target and this SSRF guard would never fire. Each hop is validated
// exactly like the first one instead.
function content_fetch_url_body(string $url, int $maxRedirects = 5): string
{
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
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
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'FamilyArchiveBot/1.0 (+family archive content fetch)',
            CURLOPT_RANGE => '0-5000000', // cap response size a well-behaved server honors
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        // No curl_close() — a no-op since PHP 8.0, deprecated as of PHP 8.5.

        if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            if ($location === '') {
                throw new RuntimeException('The server redirected without a destination.');
            }
            $url = $location;
            continue;
        }
        if ($body === false || $httpCode >= 400) {
            throw new RuntimeException("Couldn't fetch that URL (HTTP $httpCode)." . ($curlError ? " $curlError" : ''));
        }
        return $body;
    }
    throw new RuntimeException('Too many redirects.');
}

// Fetches a URL and extracts plain readable text for AI analysis — used
// by content_create_url() below. Unlike content_extract_metadata()'s
// exiftool call, a failed fetch is a real error here (there's nothing
// useful to store without it), so this throws rather than degrading.
function content_fetch_url(string $url): array
{
    $body = content_fetch_url_body($url);
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
// "text stored in the DB" code path. Also runs narrative_suggest_additions()
// (same structured timeline/person/place/quote extraction pass
// content_create_url() runs) — see that function's docblock.
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
    content_auto_link_attempt($itemId);

    $suggestions = narrative_suggest_additions(
        narrative_prompt_for_transcript_suggestions($title, $text),
        $title,
        'AI-suggested from a family-contributed transcript'
    );
    content_suggestions_insert($itemId, $suggestions);

    return content_find($itemId);
}

// Correspondence, permission letters, and similar non-testimony written
// material — distinct from Transcript (the subject's own spoken words)
// even though both start as pasted text written to a .md file the same
// way (see content_create_transcript()'s docblock for why). $file is an
// OPTIONAL second attachment — e.g. a scan or PDF of the actual letter —
// kept purely for provenance: the model only ever reads the pasted
// $text, never the attachment itself (no OCR, same limitation as Photo's
// description-only visibility). Gets the same suggestion-extraction pass
// as Transcript/URL/Story — a document is usually factual written
// material worth mining for archive facts, not an inert attachment like
// Photo/Video. No content_auto_link_attempt() call — video<->transcript
// linking doesn't apply to documents.
function content_create_document(string $title, string $text, ?array $file, string $userId, array $tags = []): array
{
    $title = trim($title);
    $text = trim($text);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    if ($text === '') {
        throw new RuntimeException('Document text is required.');
    }

    $textFileId = make_uuid();
    $textFileName = "$textFileId.md";
    $dir = content_upload_dir('document');
    file_put_contents("$dir/$textFileName", $text);
    $analysis = narrative_analyze(narrative_prompt_for_document($title, $text));

    $stored = $file !== null ? content_store_uploaded_file('document', $file) : null;

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, narrative_note, tags, created_by)
         VALUES (?, \'document\', ?, NULL, ?, ?, ?)'
    )->execute([$itemId, $title, $analysis['note'], json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    content_insert_file($pdo, $itemId, [
        'fileId' => $textFileId, 'fileName' => $textFileName, 'originalName' => "$title.md",
        'mimeType' => 'text/markdown', 'size' => strlen($text), 'metadata' => null,
    ], 0);
    if ($stored) {
        content_insert_file($pdo, $itemId, $stored, 1);
    }
    archive_content_links_apply($itemId, $analysis['links']);

    $suggestions = narrative_suggest_additions(
        narrative_prompt_for_document_suggestions($title, $text),
        $title,
        'AI-suggested from a family-contributed document'
    );
    content_suggestions_insert($itemId, $suggestions);

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
// content_create_story() above for why. Also runs the structured
// suggestion-extraction pass (narrative_suggest_additions()), same as
// content_create_url()/content_create_transcript() — but only if this
// item has no suggestions yet, since (unlike content_links, which is
// UNIQUE-KEY-deduped) content_suggestions has no such guard and this
// function has no hard lock against being called twice on an
// already-approved item.
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

    if (!content_suggestions_for_item($itemId)) {
        $suggestions = narrative_suggest_additions(
            narrative_prompt_for_story_suggestions($item['title'], $body),
            $item['title'],
            'AI-suggested from a family-recounted story'
        );
        content_suggestions_insert($itemId, $suggestions);
    }
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

    $suggestions = narrative_suggest_additions(
        narrative_prompt_for_url_suggestions($title, $url, $fetched['text']),
        "$title — $url",
        'AI-suggested from a submitted URL'
    );
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

// Exactly one of $file (a normalized upload array) or $sourceUrl must be
// given — $sourceUrl routes through content_download_media_from_url()
// instead of content_store_uploaded_file(), for a video too large or
// slow to upload through the browser (post_max_size/timeouts).
function content_create_video(string $title, ?string $description, ?array $file, string $userId, array $tags = [], ?string $sourceUrl = null): array
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    $sourceUrl = $sourceUrl !== null ? trim($sourceUrl) : null;
    $hasFile = $file !== null;
    $hasUrl = $sourceUrl !== null && $sourceUrl !== '';
    if ($hasFile === $hasUrl) {
        throw new RuntimeException('Provide exactly one: a video file or a URL to download from.');
    }

    $stored = $hasUrl ? content_download_media_from_url('video', $sourceUrl) : content_store_uploaded_file('video', $file);
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
    content_auto_link_attempt($itemId);

    return content_find($itemId);
}

// Audio-only recording (an interview or similar with no accompanying
// video) — otherwise an exact mirror of content_create_video() above,
// down to the file-vs-URL mutual exclusivity and auto-link attempt.
// Kept as its own function rather than parameterizing
// content_create_video() by type, matching this file's existing
// convention of one small function per content flavor
// (content_create_photo_album/_document/_url/_story alongside it) —
// deliberately not `[[video:ID]]`-style Ask-tab citation-seek capable
// (see includes/video_seek.php, which stays video-only); an audio item
// only stores/links to its transcript, same as video did before v1.18.0
// added seeking.
function content_create_audio(string $title, ?string $description, ?array $file, string $userId, array $tags = [], ?string $sourceUrl = null): array
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    $sourceUrl = $sourceUrl !== null ? trim($sourceUrl) : null;
    $hasFile = $file !== null;
    $hasUrl = $sourceUrl !== null && $sourceUrl !== '';
    if ($hasFile === $hasUrl) {
        throw new RuntimeException('Provide exactly one: an audio file or a URL to download from.');
    }

    $stored = $hasUrl ? content_download_media_from_url('audio', $sourceUrl) : content_store_uploaded_file('audio', $file);
    $description = $description !== null ? trim($description) : '';
    $analysis = narrative_analyze(
        narrative_prompt_for_audio($title, $description !== '' ? $description : null, $stored['metadata'])
    );

    $itemId = make_uuid();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO content_items (id, type, title, description, narrative_note, tags, created_by)
         VALUES (?, \'audio\', ?, ?, ?, ?, ?)'
    )->execute([$itemId, $title, $description !== '' ? $description : null, $analysis['note'], json_encode(content_normalize_tags($tags), JSON_UNESCAPED_UNICODE), $userId]);
    content_insert_file($pdo, $itemId, $stored, 0);
    archive_content_links_apply($itemId, $analysis['links']);
    content_auto_link_attempt($itemId);

    return content_find($itemId);
}

// Renders every page of an uploaded PDF to its own JPEG via
// poppler-utils' pdftoppm (shelled out, same optional-binary posture as
// content_extract_metadata()/content_extract_pdf_text() above) and
// stores each rendered page exactly like content_store_uploaded_file()
// would a directly-uploaded photo — same returned shape (fileId/
// fileName/destPath/originalName/mimeType/size/metadata), one entry per
// page, in page order, so content_create_photo_album() can treat the
// result identically to a normal multi-file photo selection. Unlike
// content_extract_metadata()'s silent degrade-to-null, a missing
// pdftoppm here throws — this is an explicit, user-initiated "import my
// PDF" action, not a background enrichment step, so silently producing
// nothing would just be confusing.
function content_render_pdf_to_photos(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(content_upload_error_message($error));
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
        throw new RuntimeException("That file doesn't look like a valid PDF.");
    }
    if (!function_exists('shell_exec') || stripos((string) ini_get('disable_functions'), 'shell_exec') !== false) {
        throw new RuntimeException('PDF import is not available on this host (it needs shell_exec, which is disabled here).');
    }

    $workDir = sys_get_temp_dir() . '/pdf_import_' . bin2hex(random_bytes(8));
    mkdir($workDir, 0770, true);
    try {
        $prefix = "$workDir/page";
        // -r 150: a plain "screen-ish" resolution — high enough to read
        // clearly, without producing an unreasonably large JPEG per page.
        shell_exec('pdftoppm -jpeg -r 150 ' . escapeshellarg($file['tmp_name']) . ' ' . escapeshellarg($prefix) . ' 2>/dev/null');
        $pages = glob("$prefix-*.jpg") ?: [];
        // pdftoppm numbers pages "-1", "-2", ... (or "-01" once there are
        // 10+ pages) — sort numerically on that suffix rather than
        // alphabetically, since alphabetical would put page 10 before 2.
        usort($pages, static function (string $a, string $b): int {
            $numOf = static fn (string $p) => (int) preg_replace('/^.*-(\d+)\.jpg$/', '$1', $p);
            return $numOf($a) <=> $numOf($b);
        });
        if (!$pages) {
            throw new RuntimeException('Could not extract any pages from that PDF — the host may be missing poppler-utils, or the file may be corrupt.');
        }
        if (count($pages) > 10) {
            throw new RuntimeException('That PDF has ' . count($pages) . ' pages — please split it or add at most 10 pages/photos at a time.');
        }

        $stored = [];
        foreach ($pages as $i => $pagePath) {
            $fileId = make_uuid();
            $fileName = "$fileId.jpg";
            $destPath = content_upload_dir('photo') . "/$fileName";
            rename($pagePath, $destPath);
            $stored[] = [
                'fileId' => $fileId,
                'fileName' => $fileName,
                'destPath' => $destPath,
                'originalName' => 'page-' . ($i + 1) . '.jpg',
                'mimeType' => 'image/jpeg',
                'size' => (int) filesize($destPath),
                'metadata' => content_extract_metadata($destPath),
            ];
        }
        return $stored;
    } finally {
        content_rrmdir_if_exists($workDir);
    }
}

// Deletes $dir and everything in it, if it exists — used by
// content_render_pdf_to_photos() to clean up its temp working
// directory regardless of success or failure (hence the `finally`
// there). A small local helper rather than reusing e.g.
// backup_rrmdir() from includes/backup.php, to avoid a cross-file
// dependency between two otherwise-unrelated features for one
// three-line utility.
function content_rrmdir_if_exists(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob("$dir/*") ?: [] as $path) {
        is_dir($path) ? content_rrmdir_if_exists($path) : @unlink($path);
    }
    @rmdir($dir);
}

// $files is a list of normalized upload arrays (content_normalize_multi_files()),
// one per selected photo. All photos share one title/caption and get a
// single combined narrative analysis considering them together, rather
// than N independent ones. $sourceUrl is an alternative to $files — a
// single photo downloaded server-side instead of picked locally (see
// content_download_media_from_url()); the two are mutually exclusive,
// and $sourceUrl only ever produces a one-photo "album". As a THIRD
// alternative to a normal image selection, $files may instead be a
// single PDF — detected by extension, expanded via
// content_render_pdf_to_photos() into one photo per page — since a
// multi-page scanned document/photo album saved as one PDF is a real,
// common case this form has no other way to import. A PDF must be the
// only file in the submission (never mixed with ordinary image files in
// the same request) — simpler to reason about than interleaving PDF
// pages with hand-picked images, and importing both is still just two
// separate submissions away.
function content_create_photo_album(string $title, ?string $description, array $files, string $userId, array $tags = [], ?string $sourceUrl = null): array
{
    $title = trim($title);
    if ($title === '') {
        throw new RuntimeException('Title is required.');
    }
    $sourceUrl = $sourceUrl !== null ? trim($sourceUrl) : null;
    $hasFiles = (bool) $files;
    $hasUrl = $sourceUrl !== null && $sourceUrl !== '';
    if (!$hasFiles && !$hasUrl) {
        throw new RuntimeException('At least one photo is required.');
    }
    if ($hasFiles && $hasUrl) {
        throw new RuntimeException('Provide either photo file(s) or a URL to download from, not both.');
    }
    if (count($files) > 10) {
        throw new RuntimeException('Please add at most 10 photos at a time.');
    }
    $pdfFiles = array_filter($files, static fn (array $f) =>
        strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION)) === 'pdf');
    if ($pdfFiles && count($files) > 1) {
        throw new RuntimeException('A PDF must be the only file in the submission — import it on its own to add every page as a photo.');
    }

    $stored = [];
    if ($hasUrl) {
        $stored[] = content_download_media_from_url('photo', $sourceUrl);
    } elseif ($pdfFiles) {
        $stored = content_render_pdf_to_photos($files[0]);
    } else {
        foreach ($files as $file) {
            $stored[] = content_store_uploaded_file('photo', $file);
        }
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

    // Most photo captions are just that — a caption — with nothing to
    // mine for a new archive entry, so this only runs at all when a
    // caption was actually given (unlike Document, where the text field
    // is required and always run through this pass). Covers the case of
    // a photo whose "caption" is really substantive source text — e.g. a
    // scanned citation/certificate imported as a photo for its image
    // rather than as a Document.
    if ($description !== '') {
        $suggestions = narrative_suggest_additions(
            narrative_prompt_for_photo_suggestions($title, $description),
            $title,
            'AI-suggested from a photo caption'
        );
        content_suggestions_insert($itemId, $suggestions);
    }

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
// admin's action ever touches narrative_note_reviewed_at — this feature
// is about admin oversight of AI text, and a non-admin author saving
// their own item says nothing about that.
//
// $markReviewed is a real two-way toggle, not a one-way "confirm" flag —
// the "Mark reviewed" checkbox (js/admin_content.js) is pre-checked
// whenever the note is already reviewed, so leaving it exactly as
// rendered and saving is always a no-op here (checked+already-reviewed
// re-sets NOW(), a harmless idempotent update; unchecked+already-
// unreviewed does nothing). A submitted note text change always counts
// as review regardless of the checkbox (a human edit is definitionally
// a review); otherwise $markReviewed=true sets narrative_note_reviewed_at
// to NOW() and $markReviewed=false on an already-reviewed item clears it
// back to NULL — an explicit, deliberate un-review, since the checkbox
// only ever starts unchecked when there was nothing reviewed to begin
// with.
function content_update_item(
    string $id, ?string $ownerId, ?string $narrativeNote, array $fileUpdates, ?array $tags = null,
    bool $isAdmin = false, bool $markReviewed = false, bool $linkedItemIdProvided = false, ?string $linkedItemId = null
): array {
    $item = content_find($id);
    if (!$item || ($ownerId !== null && $item['created_by'] !== $ownerId)) {
        throw new RuntimeException('Content item not found.');
    }

    $wasReviewed = $item['narrative_note_reviewed_at'] !== null;

    if ($narrativeNote !== null) {
        $note = trim($narrativeNote);
        $note = $note !== '' ? $note : null;
        $noteChanged = $note !== $item['narrative_note'];
        if ($isAdmin && ($noteChanged || $markReviewed)) {
            db()->prepare('UPDATE content_items SET narrative_note = ?, narrative_note_reviewed_at = NOW() WHERE id = ?')
                ->execute([$note, $id]);
        } elseif ($isAdmin && !$markReviewed && $wasReviewed) {
            db()->prepare('UPDATE content_items SET narrative_note = ?, narrative_note_reviewed_at = NULL WHERE id = ?')
                ->execute([$note, $id]);
        } else {
            db()->prepare('UPDATE content_items SET narrative_note = ? WHERE id = ?')
                ->execute([$note, $id]);
        }
    } elseif ($isAdmin && $markReviewed) {
        db()->prepare('UPDATE content_items SET narrative_note_reviewed_at = NOW() WHERE id = ?')
            ->execute([$id]);
    } elseif ($isAdmin && !$markReviewed && $wasReviewed) {
        db()->prepare('UPDATE content_items SET narrative_note_reviewed_at = NULL WHERE id = ?')
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

    if ($linkedItemIdProvided) {
        if ($linkedItemId === null || $linkedItemId === '') {
            content_unlink_item($id);
        } else {
            content_link_items($id, $linkedItemId, $ownerId);
        }
    }

    return content_find($id);
}

// Links two content items symmetrically — a video/audio<->transcript
// companion pairing. Only the video<->transcript pairing feeds
// Ask-tab citation-seek (see includes/video_seek.php, which stays
// video-only — an audio<->transcript link stores/displays the
// companion but has no seek behavior of its own). Always
// deterministic/PHP-decided, never model-decided. Both directions are
// written so "what's this item's companion" is a single content_find()
// away from either side. $ownerId, when given (a non-admin editor),
// requires BOTH items to be owned by that user — a contributor can't
// link their own item to someone else's without permission, mirroring
// content_update_item()'s own ownership gate above.
function content_link_items(string $idA, string $idB, ?string $ownerId = null): array
{
    if ($idA === $idB) {
        throw new RuntimeException('An item cannot be linked to itself.');
    }
    $a = content_find($idA);
    $b = content_find($idB);
    if (!$a || !$b) {
        throw new RuntimeException('Content item not found.');
    }
    if ($ownerId !== null && ($a['created_by'] !== $ownerId || $b['created_by'] !== $ownerId)) {
        throw new RuntimeException('Content item not found.');
    }
    $types = [$a['type'], $b['type']];
    sort($types);
    if (!in_array($types, [['transcript', 'video'], ['audio', 'transcript']], true)) {
        throw new RuntimeException('Only a video or audio recording can be linked to a transcript.');
    }

    // Clear any existing reciprocal link on either side first, so neither
    // item is ever left pointing at a partner that doesn't point back.
    content_unlink_item($idA);
    content_unlink_item($idB);
    $pdo = db();
    $pdo->prepare('UPDATE content_items SET linked_item_id = ? WHERE id = ?')->execute([$idB, $idA]);
    $pdo->prepare('UPDATE content_items SET linked_item_id = ? WHERE id = ?')->execute([$idA, $idB]);

    return content_find($idA);
}

// Clears $id's link and, symmetrically, whatever it was linked to's link
// back — so unlinking never leaves a dangling one-directional pointer.
function content_unlink_item(string $id): void
{
    $item = content_find($id);
    if (!$item || $item['linked_item_id'] === null) {
        return;
    }
    $pdo = db();
    $pdo->prepare('UPDATE content_items SET linked_item_id = NULL WHERE id = ?')->execute([$item['linked_item_id']]);
    $pdo->prepare('UPDATE content_items SET linked_item_id = NULL WHERE id = ?')->execute([$id]);
}

// Deterministic (non-AI, no model call) exact-title auto-match: an
// unlinked video/audio/transcript's companion is the oldest unlinked
// item of a complementary type whose title matches exactly,
// case-insensitively and trimmed. A transcript's complementary set is
// BOTH video and audio (either can be its companion); a video or
// audio's complementary set is just transcript. Presented to users as
// automatic matching, not "AI" — this never calls narrative_analyze()
// or any model. Ties (multiple same-titled unlinked candidates, or a
// transcript matching both an unlinked video AND an unlinked audio of
// the same title) resolve to the oldest by created_at, deterministically;
// the Content page's manual link picker always remains available as an
// override regardless of what this finds.
function content_auto_link_match(array $item): ?array
{
    $complementaryTypes = match ($item['type']) {
        'video', 'audio' => ['transcript'],
        'transcript' => ['video', 'audio'],
        default => [],
    };
    if (!$complementaryTypes || $item['linked_item_id'] !== null) {
        return null;
    }
    $placeholders = implode(',', array_fill(0, count($complementaryTypes), '?'));
    $stmt = db()->prepare(
        "SELECT * FROM content_items
         WHERE type IN ($placeholders) AND linked_item_id IS NULL AND id != ? AND LOWER(TRIM(title)) = LOWER(TRIM(?))
         ORDER BY created_at ASC LIMIT 1"
    );
    $stmt->execute([...$complementaryTypes, $item['id'], $item['title']]);
    $match = $stmt->fetch();
    return $match ?: null;
}

// Attempts the automatic match above for a freshly-created item and, if
// found, links it — called from content_create_video()/
// content_create_transcript() right after insert. Silent no-op if no
// match is found; the admin's manual override always remains available.
function content_auto_link_attempt(string $itemId): void
{
    $item = content_find($itemId);
    if (!$item) {
        return;
    }
    $match = content_auto_link_match($item);
    if ($match) {
        content_link_items($item['id'], $match['id']);
    }
}

// Retroactive sweep for pre-existing unlinked video/audio/transcript
// pairs (e.g. everything added before this feature shipped) — same
// matching rule as content_auto_link_attempt(), safe to re-run. Wired
// into the existing "Backfill AI analysis" button alongside
// content_backfill_ai_analysis(), even though this part isn't AI —
// reusing the one button avoids a second one for a related, infrequent
// admin action.
function content_backfill_auto_links(): array
{
    $unlinked = db()->query(
        "SELECT * FROM content_items WHERE type IN ('video','audio','transcript') AND linked_item_id IS NULL ORDER BY created_at ASC"
    )->fetchAll();

    $linked = [];
    foreach ($unlinked as $item) {
        // Re-fetch: an earlier iteration in this same sweep may have
        // already linked this row as someone else's match.
        $current = content_find($item['id']);
        if (!$current || $current['linked_item_id'] !== null) {
            continue;
        }
        $match = content_auto_link_match($current);
        if ($match) {
            content_link_items($current['id'], $match['id']);
            $linked[] = ['id' => $current['id'], 'title' => $current['title'], 'linkedTo' => $match['title']];
        }
    }
    return $linked;
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

    if ($item['type'] === 'document') {
        $file = $files[0] ?? null; // the pasted text, not the optional attachment — see content_create_document()
        if (!$file) {
            return $empty;
        }
        $path = content_upload_dir('document') . '/' . $file['file_name'];
        $text = is_file($path) ? file_get_contents($path) : null;
        return $text !== null && $text !== false
            ? narrative_analyze(narrative_prompt_for_document($item['title'], $text))
            : $empty;
    }

    if ($item['type'] === 'video' || $item['type'] === 'audio') {
        $file = $files[0] ?? null;
        $metadata = $file && $file['metadata'] !== null ? json_decode($file['metadata'], true) : null;
        return $item['type'] === 'audio'
            ? narrative_analyze(narrative_prompt_for_audio($item['title'], $item['description'], $metadata))
            : narrative_analyze(narrative_prompt_for_media($item['title'], $item['description'], $metadata));
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

// Mirrors content_analyze_existing_item(), but for the structured
// suggestion-extraction pass (narrative_suggest_additions()) — used by
// content_backfill_ai_analysis() to retroactively cover transcripts,
// approved stories, and captioned photos added before this pass existed
// for those types (or, for photo, before a caption was added/it was
// re-imported as a photo in place of a Document). url already gets this
// at creation (content_create_url()). video/audio captions aren't
// covered — unlike photo, there's no known case of substantive source
// text ending up there instead of a Document.
function content_suggest_additions_for_existing_item(array $item): array
{
    if ($item['type'] === 'photo') {
        $description = trim((string) ($item['description'] ?? ''));
        if ($description === '') {
            return [];
        }
        return narrative_suggest_additions(
            narrative_prompt_for_photo_suggestions($item['title'], $description),
            $item['title'],
            'AI-suggested from a photo caption'
        );
    }

    $file = content_files_for_item($item['id'])[0] ?? null;
    if (!$file) {
        return [];
    }
    $dir = content_upload_dir($item['type'] === 'story' ? 'story' : ($item['type'] === 'document' ? 'document' : 'transcript'));
    $path = "$dir/{$file['file_name']}";
    $text = is_file($path) ? file_get_contents($path) : null;
    if ($text === null || $text === false) {
        return [];
    }

    if ($item['type'] === 'story') {
        return narrative_suggest_additions(
            narrative_prompt_for_story_suggestions($item['title'], $text),
            $item['title'],
            'AI-suggested from a family-recounted story'
        );
    }
    if ($item['type'] === 'document') {
        return narrative_suggest_additions(
            narrative_prompt_for_document_suggestions($item['title'], $text),
            $item['title'],
            'AI-suggested from a family-contributed document'
        );
    }
    return narrative_suggest_additions(
        narrative_prompt_for_transcript_suggestions($item['title'], $text),
        $item['title'],
        'AI-suggested from a family-contributed transcript'
    );
}

// Two independent sweeps over existing content, each safe to re-run:
// (1) fills in narrative_note for every item that doesn't have one yet
// (and (re-)applies its content_links) — unchanged from this function's
// original narrative-notes-only behavior; (2) fills in suggestions
// (timeline/person/place/quote proposals, same as content_create_url())
// for every transcript/document/approved-story/captioned-photo item that
// doesn't have any yet — the photo case covers one added (or
// re-captioned) before this pass existed for photos, or a photo whose
// caption holds source text substantive enough to have warranted a
// Document instead. A pending (unapproved) story is excluded from both
// sweeps — see content_create_story()'s comment for why nothing runs on
// it before approval; content_approve_story() covers it once approved.
function content_backfill_ai_analysis(): array
{
    $noteItems = db()->query(
        "SELECT * FROM content_items WHERE narrative_note IS NULL AND type != 'story' ORDER BY created_at ASC"
    )->fetchAll();
    $suggestionItems = db()->query(
        "SELECT * FROM content_items
         WHERE (type IN ('transcript', 'document')
                OR (type = 'story' AND story_approved_at IS NOT NULL)
                OR (type = 'photo' AND description IS NOT NULL AND description != ''))
           AND id NOT IN (SELECT DISTINCT content_item_id FROM content_suggestions)
         ORDER BY created_at ASC"
    )->fetchAll();

    $resultsById = [];
    $ensure = static function (array $item) use (&$resultsById): void {
        $resultsById[$item['id']] ??= [
            'id' => $item['id'], 'title' => $item['title'], 'updated' => false, 'suggestionsAdded' => 0,
        ];
    };

    foreach ($noteItems as $item) {
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
        $ensure($item);
        $resultsById[$item['id']]['updated'] = $note !== null;
    }

    foreach ($suggestionItems as $item) {
        $suggestions = content_suggest_additions_for_existing_item($item);
        if ($suggestions) {
            content_suggestions_insert($item['id'], $suggestions);
        }
        $ensure($item);
        $resultsById[$item['id']]['suggestionsAdded'] = count($suggestions);
    }

    return array_values($resultsById);
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
