<?php
declare(strict_types=1);

// A small, dependency-free markdown SUBSET renderer for
// discrepancy_notes.content_markdown — not a general markdown parser.
// Handles exactly what a curator's discrepancy notes actually need:
// "#" through "####" headings, **bold**, `inline code`, and blank-line-
// separated paragraphs (a single newline inside a paragraph becomes
// <br>). Anything else is left as literal text. Every block is
// HTML-escaped before any markdown-subset substitution is applied, so
// this is safe against arbitrary admin-entered content — there is no
// raw-HTML passthrough. Deliberately narrower than the Node version's
// `marked`-based rendering; see the open item in the open-source plan.

function markdown_lite_render(string $markdown): string
{
    $markdown = str_replace("\r\n", "\n", $markdown);
    $blocks = preg_split('/\n{2,}/', trim($markdown));
    $html = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        if (preg_match('/^(#{1,4})\s+(.*)$/', $block, $m)) {
            $level = strlen($m[1]);
            $html[] = "<h$level>" . markdown_lite_inline($m[2]) . "</h$level>";
            continue;
        }

        $lines = array_map('markdown_lite_inline', explode("\n", $block));
        $html[] = '<p>' . implode('<br>', $lines) . '</p>';
    }

    return implode("\n", $html);
}

// Applies inline formatting to one line: **bold** and `code`. Escapes
// HTML first (via h(), includes/helpers.php) so the ** / ` markers
// themselves are matched against already-safe text and no admin-entered
// content can inject markup — the only tags this ever emits are the
// literal ones below.
function markdown_lite_inline(string $text): string
{
    $text = h($text);
    $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = (string) preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    return $text;
}
