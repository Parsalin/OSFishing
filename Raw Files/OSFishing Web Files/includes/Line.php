<?php
/**
 * Line.php - Fishing line management
 *
 * Handles line types, player line inventory, equipping, and purchasing.
 */

class Line
{
    /**
     * Get all line types (for tackle page).
     */
    public static function listAll(): array {
        return db()->query('
            SELECT id, name, weight_lb, visibility, min_level, cost_points, description, sort_order
            FROM line_types
            ORDER BY sort_order ASC, id ASC
        ')->fetchAll();
    }

    /**
     * Get lines owned by a player, with their current equipped status.
     */
    public static function listForPlayer(int $playerId): array {
        $stmt = db()->prepare('
            SELECT lt.id, lt.name, lt.weight_lb, lt.visibility, lt.min_level,
                   lt.cost_points, lt.description, lt.sort_order,
                   CASE WHEN pl.line_id IS NULL THEN 0 ELSE 1 END AS owned,
                   CASE WHEN p.equipped_line_id = lt.id THEN 1 ELSE 0 END AS equipped
            FROM line_types lt
            LEFT JOIN player_lines pl ON pl.line_id = lt.id AND pl.player_id = :pid1
            LEFT JOIN players p ON p.id = :pid2
            ORDER BY lt.sort_order ASC, lt.id ASC
        ');
        $stmt->execute([':pid1' => $playerId, ':pid2' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Equip a line.
     */
    public static function equip(int $playerId, int $lineId): array {
        $pdo = db();

        // Verify player owns this line
        $stmt = $pdo->prepare('SELECT 1 FROM player_lines WHERE player_id = :pid AND line_id = :lid');
        $stmt->execute([':pid' => $playerId, ':lid' => $lineId]);
        if (!$stmt->fetchColumn()) {
            json_error('You do not own this line.', 403);
        }

        $stmt = $pdo->prepare('UPDATE players SET equipped_line_id = :lid WHERE id = :pid');
        $stmt->execute([':lid' => $lineId, ':pid' => $playerId]);

        // Return the newly equipped line info
        $stmt = $pdo->prepare('
            SELECT id, name, weight_lb, visibility, min_level
            FROM line_types WHERE id = :lid
        ');
        $stmt->execute([':lid' => $lineId]);
        $line = $stmt->fetch();

        return [
            'message' => 'Line equipped.',
            'line'    => $line,
        ];
    }

    /**
     * Purchase a line with fishing points.
     */
    public static function buy(int $playerId, int $lineId): array {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Lock player row
            $stmt = $pdo->prepare('SELECT fishing_points, level FROM players WHERE id = :pid FOR UPDATE');
            $stmt->execute([':pid' => $playerId]);
            $player = $stmt->fetch();
            if (!$player) {
                $pdo->rollBack();
                json_error('Player not found', 404);
            }

            // Get line info
            $stmt = $pdo->prepare('SELECT id, name, cost_points, min_level FROM line_types WHERE id = :lid');
            $stmt->execute([':lid' => $lineId]);
            $line = $stmt->fetch();
            if (!$line) {
                $pdo->rollBack();
                json_error('Line not found', 404);
            }

            // Check level
            if ((int)$player['level'] < (int)$line['min_level']) {
                $pdo->rollBack();
                json_error("You must be level {$line['min_level']} to buy this line.", 403);
            }

            // Already owned?
            $stmt = $pdo->prepare('SELECT 1 FROM player_lines WHERE player_id = :pid AND line_id = :lid');
            $stmt->execute([':pid' => $playerId, ':lid' => $lineId]);
            if ($stmt->fetchColumn()) {
                $pdo->rollBack();
                json_error('You already own this line.', 400);
            }

            // Check points
            $cost = (int)$line['cost_points'];
            if ((int)$player['fishing_points'] < $cost) {
                $pdo->rollBack();
                json_error("You need {$cost} points (you have {$player['fishing_points']}).", 403);
            }

            // Deduct points and grant line
            $stmt = $pdo->prepare('UPDATE players SET fishing_points = fishing_points - :c WHERE id = :pid');
            $stmt->execute([':c' => $cost, ':pid' => $playerId]);
            $stmt = $pdo->prepare('INSERT INTO player_lines (player_id, line_id) VALUES (:pid, :lid)');
            $stmt->execute([':pid' => $playerId, ':lid' => $lineId]);

            $pdo->commit();

            return [
                'message' => "{$line['name']} purchased!",
                'line_id' => $lineId,
                'cost'    => $cost,
                'points_remaining' => (int)$player['fishing_points'] - $cost,
            ];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Get the currently-equipped line for a player (used in cast logic).
     */
    public static function getEquipped(int $playerId): ?array {
        $stmt = db()->prepare('
            SELECT lt.id, lt.name, lt.weight_lb, lt.visibility, lt.min_level
            FROM players p
            LEFT JOIN line_types lt ON lt.id = p.equipped_line_id
            WHERE p.id = :pid
        ');
        $stmt->execute([':pid' => $playerId]);
        $row = $stmt->fetch();
        if (!$row || !$row['id']) return null;
        return $row;
    }
}
