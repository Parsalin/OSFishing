<?php
/**
 * PrimCallback.php
 *
 * Manages prim callback URLs for server-to-prim push.
 * Replaces polling with event-driven updates.
 */

class PrimCallback
{
    /** A prim silent this long (three missed 30-min heartbeats) gets no pushes; they queue for it instead. */
    const STALE_SECS  = 5400;
    /** A prim silent this long is forgotten. */
    const FORGET_DAYS = 7;

    /**
     * The request must come from the prim it names: the sim stamps
     * X-SecondLife-Object-Key, so a plain request can't register a URL for,
     * or heartbeat as, someone else's prim. (A hand-made request can still
     * send any header; the owner check below is what protects a spot.)
     */
    private static function requireObject(string $primUuid): void {
        $obj = strtolower(trim((string)($_SERVER['HTTP_X_SECONDLIFE_OBJECT_KEY'] ?? '')));
        if ($obj === '' || $obj !== strtolower(trim($primUuid))) {
            json_error('Only the prim itself can do that', 403);
        }
    }

    /** The object's owner must own the spot; admins may register any spot. */
    private static function requireSpotOwner(int $spotId): void {
        $owner = trim((string)($_SERVER['HTTP_X_SECONDLIFE_OWNER_KEY'] ?? ''));
        $stmt = db()->prepare('SELECT player_id, is_system FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);

        $stmt = db()->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
        $stmt->execute([':u' => $owner]);
        $p = $stmt->fetch();
        $ok = $p && ((int)$p['is_admin'] === 1
                     || (!(int)$spot['is_system'] && (int)$spot['player_id'] === (int)$p['id']));
        if (!$ok) json_error('This prim\'s owner does not own that spot', 403);
    }

    /**
     * Register or update a fishing spot prim's callback URL. Only the prim
     * itself, owned by the spot's owner (or an admin). One prim per spot: a
     * re-rezzed spot replaces the old prim's row.
     */
    public static function register(string $primUuid, string $primType, string $callbackUrl,
                                     ?int $refId = null, string $regionName = '', string $gridName = ''): array {
        if (!preg_match('#^https?://[^/\s]+#i', $callbackUrl) || strlen($callbackUrl) > 255) {
            json_error('Invalid callback URL');
        }
        if ($primType !== 'fishing_spot' || !$refId) json_error('Unsupported prim_type');
        $primUuid = strtolower(trim($primUuid));
        self::requireObject($primUuid);
        self::requireSpotOwner($refId);

        db()->prepare('DELETE FROM prim_callbacks WHERE prim_type = :t AND ref_id = :r AND prim_uuid <> :u')
            ->execute([':t' => $primType, ':r' => $refId, ':u' => $primUuid]);

        require_once __DIR__ . '/Grid.php';
        if ($gridName !== '') {
            Grid::findOrRegister($gridName);
            Grid::checkPushLater($gridName, $callbackUrl);
        }

        $pdo = db();
        $pdo->prepare('
            INSERT INTO prim_callbacks (prim_uuid, prim_type, ref_id, callback_url, region_name, grid_name, last_seen)
            VALUES (:uuid, :type, :ref, :url, :rn, :gn, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
                callback_url = :url2,
                ref_id = :ref2,
                region_name = :rn2,
                grid_name = :gn2,
                last_seen = UTC_TIMESTAMP()
        ')->execute([
            ':uuid' => $primUuid, ':type' => $primType, ':ref' => $refId, ':url' => $callbackUrl,
            ':rn' => $regionName, ':gn' => $gridName,
            ':url2' => $callbackUrl, ':ref2' => $refId, ':rn2' => $regionName, ':gn2' => $gridName,
        ]);

        // After a sim restart the spot loses its in-memory buff cache.
        // Re-push any currently active buffs so the spot knows what's happening.
        if ($primType === 'fishing_spot' && $refId) {
            $stmt = $pdo->prepare('
                SELECT sb.buff_type, sb.buff_value, bi.name AS buff_name,
                       UNIX_TIMESTAMP(sb.expires_at) AS expires_at_unix
                FROM spot_buffs sb
                JOIN buff_items bi ON bi.buff_type = sb.buff_type
                WHERE sb.spot_id = :sid AND sb.expires_at > UTC_TIMESTAMP()
            ');
            $stmt->execute([':sid' => $refId]);
            require_once __DIR__ . '/PushQueue.php';
            foreach ($stmt->fetchAll() as $buff) {
                PushQueue::sendLater($callbackUrl, [
                    'type'       => 'buff_active',
                    'buff_type'  => $buff['buff_type'],
                    'buff_label' => $buff['buff_name'],
                    'buff_value' => (float)$buff['buff_value'],
                    'expires_at' => (int)$buff['expires_at_unix'],
                ]);
            }
        }

        return ['message' => 'Callback registered', 'prim_uuid' => $primUuid];
    }

    /**
     * Unregister a callback (on prim removal or ownership change).
     */
    public static function unregister(string $primUuid): array {
        self::requireObject($primUuid);
        db()->prepare('DELETE FROM prim_callbacks WHERE prim_uuid = :u')->execute([':u' => $primUuid]);
        return ['message' => 'Callback removed'];
    }

    /**
     * Touch last_seen — prim heartbeat. A prim that was stale gets pushes
     * again from here on, and is handed whatever queued while it was silent.
     */
    public static function heartbeat(string $primUuid): void {
        require_once __DIR__ . '/PushQueue.php';
        self::requireObject($primUuid);

        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT prim_type, ref_id, callback_url FROM prim_callbacks WHERE prim_uuid = :u
        ');
        $stmt->execute([':u' => $primUuid]);
        $row = $stmt->fetch();

        $pdo->prepare('UPDATE prim_callbacks SET last_seen = UTC_TIMESTAMP() WHERE prim_uuid = :u')
            ->execute([':u' => $primUuid]);

        // The prim just proved it is alive and reachable, so this is the
        // moment to hand it anything that was queued while it was not.
        if ($row && !empty($row['callback_url'])) {
            $pt = $row['prim_type']; $rid = (int)$row['ref_id']; $url = $row['callback_url'];
            PushQueue::later(static fn() => PushQueue::flushPrim($pt, $rid, $url));
        }
    }

    /**
     * Forget prims silent for FORGET_DAYS. Run by bin/sweep.php and now and
     * then by prim_heartbeat.
     *
     * This no longer switches spots off. It used to set is_active = 0 after
     * 90 min of silence, and nothing ever switched them back on, so a region
     * outage left spots dark until the owner noticed (audit item 36). A
     * silent prim just stops getting pushes (pushToType skips it, the push
     * queues) until its next heartbeat.
     */
    public static function cleanupStale(): array {
        $del = db()->prepare('
            DELETE FROM prim_callbacks
            WHERE last_seen < UTC_TIMESTAMP() - INTERVAL ' . self::FORGET_DAYS . ' DAY
        ');
        $del->execute();
        return ['removed_callbacks' => $del->rowCount()];
    }

    /**
     * Push a payload to all prims of a given type for a ref_id.
     * Used by buff activation, spot updates, etc.
     *
     * Sent after the reply (PushQueue outbox). Returns how many prims it was
     * sent to, not how many received it; if none did, it is queued for the
     * next prim_heartbeat.
     */
    public static function pushToType(string $primType, int $refId, array $payload): int {
        require_once __DIR__ . '/PushQueue.php';

        $stmt = db()->prepare('
            SELECT callback_url FROM prim_callbacks
            WHERE prim_type = :t AND ref_id = :r
              AND last_seen > UTC_TIMESTAMP() - INTERVAL ' . self::STALE_SECS . ' SECOND
        ');
        $stmt->execute([':t' => $primType, ':r' => $refId]);
        $urls = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // No live prim (none registered, region offline, prim taken back).
        if (!$urls) {
            PushQueue::queueForPrim($primType, $refId, $payload);
            return 0;
        }

        $tally = (object)['left' => count($urls), 'ok' => 0];
        foreach ($urls as $url) {
            PushQueue::sendLater($url, $payload, static function (array $res) use ($tally, $url, $primType, $refId, $payload) {
                if ($res['ok']) {
                    $tally->ok++;
                } elseif ($res['dead']) {
                    // Only forget the URL when the sim says it is genuinely
                    // gone; a timeout or 0 may just be a region restarting.
                    db()->prepare('DELETE FROM prim_callbacks WHERE callback_url = :u')->execute([':u' => $url]);
                }
                // Every POST failed: queue it rather than drop it.
                if (--$tally->left === 0 && $tally->ok === 0) {
                    PushQueue::queueForPrim($primType, $refId, $payload);
                }
            });
        }
        return count($urls);
    }

    /**
     * Push a payload to a single spot by spot_id.
     */
    public static function pushToSpot(int $spotId, array $payload): bool {
        return self::pushToType('fishing_spot', $spotId, $payload) > 0;
    }

}
