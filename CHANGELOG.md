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

[Unreleased]: https://github.com/sfintel/family-legacy-archive/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/sfintel/family-legacy-archive/releases/tag/v1.0.0
