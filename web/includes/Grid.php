<?php
/**
 * Grid.php - Multi-grid registry
 *
 * In-world scripts report the SIM HOSTNAME as grid_name
 * (e.g. "regions03-2.wa.darkheartsos.net:8002", from detectGrid()). One grid
 * runs many sim hosts, so a grid's identity is its BASE DOMAIN
 * ("darkheartsos.net") — see identityOf(). Every row is one sim host; rows
 * sharing an identity are one grid:
 *
 *  - Approval is per grid. A new host whose identity already has an approved
 *    (or denied) row inherits that decision, nickname and hop gateway, and the
 *    admins are told. Otherwise it arrives pending and the admins are told.
 *  - "Same grid" anywhere in the code means same identity, via sameGridSql().
 *    Never compare raw grid_name values: two regions of one grid on different
 *    sim hosts would look like different grids.
 *  - Shared hosting domains (outworldz.net) host unrelated grids, so there the
 *    full host is the identity. Bare IPs and dotless names are their own.
 *
 * Push delivery helpers live here too: no-HTTP-in per grid, a public push host
 * for grids whose sims report a private address, and the automatic push
 * address check (checkPushLater).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/AdminAlerts.php';

class Grid
{
    /**
     * Base domains that host many unrelated grids: a shared base domain says
     * nothing about identity there. (Fertility found shasiyadynasty.outworldz.net
     * auto-approved as "littlebigcity" because littlebigcity.outworldz.net was.)
     */
    const SHARED_HOSTING_DOMAINS = ['outworldz.net'];

    // ═══════════════════════════════════════
    // IDENTITY
    // ═══════════════════════════════════════

    /** Which grid a sim hostname belongs to. Idempotent: identityOf(identityOf(x)) === identityOf(x). */
    public static function identityOf(string $gridName): string {
        $host = strtolower(trim(explode(':', trim($gridName))[0]));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        $parts = explode('.', $host);
        if (count($parts) < 2) return $host;
        $base = implode('.', array_slice($parts, -2));
        return in_array($base, self::SHARED_HOSTING_DOMAINS, true) ? $host : $base;
    }

    /**
     * SQL condition: $column (a raw grid_name) is on the grid whose identity is
     * bound to $param. Hosts reach the grids table through findOrRegister, which
     * every write path that stores a grid_name calls.
     */
    public static function sameGridSql(string $column, string $param): string {
        return "{$column} IN (SELECT gx.grid_name FROM grids gx WHERE gx.grid_identity = {$param})";
    }

    // ═══════════════════════════════════════
    // REGISTRATION / APPROVAL
    // ═══════════════════════════════════════

    /**
     * Find this host's row, or create it — inheriting a sibling's decision when
     * the identity is already known, else pending. Alerts the admins either way.
     */
    public static function findOrRegister(string $gridName): ?array {
        $gridName = trim($gridName);
        if ($gridName === '') return null;
        // A sim hostname with an optional port, nothing else: unauthenticated
        // callers can name any grid, and each new one is a row plus a daily
        // pending alert (audit item 57).
        if (!preg_match('/^[A-Za-z0-9.-]{1,100}(:[0-9]{1,5})?$/', $gridName)) return null;
        $pdo = db();

        $row = self::findByName($gridName);
        if ($row) return $row;

        $identity = self::identityOf($gridName);
        $stmt = $pdo->prepare("
            SELECT status, nickname, hop_gateway, public_host, no_http_in
              FROM grids
             WHERE grid_identity = :gi AND status IN ('approved','denied')
             ORDER BY FIELD(status, 'approved', 'denied'), id
             LIMIT 1
        ");
        $stmt->execute([':gi' => $identity]);
        $sibling = $stmt->fetch();

        if ($sibling) {
            $pdo->prepare("
                INSERT IGNORE INTO grids (grid_name, grid_identity, nickname, hop_gateway, public_host, no_http_in, status, approved_at)
                VALUES (:gn, :gi, :nick, :hop, :ph, :nh, :st, IF(:st2 = 'approved', UTC_TIMESTAMP(), NULL))
            ")->execute([
                ':gn' => $gridName, ':gi' => $identity,
                ':nick' => $sibling['nickname'], ':hop' => $sibling['hop_gateway'],
                ':ph' => $sibling['public_host'], ':nh' => (int)$sibling['no_http_in'],
                ':st' => $sibling['status'], ':st2' => $sibling['status'],
            ]);
            $label = $sibling['nickname'] ?: $identity;
            AdminAlerts::send('grid_grouped',
                "New sim host {$gridName} joined grid {$label} ({$identity}) and inherited its status: {$sibling['status']}.",
                '/admin/?tab=grids', ['grid_name' => $gridName, 'identity' => $identity]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO grids (grid_name, grid_identity, status) VALUES (:gn, :gi, 'pending')")
                ->execute([':gn' => $gridName, ':gi' => $identity]);
            AdminAlerts::send('grid_pending',
                "A new grid is waiting for approval: {$identity} (first seen from {$gridName}). Nobody there can pair a HUD or set up spots until it is approved.",
                '/admin/?tab=grids', ['grid_name' => $gridName, 'identity' => $identity],
                'grid_pending:' . $identity, 24);
        }
        return self::findByName($gridName);
    }

    public static function findByName(string $gridName): ?array {
        $stmt = db()->prepare('SELECT * FROM grids WHERE grid_name = :gn');
        $stmt->execute([':gn' => $gridName]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Require the grid to be approved (registering it if unknown).
     * json_error()s on pending/denied unless $errorOnFail is false.
     */
    public static function requireApproved(string $gridName, bool $errorOnFail = true): ?array {
        $grid = self::findOrRegister($gridName);
        if (!$grid) {
            if ($errorOnFail) json_error('No grid name provided', 400);
            return null;
        }
        $label = $grid['nickname'] ?: $grid['grid_identity'];
        if ($grid['status'] === 'pending') {
            if ($errorOnFail) json_error("Grid '{$label}' is pending approval. Contact the admin.", 403);
            return null;
        }
        if ($grid['status'] === 'denied') {
            if ($errorOnFail) json_error("Grid '{$label}' is not authorized.", 403);
            return null;
        }
        return $grid;
    }

    /** Status without blocking — registers the host if new. */
    public static function checkGrid(string $gridName): array {
        $grid = self::findOrRegister($gridName);
        if (!$grid) return ['status' => 'unknown', 'message' => 'No grid name'];
        return [
            'status'   => $grid['status'],
            'identity' => $grid['grid_identity'],
            'nickname' => $grid['nickname'] ?: $grid['grid_identity'],
            'message'  => "Grid status: {$grid['status']}",
        ];
    }

    // ═══════════════════════════════════════
    // ADMIN
    // ═══════════════════════════════════════

    /** Every host row, with what uses it, for the admin Grids tab. */
    public static function listAll(): array {
        $rows = db()->query("
            SELECT g.*,
                   (SELECT COUNT(*) FROM fishing_spots fs WHERE fs.grid_name = g.grid_name AND fs.archived = 0) AS spot_count,
                   (SELECT COUNT(*) FROM hud_tokens ht WHERE ht.grid_name = g.grid_name AND ht.revoked_at IS NULL) AS token_count
              FROM grids g
             ORDER BY g.grid_identity, g.status, g.grid_name
        ")->fetchAll();
        foreach ($rows as &$r) {
            $host = explode(':', (string)$r['grid_name'])[0];
            $r['is_private'] = self::isPrivateHost($host) ? 1 : 0;
        }
        return ['grids' => $rows];
    }

    public static function approve(int $gridId): array {
        db()->prepare("UPDATE grids SET status = 'approved', approved_at = UTC_TIMESTAMP() WHERE id = :id")
            ->execute([':id' => $gridId]);
        return ['message' => 'Grid approved'];
    }

    public static function deny(int $gridId): array {
        db()->prepare("UPDATE grids SET status = 'denied' WHERE id = :id")->execute([':id' => $gridId]);
        return ['message' => 'Grid denied'];
    }

    /** Apply one status to every host of a grid. */
    public static function setStatusForIdentity(string $identity, string $status): array {
        if (!in_array($status, ['approved', 'denied', 'pending'], true)) json_error('Bad status');
        db()->prepare("
            UPDATE grids SET status = :s,
                   approved_at = IF(:s2 = 'approved', COALESCE(approved_at, UTC_TIMESTAMP()), approved_at)
             WHERE grid_identity = :gi
        ")->execute([':s' => $status, ':s2' => $status, ':gi' => $identity]);
        return ['message' => "All hosts of {$identity} set to {$status}"];
    }

    /**
     * Set one setting on every sim host of a grid, so the admin edits a grid
     * once instead of per region server. New hosts inherit it on arrival.
     */
    public static function setForIdentity(string $identity, string $field, string $value): array {
        $identity = trim($identity);
        if ($identity === '') json_error('No grid given');
        $v = trim($value);
        if ($field === 'hop_gateway') {
            $v = rtrim(preg_replace('#^(hop|https?)://#i', '', $v), '/');
        } elseif ($field === 'public_host') {
            $v = strtolower($v);
            if ($v !== '' && strpos($v, '://') !== false) $v = (string)(parse_url($v, PHP_URL_HOST) ?? '');
            $v = explode(':', explode('/', $v)[0])[0];
            if ($v !== '' && !preg_match('/^[a-z0-9.-]+$/', $v)) json_error('That is not a host name');
        } elseif (!in_array($field, ['nickname', 'notes'], true)) {
            json_error('Unknown grid setting');
        }
        $stmt = db()->prepare("UPDATE grids SET `{$field}` = :v WHERE grid_identity = :gi");
        $stmt->execute([':v' => $v === '' ? null : $v, ':gi' => $identity]);
        return ['message' => 'Saved for all ' . $stmt->rowCount() . ' sim hosts of ' . $identity];
    }

    public static function clearNoHttpInForIdentity(string $identity): array {
        db()->prepare('UPDATE grids SET no_http_in = 0 WHERE grid_identity = :gi')->execute([':gi' => trim($identity)]);
        return ['message' => 'No HTTP-in flag cleared'];
    }

    public static function setNickname(int $gridId, string $nickname): array {
        $n = trim($nickname);
        db()->prepare('UPDATE grids SET nickname = :n WHERE id = :id')->execute([':n' => $n === '' ? null : $n, ':id' => $gridId]);
        return ['message' => 'Nickname updated'];
    }

    /** Rename every row carrying a nickname (case-insensitive). */
    public static function renameNickname(string $old, string $new): array {
        $new = trim($new);
        db()->prepare('UPDATE grids SET nickname = :new WHERE LOWER(nickname) = LOWER(:old)')
            ->execute([':new' => $new === '' ? null : $new, ':old' => trim($old)]);
        return ['message' => 'Nickname renamed'];
    }

    public static function setHopGateway(int $gridId, string $gateway): array {
        $g = trim($gateway);
        $g = preg_replace('#^(hop|https?)://#i', '', $g);
        $g = rtrim($g, '/');
        db()->prepare('UPDATE grids SET hop_gateway = :g WHERE id = :id')->execute([':g' => $g === '' ? null : $g, ':id' => $gridId]);
        return ['message' => 'Hop gateway updated'];
    }

    /**
     * The public host to push to instead of the sim host, for a grid whose
     * sims report a private address (ExternalHostName set to a LAN IP).
     * Accepts "host", "host:port" or a URL; keeps only the host name.
     */
    public static function setPublicHost(int $gridId, string $publicHost): array {
        $h = strtolower(trim($publicHost));
        if ($h !== '' && strpos($h, '://') !== false) $h = (string)(parse_url($h, PHP_URL_HOST) ?? '');
        $h = explode(':', explode('/', $h)[0])[0];
        if ($h !== '' && !preg_match('/^[a-z0-9.-]+$/', $h)) json_error('That is not a host name');
        db()->prepare('UPDATE grids SET public_host = :h WHERE id = :id')->execute([':h' => $h === '' ? null : $h, ':id' => $gridId]);
        return ['message' => $h === '' ? 'Public push host cleared' : "Pushes will go to {$h}"];
    }

    public static function setNotes(int $gridId, string $notes): array {
        $n = trim($notes);
        db()->prepare('UPDATE grids SET notes = :n WHERE id = :id')->execute([':n' => $n === '' ? null : $n, ':id' => $gridId]);
        return ['message' => 'Notes saved'];
    }

    /** Admin "Clear": the grid owner fixed HTTP-in; the next denial alerts again. */
    public static function clearNoHttpIn(int $gridId): array {
        db()->prepare('UPDATE grids SET no_http_in = 0 WHERE id = :id')->execute([':id' => $gridId]);
        return ['message' => 'No HTTP-in flag cleared'];
    }

    public static function deleteGrid(int $gridId): array {
        db()->prepare('DELETE FROM grids WHERE id = :id')->execute([':id' => $gridId]);
        return ['message' => 'Grid deleted'];
    }

    /** One entry per approved grid (identity), for the map selector and home grid. */
    public static function listApproved(): array {
        $rows = db()->query("
            SELECT grid_identity AS grid_name,
                   MAX(nickname) AS nickname,
                   MAX(hop_gateway) AS hop_gateway
              FROM grids
             WHERE status = 'approved'
             GROUP BY grid_identity
             ORDER BY COALESCE(MAX(nickname), grid_identity)
        ")->fetchAll();
        return ['grids' => $rows];
    }

    /** Hop gateway for a grid identity (any host row that has one). */
    public static function hopGatewayFor(string $identity): ?string {
        $stmt = db()->prepare("SELECT hop_gateway FROM grids WHERE grid_identity = :gi AND hop_gateway IS NOT NULL AND hop_gateway <> '' LIMIT 1");
        $stmt->execute([':gi' => $identity]);
        return $stmt->fetchColumn() ?: null;
    }

    // ═══════════════════════════════════════
    // PUSH DELIVERY
    // ═══════════════════════════════════════

    /**
     * A HUD on this grid reported that llRequestURL() is denied. The first
     * report per grid alerts the admins (ask the grid owner to enable HTTP-in,
     * then Clear the badge). Returns true when the flag was newly set.
     */
    public static function markNoHttpIn(string $gridName, string $who = '', string $region = ''): bool {
        $grid = self::findOrRegister($gridName);
        if (!$grid) return false;
        $stmt = db()->prepare('UPDATE grids SET no_http_in = 1 WHERE id = :id AND no_http_in = 0');
        $stmt->execute([':id' => $grid['id']]);
        if ($stmt->rowCount() === 0) return false;
        $label = $grid['nickname'] ?: $grid['grid_identity'];
        AdminAlerts::send('grid_no_http_in',
            "Grid {$label} ({$gridName}) denies HTTP-in" . ($who !== '' ? ", reported by {$who}" : '') . ($region !== '' ? " in {$region}" : '')
            . ". The server can't push to HUDs there; they collect queued messages on their next status check instead. Ask the grid owner to enable HTTP-in, then Clear the badge.",
            '/admin/?tab=grids', ['grid_name' => $gridName]);
        return true;
    }

    /** sim host (no port) => public host, for every row that has one. Cached per request. */
    public static function publicHostMap(): array {
        static $map = null;
        if ($map !== null) return $map;
        $map = [];
        try {
            foreach (db()->query("SELECT grid_name, public_host FROM grids WHERE public_host IS NOT NULL AND public_host <> ''") as $r) {
                $host = strtolower(explode(':', trim((string)$r['grid_name']))[0]);
                if ($host !== '') $map[$host] = strtolower((string)$r['public_host']);
            }
        } catch (Throwable $e) {
            error_log('Grid::publicHostMap: ' . $e->getMessage());
        }
        return $map;
    }

    /** A push URL with its host swapped for the grid's public host, if one is set. */
    public static function rewritePushUrl(string $url): string {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $map = self::publicHostMap();
        if ($host === '' || !isset($map[$host])) return $url;
        return self::withHost($url, $map[$host]);
    }

    public static function isPrivateHost(string $host): bool {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost') return true;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return strpos($host, '.') === false;   // dotless LAN names
    }

    /**
     * After the reply is sent, check that the server can reach this grid's
     * push address (at most once a day per grid, more often while broken).
     * Call when a HUD or prim registers a callback URL.
     */
    public static function checkPushLater(string $gridName, string $callbackUrl): void {
        if (trim($gridName) === '' || trim($callbackUrl) === '') return;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        register_shutdown_function(static function () use ($gridName, $callbackUrl, $ip) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try {
                self::checkPush($gridName, $callbackUrl, $ip);
            } catch (Throwable $e) {
                error_log('Grid::checkPush failed: ' . $e->getMessage());
            }
        });
    }

    private static function checkPush(string $gridName, string $callbackUrl, string $remoteAddr): void {
        require_once __DIR__ . '/PushQueue.php';
        $grid = self::findByName($gridName);
        if (!$grid) return;

        $urlHost  = strtolower((string)parse_url($callbackUrl, PHP_URL_HOST));
        $gridHost = strtolower(explode(':', (string)$grid['grid_name'])[0]);
        $private  = self::isPrivateHost($urlHost);
        $interval = ($private && empty($grid['public_host'])) ? 600 : (!empty($grid['push_problem']) ? 21600 : 86400);

        $claim = db()->prepare('
            UPDATE grids SET push_checked_at = UTC_TIMESTAMP()
             WHERE id = :id AND (push_checked_at IS NULL OR push_checked_at < UTC_TIMESTAMP() - INTERVAL :s SECOND)
        ');
        $claim->execute([':id' => $grid['id'], ':s' => $interval]);
        if ($claim->rowCount() === 0) return;

        // A payload every in-world script ignores (no handler for it) but answers 200.
        $ping = ['type' => 'ping', 'event' => 'ping'];
        $label = $grid['nickname'] ?: $grid['grid_identity'];
        $problem = null;

        if ($private) {
            $candidates = [];
            if (!empty($grid['public_host'])) $candidates[] = strtolower($grid['public_host']);
            if ($urlHost === $gridHost) {
                if ($remoteAddr !== '' && !self::isPrivateHost($remoteAddr)) $candidates[] = $remoteAddr;
                if (!empty($grid['nickname'])) {
                    $s = db()->prepare("SELECT grid_name FROM grids WHERE LOWER(nickname) = LOWER(:n) AND id <> :id AND status <> 'denied'");
                    $s->execute([':n' => $grid['nickname'], ':id' => $grid['id']]);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $gn) $candidates[] = strtolower(explode(':', $gn)[0]);
                }
                if (!empty($grid['hop_gateway'])) $candidates[] = strtolower(explode(':', $grid['hop_gateway'])[0]);
            }
            $found = null;
            foreach (array_unique(array_filter($candidates)) as $cand) {
                if (self::isPrivateHost($cand)) continue;
                if (PushQueue::postRaw(self::withHost($callbackUrl, $cand), $ping)['ok']) { $found = $cand; break; }
            }
            if ($found !== null) {
                if ($found !== strtolower((string)$grid['public_host'])) {
                    db()->prepare('UPDATE grids SET public_host = :h WHERE id = :id')->execute([':h' => $found, ':id' => $grid['id']]);
                    AdminAlerts::send('grid_push_host',
                        "Grid {$label} ({$gridName}) reports a private push address ({$urlHost}). The server found it answers on {$found} and will push there. The real fix is the grid owner setting ExternalHostName.",
                        '/admin/?tab=grids', ['grid_name' => $gridName, 'public_host' => $found]);
                }
            } else {
                $problem = 'private';
            }
        } else {
            // Only "can't connect" counts: any HTTP answer, even 404, means reachable.
            $res = PushQueue::postRaw(self::rewritePushUrl($callbackUrl), $ping);
            if ((int)$res['code'] === 0) $problem = 'unreachable';
        }

        if ($problem !== ($grid['push_problem'] ?: null)) {
            db()->prepare('UPDATE grids SET push_problem = :p WHERE id = :id')->execute([':p' => $problem, ':id' => $grid['id']]);
            if ($problem !== null) {
                $why = $problem === 'private'
                    ? "its sims report a private push address ({$urlHost}) and no public host answered. Set a public push host on the Grids tab, and ask the grid owner to set ExternalHostName."
                    : "the server can't connect to the push address HUDs there report (likely a firewall on the grid's HTTP-in ports).";
                AdminAlerts::send('grid_push_problem',
                    "Pushes can't reach grid {$label} ({$gridName}): {$why} Messages wait for each HUD's next check-in meanwhile.",
                    '/admin/?tab=grids', ['grid_name' => $gridName, 'problem' => $problem]);
            }
        }
    }

    private static function withHost(string $url, string $host): string {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) return $url;
        return ($p['scheme'] ?? 'http') . '://' . $host
            . (isset($p['port']) ? ':' . $p['port'] : '')
            . ($p['path'] ?? '')
            . (isset($p['query']) ? '?' . $p['query'] : '');
    }
}
