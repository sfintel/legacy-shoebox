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
        quote_check_extract_spans_with_offsets($reply, VIDEO_SEEK_MIN_FRAGMENT_LENGTH),
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
// that sits inside an OPEN, not-yet-closed parenthetical — found by
// scanning backward (bounded, so an unrelated "(" much earlier in the
// reply can't false-match) for the nearest "(" and checking no ")" comes
// between it and the quote. A real testimony quote is never introduced
// inside an open paren in this app's prompt conventions, so this is both
// necessary and sufficient — and unlike an "immediately preceded by ("
// check, it also catches phrasing like `(Family-contributed transcript,
// "Title," date)`, where there's plain text between the "(" and the
// quote itself (found in a real production reply — 1.18.2 and 1.18.6
// each fixed a different citation-phrasing variant this same check
// missed; this is the third).
function video_seek_is_citation_span(string $reply, array $quote): bool
{
    $searchStart = max(0, $quote['fullStart'] - 100);
    $before = substr($reply, $searchStart, $quote['fullStart'] - $searchStart);
    $openParen = strrpos($before, '(');
    if ($openParen === false) {
        return false;
    }
    return strpos($before, ')', $openParen) === false;
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

// Words too common to say anything about WHERE in a transcript a
// paraphrased description is talking about — see
// video_seek_fuzzy_match_segment() below.
const VIDEO_SEEK_FUZZY_STOPWORDS = [
    'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'by', 'for',
    'with', 'from', 'as', 'is', 'was', 'were', 'are', 'be', 'been', 'being',
    'this', 'that', 'these', 'those', 'it', 'its', 'they', 'their', 'them',
    'he', 'his', 'him', 'she', 'her', 'we', 'our', 'us', 'you', 'your', 'i', 'my',
    'not', 'no', 'so', 'if', 'then', 'than', 'into', 'onto', 'out', 'up', 'down',
    'over', 'under', 'after', 'before', 'when', 'while', 'during', 'about',
    'again', 'also', 'one', 'two', 'three', 'had', 'has', 'have', 'did', 'does',
    'would', 'could', 'should', 'will', 'can', 'there', 'here', 'all', 'each',
];
// Segments per sliding window — wider than video_seek_match_segment()'s
// exact-quote window, since a paraphrased timeline/note description
// typically covers more ground than one verbatim quote does.
const VIDEO_SEEK_FUZZY_WINDOW = 6;
// Minimum weighted keyword-overlap score (see below) before a fuzzy
// match is trusted at all — below this, "no reliable guess" (the
// caller's existing 0:00 fallback) beats anchoring to a window that
// merely happens to share one common word with the description.
const VIDEO_SEEK_FUZZY_MIN_SCORE = 1.5;

// Approximate, best-effort counterpart to video_seek_match_segment() for
// text that will never appear verbatim in a transcript — e.g. a timeline
// entry's `event`/`note` fields describe what happened in the curator's
// own words, not the subject's, so requiring an exact substring almost
// never finds anything. Instead, scores every sliding window of segments
// by how many of $text's distinctive words it contains, weighting each
// word by how RARE it is across the whole transcript (a crude,
// self-contained inverse-document-frequency — no external
// dictionary/library needed) so a name mentioned throughout the tape
// counts for much less than a specific place or detail mentioned only a
// few times. Still grounded in the real transcript, never a guess pulled
// from nowhere — but unlike video_seek_match_segment(), the result is an
// approximate "this is roughly where that's talked about," not a
// verified verbatim anchor, so callers should treat it as a lower-
// confidence fallback, tried only after an exact match fails.
function video_seek_fuzzy_match_segment(array $segments, string $text): ?int
{
    $keywords = video_seek_fuzzy_keywords($text);
    if (!$keywords || !$segments) {
        return null;
    }

    $segmentWords = array_map(
        static fn (array $seg): array => array_unique(video_seek_fuzzy_tokenize($seg['text'])),
        $segments
    );

    $docFreq = [];
    foreach ($keywords as $word) {
        $count = 0;
        foreach ($segmentWords as $words) {
            if (in_array($word, $words, true)) {
                $count++;
            }
        }
        $docFreq[$word] = $count;
    }

    $total = count($segments);
    $bestScore = 0.0;
    $bestStart = null;

    for ($i = 0; $i < $total; $i++) {
        $windowWords = [];
        for ($j = $i; $j < min($i + VIDEO_SEEK_FUZZY_WINDOW, $total); $j++) {
            $windowWords = array_merge($windowWords, $segmentWords[$j]);
        }
        $windowWords = array_flip($windowWords);

        $score = 0.0;
        foreach ($keywords as $word) {
            if (isset($windowWords[$word]) && $docFreq[$word] > 0) {
                $score += 1.0 / $docFreq[$word];
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestStart = $segments[$i]['start'];
        }
    }

    if ($bestStart === null || $bestScore < VIDEO_SEEK_FUZZY_MIN_SCORE) {
        return null;
    }

    return max(0, $bestStart - VIDEO_SEEK_BACKOFF_SECONDS);
}

// Lowercased, punctuation-stripped words from $text, deduplicated and
// with stopwords/very-short words removed — the "distinctive vocabulary"
// video_seek_fuzzy_match_segment() looks for in the transcript.
function video_seek_fuzzy_keywords(string $text): array
{
    $words = array_unique(video_seek_fuzzy_tokenize($text));
    return array_values(array_filter(
        $words,
        static fn (string $w): bool => mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, VIDEO_SEEK_FUZZY_STOPWORDS, true)
    ));
}

function video_seek_fuzzy_tokenize(string $text): array
{
    $normalized = quote_check_normalize($text);
    preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $m);
    return $m[0];
}

// --- Pseudo closed captions (api/captions.php, the Media tab) ---
// Turns the SAME segments video_seek_transcript_segments_for_file()
// already computes for quote-based seeking into a WebVTT track. "Pseudo"
// because it's not a real captioning pass: a cue is exactly one
// transcript speaker-turn segment (real spoken words at their real
// timecodes, never generated/summarized), which usually runs much
// longer and covers far more text than a real caption line ever would.
// Still strictly more useful than nothing, and, like every other
// video_seek.php output, fully deterministic — never the model. Returns
// null under the same conditions the segments lookup does (no linked
// transcript, or an older non-timecoded one) — api/captions.php serves
// that as 404 rather than an empty-but-200 track file.
function video_seek_vtt_for_file(string $fileId): ?string
{
    $segments = video_seek_transcript_segments_for_file($fileId);
    if (!$segments) {
        return null;
    }
    $vtt = "WEBVTT\n\n";
    $cueNumber = 0;
    foreach ($segments as $seg) {
        $text = trim((string) $seg['text']);
        if ($text === '') {
            continue;
        }
        $start = (int) $seg['start'];
        // Guards against a zero/negative-duration cue (some source
        // transcripts have back-to-back turns sharing one timecode) —
        // WebVTT requires a cue's end to be strictly after its start.
        $end = max($start + 1, (int) $seg['end']);
        $speaker = trim((string) ($seg['speaker'] ?? ''));
        $cueText = $speaker !== '' ? "{$speaker}: {$text}" : $text;
        $cueNumber++;
        // Explicit cue settings, not the default "auto" line placement —
        // pins every cue to the same fixed spot near the bottom edge
        // (leaving a 10% margin below it, clear of the video player's own
        // control bar) instead of letting each browser's own "avoid the
        // controls" heuristic nudge cues up to differing heights, which
        // is what was landing them over the speaker's face in some
        // videos.
        $vtt .= "{$cueNumber}\n" . video_seek_vtt_timestamp($start) . ' --> ' . video_seek_vtt_timestamp($end)
            . " line:90% position:50% align:center size:90%\n{$cueText}\n\n";
    }
    return $cueNumber > 0 ? $vtt : null;
}

function video_seek_vtt_timestamp(int $totalSeconds): string
{
    $h = intdiv($totalSeconds, 3600);
    $m = intdiv($totalSeconds % 3600, 60);
    $s = $totalSeconds % 60;
    return sprintf('%02d:%02d:%02d.000', $h, $m, $s);
}
