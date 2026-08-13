#!/usr/bin/env bash
# Pulls the latest code into THIS clone, syncs it into a target webroot,
# then runs that webroot's upgrade.sh. Run this from inside a git clone
# kept outside any webroot (see UPGRADE.md for why) — never point git
# itself at a webroot directory.
#
# Usage: ./deploy.sh /path/to/webroot
set -euo pipefail

TARGET="${1:-}"
if [ -z "$TARGET" ]; then
    echo "Usage: ./deploy.sh /path/to/webroot" >&2
    exit 1
fi
if [ ! -f "$TARGET/config.php" ]; then
    echo "$TARGET doesn't look like an app-lamp-oss webroot (no config.php there)." >&2
    exit 1
fi
if ! command -v rsync >/dev/null 2>&1; then
    echo "rsync not found on PATH — needed to sync files into $TARGET." >&2
    exit 1
fi

cd "$(dirname "${BASH_SOURCE[0]}")"

echo "== Pulling latest code =="
git pull

echo
echo "== Syncing into $TARGET =="
# --delete removes files in the target that no longer exist in this
# release (e.g. a retired page) — safe here because the webroot should
# be 100% code; user data (uploads, backups) always lives under
# ARCHIVE_ROOT, outside the webroot, and .env/.git are excluded below.
#
# Deliberately NOT plain `-a`: on hosts where the webroot itself is
# owned by root (typical for a cPanel subdomain docroot, group-writable
# to the site user) rsync can update file *contents* fine but can't set
# attributes on the top-level target directory it doesn't own — tried
# --omit-dir-times alone first, still failed on permissions next. So:
# skip preserving times/perms/owner/group entirely (none of it matters
# for a code deploy — PHP doesn't care about file mtimes, and a new
# file still gets the source's permission bits, e.g. upgrade.sh's +x,
# via rsync's normal "new file" behavior even without --perms) and use
# checksums (-c) instead of the mtime+size quick-check, since skipping
# --times means source mtimes can't be trusted for change detection.
rsync -rlc --delete --no-times --no-perms --no-owner --no-group \
    --exclude '.git' \
    --exclude '.env' \
    "./" "$TARGET/"

echo
echo "== Running upgrade.sh in $TARGET =="
(cd "$TARGET" && ./upgrade.sh)
