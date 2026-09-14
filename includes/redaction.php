<?php
declare(strict_types=1);

// Serve-time text redaction — lets an admin register a name that should
// never appear in what's shown to family members (Ask tab context and
// replies, Browse tab JSON), without touching the canonical
// knowledge/*.yaml or transcript.md files. See sql/schema.sql's
// redacted_names table comment for the full rationale.

function redacted_names(): array
{
    $stmt = db()->prepare('SELECT name FROM redacted_names WHERE subject_id = ? ORDER BY created_at ASC');
    $stmt->execute([current_subject_id()]);
    return array_column($stmt->fetchAll(), 'name');
}

// Full rows (id/name/created_at), for the admin redactions list —
// api/admin/redactions.php.
function redacted_names_admin_list(): array
{
    $stmt = db()->prepare('SELECT id, name, created_at FROM redacted_names WHERE subject_id = ? ORDER BY created_at ASC');
    $stmt->execute([current_subject_id()]);
    return $stmt->fetchAll();
}

// Case-insensitive, word-boundary-aware replace, so redacting "Dan"
// doesn't also mangle an unrelated substring inside another word/name.
// preg_quote() so a name containing regex-special characters can't
// break the pattern.
function redact_text(string $text, array $names): string
{
    foreach ($names as $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $pattern = '/\b' . preg_quote($name, '/') . '\b/iu';
        $replaced = preg_replace($pattern, '[name withheld]', $text);
        if ($replaced !== null) {
            $text = $replaced;
        }
    }
    return $text;
}

function redact_add(string $name, string $userId): array
{
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('A name is required.');
    }
    $subjectId = current_subject_id();
    $stmt = db()->prepare('SELECT id FROM redacted_names WHERE subject_id = ? AND name = ?');
    $stmt->execute([$subjectId, $name]);
    if ($stmt->fetch()) {
        throw new RuntimeException('That name is already on the redaction list.');
    }

    $id = make_uuid();
    $stmt = db()->prepare('INSERT INTO redacted_names (id, subject_id, name, created_by) VALUES (?, ?, ?, ?)');
    $stmt->execute([$id, $subjectId, $name, $userId]);

    $stmt = db()->prepare('SELECT id, name, created_at FROM redacted_names WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function redact_remove(string $id): bool
{
    $stmt = db()->prepare('DELETE FROM redacted_names WHERE id = ? AND subject_id = ?');
    $stmt->execute([$id, current_subject_id()]);
    return $stmt->rowCount() > 0;
}
