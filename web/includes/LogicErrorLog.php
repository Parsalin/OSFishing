<?php
/**
 * logic_error_log: silent self-repairs and fail-closed checks.
 *
 * Log here when the server finds data that should never exist and corrects
 * it, or when a permission/allowlist check refuses something it shouldn't
 * have had to — so bugs being quietly smoothed over stay visible. NOT for
 * normal, expected gameplay events; those are noise here.
 *
 * Categories in use are listed in CLAUDE.md (Logic Error Log). Add a row
 * there when adding a new one. Never throws.
 */

require_once __DIR__ . '/bootstrap.php';

class LogicErrorLog {

    public static function log(string $category, ?int $playerId, string $message, array $context = []): void {
        try {
            db()->prepare('
                INSERT INTO logic_error_log (category, player_id, message, context_json)
                VALUES (:c, :p, :m, :x)
            ')->execute([
                ':c' => substr($category, 0, 64),
                ':p' => $playerId,
                ':m' => mb_substr($message, 0, 500),
                ':x' => $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]);
        } catch (Throwable $e) {
            error_log("LogicErrorLog::log failed ({$category}): " . $e->getMessage() . " — {$message}");
        }
    }

    public static function seenRecently(string $category, string $message, int $hours): bool {
        try {
            $stmt = db()->prepare('
                SELECT 1 FROM logic_error_log
                WHERE category = :c AND message = :m AND logged_at > UTC_TIMESTAMP() - INTERVAL :h HOUR
                LIMIT 1
            ');
            $stmt->execute([':c' => $category, ':m' => mb_substr($message, 0, 500), ':h' => $hours]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Admin panel: recent entries, optionally one category, plus per-category counts. */
    public static function listEntries(string $category = '', int $limit = 200): array {
        $pdo = db();
        $params = [];
        $where = '';
        if ($category !== '') {
            $where = 'WHERE l.category = :c';
            $params[':c'] = $category;
        }
        $stmt = $pdo->prepare("
            SELECT l.id, l.logged_at, l.category, l.player_id, l.message, l.context_json,
                   p.display_name AS player_name
              FROM logic_error_log l
              LEFT JOIN players p ON p.id = l.player_id
              {$where}
             ORDER BY l.id DESC
             LIMIT " . max(1, min(500, $limit)));
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['context'] = $r['context_json'] ? json_decode($r['context_json'], true) : null;
            unset($r['context_json']);
        }
        $counts = $pdo->query('
            SELECT category, COUNT(*) AS n, MAX(logged_at) AS last_at
              FROM logic_error_log GROUP BY category ORDER BY last_at DESC
        ')->fetchAll();
        return ['entries' => $rows, 'categories' => $counts];
    }
}
