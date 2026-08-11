<?php
declare(strict_types=1);

// Imports an existing app-lamp deployment's knowledge/*.yaml + testimony
// transcript into this version's DB-backed archive tables — see
// api/admin/legacy_import.php (the endpoint) and admin_archive.php's
// Import tab. Deliberately a narrow, purpose-built parser for exactly
// the flat-record shape those files use (a top-level list of `- key:
// value` maps, flow arrays like `[a, b, c]`, and block/continuation
// text), not a general YAML library — no Composer available on shared
// hosting, and the real format is simple enough not to need one.
//
// Every function here is a pure text-in/array-out transform with no DB
// access, so it's independently testable without a database.

// --- Generic flat-record YAML-subset parser ---

// Parses a top-level YAML sequence of flat mappings into a list of
// assoc arrays of RAW (still-quoted, still-joined) string values —
// callers apply legacy_yaml_unquote()/legacy_yaml_flow_array()/etc.
// themselves per field, since only the caller knows which fields are
// short scalars vs. prose vs. arrays. Multi-line values — whether an
// explicit `key: |` block or a plain scalar that just wraps onto an
// indented continuation line — are folded into one space-joined string;
// this deliberately treats `|` (literal) the same as `>` (folded)
// because in these files line-wrapping is purely an editing convenience
// (readable diffs), never meaningful structure — a verbatim spoken quote
// should read as one flowing paragraph, not break wherever an editor
// happened to wrap the line.
function legacy_yaml_parse(string $text): array
{
    $lines = explode("\n", str_replace("\r\n", "\n", $text));

    $records = [];
    $current = null;
    $curKey = null;
    $curIndent = null;

    $startField = function (string $rest, int $indent) use (&$current, &$curKey, &$curIndent): void {
        if (!preg_match('/^(\w+):\s?(.*)$/', $rest, $m)) {
            return;
        }
        $key = $m[1];
        $value = rtrim($m[2]);
        if (in_array($value, ['|', '>', '|-', '>-'], true)) {
            $value = '';
        }
        $current[$key] = $value;
        $curKey = $key;
        $curIndent = $indent;
    };

    foreach ($lines as $line) {
        $trimmed = ltrim($line, ' ');
        if ($trimmed === '') {
            continue;
        }
        $indent = strlen($line) - strlen($trimmed);

        if ($indent === 0) {
            if ($trimmed[0] === '#') {
                continue;
            }
            if ($trimmed[0] === '-' && (strlen($trimmed) === 1 || $trimmed[1] === ' ')) {
                if ($current !== null) {
                    $records[] = $current;
                }
                $current = [];
                $curKey = null;
                $curIndent = null;
                $startField(ltrim(substr($trimmed, 1)), 2);
            }
            continue;
        }

        if ($current === null) {
            continue;
        }

        if ($indent === 2 && preg_match('/^\w+:/', $trimmed)) {
            $startField($trimmed, 2);
            continue;
        }

        if ($curKey !== null && $indent > $curIndent) {
            $current[$curKey] = $current[$curKey] === '' ? $trimmed : $current[$curKey] . ' ' . $trimmed;
        }
    }
    if ($current !== null) {
        $records[] = $current;
    }
    return $records;
}

function legacy_yaml_unquote(string $s): string
{
    $s = trim($s);
    $len = strlen($s);
    if ($len >= 2) {
        $first = $s[0];
        $last = $s[$len - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($s, 1, -1);
        }
    }
    return $s;
}

// Parses a flow sequence like `[Jane Doe, J. Doe, "Nickname [?]"]` — a
// small quote-aware tokenizer since a comma can appear inside a
// quoted item.
function legacy_yaml_flow_array(string $s): array
{
    $s = trim($s);
    if (strlen($s) < 2 || $s[0] !== '[' || $s[strlen($s) - 1] !== ']') {
        return [];
    }
    $inner = substr($s, 1, -1);
    $items = [];
    $buf = '';
    $inQuote = null;
    for ($i = 0, $n = strlen($inner); $i < $n; $i++) {
        $ch = $inner[$i];
        if ($inQuote !== null) {
            $buf .= $ch;
            if ($ch === $inQuote) {
                $inQuote = null;
            }
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $inQuote = $ch;
            $buf .= $ch;
            continue;
        }
        if ($ch === ',') {
            $items[] = legacy_yaml_unquote(trim($buf));
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    if (trim($buf) !== '') {
        $items[] = legacy_yaml_unquote(trim($buf));
    }
    return array_values(array_filter($items, static fn (string $v): bool => $v !== ''));
}

// Extracts `# key: description text` header-comment lines into a lookup
// map — e.g. people.yaml's own "# source_vha: confirmed in VHA
// testimony..." comment. Generic to whatever convention a given
// archive's YAML documents about itself; nothing here is specific to
// any one archive's sources. Deliberately does NOT fold a following
// non-"key:" comment line into the previous description — some files'
// header blocks do wrap one description across lines, but others (e.g.
// people.yaml) follow a `# source_X: ...` line with an unrelated
// standalone comment, and there's no reliable way to tell those apart
// generically; treating each comment line independently is the safer
// default; a wrapped description just ends up truncated to its first
// line rather than accidentally absorbing unrelated text.
function legacy_yaml_comment_map(string $text): array
{
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    $map = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] !== '#') {
            continue;
        }
        $body = ltrim(substr($trimmed, 1));
        if (preg_match('/^(\w+):\s*(.+)$/', $body, $m)) {
            $map[$m[1]] = $m[2];
        }
    }
    return $map;
}

// Shared by people/places: any `source_X: true` field, cross-referenced
// against that file's own "# source_X: <description>" header comments
// (if present) to build a readable citation. Falls back to just listing
// the raw flag names when a file doesn't document them in comments.
function legacy_import_build_source(array $record, array $comments): array
{
    $labels = [];
    $descriptions = [];
    foreach ($record as $key => $value) {
        if (!str_starts_with($key, 'source_') || trim((string) $value) !== 'true') {
            continue;
        }
        $labels[] = substr($key, strlen('source_'));
        if (isset($comments[$key])) {
            $descriptions[] = ucfirst(rtrim(trim($comments[$key]), '. ')) . '.';
        }
    }
    return [
        $labels ? implode(', ', $labels) : null,
        $descriptions ? implode(' ', $descriptions) : null,
    ];
}

// --- Per-file mappers: raw YAML text -> arrays matching this app's own
// archive_*_create()/update() field shapes (includes/archive.php) ---

function legacy_import_parse_people(string $yamlText): array
{
    $comments = legacy_yaml_comment_map($yamlText);
    $out = [];
    foreach (legacy_yaml_parse($yamlText) as $r) {
        $names = legacy_yaml_flow_array($r['names'] ?? '');
        if (!$names) {
            continue;
        }

        $notes = isset($r['notes']) ? legacy_yaml_unquote($r['notes']) : '';
        $bornBits = [];
        if (!empty($r['born'])) {
            $bornBits[] = 'Born ' . legacy_yaml_unquote($r['born']);
        }
        if (!empty($r['birthplace'])) {
            $bornBits[] = legacy_yaml_unquote($r['birthplace']);
        }
        if ($bornBits) {
            $bornLine = implode(', ', $bornBits) . '.';
            $notes = $notes !== '' ? $bornLine . ' ' . $notes : $bornLine;
        }
        if (!empty($r['book_note'])) {
            $notes = $notes !== '' ? $notes . ' ' . $r['book_note'] : $r['book_note'];
        }

        $nameNote = $r['name_note'] ?? '';
        if (!empty($r['book_name_note'])) {
            $nameNote = $nameNote !== '' ? $nameNote . ' ' . $r['book_name_note'] : $r['book_name_note'];
        }

        [$sourceNote, $citation] = legacy_import_build_source($r, $comments);

        $out[] = [
            'slug' => $r['id'] ?? null,
            'names' => $names,
            'role' => isset($r['role']) && $r['role'] !== '' ? legacy_yaml_unquote($r['role']) : null,
            'fate' => isset($r['fate']) && $r['fate'] !== '' ? $r['fate'] : null,
            'notes' => $notes !== '' ? $notes : null,
            'name_note' => $nameNote !== '' ? $nameNote : null,
            'source_note' => $sourceNote,
            'citation' => $citation,
        ];
    }
    return $out;
}

function legacy_import_parse_places(string $yamlText): array
{
    $comments = legacy_yaml_comment_map($yamlText);
    $out = [];
    foreach (legacy_yaml_parse($yamlText) as $r) {
        $names = legacy_yaml_flow_array($r['names'] ?? '');
        if (!$names) {
            continue;
        }

        $role = isset($r['role']) && $r['role'] !== '' ? legacy_yaml_unquote($r['role']) : '';
        $notes = isset($r['notes']) ? legacy_yaml_unquote($r['notes']) : '';
        // Some archives use a long, multi-sentence "role" as de-facto
        // descriptive notes rather than a short label — move it to
        // notes rather than truncating it into the shorter role column.
        if (mb_strlen($role) > 255) {
            $notes = $notes !== '' ? $role . ' ' . $notes : $role;
            $role = '';
        }

        [$sourceNote, $citation] = legacy_import_build_source($r, $comments);

        $out[] = [
            'slug' => $r['id'] ?? null,
            'names' => $names,
            'wartime_country' => isset($r['wartime_country']) && $r['wartime_country'] !== '' ? legacy_yaml_unquote($r['wartime_country']) : null,
            'modern_country' => isset($r['modern_country']) && $r['modern_country'] !== '' ? legacy_yaml_unquote($r['modern_country']) : null,
            'approx_coords' => isset($r['approx_coords']) && $r['approx_coords'] !== '' ? legacy_yaml_unquote($r['approx_coords']) : null,
            'role' => $role !== '' ? $role : null,
            'notes' => $notes !== '' ? $notes : null,
            'source_note' => $sourceNote,
            'citation' => $citation,
        ];
    }
    return $out;
}

function legacy_import_parse_timeline(string $yamlText): array
{
    $out = [];
    $order = 1;
    foreach (legacy_yaml_parse($yamlText) as $r) {
        $dateLabel = isset($r['date']) ? legacy_yaml_unquote($r['date']) : '';
        $event = $r['event'] ?? '';
        if ($dateLabel === '' || $event === '') {
            continue;
        }
        $confidence = isset($r['confidence']) ? strtolower(trim($r['confidence'])) : null;
        if (!in_array($confidence, ['high', 'medium', 'low'], true)) {
            $confidence = null;
        }
        $out[] = [
            'sort_order' => $order++,
            'date_label' => $dateLabel,
            'event' => $event,
            'source_note' => isset($r['source']) && $r['source'] !== '' ? trim($r['source']) : null,
            'confidence' => $confidence,
            'note' => isset($r['note']) && $r['note'] !== '' ? $r['note'] : null,
            'historical_date' => isset($r['historical_date']) && $r['historical_date'] !== '' ? legacy_yaml_unquote($r['historical_date']) : null,
            'historical_source' => isset($r['historical_source']) && $r['historical_source'] !== '' ? $r['historical_source'] : null,
        ];
    }
    return $out;
}

function legacy_import_parse_quotes(string $yamlText): array
{
    $out = [];
    foreach (legacy_yaml_parse($yamlText) as $r) {
        $speaker = isset($r['speaker']) ? legacy_yaml_unquote($r['speaker']) : '';
        $quote = $r['quote'] ?? '';
        if ($speaker === '' || $quote === '') {
            continue;
        }
        $tape = isset($r['tape']) ? trim($r['tape']) : '';
        $sourceNote = null;
        if ($tape !== '') {
            $sourceNote = ctype_digit($tape) ? "Tape $tape" : $tape;
        }
        $out[] = [
            'speaker' => $speaker,
            'source_note' => $sourceNote,
            'tags' => isset($r['tags']) ? legacy_yaml_flow_array($r['tags']) : [],
            'quote_text' => $quote,
        ];
    }
    return $out;
}

// Rewrites a transcript's speaker markers (e.g. "**SF:**"/"**INT:**",
// whatever a given archive's own transcript used) into this app's
// generic "**SUBJECT:**"/"**INTERVIEWER:**" convention (see
// transcript_parse_tapes() in includes/transcript.php). Blank marker
// arguments are a no-op, for a transcript that already uses the generic
// convention.
function legacy_import_transform_transcript(string $md, ?string $subjectMarker, ?string $interviewerMarker): string
{
    $subjectMarker = trim((string) $subjectMarker);
    $interviewerMarker = trim((string) $interviewerMarker);
    if ($subjectMarker !== '') {
        $md = preg_replace('/\*\*' . preg_quote($subjectMarker, '/') . '\s*:?\*\*/', '**SUBJECT:**', $md);
    }
    if ($interviewerMarker !== '') {
        $md = preg_replace('/\*\*' . preg_quote($interviewerMarker, '/') . '\s*:?\*\*/', '**INTERVIEWER:**', $md);
    }
    return $md;
}
