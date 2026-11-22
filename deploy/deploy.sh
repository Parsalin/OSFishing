#!/usr/bin/env bash
#
# OSFishing — deploy from git.
# Run ON THE SERVER as the 'osfishing' user:
#
#   ssh osfishing@flamesfall.net 'bash /var/www/osfishing/repo/deploy/deploy.sh <branch>'
#
# Deploy whole branches, never individual files. Partial copies were a
# documented source of "server is running stale code" bugs on this project's
# sibling app.
#
# Order matters (audit item 47). OPcache revalidates file timestamps, so the
# moment rsync copies a file it is live; the FPM reload is not the switch.
# So everything that can fail runs BEFORE the sync, against the repo tree:
#   fetch -> syntax check -> migrations -> sync (goes live) -> cron -> reload.
# A failed check or migration leaves the old code serving, untouched.
# Migrations therefore run while the OLD code is still live: each one must
# leave the schema working for the old code too (add, don't rename/drop in
# the same deploy that stops using something).
#
set -euo pipefail

APP_ROOT="/var/www/osfishing"
REPO_DIR="${APP_ROOT}/repo"
WEB_SRC="${REPO_DIR}/web"
WEB_DST="${APP_ROOT}/web"

if [ "${1:-}" != "--after-fetch" ]; then
    BRANCH="${1:-main}"
    cd "${REPO_DIR}"
    git config --global --get-all safe.directory | grep -qxF "${REPO_DIR}" \
        || git config --global --add safe.directory "${REPO_DIR}"

    echo "==> Fetching ${BRANCH}"
    git fetch --all --prune
    git reset --hard "origin/${BRANCH}"
    # This file may just have changed under the running shell, which reads
    # scripts as it goes. Hand over to the fresh copy for everything else.
    exec bash "${REPO_DIR}/deploy/deploy.sh" --after-fetch "${BRANCH}"
fi
BRANCH="${2:-main}"
cd "${REPO_DIR}"
REV="$(git rev-parse --short HEAD)"
echo "    now at ${REV}: $(git log -1 --pretty=%s)"

if [ -f "${WEB_SRC}/composer.json" ]; then
    echo "==> Installing PHP dependencies"
    (cd "${WEB_SRC}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
fi

echo "==> Syntax check (repo tree, before anything goes live)"
for d in public includes bin migrations; do
    [ -d "${WEB_SRC}/${d}" ] || continue
    find "${WEB_SRC}/${d}" -name '*.php' -print0 \
        | xargs -0 -r -n1 -P4 php -l > /dev/null
done

# config.php is not in git; check the live one so a broken hand-edit is
# caught here rather than as a 500 on the live site.
if [ -f "${WEB_DST}/config.php" ]; then
    php -l "${WEB_DST}/config.php" > /dev/null
else
    echo "    ERROR: ${WEB_DST}/config.php is missing — the site cannot work." >&2
    exit 1
fi

# Migrations run from the repo tree (new migrate.php + new migrations) with
# the live config, while the old code is still serving. The symlink is
# gitignored, survives `git reset --hard`, and rsync excludes it.
echo "==> Applying migrations"
ln -sfn "${WEB_DST}/config.php" "${WEB_SRC}/config.php"
php "${WEB_SRC}/bin/migrate.php"

# config.php holds the DB credentials and HUD_SECRET. Without this exclude,
# --delete would remove the live config and take the site down.
echo "==> Syncing to ${WEB_DST} (live from here)"
rsync -a --delete \
    --exclude 'config.php' \
    --exclude 'storage/' \
    --exclude 'temp/' \
    --exclude '.git' \
    "${WEB_SRC}/" "${WEB_DST}/"

install -d -o osfishing -g osfishing -m 2775 "${APP_ROOT}/storage"

# Scheduled jobs live in the repo: this replaces the managed block of the
# osfishing crontab on every deploy and leaves any other lines alone.
# Host cron runs on America/New_York; the times below are local.
echo "==> Installing scheduled jobs"
install -d -m 700 "${HOME}/logs"
CRON_BLOCK="# BEGIN osfishing-managed (deploy/deploy.sh — edit there, not here)
23 3 * * * bash ${REPO_DIR}/deploy/backup.sh >> ${HOME}/logs/backup.log 2>&1
41 3 * * * php ${WEB_DST}/bin/sweep.php >> ${HOME}/logs/sweep.log 2>&1
*/10 * * * * php ${WEB_DST}/bin/sweep.php --buffs >> ${HOME}/logs/buffs.log 2>&1
# END osfishing-managed"
{ { crontab -l 2>/dev/null || true; } | sed '/^# BEGIN osfishing-managed/,/^# END osfishing-managed/d'; echo "${CRON_BLOCK}"; } | crontab -

# Drops cached copies of files the sync deleted or replaced.
echo "==> Reloading PHP-FPM (clears OPcache)"
sudo /usr/bin/systemctl reload php8.4-fpm

echo "==> Deployed ${REV}"
