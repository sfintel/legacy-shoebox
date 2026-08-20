<?php
declare(strict_types=1);

// Deterministic, non-LLM check on Ask-tab replies: knowledge_system_role()
// tells the model never to invent a direct quote, but nothing verifies
// that. This extracts spans the reply presents as direct quotes (i.e.
// double-quoted text) and checks each appears, modulo normalization, in
// the material the app actually gave the model. Never rewrites the
// reply — an unverified span is often a normalization miss, not a
// fabrication, so the caller only surfaces a caution (see api/chat.php).
// Pure functions: no DB access, no side effects, never throws.

// Below this many characters (after normalization), a "quoted" span is
// too short to be a meaningful claim (contractions, scare quotes, the
// app's own stock phrases) and is skipped rather than checked. Tune here
// if server logs show too many/too few cautions.
const QUOTE_CHECK_MIN_LENGTH = 25;

// Normalizes both the candidate quote and the haystack the same way
// before comparing, so a curly-quoted, line-wrapped copy of a straight
// ASCII source line still matches. The transcript is a long multi-line
// document; a quote the model prints on one line will span a newline in
// the source, so whitespace collapsing is load-bearing, not cosmetic.
function quote_check_normalize(string $s): string
{
    $s = strtr($s, [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2011}" => '-',
        "\u{2026}" => '...',
        "\u{00A0}" => ' ',
    ]);
    $s = (string) preg_replace('/\s+/u', ' ', $s);
    return trim(mb_strtolower($s));
}

// Only double-quoted spans count as a direct-quote claim — a single-quote
// scan would false-positive on every contraction and possessive.
// Straight and curly openers/closers are matched interchangeably; quote
// characters are excluded from the inner class so a nested quote yields
// the shorter inner match rather than swallowing the whole sentence.
function quote_check_extract_spans(string $reply): array
{
    return array_map(static fn (array $s): string => $s['text'], quote_check_extract_spans_with_offsets($reply));
}

// Same span extraction as above, but also returns each span's byte
// offset in $reply, plus the byte range of the FULL match including its
// surrounding quote characters (fullStart/fullEnd) — used by
// includes/video_seek.php both to find the quote nearest a given
// [[video:ID]] token, and (via fullStart/fullEnd) to tell a real
// testimony quote apart from a source-citation string that's also
// wrapped in quote marks (see knowledge_system_role()'s citation
// convention) by checking what character immediately surrounds it in
// the reply.
//
// $minLength defaults to QUOTE_CHECK_MIN_LENGTH (the unverified-quote-
// caution threshold — tuned to avoid noisy false positives on
// contractions/scare-quotes) but video_seek.php calls this with its own,
// shorter VIDEO_SEEK_MIN_FRAGMENT_LENGTH instead: a short-but-genuine
// spoken excerpt like "eating us alive" is a perfectly safe seek anchor
// (video_seek_match_segment() only accepts it on an exact substring
// match against the real transcript, so a coincidental false match is
// vanishingly unlikely), even though 25 characters is the right bar for
// "worth flagging as an unverified claim."
function quote_check_extract_spans_with_offsets(string $reply, int $minLength = QUOTE_CHECK_MIN_LENGTH): array
{
    $pattern = '/["\x{201C}]([^"\x{201C}\x{201D}]+)["\x{201D}]/u';
    if (!preg_match_all($pattern, $reply, $matches, PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $spans = [];
    foreach ($matches[0] as $i => [$fullMatch, $fullOffset]) {
        $span = $matches[1][$i][0];
        if (mb_strlen(quote_check_normalize($span), 'UTF-8') >= $minLength) {
            $spans[] = [
                'text' => $span,
                'offset' => (int) $matches[1][$i][1],
                'fullStart' => (int) $fullOffset,
                'fullEnd' => (int) $fullOffset + strlen($fullMatch),
            ];
        }
    }
    return $spans;
}

// Returns the subset of $reply's quoted spans that could NOT be found
// (post-normalization) in $haystack, in reply order, as their original
// (un-normalized) text — so the log/UI show what the reader actually saw.
function quote_check_unverified(string $reply, string $haystack): array
{
    $normalizedHaystack = quote_check_normalize($haystack);
    $unverified = [];

    foreach (quote_check_extract_spans($reply) as $span) {
        // Strip leading/trailing punctuation from the span only — quoting
        // "...they took everything." where the source reads "...everything,"
        // is a punctuation artifact, not a fabricated quote.
        $trimmed = trim($span, ".,;:!?\u{2014}\u{2013}- \t\n\r");
        $normalizedSpan = quote_check_normalize($trimmed);
        if ($normalizedSpan === '' || mb_strlen($normalizedSpan, 'UTF-8') < QUOTE_CHECK_MIN_LENGTH) {
            continue;
        }
        if (!str_contains($normalizedHaystack, $normalizedSpan)) {
            $unverified[] = $span;
        }
    }

    return $unverified;
}
