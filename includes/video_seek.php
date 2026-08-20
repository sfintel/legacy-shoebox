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

// Returns ['<content_files.id>' => <int seekSeconds>, ...] — additive,
// never mutates $reply.
function video_seek_resolve_for_reply(string $reply): array
{
    if (!preg_match_all('/\[\[video:([0-9a-f-]{36})\]\]/', $reply, $tokenMatches, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    $quotes = quote_check_extract_spans_with_offsets($reply);
    if (!$quotes) {
        return [];
    }

    $result = [];
    foreach ($tokenMatches[1] as [$fileId, $tokenOffset]) {
        if (isset($result[$fileId])) {
            continue;
        }
        $segments = video_seek_transcript_segments_for_file($fileId);
        if (!$segments) {
            continue;
        }
        $quote = video_seek_nearest_quote($quotes, (int) $tokenOffset);
        if ($quote === null) {
            continue;
        }
        $seconds = video_seek_match_segment($segments, $quote);
        if ($seconds !== null) {
            $result[$fileId] = $seconds;
        }
    }
    return $result;
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

// Same normalize+edge-punctuation-trim+contains check as
// quote_check_unverified(), but against transcript segment text, and
// returning a seek time on match instead of pass/fail. Tries a single
// segment, then a sliding 2- and 3-segment window (a quote that spans a
// segment boundary), each anchored at that window's earliest start.
function video_seek_match_segment(array $segments, array $quote): ?int
{
    $trimmed = trim($quote['text'], ".,;:!?\u{2014}\u{2013}- \t\n\r");
    $normalizedSpan = quote_check_normalize($trimmed);
    if ($normalizedSpan === '' || mb_strlen($normalizedSpan, 'UTF-8') < QUOTE_CHECK_MIN_LENGTH) {
        return null;
    }

    for ($windowSize = 1; $windowSize <= 3; $windowSize++) {
        for ($i = 0; $i + $windowSize <= count($segments); $i++) {
            $window = array_slice($segments, $i, $windowSize);
            $haystack = quote_check_normalize(implode(' ', array_column($window, 'text')));
            if (str_contains($haystack, $normalizedSpan)) {
                return max(0, $window[0]['start'] - VIDEO_SEEK_BACKOFF_SECONDS);
            }
        }
    }
    return null;
}
