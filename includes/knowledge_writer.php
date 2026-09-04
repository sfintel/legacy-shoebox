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
//
// $contentItemId — the content_suggestions row's own source item — gets
// recorded via the existing content_links table (see sql/schema.sql's
// comment on it) rather than a new column: same table narrative_analyze()'s
// entity-linking already uses for "related content" on the Timeline/People/
// Places/Quotes tabs, so the freshly-created row picks up a real clickable
// source link for free, and content_links' own ON DELETE CASCADE on
// content_item_id makes that link (and only that link) disappear on its own
// if the source content item is later deleted — no dangling reference left
// behind, and no per-table cleanup code needed.
function kw_apply_suggestion(string $kind, array $fields, string $contentItemId): array
{
    // sourceNote is PHP-attached at suggestion-creation time (see
    // narrative_suggest_additions()) so it accurately reflects what kind
    // of content item this came from (URL/transcript/story) — falls back
    // to the old URL-only wording for a suggestion created before that
    // field existed (still accurate for those, since URL was the only
    // source type back then).
    $sourceNote = $fields['sourceNote'] ?? 'AI-suggested from a submitted URL';

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

    archive_content_links_apply($contentItemId, [['type' => $kind, 'id' => $row['id']]]);

    return ['id' => $row['id']];
}
