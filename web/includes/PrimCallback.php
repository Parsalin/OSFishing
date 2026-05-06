<?php
/**
 * PrimCallback.php
 *
 * Manages prim callback URLs for server-to-prim push.
 * Replaces polling with event-driven updates.
 */

class PrimCallback
{
    /**
     * Register or update a prim's callback URL.
     */
    public static function register(string $primUuid, string $primType, string $callbackUrl,
                                     ?int $refId = null, string $regionName = '', string $gridName = ''): array {
        if (!preg_match('/^https?:\/\//', $callbackUrl)) {
            json_error('Invalid callback URL');
        }

        $pdo = db();
        $pdo->prepare('
            INSERT INTO prim_callbacks (prim_uuid, prim_type, ref_id, callback_url, region_name, grid_name, last_seen)
            VALUES (:uuid, :type, :ref, :url, :rn, :gn, NOW())
            ON DUPLICATE KEY UPDATE
                callback_url = :url2,
                ref_id = :ref2,
                region_name = :rn2,
                grid_name = :gn2,
                last_seen = NOW()
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
                WHERE sb.spot_id = :sid AND sb.expires_at > NOW()
            ');
            $stmt->execute([':sid' => $refId]);
            foreach ($stmt->fetchAll() as $buff) {
                self::pushUrl($callbackUrl, [
                    'event'      => 'buff_active',
                    'buff_type'  => $buff['buff_type'],
                    'buff_name'  => $buff['buff_name'],
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
        db()->prepare('DELETE FROM prim_callbacks WHERE prim_uuid = :u')->execute([':u' => $primUuid]);
        return ['message' => 'Callback removed'];
    }

    /**
     * Touch last_seen — prim heartbeat.
     * If the prim is a fishing_spot that was previously marked dead by the
     * stale-callback sweep, this also re-marks the spot as active server-side
     * so the owner doesn't have to manually re-activate it.
     */
    public static function heartbeat(string $primUuid): void {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT prim_type, ref_id FROM prim_callbacks WHERE prim_uuid = :u
        ');
        $stmt->execute([':u' => $primUuid]);
        $row = $stmt->fetch();

        $pdo->prepare('UPDATE prim_callbacks SET last_seen = NOW() WHERE prim_uuid = :u')
            ->execute([':u' => $primUuid]);
    }

    /**
     * Sweep for stale callbacks. Called periodically (e.g. via cron or
     * lazily on each request). Removes prim_callbacks rows whose last_seen
     * is older than the threshold. If the prim was a fishing_spot, also
     * marks the spot inactive so we stop trying to push to a dead URL.
     *
     * Threshold: 90 minutes (allowing for ~3 missed 30-min heartbeats).
     */
    public static function cleanupStale(int $thresholdSeconds = 5400): array {
        $pdo = db();

        // Find stale fishing_spot callbacks first
        $stmt = $pdo->prepare('
            SELECT ref_id FROM prim_callbacks
            WHERE prim_type = :t AND last_seen < DATE_SUB(NOW(), INTERVAL :s SECOND)
        ');
        $stmt->execute([':t' => 'fishing_spot', ':s' => $thresholdSeconds]);
        $deadSpotIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Mark those spots inactive (but don't archive — owner can re-activate by
        // touching the prim, which will re-register the callback and resume heartbeats)
        if (count($deadSpotIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($deadSpotIds), '?'));
            $stmt = $pdo->prepare("UPDATE fishing_spots SET is_active = 0
                                   WHERE id IN ($placeholders)");
            $stmt->execute($deadSpotIds);
        }

        // Delete the stale callback rows
        $del = $pdo->prepare('
            DELETE FROM prim_callbacks
            WHERE last_seen < DATE_SUB(NOW(), INTERVAL :s SECOND)
        ');
        $del->execute([':s' => $thresholdSeconds]);

        return [
            'removed_callbacks' => $del->rowCount(),
            'deactivated_spots' => count($deadSpotIds),
        ];
    }

    /**
     * Push a payload to all prims of a given type for a ref_id.
     * Used by buff activation, spot updates, etc.
     */
    public static function pushToType(string $primType, int $refId, array $payload): int {
        $stmt = db()->prepare('SELECT callback_url FROM prim_callbacks WHERE prim_type = :t AND ref_id = :r');
        $stmt->execute([':t' => $primType, ':r' => $refId]);
        $urls = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($urls as $url) {
            if (self::pushUrl($url, $payload)) $count++;
        }
        return $count;
    }

    /**
     * Push a payload to a single spot by spot_id.
     */
    public static function pushToSpot(int $spotId, array $payload): bool {
        return self::pushToType('fishing_spot', $spotId, $payload) > 0;
    }

    /**
     * Push to a single URL. Returns true on success.
     */
    public static function pushUrl(string $url, array $payload): bool {
        $json = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 200/201/204 are good. If URL is dead (404, 500, no response), drop it.
        if ($code === 0 || $code === 404 || $code === 410) {
            db()->prepare('DELETE FROM prim_callbacks WHERE callback_url = :u')->execute([':u' => $url]);
            return false;
        }
        return ($code >= 200 && $code < 300);
    }

}
