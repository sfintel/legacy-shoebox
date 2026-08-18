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

[Unreleased]: https://github.com/sfintel/family-legacy-archive/compare/v1.13.0...HEAD
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
