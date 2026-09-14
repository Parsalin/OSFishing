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
set -euo pipefail

APP_ROOT="/var/www/osfishing"
REPO_DIR="${APP_ROOT}/repo"
WEB_SRC="${REPO_DIR}/web"
WEB_DST="${APP_ROOT}/web"
BRANCH="${1:-main}"

cd "${REPO_DIR}"

git config --global --get-all safe.directory | grep -qxF "${REPO_DIR}" \
    || git config --global --add safe.directory "${REPO_DIR}"

echo "==> Fetching ${BRANCH}"
git fetch --all --prune
git reset --hard "origin/${BRANCH}"
REV="$(git rev-parse --short HEAD)"
echo "    now at ${REV}: $(git log -1 --pretty=%s)"

if [ -f "${WEB_SRC}/composer.json" ]; then
    echo "==> Installing PHP dependencies"
    (cd "${WEB_SRC}" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
fi

# config.php holds the DB credentials and HUD_SECRET. It is gitignored, so it
# does not exist in the source tree — without this exclude, --delete would
# remove the live config and take the site down.
echo "==> Syncing to ${WEB_DST}"
rsync -a --delete \
    --exclude 'config.php' \
    --exclude 'storage/' \
    --exclude 'temp/' \
    --exclude '.git' \
    "${WEB_SRC}/" "${WEB_DST}/"

install -d -o osfishing -g osfishing -m 2775 "${APP_ROOT}/storage"

# Fail the deploy before bad PHP goes live, not after.
echo "==> Syntax check on deployed tree"
for d in public includes; do
    [ -d "${WEB_DST}/${d}" ] || continue
    find "${WEB_DST}/${d}" -name '*.php' -print0 \
        | xargs -0 -r -n1 -P4 php -l > /dev/null
done

# config.php is not in the synced tree; check it separately so a broken
# hand-edit is caught here rather than as a 500 on the live site.
if [ -f "${WEB_DST}/config.php" ]; then
    php -l "${WEB_DST}/config.php" > /dev/null
else
    echo "    WARNING: ${WEB_DST}/config.php is missing — the site will not work."
fi

echo "==> Reloading PHP-FPM (clears OPcache)"
sudo /usr/bin/systemctl reload php8.4-fpm

echo "==> Deployed ${REV}"
