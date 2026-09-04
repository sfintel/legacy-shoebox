# Family Legacy Archive — LAMP Edition

A self-contained PHP/MySQL app for preserving and querying a family
member's testimony and legacy materials — built to run on plain shared or
traditional web hosting (cPanel, Plesk, or anywhere that gives you PHP +
MySQL but no Node, Docker, or shell access). Core features: per-user login
with admin approval, an AI-grounded Ask tab, a Browse tab (timeline,
people, places, quotes, full transcript, source notes), an installable
PWA, and a full admin-facing archive editor.

No Composer, no npm, no build step. Every dependency (SMTP client, JSON
handling, UUIDs) is hand-rolled in plain PHP so there's nothing to install
beyond what a standard PHP 8+ / MySQL host already provides — with one
deliberate exception: passkey login vendors a small, dependency-free
WebAuthn library (see "Passkey login" below) rather than hand-rolling
signature verification, since that's genuine account-takeover risk to
get wrong, not just a display bug. The database is the single source of
truth for the entire archive — there's no YAML/JSON file layer to keep
in sync, and nothing to regenerate after an edit.

Already running a deployment and upgrading to a newer release? If you
keep a git clone of this repo, `./deploy.sh /path/to/webroot` does the
whole cycle in one command. Otherwise, deploy the new code yourself and
run `./upgrade.sh` from inside your webroot. See [UPGRADE.md](UPGRADE.md)
for details rather than starting over from this README, and check
[CHANGELOG.md](CHANGELOG.md) for what changed release
to release.

## What you need from your host

- PHP 8.0+ with the `pdo_mysql`, `openssl`, `curl`, and `zip` (`ZipArchive`)
  extensions — all four are on by default on essentially every shared host.
- A MySQL or MariaDB database (cPanel: *MySQL Databases*).
- Apache with `.htaccess` support (`AllowOverride All` — standard on shared
  hosting; this app does **not** need `mod_rewrite`, only `mod_authz_core`
  for the `Require all denied` blocks, which is universal).
- Outgoing SMTP — usually a mailbox on your own domain (cPanel: *Email
  Accounts*), or any SMTP provider's credentials. Optional — see step 3.

## 1. Create the database

In cPanel: *MySQL Databases* → create a database and a user, add the user
to the database with **All Privileges**. Note the three values it gives you
(host is almost always `localhost`).

Then import the schema — either cPanel's *phpMyAdmin* (Import tab, pick
`sql/schema.sql`), or from a terminal if your host gives you one:

```bash
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < sql/schema.sql
```

This creates the full set of tables the app needs — accounts and
permissions (`users`, `consumed_tokens`, `rate_limits`), the core archive
(`site_settings`, `audience_modes`, `people`, `places`, `timeline_entries`,
`quotes`, `primary_testimony`, `discrepancy_notes`), family-contributed
material (`content_items`, `content_files`, `content_suggestions`), the
app-wide keyword list (`keywords`), and the name-redaction registry
(`redacted_names`). See `sql/schema.sql` itself for
what each one is for — every table has a comment explaining its role.

## 2. Upload the files

The contents of this folder **are your web root** — upload all of it (or
a subfolder your domain/subdomain points at) via cPanel's File Manager
(zip it first, upload, then *Extract*) or FTP/SFTP.

## 3. Run the setup wizard

Visit your site. You'll land on `/setup.php` automatically — nothing else
is reachable until it's finished. It walks through four resumable stages:

1. **Database** — enter the host/name/user/password from step 1. This
   writes `.env` (including a random `SESSION_SECRET`) and imports
   `sql/schema.sql` for you — no manual `.env` editing or SQL import
   needed.
2. **Archive identity** — the archive's name, the subject's name and
   pronouns, birth details, and a short bio, plus the default audience
   categories (e.g. young children vs. researchers). Or skip straight to
   "Load example archive instead" to see a small fictional archive filled
   in, if you just want to explore the app first.
3. **Admin account** — your name/email/password, plus (optional) an AI
   provider and API key. Choose Anthropic or OpenAI (the OpenAI option
   also works with any OpenAI-compatible endpoint — Groq, DeepSeek,
   OpenRouter, Azure OpenAI, a local Ollama/LM Studio server, etc. — via
   its base-URL field). Without a key, Browse still works but Ask errors
   and new content is saved without an AI note/suggestions; you can add
   one later by hand-editing `.env` (see `.env.example`). Every AI call
   runs at a low sampling temperature by default (see "Ask tab accuracy
   safeguards" below) — tune or disable it via `AI_TEMPERATURE`.
4. **Advanced settings** — public URL, outgoing SMTP, rate limits. Fully
   skippable; sensible defaults are used and everything here can be
   changed later by hand in `.env`.

Once finished you're logged in automatically, and `/setup.php` refuses to
run again — reach it a second time and it just redirects to `/login.php`.

`.htaccess` blocks direct web access to `.env` and `config.php`, but on
hosts where you can set file permissions, it doesn't hurt to make `.env`
`600` as well.

## 4. Revisit your archive's identity anytime

`/admin_settings.php`'s **Site identity** tab holds everything the wizard
asked in stage 2, editable anytime — this drives page titles, the About
tab, the login page, the installable-app manifest, outgoing email subject
lines, and — most importantly — the Ask tab's AI prompt, which is built
entirely from these fields. The same page's **Sources** tab manages your
citation list (primary interview, plus any additional sources, each
optionally flagged as a dramatization), and its **Audience categories**
tab manages the Ask tab's "Telling for:" options — add, edit, reorder,
delete, or change the default. Each category needs a short note telling
the AI how to adjust its answers for that audience; that can't be inferred
automatically, so writing it is part of adding a category.

## Building out the archive

`/admin_archive.php` (admin-only) is where the core archive itself gets
built: People, Places, Timeline, Quotes, Testimony, and Discrepancy/source
notes, each with add/edit/delete — People, Places, Timeline, and Quotes
also support reordering (up/down arrows). Everything here is what the
Ask tab is grounded in and what the Browse tab displays — there's no
separate file format to hand-edit or keep in sync.

**Note on the subject's own People-tab entry**: `admin_settings.php`'s
"Date of passing" field (site identity — feeds the About tab and the Ask
tab's system prompt) and the subject-role person's own `fate` field here
in People are separate columns in different tables, kept in sync
one-directionally: setting/changing the date of passing overwrites that
person's `fate` to "Survived. Died {date}." If you've written a fuller
fate for them than that, re-apply it after changing the date — this is
a real overwrite, not a merge (there's no reliable way to merge into
free text automatically).

The **Testimony** tab holds one primary recorded interview, if there is
one: paste its transcript using `## Tape 1` for each session/tape break
and `**SUBJECT:**` / `**INTERVIEWER:**` to mark speaker turns.

The **Discrepancy notes** tab is freeform curator's notes — e.g. where two
sources disagree on a name or date — supporting basic `#`/`##` headings,
`**bold**`, `` `code` ``, and paragraphs (not full Markdown).

## Accounts and permissions

1. New family members visit `/signup.php` ("Request access" link on the
   login page) and submit name, email, a password they choose, and which
   of the Ask tab's "Telling for:" audience categories answers should
   default to for them — they can still change it per-question later.
2. You get an email at `NOTIFY_EMAIL` with Approve/Reject links (skipped if
   `SMTP_HOST` is blank — check `/admin.php` instead, which always shows
   pending requests).
3. Clicking a link opens a confirmation page — nothing happens until you
   click again, so email security scanners that prefetch links can't
   accidentally auto-approve someone.
4. On approval, they get an email confirming they can log in with the
   email/password they already chose.

`/admin.php` (an "Admin" link appears in the header for admins only) lists
everyone with their role, chosen audience category ("Telling for"), when
they last logged in, and Approve/Reject/Revoke/Delete actions, plus
Unlock for a locked-out account (see "Login lockout"). Admin accounts
don't show Revoke/Delete — both the buttons and the underlying API
reject those actions against an admin row, so there's no way to lock
everyone out of user management by mistake. A search box filters the
list by name/email/role/status client-side, once it grows past a
glance.

Every account has one of three roles:

- **admin** — full access, including user management.
- **author** — can add content (transcripts/photos/videos/URLs, via
  `/admin_content.php`) and edit/delete their own; everything else a
  reader can do.
- **reader** — can view the archive and use the Ask tab; can't add
  content.

New signups start as readers; an admin promotes/demotes between author
and reader from a user's row on `/admin.php` ("Make author"/"Make
reader"), or promotes straight to admin ("Make admin", with a
confirmation given how much access that grants). Admin accounts aren't
changeable through this page at all afterward — same protection as
Revoke/Delete, and there's deliberately no "Make author"/"Make reader"
demotion path for an existing admin either, to rule out ever locking
everyone out of user management by mistake.

### Login lockout

After `LOGIN_ATTEMPT_LIMIT` (default 5) failed attempts within
`LOGIN_LOCKOUT_SECONDS` (default 900 = 15 min), further attempts are
blocked. Tracked two ways independently — by the email address being
attempted, and by the request's IP — so a lockout on one doesn't depend
on the other: e.g. someone repeatedly mistyping their password locks out
just that email; a flood of attempts against many different email
addresses from one place locks out that IP regardless of which addresses
were tried. Unlock an account from `/admin.php`'s Unlock button, which
only clears that account's email-side counter — an IP-side lockout isn't
tied to one account and just expires on its own after the window.

A correct password against a not-yet-approved account doesn't count
against this limit — it gets a distinct "still awaiting approval"
message instead of the generic wrong-password error, so a new user
trying to log in before you've approved them can't lock themselves out.

### Changing your password

Every account (reader, author, or admin) can change its own password
from `/account.php` ("Account" link in the header once signed in) —
enter the current password plus a new one (8+ characters).

If the password is forgotten entirely, use "Forgot password?" on the
sign-in page (`/forgot_password.php`) — it emails a reset link to the
account's address, valid for 1 hour and usable once. Requests are
rate-limited per IP (`FORGOT_PASSWORD_RATE_LIMIT`, default 5/hour) and
the response is identical whether or not the email has an account, so
the endpoint can't be used to test which addresses are registered.
There's no admin override to force-reset someone else's password; the
only way into an account without email access is a passkey (if one's
registered) or direct database access.

### Passkey login

Admin and author accounts can additionally sign in with a passkey
(fingerprint, face, screen lock, or a security key) — always alongside
password login, never replacing it. Register one at `/account.php`
(linked from the main app header once signed in), then use "Sign in
with a passkey" on the login page. A user can register several (one per
device) and remove any of them at any time from that same page; removing
a passkey never touches the account's password.

Built on a vendored copy of [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn)
(`includes/webauthn/`, MIT-licensed — see `NOTICE.md` in that directory)
rather than hand-rolled, since WebAuthn's signature/attestation
verification is genuine security-critical code. `includes/webauthn_helper.php`
is this project's own thin wrapper around it, translating between the
library's shapes and this app's session/database conventions.
`webauthn_credentials` is a new table (one row per registered device);
nothing else about the `users` table or password login changes.

## Content management

`/admin_content.php` (linked from the header for anyone with content
access — admins and authors) lets family members
add material beyond the core archive built via `/admin_archive.php`:
a video or audio recording (with its transcript, either or both), a
Document, photos, a URL, or a Story. Everything added here becomes part
of what the Ask tab knows about — except a pending Story, see below.

- **Video/Audio + Transcript**: one combined add-content type covering a
  recording and its transcript together — give a title, pick Video or
  Audio as the "Kind," and provide the recording (file or URL), the
  transcript text, or both. Providing both in one submission creates and
  links them automatically (see "Linking a recording to its transcript"
  below); providing just one is equally valid — e.g. a transcript alone
  when you don't have the recording file yet, or a recording alone when
  no transcript exists — and the two can still be linked later from
  either item's Edit row. EXIF-style capture metadata (date, device, GPS,
  duration) is auto-extracted from a video/audio file via `exiftool`,
  editable afterward if the host doesn't have `exiftool` or the
  auto-read value is wrong. Instead of a local file, you can give a
  **URL to download from** — the server fetches it directly, which
  sidesteps browser upload size/timeout limits for a large file (see
  "Uploading large files" below). Give exactly one of a file or a URL,
  not both, for whichever one you're providing.
- **Photos**: title + optional caption; EXIF metadata is auto-extracted
  the same way. A photo "album" (up to 10 files sharing one
  title/caption) is analyzed together as a set. Instead of a local
  file, you can give a **URL to download from**, same as above; a URL
  always produces a single file (no multi-photo album from a URL).
- **Uploading large files**: PHP's own `post_max_size` and
  `upload_max_filesize` limits (commonly a shared host's small default,
  e.g. 20M) apply to a browser upload through the file picker — a large
  video may need these raised in your host's PHP settings before a
  direct upload works. The "download from URL" option above isn't
  subject to either limit at all (it's an outgoing fetch from the
  server, not an incoming upload), so it's the simplest way around a
  host that won't raise them. Either way, a large transfer can also hit
  PHP-FPM's `request_terminate_timeout` if it takes longer than that to
  complete — raise it alongside the size limits if your host exposes it.
- **Transcripts, URLs, and Stories** all run two AI passes: a short
  narrative-connection note (same as photos/videos/audio get) and a separate
  structured pass that proposes new timeline/people/places/quotes
  entries grounded in that material — treating a family-submitted
  transcript or story the same way a URL source already was. Proposals
  never duplicate something already in the archive and are never applied
  automatically: they sit as pending suggestions under that content item
  until an admin approves or dismisses each one individually from the
  item's "Suggestions" panel. Approving inserts the entry straight into
  the core archive (the same tables `/admin_archive.php` manages) — no
  separate file format involved, and it appears in the Ask tab and
  Browse tab immediately. Each suggestion's citation traces back to the
  specific content item it came from (its title, plus the URL for a URL
  source), and its "source note" says which of the three it came from —
  a family-recounted story's proposals stay distinguishable from a
  first-person transcript's, matching the "reliable but secondhand"
  distinction the Ask tab already draws elsewhere.
- **Story**: a story family members tell about the subject — distinct
  from their own testimony, so it's held pending until an admin approves
  it (a badge + Approve button appear on the item's row). Only once
  approved does it appear on the public "Stories" tab, get included in
  the Ask tab's knowledge base (explicitly weighted below the primary
  testimony transcript — "recounted by family — reliable, but
  secondhand"), and get its narrative-connection note and suggestion
  proposals computed. Nothing about a pending story is computed or
  visible anywhere until it's approved. When an author (not an admin)
  submits one, the admin gets an email — same `NOTIFY_EMAIL` setup as
  the signup-request notification — linking straight to
  `admin_content.php` to review it.
- "Backfill AI analysis" (admin-only button) sweeps existing content for
  anything the passes above haven't run on yet — a missing narrative
  note (any type except a still-pending Story), or missing suggestions
  (Transcript, or an already-approved Story) — covering items added
  before this feature existed, added before suggestion-extraction
  covered their type, or where the original AI call failed. Safe to run
  repeatedly: it only ever fills in what's missing, never re-runs
  analysis on an item that already has it. It also links any unlinked
  video-or-audio/transcript pair whose titles match exactly (see below).
- **Linking a recording to its transcript**: a video or audio item can
  be paired with the transcript of that same interview. For a **video**
  specifically, this also lets an Ask-tab reply that quotes the
  transcript offer to jump the video to that moment (see below) — an
  audio recording can be linked and displayed the same way in the admin
  Content list, but doesn't yet get that same seek-to-moment behavior in
  Ask-tab replies. Pairing happens automatically when a recording and a
  transcript share the exact same title (case-insensitive) and neither
  is already linked — no AI involved, just an exact match (submitting
  both together via the combined Video/Audio + Transcript type above
  always triggers this, since both get the same title). Edit either
  item's row and use the "Linked transcript"/"Linked recording" dropdown
  to link, relink, or unlink them by hand when titles don't match or the
  automatic pairing missed it — a transcript's dropdown lists both video
  and audio candidates, each labeled with its kind. A transcript can
  additionally use a per-segment-timecode format
  instead of the `## Tape N` convention above — one block per speaker
  turn, a `HH:MM:SS:FF - HH:MM:SS:FF` timecode line, then the speaker's
  name, then their spoken text:
  ```
  00:00:53:21 - 00:01:09:15
  Jane Doe
  The spoken text for this segment goes here...

  00:01:09:16 - 00:01:24:02
  Interviewer
  And the next turn's text...
  ```
  Both transcript formats are auto-detected and supported side by side.
  When an Ask-tab reply directly quotes a passage from a timecoded
  transcript linked to a video, the `[[video:ID]]` reference it shows
  opens seeked to ~5 seconds before that passage instead of at 0:00 —
  the app never trusts the model to state or compute the timecode itself,
  it's always resolved server-side from the transcript's own parsed data.
- Every "Narrative connection" note is unattended AI output with no
  approval gate — it starts feeding the Ask tab's knowledge base the
  moment it's written. An `unreviewed` badge (list and edit views) marks
  any note no admin has looked at yet; it's visibility only, not a gate.
  An admin edit that changes the note text clears the badge automatically
  (a human edit is definitionally a review); a "Mark reviewed" checkbox
  in the edit row — pre-checked whenever the note is already reviewed,
  so it always reflects current status rather than starting blank —
  clears the badge without changing the text when checked, or restores
  it if unchecked on a note that was previously marked reviewed. The
  badge reappears if a later AI pass (approval, backfill) rewrites the
  note.
- A non-admin content contributor only sees and can edit/delete their own
  items (and, for items with suggestions, only sees their own item's
  suggestions, read-only — approving/dismissing is admin-only since it
  mutates the shared archive).

## Ask tab accuracy safeguards

The Ask tab is generative — an AI model handed the whole archive plus a
charter (see "Building out the archive") and asked to answer in prose —
which is a different risk profile from the deterministic Browse tab. A
few narrow, mostly-deterministic safeguards:

- **Low sampling temperature.** Every AI call (Ask tab, narrative notes,
  URL-suggestion extraction) runs at `AI_TEMPERATURE` (default `0.2`)
  rather than the provider's own default — these are recall/citation
  tasks, not creative writing, so a low temperature reduces paraphrase
  drift and quote invention. Set it to the literal word `default` to omit
  the parameter entirely and let the provider decide, which some models
  require (they reject any other temperature with an HTTP 400 —
  seen in practice on an Anthropic account, not just OpenAI-shaped
  backends as originally assumed). **If the Ask tab starts erroring right
  after upgrading**, check the PHP error log for `ai_http_post: AI
  provider error (HTTP 400)` mentioning `temperature` and set
  `AI_TEMPERATURE=default`.
- **Quote verification.** The charter tells the model never to invent a
  direct quote — this adds a deterministic check on top of that
  instruction rather than trusting it alone. Every reply is scanned for
  double-quoted spans (25+ characters after normalization) and each is
  checked against the exact material the model was given (the charter
  plus the archive knowledge base). A span that can't be matched
  word-for-word doesn't get rewritten or blocked — the reply is shown
  exactly as generated, with a small caution appended naming the
  unmatched wording, so the reader knows to check it against the
  original testimony. A caution is often a normalization miss (a
  reworded phrase in quotes), not necessarily a fabrication.

## Name redaction

`/admin_redactions.php` (admin-only) maintains a list of names to strip
from everything shown to family members — the Ask tab (both its context
and the model's replies) and the Browse tabs' JSON. Add a name and any
exact, word-boundary match of it is replaced with "[name withheld]"
wherever the archive is served. This is a **serve-time filter only** — it
never modifies the underlying archive data, so removing a name from the
list immediately un-redacts it. Add variants (nicknames, alternate
spellings) as separate entries to cover all of them. Admin-facing views
(Content management, the archive editor, the redaction list itself) are
never redacted, since the admin needs full visibility to manage the
archive.

## Backup & Restore

`/admin_backup.php` (admin-only) creates a single downloadable .zip
containing a full database dump and every file under `ARCHIVE_ROOT/
uploads/`. Backups are pure PHP (PDO for the dump, the `ZipArchive`
extension for packaging) — no `mysqldump` or `shell_exec` dependency, so
it works on hosts that don't allow either. Backups are stored under
`ARCHIVE_ROOT/backups/`, outside the webroot like `uploads/` already is,
and are only ever served through an authenticated download endpoint —
never a direct URL.

Restoring from a backup **replaces the entire database and uploads
directory** with what's in that .zip — it's a full point-in-time
restore, not a merge, and requires typing a confirmation phrase before
the button becomes clickable. Use it to undo a bad upgrade or other
mistake, not as a way to apply a single schema change (see
[UPGRADE.md](UPGRADE.md) for why).

### Retention, scheduling, and storage location

Three things are configurable in `.env` (see `.env.example`'s "Backup &
Restore" section) — not on `/admin_backup.php` itself, same as SMTP and
rate-limit settings, since these are infrastructure config rather than
archive content:

- **`BACKUP_RETENTION_COUNT`** (default 14) — the oldest backups are
  deleted automatically once there are more than this, whether they were
  made by hand or automatically. Applied every time a backup is created,
  and also available on demand via the "Apply retention now" button on
  `/admin_backup.php` (useful the first time you set a lower count, to
  clean up an existing pile without waiting for the next backup). Set to
  `0` to keep everything and never prune.
- **`BACKUP_AUTO_INTERVAL_HOURS`** (default `0`, meaning off) — how often
  an automatic backup should run. Setting this alone does nothing by
  itself: this app has no long-running process of its own (same shared-
  hosting assumption as everywhere else in this README), so automatic
  backups need a host cron job to actually trigger them. Point one at
  `cron_backup.php`:
  - **Preferred**: cPanel's *Cron Jobs* running the command
    `php /home/you/public_html/cron_backup.php` (adjust the path) on a
    short, frequent schedule (e.g. hourly) — each run is a no-op unless a
    backup is actually due, so scheduling it more often than you want
    backups is harmless. A command-line cron job isn't reachable over
    HTTP, so no secret/token is needed for this route.
  - **If your host only offers "cron via URL"** (wget/curl pinging a URL
    on a schedule, no shell command option): set `BACKUP_CRON_SECRET` in
    `.env` to a random string, then point cron at
    `https://yoursite.example/cron_backup.php?token=that-string`. Without
    a configured secret, `cron_backup.php` refuses every HTTP request —
    it creates backups and touches disk, so it's never a publicly
    reachable no-auth endpoint by default.
- **`BACKUP_DIR`** (optional) — where backup .zip files are stored.
  Defaults to `ARCHIVE_ROOT/backups`, outside the webroot like `uploads/`
  already is; only set this if you specifically want backups on a
  different disk/mount (e.g. more free space than `ARCHIVE_ROOT`'s
  volume has), and keep it outside the webroot the same way.

### Moving to a new host

A backup is a valid way to move the whole archive to a different
host — including one with a completely different database server or
credentials. `database.sql` inside the .zip is a full rebuild (`DROP
TABLE IF EXISTS` + `CREATE TABLE` + `INSERT` for every table), and
restoring just runs that through whatever DB connection the *new*
host's own `.env` already points at — it never reads or restores
`DB_HOST`/`DB_USER`/`DB_PASS` itself, since connection details are
never included in the backup in the first place. To migrate:

1. Deploy the codebase to the new host with its own fresh `.env`
   pointing at its own database (an empty database is fine).
2. Run the setup wizard once, just to get a working admin login — it
   only creates one admin account and a site-settings row, no real
   content.
3. Log in and restore the old host's backup from `/admin_backup.php`.
   This replaces that throwaway admin (and everything else) with the
   real data from the backup.

The one thing a restore never carries over is `.env` itself — secrets
are deliberately excluded from the backup, so `SESSION_SECRET`,
`AI_API_KEY`, SMTP credentials, etc. need to be set up fresh on the
new host regardless of how the database migration goes.

## Security notes

- `.htaccess` files deny direct access to `.env`, `config.php`, and
  everything under `includes/` and `sql/` — only the PHP entry points and
  static assets (`css/`, `js/`, `icons/`) are web-reachable.
- Session cookies are marked `Secure` automatically when the request comes
  in over HTTPS (checked per-request — no separate "behind a proxy" flag
  needed, since there's no reverse proxy in front of this deployment by
  default).
- Email approve/reject links are signed, expire after 7 days, and are
  single-use — enforced via a unique key in `consumed_tokens`, so even two
  simultaneous clicks on the same link can only succeed once.
- Use HTTPS. Most hosts offer a free Let's Encrypt certificate through
  cPanel (*SSL/TLS Status* → *Run AutoSSL*) — turn it on before sending
  anyone a login link.
- This was built for a small trusted family group, not as a hardened
  multi-tenant auth system — the login/approval flow keeps casual visitors
  out and gives you an audit trail of who has access, nothing more.

## A note on how changes get verified

This project is typically edited without a local PHP/MySQL environment —
changes are staged against a real (but disposable) test database and
verified there directly (schema changes applied over SSH before deploying
dependent code, features exercised end-to-end with throwaway test
accounts/content, always cleaned up afterward) rather than trusted by
inspection alone. If you're extending this app yourself without a local
PHP setup, the same approach — a separate test database, throwaway test
data, verification via direct DB/API inspection — is the practical way to
do it.
