# Upgrading an existing deployment

This covers moving a deployment that's already running (real users, real
archive content) from an older release to a newer one. If you're setting
up a brand-new deployment instead, you don't need this — just follow
`README.md`.

## The easiest way: `deploy.sh` (if you're deploying from a git clone)

As of 1.3.0, if you keep a git clone of this repo on your host — outside
any webroot, since a webroot itself must never be a git working tree
(see the note under "Deploy the new code" below for why) — one command
does the whole cycle:

```bash
cd /path/to/your/clone
./deploy.sh /path/to/your/webroot
```

That's `git pull`, then `rsync -a --delete` (excluding `.git` and
`.env`) into the target webroot, then that webroot's `upgrade.sh` — in
one go. Safe to run repeatedly for the same reason `upgrade.sh` is: if
there's nothing new, `git pull` says so and `upgrade.sh` exits
immediately after.

If you don't keep a git clone (you deploy via scp, a host file manager,
etc.), use `upgrade.sh` directly instead — see below.

## The easy way: `upgrade.sh`

As of 1.2.0, this repo carries its own upgrade runner. Two steps:

```bash
# 1. Deploy the new code (see "Deploy the new code" below for what to
#    exclude). This brings in the new VERSION file and any new steps in
#    includes/migrations.php along with everything else.

# 2. From inside the deployed webroot:
./upgrade.sh
```

`upgrade.sh` compares the version it finds recorded in the database
against the `VERSION` file that just got deployed, takes an automatic
backup (the same engine behind `/admin_backup.php`), then applies every
step in between via `upgrade.php` + `includes/migrations.php` — in
order, one version at a time. It's safe to re-run: each version's step
only applies once, and if you're already caught up it just says so and
exits. If a step fails partway through a multi-version jump, everything
before the failure is already recorded as applied — fix the problem (or
restore the backup it took at the start) and re-run; it picks up where
it left off rather than redoing earlier versions.

It does **not** deploy code for you (there are too many ways people
sync a webroot — git, rsync, scp, a host's file manager — for one script
to cover) and it can't safely rewrite `.env` for you (it holds real
secrets it has no way to guess). If a version needs an `.env` change,
`upgrade.sh` prints exactly what's needed at the end, for you to add by
hand.

If your deployment predates 1.2.0 (no `upgrade.sh` in your webroot yet),
use the manual procedure below for this one upgrade — `upgrade.sh`
itself detects that it's the first version-tracked run and treats you as
starting from the 1.0.0 baseline, so once you're on 1.2.0 or later,
every upgrade after that can use it.

## The manual way

### The one thing to understand first

`sql/schema.sql` uses `CREATE TABLE IF NOT EXISTS` for every table. That
makes it **always safe to re-run in full** — it will never touch, alter,
or drop a table that already exists. But that also means it does
**nothing** for a release that adds a new column to a table you already
have (e.g. `content_items` gaining a `tags` column in a later release).
Re-running `schema.sql` alone will silently skip that change, and the
new feature that depends on it will fail with a MySQL "unknown column"
error the first time it's used. (This is exactly the gap `upgrade.sh`
exists to close.)

So: `schema.sql` handles brand-new tables automatically. Everything
else — new columns, renamed `.env` variables, one-time data fixes — is
called out explicitly in [CHANGELOG.md](CHANGELOG.md) under **Database
changes** / **Environment changes** for the specific release that
introduced it, and you have to apply those by hand, in order, for every
release between the one you're on and the one you're upgrading to.

### 0. Back up everything first

If your deployment is already on 1.1.0 or later, it has the Backup &
Restore admin page (`/admin_backup.php`) — just click "Create backup
now" there — it bundles the database and every uploaded file into one
.zip you can download. That's the easiest way to get a restore point.

If you're upgrading from a release that predates that page (or want a
backup outside the app itself), do it by hand instead:

```bash
mysqldump -u YOUR_DB_USER -p YOUR_DB_NAME > backup_$(date +%Y%m%d).sql
tar czf webroot_backup_$(date +%Y%m%d).tar.gz /path/to/your/webroot
tar czf uploads_backup_$(date +%Y%m%d).tar.gz /path/to/ARCHIVE_ROOT/uploads
```

Keep the backup somewhere outside the webroot until you're confident the
upgrade went cleanly — a week is a reasonable minimum. Note that the
admin page's "Restore" is a full replace (database + uploads both), not
a way to apply a single new column — see the note above about why
`schema.sql` alone can't do that either. Use it only to undo a bad
upgrade, then still apply steps 3–4 by hand.

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
`app-lamp-oss/.htaccess` only denies `.env*`, `config.php`, and
`upgrade.php` — it does not block arbitrary leftover files, so don't
count on it to make a stray file invisible.)

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

Restore from the step-0 backup. If it was made with `/admin_backup.php`,
upload that .zip back through the "Restore from backup" form there —
it replaces the database and uploads directory in one step (the webroot
code itself isn't part of that backup, so also put the old webroot files
back by hand). If it was made with `mysqldump`/`tar` instead, put the
old webroot files back and restore the database from the dump
(`mysql -u USER -p DBNAME < backup_*.sql`). Either way, you're back to
exactly where you started — then figure out what went wrong before
trying again.
