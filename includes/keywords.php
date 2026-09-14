<?php
declare(strict_types=1);

// The app-wide keyword list — a curated master list Quotes (quotes.tags)
// and Content items (content_items.tags) both draw their picker
// suggestions from (see js/content_form.js's renderTagPicker and
// admin_archive.js's Quotes tab), rather than each page inventing its
// own suggestion pool from whatever's already been typed on that page
// alone. Deliberately NOT a foreign key on either table — quotes.tags
// and content_items.tags stay exactly what they've always been (a plain
// JSON array of strings), so a picker is still free-typeable and never
// blocked on this list. Every label that gets used anywhere is added
// here automatically (see keywords_ensure(), called from
// content_normalize_tags() and both archive_quote_create()/
// archive_quote_update()) — this list is a side effect of tagging, not
// a gate in front of it. An admin can rename a keyword afterward (see
// admin_settings.php's Keywords tab) to fix a typo or merge a duplicate
// like "WWII"/"WW2" into one spelling; renaming cascades to every quote
// and content item currently using the old label, since a rename that
// only fixed future suggestions while leaving already-tagged items
// permanently split across two spellings would defeat the point.

function keywords_all(): array
{
    $stmt = db()->prepare('SELECT id, label FROM keywords WHERE subject_id = ? ORDER BY label ASC');
    $stmt->execute([current_subject_id()]);
    return array_map(static fn (array $r) => ['id' => $r['id'], 'label' => $r['label']], $stmt->fetchAll());
}

// Adds any of $labels not already in the master list. Silently ignores
// duplicates (INSERT IGNORE) rather than checking first — cheaper, and
// the only way this can race is two people tagging the same new keyword
// at once, which should just result in one row either way.
function keywords_ensure(array $labels): void
{
    $labels = array_values(array_unique(array_filter(array_map(
        static fn ($l) => trim((string) $l),
        $labels
    ), static fn (string $l) => $l !== '')));
    if (!$labels) {
        return;
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('INSERT IGNORE INTO keywords (id, subject_id, label) VALUES (?, ?, ?)');
    foreach ($labels as $label) {
        $stmt->execute([make_uuid(), $subjectId, $label]);
    }
}

function keywords_create(string $label): array
{
    $label = trim($label);
    if ($label === '') {
        throw new RuntimeException('Keyword text is required.');
    }
    $subjectId = current_subject_id();
    $existing = db()->prepare('SELECT id FROM keywords WHERE subject_id = ? AND label = ?');
    $existing->execute([$subjectId, $label]);
    $id = $existing->fetchColumn();
    if (!$id) {
        $id = make_uuid();
        db()->prepare('INSERT INTO keywords (id, subject_id, label) VALUES (?, ?, ?)')->execute([$id, $subjectId, $label]);
    }
    return ['id' => $id, 'label' => $label];
}

// Rewrites every quotes.tags / content_items.tags row containing
// $oldLabel to use $newLabel instead. Plain PHP decode/mutate/encode
// rather than a MySQL JSON_* function — this codebase treats JSON
// columns as opaque to SQL everywhere else (see e.g. content_public()),
// which also sidesteps any doubt about JSON function availability
// across MySQL/MariaDB versions on shared hosting. Scoped to the
// current subject — an unscoped sweep here would rewrite every OTHER
// subject's tags too.
function keywords_cascade_rename(string $oldLabel, string $newLabel): void
{
    $subjectId = current_subject_id();
    foreach (['content_items', 'quotes'] as $table) {
        $rows = db()->prepare("SELECT id, tags FROM $table WHERE subject_id = ? AND tags IS NOT NULL AND tags != '[]'");
        $rows->execute([$subjectId]);
        $stmt = db()->prepare("UPDATE $table SET tags = ? WHERE id = ? AND subject_id = ?");
        foreach ($rows->fetchAll() as $row) {
            $tags = json_decode((string) $row['tags'], true) ?: [];
            if (!in_array($oldLabel, $tags, true)) {
                continue;
            }
            $tags = array_values(array_unique(array_map(
                static fn ($t) => $t === $oldLabel ? $newLabel : $t,
                $tags
            )));
            $stmt->execute([json_encode($tags, JSON_UNESCAPED_UNICODE), $row['id'], $subjectId]);
        }
    }
}

// Renaming to a label that's already someone else's keyword merges the
// two: every quote/content item tagged with the old label is
// repointed to the existing keyword's label, and the old row is
// deleted, rather than violating keywords.label's UNIQUE constraint or
// silently creating a second row with the same text.
function keywords_rename(string $id, string $newLabel): array
{
    $newLabel = trim($newLabel);
    if ($newLabel === '') {
        throw new RuntimeException('Keyword text is required.');
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT id, label FROM keywords WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, $subjectId]);
    $current = $stmt->fetch();
    if (!$current) {
        throw new RuntimeException('Keyword not found.');
    }
    if ($current['label'] === $newLabel) {
        return ['id' => $id, 'label' => $newLabel];
    }

    // MySQL's default collation is case-insensitive, so "label = ?" can
    // match an existing row whose stored casing differs from what was
    // just typed (e.g. typing "wwii" collides with a stored "WWII") —
    // cascade to that row's own actual label, not the freshly typed
    // string, so the surviving keyword's casing doesn't silently change
    // as a side effect of how this particular rename happened to be typed.
    $collision = db()->prepare('SELECT id, label FROM keywords WHERE subject_id = ? AND label = ? AND id != ?');
    $collision->execute([$subjectId, $newLabel, $id]);
    $collisionRow = $collision->fetch();
    $targetLabel = $collisionRow ? $collisionRow['label'] : $newLabel;

    keywords_cascade_rename($current['label'], $targetLabel);

    if ($collisionRow) {
        db()->prepare('DELETE FROM keywords WHERE id = ? AND subject_id = ?')->execute([$id, $subjectId]);
        return ['id' => (string) $collisionRow['id'], 'label' => $targetLabel];
    }
    db()->prepare('UPDATE keywords SET label = ? WHERE id = ? AND subject_id = ?')->execute([$newLabel, $id, $subjectId]);
    return ['id' => $id, 'label' => $newLabel];
}

// Removes a keyword from the master list only — existing quotes/content
// items keep whatever tag text they already had (same "delete doesn't
// cascade" posture as removing a source or an audience category
// elsewhere in this app). It just stops being suggested going forward.
function keywords_delete(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM keywords WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    return $stmt->rowCount() > 0;
}
