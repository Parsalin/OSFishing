#!/usr/bin/env bash
#
# Install the OSFishing nginx pieces from the repo checkout. Run as root:
#
#   sudo bash /var/www/osfishing/repo/deploy/nginx/install-snippet.sh
#
# Touches ONLY these two files, never the vhost (certbot has rewritten the
# live vhost for TLS; the repo copies predate that):
#   /etc/nginx/snippets/osfishing-limits.conf    (server context: routing,
#                                                 rate limit, headers)
#   /etc/nginx/conf.d/osfishing-ratelimit.conf   (http context: the zone)
# Backs up what was there, tests, and rolls back both if nginx -t fails.
# Read both files before running this as root: the repo checkout is
# writable by the osfishing user.
#
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
PAIRS=(
    "${DIR}/osfishing-limits.conf:/etc/nginx/snippets/osfishing-limits.conf"
    "${DIR}/osfishing-ratelimit.conf:/etc/nginx/conf.d/osfishing-ratelimit.conf"
)

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 1; }

restore() {
    for p in "${PAIRS[@]}"; do
        dst="${p#*:}"
        if [ -f "${dst}.bak.${STAMP}" ]; then cp -a "${dst}.bak.${STAMP}" "${dst}"; else rm -f "${dst}"; fi
    done
}

for p in "${PAIRS[@]}"; do
    src="${p%%:*}"; dst="${p#*:}"
    [ -f "${src}" ] || { echo "Missing ${src}" >&2; exit 1; }
    echo "==> ${dst}"
    if [ -f "${dst}" ]; then diff -u "${dst}" "${src}" || true; cp -a "${dst}" "${dst}.bak.${STAMP}"; else echo "    (new file)"; fi
    install -m 644 -o root -g root "${src}" "${dst}"
done

if ! nginx -t; then
    echo "==> nginx -t failed; restoring" >&2
    restore
    exit 1
fi
systemctl reload nginx
echo "==> Installed and reloaded. Backups: *.bak.${STAMP}"
