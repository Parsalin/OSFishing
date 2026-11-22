#!/usr/bin/env bash
#
# OSFishing — nightly database dump.
# Runs from the osfishing crontab (installed by deploy.sh), as 'osfishing':
#
#   bash /var/www/osfishing/repo/deploy/backup.sh
#
# Writes osfishing-<UTC stamp>.sql.gz to BACKUP_DIR and keeps KEEP_DAYS days.
# /var/backups/osfishing is root-owned (not writable by this user), so the
# default is ~/backups; set BACKUP_DIR to override. This is an on-server copy
# only: it protects against bad migrations and mistakes, not a lost server.
#
set -euo pipefail

SECRETS="${HOME}/secrets/db.txt"
BACKUP_DIR="${BACKUP_DIR:-${HOME}/backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
OUT="${BACKUP_DIR}/osfishing-${STAMP}.sql.gz"

umask 077
mkdir -p "${BACKUP_DIR}"

# Any failure raises an admin alert (Alerts tab), not just a line in ~/logs.
ALERT_PHP="/var/www/osfishing/web/bin/alert.php"
on_fail() {
    php "${ALERT_PHP}" backup_failed "Nightly database backup failed at line $1 ($(date -u +%FT%TZ)). See ~/logs/backup.log on the server." || true
}
trap 'on_fail ${LINENO}' ERR

get() { grep -E "^$1=" "${SECRETS}" | head -n1 | cut -d= -f2-; }
DB_NAME="$(get DB_NAME)"
DB_USER="$(get DB_USER)"
DB_PASS="$(get DB_PASSWORD)"

# Credentials go in a private option file, never on the command line
# (where any user could read them from the process list).
CNF="$(mktemp)"
trap 'rm -f "${CNF}" "${OUT}.partial"' EXIT
printf '[client]\nuser=%s\npassword="%s"\n' "${DB_USER}" "${DB_PASS}" > "${CNF}"

# --single-transaction: a consistent dump without locking the live site.
mariadb-dump --defaults-extra-file="${CNF}" \
    --single-transaction --quick --routines --events \
    --default-character-set=utf8mb4 \
    "${DB_NAME}" | gzip -9 > "${OUT}.partial"

# Refuse an empty or truncated dump rather than rotating out good backups.
SIZE=$(stat -c%s "${OUT}.partial")
if [ "${SIZE}" -lt 10240 ] || ! zcat "${OUT}.partial" | tail -n1 | grep -q 'Dump completed'; then
    echo "$(date -u +%FT%TZ) ERROR: dump looks incomplete (${SIZE} bytes); keeping old backups" >&2
    php "${ALERT_PHP}" backup_failed "Nightly database backup looked incomplete (${SIZE} bytes); old backups kept." || true
    exit 1
fi
mv "${OUT}.partial" "${OUT}"

find "${BACKUP_DIR}" -name 'osfishing-*.sql.gz' -mtime "+${KEEP_DAYS}" -delete

echo "$(date -u +%FT%TZ) backup ok: ${OUT} (${SIZE} bytes)"
