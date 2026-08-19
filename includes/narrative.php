<?php
declare(strict_types=1);

// Analyzes newly-added Content-page material (see includes/content.php)
// against the existing archive to find — and only cite, never invent —
// specific connections: named people/places/timeline events/quotes it
// relates to. Goes through the same includes/ai_provider.php adapter
// api/chat.php uses (same configured provider/model, same cached system
// blocks where the provider supports them) but asks a narrower question
// and returns plain text instead of a chat reply. Never throws: a
// missing API key, network error, or timeout just means no narrative
// note gets stored, exactly like content_extract_metadata()'s exiftool
// fallback.

function narrative_system_prompt(): string
{
    $subject = subject_name();
    return <<<EOT
You are helping curate a family history archive about $subject. A
family member just added a new piece of material (a transcript excerpt,
or a photo/video with a caption). Your only job is to identify how this
new item connects to the EXISTING archive material given to you as
context — specific named people, places, timeline events, or quotes,
each shown in that context with its own "id:" line.

Rules:
- Cite specific existing elements by name in your note when you find a
  connection (e.g. "the timeline entry for [event]," "[a specific
  person] in the People section," "the quote about [topic]").
- If you don't see a clear, specific connection, say plainly "No clear
  connection to the existing archive was found" rather than inventing
  one. A generic photo or caption with nothing identifiable usually has
  no findable connection — that's the correct answer in that case.
- Never invent facts, names, dates, or events not present in either the
  existing archive or the new item's own text/caption.
- Keep your note to 1-3 sentences.
- Separately, list which specific existing entries (by their "id:") this
  item is a strong, direct match for — e.g. a photo clearly depicting a
  named person or place, or clearly illustrating a specific timeline
  event or quote. Only include an entry here if the match is
  unambiguous; a loose thematic connection belongs in the note, not
  here. An empty list is the correct, common answer. List at most 8
  matches, even if more seem loosely relevant — pick the strongest ones.

Respond with ONLY a JSON object (no prose, no markdown fence):
{"note": "<your 1-3 sentence note, or the exact no-connection sentence above>",
 "links": [{"type": "person"|"place"|"timeline"|"quote", "id": "<id from the context>"}]}
EOT;
}

// Parses narrative_system_prompt()'s {"note": ..., "links": [...]}
// response. Falls back to treating a non-JSON reply as plain note text
// with no links, in case a provider doesn't comply with the JSON
// instruction — degrading gracefully rather than losing the note
// entirely, matching this file's existing fail-quiet posture.
function narrative_parse_analysis(?string $raw): array
{
    $empty = ['note' => null, 'links' => []];
    if ($raw === null) {
        return $empty;
    }
    $raw = trim($raw);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $raw, $m)) {
        $raw = trim($m[1]);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['note' => $raw !== '' ? $raw : null, 'links' => []];
    }

    $note = isset($decoded['note']) && is_string($decoded['note']) && $decoded['note'] !== '' ? $decoded['note'] : null;
    $links = [];
    foreach ($decoded['links'] ?? [] as $link) {
        if (!is_array($link)) {
            continue;
        }
        $type = $link['type'] ?? null;
        $id = $link['id'] ?? null;
        if (in_array($type, ['person', 'place', 'timeline', 'quote'], true) && is_string($id) && $id !== '') {
            $links[] = ['type' => $type, 'id' => $id];
        }
    }
    return ['note' => $note, 'links' => $links];
}

function narrative_prompt_for_transcript(string $title, string $text): string
{
    return "New transcript added to the archive.\nTitle: $title\n\nText:\n$text\n\n"
        . 'How does this connect to the existing archive material provided as context? Follow the rules above.';
}

function narrative_prompt_for_url(string $title, string $url, string $text): string
{
    return "New source added to the archive via URL.\nTitle: $title\nURL: $url\n\nExtracted text:\n$text\n\n"
        . 'How does this connect to the existing archive material provided as context? Follow the rules above.';
}

function narrative_prompt_for_story(string $title, string $text): string
{
    return "New family-recounted story added to the archive (not the subject's own testimony — a story "
        . "family members tell about them).\nTitle: $title\n\nText:\n$text\n\n"
        . 'How does this connect to the existing archive material provided as context? Follow the rules above.';
}

function narrative_prompt_for_media(string $title, ?string $description, ?array $metadata): string
{
    $lines = ['New video added to the archive.', "Title: $title"];
    if ($description !== null && $description !== '') {
        $lines[] = "Caption: $description";
    }
    if ($metadata) {
        $lines[] = 'Captured info: ' . content_format_metadata_summary($metadata);
    }
    $lines[] = '';
    $lines[] = 'How does this (based on its caption) connect to the existing archive material provided as context? Follow the rules above.';
    return implode("\n", $lines);
}

// $metadataList is one entry per photo, in the same order the photos'
// image blocks are attached, so the model can refer to "photo 2" etc.
function narrative_prompt_for_photo_album(string $title, ?string $description, array $metadataList): string
{
    $count = count($metadataList);
    $lines = [
        'New photo' . ($count === 1 ? '' : ' album') . " added to the archive ($count photo" . ($count === 1 ? '' : 's') . ').',
        "Title: $title",
    ];
    if ($description !== null && $description !== '') {
        $lines[] = "Caption: $description";
    }
    foreach ($metadataList as $i => $metadata) {
        if ($metadata) {
            $n = $i + 1;
            $lines[] = "Photo $n captured info: " . content_format_metadata_summary($metadata);
        }
    }
    $lines[] = '';
    $lines[] = 'The photo(s) themselves are attached, in the same order as listed above. How does this '
        . ($count === 1 ? 'photo' : 'album') . ' (and its caption) connect to the existing archive material provided as context? Follow the rules above.';
    return implode("\n", $lines);
}

// Downscales a photo to a vision-friendly size via ImageMagick (present
// on this host at /usr/local/bin/convert; not guaranteed on all shared
// hosts, so this degrades rather than failing). 1568px on the long edge
// keeps both Anthropic's and OpenAI's vision inputs comfortably under
// their per-image limits and keeps token cost down. `[0]` pins the
// first frame for animated GIFs. Returns the generic ai_image_block()
// shape — ai_chat() translates it into whichever provider's own
// image-block format is configured.
function narrative_build_image_block(string $path): ?array
{
    $shellOk = function_exists('shell_exec') && stripos((string) ini_get('disable_functions'), 'shell_exec') === false;
    if ($shellOk) {
        $tmp = tempnam(sys_get_temp_dir(), 'narr') . '.jpg';
        @shell_exec(
            'convert ' . escapeshellarg($path . '[0]') . ' -auto-orient -resize 1568x1568\> -quality 85 '
            . escapeshellarg($tmp) . ' 2>/dev/null'
        );
        if (is_file($tmp) && filesize($tmp) > 0 && filesize($tmp) <= 4_000_000) {
            $data = base64_encode((string) file_get_contents($tmp));
            unlink($tmp);
            return ai_image_block('image/jpeg', $data);
        }
        @unlink($tmp);
    }

    // No usable resize — fall back to the original bytes, but only if
    // small enough to reasonably fit in a base64 request body.
    $size = @filesize($path);
    if ($size !== false && $size <= 3_500_000) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($path);
        return ai_image_block($mime, base64_encode((string) file_get_contents($path)));
    }

    return null;
}

// Shared low-level call — both narrative_analyze() and
// narrative_suggest_additions() send the same request shape (system
// prompt + cached archive context) via ai_chat() and only differ in
// system prompt/content/token budget. Returns the reply text, or null
// on any failure (missing key, network error, timeout, non-2xx) —
// callers never throw.
function narrative_call_ai(string $systemPrompt, array $content, int $maxTokens): ?string
{
    try {
        $context = knowledge_context();
    } catch (Throwable $e) {
        error_log('narrative_call_ai: knowledge base load failed: ' . $e->getMessage());
        return null;
    }

    $result = ai_chat([$systemPrompt, $context], [['role' => 'user', 'content' => $content]], $maxTokens);
    return $result['text'] ?? null;
}

// Returns ['note' => string|null, 'links' => [['type' => ..., 'id' => ...], ...]].
// The note is the same 1-3 sentence connection text this function has
// always produced; links is new — see narrative_system_prompt() and
// archive_content_links_apply() (includes/archive.php), which validates
// each id before it's ever stored.
function narrative_analyze(string $userPrompt, array $imageBlocks = []): array
{
    $content = $imageBlocks;
    $content[] = ['type' => 'text', 'text' => $userPrompt];
    // Generous headroom over a plain 1-3 sentence note: an item that
    // legitimately matches many existing entries (e.g. a group photo
    // naming several people) needs room for that many {"type","id"}
    // pairs too, and a response truncated mid-JSON fails to parse at
    // all — losing the note along with the links, not just trimming
    // the list. Seen in practice: a Yad Vashem exhibit photo matching 6
    // people overflowed the previous 500-token budget.
    $raw = narrative_call_ai(narrative_system_prompt(), $content, 900);
    return narrative_parse_analysis($raw);
}

// Kind => required field names, used both to validate the model's JSON
// and to drive includes/knowledge_writer.php's field emission order.
function narrative_suggestion_schema(): array
{
    return [
        'timeline' => ['date', 'event', 'confidence'],
        'person' => ['id', 'names', 'role', 'notes'],
        'place' => ['id', 'names', 'role'],
        'quote' => ['id', 'speaker', 'tags', 'quote'],
    ];
}

function narrative_suggestions_system_prompt(): string
{
    $subject = subject_name();
    return <<<EOT
You are helping curate a family history archive about $subject. A
family member added new material to the archive — an external source
(an article, document, genealogy record, Yizkor book page, etc.) via
URL, a first-person transcript, or a family-recounted story. Your job
is to compare it against the EXISTING archive material given to you as
context and propose NEW, well-grounded additions to the archive's
timeline, people registry, places registry, and quote bank.

Rules (non-negotiable):
- Propose ONLY new entries. If the source describes a person, place, or
  event ALREADY in the existing registry, do not propose a duplicate —
  skip it. This tool never edits or replaces an existing entry.
- Never invent facts, names, or dates not present in the source text
  provided. Every field must be traceable to that text.
- A "quote" suggestion's `quote` field must be copied VERBATIM from the
  source text — never paraphrased, never invented. Only propose a quote
  if the source actually contains a directly quotable line worth
  preserving (e.g. a documented eyewitness statement, not routine prose).
- An empty array is the correct, expected answer for most sources — do
  not force a connection that isn't clearly there.
- `id` fields (person/place/quote) must be a short lowercase slug
  (letters, digits, underscores only) not already used in the existing
  registry.
- `date` (timeline) follows the existing convention: ISO
  ("1942-07-18") when precise, or "~YYYY" / "~YYYY-MM" when approximate.
- `confidence` (timeline) is "high", "medium", or "low".
- Do not include a `source` or `citation` field yourself — the
  application attaches those automatically from the material you were
  given.
- Propose AT MOST 6 additions, even if the source could support more —
  pick the most significant, well-grounded ones. Keep each `event`/
  `notes`/`quote` field concise (2-4 sentences at most). Your response
  must fit in a limited token budget, so brevity matters more than
  completeness — fewer, well-chosen suggestions beat a truncated list.

Respond with ONLY a JSON array (no prose, no markdown fence), where each
element is: {"kind": "timeline"|"person"|"place"|"quote", "fields": {...}}
using exactly these fields per kind:
- timeline: date, event, confidence, note (optional)
- person: id, names (array), role, fate (optional), notes
- place: id, names (array), role, notes (optional)
- quote: id, speaker, tags (array), quote

If there is nothing to propose, respond with exactly: []
EOT;
}

function narrative_prompt_for_url_suggestions(string $title, string $url, string $text): string
{
    return "New source added to the archive via URL.\nTitle: $title\nURL: $url\n\nExtracted text:\n$text\n\n"
        . 'Propose new archive additions per the rules above.';
}

function narrative_prompt_for_transcript_suggestions(string $title, string $text): string
{
    return "New transcript added to the archive.\nTitle: $title\n\nText:\n$text\n\n"
        . 'Propose new archive additions per the rules above.';
}

function narrative_prompt_for_story_suggestions(string $title, string $text): string
{
    return "New family-recounted story added to the archive (not the subject's own testimony — a story "
        . "family members tell about them).\nTitle: $title\n\nText:\n$text\n\n"
        . 'Propose new archive additions per the rules above.';
}

// Returns a list of ['kind' => ..., 'fields' => [...]] ready for
// content_suggestions_insert() — never throws; a missing API key, parse
// failure, or malformed item just yields fewer (or zero) suggestions,
// same fail-quiet posture as narrative_analyze(). $citation and
// $sourceNote are always PHP-attached, never trusted from the model —
// build $prompt with one of the narrative_prompt_for_*_suggestions()
// functions above so it matches what $citation/$sourceNote describe.
function narrative_suggest_additions(string $prompt, string $citation, string $sourceNote): array
{
    $raw = narrative_call_ai(
        narrative_suggestions_system_prompt(),
        [['type' => 'text', 'text' => $prompt]],
        4096
    );
    if ($raw === null) {
        return [];
    }

    // Tolerate a ```json ... ``` fence even though the prompt asks for
    // bare JSON — models don't always comply exactly.
    $raw = trim($raw);
    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $raw, $m)) {
        $raw = $m[1];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('narrative_suggest_additions: model did not return a JSON array: ' . substr($raw, 0, 500));
        return [];
    }

    $schema = narrative_suggestion_schema();
    $out = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        $kind = $item['kind'] ?? null;
        $fields = $item['fields'] ?? null;
        if (!is_string($kind) || !isset($schema[$kind]) || !is_array($fields)) {
            continue;
        }
        $missing = array_filter($schema[$kind], static fn ($key) => !isset($fields[$key]) || $fields[$key] === '');
        if ($missing) {
            continue; // required field absent — drop rather than guess
        }
        if (in_array($kind, ['person', 'place', 'quote'], true)) {
            $slug = strtolower((string) $fields['id']);
            if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
                continue;
            }
            $fields['id'] = $slug;
        }
        $fields['citation'] = $citation;
        $fields['sourceNote'] = $sourceNote;
        $out[] = ['kind' => $kind, 'fields' => $fields];
    }
    return $out;
}
