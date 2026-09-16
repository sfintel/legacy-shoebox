<?php
declare(strict_types=1);

// CLI-only one-time data repair for installs upgrading past 2.5.0 (see
// CHANGELOG.md's entry) that already accumulated exact-text duplicate
// quotes — the AI suggestion prompt didn't check the quote bank for
// duplicates before that fix, so the same testimony line could get
// proposed and approved more than once, each time with different tags.
//
// For every subject, groups quotes by identical (trimmed) quote_text,
// keeps the earliest-created row in each group, unions every
// duplicate's tags onto it, repoints any content_links that pointed at
// a row being removed, then deletes the removed rows. Safe to run
// repeatedly — a second run finds no duplicate groups and does nothing.
//
// Usage: php dedupe_quotes.php [--dry-run]
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = db();

$subjects = $pdo->query('SELECT id, hostname FROM subjects')->fetchAll();
$totalGroups = 0;
$totalDeleted = 0;

foreach ($subjects as $subject) {
    $rows = $pdo->prepare('SELECT * FROM quotes WHERE subject_id = ? ORDER BY quote_text, created_at ASC, id ASC');
    $rows->execute([$subject['id']]);
    $rows = $rows->fetchAll();

    $byText = [];
    foreach ($rows as $r) {
        $byText[$r['quote_text']][] = $r;
    }

    foreach ($byText as $text => $group) {
        if (count($group) < 2) {
            continue;
        }
        $totalGroups++;
        $keeper = $group[0];
        $losers = array_slice($group, 1);

        $mergedTags = json_decode((string) $keeper['tags'], true) ?: [];
        foreach ($losers as $loser) {
            $loserTags = json_decode((string) $loser['tags'], true) ?: [];
            $mergedTags = array_values(array_unique(array_merge($mergedTags, $loserTags)));
        }

        echo "[{$subject['hostname']}] keeping {$keeper['id']} (\"" . substr($text, 0, 60) . "...\"), merging tags -> "
            . json_encode($mergedTags) . ", removing " . count($losers) . " duplicate(s): "
            . implode(', ', array_column($losers, 'id')) . "\n";

        if ($dryRun) {
            $totalDeleted += count($losers);
            continue;
        }

        $pdo->prepare('UPDATE quotes SET tags = ? WHERE id = ?')
            ->execute([json_encode($mergedTags, JSON_UNESCAPED_UNICODE), $keeper['id']]);
        keywords_ensure($mergedTags);

        foreach ($losers as $loser) {
            // Repoint any content_links pointing at the loser onto the
            // keeper instead — but the (content_item_id, entity_type,
            // entity_id) unique key means a link from the same content
            // item to both the loser and the keeper can't coexist, so
            // drop the loser's link in that case rather than erroring.
            $links = $pdo->prepare("SELECT * FROM content_links WHERE entity_type = 'quote' AND entity_id = ?");
            $links->execute([$loser['id']]);
            foreach ($links->fetchAll() as $link) {
                $exists = $pdo->prepare("SELECT COUNT(*) FROM content_links WHERE content_item_id = ? AND entity_type = 'quote' AND entity_id = ?");
                $exists->execute([$link['content_item_id'], $keeper['id']]);
                if ((int) $exists->fetchColumn() > 0) {
                    $pdo->prepare('DELETE FROM content_links WHERE id = ?')->execute([$link['id']]);
                } else {
                    $pdo->prepare('UPDATE content_links SET entity_id = ? WHERE id = ?')->execute([$keeper['id'], $link['id']]);
                }
            }
            $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$loser['id']]);
            $totalDeleted++;
        }
    }
}

echo "\n" . ($dryRun ? "[DRY RUN] " : "") . "Done. $totalGroups duplicate group(s) found across all subjects, $totalDeleted row(s) " . ($dryRun ? "would be" : "were") . " removed.\n";
