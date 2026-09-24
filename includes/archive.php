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
    $stmt = db()->prepare('SELECT * FROM people WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

function archive_places(): array
{
    $stmt = db()->prepare('SELECT * FROM places WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

function archive_timeline(): array
{
    $stmt = db()->prepare('SELECT * FROM timeline_entries WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

function archive_quotes(): array
{
    $stmt = db()->prepare('SELECT * FROM quotes WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

// primary_testimony/discrepancy_notes/site_settings are one row PER
// SUBJECT (subject_id is their primary key — see sql/schema.sql), not a
// fixed id=1 singleton as before multi-subject support.
function archive_primary_testimony(): ?array
{
    $stmt = db()->prepare('SELECT * FROM primary_testimony WHERE subject_id = ?');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetch() ?: null;
}

// Annotates each "## Tape N" section of the primary testimony transcript
// (see transcript_parse_tapes()'s same header convention) with the
// matching Content Library video's file id, so knowledge.php's
// knowledge_context() can feed the model an id= hint right next to the
// verbatim testimony text it's most likely to quote from — without this,
// a quote drawn from THIS block (as opposed to a separately-uploaded
// Content Library transcript, which already gets a companion note via
// content_context()) had no id available at all, so the model could
// never attach a [[video:ID]] token to it (see knowledge_system_role()'s
// rule for that token) no matter how well it followed the citation
// instruction. Sections with no matching video (or malformed markdown,
// no "## Tape N" headers) pass through unchanged.
function archive_testimony_text_with_video_ids(string $markdown): string
{
    return (string) preg_replace_callback(
        '/^(##\s*Tape\s+(\d+)[^\n]*)$/mi',
        static function (array $m): string {
            $videoFile = archive_video_file_for_tape($m[2]);
            return $videoFile
                ? $m[1] . "\nA companion video of this testimony exists: id={$videoFile['id']}."
                : $m[1];
        },
        $markdown
    );
}

function archive_discrepancy_notes(): ?array
{
    $stmt = db()->prepare('SELECT * FROM discrepancy_notes WHERE subject_id = ?');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetch() ?: null;
}

function archive_site_settings(): ?array
{
    $stmt = db()->prepare('SELECT * FROM site_settings WHERE subject_id = ?');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetch() ?: null;
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

// Regenerates this subject's static manifest-{slug}.json file on disk
// from site_settings — called whenever site identity changes
// (admin_settings.php's save handler, and the setup wizard's finalize
// step). Per-subject filename (rather than a single shared
// manifest.json) since multiple subjects share one webroot — a fixed
// filename would have the last subject to save overwrite every other
// subject's manifest. Still written to disk rather than served
// dynamically, keeping the app's "no mod_rewrite" design principle
// intact — index.php's <link rel="manifest"> just needs a per-subject
// href, computed the same way any other per-request value already is.
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
        __DIR__ . '/../manifest-' . current_subject()['slug'] . '.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

// The URL index.php's <link rel="manifest"> points at — a small helper
// so every caller (just index.php today) computes the same filename
// manifest_regenerate() actually writes to.
function manifest_url(): string
{
    return '/manifest-' . current_subject()['slug'] . '.json';
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
    // the row, so any column not in its explicit list (or any future
    // column this function's author forgets to add here) silently
    // reverts to its schema DEFAULT. Found via testing (back when this
    // table still had a schema_version column): it was getting reset to
    // '1.0.0' on every settings save. INSERT IGNORE first guarantees
    // this subject's row exists (a brand-new subject, before its
    // identity stage, has no row yet) without clobbering it if it
    // already does.
    $subjectId = current_subject_id();
    db()->prepare('INSERT IGNORE INTO site_settings (subject_id) VALUES (?)')->execute([$subjectId]);
    $stmt = db()->prepare(
        'UPDATE site_settings SET
          site_name = ?, subject_name = ?, subject_pronoun_subject = ?, subject_pronoun_object = ?,
          subject_pronoun_possessive = ?, subject_birth_date = ?, subject_birthplace = ?,
          subject_death_date = ?, subject_short_bio = ?, closing_quote = ?,
          closing_quote_attribution = ?, ask_placeholder_text = ?, setup_completed_at = ?
         WHERE subject_id = ?'
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
        $subjectId,
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
    $stmt = db()->prepare("SELECT * FROM people WHERE subject_id = ? AND role = 'subject' LIMIT 1");
    $stmt->execute([current_subject_id()]);
    $subject = $stmt->fetch();
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
    $stmt = db()->prepare('SELECT * FROM sources WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

function archive_source_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM sources WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_source_create(array $fields): array
{
    $label = archive_trim_or_null($fields['label'] ?? null);
    if ($label === null) {
        throw new RuntimeException('A label is required.');
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM sources WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $maxOrder = (int) $stmt->fetchColumn();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO sources (id, subject_id, label, details, is_dramatization, permission_note, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $subjectId,
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
    $stmt = db()->prepare('UPDATE sources SET label=?, details=?, is_dramatization=?, permission_note=? WHERE id=? AND subject_id=?');
    $stmt->execute([
        $label,
        archive_trim_or_null($fields['details'] ?? null),
        !empty($fields['is_dramatization']) ? 1 : 0,
        archive_trim_or_null($fields['permission_note'] ?? null),
        $id,
        current_subject_id(),
    ]);
    return archive_source_find($id);
}

function archive_source_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM sources WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
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
    $stmt = $pdo->prepare('UPDATE sources SET sort_order = ? WHERE id = ? AND subject_id = ?');
    $stmt->execute([$b['sort_order'], $a['id'], current_subject_id()]);
    $stmt->execute([$a['sort_order'], $b['id'], current_subject_id()]);
}

function archive_audience_modes_rows(): array
{
    $stmt = db()->prepare('SELECT * FROM audience_modes WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

function archive_audience_mode_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM audience_modes WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
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
    $subjectId = current_subject_id();
    $slug = archive_slugify_mode(archive_trim_or_null($fields['slug'] ?? null) ?? $label);
    $exists = db()->prepare('SELECT id FROM audience_modes WHERE subject_id = ? AND slug = ?');
    $exists->execute([$subjectId, $slug]);
    if ($exists->fetch()) {
        throw new RuntimeException('A category with that identifier already exists — try a different label.');
    }

    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM audience_modes WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $maxOrder = (int) $stmt->fetchColumn();
    $stmt = db()->prepare('SELECT COUNT(*) FROM audience_modes WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $isFirst = (int) $stmt->fetchColumn() === 0;
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO audience_modes (id, subject_id, slug, label, ai_guidance, sort_order, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$id, $subjectId, $slug, $label, $guidance, $maxOrder + 1, $isFirst ? 1 : 0]);
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
    $stmt = db()->prepare('UPDATE audience_modes SET label = ?, ai_guidance = ? WHERE id = ? AND subject_id = ?');
    $stmt->execute([$label, $guidance, $id, current_subject_id()]);
    return archive_audience_mode_find($id);
}

// At least one category must always exist (the Ask tab / signup
// dropdown would otherwise have nothing to offer), and deleting the
// current default promotes the next one rather than leaving none set.
function archive_audience_mode_delete(string $id): bool
{
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT COUNT(*) FROM audience_modes WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    if ((int) $stmt->fetchColumn() <= 1) {
        throw new RuntimeException('At least one audience category must remain.');
    }
    $row = archive_audience_mode_find($id);
    if (!$row) {
        return false;
    }
    $stmt = db()->prepare('DELETE FROM audience_modes WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, $subjectId]);
    if ((int) $row['is_default'] === 1) {
        $stmt = db()->prepare('SELECT id FROM audience_modes WHERE subject_id = ? ORDER BY sort_order ASC, created_at ASC LIMIT 1');
        $stmt->execute([$subjectId]);
        $next = $stmt->fetchColumn();
        if ($next) {
            db()->prepare('UPDATE audience_modes SET is_default = 1 WHERE id = ? AND subject_id = ?')->execute([$next, $subjectId]);
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
    $stmt = $pdo->prepare('UPDATE audience_modes SET sort_order = ? WHERE id = ? AND subject_id = ?');
    $stmt->execute([$b['sort_order'], $a['id'], current_subject_id()]);
    $stmt->execute([$a['sort_order'], $b['id'], current_subject_id()]);
}

function archive_audience_mode_set_default(string $id): void
{
    if (!archive_audience_mode_find($id)) {
        throw new RuntimeException('Audience category not found.');
    }
    $subjectId = current_subject_id();
    $pdo = db();
    // Scoped to this subject only — an unscoped reset here would have
    // cleared every OTHER subject's default flag too.
    $pdo->prepare('UPDATE audience_modes SET is_default = 0 WHERE subject_id = ?')->execute([$subjectId]);
    $pdo->prepare('UPDATE audience_modes SET is_default = 1 WHERE id = ? AND subject_id = ?')->execute([$id, $subjectId]);
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
        'relatedContent' => archive_content_links_public('timeline', $row['id'], (string) $row['event'], $row['note'] !== null ? (string) $row['note'] : null),
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

// Looks up the Content Library video whose title names the given tape
// number (the "VHA Interview 14091 — Tape N" naming convention) and
// returns its first file's row, or null if no such video exists. Shared
// by archive_quote_video_link() below and knowledge.php's
// archive_testimony_text_with_video_ids() (which annotates the primary
// testimony transcript fed to the Ask tab's model with the same ids, so
// it has something to cite — see knowledge_system_role()'s [[video:ID]]
// rule).
function archive_video_file_for_tape(string $tapeNum): ?array
{
    static $videos = null;
    if ($videos === null) {
        $stmt = db()->prepare("SELECT * FROM content_items WHERE subject_id = ? AND type = 'video' ORDER BY created_at ASC");
        $stmt->execute([current_subject_id()]);
        $videos = $stmt->fetchAll();
    }

    foreach ($videos as $candidate) {
        if (preg_match('/\bTape\s*' . preg_quote($tapeNum, '/') . '\b/i', (string) $candidate['title'])) {
            return content_files_for_item($candidate['id'])[0] ?? null;
        }
    }
    return null;
}

// Best-effort "watch the moment this quote comes from" link for the
// Quotes tab's Watch video button — same "Tape N" parse js/app.js
// already does client-side for the View in transcript button, matched
// against a Content Library video item whose title names that tape,
// then the same verbatim-substring matching includes/video_seek.php uses
// for Ask-tab citations, just triggered from a stored quote instead of a
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
    $videoFile = archive_video_file_for_tape($m[1]);
    if (!$videoFile) {
        return null;
    }

    $segments = video_seek_transcript_segments_for_file($videoFile['id']);
    $seconds = $segments ? video_seek_match_segment($segments, ['text' => (string) $row['quote_text']]) : null;

    // Same segments already tell us whether pseudo closed captions exist
    // for this video (see video_seek_vtt_for_file()) — reusing them here
    // instead of a second lookup.
    return ['fileId' => $videoFile['id'], 'seekSeconds' => $seconds, 'hasCaptions' => !empty($segments)];
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
    $stmt = db()->prepare('SELECT * FROM people WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_person_create(array $fields): array
{
    $names = archive_normalize_names($fields['names'] ?? []);
    if (!$names) {
        throw new RuntimeException('At least one name is required.');
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM people WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $maxOrder = (int) $stmt->fetchColumn();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO people (id, subject_id, slug, sort_order, names, role, fate, notes, name_note, source_note, citation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $subjectId,
        archive_slug_or_null($fields['slug'] ?? null),
        $maxOrder + 1,
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
    $stmt = db()->prepare('DELETE FROM people WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    return $stmt->rowCount() > 0;
}

// See archive_move_row() (below, near archive_timeline_move()) for the
// shared swap logic.
function archive_person_move(string $id, string $direction): void
{
    archive_move_row('people', archive_people(), $id, $direction);
}

// --- Places ---

function archive_place_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM places WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_place_create(array $fields): array
{
    $names = archive_normalize_names($fields['names'] ?? []);
    if (!$names) {
        throw new RuntimeException('At least one name is required.');
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM places WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $maxOrder = (int) $stmt->fetchColumn();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO places (id, subject_id, slug, sort_order, names, wartime_country, modern_country, approx_coords, role, notes, source_note, citation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $subjectId,
        archive_slug_or_null($fields['slug'] ?? null),
        $maxOrder + 1,
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
    $stmt = db()->prepare('DELETE FROM places WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    return $stmt->rowCount() > 0;
}

// See archive_move_row() (below, near archive_timeline_move()) for the
// shared swap logic.
function archive_place_move(string $id, string $direction): void
{
    archive_move_row('places', archive_places(), $id, $direction);
}

// --- Timeline ---

function archive_timeline_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM timeline_entries WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
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
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM timeline_entries WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $maxOrder = (int) $stmt->fetchColumn();
    $id = make_uuid();
    $stmt = db()->prepare(
        'INSERT INTO timeline_entries (id, subject_id, sort_order, date_label, event, source_note, confidence, note, historical_date, historical_source, citation)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $subjectId,
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
    $stmt = db()->prepare('DELETE FROM timeline_entries WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    return $stmt->rowCount() > 0;
}

// Swaps sort_order with the adjacent row — simpler and far more usable
// for a non-technical admin than hand-entering a raw sort_order number,
// same "explicit, admin-adjustable order" idea as content_files.sort_order.
// Shared by every reorderable list (Timeline, People, Places, Quotes) —
// the swap logic is identical regardless of table, so this is the one
// implementation; each entity keeps its own thin archive_X_move()
// wrapper (below, and alongside people/places/quotes) so callers don't
// need to know $table/$rows plumbing details. $table is always one of
// this app's own hardcoded table names, never user input — same trust
// level as includes/backup.php's own table-name interpolation.
function archive_move_row(string $table, array $rows, string $id, string $direction): void
{
    if (!in_array($direction, ['up', 'down'], true)) {
        throw new RuntimeException('Direction must be up or down.');
    }
    $index = null;
    foreach ($rows as $i => $row) {
        if ($row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        throw new RuntimeException('Item not found.');
    }
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($rows)) {
        return; // already at that end — a no-op, not an error
    }
    $a = $rows[$index];
    $b = $rows[$swapWith];
    $stmt = db()->prepare("UPDATE $table SET sort_order = ? WHERE id = ? AND subject_id = ?");
    $stmt->execute([$b['sort_order'], $a['id'], current_subject_id()]);
    $stmt->execute([$a['sort_order'], $b['id'], current_subject_id()]);
}

function archive_timeline_move(string $id, string $direction): void
{
    archive_move_row('timeline_entries', archive_timeline(), $id, $direction);
}

// --- Quotes ---

function archive_quote_find(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM quotes WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Exact (trimmed) text match within this subject's own quote bank —
// catches both a human re-adding a line by hand and an AI suggestion
// proposing one already present (belt-and-suspenders alongside the
// prompt instruction in narrative_suggestions_system_prompt(), which
// can't be relied on alone since the model sometimes misses it — see
// CHANGELOG's entry on this). $excludeId lets an update check against
// every OTHER quote without flagging itself as its own duplicate.
function archive_quote_text_duplicate_exists(string $quoteText, ?string $excludeId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM quotes WHERE subject_id = ? AND quote_text = ?';
    $params = [current_subject_id(), $quoteText];
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function archive_quote_create(array $fields): array
{
    $speaker = archive_trim_or_null($fields['speaker'] ?? null);
    $quoteText = archive_trim_or_null($fields['quote_text'] ?? null);
    if ($speaker === null || $quoteText === null) {
        throw new RuntimeException('Speaker and quote text are required.');
    }
    if (archive_quote_text_duplicate_exists($quoteText)) {
        throw new RuntimeException('This exact quote is already in the archive — check the Quotes tab before adding it again.');
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM quotes WHERE subject_id = ?');
    $stmt->execute([$subjectId]);
    $maxOrder = (int) $stmt->fetchColumn();
    $id = make_uuid();
    $tags = archive_normalize_tags($fields['tags'] ?? []);
    keywords_ensure($tags); // joins the app-wide master list — see includes/keywords.php
    $stmt = db()->prepare(
        'INSERT INTO quotes (id, subject_id, sort_order, speaker, source_note, tags, quote_text, citation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $id,
        $subjectId,
        $maxOrder + 1,
        $speaker,
        archive_trim_or_null($fields['source_note'] ?? null),
        json_encode($tags, JSON_UNESCAPED_UNICODE),
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
    if (archive_quote_text_duplicate_exists($quoteText, $id)) {
        throw new RuntimeException('This exact quote is already in the archive — check the Quotes tab before adding it again.');
    }
    $tags = archive_normalize_tags($fields['tags'] ?? []);
    keywords_ensure($tags); // joins the app-wide master list — see includes/keywords.php
    $stmt = db()->prepare(
        'UPDATE quotes SET speaker=?, source_note=?, tags=?, quote_text=?, citation=? WHERE id=?'
    );
    $stmt->execute([
        $speaker,
        archive_trim_or_null($fields['source_note'] ?? null),
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        $quoteText,
        archive_trim_or_null($fields['citation'] ?? null),
        $id,
    ]);
    return archive_quote_find($id);
}

function archive_quote_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM quotes WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    return $stmt->rowCount() > 0;
}

// See archive_move_row() (below, near archive_timeline_move()) for the
// shared swap logic.
function archive_quote_move(string $id, string $direction): void
{
    archive_move_row('quotes', archive_quotes(), $id, $direction);
}

// --- Primary testimony & discrepancy notes (single-row "settings-style"
// tables — always id=1, no create/delete, just an update) ---

function archive_primary_testimony_update(array $fields): array
{
    $stmt = db()->prepare(
        'REPLACE INTO primary_testimony (subject_id, interview_label, interview_date, location, interviewer, videographer, length_label, raw_markdown)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        current_subject_id(),
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
    $stmt = db()->prepare('REPLACE INTO discrepancy_notes (subject_id, content_markdown) VALUES (?, ?)');
    $stmt->execute([current_subject_id(), archive_trim_or_null($fields['content_markdown'] ?? null)]);
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
            'INSERT IGNORE INTO content_links (id, subject_id, content_item_id, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([make_uuid(), current_subject_id(), $contentItemId, $type, $id]);
    }
}

// Re-running analysis (e.g. the admin backfill action) should replace
// this item's links rather than accumulate duplicates across runs.
function archive_content_links_clear_for_item(string $contentItemId): void
{
    $stmt = db()->prepare('DELETE FROM content_links WHERE content_item_id = ? AND subject_id = ?');
    $stmt->execute([$contentItemId, current_subject_id()]);
}

// Content items linked to a given archive entity, each with EVERY one of
// its files' ids (for display — see archive_content_links_public() below)
// so e.g. a linked multi-photo item (a scanned multi-page document
// imported as a photo album) can show/page through every page, not just
// its first file — a second round-trip per file isn't needed since
// content_files_for_item() is already a cheap indexed lookup.
function archive_content_links_for_entity(string $entityType, string $entityId): array
{
    $stmt = db()->prepare(
        "SELECT ci.id, ci.type, ci.title, ci.source_url
         FROM content_links cl
         JOIN content_items ci ON ci.id = cl.content_item_id
         WHERE cl.entity_type = ? AND cl.entity_id = ? AND cl.subject_id = ?
         ORDER BY ci.created_at ASC"
    );
    $stmt->execute([$entityType, $entityId, current_subject_id()]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $files = content_files_for_item($row['id']);
        $row['file_ids'] = array_column($files, 'id');
        $row['mime_type'] = $files[0]['mime_type'] ?? null;
    }
    unset($row);
    return $rows;
}

// $seekMatchText (optional): text belonging to the entity itself (e.g. a
// timeline entry's event description) to try to locate in a linked
// video's transcript, same verbatim-substring approach as
// archive_quote_video_link()/includes/video_seek.php, so the resulting
// link opens at that moment instead of always at 0:00. Only worth passing
// for entities whose own text plausibly appears in a testimony transcript
// (timeline entries); people/places/quotes either have their own
// dedicated seek link (quotes) or no comparable text to match.
// $seekMatchFallbackText (optional): tried only if $seekMatchText doesn't
// match anything — a timeline entry's own `event` text is usually a
// paraphrased summary that never appears verbatim in the transcript, but
// its `note` field occasionally quotes the subject directly (e.g. `Slava
// says "..."`), which the primary summary text can't be.
//
// If NEITHER text is a verbatim substring of the transcript (the normal
// case for a timeline entry — see video_seek_fuzzy_match_segment()'s own
// comment), falls back further to an approximate keyword-overlap match
// across both texts combined: still opening at a real, evidence-backed
// point in the video rather than always 0:00, which is the actual point
// of showing a video link at all, even for a paraphrased citation.
function archive_content_links_public(string $entityType, string $entityId, ?string $seekMatchText = null, ?string $seekMatchFallbackText = null): array
{
    return array_map(static function (array $row) use ($seekMatchText, $seekMatchFallbackText): array {
        $isVideo = $row['mime_type'] !== null && str_starts_with((string) $row['mime_type'], 'video/');
        $isAudio = $row['mime_type'] !== null && str_starts_with((string) $row['mime_type'], 'audio/');
        $seekSeconds = null;
        $hasCaptions = false;
        if ($isVideo && ($row['file_ids'][0] ?? null)) {
            $segments = video_seek_transcript_segments_for_file($row['file_ids'][0]);
            $hasCaptions = !empty($segments);
            if ($segments) {
                foreach ([$seekMatchText, $seekMatchFallbackText] as $candidate) {
                    if ($candidate === null || $candidate === '') {
                        continue;
                    }
                    $seekSeconds = video_seek_match_segment($segments, ['text' => $candidate]);
                    if ($seekSeconds !== null) {
                        break;
                    }
                }
                if ($seekSeconds === null) {
                    $combined = trim(($seekMatchText ?? '') . ' ' . ($seekMatchFallbackText ?? ''));
                    if ($combined !== '') {
                        $seekSeconds = video_seek_fuzzy_match_segment($segments, $combined);
                    }
                }
            }
        }
        return [
            'id' => $row['id'],
            'type' => $row['type'],
            'title' => $row['title'],
            'fileId' => $row['file_ids'][0] ?? null,
            // Only meaningfully more than one entry for a multi-photo item —
            // js/app.js's renderRelatedContent() uses this to show/page
            // through every page rather than just the first.
            'fileIds' => $row['file_ids'],
            'sourceUrl' => $row['source_url'],
            'isVideo' => $isVideo,
            'isAudio' => $isAudio,
            'seekSeconds' => $seekSeconds,
            'hasCaptions' => $hasCaptions,
        ];
    }, archive_content_links_for_entity($entityType, $entityId));
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
