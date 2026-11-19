<?php
/**
 * Shared admin inbox (admin_alerts). Anything an admin should act on — a new
 * grid waiting for approval, a grid that denies HTTP-in, an admin object
 * refused or revoked, a failed sim-origin check — lands here and shows on
 * the admin panel's Alerts tab with an unread count.
 *
 * Never throws: an alert failing must not break the request that raised it.
 */

require_once __DIR__ . '/bootstrap.php';

class AdminAlerts {

    /**
     * Raise an alert. With $dedupeKey + $withinHours, an alert with the same
     * key raised in that window suppresses this one (e.g. once a day per grid).
     * Returns true when an alert was written.
     */
    public static function send(string $category, string $message, ?string $link = null,
                                array $data = [], ?string $dedupeKey = null, int $withinHours = 0): bool {
        try {
            $pdo = db();
            if ($dedupeKey !== null && $withinHours > 0) {
                $stmt = $pdo->prepare('
                    SELECT 1 FROM admin_alerts
                    WHERE dedupe_key = :k AND created_at > UTC_TIMESTAMP() - INTERVAL :h HOUR
                    LIMIT 1
                ');
                $stmt->execute([':k' => $dedupeKey, ':h' => $withinHours]);
                if ($stmt->fetchColumn()) return false;
            }
            $pdo->prepare('
                INSERT INTO admin_alerts (category, message, link, data_json, dedupe_key)
                VALUES (:c, :m, :l, :d, :k)
            ')->execute([
                ':c' => substr($category, 0, 48),
                ':m' => $message,
                ':l' => $link,
                ':d' => $data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':k' => $dedupeKey,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('AdminAlerts::send failed: ' . $e->getMessage() . ' — ' . $message);
            return false;
        }
    }

    public static function unreadCount(): int {
        try {
            return (int)db()->query('SELECT COUNT(*) FROM admin_alerts WHERE read_at IS NULL')->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function listAlerts(bool $unreadOnly = false, int $limit = 200): array {
        $where = $unreadOnly ? 'WHERE a.read_at IS NULL' : '';
        $rows = db()->query("
            SELECT a.id, a.created_at, a.category, a.message, a.link, a.data_json,
                   a.read_at, p.display_name AS read_by_name
              FROM admin_alerts a
              LEFT JOIN players p ON p.id = a.read_by
              {$where}
             ORDER BY a.id DESC
             LIMIT " . max(1, min(500, $limit))
        )->fetchAll();
        foreach ($rows as &$r) {
            $r['data'] = $r['data_json'] ? json_decode($r['data_json'], true) : null;
            unset($r['data_json']);
        }
        return ['alerts' => $rows, 'unread' => self::unreadCount()];
    }

    public static function markRead(int $alertId, int $adminId): array {
        db()->prepare('UPDATE admin_alerts SET read_at = UTC_TIMESTAMP(), read_by = :a WHERE id = :id AND read_at IS NULL')
            ->execute([':a' => $adminId, ':id' => $alertId]);
        return ['message' => 'Marked read', 'unread' => self::unreadCount()];
    }

    public static function markAllRead(int $adminId): array {
        db()->prepare('UPDATE admin_alerts SET read_at = UTC_TIMESTAMP(), read_by = :a WHERE read_at IS NULL')
            ->execute([':a' => $adminId]);
        return ['message' => 'All marked read', 'unread' => 0];
    }
}
