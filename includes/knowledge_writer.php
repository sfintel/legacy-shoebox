<?php
declare(strict_types=1);

// Applies an approved content_suggestions row (see includes/narrative.php's
// narrative_suggest_additions()) into the core archive tables (see
// includes/archive.php) — the database is the source of truth for the
// archive now, so this is a plain insert via the same archive_*_create()
// functions the admin Archive Editor uses, not hand-formatted YAML text.
// (Pre-database-rewrite, this file hand-formatted YAML + patched
// data/*.json + wrote .bak snapshots — see the open-source plan's "core
// architectural shift" for why that's gone.)
//
// $fields comes from narrative_suggestion_schema() in includes/narrative.php
// — already validated there (required fields present, id normalized to a
// slug) — plus the citation PHP itself always attaches. `slug` is
// deliberately left unset here: the suggestion's own proposed id was only
// ever meant as a human-readable hint, and the UUID primary key the
// archive_*_create() functions generate is sufficient; skipping it avoids
// ever needing to resolve a slug collision on this path.
function kw_apply_suggestion(string $kind, array $fields): array
{
    $sourceNote = 'AI-suggested from a submitted URL';

    $row = match ($kind) {
        'timeline' => archive_timeline_create([
            'date_label' => $fields['date'] ?? '',
            'event' => $fields['event'] ?? '',
            'source_note' => $sourceNote,
            'confidence' => $fields['confidence'] ?? null,
            'note' => $fields['note'] ?? null,
            'citation' => $fields['citation'] ?? null,
        ]),
        'person' => archive_person_create([
            'names' => $fields['names'] ?? [],
            'role' => $fields['role'] ?? null,
            'fate' => $fields['fate'] ?? null,
            'notes' => $fields['notes'] ?? null,
            'source_note' => $sourceNote,
            'citation' => $fields['citation'] ?? null,
        ]),
        'place' => archive_place_create([
            'names' => $fields['names'] ?? [],
            'role' => $fields['role'] ?? null,
            'notes' => $fields['notes'] ?? null,
            'source_note' => $sourceNote,
            'citation' => $fields['citation'] ?? null,
        ]),
        'quote' => archive_quote_create([
            'speaker' => $fields['speaker'] ?? '',
            'source_note' => $sourceNote,
            'tags' => $fields['tags'] ?? [],
            'quote_text' => $fields['quote'] ?? '',
            'citation' => $fields['citation'] ?? null,
        ]),
        default => throw new RuntimeException("Unknown suggestion kind: $kind"),
    };

    return ['id' => $row['id']];
}
