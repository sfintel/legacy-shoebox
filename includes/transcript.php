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
