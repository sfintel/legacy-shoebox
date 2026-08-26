<?php
declare(strict_types=1);

// Read/write access to the core archive tables (see sql/schema.sql) —
// the database is the source of truth for timeline/people/places/
// quotes/primary testimony/discrepancy notes/site identity/audience
// categories, not knowledge/*.yaml files. Used by includes/knowledge.php
// (Ask tab context) and api/data.php (Browse tab JSON) alike, so both
// surfaces always see the same data. See also includes/knowledge_writer.php,
// whose kw_apply_suggestion() inserts into these same tables when an
// admin approves an AI-suggested addition.

// The survivor/subject of the archive (role='subject') always sorts
// first, regardless of when they were added, since they're the person
// every other entry here relates to — everyone else stays in the order
// they were added.
function archive_people(): array
{
    return db()->query("SELECT * FROM people ORDER BY (role = 'subject') DESC, created_at ASC")->fetchAll();
}

function archive_places(): array
{
    return db()->query('SELECT * FROM places ORDER BY created_at ASC')->fetchAll();
}

function archive_timeline(): array
{
    return db()->query('SELECT * FROM timeline_entries ORDER BY sort_order ASC, created_at ASC')->fetchAll();
}

function archive_quotes(): array
{
    return db()->query('SELECT * FROM quotes ORDER BY created_at ASC')->fetchAll();
}

function archive_primary_testimony(): ?array
{
    $row = db()->query('SELECT * FROM primary_testimony WHERE id = 1')->fetch();
    return $row ?: null;
}

function archive_discrepancy_notes(): ?array
{
    $row = db()->query('SELECT * FROM discrepancy_notes WHERE id = 1')->fetch();
    return $row ?: null;
}

function archive_site_settings(): ?array
{
    $row = db()->query('SELECT * FROM site_settings WHERE id = 1')->fetch();
    return $row ?: null;
}

// Cached, default-filled version of archive_site_settings() — used
// throughout the app (page titles, AI prompts, emails) so callers never
// need to null-check. The fallback values only matter before the setup
// wizard (Phase 4) has run; once it has, a real row always exists.
function site_settings(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $row = archive_site_settings();
    $cached = ($row ?? []) + [
        'site_name' => 'Family Archive',
        'subject_name' => 'this family member',
        'subject_pronoun_subject' => 'they',
        'subject_pronoun_object' => 'them',
        'subject_pronoun_possessive' => 'their',
        'subject_birth_date' => null,
        'subject_birthplace' => null,
        'subject_death_date' => null,
        'subject_short_bio' => null,
        'closing_quote' => null,
        'closing_quote_attribution' => null,
        'ask_placeholder_text' => null,
        'setup_completed_at' => null,
    ];
    return $cached;
}

function site_name(): string
{
    $s = site_settings();
    return $s['site_name'] !== '' && $s['site_name'] !== null ? $s['site_name'] : 'Family Archive';
}

function subject_name(): string
{
    $s = site_settings();
    return $s['subject_name'] !== '' && $s['subject_name'] !== null ? $s['subject_name'] : 'this family member';
}

// Regenerates the static manifest.json file on disk from site_settings —
// called whenever site identity changes (admin_settings.php's save
// handler, and the setup wizard's finalize step in Phase 4). Written to
// disk rather than served dynamically so the app's "no mod_rewrite"
// design principle stays intact — <link rel="manifest" href="/manifest.json">
// in index.php never needs to change.
function manifest_regenerate(): void
{
    $name = site_name();
    $manifest = [
        'name' => $name,
        'short_name' => manifest_short_name($name),
        'description' => "Private family archive for querying " . subject_name() . "'s testimony and legacy materials.",
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#1b1a17',
        'theme_color' => '#1b1a17',
        'orientation' => 'portrait-primary',
        'icons' => [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ];
    file_put_contents(
        __DIR__ . '/../manifest.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

// PWA home-screen labels read best short — truncates at the last word
// boundary before 20 chars rather than cutting mid-word.
function manifest_short_name(string $name): string
{
    if (mb_strlen($name) <= 20) {
        return $name;
    }
    $truncated = mb_substr($name, 0, 20);
    $lastSpace = mb_strrpos($truncated, ' ');
    return $lastSpace !== false ? mb_substr($truncated, 0, $lastSpace) : $truncated;
}

// $fields carries whichever identity fields the caller has to set — NOT
// necessarily all of them. A key simply absent from $fields keeps its
// current value (so a partial update, like setup_finalize()'s "just flip
// the completed flag", can't silently wipe every other field back to
// blank — this bit us once already). A key present with an empty value
// is what actually clears it. $markSetupComplete is true only from the
// setup wizard's finalize step (Phase 4).
function archive_site_settings_update(array $fields, bool $markSetupComplete = false): array
{
    $current = archive_site_settings() ?? [];
    $setupCompletedAt = $markSetupComplete
        ? date('Y-m-d H:i:s')
        : ($current['setup_completed_at'] ?? null);

    $pick = static function (string $key, $default = null) use ($fields, $current) {
        if (array_key_exists($key, $fields)) {
            return $fields[$key];
        }
        return $current[$key] ?? $default;
    };

    // A plain UPDATE, not REPLACE INTO — REPLACE deletes and reinserts
    // the row, so any column not in its explicit list (schema_version,
    // or any future column this function's author forgets to add here)
    // silently reverts to its schema DEFAULT. Found via testing:
    // schema_version was getting reset to '1.0.0' on every settings
    // save. INSERT IGNORE first guarantees the id=1 row exists (a
    // brand-new install, before setup, has no row yet) without
    // clobbering it if it already does.
    db()->exec('INSERT IGNORE INTO site_settings (id) VALUES (1)');
    $stmt = db()->prepare(
        'UPDATE site_settings SET
          site_name = ?, subject_name = ?, subject_pronoun_subject = ?, subject_pronoun_object = ?,
          subject_pronoun_possessive = ?, subject_birth_date = ?, subject_birthplace = ?,
          subject_death_date = ?, subject_short_bio = ?, closing_quote = ?,
          closing_quote_attribution = ?, ask_placeholder_text = ?, setup_completed_at = ?
         WHERE id = 1'
    );
    $stmt->execute([
        archive_trim_or_null($pick('site_name')) ?? '',
        archive_trim_or_null($pick('subject_name')) ?? '',
        archive_trim_or_null($pick('subject_pronoun_subject')) ?? 'they',
        archive_trim_or_null($pick('subject_pronoun_object')) ?? 'them',
        archive_trim_or_null($pick('subject_pronoun_possessive')) ?? 'their',
        archive_trim_or_null($pick('subject_birth_date')),
        archive_trim_or_null($pick('subject_birthplace')),
        archive_trim_or_null($pick('subject_death_date')),
        archive_trim_or_null($pick('subject_short_bio')),
        archive_trim_or_null($pick('closing_quote')),
        archive_trim_or_null($pick('closing_quote_attribution')),
        archive_trim_or_null($pick('ask_placeholder_text')),
        $setupCompletedAt,
    ]);

    // Site identity's death date and the subject-role person's own
    // People-tab fate field are otherwise unrelated columns in
    // different tables — sync them one-directionally so editing the
    // former updates the latter, since that's the natural expectation
    // (see archive_sync_subject_death_date()'s own comment for what
    // "sync" means here — it's a real overwrite, not a merge).
    $newDeathDate = array_key_exists('subject_death_date', $fields)
        ? archive_trim_or_null($fields['subject_death_date'])
        : null;
    $oldDeathDate = $current['subject_death_date'] ?? null;
    if ($newDeathDate !== null && $newDeathDate !== $oldDeathDate) {
        archive_sync_subject_death_date($newDeathDate);
    }

    return archive_site_settings();
}

// Overwrites the subject-role person's `fate` field to reflect a newly
// set site-identity death date — deliberately a real overwrite, not an
// attempt to merge into whatever narrative is already there (parsing
// free text reliably isn't possible, and a silent partial edit would be
// worse than an obvious one). If you've written a richer fate for them
// than "Survived. Died X.", re-apply it after changing the date. No-op
// if no subject-role person exists yet (e.g. in the setup wizard,
// before any People entries — this is also called from there via
// archive_site_settings_update()).
function archive_sync_subject_death_date(string $deathDate): void
{
    $subject = db()->query("SELECT * FROM people WHERE role = 'subject' LIMIT 1")->fetch();
    if (!$subject) {
        return;
    }
    archive_person_update($subject['id'], [
        'names' => json_decode((string) $subject['names'], true) ?: [],
        'role' => $subject['role'],
        'fate' => "Survived. Died {$deathDate}.",
        'notes' => $subject['notes'],
        'name_note' => $subject['name_note'],
        'source_note' => $subject['source_note'],
        'citation' => $subject['citation'],
    ]);
}

// --- Sources (the archive's source material — a list, not a fixed
// primary/secondary pair; see sql/schema.sql's comment on the table) ---

function archive_sources(): array
{
    return db()->query('SELECT * FROM sources ORDER BY sort_order ASC, created_at ASC')->fetchAll();
}

function archive_source_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM sources WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_source_create(array $fields): array
{
    $label = archive_trim_or_null($fields['label'] ?? null);
    if ($label === null) {
        throw new RuntimeException('A label is required.');
    }
    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM sources')->fetchColumn();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO sources (id, label, details, is_dramatization, permission_note, sort_order) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $label,
        archive_trim_or_null($fields['details'] ?? null),
        !empty($fields['is_dramatization']) ? 1 : 0,
        archive_trim_or_null($fields['permission_note'] ?? null),
        $maxOrder + 1,
    ]);
    return archive_source_find($id);
}

function archive_source_update(string $id, array $fields): array
{
    if (!archive_source_find($id)) {
        throw new RuntimeException('Source not found.');
    }
    $label = archive_trim_or_null($fields['label'] ?? null);
    if ($label === null) {
        throw new RuntimeException('A label is required.');
    }
    $stmt = db()->prepare('UPDATE sources SET label=?, details=?, is_dramatization=?, permission_note=? WHERE id=?');
    $stmt->execute([
        $label,
        archive_trim_or_null($fields['details'] ?? null),
        !empty($fields['is_dramatization']) ? 1 : 0,
        archive_trim_or_null($fields['permission_note'] ?? null),
        $id,
    ]);
    return archive_source_find($id);
}

function archive_source_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM sources WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// Swaps sort_order with the adjacent entry — same pattern as
// archive_timeline_move()/archive_audience_mode_move(). The FIRST source
// (after reordering) becomes the "primary" one knowledge_system_role()
// builds its main citation instruction around, so reordering here has
// real effect on the AI prompt, not just display order.
function archive_source_move(string $id, string $direction): void
{
    if (!in_array($direction, ['up', 'down'], true)) {
        throw new RuntimeException('Direction must be up or down.');
    }
    $rows = archive_sources();
    $index = null;
    foreach ($rows as $i => $row) {
        if ($row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        throw new RuntimeException('Source not found.');
    }
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($rows)) {
        return;
    }
    $a = $rows[$index];
    $b = $rows[$swapWith];
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE sources SET sort_order = ? WHERE id = ?');
    $stmt->execute([$b['sort_order'], $a['id']]);
    $stmt->execute([$a['sort_order'], $b['id']]);
}

function archive_audience_modes_rows(): array
{
    return db()->query('SELECT * FROM audience_modes ORDER BY sort_order ASC, created_at ASC')->fetchAll();
}

function archive_audience_mode_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM audience_modes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Categories are keyed by `slug` — the actual value stored in
// users.default_audience_mode and sent by the client, so unlike
// people/places' slug (display-only) this one is functionally load-
// bearing and must be unique. Derived from the label if not given
// explicitly, and — unlike label/ai_guidance — deliberately immutable
// after creation (see archive_audience_mode_update()): changing it out
// from under existing users' stored preference would silently orphan
// their choice.
function archive_slugify_mode(string $input): string
{
    $slug = archive_slug_or_null($input);
    if ($slug === null) {
        throw new RuntimeException('Could not derive a valid identifier from that label.');
    }
    return $slug;
}

function archive_audience_mode_create(array $fields): array
{
    $label = archive_trim_or_null($fields['label'] ?? null);
    $guidance = archive_trim_or_null($fields['ai_guidance'] ?? null);
    if ($label === null || $guidance === null) {
        throw new RuntimeException('Label and AI guidance are required.');
    }
    $slug = archive_slugify_mode(archive_trim_or_null($fields['slug'] ?? null) ?? $label);
    $exists = db()->prepare('SELECT id FROM audience_modes WHERE slug = ?');
    $exists->execute([$slug]);
    if ($exists->fetch()) {
        throw new RuntimeException('A category with that identifier already exists — try a different label.');
    }

    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM audience_modes')->fetchColumn();
    $isFirst = (int) db()->query('SELECT COUNT(*) FROM audience_modes')->fetchColumn() === 0;
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO audience_modes (id, slug, label, ai_guidance, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $slug, $label, $guidance, $maxOrder + 1, $isFirst ? 1 : 0]);
    return archive_audience_mode_find($id);
}

// Label and guidance only — slug is immutable, see archive_slugify_mode().
function archive_audience_mode_update(string $id, array $fields): array
{
    if (!archive_audience_mode_find($id)) {
        throw new RuntimeException('Audience category not found.');
    }
    $label = archive_trim_or_null($fields['label'] ?? null);
    $guidance = archive_trim_or_null($fields['ai_guidance'] ?? null);
    if ($label === null || $guidance === null) {
        throw new RuntimeException('Label and AI guidance are required.');
    }
    $stmt = db()->prepare('UPDATE audience_modes SET label = ?, ai_guidance = ? WHERE id = ?');
    $stmt->execute([$label, $guidance, $id]);
    return archive_audience_mode_find($id);
}

// At least one category must always exist (the Ask tab / signup
// dropdown would otherwise have nothing to offer), and deleting the
// current default promotes the next one rather than leaving none set.
function archive_audience_mode_delete(string $id): bool
{
    $count = (int) db()->query('SELECT COUNT(*) FROM audience_modes')->fetchColumn();
    if ($count <= 1) {
        throw new RuntimeException('At least one audience category must remain.');
    }
    $row = archive_audience_mode_find($id);
    if (!$row) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM audience_modes WHERE id = ?');
    $stmt->execute([$id]);
    if ((int) $row['is_default'] === 1) {
        $next = db()->query('SELECT id FROM audience_modes ORDER BY sort_order ASC, created_at ASC LIMIT 1')->fetchColumn();
        if ($next) {
            db()->prepare('UPDATE audience_modes SET is_default = 1 WHERE id = ?')->execute([$next]);
        }
    }
    return true;
}

function archive_audience_mode_move(string $id, string $direction): void
{
    if (!in_array($direction, ['up', 'down'], true)) {
        throw new RuntimeException('Direction must be up or down.');
    }
    $rows = archive_audience_modes_rows();
    $index = null;
    foreach ($rows as $i => $row) {
        if ($row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        throw new RuntimeException('Audience category not found.');
    }
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($rows)) {
        return;
    }
    $a = $rows[$index];
    $b = $rows[$swapWith];
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE audience_modes SET sort_order = ? WHERE id = ?');
    $stmt->execute([$b['sort_order'], $a['id']]);
    $stmt->execute([$a['sort_order'], $b['id']]);
}

function archive_audience_mode_set_default(string $id): void
{
    if (!archive_audience_mode_find($id)) {
        throw new RuntimeException('Audience category not found.');
    }
    $pdo = db();
    $pdo->exec('UPDATE audience_modes SET is_default = 0');
    $pdo->prepare('UPDATE audience_modes SET is_default = 1 WHERE id = ?')->execute([$id]);
}

// --- JSON-ready shapes for api/data.php (Browse tab) ---
// Field names deliberately match the DB columns rather than the old
// YAML files' naming — js/app.js is updated to match in the branding/
// genericization pass (see the open-source plan's Phase 3), not here.

function archive_person_public(array $row): array
{
    return [
        'id' => $row['id'],
        'names' => json_decode((string) $row['names'], true) ?: [],
        'role' => $row['role'],
        'fate' => $row['fate'],
        'notes' => $row['notes'],
        'name_note' => $row['name_note'],
        'source_note' => $row['source_note'],
        'citation' => $row['citation'],
        'relatedContent' => archive_content_links_public('person', $row['id']),
    ];
}

function archive_place_public(array $row): array
{
    return [
        'id' => $row['id'],
        'names' => json_decode((string) $row['names'], true) ?: [],
        'wartime_country' => $row['wartime_country'],
        'modern_country' => $row['modern_country'],
        'approx_coords' => $row['approx_coords'],
        'role' => $row['role'],
        'notes' => $row['notes'],
        'source_note' => $row['source_note'],
        'citation' => $row['citation'],
        'relatedContent' => archive_content_links_public('place', $row['id']),
    ];
}

function archive_timeline_entry_public(array $row): array
{
    return [
        'id' => $row['id'],
        'date_label' => $row['date_label'],
        'event' => $row['event'],
        'source_note' => $row['source_note'],
        'confidence' => $row['confidence'],
        'note' => $row['note'],
        'historical_date' => $row['historical_date'],
        'historical_source' => $row['historical_source'],
        'citation' => $row['citation'],
        'relatedContent' => archive_content_links_public('timeline', $row['id']),
    ];
}

function archive_quote_public(array $row): array
{
    return [
        'id' => $row['id'],
        'speaker' => $row['speaker'],
        'source_note' => $row['source_note'],
        'tags' => json_decode((string) ($row['tags'] ?? '[]'), true) ?: [],
        'quote' => $row['quote_text'],
        'citation' => $row['citation'],
        'video' => archive_quote_video_link($row),
        'relatedContent' => archive_content_links_public('quote', $row['id']),
    ];
}

// Best-effort "watch the moment this quote comes from" link for the
// Quotes tab's Watch video button — same "Tape N" parse js/app.js
// already does client-side for the View in transcript button, matched
// against a Content Library video item whose title names that tape
// (see the "VHA Interview 14091 — Tape N" naming convention), then the
// same verbatim-substring matching includes/video_seek.php uses for
// Ask-tab citations, just triggered from a stored quote instead of a
// fresh reply. Returns null if source_note doesn't name a tape, or no
// video exists for that tape. seekSeconds inside a non-null result may
// itself be null if the quote's stored text isn't a verbatim substring
// of that tape's transcript (paraphrased, or transcribed differently
// than the VHA transcript) — the video still opens in that case, just
// at 0:00, same fallback the Ask tab already uses.
function archive_quote_video_link(array $row): ?array
{
    if (!preg_match('/Tape\s+(\d+)/i', (string) ($row['source_note'] ?? ''), $m)) {
        return null;
    }
    $tapeNum = $m[1];

    static $videos = null;
    if ($videos === null) {
        $videos = db()->query("SELECT * FROM content_items WHERE type = 'video' ORDER BY created_at ASC")->fetchAll();
    }

    $video = null;
    foreach ($videos as $candidate) {
        if (preg_match('/\bTape\s*' . preg_quote($tapeNum, '/') . '\b/i', (string) $candidate['title'])) {
            $video = $candidate;
            break;
        }
    }
    if (!$video) {
        return null;
    }
    $videoFile = content_files_for_item($video['id'])[0] ?? null;
    if (!$videoFile) {
        return null;
    }

    $segments = video_seek_transcript_segments_for_file($videoFile['id']);
    $seconds = $segments ? video_seek_match_segment($segments, ['text' => (string) $row['quote_text']]) : null;

    return ['fileId' => $videoFile['id'], 'seekSeconds' => $seconds];
}

// api/data.php?name=transcript's JSON shape — subject speaker turns are
// labeled from site_settings.subject_name (falling back to "Subject" if
// identity setup hasn't happened yet) rather than a hardcoded name.
function archive_transcript_payload(): array
{
    $testimony = archive_primary_testimony();
    $settings = archive_site_settings();
    $subjectLabel = ($settings && $settings['subject_name']) ? $settings['subject_name'] : 'Subject';

    $tapes = ($testimony && $testimony['raw_markdown'])
        ? transcript_parse_tapes($testimony['raw_markdown'], $subjectLabel)
        : [];

    return [
        'interview_label' => $testimony['interview_label'] ?? null,
        'interview_date' => $testimony['interview_date'] ?? null,
        'location' => $testimony['location'] ?? null,
        'interviewer' => $testimony['interviewer'] ?? null,
        'videographer' => $testimony['videographer'] ?? null,
        'length_label' => $testimony['length_label'] ?? null,
        'tapes' => $tapes,
    ];
}

// api/data.php?name=discrepancies's JSON shape.
function archive_discrepancies_payload(): array
{
    $row = archive_discrepancy_notes();
    $markdown = ($row && $row['content_markdown']) ? $row['content_markdown'] : '';
    return ['html' => $markdown !== '' ? markdown_lite_render($markdown) : ''];
}

// --- Shared validation/normalization helpers ---

function archive_trim_or_null($value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim((string) $value);
    return $trimmed !== '' ? $trimmed : null;
}

// Accepts a real array (from a JSON request body) or a comma-separated
// string (simplest way for a plain HTML form field to submit multiple
// names) — trims each, drops empties, preserves order.
function archive_normalize_names($raw): array
{
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $n) {
        $n = trim((string) $n);
        if ($n !== '') {
            $out[] = $n;
        }
    }
    return $out;
}

// Same idea as archive_normalize_names() but for quotes.tags.
function archive_normalize_tags($raw): array
{
    return archive_normalize_names($raw);
}

// $slug is intentionally NOT exposed in the admin Archive Editor's
// forms — the UUID primary key is enough for admin operations. This
// existed for the retired legacy-YAML importer's benefit (so an
// imported row could keep its old human-readable `id:` for reference,
// see sql/schema.sql's comment on the people table); every write path
// here still defaults it to null rather than deriving one automatically.
function archive_slug_or_null($value): ?string
{
    $trimmed = archive_trim_or_null($value);
    if ($trimmed === null) {
        return null;
    }
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $trimmed));
    $slug = trim($slug, '_');
    return $slug !== '' ? $slug : null;
}

// --- People ---

function archive_person_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM people WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_person_create(array $fields): array
{
    $names = archive_normalize_names($fields['names'] ?? []);
    if (!$names) {
        throw new RuntimeException('At least one name is required.');
    }
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO people (id, slug, names, role, fate, notes, name_note, source_note, citation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        archive_slug_or_null($fields['slug'] ?? null),
        json_encode($names, JSON_UNESCAPED_UNICODE),
        archive_trim_or_null($fields['role'] ?? null),
        archive_trim_or_null($fields['fate'] ?? null),
        archive_trim_or_null($fields['notes'] ?? null),
        archive_trim_or_null($fields['name_note'] ?? null),
        archive_trim_or_null($fields['source_note'] ?? null),
        archive_trim_or_null($fields['citation'] ?? null),
    ]);
    return archive_person_find($id);
}

function archive_person_update(string $id, array $fields): array
{
    if (!archive_person_find($id)) {
        throw new RuntimeException('Person not found.');
    }
    $names = archive_normalize_names($fields['names'] ?? []);
    if (!$names) {
        throw new RuntimeException('At least one name is required.');
    }
    $stmt = db()->prepare(
        'UPDATE people SET names=?, role=?, fate=?, notes=?, name_note=?, source_note=?, citation=? WHERE id=?'
    );
    $stmt->execute([
        json_encode($names, JSON_UNESCAPED_UNICODE),
        archive_trim_or_null($fields['role'] ?? null),
        archive_trim_or_null($fields['fate'] ?? null),
        archive_trim_or_null($fields['notes'] ?? null),
        archive_trim_or_null($fields['name_note'] ?? null),
        archive_trim_or_null($fields['source_note'] ?? null),
        archive_trim_or_null($fields['citation'] ?? null),
        $id,
    ]);
    return archive_person_find($id);
}

function archive_person_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM people WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// --- Places ---

function archive_place_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM places WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_place_create(array $fields): array
{
    $names = archive_normalize_names($fields['names'] ?? []);
    if (!$names) {
        throw new RuntimeException('At least one name is required.');
    }
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO places (id, slug, names, wartime_country, modern_country, approx_coords, role, notes, source_note, citation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        archive_slug_or_null($fields['slug'] ?? null),
        json_encode($names, JSON_UNESCAPED_UNICODE),
        archive_trim_or_null($fields['wartime_country'] ?? null),
        archive_trim_or_null($fields['modern_country'] ?? null),
        archive_trim_or_null($fields['approx_coords'] ?? null),
        archive_trim_or_null($fields['role'] ?? null),
        archive_trim_or_null($fields['notes'] ?? null),
        archive_trim_or_null($fields['source_note'] ?? null),
        archive_trim_or_null($fields['citation'] ?? null),
    ]);
    return archive_place_find($id);
}

function archive_place_update(string $id, array $fields): array
{
    if (!archive_place_find($id)) {
        throw new RuntimeException('Place not found.');
    }
    $names = archive_normalize_names($fields['names'] ?? []);
    if (!$names) {
        throw new RuntimeException('At least one name is required.');
    }
    $stmt = db()->prepare(
        'UPDATE places SET names=?, wartime_country=?, modern_country=?, approx_coords=?, role=?, notes=?, source_note=?, citation=? WHERE id=?'
    );
    $stmt->execute([
        json_encode($names, JSON_UNESCAPED_UNICODE),
        archive_trim_or_null($fields['wartime_country'] ?? null),
        archive_trim_or_null($fields['modern_country'] ?? null),
        archive_trim_or_null($fields['approx_coords'] ?? null),
        archive_trim_or_null($fields['role'] ?? null),
        archive_trim_or_null($fields['notes'] ?? null),
        archive_trim_or_null($fields['source_note'] ?? null),
        archive_trim_or_null($fields['citation'] ?? null),
        $id,
    ]);
    return archive_place_find($id);
}

function archive_place_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM places WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// --- Timeline ---

function archive_timeline_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM timeline_entries WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_timeline_create(array $fields): array
{
    $dateLabel = archive_trim_or_null($fields['date_label'] ?? null);
    $event = archive_trim_or_null($fields['event'] ?? null);
    if ($dateLabel === null || $event === null) {
        throw new RuntimeException('Date and event are required.');
    }
    $confidence = archive_trim_or_null($fields['confidence'] ?? null);
    if ($confidence !== null && !in_array($confidence, ['high', 'medium', 'low'], true)) {
        throw new RuntimeException('Confidence must be high, medium, or low.');
    }
    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM timeline_entries')->fetchColumn();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO timeline_entries (id, sort_order, date_label, event, source_note, confidence, note, historical_date, historical_source, citation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $maxOrder + 1,
        $dateLabel,
        $event,
        archive_trim_or_null($fields['source_note'] ?? null),
        $confidence,
        archive_trim_or_null($fields['note'] ?? null),
        archive_trim_or_null($fields['historical_date'] ?? null),
        archive_trim_or_null($fields['historical_source'] ?? null),
        archive_trim_or_null($fields['citation'] ?? null),
    ]);
    return archive_timeline_find($id);
}

function archive_timeline_update(string $id, array $fields): array
{
    if (!archive_timeline_find($id)) {
        throw new RuntimeException('Timeline entry not found.');
    }
    $dateLabel = archive_trim_or_null($fields['date_label'] ?? null);
    $event = archive_trim_or_null($fields['event'] ?? null);
    if ($dateLabel === null || $event === null) {
        throw new RuntimeException('Date and event are required.');
    }
    $confidence = archive_trim_or_null($fields['confidence'] ?? null);
    if ($confidence !== null && !in_array($confidence, ['high', 'medium', 'low'], true)) {
        throw new RuntimeException('Confidence must be high, medium, or low.');
    }
    $stmt = db()->prepare(
        'UPDATE timeline_entries SET date_label=?, event=?, source_note=?, confidence=?, note=?, historical_date=?, historical_source=?, citation=? WHERE id=?'
    );
    $stmt->execute([
        $dateLabel,
        $event,
        archive_trim_or_null($fields['source_note'] ?? null),
        $confidence,
        archive_trim_or_null($fields['note'] ?? null),
        archive_trim_or_null($fields['historical_date'] ?? null),
        archive_trim_or_null($fields['historical_source'] ?? null),
        archive_trim_or_null($fields['citation'] ?? null),
        $id,
    ]);
    return archive_timeline_find($id);
}

function archive_timeline_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM timeline_entries WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// Swaps sort_order with the adjacent entry — simpler and far more usable
// for a non-technical admin than hand-entering a raw sort_order number,
// same "explicit, admin-adjustable order" idea as content_files.sort_order.
function archive_timeline_move(string $id, string $direction): void
{
    if (!in_array($direction, ['up', 'down'], true)) {
        throw new RuntimeException('Direction must be up or down.');
    }
    $rows = archive_timeline();
    $index = null;
    foreach ($rows as $i => $row) {
        if ($row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        throw new RuntimeException('Timeline entry not found.');
    }
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($rows)) {
        return; // already at that end — a no-op, not an error
    }
    $a = $rows[$index];
    $b = $rows[$swapWith];
    $pdo = db();
    $stmt = $pdo->prepare('UPDATE timeline_entries SET sort_order = ? WHERE id = ?');
    $stmt->execute([$b['sort_order'], $a['id']]);
    $stmt->execute([$a['sort_order'], $b['id']]);
}

// --- Quotes ---

function archive_quote_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM quotes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_quote_create(array $fields): array
{
    $speaker = archive_trim_or_null($fields['speaker'] ?? null);
    $quoteText = archive_trim_or_null($fields['quote_text'] ?? null);
    if ($speaker === null || $quoteText === null) {
        throw new RuntimeException('Speaker and quote text are required.');
    }
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO quotes (id, speaker, source_note, tags, quote_text, citation) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $speaker,
        archive_trim_or_null($fields['source_note'] ?? null),
        json_encode(archive_normalize_tags($fields['tags'] ?? []), JSON_UNESCAPED_UNICODE),
        $quoteText,
        archive_trim_or_null($fields['citation'] ?? null),
    ]);
    return archive_quote_find($id);
}

function archive_quote_update(string $id, array $fields): array
{
    if (!archive_quote_find($id)) {
        throw new RuntimeException('Quote not found.');
    }
    $speaker = archive_trim_or_null($fields['speaker'] ?? null);
    $quoteText = archive_trim_or_null($fields['quote_text'] ?? null);
    if ($speaker === null || $quoteText === null) {
        throw new RuntimeException('Speaker and quote text are required.');
    }
    $stmt = db()->prepare(
        'UPDATE quotes SET speaker=?, source_note=?, tags=?, quote_text=?, citation=? WHERE id=?'
    );
    $stmt->execute([
        $speaker,
        archive_trim_or_null($fields['source_note'] ?? null),
        json_encode(archive_normalize_tags($fields['tags'] ?? []), JSON_UNESCAPED_UNICODE),
        $quoteText,
        archive_trim_or_null($fields['citation'] ?? null),
        $id,
    ]);
    return archive_quote_find($id);
}

function archive_quote_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM quotes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

// --- Primary testimony & discrepancy notes (single-row "settings-style"
// tables — always id=1, no create/delete, just an update) ---

function archive_primary_testimony_update(array $fields): array
{
    $stmt = db()->prepare(
        'REPLACE INTO primary_testimony (id, interview_label, interview_date, location, interviewer, videographer, length_label, raw_markdown)
         VALUES (1, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        archive_trim_or_null($fields['interview_label'] ?? null),
        archive_trim_or_null($fields['interview_date'] ?? null),
        archive_trim_or_null($fields['location'] ?? null),
        archive_trim_or_null($fields['interviewer'] ?? null),
        archive_trim_or_null($fields['videographer'] ?? null),
        archive_trim_or_null($fields['length_label'] ?? null),
        archive_trim_or_null($fields['raw_markdown'] ?? null),
    ]);
    return archive_primary_testimony();
}

function archive_discrepancy_notes_update(array $fields): array
{
    $stmt = db()->prepare('REPLACE INTO discrepancy_notes (id, content_markdown) VALUES (1, ?)');
    $stmt->execute([archive_trim_or_null($fields['content_markdown'] ?? null)]);
    return archive_discrepancy_notes();
}

// --- Content links (content_items <-> people/places/timeline/quotes) ---
// See sql/schema.sql's content_links comment for the full rationale.

// $links is the AI-suggested list from narrative_analyze() — validates
// each entry's type/id against the real tables before storing, so a
// hallucinated or stale id is silently dropped rather than stored.
function archive_content_links_apply(string $contentItemId, array $links): void
{
    $finders = [
        'person' => 'archive_person_find',
        'place' => 'archive_place_find',
        'timeline' => 'archive_timeline_find',
        'quote' => 'archive_quote_find',
    ];
    foreach ($links as $link) {
        $type = $link['type'] ?? null;
        $id = $link['id'] ?? null;
        if (!isset($finders[$type]) || !is_string($id) || $id === '') {
            continue;
        }
        if (!$finders[$type]($id)) {
            continue;
        }
        $stmt = db()->prepare(
            'INSERT IGNORE INTO content_links (id, content_item_id, entity_type, entity_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([make_uuid(), $contentItemId, $type, $id]);
    }
}

// Re-running analysis (e.g. the admin backfill action) should replace
// this item's links rather than accumulate duplicates across runs.
function archive_content_links_clear_for_item(string $contentItemId): void
{
    db()->prepare('DELETE FROM content_links WHERE content_item_id = ?')->execute([$contentItemId]);
}

// Content items linked to a given archive entity, each with its first
// file's id/mime type (for display — see archive_content_links_public()
// below) so e.g. the Timeline tab can show a thumbnail/link without a
// second round-trip.
function archive_content_links_for_entity(string $entityType, string $entityId): array
{
    $stmt = db()->prepare(
        "SELECT ci.id, ci.type, ci.title, ci.source_url,
                (SELECT cf.id FROM content_files cf WHERE cf.content_item_id = ci.id ORDER BY cf.sort_order ASC LIMIT 1) AS file_id,
                (SELECT cf.mime_type FROM content_files cf WHERE cf.content_item_id = ci.id ORDER BY cf.sort_order ASC LIMIT 1) AS mime_type
         FROM content_links cl
         JOIN content_items ci ON ci.id = cl.content_item_id
         WHERE cl.entity_type = ? AND cl.entity_id = ?
         ORDER BY ci.created_at ASC"
    );
    $stmt->execute([$entityType, $entityId]);
    return $stmt->fetchAll();
}

function archive_content_links_public(string $entityType, string $entityId): array
{
    return array_map(static fn (array $row): array => [
        'id' => $row['id'],
        'type' => $row['type'],
        'title' => $row['title'],
        'fileId' => $row['file_id'],
        'sourceUrl' => $row['source_url'],
        'isVideo' => $row['mime_type'] !== null && str_starts_with((string) $row['mime_type'], 'video/'),
    ], archive_content_links_for_entity($entityType, $entityId));
}

// --- Plain-text formatting for the AI context (includes/knowledge.php) ---
// Mirrors the old YAML files' own hand-written text shape closely enough
// that the system prompt's existing expectations (citing "the timeline
// entry for X", tape/source citations, etc.) keep working, without
// actually being YAML — just readable structured text.

function archive_format_people_for_context(array $rows): string
{
    $out = '';
    foreach ($rows as $row) {
        $names = json_decode((string) $row['names'], true) ?: [];
        $out .= "- id: {$row['id']}\n";
        $out .= "  names: " . implode(', ', $names) . "\n";
        if ($row['role']) {
            $out .= "  role: {$row['role']}\n";
        }
        if ($row['fate']) {
            $out .= "  fate: {$row['fate']}\n";
        }
        if ($row['name_note']) {
            $out .= "  name_note: {$row['name_note']}\n";
        }
        if ($row['notes']) {
            $out .= "  notes: {$row['notes']}\n";
        }
        if ($row['source_note']) {
            $out .= "  source_note: {$row['source_note']}\n";
        }
        if ($row['citation']) {
            $out .= "  citation: {$row['citation']}\n";
        }
        $out .= "\n";
    }
    return $out;
}

function archive_format_places_for_context(array $rows): string
{
    $out = '';
    foreach ($rows as $row) {
        $names = json_decode((string) $row['names'], true) ?: [];
        $out .= "- id: {$row['id']}\n";
        $out .= "  names: " . implode(', ', $names) . "\n";
        foreach (['wartime_country', 'modern_country', 'approx_coords', 'role', 'notes', 'source_note', 'citation'] as $key) {
            if (!empty($row[$key])) {
                $out .= "  $key: {$row[$key]}\n";
            }
        }
        $out .= "\n";
    }
    return $out;
}

function archive_format_timeline_for_context(array $rows): string
{
    $out = '';
    foreach ($rows as $row) {
        $out .= "- id: {$row['id']}\n";
        $out .= "  date: {$row['date_label']}\n";
        $out .= "  event: {$row['event']}\n";
        foreach (['source_note', 'confidence', 'note', 'historical_date', 'historical_source', 'citation'] as $key) {
            if (!empty($row[$key])) {
                $out .= "  $key: {$row[$key]}\n";
            }
        }
        $out .= "\n";
    }
    return $out;
}

function archive_format_quotes_for_context(array $rows): string
{
    $out = '';
    foreach ($rows as $row) {
        $tags = json_decode((string) ($row['tags'] ?? '[]'), true) ?: [];
        $out .= "- id: {$row['id']}\n";
        $out .= "  speaker: {$row['speaker']}\n";
        if ($row['source_note']) {
            $out .= "  source_note: {$row['source_note']}\n";
        }
        if ($tags) {
            $out .= '  tags: ' . implode(', ', $tags) . "\n";
        }
        $out .= "  quote: {$row['quote_text']}\n";
        if ($row['citation']) {
            $out .= "  citation: {$row['citation']}\n";
        }
        $out .= "\n";
    }
    return $out;
}
