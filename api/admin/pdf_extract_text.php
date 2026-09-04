<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';

// Called from the add-content form's "Extract text from PDF" button
// (Document type only) — a standalone pre-submit step, not part of
// api/admin/content.php's own POST handler, since the extracted text
// needs to land back in the still-editable textarea for a human to
// review/adjust before the actual "Add content" submit, rather than
// being silently baked into the created item. Same admin-or-author
// gate as adding content itself.
$user = require_content_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$file = $_FILES['file'] ?? null;
if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['error' => 'No file was uploaded.'], 400);
}

// Trust the file's actual bytes over its extension/browser-supplied
// MIME type, same posture as content_store_uploaded_file().
$finfo = new finfo(FILEINFO_MIME_TYPE);
$realMime = (string) $finfo->file($file['tmp_name']);
if ($realMime !== 'application/pdf') {
    json_response(['error' => "That file doesn't look like a PDF."], 400);
}

$text = content_extract_pdf_text($file['tmp_name']);
if ($text === null) {
    json_response([
        'error' => "Couldn't extract any text from that PDF — either this host doesn't support PDF text "
            . "extraction, or the PDF is a scanned image with no real text layer (this reads embedded text "
            . "only; it doesn't OCR a scan). You can still paste the text in by hand.",
    ], 422);
}

json_response(['text' => $text]);
