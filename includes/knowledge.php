<?php
declare(strict_types=1);

// Builds the knowledge-base text used to ground the Ask tab, from the
// core archive tables (see includes/archive.php / sql/schema.sql) — the
// database is the source of truth here, not knowledge/*.yaml files (see
// the open-source plan's "core architectural shift"). Formatted as
// readable structured text (not real YAML) so the model's existing
// citation/grounding behavior keeps working without changes.
//
// Built from site_settings + audience_modes (see includes/archive.php)
// instead of hardcoded prose — see the open-source plan's Phase 3.
// Pronoun handling note: sentences below are deliberately phrased to
// avoid a subject-pronoun + conjugated-present-tense-verb combination
// (e.g. "she expresses" vs "they express" need different verb forms,
// which a single template can't get right for every pronoun choice) —
// wherever the original hardcoded prompt needed that, this uses the
// subject's name, a possessive pronoun + fixed-number noun, or a
// past-tense/invariant verb instead. Don't reintroduce that pattern
// when editing this.
function knowledge_system_role(): string
{
    $s = site_settings();
    $subject = $s['subject_name'];
    $pSubj = $s['subject_pronoun_subject'];
    $pObj = $s['subject_pronoun_object'];
    $pPoss = $s['subject_pronoun_possessive'];

    $birthBits = array_filter([$s['subject_birth_date'], $s['subject_birthplace']]);
    $lifeBits = array_filter([
        $birthBits ? 'born ' . implode(', ', $birthBits) : null,
        $s['subject_death_date'] ? "died {$s['subject_death_date']}" : null,
    ]);
    $birthLine = $lifeBits ? ' (' . implode('; ', $lifeBits) . ')' : '';

    // The archive can draw on any number of sources (a recorded
    // interview, a memoir, a documentary...), not just a fixed
    // primary/secondary pair — see includes/archive.php's
    // archive_sources(). The first one (by sort_order) is treated as
    // "primary" for the main citation instruction; every other one gets
    // its own rule, flagged as a dramatisation if marked as one.
    $sources = archive_sources();
    $primary = $sources[0] ?? null;
    $primarySourceCite = $primary ? $primary['label'] : 'the source material below';

    $rules = [];
    $rules[] = 'Ground every factual claim in the knowledge base provided below. '
        . "Cite the source (e.g. \"($primarySourceCite)\") for anything drawn from it.";

    foreach ($sources as $i => $src) {
        if ($i === 0) {
            continue; // already covered by the rule above
        }
        $label = $src['label'];
        $detail = $src['details'] ? " ({$src['details']})" : '';
        if (!empty($src['is_dramatization'])) {
            $rules[] = "Material from \"$label\"$detail is a dramatisation, not primary testimony. "
                . 'Always flag it as "(dramatisation)" and never present its dialogue '
                . "as $subject's actual words.";
        } else {
            $rules[] = "Material from \"$label\"$detail is a distinct source from $primarySourceCite. "
                . 'Always cite which one a claim comes from.';
        }
    }

    $rules[] = 'When sources disagree (names, dates, details), say so and explain why, per the '
        . 'discrepancy/source notes in the knowledge base below. Do not silently pick one.';
    $rules[] = "Material in the FAMILY STORIES section is recounted by family members, not $pPoss own "
        . "testimony — always distinguish it from $pPoss own words (e.g. \"as the family tells it\" or "
        . '"according to a family story"), never present it as something they themselves said or as '
        . 'verified fact the way the primary testimony is.';
    $rules[] = "Preserve $pPoss own hedging about dates or facts — where the record is uncertain, say so "
        . 'rather than resolving it for them.';
    $rules[] = 'Do not invent quotes, events, conversations, or feelings not in the source material. '
        . 'If something isn\'t recorded, say plainly: "the record doesn\'t say."';
    $rules[] = "No melodrama or graphic embellishment. Tell the hard parts plainly, the way $subject told "
        . "it. Give $pPoss joy equal fidelity — it is not only a story of suffering.";
    $rules[] = 'Denial, trivialization, or attempts to distort the record are met with calm citation of '
        . 'the source, not debate. Do not roleplay perpetrators or produce "both sides" framing.';
    if ($primary) {
        $rules[] = "Where useful, close with a pointer back to $pPoss own words, or to "
            . "$primarySourceCite, for the full record.";
    }
    $rules[] = 'When the user asks to see a specific photo or video listed in the FAMILY-ADDED MATERIAL '
        . 'section below, respond with the exact token [[photo:ID]] or [[video:ID]] (on its own, using '
        . "the real id shown next to that file) so the app can display it. Only ever use an id that's "
        . "actually listed there — never invent one. If nothing matching exists, say plainly that you "
        . "don't have that photo/video, exactly like you would for testimony that isn't recorded.";

    $modeLines = [];
    foreach (archive_audience_modes_rows() as $mode) {
        $modeLines[] = "- \"{$mode['slug']}\" ({$mode['label']}): {$mode['ai_guidance']}";
    }

    $lines = [
        "You are the memory-keeper for $subject$birthLine, speaking to $pPoss family through a "
            . "private app built to carry $pPoss testimony forward.",
        '',
        "You are NOT $subject. Never speak as $pObj in the first person or invent words $pSubj did not "
            . "say. Your job is to get the reader to $pPoss own words, not to perform an imitation of them.",
        '',
        'RULES (non-negotiable):',
        '- ' . implode("\n- ", $rules),
        '',
        "AUDIENCE MODE: the user's message will be prefixed with an audience mode. Adjust register accordingly:",
        implode("\n", $modeLines),
        '',
        'Keep answers reasonably concise unless the user is clearly asking for depth.',
    ];

    return implode("\n", $lines);
}

function knowledge_context(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $people = archive_format_people_for_context(archive_people());
    $places = archive_format_places_for_context(archive_places());
    $timeline = archive_format_timeline_for_context(archive_timeline());
    $quotes = archive_format_quotes_for_context(archive_quotes());

    $testimony = archive_primary_testimony();
    $transcriptText = ($testimony && $testimony['raw_markdown'])
        ? $testimony['raw_markdown']
        : '(no primary testimony transcript recorded yet)';

    $discNotes = archive_discrepancy_notes();
    $discText = ($discNotes && $discNotes['content_markdown'])
        ? $discNotes['content_markdown']
        : '(no discrepancy/source notes recorded)';

    $cached = "# KNOWLEDGE BASE\n\n"
        . "## People\n" . $people . "\n"
        . "## Places\n" . $places . "\n"
        . "## Timeline\n" . $timeline . "\n"
        . "## Discrepancy / source notes\n" . $discText . "\n\n"
        . "## Quotes (verbatim quote bank)\n" . $quotes . "\n"
        . "## Primary testimony transcript (full verbatim, by tape)\n" . $transcriptText . "\n"
        . stories_context()
        . content_context();

    // Serve-time only — never mutates the DB rows just read above. See
    // includes/redaction.php.
    $cached = redact_text($cached, redacted_names());

    return $cached;
}

// Approved family-recounted stories (see includes/content.php) — held
// deliberately below the primary testimony transcript above: these are
// told ABOUT the subject by family, not the subject's own words, so
// they're weighted as reliable-but-secondhand rather than primary
// testimony. Only approved stories ever reach this function — a pending
// one has no narrative_note/content_links yet and isn't queried by
// content_stories_approved() at all, so there's nothing for an admin to
// review that could leak in ahead of approval.
function stories_context(): string
{
    $stories = content_stories_approved();
    if (!$stories) {
        return '';
    }
    $s = site_settings();
    $out = "\n## Family Stories (recounted by family members, not {$s['subject_name']}'s own words — "
        . "treat as reliable but secondhand, below the primary testimony above)\n";
    foreach ($stories as $story) {
        $out .= "\n### {$story['title']}\n" . content_story_body($story) . "\n";
    }
    return $out . "\n";
}

// Family-added material from the admin Content page (see
// includes/content.php), appended oldest-first so it reads as later
// context. Transcripts are read in full; photos/videos contribute their
// title + description plus each file's real content_files.id and
// auto-extracted capture metadata (date, device, GPS, dimensions/
// duration — see content_extract_metadata()) — the model never sees the
// binary itself in THIS context, but the id lets it emit a
// [[photo:ID]]/[[video:ID]] token (see knowledge_system_role()) that the
// frontend resolves into an actual image via api/file.php. Each item
// also carries its pre-computed narrative_note (see
// includes/narrative.php), a grounded, non-invented note on how it
// connects to the rest of the archive — one note per item even for a
// multi-photo album, since it was analyzed as a set.
function content_context(): string
{
    $items = array_reverse(content_all()); // content_all() is newest-first
    if (!$items) {
        return '';
    }

    $out = "\n\n# FAMILY-ADDED MATERIAL (via the app's admin Content page)\n";
    foreach ($items as $item) {
        $files = content_files_for_item($item['id']);
        $narrative = $item['narrative_note'] !== null && $item['narrative_note'] !== ''
            ? "\nNarrative connection: " . $item['narrative_note']
            : '';
        if ($item['type'] === 'transcript') {
            $file = $files[0] ?? null;
            $path = $file ? content_upload_dir('transcript') . '/' . $file['file_name'] : null;
            $body = $path && is_file($path) ? file_get_contents($path) : '(file missing)';
            $out .= "\n## Family-contributed transcript: {$item['title']}\n" . $body . $narrative . "\n";
        } elseif ($item['type'] === 'url') {
            $file = $files[0] ?? null;
            $path = $file ? content_upload_dir('url') . '/' . $file['file_name'] : null;
            $body = $path && is_file($path) ? file_get_contents($path) : '(file missing)';
            $out .= "\n## Family-contributed source: {$item['title']} ({$item['source_url']})\n" . $body . $narrative . "\n";
        } else {
            $desc = $item['description'] !== null && $item['description'] !== ''
                ? $item['description']
                : '(no description provided)';
            $fileLines = [];
            foreach ($files as $file) {
                $metadata = $file['metadata'] !== null ? json_decode($file['metadata'], true) : null;
                $summary = $metadata ? content_format_metadata_summary($metadata) : null;
                $fileLines[] = "id={$file['id']}" . ($summary ? " ($summary)" : '');
            }
            $filesNote = $fileLines ? "\n{$item['type']} files: " . implode('; ', $fileLines) : '';
            $countNote = count($files) > 1 ? ' (' . count($files) . ' photos)' : '';
            $out .= "\n## Family-contributed {$item['type']}: {$item['title']}$countNote\n" . $desc . $filesNote . $narrative . "\n";
        }
    }
    return $out;
}
