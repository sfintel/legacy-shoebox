<?php
declare(strict_types=1);

// Parses primary_testimony.raw_markdown into a structured
// {tape, turns:[{speaker, text}]} list for the Transcript tab — a PHP
// port of app/lib/build-data.js's parseTranscript() (the Node version),
// since this deployment has no Node available. Markers are genericized
// from the original SF/INT (one specific subject's initials) to
// SUBJECT/INTERVIEWER so the format never depends on who the archive is
// about; $subjectLabel supplies the display name for SUBJECT turns.
//
// Convention:
//   ## Tape 1
//   **SUBJECT:** text...
//   **INTERVIEWER:** text...
// Lines starting with #, >, or **Survivor/Interviewer/Videographer/Length
// (leftover metadata headers some pasted transcripts have at the top)
// are skipped rather than treated as dialogue.
function transcript_parse_tapes(string $markdown, string $subjectLabel): array
{
    $lines = preg_split('/\r\n|\n|\r/', $markdown);
    $tapes = [];
    $hasTape = false;
    $currentSpeaker = null;
    $buffer = [];

    $flush = function () use (&$tapes, &$hasTape, &$currentSpeaker, &$buffer, $subjectLabel): void {
        if ($hasTape && $buffer && $currentSpeaker !== null) {
            $tapes[count($tapes) - 1]['turns'][] = [
                'speaker' => $currentSpeaker === 'SUBJECT' ? $subjectLabel : 'Interviewer',
                'text' => trim(implode(' ', $buffer)),
            ];
        }
        $buffer = [];
    };

    foreach ($lines as $raw) {
        $line = trim((string) $raw);

        if (preg_match('/^##\s+Tape\s+(\d+)/i', $line, $m)) {
            $flush();
            $currentSpeaker = null;
            $tapes[] = ['tape' => (int) $m[1], 'turns' => []];
            $hasTape = true;
            continue;
        }

        if (preg_match('/^\*\*(SUBJECT|INTERVIEWER)[:*]*\*\*\s*(.*)$/i', $line, $m)) {
            $flush();
            $currentSpeaker = strtoupper($m[1]);
            $rest = trim($m[2]);
            $buffer = $rest !== '' ? [$rest] : [];
            continue;
        }

        if ($line === '') {
            continue;
        }
        if (preg_match('/^(#|>|\*\*Survivor|\*\*Interviewer|\*\*Videographer|\*\*Length)/i', $line)) {
            continue;
        }
        if ($currentSpeaker !== null) {
            $buffer[] = $line;
        }
    }
    $flush();

    return $tapes;
}

// A different, newer transcript format — one segment per speaker turn,
// each prefixed with an exact start-end timecode range, e.g.:
//   00:00:53:21 - 00:01:09:15
//   Slava Fintel
//   Spoken text, possibly across
//   several lines...
//
//   00:01:09:16 - 00:01:24:02
//   Interviewer
//   ...
// HH:MM:SS:FF — the frame component (FF) is intentionally dropped, not
// rounded: this codebase never assumes a specific frame rate, and
// converting frames to a fraction of a second without knowing fps would
// be a guess, not a deterministic computation. video_seek.php's eventual
// 5-second backoff already makes sub-second precision here moot, and
// truncating toward the named second never seeks past the real moment.
//
// Returns null (not a best-effort partial parse) unless the first
// non-blank line is itself a timecode line — so an old ## Tape-format or
// plain-prose transcript is correctly reported as "not this format"
// rather than silently misparsed. transcript_parse_tapes() above is
// completely untouched by this — the two formats never interact.
function transcript_parse_timecoded(string $text): ?array
{
    $lines = preg_split('/\r\n|\n|\r/', $text);
    $timecodeRe = '/^(\d{2}):(\d{2}):(\d{2}):(\d{2})\s*-\s*(\d{2}):(\d{2}):(\d{2}):(\d{2})\s*$/';

    $firstContentLine = null;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $firstContentLine = $trimmed;
            break;
        }
    }
    if ($firstContentLine === null || !preg_match($timecodeRe, $firstContentLine)) {
        return null;
    }

    $segments = [];
    $current = null;
    $expectSpeaker = false;

    $flush = function () use (&$segments, &$current): void {
        if ($current !== null && $current['speaker'] !== null) {
            $segments[] = [
                'start' => $current['start'],
                'end' => $current['end'],
                'speaker' => $current['speaker'],
                'text' => trim(implode(' ', $current['textLines'])),
            ];
        }
    };

    foreach ($lines as $raw) {
        $line = trim($raw);
        if ($line === '') {
            continue;
        }
        if (preg_match($timecodeRe, $line, $m)) {
            $flush();
            $current = [
                'start' => ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3],
                'end' => ((int) $m[5]) * 3600 + ((int) $m[6]) * 60 + (int) $m[7],
                'speaker' => null,
                'textLines' => [],
            ];
            $expectSpeaker = true;
            continue;
        }
        if ($current === null) {
            continue; // stray content before the first timecode
        }
        if ($expectSpeaker) {
            $current['speaker'] = rtrim($line, ": \t");
            $expectSpeaker = false;
            continue;
        }
        $current['textLines'][] = $line;
    }
    $flush();

    return $segments ?: null;
}
