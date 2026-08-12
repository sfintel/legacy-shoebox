#!/usr/bin/env bash
# Applies every pending database/environment change between whatever
# version this deployment is currently running and the version of the
# code you just deployed (read from VERSION). Run this AFTER deploying
# new code (git pull, rsync, scp — whatever you use) and BEFORE
# considering the upgrade done. See UPGRADE.md for the full procedure
# this automates; this script covers steps 3-4 of that document (the
# actual work happens in upgrade.php — this is just the entry point).
#
# Safe to re-run: each version step only applies once (tracked in
# site_settings.schema_version) and a fresh backup is taken automatically
# before anything changes.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if [ ! -f config.php ] || [ ! -f VERSION ]; then
    echo "This doesn't look like an app-lamp-oss webroot (no config.php/VERSION here)." >&2
    echo "Run this from inside the deployed webroot, after syncing the new code." >&2
    exit 1
fi

if [ ! -f .env ]; then
    echo ".env not found — nothing to connect to the database with. Aborting." >&2
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "php CLI not found on PATH. Aborting." >&2
    exit 1
fi

TARGET_VERSION="$(tr -d '[:space:]' < VERSION)"
echo "app-lamp-oss upgrade runner — target version: $TARGET_VERSION"
echo "This will back up the database + uploads automatically before changing anything."
echo

php upgrade.php
STATUS=$?

echo
if [ $STATUS -eq 0 ]; then
    echo "Done. Now verify the site:"
    echo "  - Visit the homepage while logged out — should show the login page, not a PHP error."
    echo "  - Log in and check the Ask tab, each Browse tab, and the admin pages."
    echo "  - Check your host's PHP error log for anything unexpected."
    echo "  - If any '.env changes still required' were printed above, apply them now."
else
    echo "Upgrade did not finish — see the error above. The automatic backup taken at the" >&2
    echo "start of this run is available in /admin_backup.php (or ARCHIVE_ROOT/backups/)" >&2
    echo "if you need to roll back." >&2
fi

exit $STATUS
