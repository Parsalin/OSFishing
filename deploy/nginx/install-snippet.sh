#!/usr/bin/env bash
#
# Install the OSFishing routing snippet from the repo checkout. Run as root:
#
#   sudo bash /var/www/osfishing/repo/deploy/nginx/install-snippet.sh
#
# Touches ONLY /etc/nginx/snippets/osfishing-limits.conf, never the vhost
# (certbot has rewritten the live vhost for TLS; the repo copies predate
# that). Backs up the old snippet, tests, and rolls back if nginx -t fails.
# Read this file before running it as root: the repo checkout is writable
# by the osfishing user.
#
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)/osfishing-limits.conf"
SNIP=/etc/nginx/snippets/osfishing-limits.conf
BAK="${SNIP}.bak.$(date +%Y%m%d-%H%M%S)"

[ "$(id -u)" -eq 0 ] || { echo "Run as root (sudo)." >&2; exit 1; }
[ -f "$SRC" ] || { echo "Missing $SRC" >&2; exit 1; }

echo "==> Changes:"
diff -u "$SNIP" "$SRC" || true

cp -a "$SNIP" "$BAK"
install -m 644 -o root -g root "$SRC" "$SNIP"

if ! nginx -t; then
    echo "==> nginx -t failed; restoring $BAK" >&2
    cp -a "$BAK" "$SNIP"
    exit 1
fi
systemctl reload nginx
echo "==> Installed and reloaded. Backup: $BAK"
