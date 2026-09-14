#!/usr/bin/env bash
# Wires up a new hostname to serve an EXISTING app-lamp-oss webroot as
# another subject on the same multi-subject install (see
# includes/subjects.php and CHANGELOG.md's 2.0.0 entry) — e.g. adding
# "dad.fintelfamily.com" alongside an already-running
# "slava.fintelfamily.com". Symlink-mirrors a cPanel-created docroot to
# point at the SAME codebase as the existing webroot, since cPanel
# refuses to let a new subdomain/addon domain reuse an existing
# document root directly — this sidesteps that restriction rather than
# fighting it.
#
# You MUST do these two steps in cPanel BEFORE running this script —
# neither can be scripted from here:
#
#   1. DNS: create an A/CNAME record for the new hostname, pointing at
#      this server's IP (the same one your existing subject already
#      resolves to).
#   2. cPanel > Domains > Create A New Domain: create the new hostname
#      as a subdomain or addon domain, letting cPanel pick its own
#      default document root — it WILL refuse to let you reuse an
#      existing one, so don't try. Just note the docroot path it
#      creates (e.g. /home/USER/www/dad.example.com); you'll pass that
#      as this script's second argument.
#
# After cPanel's AutoSSL issues a certificate for the new hostname
# (automatic, but can take a while — trigger it manually in cPanel if
# you don't want to wait), visit https://<new-hostname>/setup.php and
# follow the printed next-steps below.
#
# Usage: ./add_subject_host.sh /path/to/existing/webroot /path/to/new/cpanel-docroot
set -euo pipefail

SRC="${1:-}"
DST="${2:-}"
if [ -z "$SRC" ] || [ -z "$DST" ]; then
    echo "Usage: ./add_subject_host.sh /path/to/existing/webroot /path/to/new/cpanel-docroot" >&2
    exit 1
fi
if [ ! -f "$SRC/config.php" ]; then
    echo "$SRC doesn't look like an app-lamp-oss webroot (no config.php there)." >&2
    exit 1
fi
if [ ! -d "$DST" ]; then
    echo "$DST doesn't exist yet — create the subdomain in cPanel first (see this script's header comment)." >&2
    exit 1
fi

SRC="$(cd "$SRC" && pwd)"
DST="$(cd "$DST" && pwd)"
if [ "$SRC" = "$DST" ]; then
    echo "Source and target are the same directory — nothing to do." >&2
    exit 1
fi
if [ -e "$DST/config.php" ] && [ ! -L "$DST/config.php" ]; then
    echo "$DST/config.php already exists and isn't a symlink this script made — refusing to touch what looks like a real, separate site. Delete it by hand first if you're sure." >&2
    exit 1
fi

echo "== Clearing cPanel's placeholder content from $DST =="
# A freshly cPanel-created docroot only ever holds cPanel's own default
# index.php/php.ini/missing.html (and possibly an empty cgi-bin) — safe
# to clear unconditionally. shopt nullglob so an empty/no-match glob
# doesn't get passed through literally.
shopt -s nullglob dotglob
for item in "$DST"/*; do
    rm -rf "$item"
done
shopt -u nullglob dotglob

echo
echo "== Symlinking every item from $SRC into $DST =="
# Every top-level file/directory — including dotfiles like .env and
# .htaccess — becomes a symlink into the SAME underlying codebase. This
# is what makes the new hostname serve byte-identical content to every
# other subject on this install, including the shared .env (install-
# wide config every subject needs: DB credentials, SUBJECT_SETUP_SECRET,
# ARCHIVE_ROOT_BASE, AI defaults) — subject-specific data (uploads,
# backups, AI overrides) lives in the database and under
# ARCHIVE_ROOT_BASE/{slug}/, never in the webroot itself, so sharing
# every file here is exactly right, not a leak risk.
shopt -s dotglob
count=0
for item in "$SRC"/*; do
    name="$(basename "$item")"
    ln -sf "$item" "$DST/$name"
    count=$((count + 1))
done
shopt -u dotglob

echo
echo "== Done. $count items linked. =="
echo
echo "Next steps:"
echo "  1. Confirm DNS for the new hostname resolves, and that cPanel's"
echo "     AutoSSL has issued it a certificate (check cPanel > SSL/TLS"
echo "     Status, or trigger it manually) before visiting it over https://."
echo "  2. Visit https://<new-hostname>/setup.php — since this install"
echo "     already has at least one subject, it will ask for the setup"
echo "     secret. Find it with:"
echo "       grep SUBJECT_SETUP_SECRET $SRC/.env"
echo "     (or log in first as a master admin, if one exists, to skip"
echo "     the secret prompt entirely — see create_master_admin.php)."
echo "  3. Complete the wizard: site identity, admin account (with its"
echo "     own optional AI provider/key, independent of every other"
echo "     subject's), then finish. Its uploads will automatically live"
echo "     under a new ARCHIVE_ROOT_BASE/{slug}/ directory, fully"
echo "     separate from every other subject on this install."
