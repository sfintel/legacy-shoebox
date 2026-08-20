<?php
declare(strict_types=1);

// Resolves an Ask-tab reply's [[video:ID]] citations to a "seek 5
// seconds before the quoted passage" timestamp — entirely deterministic
// PHP, never the model (same rule this project already applies to
// quote_check.php and narrative.php's sourceNote handling: the model is
// never trusted to compute or state a fact this precise). Given a reply:
// (1) find each [[video:ID]] token and its offset, (2) follow file ->
// content item -> linked_item_id -> transcript item, (3) parse that
// transcript's timecoded segments (transcript_parse_timecoded() —
// returns null for an older ## Tape-format transcript, in which case
// this video gets no entry and the client falls back to today's
// behavior, opening at 0:00), (4) find the nearest quoted span in the
// same reply to that token (knowledge_system_role() instructs the model
// to place the token near the quote it illustrates — proximity is a
// fully-deterministic proxy for "this is the quote this token is about",
// never something the model is asked to state itself), (5) match that
// quote's normalized text against the transcript's segment text the same
// way quote_check_unverified() already matches against the whole
// knowledge base.

const VIDEO_SEEK_MAX_QUOTE_DISTANCE = 600; // bytes; tune from real replies
const VIDEO_SEEK_BACKOFF_SECONDS = 5;
// Below this many characters (after normalization), a "..."-delimited
// fragment of a quote is too short to reliably anchor a seek time —
// shorter than quote_check.php's QUOTE_CHECK_MIN_LENGTH (25) since a
// fragment is naturally shorter than the full quote it came from.
const VIDEO_SEEK_MIN_FRAGMENT_LENGTH = 8;

// Returns a list of one entry per [[video:ID]] occurrence in $reply, IN
// ORDER — e.g. [3086, null, 4758] for three video tokens where the 2nd
// had no matchable quote nearby. Deliberately keyed by occurrence
// position, not by file id: the SAME video is sometimes cited more than
// once in one reply, each time illustrating a different moment (e.g.
// "hidden under a cow" earlier, "the blockade finally ended" later) — an
// id-keyed map would collapse every repeat of that id down to whichever
// ONE occurrence happened to match first, silently applying that same
// (wrong, for every other occurrence) seek time to all of them. Additive
// only, never mutates $reply.
function video_seek_resolve_for_reply(string $reply): array
{
    if (!preg_match_all('/\[\[video:([0-9a-f-]{36})\]\]/', $reply, $tokenMatches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $quotes = array_values(array_filter(
        quote_check_extract_spans_with_offsets($reply),
        static fn (array $q): bool => !video_seek_is_citation_span($reply, $q)
    ));

    $segmentsByFileId = [];
    $results = [];
    foreach ($tokenMatches[1] as [$fileId, $tokenOffset]) {
        if (!array_key_exists($fileId, $segmentsByFileId)) {
            $segmentsByFileId[$fileId] = video_seek_transcript_segments_for_file($fileId);
        }
        $segments = $segmentsByFileId[$fileId];

        $seconds = null;
        if ($segments && $quotes) {
            $quote = video_seek_nearest_quote($quotes, (int) $tokenOffset);
            if ($quote !== null) {
                $seconds = video_seek_match_segment($segments, $quote);
            }
        }
        $results[] = $seconds;
    }
    return $results;
}

// Segments for the transcript linked to the video that owns $fileId, or
// null if not a video / not linked / linked item isn't a transcript /
// that transcript doesn't parse as timecoded. Redacts the transcript
// text the same way knowledge_context() redacts content_context()'s
// output before the model ever sees it — the model's reply (and
// therefore the quote span extracted from it) is already redacted, so
// matching against an un-redacted transcript file would spuriously fail
// on any quote touching a redacted name.
function video_seek_transcript_segments_for_file(string $fileId): ?array
{
    $file = content_file_find($fileId);
    if (!$file) {
        return null;
    }
    $video = content_find($file['content_item_id']);
    if (!$video || $video['type'] !== 'video' || empty($video['linked_item_id'])) {
        return null;
    }
    $transcript = content_find($video['linked_item_id']);
    if (!$transcript || $transcript['type'] !== 'transcript') {
        return null;
    }
    $tFile = content_files_for_item($transcript['id'])[0] ?? null;
    if (!$tFile) {
        return null;
    }
    $path = content_upload_dir('transcript') . '/' . $tFile['file_name'];
    $text = is_file($path) ? file_get_contents($path) : null;
    if ($text === null || $text === false) {
        return null;
    }
    $text = redact_text($text, redacted_names());
    return transcript_parse_timecoded($text);
}

// knowledge_system_role() instructs the model to cite its source in
// quotes right next to the material it's citing, e.g.
// `— "actual spoken words" ("Source Title") [[video:ID]]`. That source
// title is itself inside quote marks, so quote_check_extract_spans_with_
// offsets() picks it up as a second "quote" — and because the app places
// the [[video:ID]] token right after the citation, the citation is often
// textually CLOSER to the token than the real testimony quote it's
// citing. Left unfiltered, video_seek_nearest_quote() would pick the
// citation's title instead of the actual words, which never appears in
// the transcript and always fails to match (silently falling back to
// 0:00). Detected structurally, not by content: a citation span is one
// that starts immediately after an opening parenthesis. Deliberately
// checks ONLY that (not also "immediately followed by a closing
// parenthesis") — the model's exact citation phrasing varies (sometimes
// the whole citation is one quoted string like `("Title (part 1)")`,
// sometimes the quote covers only part of it, e.g.
// `("Title," recorded October 7, 1991)`, with plain text between the
// closing quote mark and the ")" — but a testimony quote is never
// introduced immediately after an open paren in this app's prompt
// conventions, so the single "starts right after (" signal alone is
// both necessary and sufficient, and is robust to that phrasing drift.
function video_seek_is_citation_span(string $reply, array $quote): bool
{
    $before = rtrim(substr($reply, 0, $quote['fullStart']));
    return $before !== '' && $before[-1] === '(';
}

function video_seek_nearest_quote(array $quotes, int $tokenOffset): ?array
{
    $best = null;
    $bestDistance = null;
    foreach ($quotes as $q) {
        $distance = abs($q['offset'] - $tokenOffset);
        if ($distance > VIDEO_SEEK_MAX_QUOTE_DISTANCE) {
            continue;
        }
        if ($bestDistance === null || $distance < $bestDistance) {
            $best = $q;
            $bestDistance = $distance;
        }
    }
    return $best;
}

// Same normalize+edge-punctuation-trim approach as
// quote_check_unverified(), but tolerant of "..." elisions — a quote the
// model assembles from spoken testimony often stitches together a few
// non-contiguous words (skipping filler, cross-talk, or a brief
// interjection from the other speaker) using "...", sometimes spanning
// two speakers' turns. Requiring the WHOLE quote to be one exact
// substring is too strict for real interrupted dialogue like this, so
// this splits the quote on its own "..." markers and tries each
// resulting fragment (longest first, since the model tends to keep the
// most distinctive wording verbatim and paraphrase the connective
// tissue) — the first fragment that's an exact substring of some window
// of segments anchors the seek time. Still fully deterministic: the seek
// time always traces back to a real, verbatim substring of the actual
// transcript, never a guess or something the model stated itself.
function video_seek_match_segment(array $segments, array $quote): ?int
{
    $trimmed = trim($quote['text'], ".,;:!?\u{2014}\u{2013}- \t\n\r");
    $normalized = quote_check_normalize($trimmed);
    if ($normalized === '') {
        return null;
    }

    $fragments = array_values(array_filter(
        array_map('trim', explode('...', $normalized)),
        static fn (string $f): bool => mb_strlen($f, 'UTF-8') >= VIDEO_SEEK_MIN_FRAGMENT_LENGTH
    ));
    if (!$fragments) {
        return null;
    }
    usort($fragments, static fn (string $a, string $b): int => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

    foreach ($fragments as $fragment) {
        for ($windowSize = 1; $windowSize <= 3; $windowSize++) {
            for ($i = 0; $i + $windowSize <= count($segments); $i++) {
                $window = array_slice($segments, $i, $windowSize);
                $haystack = quote_check_normalize(implode(' ', array_column($window, 'text')));
                if (str_contains($haystack, $fragment)) {
                    return max(0, $window[0]['start'] - VIDEO_SEEK_BACKOFF_SECONDS);
                }
            }
        }
    }
    return null;
}
