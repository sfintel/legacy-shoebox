# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/) (MAJOR.MINOR.PATCH).

**If you're upgrading an existing deployment, don't just read this for
interest — see [UPGRADE.md](UPGRADE.md) for the actual procedure.** Every
release below that changed the database or `.env` says so explicitly
under "Database changes" / "Environment changes"; UPGRADE.md explains
why `sql/schema.sql` alone isn't enough to pick those up automatically.

## [Unreleased]

Nothing yet.

## [1.28.0] — 2026-08-26

### Added

- The main app header's "Add Content" link now opens a modal with just
  the add-content form, instead of navigating to the full
  `/admin_content.php` page (form + the entire content list below it).
  The modal links to `/admin_content.php` for anyone who actually wants
  to manage/edit existing items — admins also have it in the new Admin
  dropdown (1.26.0); it's the only path there for a non-admin author,
  so that's preserved.

### Changed

- Extracted the add-content form's markup (`content_add_form_html()` in
  `includes/helpers.php`) and its JS (new `js/content_form.js`) out of
  `admin_content.php`/`admin_content.js` so the modal and the full page
  share one implementation instead of two copies that could drift.
  `admin_content.js` itself is unchanged in behavior — this is a pure
  extraction, not a rewrite.

### Environment changes

None.

## [1.27.0] — 2026-08-26

### Added

- Content admin table (`/admin_content.php`): new **Linked** column
  showing a video/transcript pair's companion item title (was only
  visible by opening Edit before); new **Keywords** column — a
  circled-i icon with the full keyword list on hover — replacing the
  inline tag pills that used to clutter the Title cell.
- A type filter ("Show: All types / Transcript / Photo / …") above the
  table, and click-to-sort on the Title, Type, and Added column headers
  (click again to reverse).
- "Backfill AI analysis" is now a prominent primary button (previously
  styled the same as a plain nav link) with hover text explaining what
  it does and when it's worth running.
- Adding Photo/Video content: once you've started typing a URL to
  download from, the local-file field greys out (and vice versa) — the
  two were already mutually exclusive at submit time, this just makes
  that visible as you fill in the form instead of only on error.

### Fixed

- The Narrative connection cell showed each note's full text inline,
  breaking the single-line-per-row layout every other admin table uses
  (Quotes/People/Places/Timeline) — now truncated to one line, same as
  those, with the full text still editable via Edit.
- The "unreviewed" narrative-note badge shared its color with unrelated
  statuses (pending-story approval, the "dramatisation" tag) — it now
  has its own distinct color.

### Environment changes

None.

## [1.26.0] — 2026-08-26

### Added

- The main app header's "Admin" link is now a hover dropdown (tap to
  open on touch) listing all six admin sections — Users, Content,
  Archive, Settings, Redactions, Backup — instead of linking straight to
  `/admin.php`.
- Every admin_*.php page's header now lists all six sections (previously
  each page omitted the link back to itself, which was the only "you are
  here" cue) with the current section highlighted via a new
  `.ghost-btn.active` style and `aria-current="page"`.

### Fixed

- `/admin_content.php`'s nav hid Users/Archive/Settings/Redactions from
  non-admin authors but not Backup, letting an author see a link to a
  page they can't actually use (`admin_backup.php` is admin-only) — now
  hidden consistently with the others.

### Environment changes

None.

## [1.25.0] — 2026-08-26

### Fixed

- Reorderable admin tables (Timeline entries, Sources, Audience
  categories) no longer show a move-up arrow on the first row or a
  move-down arrow on the last row — previously both arrows were always
  shown regardless of position, so clicking them on an edge row was a
  silent no-op.

### Environment changes

None.

## [1.24.0] — 2026-08-26

### Removed

- The legacy YAML importer (admin_archive.php's "Import" tab, for
  migrating from the original file-based app-lamp deployment) —
  deprecated and no longer needed by any active deployment. Removed
  `includes/legacy_import.php`, `api/admin/legacy_import.php`, the
  Import tab/panel and its JS wiring, and the
  `archive_person_upsert_by_slug()`/`archive_place_upsert_by_slug()`
  helpers that existed only to support it. The `people.slug`/
  `places.slug` columns stay in the schema (nullable, unused going
  forward) rather than a column-dropping migration for no functional
  gain.

### Database changes

None — no schema change, just unused code removed.

### Environment changes

None.

## [1.23.1] — 2026-08-25

### Fixed

- `cron_backup.php` now starts with a `#!/usr/local/bin/php.cli` shebang
  line, needed by hosting panels (e.g. Plesk's Scheduled Tasks) whose
  cron UI runs the script directly as an executable rather than via `php
  script.php` — found while wiring up automatic backups on a real
  deployment. PHP's CLI SAPI automatically skips a leading `#!` line, so
  this doesn't change anything for `php cron_backup.php` or a plain URL
  hit; a panel that needs it may also require the file to have execute
  permission (`chmod 775`) and Unix line endings, per that panel's own
  requirements.

### Environment changes

None.

## [1.23.0] — 2026-08-25

### Added

- Backup retention: old backups are now deleted automatically once there
  are more than `BACKUP_RETENTION_COUNT` (default 14), applied every time
  a backup is created (manual or automatic) — previously nothing ever
  purged old backups, so they accumulated indefinitely. An "Apply
  retention now" button on `/admin_backup.php` also applies it on demand,
  to clean up an existing pile immediately after lowering the count
  rather than waiting for the next backup.
- Optional automatic backups on a schedule (`BACKUP_AUTO_INTERVAL_HOURS`,
  default `0`/off), via a new `cron_backup.php` entry point meant to be
  triggered by a host cron job (CLI, or HTTP with a `BACKUP_CRON_SECRET`
  token for hosts that only offer "cron via URL") — this app has no
  long-running process of its own, so the actual triggering has to come
  from outside. See README's "Backup & Restore" section for setup.
- Optional `BACKUP_DIR` override for where backup .zip files are stored
  (defaults to `ARCHIVE_ROOT/backups`, unchanged), for hosts that want
  backups on a separate disk/mount with more space.
- `/admin_backup.php` now shows the currently effective auto-backup
  interval, retention count, storage directory, and last-backup time.
- The setup wizard's advanced-settings stage now offers all four backup
  settings above, so a new install can set non-default values (or a
  cron secret) at onboarding time instead of hand-editing `.env`
  afterward.

### Environment changes

New optional variables — see `.env.example`'s "Backup & Restore"
section: `BACKUP_RETENTION_COUNT` (default 14), `BACKUP_AUTO_INTERVAL_HOURS`
(default 0/off), `BACKUP_DIR` (default `ARCHIVE_ROOT/backups`),
`BACKUP_CRON_SECRET` (default blank — only needed for URL-triggered
cron). All optional; a deployment that adds nothing to `.env` keeps its
exact previous behavior (manual-only backups, never pruned).

## [1.22.0] — 2026-08-21

### Added

- "Watch video" button on the Quotes tab, next to "View in transcript" —
  for a quote whose source note names a tape (e.g. "Tape 2"), it opens
  the matching Content Library video and seeks to that quote's actual
  moment, using the same verbatim-substring matching the Ask tab's
  video citations already use. Falls back to opening at 0:00 if the
  quote's stored text isn't found verbatim in that tape's transcript, or
  doesn't show at all if no video exists for that tape.

## [1.21.0] — 2026-08-21

### Added

- Primary Testimony's transcript now supports a third speaker beyond
  `**SUBJECT:**` / `**INTERVIEWER:**` — any other `**Name:**` marker (e.g.
  `**Mark Fintel:**`) is shown on the Transcript tab under that literal
  name, for someone else present during the interview. Previously their
  words were silently absorbed into whichever of the two main speakers'
  turns came right before them.

## [1.20.0] — 2026-08-21

### Added

- New "Document" content type on the Content page, for permission
  letters, correspondence, and similar non-testimony written material —
  previously there was nowhere for these to go except misusing
  Transcript. Paste the document's text (read in full by the Ask tab,
  same as a Transcript) plus an optional attached file (a scan or PDF of
  the original, kept for provenance only — never read directly by the
  model, same "described, not OCR'd" limitation as Photo). Gets the same
  AI suggestion-extraction pass as Transcript/URL/Story.

### Database changes

Adds `'document'` to `content_items.type` (`upgrade.sh` handles this
automatically). Manual equivalent:

```sql
ALTER TABLE content_items MODIFY COLUMN type ENUM('transcript','photo','video','url','story','document') NOT NULL;
```

### Environment changes

None.

## [1.19.2] — 2026-08-20

### Fixed

- Ask tab: pressing Enter in the question box inserted a newline instead
  of sending the question — Shift+Enter now inserts a newline, and Enter
  alone submits, matching the convention most chat UIs use.

### Database changes

None.

### Environment changes

None.

## [1.19.1] — 2026-08-20

### Fixed

- Ask-tab video seek regressed to 0:00 for a video that had previously
  worked, on a citation phrasing variant `video_seek_is_citation_span()`
  still missed after 1.18.2 and 1.18.6 — its third distinct miss. Found
  via a real production reply: `(Family-contributed transcript, "Slava
  and Celia Interview," Oct. 7 1991)` puts plain text between the `(`
  and the opening quote mark, unlike the phrasings the two earlier fixes
  were built against — so the "immediately preceded by `(`" check missed
  it, and the citation's title (textually closer to the `[[video:ID]]`
  token than the real testimony quote) won the nearest-quote search,
  which never appears in the transcript and always fails to match.
  Replaced the check with a bounded backward scan for the nearest
  unclosed `(` before the quote, which catches both this phrasing and
  the two earlier ones in a single, more general rule.

### Database changes

None.

### Environment changes

None.

## [1.19.0] — 2026-08-20

### Changed

- Raised the Ask tab's reply length ceiling from 4096 to 8192 tokens, to
  substantially reduce how often a reply hits the "Note: this reply was
  cut short by a length limit — ask to continue for the rest" notice —
  most noticeable on broad, multi-incident questions ("tell me about all
  the times..."). Raised the AI provider HTTP timeout from 60s to 120s
  to match: without that, a genuinely long reply generating past 60s
  would have hard-failed with "The AI backend failed to respond" instead
  of just taking longer to arrive. This doesn't remove the length limit
  or the "cut short" notice entirely — a reply can still hit 8192 tokens
  — just moves the ceiling much higher.

### Database changes

None.

### Environment changes

None.

## [1.18.9] — 2026-08-20

### Fixed

- Ask-tab video seek still missed some spoken quotes that were genuinely
  short. Found via a real production reply describing a tunnel dugout
  during the 1943 blockade: `"eating us alive"` (16 chars) and `"a
  couple of days"` (17 chars) were both discarded by
  `quote_check_extract_spans_with_offsets()`'s hardcoded 25-character
  threshold before `video_seek.php`'s own fragment-matching logic ever
  got a chance to try them — a distinct root cause from the earlier
  matching-logic bugs in 1.18.1/1.18.2/1.18.6. The function now takes an
  optional `$minLength` parameter; `video_seek.php` passes its own
  shorter `VIDEO_SEEK_MIN_FRAGMENT_LENGTH` (8) since an exact-substring
  match against the real transcript makes even a short quote a safe seek
  anchor. The unverified-quote caution (`quote_check_unverified()`) is
  unaffected and still uses the 25-character default.

### Database changes

None.

### Environment changes

None.

## [1.18.8] — 2026-08-20

### Fixed

- Two silent-failure gaps in `includes/ai_provider.php`, found while
  diagnosing a one-off "AI backend failed to respond" report that didn't
  recur on retry (most likely a transient network issue, not a code
  bug — but the investigation surfaced a real logging gap worth fixing
  regardless): `ai_chat_anthropic()` returning `null` for a successful
  HTTP response with no extractable text block (e.g. a content-refusal
  `stop_reason`), and `ai_http_post()` returning `null` for a 2xx
  response whose body isn't valid JSON, both logged nothing at all —
  contradicting `ai_http_post()`'s own docblock, which already promised
  every failure is "always `error_log()`'d". Both paths now log a short,
  diagnosable line.

### Database changes

None.

### Environment changes

None.

## [1.18.7] — 2026-08-20

### Changed

- Further strengthened `knowledge_system_role()`'s video-citation prompt
  rule. Found via a real production reply to a broad "tell me about all
  the times Piotr hid Slava" question: the model correctly followed
  1.18.4's rule (never attach `[[video:ID]]` without a real quote), but
  for a multi-incident list it tended to quote only the first or most
  vivid incident and narrate the rest in plain prose — so those other
  incidents correctly got no video link at all, which is safe but means
  fewer links than useful. The rule now explicitly asks for a short
  direct quote for EACH distinct incident described, not only the first.
  **Still a prompt nudge, not a guarantee** — like 1.18.4, this can't
  promise every incident in every reply gets a matchable quote.

### Database changes

None.

### Environment changes

None.

## [1.18.6] — 2026-08-20

### Fixed

- Ask-tab video seek still occasionally picked the source citation
  instead of the real testimony quote, in a citation phrasing variant
  the 1.18.2 fix missed. Found via a real production reply:
  `("Slava and Celia Interview," recorded October 7, 1991)` puts plain
  text between the closing quote mark and the `)`, unlike the fully
  quote-wrapped citations 1.18.2 was built against — so
  `video_seek_is_citation_span()`'s "must be immediately followed by
  `)`" check missed it, and the citation (textually closer to the
  `[[video:ID]]` token) won the nearest-quote search over the real
  quote, which never appears in the transcript. Simplified the check to
  just "starts immediately after an opening parenthesis" — that signal
  alone is reliable regardless of how the rest of the citation is
  phrased, since this app never introduces a testimony quote that way.

### Database changes

None.

### Environment changes

None.

## [1.18.5] — 2026-08-20

### Fixed

- Ask-tab video seek collapsed every occurrence of a repeated
  `[[video:ID]]` token down to one shared time. Found via a real
  production reproduction: a reply citing the same video twice — once
  for "hidden under a cow" and again, much later, for "the blockade
  finally ended" — computed the correct seek time for the second
  citation, but because `video_seek_resolve_for_reply()`'s result was
  keyed by file id (not by which occurrence it was), that one time got
  applied to *both* embeds, including the one next to the wrong quote.
  Now returns one seek result per token occurrence, in the order they
  appear in the reply, and `js/app.js` consumes it the same way (a
  running index over video-token matches) instead of an id lookup.
  Verified against a real multi-occurrence citation with a functional
  DB-backed test before shipping.

### Database changes

None.

### Environment changes

None.

## [1.18.4] — 2026-08-20

### Changed

- Strengthened `knowledge_system_role()`'s prompt rule for video
  citations: the model must now quote the actual words for a moment
  verbatim (in quotation marks) next to a `[[video:ID]]` token, rather
  than being allowed to attach the token to a paraphrase. Found via a
  real production capture: 1.18.1-1.18.3 fixed every deterministic
  matching/streaming bug, but the video still opened at 0:00 for one
  specific reply because the model described the moment in its own
  words with no quote marks at all near the token — with no verbatim
  passage to anchor to, the app correctly (and safely) declined to guess
  a timestamp, per its existing "never trust the model with precision"
  rule.
  **This is a prompt nudge, not a deterministic fix** — unlike 1.18.1
  through 1.18.3, it cannot guarantee every future reply includes a
  matchable quote, since the underlying model's instruction-following
  isn't 100% reliable. When it doesn't, the video will still embed and
  play, just starting at 0:00 rather than the cited moment, exactly as
  it did before this whole feature existed.

### Database changes

None.

### Environment changes

None.

## [1.18.3] — 2026-08-20

### Fixed

- Video seeking always landed at 0:00, for real this time — the root
  cause was one level below 1.18.1/1.18.2's quote-matching fixes.
  `api/file.php` and `api/admin/content_file.php` (every photo/video the
  app serves, including the Ask tab's `<video>` embeds) streamed the
  whole file with a plain `readfile()` and no HTTP Range support at all.
  A browser can only seek within a `<video>` — via a `#t=N` media
  fragment (1.18.0's Ask-tab seek feature) *or* via normal manual
  scrubbing — if the server supports partial `206` byte-range responses;
  without `Accept-Ranges`, every seek attempt is silently ignored and
  playback always starts over from 0:00, no matter what the URL says.
  This affected every video in the app, not just Ask-tab citations. New
  shared `content_stream_file()` helper (`includes/content.php`) adds
  proper Range-request handling (`206`, `Content-Range`,
  `Accept-Ranges: bytes`, and `416` for an out-of-bounds range),
  verified against real HTTP responses (not just in isolation) before
  shipping.

### Database changes

None.

### Environment changes

None.

## [1.18.2] — 2026-08-20

### Fixed

- Ask-tab video seeking still landed at 0:00 in a second, distinct case
  even after 1.18.1: `knowledge_system_role()`'s own citation convention
  quotes the source title right next to the passage it's citing (e.g.
  `— "actual words" ("Source Title") [[video:ID]]`), so the citation is
  itself picked up as a "quoted span" by the same extraction the video
  seek's quote-matching uses — and since the app places `[[video:ID]]`
  right after the citation, the citation is often textually *closer* to
  the token than the real testimony quote it's citing. The nearest-quote
  search was picking the citation's title, which obviously never appears
  in the transcript, so matching always failed. Now excludes any quoted
  span structurally wrapped in parentheses in the reply before searching
  for the nearest one to a video token.

### Database changes

None.

### Environment changes

None.

## [1.18.1] — 2026-08-20

### Fixed

- Ask-tab video seeking (1.18.0) always landed at 0:00 in real use.
  `video_seek_match_segment()` required the entire quoted passage to be
  one exact substring of the linked transcript, but a quote the model
  assembles from spoken testimony often stitches together a few
  non-contiguous words — skipping filler, cross-talk, or a brief
  interjection from the other speaker — using `"..."`, sometimes spanning
  two speakers' turns. That's normal for interrupted interview dialogue,
  not a fabrication, so requiring the whole thing verbatim was too
  strict. Now splits the quote on its own `"..."` markers and matches on
  the most distinctive fragment (longest first) instead — the seek time
  still always traces back to a real, verbatim substring of the actual
  transcript, never a guess.

### Database changes

None.

### Environment changes

None.

## [1.18.0] — 2026-08-20

### Added

- A video content item can now be linked to its companion transcript
  item (the same interview, transcribed) — either automatically at
  creation time (an exact, case-insensitive title match between an
  unlinked video and an unlinked transcript, no AI involved) or manually
  from the Content page's edit row via a new "Linked transcript"/"Linked
  video" picker, which always stays available as an override for when
  the automatic match misses a pair. The existing "Backfill AI analysis"
  button now also sweeps existing unlinked pairs for this same
  exact-title match and reports how many it linked.
- New transcript format: a transcript can now use per-segment timecodes
  (`00:00:53:21 - 00:01:09:15`, followed by a speaker line, then the
  spoken text) instead of the existing `## Tape N` / `**SUBJECT:**`
  convention — both formats are supported side by side, auto-detected
  per transcript.
- When an Ask-tab reply directly quotes a passage from a timecoded
  transcript that's linked to a video, the video reference now opens
  seeked to ~5 seconds before that passage instead of at 0:00 — resolved
  entirely server-side from the parsed timecode data (never computed or
  stated by the model itself, consistent with how citations and quotes
  are already always PHP-verified in this app).

### Database changes

Run `php upgrade.php` (or `upgrade.sh`) after deploying. Adds a nullable
`content_items.linked_item_id` self-referential column (with an
`ON DELETE SET NULL` foreign key) — see `sql/schema.sql`.

### Environment changes

None.

## [1.17.1] — 2026-08-20

### Added

- Content page: the "Add content" submit button now shows the same
  animated three-dot typing indicator as the Ask tab's pending reply
  while a "download from URL" (1.17.0) is in progress, instead of just
  static "Downloading…" text — a server-side download can take a while
  for a large file, so it's worth being clearer that something's still
  happening rather than the button just looking stuck.

### Fixed

- Content page: four `<p class="meta">` hint paragraphs (the Story/File/
  "download from URL"/Source URL hints) rendered in the page's default
  bright text color instead of muted grey. `.meta` is only styled by CSS
  when nested inside a `.card` (`.card .meta{...}`); every other `.meta`
  usage in the app (34 of them, across every admin page) works around
  this by setting `color:var(--muted); font-size:.85rem` inline on each
  one — these four were the only ones that didn't. Brought them in line
  with the rest.
- `narrative_parse_analysis()` (the connections-note pass every content
  type gets) only handled two shapes of model reply: pure JSON, or a
  ```json fenced block. When the model instead prefaced the required
  JSON object with a sentence or two of prose — a real instance seen in
  practice, despite the prompt saying "ONLY a JSON object, no prose" —
  `json_decode()` on the whole reply failed, and the fallback treated
  the *entire* raw reply (prose and the JSON block both) as the note,
  which read as garbled duplicated text. Added a brace-depth JSON-object
  extractor (correctly ignores braces inside quoted strings) that
  recovers the embedded object first, with a shape sanity-check before
  trusting it, so this degrades gracefully instead of leaking raw JSON
  into a stored note.

### Database changes

None.

### Environment changes

None.

## [1.17.0] — 2026-08-20

### Added

- Photo/Video content can now be added by giving a **URL to download
  from** instead of picking a local file — the server fetches it
  directly (`content_download_media_from_url()`), which sidesteps
  browser upload size/timeout limits entirely, since an outgoing fetch
  isn't subject to `post_max_size`/`upload_max_filesize` the way an
  incoming upload is. Streams straight to disk rather than buffering in
  PHP memory (a video can be hundreds of MB), validates the downloaded
  content is actually a photo/video the same way an upload is validated
  (real MIME sniffing, never the URL's claimed extension), and is capped
  at 1.5GB to stop a misconfigured/malicious URL from filling the disk.
  Give exactly one of a file or a URL; a URL always produces a single
  file (no multi-photo album from a URL).
- Redirects are now walked manually with the SSRF host-safety check
  (`content_is_safe_host()`) re-validated on *every* hop, for both the
  new media downloader and the existing URL-content-type text fetch
  (`content_fetch_url()`, previously used `CURLOPT_FOLLOWLOCATION`,
  which follows a redirect without ever re-checking the new host — an
  initially-safe URL could 302 to a private/internal target and the
  guard would never fire).

### Fixed

- Content page: the 1.16.0 Captured-column fix applied
  `display:-webkit-box` (required for the 3-line CSS clamp) directly to
  the `<td>` itself, which overrides its `display:table-cell` and could
  knock the cell out of the table's normal row layout — reported as the
  cell's content appearing truncated on its own line, above the rest of
  the row. The clamp now applies to an inner `<div>` instead, leaving
  the `<td>`'s own display untouched.

### Database changes

None.

### Environment changes

Not a `.env` change: if a large photo/video upload fails, your host's
PHP `post_max_size`/`upload_max_filesize` (and possibly PHP-FPM's
`request_terminate_timeout`) may need raising — see README's "Uploading
large files". The new "download from URL" option sidesteps this
entirely, since it isn't subject to either upload limit.

## [1.16.0] — 2026-08-19

### Added

- Ask tab: replies were silently cut off mid-word/mid-sentence whenever
  the model's answer ran past the 1200-token reply budget — the
  provider's `stop_reason`/`finish_reason` was never checked, so a
  truncated reply looked identical to a complete one. Raised the budget
  to 4096 (raising it costs nothing extra unless the model actually
  generates more — it's a ceiling, not a target) and `ai_chat()` now
  returns a `truncated` flag when the ceiling is still hit; `api/chat.php`
  surfaces it and the Ask tab appends a small "cut short" notice
  (same treatment as the quote-verification caution) rather than
  presenting a partial answer as complete.

### Fixed

- Content page: the 1.15.0 fix for the unbounded "Captured" column
  capped the number of `<br>`-joined summary *entries* to 3, but a
  single file's summary can itself wrap across several lines in a
  narrow column — 3 entries could still render as far more than 3
  visual lines in practice. Switched to real CSS line-clamping
  (`-webkit-line-clamp`), which bounds actual rendered lines regardless
  of how much any one entry wraps.

### Database changes

None.

### Environment changes

None.

## [1.15.0] — 2026-08-19

### Added

- Ask tab: the pending reply bubble now shows an animated three-dot
  typing indicator instead of static "Thinking…" text, so it's clearer
  at a glance that a request is in flight. The text is kept for screen
  readers (visually hidden, still announced via `#chatLog`'s existing
  `aria-live="polite"`) rather than shown, since the dots alone convey
  nothing to assistive tech.

### Fixed

- Content page: the "Mark reviewed" checkbox (added in 1.13.0) rendered
  with its checkbox mid-row and its label text pushed to the far right —
  `.content-form input{width:100%}` was stretching the checkbox itself,
  same as every other input in that form. Added `width:auto` on the
  checkbox specifically, matching the existing convention already used
  for the Sources tab's "is a dramatisation" checkbox.
- Content page: the "Captured" column (EXIF summary) was unbounded — a
  photo album with many files stacked one summary line per file with no
  limit. Capped at 3 lines, with the third suffixed "..." when there's
  more.

### Database changes

None.

### Environment changes

None.

## [1.14.0] — 2026-08-19

### Added

- The structured suggestion-extraction pass (proposes new
  timeline/people/places/quotes entries, held as pending suggestions for
  admin approval) previously only ran on URL content — now also runs on
  **Transcript** content at upload, and on **Story** content at approval
  time (matching when its narrative-connection note is computed). A
  first-person transcript or a family-recounted story can now grow the
  archive's structured registries the same way a submitted URL already
  could, still with the same never-auto-applied, per-suggestion admin
  review. Each suggestion's `citation` traces back to the specific
  content item (its title), and a new `sourceNote` field records which
  of the three content types it came from — previously hardcoded to "AI
  suggested from a submitted URL" regardless of actual origin, now
  accurate for all three, so a family-recounted story's proposals stay
  visibly distinct from a first-person transcript's or a documented
  URL's once applied to the archive.
- The "Backfill narrative notes" admin button is now "Backfill AI
  analysis" (`content_backfill_ai_analysis()`, renamed from
  `content_backfill_narrative_notes()`) and covers two independent gaps:
  a missing narrative note (unchanged from before), and — new — missing
  suggestions for any Transcript or already-approved Story added before
  this feature covered its type. Safe to re-run: only fills in what's
  actually missing per item, per pass.

### Database changes

None — `sourceNote` lives in `content_suggestions.payload` (JSON), no
schema change needed. A suggestion already pending from before this
upgrade has no `sourceNote` in its stored payload; approving it still
falls back to the old "AI-suggested from a submitted URL" wording, which
remains accurate for it (URL was the only source type before now).

### Environment changes

None.

## [1.13.0] — 2026-08-18

### Added

- Content page now surfaces which AI-generated "Narrative connection"
  notes no admin has ever reviewed — an `unreviewed` badge in both the
  item list and the edit row (new `narrative_note_reviewed_at` column,
  set only by an explicit admin action: editing the note text, or a new
  "Mark reviewed" checkbox in the edit row). These notes are written
  unattended on upload/approval/backfill and feed the Ask tab's
  knowledge base with no gate today — this is visibility, not a gate, so
  existing notes remain part of the knowledge base either way. Last of
  three planned Ask-tab accuracy safeguards. Every code path that
  (re)writes a note's text with fresh AI output also clears the review
  flag, so a stale "reviewed" mark can't survive an approval or backfill
  rewrite. `content_update_item()` gained two new parameters
  (`$isAdmin`, `$markReviewed`); its one caller
  (`api/admin/content_update.php`) was updated to match.

### Database changes

- `content_items` gains `narrative_note_reviewed_at DATETIME NULL`.
  Existing rows are **not backfilled to NOW()** — every item added before
  this upgrade will show as unreviewed, which is the honest answer (we
  don't actually know). On `slava.fintelfamily.com` this will surface a
  burst of badges on upgrade — expected, and worth working through as an
  actual review pass, not just letting the badges age.

### Environment changes

None.

## [1.12.0] — 2026-08-18

### Added

- Deterministic quote verification on Ask-tab replies: every reply is
  scanned for double-quoted spans (25+ characters), and each is checked
  against the exact material the model was given (the charter plus the
  archive knowledge base). An unmatched span never rewrites or blocks
  the reply — it appends a small caution naming the unmatched wording,
  so the family can check it against the original testimony. Second of
  three planned Ask-tab accuracy safeguards. New `includes/quote_check.php`
  (pure functions, no DB access); `api/chat.php` returns a new
  `unverifiedQuotes` field (capped at 3 spans); `js/app.js` renders the
  caution inside the reply bubble; bumped the service-worker cache name
  since `js/app.js`/`css/style.css` changed.

### Verified

- `php -l`, a disposable-script fixture suite covering the plan's full
  test matrix (verbatim, curly-quote/line-wrapped, altered-word,
  punctuation, nested, no-quotes, sub-floor, charter-stock-phrase — all
  passed), and, after deploying to `slava.fintelfamily.com`, a real
  end-to-end round-trip: a genuine reply quoting real testimony verified
  against the live archive with zero false positives. Not separately
  exercised alongside a `[[photo:ID]]` token in the same reply.

### Database changes

None.

### Environment changes

None.

## [1.11.0] — 2026-08-18

### Added

- Every AI call (Ask tab, narrative notes, URL-suggestion extraction) now
  sends an explicit sampling `temperature`, defaulting to a low `0.2`
  instead of the provider's own default (Anthropic: `1.0`). These are
  all recall/citation tasks — the Ask tab answers from the archive, and
  the narrative/suggestion passes must cite or copy verbatim, never
  invent — so a low temperature reduces paraphrase drift and quote
  fabrication. First of three planned Ask-tab accuracy safeguards.
  Configurable via the new `AI_TEMPERATURE` env var; set it to the
  literal word `default` to omit the parameter and fall back to the
  provider's own default, since some OpenAI-shaped backends and
  reasoning models reject any other temperature with an error.

### Verified — and a real-world compatibility note

- Deploying this to `slava.fintelfamily.com` immediately surfaced exactly
  the failure mode this env var exists for: the account's
  currently-configured Anthropic model rejected `temperature` outright —
  `ai_http_post: AI provider error (HTTP 400) ... "temperature is
  deprecated for this model"` — which broke the Ask tab (502 to real
  users) until `AI_TEMPERATURE=default` was set in `.env`. This wasn't an
  OpenAI-shaped-backend-only risk as assumed above; **if the Ask tab
  starts erroring right after upgrading to this version, check the PHP
  error log for that HTTP 400 and set `AI_TEMPERATURE=default`.** Once
  set, a real round-trip against the live provider confirmed the
  parameter is correctly omitted and a normal reply comes back.

### Database changes

None.

### Environment changes

- New optional `AI_TEMPERATURE` (defaults to `0.2` if unset). See
  `.env.example`.

## [1.10.1] — 2026-08-16

### Fixed

- The expired/already-used screen on `/reset_password.php` was a dead
  end — a "Request a new link" button but no way back to login. Real
  scenario: the reset link is single-use, so opening/submitting it
  twice (e.g. two tabs, a resubmit after going back) shows this screen
  even when the first attempt already succeeded, which read as an
  unexplained failure. Added a "Back to login" link and reworded the
  message to say so explicitly when that's likely what happened.

### Database changes

None.

### Environment changes

None.

## [1.10.0] — 2026-08-16

### Added

- Forgot-password flow: `/forgot_password.php` emails a time-limited
  (1 hour), single-use reset link to the account's address; the link
  lands on `/reset_password.php` to set a new password. Until now, a
  user who forgot their password with no passkey registered had no way
  back into their account short of someone with direct database access
  resetting it by hand. Uses the same signed-token pattern as the
  signup approve/reject links. Requesting a reset always returns the
  same "if that email has an account…" response and only ever sends
  mail to an address that's actually registered, so the endpoint can't
  be used to test which emails have accounts. Rate-limited per IP
  (`FORGOT_PASSWORD_RATE_LIMIT`, default 5/hour). A successful reset
  also clears any existing login lockout on that account and emails a
  "your password was changed" notice as a tamper alert.

### Database changes

None.

### Environment changes

New optional `.env` var: `FORGOT_PASSWORD_RATE_LIMIT` (defaults to 5 if
unset).

## [1.9.0] — 2026-08-15

### Added

- Self-service password change at `/account.php`, available to every
  logged-in user (readers included, not just admin/author) — until now
  there was no way to change a password after signup at all. Requires
  the current password; rate-limited per account the same way login is.

### Fixed

- A pending (not-yet-approved) account trying to log in got the same
  "Incorrect email or password" error as a genuinely wrong password —
  and each attempt counted against the 5-try lockout, so a new user
  logging in before an admin approved them could lock themselves out
  for 15 minutes for doing nothing wrong. `api/login.php` now checks
  the password first: if it's actually correct but the account just
  isn't approved yet, it shows a specific "still awaiting approval"
  message and doesn't touch the lockout counter. An incorrect password
  still gets the same generic error as before either way, so this can't
  be used to tell whether an email has an account.

### Database changes

None.

### Environment changes

None.

## [1.8.1] — 2026-08-15

### Fixed

- Registering a passkey always failed with "Call to a member function
  getBinaryString() on string" — `includes/webauthn_helper.php` assumed
  the vendored library's `processCreate()` returned `credentialId` as a
  `ByteBuffer` (like several other fields in that same response
  object), but it's actually already a raw PHP string
  (`AuthenticatorData.php` builds it via `substr()`, never wraps it).
  The crash happened *after* the library had already cryptographically
  validated the passkey, only while saving it — so a browser password
  manager that stores the passkey at creation time (e.g. Bitwarden)
  would correctly decline to keep it, since the server never confirmed
  the registration completed.

### Database changes

None.

### Environment changes

None.

## [1.8.0] — 2026-08-15

### Added

- **Passkey (WebAuthn) login** for admin and author accounts — additive
  to password login, never a replacement; readers keep password-only.
  Register a passkey (fingerprint, face, screen lock, or a security
  key) at the new `/account.php`, then use "Sign in with a passkey" on
  the login page instead of typing a password. Built on a vendored copy
  of [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn) (MIT, see
  `includes/webauthn/NOTICE.md`) — the one deliberate exception to this
  project's otherwise dependency-free approach, since hand-rolling
  WebAuthn's signature/attestation verification is genuine
  account-takeover risk if gotten wrong, not just a display bug.
  `includes/webauthn_helper.php` is this project's own thin wrapper
  around it.
- `/account.php`: new self-service page (admin/author) to register,
  name, and remove passkeys. Linked from the main app header.

### Database changes

Adds `webauthn_credentials` (`upgrade.sh` handles this automatically —
see `sql/schema.sql` for the exact definition, or run that file's
`CREATE TABLE IF NOT EXISTS webauthn_credentials` block by hand).

### Environment changes

None — no new `.env` variables. (PHP's `openssl` extension was already
a stated requirement; this doesn't add a new one.)

## [1.7.0] — 2026-08-15

### Added

- `/admin.php` now shows each user's last login time, recorded via
  `auth_login()` (the single choke point every login method already
  goes through, so any future login method — passkeys included — gets
  this for free with no extra wiring).
- Client-side search box on `/admin.php`, filtering by name/email/role/
  status — useful once the user list grows past a glance.

### Database changes

Adds `users.last_login_at` (`upgrade.sh` handles this automatically).
Manual equivalent:

```sql
ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL AFTER approved_at;
```

### Environment changes

None.

## [1.6.0] — 2026-08-13

### Added

- "Make admin" action on `/admin.php` — promotes a reader or author
  straight to a full admin, with a confirmation dialog given how much
  access that grants (user management, every part of the site). Admin
  accounts still aren't changeable through this page afterward — there's
  deliberately no demotion path back to author/reader for an existing
  admin, same reasoning as the existing Revoke/Delete protection (rules
  out ever locking everyone out of user management by mistake).

### Database changes

None.

### Environment changes

None.

## [1.5.4] — 2026-08-13

### Added

- The site identity's "Date of passing" (added in 1.5.2) now syncs
  one-directionally into the subject-role person's own `fate` field in
  the People registry — the two were separate columns in different
  tables with no connection, which read as a bug ("I set it and it's
  not showing in People") but was really just two unrelated fields.
  Setting/changing the date overwrites that person's `fate` to
  "Survived. Died {date}." — a real overwrite, not a merge; see
  README.md for the tradeoff if you've written a fuller fate for them.

### Database changes

None.

### Environment changes

None.

## [1.5.3] — 2026-08-13

### Fixed

- `archive_site_settings_update()` used `REPLACE INTO`, which deletes
  and reinserts the row — so any column not in its explicit list
  silently reverted to its schema default. In practice this meant
  **every settings save reset `schema_version` back to `1.0.0`**, found
  while testing 1.5.2. It also meant any *future* column added to
  `site_settings` would have needed a matching edit here to survive a
  save, with no error if someone forgot. Replaced with a real `UPDATE`
  (plus `INSERT IGNORE` up front to guarantee the row exists on a
  brand-new install) — now only the columns actually listed are ever
  touched, and unlisted columns (like `schema_version`, or any later
  addition) are never touched. No action needed: the next `upgrade.sh`
  run corrects `schema_version` regardless of what it was reset to.

### Database changes

None.

### Environment changes

None.

## [1.5.2] — 2026-08-13

### Added

- A "Date of passing" field alongside the existing birth date (setup
  wizard and `admin_settings.php`) — optional, blank if the subject is
  living. Shows on the About tab and feeds the Ask tab's system prompt
  the same way birth date already did.

### Database changes

Adds `site_settings.subject_death_date` (`upgrade.sh` handles this
automatically). Manual equivalent:

```sql
ALTER TABLE site_settings ADD COLUMN subject_death_date VARCHAR(100) NULL AFTER subject_birthplace;
```

### Environment changes

None.

## [1.5.1] — 2026-08-13

### Added

- The admin now gets an email (same `NOTIFY_EMAIL`/SMTP setup as the
  signup-request notification) when an author submits a new story —
  linking to `admin_content.php` to review it. An admin submitting their
  own story doesn't trigger it (they can just approve it themselves).

### Database changes

None.

### Environment changes

None.

## [1.5.0] — 2026-08-13

### Added

- **Family Stories**: a new "Story" content type (`admin_content.php`,
  any author can add) for stories family members tell about the
  subject, distinct from their own testimony. Held pending until an
  admin approves it (`admin_content.php` shows a pending badge + an
  Approve button) — only then does it appear on the new "Stories" tab
  and get included in the Ask tab's knowledge base, explicitly weighted
  below the primary testimony transcript ("recounted by family, not the
  subject's own words — reliable but secondhand"). No AI analysis runs
  until approval, so nothing about a pending story is visible anywhere
  (including via other entries' related-content links) before review.

### Database changes

Adds `'story'` to `content_items.type` and a new
`content_items.story_approved_at` column (`upgrade.sh` handles this
automatically). Manual equivalent:

```sql
ALTER TABLE content_items MODIFY COLUMN type ENUM('transcript','photo','video','url','story') NOT NULL;
ALTER TABLE content_items ADD COLUMN story_approved_at DATETIME NULL AFTER tags;
```

### Environment changes

None.

## [1.4.1] — 2026-08-13

### Fixed

- `upgrade.php` wrapped each version's `db` step in an explicit
  `beginTransaction()`/`commit()` — but MySQL's `ALTER TABLE` (and other
  DDL) causes an implicit commit, silently ending that transaction the
  moment it runs. Any step mixing DDL with other statements (like
  1.4.0's role migration) then failed on `commit()` with "There is no
  active transaction" — even though every SQL statement in the step had
  already executed and committed successfully; only the bookkeeping
  call failed, after the real work was done. `upgrade.php` no longer
  wraps steps in a transaction (DDL can't be rolled back in MySQL
  regardless, so it was never real protection — the actual safety net
  is the automatic backup taken before any step runs).

### Database changes

None beyond what 1.4.0 already applies (this only touches the runner,
not the schema). If you hit the "There is no active transaction" error
running 1.4.0's upgrade, check whether it actually finished — see this
version's fix description; you likely just need `upgrade.sh` re-run
after updating to record `schema_version` correctly.

### Environment changes

None.

## [1.4.0] — 2026-08-13

### Added

- Three-tier user roles: **admin** (unchanged), **author** (can add
  content and edit/delete their own — replaces the old `member` +
  `can_add_content` flag combo), **reader** (view archive, use Ask tab,
  can't add content). New signups start as readers. Manage from
  `/admin.php` ("Make author"/"Make reader").
- Keyword picker suggestions now filter live as you type, instead of
  always showing every existing keyword.

### Fixed

- Related-content links (Timeline/Quotes/People/Places cards) for a URL
  source pointed at our locally-cached scrape of the page instead of the
  actual source (e.g. a Wikipedia link "dumped raw HTML" instead of
  opening Wikipedia) — now links straight to the source URL. Also fixed
  the fallback for non-photo/video content types, which previously
  rendered as a broken image with no icon; text/URL links now get a
  proper icon.
- `service-worker.js` treated `/api/data.php` (Timeline/Quotes/People/
  Places data) as cache-first, so an admin's edit — e.g. changing
  someone's Fate in the Archive editor — didn't show up in the app until
  a *second* page load. Now network-first: always tries the network,
  only falls back to cache when actually offline. (Note: the AI-facing
  narrative notes on existing content items don't auto-update when
  Archive data changes — that's what `admin_content.php`'s existing
  "Backfill narrative notes & links" button is for, unrelated to this
  caching bug.)

### Database changes

Migrates `users.role` from `ENUM('admin','member')` + a separate
`can_add_content` flag to `ENUM('admin','author','reader')`, then drops
`can_add_content`. `upgrade.sh` handles this automatically (widens the
enum, migrates every row's data, narrows it back down, drops the old
column). Manual equivalent if you're not using `upgrade.sh`:

```sql
ALTER TABLE users MODIFY COLUMN role ENUM('admin','author','reader','member') NOT NULL DEFAULT 'reader';
UPDATE users SET role = 'author' WHERE role = 'member' AND can_add_content = 1;
UPDATE users SET role = 'reader' WHERE role = 'member';
ALTER TABLE users MODIFY COLUMN role ENUM('admin','author','reader') NOT NULL DEFAULT 'reader';
ALTER TABLE users DROP COLUMN can_add_content;
```

### Environment changes

None.

## [1.3.2] — 2026-08-13

### Fixed

- 1.3.1's `--omit-dir-times` fix was incomplete — rsync then failed the
  same way trying to set *permissions* on a root-owned webroot's
  top-level directory. `deploy.sh` now skips times/perms/owner/group
  entirely (`--no-times --no-perms --no-owner --no-group`) and compares
  by checksum instead of the mtime+size quick-check, since skipping
  `--times` means source mtimes can't be trusted for change detection.
  Verified: a brand-new file (`deploy.sh` itself, syncing into
  `oss-test` for the first time) still landed with the correct `755`
  from rsync's normal new-file behavior, and an existing file
  (`upgrade.sh`) kept its already-correct `+x`.

### Database changes

None.

### Environment changes

None.

## [1.3.1] — 2026-08-13

### Fixed

- `deploy.sh` failed with `rsync error ... code 23` when the target
  webroot's own directory is owned by `root` (typical for a cPanel
  subdomain docroot, group-writable to the site user) — rsync could
  sync file contents fine but not the directory entry's own mtime.
  Added `--omit-dir-times`, which rsync doesn't need for correctness
  here.

### Database changes

None.

### Environment changes

None.

## [1.3.0] — 2026-08-13

### Added

- `deploy.sh`: run from inside a git clone kept outside any webroot
  (see UPGRADE.md) — does `git pull`, syncs the result into a target
  webroot with `rsync -a --delete` (excluding `.env` and `.git`), then
  runs that webroot's `upgrade.sh`. The full "get current" cycle in one
  command instead of three.

### Database changes

None.

### Environment changes

None.

## [1.2.1] — 2026-08-12

### Added

- The About tab now shows the running app version (read from `VERSION`),
  so you can tell at a glance whether a deployment is current.

### Database changes

None.

### Environment changes

None.

## [1.2.0] — 2026-08-12

### Added

- Automated upgrade runner: `upgrade.sh` (run after deploying new code)
  applies every pending database change between your currently-installed
  version and `VERSION`, in order, via `upgrade.php` and the step list in
  `includes/migrations.php`. Takes an automatic backup (using the 1.1.0
  Backup & Restore engine) before changing anything. Safe to re-run —
  each version's step only applies once. Every future release that needs
  a database change adds a step to `includes/migrations.php` in the same
  commit that updates this changelog, so the two stay in sync.
- `VERSION` file at the repo root — what `upgrade.sh` treats as "the
  version you just deployed."

### Database changes

Adds `site_settings.schema_version` (defaults existing rows to `1.0.0`,
since this is the first release that tracks it) — this is how
`upgrade.sh` knows what's already been applied. If you're upgrading by
hand instead of with `upgrade.sh`, run:

```sql
ALTER TABLE site_settings ADD COLUMN schema_version VARCHAR(20) NOT NULL DEFAULT '1.0.0' AFTER setup_completed_at;
UPDATE site_settings SET schema_version = '1.2.0' WHERE id = 1;
```

### Environment changes

None.

## [1.1.0] — 2026-08-12

### Added

- Backup & Restore admin page (`admin_backup.php`): creates a single
  downloadable .zip containing a full database dump and every uploaded
  file, storable/downloadable from the admin UI. Restore replaces the
  entire database and uploads directory from a previously created
  backup .zip. Pure PHP (PDO + ZipArchive) — no `mysqldump`/`shell_exec`
  dependency, so it works on hosts that don't allow either.

### Database changes

None — backups are stored as files under `ARCHIVE_ROOT/backups/`, not
tracked in a database table.

### Environment changes

None.

## [1.0.0] — 2026-08-11

Initial public release.

### Added

- Database-backed archive (people, places, timeline, quotes, primary
  testimony, discrepancy notes) — the database is the single source of
  truth; no `knowledge/*.yaml` file layer to hand-edit or keep in sync.
- Admin Archive Editor (`admin_archive.php`) for all of the above, plus
  a legacy-YAML importer tab for migrating from the original file-based
  version of this app.
- First-run setup wizard (`setup.php`) — database connection, archive
  identity, admin account, AI provider, optional advanced settings.
  Fully resumable; re-derives its stage from actual server state rather
  than tracking wizard progress separately.
- AI-grounded Ask tab, supporting either Anthropic or any
  OpenAI-compatible provider (OpenAI itself, Groq, DeepSeek, OpenRouter,
  Azure OpenAI, a local Ollama/LM Studio server, etc.) via `AI_PROVIDER`
  / `AI_BASE_URL`.
- Family Content page: transcripts, photos, videos, and URL sources,
  each analyzed against the existing archive for a narrative-connection
  note and (for URLs) structured suggested archive additions.
- Content cross-referencing: photos/videos/etc. can be linked to
  specific people/places/timeline entries/quotes they depict, surfaced
  on the Browse tabs, not just inside Ask-tab chat replies.
- Keyword picker on the Content page (admin-curated, distinct from the
  AI-suggested content links above).
- Name redaction, per-user audience categories ("Telling for:"), account
  approval workflow with email notifications.
- Installable PWA (manifest + service worker).

### Database changes

None to track for upgraders — this is the baseline. Run `sql/schema.sql`
against a fresh database as described in `README.md`.

### Environment changes

None to track for upgraders — this is the baseline `.env` shape; see
`.env.example`.

[Unreleased]: https://github.com/sfintel/family-legacy-archive/compare/v1.18.8...HEAD
[1.20.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.20.0
[1.19.2]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.19.2
[1.19.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.19.1
[1.19.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.19.0
[1.18.9]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.9
[1.18.8]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.8
[1.18.7]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.7
[1.18.6]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.6
[1.18.5]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.5
[1.18.4]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.4
[1.18.3]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.3
[1.18.2]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.2
[1.18.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.1
[1.18.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.18.0
[1.17.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.17.1
[1.17.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.17.0
[1.16.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.16.0
[1.15.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.15.0
[1.14.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.14.0
[1.13.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.13.0
[1.12.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.12.0
[1.11.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.11.0
[1.10.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.10.1
[1.10.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.10.0
[1.9.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.9.0
[1.8.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.8.1
[1.8.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.8.0
[1.7.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.7.0
[1.6.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.6.0
[1.5.4]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.5.4
[1.5.3]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.5.3
[1.5.2]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.5.2
[1.5.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.5.1
[1.5.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.5.0
[1.4.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.4.1
[1.4.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.4.0
[1.3.2]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.3.2
[1.3.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.3.1
[1.3.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.3.0
[1.2.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.2.1
[1.2.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.2.0
[1.1.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.1.0
[1.0.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.0.0
