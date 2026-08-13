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
rsync -a --delete \
    --exclude '.git' \
    --exclude '.env' \
    "./" "$TARGET/"

echo
echo "== Running upgrade.sh in $TARGET =="
(cd "$TARGET" && ./upgrade.sh)
