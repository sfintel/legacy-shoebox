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

[Unreleased]: https://github.com/sfintel/family-legacy-archive/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.3.1
[1.3.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.3.0
[1.2.1]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.2.1
[1.2.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.2.0
[1.1.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.1.0
[1.0.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.0.0
