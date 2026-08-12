# Upgrading an existing deployment

This covers moving a deployment that's already running (real users, real
archive content) from an older release to a newer one. If you're setting
up a brand-new deployment instead, you don't need this — just follow
`README.md`.

## The one thing to understand first

`sql/schema.sql` uses `CREATE TABLE IF NOT EXISTS` for every table. That
makes it **always safe to re-run in full** — it will never touch, alter,
or drop a table that already exists. But that also means it does
**nothing** for a release that adds a new column to a table you already
have (e.g. `content_items` gaining a `tags` column in a later release).
Re-running `schema.sql` alone will silently skip that change, and the
new feature that depends on it will fail with a MySQL "unknown column"
error the first time it's used.

So: `schema.sql` handles brand-new tables automatically. Everything
else — new columns, renamed `.env` variables, one-time data fixes — is
called out explicitly in [CHANGELOG.md](CHANGELOG.md) under **Database
changes** / **Environment changes** for the specific release that
introduced it, and you have to apply those by hand, in order, for every
release between the one you're on and the one you're upgrading to.

## Procedure

### 0. Back up everything first

Before touching anything:

```bash
mysqldump -u YOUR_DB_USER -p YOUR_DB_NAME > backup_$(date +%Y%m%d).sql
tar czf webroot_backup_$(date +%Y%m%d).tar.gz /path/to/your/webroot
tar czf uploads_backup_$(date +%Y%m%d).tar.gz /path/to/ARCHIVE_ROOT/uploads
```

Keep all three somewhere outside the webroot until you're confident the
upgrade went cleanly — a week is a reasonable minimum.

### 1. Read CHANGELOG.md for every version between yours and the target

If you don't know what version you're currently running, check your
deployment's own git history (`git log` in the webroot, if you deployed
via git) or just assume the worst and read every entry — re-running an
`ALTER TABLE` for a column you already have is a no-op error you can
safely ignore, but *missing* one isn't safe to ignore.

Make a plain list of every SQL statement and every `.env` change called
out along the way before you start step 2 — you want all of it in front
of you, not discovered one broken page at a time.

### 2. Deploy the new code

Sync every file from the new release into your webroot, with two
exceptions:

- **Never overwrite `.env`** — it holds your real secrets and won't
  exist in a fresh git checkout anyway, so a normal file copy naturally
  leaves it alone.
- **Never touch your `ARCHIVE_ROOT`/uploads directory** — it's outside
  the webroot by design and no release should ever need to touch it.

If you're syncing with something that only adds/overwrites files (like
plain `scp` or `cp -r`), also check for files the new release *removed*
that are still sitting in your webroot from the old one — they're
harmless in most cases, but worth a quick look. (This project's own
`app-lamp-oss/.htaccess` only denies `.env*` and `config.php` — it does
not block arbitrary leftover files, so don't count on it to make a stray
file invisible.)

### 3. Apply the database changes you listed in step 1

Run `sql/schema.sql` in full first (safe, additive-only, per the note
above). Then apply every `ALTER TABLE` / data-fix statement you
collected from CHANGELOG.md, in release order.

### 4. Apply the `.env` changes you listed in step 1

Add or rename whichever variables that version's changelog entry calls
for. Don't regenerate `.env` from scratch — hand-edit just the specific
lines, so everything else (your real API key, SMTP credentials, session
secret) stays untouched.

### 5. Verify

- Visit the homepage while logged out — should redirect to `/login.php`,
  not show a PHP error.
- Log in and check the Ask tab, each Browse tab, and (if you're an
  admin) `/admin_archive.php` and `/admin_content.php`.
- Check your host's PHP error log for anything unexpected right after
  the upgrade.
- If the release touched AI-related code, send one real Ask-tab message
  to confirm the configured provider still responds.

### If something's wrong

Restore from the step-0 backup: put the old webroot files back, restore
the database from the dump (`mysql -u USER -p DBNAME < backup_*.sql`),
and you're back to exactly where you started. Then figure out what went
wrong before trying again.
