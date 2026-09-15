<?php
/**
 * Durable delivery for server-initiated pushes.
 *
 * The rule, applied to every push path:
 *
 *     queue on empty callback URL; live POST on non-empty; REQUEUE on failure.
 *
 * Previously each push did a fire-and-forget POST and dropped the message if it
 * failed, so a transient blip silently lost a buff notification and grids that
 * deny llRequestURL() could never be pushed to at all. Now nothing is lost:
 * anything undelivered sits in push_queue and flushes on the target's next
 * inbound contact (hud_status / register_callback / prim_heartbeat).
 *
 * Two kinds of payload, which must be treated differently:
 *
 *   - Collapsible (equip_update): a snapshot of current state. Queueing five
 *     and replaying all five is pointless and wrong — only the newest matters,
 *     so a new one replaces any pending row with the same target and type.
 *   - Non-collapsible (buff_active, buff_expired, tutorial_event): discrete
 *     events. Every one must be delivered, in creation order.
 */

require_once __DIR__ . '/../config.php';

class PushQueue {

    /** Give up on a message after this many failed attempts. */
    const MAX_ATTEMPTS = 8;

    /** Discard anything older than this regardless of attempts. */
    const MAX_AGE_HOURS = 48;

    /** Most messages to deliver in one flush, so an inbound request stays fast. */
    const FLUSH_BATCH = 12;

    /** Payload 'type' values that are state snapshots rather than events. */
    private static $collapsible = ['equip_update'];

    public static function isCollapsible(string $pushType): bool {
        return in_array($pushType, self::$collapsible, true);
    }

    // ── Enqueue ───────────────────────────────────────────────

    /**
     * Queue a push for a player. Safe to call when the player has no callback
     * URL — that is the case this exists for.
     */
    public static function queueForPlayer(int $playerId, array $payload): void {
        self::insert('player', $playerId, null, null, $payload);
    }

    /**
     * Queue a push for an in-world prim, identified the same way
     * PrimCallback addresses them.
     */
    public static function queueForPrim(string $primType, int $refId, array $payload): void {
        self::insert('prim', null, $primType, $refId, $payload);
    }

    private static function insert(
        string $kind, ?int $playerId, ?string $primType, ?int $refId, array $payload
    ): void {
        $pushType = (string)($payload['type'] ?? 'unknown');
        $collapse = self::isCollapsible($pushType);
        $pdo      = db();

        // A collapsible push supersedes any pending one for the same target.
        // Delete-then-insert rather than UPDATE so the new row sorts last in
        // creation order alongside any events queued in between.
        if ($collapse) {
            if ($kind === 'player') {
                $pdo->prepare('DELETE FROM push_queue
                               WHERE target_kind = "player" AND player_id = :pid
                                 AND push_type = :pt')
                    ->execute([':pid' => $playerId, ':pt' => $pushType]);
            } else {
                $pdo->prepare('DELETE FROM push_queue
                               WHERE target_kind = "prim" AND prim_type = :ptype
                                 AND ref_id = :rid AND push_type = :pt')
                    ->execute([':ptype' => $primType, ':rid' => $refId, ':pt' => $pushType]);
            }
        }

        $pdo->prepare('
            INSERT INTO push_queue
                (target_kind, player_id, prim_type, ref_id, push_type, collapsible, payload)
            VALUES (:kind, :pid, :ptype, :rid, :pt, :col, :payload)
        ')->execute([
            ':kind'    => $kind,
            ':pid'     => $playerId,
            ':ptype'   => $primType,
            ':rid'     => $refId,
            ':pt'      => $pushType,
            ':col'     => $collapse ? 1 : 0,
            ':payload' => json_encode($payload),
        ]);
    }

    // ── Transport ─────────────────────────────────────────────

    /**
     * POST a payload to a callback URL.
     * Returns true only on a 2xx. Never throws — callers decide what a
     * failure means.
     */
    public static function post(string $url, array $payload, int $timeout = 3): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        return [
            'ok'   => ($code >= 200 && $code < 300),
            'code' => $code,
            // A 404/410 means this specific URL is gone for good — the sim
            // dropped it. Anything else (0 = no connection, 5xx) is worth
            // retrying: the object may simply be in an offline region.
            'dead' => ($code === 404 || $code === 410),
            'err'  => $err !== '' ? substr($err, 0, 200) : ('HTTP ' . $code),
        ];
    }

    // ── Flush ─────────────────────────────────────────────────

    /**
     * Deliver queued messages for a player. Called when the player's HUD makes
     * contact, so the URL is known-good at that moment.
     *
     * Returns the number delivered.
     */
    public static function flushPlayer(int $playerId, ?string $url = null): int {
        if ($url === null) {
            $stmt = db()->prepare('
                SELECT callback_url FROM hud_tokens
                WHERE player_id = :pid AND is_active = 1 AND revoked_at IS NULL
                  AND callback_url IS NOT NULL
                ORDER BY last_used DESC LIMIT 1
            ');
            $stmt->execute([':pid' => $playerId]);
            $url = $stmt->fetchColumn() ?: null;
        }
        if (!$url) return 0;

        $stmt = db()->prepare('
            SELECT * FROM push_queue
            WHERE target_kind = "player" AND player_id = :pid
              AND next_retry_at <= NOW()
            ORDER BY created_at ASC, id ASC
            LIMIT ' . self::FLUSH_BATCH
        );
        $stmt->execute([':pid' => $playerId]);
        return self::deliver($stmt->fetchAll(), $url);
    }

    /**
     * Pull-based delivery: return queued payloads and remove them, instead of
     * POSTing. This is the only path that works for a HUD whose grid denies
     * llRequestURL() — it can never be pushed to, so it collects its messages
     * on its next outbound request (hud_status).
     *
     * Rows are deleted in the same transaction they are read, so two
     * concurrent requests cannot both receive the same message.
     */
    public static function takeForPlayer(int $playerId, int $limit = self::FLUSH_BATCH): array {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                SELECT id, payload FROM push_queue
                WHERE target_kind = "player" AND player_id = :pid
                ORDER BY created_at ASC, id ASC
                LIMIT ' . (int)$limit . '
                FOR UPDATE
            ');
            $stmt->execute([':pid' => $playerId]);
            $rows = $stmt->fetchAll();

            if (!$rows) { $pdo->commit(); return []; }

            $ids  = array_column($rows, 'id');
            $in   = implode(',', array_map('intval', $ids));
            $pdo->exec("DELETE FROM push_queue WHERE id IN ({$in})");
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('push_queue takeForPlayer failed: ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $p = json_decode($r['payload'], true);
            if (is_array($p)) $out[] = $p;
        }
        return $out;
    }

    /**
     * Deliver queued messages for a prim.
     */
    public static function flushPrim(string $primType, int $refId, string $url): int {
        $stmt = db()->prepare('
            SELECT * FROM push_queue
            WHERE target_kind = "prim" AND prim_type = :pt AND ref_id = :rid
              AND next_retry_at <= NOW()
            ORDER BY created_at ASC, id ASC
            LIMIT ' . self::FLUSH_BATCH
        );
        $stmt->execute([':pt' => $primType, ':rid' => $refId]);
        return self::deliver($stmt->fetchAll(), $url);
    }

    /**
     * Send each row, removing it on success and backing it off on failure.
     */
    private static function deliver(array $rows, string $url): int {
        if (!$rows) return 0;

        $pdo  = db();
        $del  = $pdo->prepare('DELETE FROM push_queue WHERE id = :id');
        $fail = $pdo->prepare('
            UPDATE push_queue
               SET attempts = attempts + 1,
                   last_error = :err,
                   next_retry_at = DATE_ADD(NOW(), INTERVAL :backoff SECOND)
             WHERE id = :id
        ');

        $sent = 0;
        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);
            if (!is_array($payload)) {          // corrupt row, do not retry forever
                $del->execute([':id' => $row['id']]);
                continue;
            }

            $res = self::post($url, $payload);

            if ($res['ok']) {
                $del->execute([':id' => $row['id']]);
                $sent++;
                continue;
            }

            $attempts = (int)$row['attempts'] + 1;

            // Stop trying if the URL is gone, or we have tried long enough.
            // Everything else backs off exponentially (2s, 4s, 8s ... capped).
            if ($res['dead'] || $attempts >= self::MAX_ATTEMPTS) {
                $del->execute([':id' => $row['id']]);
                error_log("push_queue: giving up on #{$row['id']} ({$row['push_type']}) after {$attempts} attempts: {$res['err']}");
                continue;
            }

            $fail->execute([
                ':err'     => $res['err'],
                ':backoff' => min(3600, (int)pow(2, $attempts)),
                ':id'      => $row['id'],
            ]);

            // The URL is failing right now; no point hammering it with the
            // rest of this batch. They keep their place in the queue.
            break;
        }

        return $sent;
    }

    // ── Maintenance ───────────────────────────────────────────

    /**
     * Drop messages too old to be worth delivering. Called opportunistically,
     * the same way PrimCallback sweeps stale callbacks.
     */
    public static function sweep(): int {
        $stmt = db()->prepare('
            DELETE FROM push_queue
            WHERE created_at < DATE_SUB(NOW(), INTERVAL :h HOUR)
        ');
        $stmt->execute([':h' => self::MAX_AGE_HOURS]);
        return $stmt->rowCount();
    }

    /** Queue depth, for the admin panel. */
    public static function stats(): array {
        return db()->query('
            SELECT push_type,
                   COUNT(*)      AS queued,
                   MAX(attempts) AS max_attempts,
                   MIN(created_at) AS oldest
              FROM push_queue
          GROUP BY push_type
          ORDER BY queued DESC
        ')->fetchAll();
    }
}
