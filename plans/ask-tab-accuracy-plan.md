# Ask-tab accuracy plan

Implementation plan for three safeguards against the Ask tab's
hallucination risk. **This is a plan only — no application code has been
written.** A previous half-finished attempt exists as `git stash@{0}`
("WIP: temperature + quote verification…"); it is referenced below only
where this plan deliberately agrees or disagrees with it. Do not
`git stash pop` it and call it done — several of its decisions are wrong
(see the "diverges from the stashed draft" notes).

## Goal

The project's purpose is recalling **recorded facts** from a Holocaust
survivor's testimony, not creative writing. The Browse tab is
deterministic (DB rows rendered directly). The Ask tab is not: it is an
LLM handed the whole archive (`knowledge_context()`) plus a charter
(`knowledge_system_role()`) and asked to answer in prose. The charter
already forbids inventing quotes/events/feelings — but nothing verifies
that, and one AI-generated artifact (`content_items.narrative_note`)
gets written into the archive with no human gate and then feeds every
future answer.

Three narrow, mostly-deterministic changes:

1. **Lower the sampling temperature** on every AI call — currently no
   `temperature` is sent at all, so every call runs at the provider
   default (Anthropic: 1.0).
2. **Verify claimed direct quotes** in Ask-tab replies against the exact
   text the model was given, and surface a caution when one doesn't
   check out. Never rewrite the reply.
3. **Track whether a human has reviewed** an AI-generated
   `narrative_note`, and show it in the admin Content page.

Non-goal (deliberately): making the Ask tab non-generative, or blocking
unreviewed notes from the knowledge base. See "Deferred / non-goals".

## Verified starting state (as of VERSION 1.10.1)

- `includes/ai_provider.php` — `ai_chat(array $systemParts, array
  $messages, int $maxTokens): ?array` dispatches to
  `ai_chat_anthropic()` / `ai_chat_openai()`. **Neither payload contains
  a `temperature` key.** Config accessors here are functions reading
  `env()` (`ai_provider()`, `ai_model()`, `ai_base_url()`), not
  `define()`s in `config.php`.
- Callers of `ai_chat()`: `api/chat.php:43` (Ask tab, 1200 tokens) and
  `includes/narrative.php:195` `narrative_call_ai()` — used by
  `narrative_analyze()` (900) and `narrative_suggest_additions()` (4096).
  That is all of them.
- `api/chat.php` builds `$charter = knowledge_system_role()` and
  `$context = knowledge_context()`, calls `ai_chat()`, runs
  `redact_text()` over the reply, and returns
  `['reply' => …, 'usage' => …]`. Both `$charter` and `$context` are
  still in scope at that point.
- `knowledge_context()` is redacted at the end via `redact_text()`;
  `knowledge_system_role()` is **not** redacted, and is not included in
  the context string — it is a separate system block.
- `content_items` has no review-state column. `narrative_note` is
  written by `content_create_transcript/url/video/photo_album()` (at
  upload), `content_approve_story()` (at approval), and
  `content_backfill_narrative_notes()` (admin button) — all
  unattended AI output. `content_update_item()` is the only human write.
- `content_public()` (`includes/content.php:80`) is the admin-facing
  shape; `narrative_note` is never exposed to readers, only to
  `/admin_content.php` and to `knowledge_context()`'s
  `content_context()`.
- Existing badge CSS: `.status-badge` + `.status-pending` (amber) /
  `.status-approved` (green) / `.status-rejected` (red) in
  `css/style.css:142-145`. Used by `js/admin_content.js` for story
  approval and suggestion status.
- `includes/backup.php` dumps with `SELECT *` + `SHOW CREATE TABLE`, so
  a new column is picked up automatically — no backup change needed.

## Project conventions every landing must follow

Confirmed from `CHANGELOG.md`, `UPGRADE.md`, `includes/migrations.php`,
and `README.md`'s "A note on how changes get verified":

1. **Version bump** — `VERSION` file. New user-visible behavior = minor
   bump (`1.11.0`); pure fix = patch.
2. **`includes/migrations.php`** — a new keyed entry with
   `description`, `db` (closure or `null`), `env` (list of strings).
   Every `db` closure **must** check `information_schema.columns` before
   `ALTER TABLE` — `upgrade.php` replays every step newer than the
   recorded version, so steps must be safe against a partially-migrated
   database. Copy the `1.5.2` / `1.7.0` entries as the pattern.
3. **`sql/schema.sql`** — any new column also goes into the `CREATE
   TABLE` (with an explanatory comment, matching the density of the
   existing ones), so a fresh install and an upgraded install converge.
4. **`CHANGELOG.md`** — new `## [x.y.z] — YYYY-MM-DD` section above the
   previous one, with `### Added` / `### Fixed`, then **`### Database
   changes`** and **`### Environment changes`** sections (write "None."
   explicitly when there are none), and the link refs at the bottom of
   the file updated (`[Unreleased]` compare link + a new release tag
   link).
5. **`README.md`** — update in the same commit (per the user's standing
   rule). Relevant sections: "Content management" (~line 256) for the
   review-status feature; a new "Ask tab accuracy safeguards" section is
   a reasonable home for temperature + quote verification.
6. **Testing discipline** — there is no local PHP/MySQL. Verify by
   `scp`-ing changed files to the disposable remote `oss_webroot`,
   running `php -l` on every changed PHP file, applying schema changes
   over SSH **before** deploying code that depends on them, exercising
   the feature end-to-end with throwaway test data, and deleting every
   disposable test script and test row afterward. `.claude/settings.local.json`
   already allows `ssh`/`scp`/`git`/`php -v`.
7. **Deploy** — `deploy.sh <webroot>` (git pull + rsync + `upgrade.sh`),
   or `upgrade.sh` in the webroot. `upgrade.sh` takes its own backup
   first.
8. **Service-worker cache** — changes to `js/app.js` / `css/style.css`
   are subject to the PWA cache; check `service-worker.js`'s cache name
   / versioning before assuming a browser will pick up new frontend
   assets (this has bitten this project before).

---

# Feature 1 — Lower AI sampling temperature

Small, self-contained, no schema. Do this first; it is the cheapest and
it de-risks the other two by reducing run-to-run variance while testing.

### 1.1 Add a temperature accessor to `includes/ai_provider.php`

Add alongside `ai_model()` / `ai_base_url()` (that file, not
`config.php`, is where AI config lives — keep it consistent):

```php
function ai_temperature(): ?float
```

Reads `AI_TEMPERATURE` from `env()`, defaults to the chosen low value.
Returns `null` when the env var is explicitly set to something meaning
"leave it to the provider" (recommend the literal string `default`;
avoid empty string, which `env()` already treats as unset).

**Why env-configurable rather than hardcoded** (this diverges from the
stashed draft, which hardcoded `0.2`): `AI_BASE_URL` means this app can
point at Groq / DeepSeek / OpenRouter / Azure / Ollama, and some
OpenAI-shaped backends and reasoning models **reject** any temperature
other than their fixed one with a 400. `ai_http_post()` fails quiet on
non-2xx, so that failure mode is "the Ask tab returns 502 and only the
server log knows why" — a hard-to-diagnose regression for a downstream
user of the open-source project. The escape hatch costs three lines.

### 1.2 Thread `$temperature` through `ai_chat()` and both adapters

- `ai_chat(array $systemParts, array $messages, int $maxTokens, ?float $temperature = null): ?array`
  — `null` means "use `ai_temperature()`", so a future call site can
  override without every call site having to know the default.
- `ai_chat_anthropic()` / `ai_chat_openai()` take `?float $temperature`
  as a **required** parameter (no default). These are internal helpers
  only ever called from `ai_chat()`; giving them their own defaults
  (as the stashed draft did) duplicates the value in three signatures.
- In both payload builders, add `'temperature' => $temperature` **only
  when `$temperature !== null`** — so the `default` escape hatch really
  omits the key rather than sending `null`.
- Update the `ai_chat()` docblock to say what the low default is for
  (recall-and-cite, not composition) and that `null` = provider default.

### 1.3 Document the new env var

- `.env.example` — add `AI_TEMPERATURE` in the AI block, commented with
  the default and the `default` escape value.
- `README.md` — mention it wherever `AI_PROVIDER`/`AI_MODEL`/`AI_BASE_URL`
  are documented, plus a line in the new "Ask tab accuracy safeguards"
  section.
- `CHANGELOG.md` "Environment changes": new **optional** var
  `AI_TEMPERATURE` (defaults to the chosen value if unset) — the same
  shape as the `FORGOT_PASSWORD_RATE_LIMIT` entry in 1.10.0.

### 1.4 Verify against the real provider

`php -l` both changed files, deploy to the test webroot, send one real
Ask-tab message and confirm a 200 with a sane reply (not a 502 from a
rejected `temperature`). Then trigger one narrative pass (add a
throwaway transcript content item) and confirm a note still comes back.
Delete the throwaway item afterward.

### Open question 1-A — the actual value

**Recommendation: `0.2`.** Low enough to sharply cut paraphrase drift
and quote invention, not `0.0` (which on some providers is more prone to
degenerate repetition, and gives no benefit here because we are not
trying to make answers byte-reproducible). **Alternatives:** `0.0` if
the user wants maximum determinism; `0.3–0.4` if the answers start
reading mechanically in the family-facing "Ask" voice. Easy to retune
later now that it is an env var — worth saying so in the README.

### Open question 1-B — one blanket value or per-call-site

**Recommendation: one blanket value.** All three call sites are
recall/extraction tasks — the Ask tab cites the archive, `narrative_analyze()`
must "cite, never invent," and `narrative_suggest_additions()` must copy
quotes verbatim and emit strict JSON. None of them want variance. The
optional per-call parameter from 1.2 leaves the door open without
spending anything now. **Alternative:** if suggestion-generation turns
out to under-propose at low temperature, pass a slightly higher explicit
value from `narrative_suggest_additions()` only.

---

# Feature 2 — Deterministic quote verification on Ask-tab replies

The charter says "Do not invent quotes"; nothing checks it. This adds a
non-LLM post-check: extract spans the model presents as direct quotes,
require each to appear verbatim (modulo normalization) in the material
the app actually gave the model, and surface a caution when one doesn't.
**The reply is never modified** — an unverified span is often a
normalization miss, not a fabrication, and silently editing testimony
prose is a worse failure than flagging it.

### 2.1 New file `includes/quote_check.php` — pure functions

Put it in its own file rather than in `knowledge.php` (this diverges
from the stashed draft). Rationale: `knowledge.php` already does two
jobs (charter + context assembly); verification is a third, has zero DB
dependencies, and being standalone makes it trivially exercisable from a
disposable CLI script. The project already favours small single-purpose
includes (`redaction.php`, `markdown_lite.php`, `tokens.php`).

Add `require_once __DIR__ . '/includes/quote_check.php';` to
`config.php`'s require block (order doesn't matter — no dependencies).

Suggested API (names are a judgment call, shapes are not):

```php
function quote_check_normalize(string $s): string
function quote_check_extract_spans(string $reply): array   // list<string>, raw spans
function quote_check_unverified(string $reply, string $haystack): array // list<string>
```

**`quote_check_normalize()` must handle, on both sides of the
comparison:**
- curly → straight quotes: `U+2018 U+2019 U+201A` → `'`,
  `U+201C U+201D U+201E` → `"`
- en/em dash and non-breaking hyphen (`U+2013 U+2014 U+2011`) → `-`
- ellipsis `U+2026` → `...`
- non-breaking space `U+00A0` → space
- collapse all runs of whitespace (`\s+`, incl. newlines) to one space
- `mb_strtolower()` + `trim()`

The transcript is a long multi-line document; a quote the model prints
on one line will span a newline in the source, so whitespace collapsing
is load-bearing, not cosmetic.

**`quote_check_extract_spans()`:**
- Only **double**-quoted spans count as a direct-quote claim. A
  single-quote scan false-positives on every contraction and possessive
  — do not add one.
- Match straight and curly openers/closers interchangeably; exclude all
  quote characters from the inner class so nested quotes simply produce
  the shorter inner match rather than swallowing sentences. Non-greedy.
- Enforce a minimum length floor (see open question 2-B).
- Use the `/u` flag so the length floor counts characters, not bytes.

**`quote_check_unverified()`:** normalize the haystack once, then for
each span: strip leading/trailing punctuation and whitespace from the
**span only** (`.,;:!?—-` and space) before comparing — quoting
`"…they took everything."` where the transcript reads `…everything,` is
a punctuation artifact, not a fabricated quote, and this single step
removes a large share of the false positives. Then `str_contains()`.
Return the spans that failed, in reply order, original (un-normalized)
text so the log and UI show what the reader actually saw.

Never throws, no DB access, no side effects.

### 2.2 Wire it into `api/chat.php`

After the existing `redact_text()` line (which is important — see
below), before `json_response()`:

- Haystack = **`$charter` + `$context`**, not `$context` alone. This
  diverges from the stashed draft and it matters: the charter contains
  the source labels the model is told to cite, and the stock phrases the
  charter tells it to use verbatim (`"(dramatisation)"`, `"the record
  doesn't say."`, `"as the family tells it"`). Those are all long enough
  to clear any sane length floor and none of them appear in
  `knowledge_context()`, so checking against the context alone
  guarantees recurring false cautions. Both variables are already in
  scope at that point in the file.
- **Order matters:** verify the *redacted* reply against the *redacted*
  context. `knowledge_context()` already redacts itself, and
  `api/chat.php` redacts the reply — so both sides carry
  `[name withheld]` identically and a quote containing a redacted name
  still matches. Verifying pre-redaction reply against post-redaction
  context would flag every such quote. Note `$charter` is **not**
  redacted; that's fine (it contains no archive prose), but say so in a
  comment so nobody "fixes" it later.
- Log via `error_log()` when the list is non-empty: a count plus each
  span truncated (~200 chars), joined with a separator. Prefix
  distinctively (e.g. `chat.php: unverified quote span(s)`) so it's
  greppable in a shared host's PHP error log. Post-redaction text means
  no redacted name can leak into the log — worth a comment.
- Response shape: add one field to the existing JSON. See open question
  2-C.

### 2.3 Frontend caution in the Ask tab

- `js/app.js` — `setAssistantReply(bubble, text)` gains a third
  argument for the unverified-quote payload; the `chatForm` submit
  handler passes `data.<field>` through. Append a `<div
  class="quote-caution">` inside the bubble, after the reply content,
  built with `textContent` (never `innerHTML` — the spans are model
  output).
- Wording: it is a *caution*, not an error. Say what happened and what
  to do: the reply contains quoted wording that could not be matched
  word-for-word in the archive, so check it against the original
  testimony before relying on it. Do not use an emoji as the only
  signal; lead with a text label ("Caution:"). Keep the reply itself
  visually unchanged.
- `css/style.css` — add `.quote-caution` near the other `.bubble`
  rules (~line 117). Needs `white-space:normal` because `.bubble` sets
  `pre-wrap`. Suggested treatment: a dashed top border in `--border`,
  small type, `--accent` or `--text` colour. Check contrast against
  `--panel` (#242320) meets 4.5:1 — `--accent` (#b8863b) is around 4.9:1
  and passes, but verify rather than assume; `--muted` (#a89f8f) would
  also pass and read less alarming.
- Accessibility: `#chatLog` is already `role="log" aria-live="polite"
  aria-relevant="additions"`, so appending inside the bubble is
  announced. No new ARIA needed; do not add `role="alert"` (it would
  interrupt).
- The caution is **not** pushed into the client-side `history` array —
  only `data.reply` is, exactly as today. Don't let the caution text
  become part of the next request's conversation history.
- Remember the service-worker cache when testing (convention 8 above).

### 2.4 Test with a disposable script, then live

Two layers:
- A throwaway CLI script on the test host (`php -r` or a temp file,
  deleted after) that `require`s only `includes/quote_check.php` and
  runs a fixture table: a verbatim quote from a known transcript line
  (must pass), the same quote with curly quotes and a line break (must
  pass), the same quote with one word changed (must fail), a quote
  differing only in trailing punctuation (must pass), a nested-quote
  string, a contraction-heavy reply with no double quotes (must yield
  zero spans), a sub-floor short quote (must be skipped), and one of the
  charter's stock phrases in quotes (must pass, proving 2.2's haystack
  choice).
- Then live: one Ask-tab question whose answer will quote real
  testimony (expect no caution), and one crafted to elicit a
  quote-shaped answer that isn't in the archive. Also confirm a reply
  containing a `[[photo:ID]]` token still renders the media *and* the
  caution correctly if both occur.

### 2.5 (Optional, land separately) Ellipsis-aware fragment checking

Models legitimately elide inside a quote (`"… we left at dawn … and
never came back"`). Today that whole span fails. Refinement: if a
normalized span contains an internal elision marker (`...`, `[...]`),
split on it and require each fragment that clears the length floor to
appear in the haystack; fragments below the floor are ignored. Keep
this out of the first landing — it is a false-positive reduction, not a
correctness fix, and it is easier to judge after seeing real logs.

### Open question 2-A — where the function lives

**Recommendation: new `includes/quote_check.php`** (reasons in 2.1).
**Alternative:** append to `includes/knowledge.php` as the stashed draft
did — one fewer file and one fewer `require_once`, at the cost of
mixing concerns and making the pure functions harder to exercise in
isolation.

### Open question 2-B — the minimum length floor

**Recommendation: 25 characters.** The stashed draft used 12, which is
short enough that ordinary scare-quoted phrases and the app's own stock
phrases trip it. Real testimony quotes worth flagging are clauses, not
two-word fragments; a fabricated 15-character quote is also far less
damaging than a fabricated sentence. **Alternative:** 12–15 for higher
sensitivity, accepting more cautions (and more caution-fatigue, which
makes the signal worthless). Consider landing at 25, reading the server
logs for a week, and tuning — the value should be a named constant at
the top of `quote_check.php` so tuning is a one-line change.

### Open question 2-C — response shape: boolean or list

**Recommendation: return the list**, e.g.
`'unverifiedQuotes' => ['…', '…']` (capped at 3 spans, each truncated
to ~120 chars server-side). The UI can then name the specific phrase to
double-check, which is far more actionable than "something in here is
unverified," and the client just checks `.length`. The spans are the
model's own words, already displayed on screen, so this leaks nothing.
**Alternative:** the stashed draft's plain boolean — smaller payload,
simpler UI, but the reader has no idea which of five quoted lines to
check.

### Open question 2-D — should the user's own message be in the haystack

**Recommendation: no.** Keep the invariant crisp: a quote verifies only
if it appears in the material *the app* supplied (charter + archive).
Including user text would let a family member's own assertion
("didn't she say 'I never forgave them'?") launder itself into a
verified quote when the model echoes it back. **Accepted cost:** a reply
that quotes the user's question back while saying the record doesn't
contain it will draw a caution. That caution is mildly wrong but points
in the safe direction. **Alternative:** include the current turn's user
message (not the whole history) if that false-positive shape turns out
to be common in practice.

### Open question 2-E — markdown blockquotes

The Ask tab renders replies as plain text (`white-space:pre-wrap`, no
markdown), so quotes will normally be inline with quotation marks. If a
model starts emitting `> `-prefixed block quotes instead, they slip past
this check entirely. **Recommendation:** don't handle it now; note it in
the code comment as a known gap, and revisit if the logs show it.

---

# Feature 3 — Surface unreviewed status of AI-generated narrative notes

`narrative_analyze()` runs unattended on every upload and writes
`content_items.narrative_note` with no approval gate; that text then
flows into `knowledge_context()` for every future Ask-tab answer. The
goal here is **visibility**, not gating: make it obvious in
`/admin_content.php` which notes no human has ever looked at.

**Dependency:** 3.1 (schema + migration) must land and be applied to a
database before 3.2–3.4 can be tested end-to-end. The PHP in 3.2 should
still be written defensively (`?? null` on the new column) so that a
webroot deployed *before* `upgrade.sh` runs doesn't fatal — this is a
real window, since `deploy.sh` syncs code and then runs the upgrade.

### 3.1 Schema + migration

New column:

```sql
ALTER TABLE content_items
  ADD COLUMN narrative_note_reviewed_at DATETIME NULL AFTER narrative_note;
```

- `sql/schema.sql` — add it to the `CREATE TABLE content_items` block
  (currently line ~81-104) directly after `narrative_note`, with a
  comment in the style of the neighbouring `story_approved_at` comment:
  what NULL means, what sets it, and why it exists.
- `includes/migrations.php` — new version entry whose `db` closure does
  the `information_schema.columns` count check before the `ALTER TABLE`,
  copied from the `1.5.2` entry. `env` is `[]`.
- Do **not** backfill existing rows to `NOW()`. Every existing item will
  show as unreviewed after the upgrade, including ones whose notes an
  admin genuinely edited months ago — that is correct (we don't know)
  and honest. Call it out explicitly in the CHANGELOG entry so the live
  `slava.fintelfamily.com` deployment's burst of badges isn't a
  surprise.
- If 3.5's reviewer-identity option is taken, add
  `narrative_note_reviewed_by CHAR(36) NULL` in the same migration —
  decide before writing it, since adding a second column later means a
  second version entry.

### 3.2 Reset review state wherever an AI pass (re)writes a note

**This is the biggest gap in the stashed draft — it does not do this,
and the result is a note that claims to be reviewed but isn't.** Every
code path that writes AI-generated note text must clear
`narrative_note_reviewed_at` in the same `UPDATE`:

- `content_approve_story()` (`includes/content.php:459`) — currently
  `UPDATE content_items SET narrative_note = ?, story_approved_at = NOW()`.
  Failure case: an admin opens a *pending* story's edit row and saves
  (setting reviewed_at), then approves it; approval overwrites the note
  with fresh AI text while reviewed_at stays set.
- `content_backfill_narrative_notes()` (`includes/content.php:849`) —
  same shape. Failure case: an admin saves the edit form with the note
  field emptied (note → NULL, reviewed_at → set), and the backfill then
  fills in AI text on a row already marked reviewed.
- The `content_create_*()` INSERTs need no change — the column defaults
  to NULL, which is exactly right.

Add a one-line comment at each site tying it back to the schema comment,
so a future note-writing path copies the pattern.

### 3.3 Set review state, and expose it

- `content_public()` — add
  `'narrativeNoteReviewedAt' => $item['narrative_note_reviewed_at'] ?? null`.
  Keep the `?? null` and comment *why* (pre-migration deploy window).
- `content_update_item()` (`includes/content.php:727`) — set
  `narrative_note_reviewed_at = NOW()` on the note `UPDATE`. See open
  question 3-A for exactly when this should fire, which determines
  whether the signature and `api/admin/content_update.php` need a new
  parameter.

### 3.4 Admin UI

`js/admin_content.js`:
- Extract the narrative-note cell into a small
  `renderNarrativeNoteCell(item)` helper (replacing the inline ternary
  at line 221) that appends an `unreviewed` badge when the item has a
  note and no review timestamp.
- Add the same badge next to the "Narrative connection" label in
  `renderEditRow()` (line ~367), so it is visible in the place where you
  act on it.
- Keep everything HTML-escaped through the existing `esc()`.
- Optionally add the unreviewed count to the `#status` line
  (`"12 items — 3 notes unreviewed"`), which makes the state visible
  without scanning the table. Cheap; recommended.

`css/style.css`: reuse `.status-badge` + `.status-pending` (amber). See
open question 3-C.

`admin_content.php`: no markup change needed (the table is rendered by
JS), but the explanatory `<p class="meta">` under "Add content" is a
reasonable place for one sentence about what "unreviewed" means.

### 3.5 Test

Apply the schema over SSH first, then deploy code. With a throwaway
content item: confirm the badge appears on creation, disappears after
the review action, reappears after a backfill/approval rewrite (3.2),
and that `content_public()` doesn't fatal against a database where the
column is missing. Delete the throwaway item and any test rows.

### Open question 3-A — what counts as "reviewed"

**Recommendation: an explicit signal, not a bare form save.** Treat a
note as reviewed when either (a) the submitted note text *differs* from
what's stored — definitionally a human edit — or (b) the admin clicks an
explicit "Mark reviewed" control in the edit row. Implement (b) by
adding a boolean field to the existing `api/admin/content_update.php`
request body rather than creating a new endpoint. Rationale: saving the
edit form to fix a photo's GPS coordinates says nothing about whether
anyone read the note, and a "reviewed" flag that can be set without
reading is worse than no flag — it converts "unknown" into a false
"checked."

**Alternative (the stashed draft's, and materially less work):** any
save of the edit form clears it, changed or not. About 15 lines cheaper
and adds no UI. Defensible if the user prefers zero friction and treats
the flag as "an admin has been in here" rather than "an admin vouched
for this."

### Open question 3-B — does an *author's* save count as review?

`api/admin/content_update.php` is reachable by non-admin authors for
their own items (`require_content_api()`, `$ownerId` scoping). The
feature exists for **admin** oversight of AI text.
**Recommendation: only an admin's action clears the flag.** Today
`$ownerId === null` already means "caller is an admin" inside
`content_update_item()`, but relying on that implicitly is fragile —
pass an explicit reviewer id (or a bool) as a new trailing parameter
from the endpoint, which is also what makes the optional
`narrative_note_reviewed_by` column possible.
**Alternative:** any content-editor's save counts — simpler, and
arguably fine for a single-family deployment with one or two authors.

### Open question 3-C — reuse `.status-pending` or add a class

**Recommendation: reuse** `.status-badge status-pending` with the label
text `unreviewed`. It matches the existing visual language and costs no
CSS. **Concern worth weighing:** on this same page, amber "pending"
already means "blocked from publication until an admin acts" (stories,
suggestions), whereas an unreviewed note is *already live* in the
knowledge base. If that conflation bothers the user, add a
`.status-unreviewed` rule (e.g. a muted/neutral fill) so the two states
read differently.

### Open question 3-D — should unreviewed notes be marked in the AI context?

Currently `content_context()` emits `"\nNarrative connection: …"` with
no provenance marker, so the model treats an unreviewed AI note exactly
like human-curated archive text — the actual compounding mechanism.
A cheap, deterministic mitigation: when `narrative_note_reviewed_at IS
NULL`, label it in the context (e.g. `Narrative connection
(auto-generated, not yet reviewed by an archivist)`), so the model
weights it below testimony — the same technique the archive already uses
for family stories and dramatisations.
**Recommendation: do it, but as a separate, clearly-scoped sub-task
after 3.1–3.4 are live**, so the schema and the admin surface can be
verified without also changing Ask-tab behavior in the same step.
**Alternative:** leave the context untouched (this plan's default
non-goal) and rely on admin review alone.

---

# Deferred / non-goals

- **Blocking unreviewed notes from `knowledge_context()` entirely.**
  Would silently drop every existing note from the Ask tab's grounding
  on upgrade day and change answers with no warning. 3-D's labelling is
  the proportionate version.
- **LLM-based fact-checking of replies.** Adds a second probabilistic
  layer to solve a probabilistic problem; the whole point of feature 2
  is that the check is deterministic.
- **Persisting Ask-tab conversations / caution history.** Chat is
  in-memory client-side today; changing that is its own feature.
- **Citation verification** (checking that a cited source label is real).
  Plausible next step, out of scope here.

# Suggested implementation order

Ordering constraints are weak — the three features touch almost
disjoint files (`ai_provider.php` / `quote_check.php`+`chat.php`+`app.js`
/ `content.php`+`admin_content.js`). Within features, order matters.

1. **Feature 1** (1.1 → 1.2 → 1.3 → 1.4). Smallest, no schema, reduces
   variance while testing everything else.
2. **Feature 2** (2.1 → 2.2 → 2.3 → 2.4; 2.5 optional and later). 2.1
   is pure and testable alone; 2.2 depends on it; 2.3 depends on 2.2's
   response shape.
3. **Feature 3** (3.1 → 3.2 → 3.3 → 3.4 → 3.5; 3-D's labelling after).
   **3.1 must be applied to the database before 3.3/3.4 can be tested
   end-to-end** — apply the `ALTER TABLE` over SSH first, then deploy
   the code, per convention 6.

**Release bookkeeping:** each landing that reaches the live site needs
its own version bump + `migrations.php` entry + `CHANGELOG.md` section +
`README.md` update. Two reasonable groupings:

- **One release** (`1.11.0`) if all three land together — one changelog
  entry with all three bullets, one migration entry carrying the
  `ALTER TABLE`, one `AI_TEMPERATURE` line under "Environment changes."
- **Three releases** (`1.11.0` temperature, `1.12.0` quote verification,
  `1.13.0` review status) if they land separately, which fits the
  "one sub-task per session" goal better and keeps each changelog entry
  legible. Note that `migrations.php` needs an entry for **every**
  version in the sequence, even the two with `'db' => null`.

# Cross-cutting open questions

- **Release grouping** — one `1.11.0` or three separate minors? See
  above. Recommendation: three, if implemented in separate sessions.
- **Does the live archive want a one-time review sweep?** After feature
  3 ships, `slava.fintelfamily.com` will show every existing content
  item as unreviewed. Is that a to-do list the user intends to work
  through, or should the CHANGELOG simply explain it and let the badges
  age? Recommendation: treat it as a real to-do — that backlog is
  exactly the risk this feature exists to expose.
- **Should the Ask tab's caution wording name the app or the model?**
  This archive is a memorial; the phrasing should be plain and
  non-alarming (the family reads it). Worth one round of wording review
  with the user before shipping 2.3.
