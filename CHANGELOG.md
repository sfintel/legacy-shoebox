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

[Unreleased]: https://github.com/sfintel/family-legacy-archive/compare/v1.5.3...HEAD
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
