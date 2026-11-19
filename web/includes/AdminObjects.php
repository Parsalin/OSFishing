<?php
/**
 * Admin-approved in-world objects (admin_objects). Ported from Fertility.
 *
 * Pairing: on reset the object calls aobj_pair_request (its owner must be an
 * admin avatar) and opens /admin/?tab=objects&approve=<code> for the owner.
 * An admin approves it there. The object polls aobj_pair_status; the first
 * poll after approval collects its token — once. Only sha256(token) is kept.
 * The token lives only in the script's memory; a reset or re-rez forgets it.
 *
 * Every authenticated request must match ALL of: the token; the object key
 * the sim stamps (X-SecondLife-Object-Key); the owner (uuid, checked against
 * X-SecondLife-Owner-Key) who must still be an admin; the internet address it
 * was approved from (REMOTE_ADDR — never a forwarded header, there is no
 * proxy); its grid; and the per-type action list. Wrong object, owner or
 * admin status revokes at once. A new address pauses it until an admin
 * accepts the address. Why the address: OpenSim saves script memory inside a
 * taken copy and a grid owner can read it from their database; headers are
 * just headers. The connecting address is the one thing a stolen token can't
 * bring along.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Grid.php';
require_once __DIR__ . '/AdminAlerts.php';

class AdminObjects
{
    const TYPES = ['recovery' => 'Account Recovery terminal'];

    /** What each type may do once paired. Anything else is refused and reported. */
    const ALLOWED = ['recovery' => ['aobj_hello', 'aobj_recovery']];

    const PAIR_TTL          = 900;   // seconds to approve a setup request
    const RECOVERY_COOLDOWN = 600;   // seconds between recovery links per account
    const RECOVERY_LINK_TTL = 900;   // lifetime of a recovery link, and of recovery mode

    // ── Pairing ──────────────────────────────────────────────

    public static function pairRequest(string $owner, string $objectKey, string $ip, array $data): array {
        $type = strtolower(trim((string)($data['type'] ?? '')));
        if (!isset(self::TYPES[$type])) return self::fail('unknown_type');
        if (!self::isUuid($objectKey)) return self::fail('not_from_a_sim');
        if (!self::isAdminAvatar($owner)) {
            return self::fail('not_admin', 'The owner of this object is not an admin on the OSFishing website (link this avatar to your admin account first).');
        }

        $gridHost = trim((string)($data['grid_name'] ?? ''));
        $code = bin2hex(random_bytes(16));
        $pdo = db();
        $pdo->prepare('
            INSERT INTO admin_objects (object_type, object_key, object_name, owner_uuid, status, pair_code, pair_expires_at,
                                       source_ip, grid_host, grid_identity, region, position)
            VALUES (:t, :k, :n, :o, "pending", :c, UTC_TIMESTAMP() + INTERVAL :ttl SECOND, :ip, :gh, :gi, :r, :p)
        ')->execute([
            ':t' => $type, ':k' => $objectKey, ':n' => mb_substr(trim((string)($data['name'] ?? '')), 0, 128),
            ':o' => $owner, ':c' => $code, ':ttl' => self::PAIR_TTL, ':ip' => $ip,
            ':gh' => $gridHost, ':gi' => Grid::identityOf($gridHost),
            ':r' => self::region($data), ':p' => mb_substr(trim((string)($data['position'] ?? '')), 0, 64),
        ]);
        $id = (int)$pdo->lastInsertId();
        self::log($id, 'pair_request', 'ok', 'Setup started', $ip);

        return ['ok' => true, 'code' => $code, 'url' => self::baseUrl() . '/admin/?tab=objects&approve=' . $code];
    }

    /** Polled while waiting for approval. Hands out the token once. */
    public static function pairStatus(string $owner, string $objectKey, string $ip, array $data): array {
        $row = self::findByPairCode(strtolower(trim((string)($data['code'] ?? ''))));
        // Only the object that asked, from where it asked, may collect the token.
        if (!$row || $row['object_key'] !== $objectKey || strcasecmp($row['owner_uuid'], $owner) !== 0 || $row['source_ip'] !== $ip) {
            return ['ok' => true, 'status' => 'expired'];
        }
        if ($row['status'] === 'pending') {
            return ['ok' => true, 'status' => self::pairCodeLive($row) ? 'pending' : 'expired'];
        }
        if ($row['status'] !== 'active') return ['ok' => true, 'status' => 'rejected'];
        if ((int)$row['token_issued'] === 1) return ['ok' => true, 'status' => 'expired'];

        $token = bin2hex(random_bytes(32));
        $stmt = db()->prepare('UPDATE admin_objects SET token_hash = :h, token_issued = 1, pair_code = NULL, last_seen_at = UTC_TIMESTAMP()
                                WHERE id = :id AND token_issued = 0');
        $stmt->execute([':h' => hash('sha256', $token), ':id' => $row['id']]);
        if ($stmt->rowCount() === 0) return ['ok' => true, 'status' => 'expired'];
        self::log((int)$row['id'], 'paired', 'ok', 'Token collected', $ip);
        return ['ok' => true, 'status' => 'active', 'token' => $token];
    }

    /** A pending, unexpired object for the approval card. */
    public static function pendingForCode(string $code): ?array {
        $row = self::findByPairCode(strtolower(trim($code)));
        if (!$row || $row['status'] !== 'pending' || !self::pairCodeLive($row)) return null;
        return self::decorate($row);
    }

    public static function approve(int $id, array $admin): array {
        $row = self::find($id);
        if (!$row || $row['status'] !== 'pending' || !self::pairCodeLive($row)) json_error('That setup request has expired. Reset the object to try again.', 410);
        db()->prepare('UPDATE admin_objects SET status = "active", approved_at = UTC_TIMESTAMP(), approved_by = :a WHERE id = :id')
            ->execute([':a' => $admin['id'], ':id' => $id]);
        // A re-paired object (its script was reset) replaces its old entry.
        $old = db()->prepare('SELECT id FROM admin_objects WHERE object_key = :k AND status = "active" AND id <> :id');
        $old->execute([':k' => $row['object_key'], ':id' => $id]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            self::revoke((int)$oid, 'Replaced: the object was set up again');
        }
        self::log($id, 'approved', 'ok', 'Approved by ' . self::adminName($admin), '');
        return ['message' => 'Approved. The object will finish setting itself up within a few seconds.'];
    }

    public static function reject(int $id, array $admin): array {
        db()->prepare('UPDATE admin_objects SET status = "rejected", pair_code = NULL WHERE id = :id AND status = "pending"')->execute([':id' => $id]);
        self::log($id, 'rejected', 'ok', 'Setup rejected by ' . self::adminName($admin), '');
        return ['message' => 'Rejected'];
    }

    // ── Authenticated requests ───────────────────────────────

    /** @return array{0: array, 1: int} reply and HTTP status */
    public static function handle(string $action, string $owner, string $objectKey, string $ip, string $token, array $data): array {
        $row = $token !== '' ? self::findByTokenHash(hash('sha256', strtolower(trim($token)))) : null;
        if (!$row) return [self::fail('unknown_token'), 401];
        $id = (int)$row['id'];
        $name = self::displayName($row);
        if ($row['status'] !== 'active') return [self::fail('revoked'), 401];

        // The token is bound to one object. Anything else holding it means it
        // was copied out — cut it off at once.
        if ($objectKey === '' || $objectKey !== $row['object_key']) {
            return self::revokeHostile($row, $objectKey === ''
                ? 'its token was used from outside a sim (no object key)'
                : "its token was used by a different object ({$objectKey})", $ip, $action);
        }
        if (strcasecmp($owner, $row['owner_uuid']) !== 0) {
            return self::revokeHostile($row, "its token was used with a different owner ({$owner})", $ip, $action);
        }
        if (!self::isAdminAvatar($owner)) {
            return self::revokeHostile($row, 'its owner is no longer an admin', $ip, $action);
        }

        if ($ip !== $row['source_ip']) {
            self::log($id, $action, 'refused', "Request from a new address {$ip} (approved from {$row['source_ip']})", $ip);
            $stmt = db()->prepare('UPDATE admin_objects SET pending_ip = :ip WHERE id = :id AND (pending_ip IS NULL OR pending_ip <> :ip2)');
            $stmt->execute([':ip' => $ip, ':ip2' => $ip, ':id' => $id]);
            if ($stmt->rowCount() > 0) {
                self::alert("{$name} is calling from a new internet address ({$ip}, was {$row['source_ip']}) and has been paused. "
                    . 'If you restarted or moved its sim, open the Objects tab and click Accept new address; otherwise Revoke it.');
            }
            return [self::fail('address_changed'), 403];
        }

        $gridHost = trim((string)($data['grid_name'] ?? ''));
        if ($gridHost !== '' && (string)$row['grid_identity'] !== '' && Grid::identityOf($gridHost) !== $row['grid_identity']) {
            self::log($id, $action, 'refused', 'Reported a different grid: ' . $gridHost, $ip);
            if (self::claimAlert($row, 'grid:' . $gridHost)) {
                self::alert("{$name} reported a different grid ({$gridHost}) from the one it was approved on, and was refused.");
            }
            return [self::fail('wrong_grid'), 403];
        }

        if (!in_array($action, self::ALLOWED[$row['object_type']] ?? [], true)) {
            self::log($id, $action, 'refused', 'Not allowed for a ' . $row['object_type'], $ip);
            if (self::claimAlert($row, 'action:' . $action)) {
                self::alert("{$name} tried \"{$action}\", which it isn't allowed to do, and was refused.");
            }
            return [self::fail('not_allowed'), 403];
        }

        $region = self::region($data);
        if ($action === 'aobj_hello') {
            if ($region !== '' && $region !== (string)$row['region']) self::log($id, 'hello', 'ok', 'Now in ' . $region, $ip);
            db()->prepare('UPDATE admin_objects SET last_seen_at = UTC_TIMESTAMP(),
                                  region = COALESCE(NULLIF(:r, ""), region),
                                  position = COALESCE(NULLIF(:p, ""), position),
                                  grid_host = COALESCE(NULLIF(:g, ""), grid_host)
                            WHERE id = :id')
                ->execute([':r' => $region, ':p' => mb_substr(trim((string)($data['position'] ?? '')), 0, 64), ':g' => $gridHost, ':id' => $id]);
            return [['ok' => true], 200];
        }

        db()->prepare('UPDATE admin_objects SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id')->execute([':id' => $id]);
        return [self::recover($row, $data, $ip), 200];
    }

    // ── Account recovery ─────────────────────────────────────

    private static function recover(array $terminal, array $data, string $ip): array {
        $id = (int)$terminal['id'];
        $avatar = strtolower(trim((string)($data['avatar'] ?? '')));
        $avatarName = mb_substr(trim((string)($data['avatar_name'] ?? '')), 0, 64);
        if (!self::isUuid($avatar)) return self::fail('bad_avatar');

        // Only an avatar linked on THIS grid. A visitor from another grid could
        // be a look-alike made with someone else's UUID by that grid's owner.
        $stmt = db()->prepare('
            SELECT p.id, p.username, p.display_name, p.is_banned, pu.grid_identity
              FROM player_uuids pu JOIN players p ON p.id = pu.player_id
             WHERE pu.uuid = :u
        ');
        $stmt->execute([':u' => $avatar]);
        $acct = $stmt->fetch();
        if (!$acct || (string)$acct['grid_identity'] === '' || $acct['grid_identity'] !== $terminal['grid_identity']) {
            self::log($id, 'recovery', 'refused', "No account linked on this grid: {$avatarName} {$avatar}", $ip);
            return self::fail('not_linked', 'No OSFishing account is linked to this avatar on this grid. Pair your HUD on this grid first, or ask an admin.');
        }
        if ((int)$acct['is_banned'] === 1) {
            self::log($id, 'recovery', 'refused', "Banned account: {$acct['username']}", $ip);
            return self::fail('not_linked', 'This avatar has no active OSFishing account.');
        }

        $recent = db()->prepare('SELECT MAX(created_at) FROM login_tokens WHERE player_id = :pid AND purpose = "recovery"
                                    AND created_at > UTC_TIMESTAMP() - INTERVAL :s SECOND');
        $recent->execute([':pid' => $acct['id'], ':s' => self::RECOVERY_COOLDOWN]);
        if ($last = $recent->fetchColumn()) {
            $mins = max(1, (int)ceil((strtotime($last . ' UTC') + self::RECOVERY_COOLDOWN - time()) / 60));
            return self::fail('too_soon', "A recovery link was given out for this account recently. Try again in {$mins} min.");
        }

        $loginToken = bin2hex(random_bytes(24));
        db()->prepare('INSERT INTO login_tokens (token_hash, player_id, avatar_uuid, purpose, expires_at)
                        VALUES (:h, :pid, :u, "recovery", UTC_TIMESTAMP() + INTERVAL :ttl SECOND)')
            ->execute([':h' => hash('sha256', $loginToken), ':pid' => $acct['id'], ':u' => $avatar, ':ttl' => self::RECOVERY_LINK_TTL]);
        self::log($id, 'recovery', 'ok', "Link given to {$avatarName} ({$acct['username']})", $ip);

        return [
            'ok'  => true,
            'url' => self::baseUrl() . '/recover.php?token=' . $loginToken,
            'msg' => 'Here is your one-time login link. It works once, within 15 minutes, and lets you set a new password.',
        ];
    }

    /**
     * Consume a recovery link: returns the player id, or null if it is
     * unknown, used or expired. Atomic, so a link works exactly once.
     */
    public static function consumeLoginToken(string $token): ?int {
        $hash = hash('sha256', trim($token));
        $stmt = db()->prepare('UPDATE login_tokens SET used_at = UTC_TIMESTAMP()
                                WHERE token_hash = :h AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([':h' => $hash]);
        if ($stmt->rowCount() === 0) return null;
        $p = db()->prepare('SELECT player_id FROM login_tokens WHERE token_hash = :h');
        $p->execute([':h' => $hash]);
        return (int)$p->fetchColumn() ?: null;
    }

    // ── Admin Objects tab ────────────────────────────────────

    public static function listForAdmin(): array {
        $rows = db()->query("SELECT * FROM admin_objects WHERE status <> 'pending' OR pair_expires_at > UTC_TIMESTAMP() ORDER BY FIELD(status,'pending','active','revoked','rejected'), id DESC")->fetchAll();
        $log = db()->prepare('SELECT created_at, action, result, message, ip FROM admin_object_log WHERE object_id = :id ORDER BY id DESC LIMIT 12');
        foreach ($rows as &$r) {
            $log->execute([':id' => $r['id']]);
            $r['log'] = $log->fetchAll();
            $r = self::decorate($r);
        }
        return ['objects' => $rows];
    }

    public static function adminRevoke(int $id, array $admin): array {
        self::revoke($id, 'Revoked by ' . self::adminName($admin));
        return ['message' => 'Revoked'];
    }

    public static function adminAcceptIp(int $id, array $admin): array {
        $row = self::find($id);
        if (!$row || !$row['pending_ip']) json_error('No new address to accept');
        db()->prepare('UPDATE admin_objects SET source_ip = pending_ip, pending_ip = NULL WHERE id = :id')->execute([':id' => $id]);
        self::log($id, 'address', 'ok', "New address {$row['pending_ip']} accepted by " . self::adminName($admin), '');
        return ['message' => 'New address accepted'];
    }

    public static function adminRename(int $id, string $label): array {
        $l = mb_substr(trim($label), 0, 128);
        db()->prepare('UPDATE admin_objects SET label = :l WHERE id = :id')->execute([':l' => $l === '' ? null : $l, ':id' => $id]);
        return ['message' => 'Renamed'];
    }

    /** Only revoked or rejected entries can be deleted, so a live object never vanishes by accident. */
    public static function adminDelete(int $id): array {
        db()->prepare("DELETE FROM admin_objects WHERE id = :id AND status IN ('revoked','rejected')")->execute([':id' => $id]);
        db()->prepare('DELETE FROM admin_object_log WHERE object_id = :id AND NOT EXISTS (SELECT 1 FROM admin_objects WHERE id = :id2)')
            ->execute([':id' => $id, ':id2' => $id]);
        return ['message' => 'Deleted'];
    }

    /** Recovery terminals seen in the last two days, for the login page. */
    public static function recoveryTerminals(): array {
        $rows = db()->query("
            SELECT o.*, (SELECT MAX(g.hop_gateway) FROM grids g WHERE g.grid_identity = o.grid_identity) AS hop_gateway,
                        (SELECT MAX(g.nickname) FROM grids g WHERE g.grid_identity = o.grid_identity) AS grid_nickname
              FROM admin_objects o
             WHERE o.object_type = 'recovery' AND o.status = 'active'
               AND o.last_seen_at > UTC_TIMESTAMP() - INTERVAL 48 HOUR
             ORDER BY o.last_seen_at DESC
        ")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $gw = $r['hop_gateway'] ?: $r['grid_host'];
            $out[] = [
                'name'   => self::displayName($r, false),
                'region' => (string)$r['region'],
                'grid'   => $r['grid_nickname'] ?: (string)$r['grid_identity'],
                'hop'    => ($gw && $r['region']) ? 'hop://' . $gw . '/' . rawurlencode($r['region']) . '/' . self::hopPosition((string)$r['position']) : '',
            ];
        }
        return ['terminals' => $out];
    }

    // ── Helpers ──────────────────────────────────────────────

    /** An avatar linked to a non-banned admin account. */
    public static function isAdminAvatar(string $uuid): bool {
        $stmt = db()->prepare('SELECT 1 FROM player_uuids pu JOIN players p ON p.id = pu.player_id
                                WHERE pu.uuid = :u AND p.is_admin = 1 AND p.is_banned = 0');
        $stmt->execute([':u' => strtolower(trim($uuid))]);
        return (bool)$stmt->fetchColumn();
    }

    private static function revoke(int $id, string $reason): void {
        db()->prepare('UPDATE admin_objects SET status = "revoked", revoked_at = UTC_TIMESTAMP(), revoke_reason = :r, token_hash = NULL WHERE id = :id')
            ->execute([':r' => mb_substr($reason, 0, 255), ':id' => $id]);
        self::log($id, 'revoked', 'ok', $reason, '');
    }

    private static function revokeHostile(array $row, string $why, string $ip, string $action): array {
        self::revoke((int)$row['id'], ucfirst($why));
        self::log((int)$row['id'], $action, 'revoked', ucfirst($why) . " (from {$ip})", $ip);
        self::alert(self::displayName($row) . " was revoked: {$why}, from address {$ip}. Set it up again if it was yours.");
        return [self::fail('revoked'), 401];
    }

    /** True once per hour per object and reason. */
    private static function claimAlert(array $row, string $reason): bool {
        $seen = json_decode((string)$row['alerts_json'], true) ?: [];
        if (isset($seen[$reason]) && time() - (int)$seen[$reason] < 3600) return false;
        $seen[$reason] = time();
        db()->prepare('UPDATE admin_objects SET alerts_json = :j WHERE id = :id')->execute([':j' => json_encode($seen), ':id' => $row['id']]);
        return true;
    }

    private static function alert(string $message): void {
        AdminAlerts::send('admin_object', '[Admin Objects] ' . $message, '/admin/?tab=objects');
    }

    private static function log(int $id, string $action, string $result, string $message, string $ip): void {
        try {
            db()->prepare('INSERT INTO admin_object_log (object_id, action, result, message, ip) VALUES (:o, :a, :r, :m, :ip)')
                ->execute([':o' => $id, ':a' => $action, ':r' => $result, ':m' => mb_substr($message, 0, 500), ':ip' => $ip ?: null]);
        } catch (Throwable $e) {
            error_log('admin_object_log: ' . $e->getMessage());
        }
    }

    private static function find(int $id): ?array {
        $s = db()->prepare('SELECT * FROM admin_objects WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetch() ?: null;
    }

    private static function findByPairCode(string $code): ?array {
        if (!preg_match('/^[0-9a-f]{32}$/', $code)) return null;
        $s = db()->prepare('SELECT * FROM admin_objects WHERE pair_code = :c');
        $s->execute([':c' => $code]);
        return $s->fetch() ?: null;
    }

    private static function findByTokenHash(string $hash): ?array {
        $s = db()->prepare('SELECT * FROM admin_objects WHERE token_hash = :h');
        $s->execute([':h' => $hash]);
        return $s->fetch() ?: null;
    }

    private static function pairCodeLive(array $row): bool {
        return $row['pair_expires_at'] && strtotime($row['pair_expires_at'] . ' UTC') > time();
    }

    private static function decorate(array $r): array {
        $r['type_label'] = self::TYPES[$r['object_type']] ?? $r['object_type'];
        $r['display_name'] = self::displayName($r, false);
        $s = db()->prepare('SELECT p.display_name FROM player_uuids pu JOIN players p ON p.id = pu.player_id WHERE pu.uuid = :u');
        $s->execute([':u' => $r['owner_uuid']]);
        $r['owner_name'] = $s->fetchColumn() ?: $r['owner_uuid'];
        unset($r['token_hash'], $r['alerts_json']);
        return $r;
    }

    private static function displayName(array $row, bool $withType = true): string {
        $label = trim((string)($row['label'] ?? '')) ?: trim((string)($row['object_name'] ?? ''));
        $type = self::TYPES[$row['object_type']] ?? $row['object_type'];
        if (!$withType) return $label !== '' ? $label : $type;
        return $type . ($label !== '' ? " \"{$label}\"" : '') . ' #' . (int)$row['id'];
    }

    private static function adminName(array $admin): string {
        return (string)($admin['display_name'] ?: $admin['username']);
    }

    private static function region(array $data): string {
        $h = trim((string)($_SERVER['HTTP_X_SECONDLIFE_REGION'] ?? ''));
        if ($h !== '') return mb_substr(preg_replace('/\s*\(\d+,\s*\d+\)\s*$/', '', $h), 0, 128);
        return mb_substr(trim((string)($data['region'] ?? '')), 0, 128);
    }

    /** "<128.5, 64.2, 22.0>" → "128/64/22" (region centre if unknown) */
    private static function hopPosition(string $position): string {
        if (preg_match('/<\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*>/', $position, $m)) {
            return (int)round((float)$m[1]) . '/' . (int)round((float)$m[2]) . '/' . (int)round((float)$m[3]);
        }
        return '128/128/25';
    }

    private static function isUuid(string $v): bool {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($v));
    }

    private static function fail(string $code, string $msg = ''): array {
        return ['ok' => false, 'success' => false, 'error' => $code] + ($msg !== '' ? ['msg' => $msg] : []);
    }

    private static function baseUrl(): string {
        return (defined('SITE_URL') && SITE_URL !== '') ? rtrim(SITE_URL, '/') : 'https://osfishing.flamesfall.net';
    }
}
