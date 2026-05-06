<?php
/**
 * GatherPoint.php - Bait gathering point management
 *
 * Features:
 * - Player ownership with level-based limits (1 per level)
 * - System spots (admin/no owner)
 * - Server-controlled stock regeneration
 * - Partial regen: 1-5 every 10 min if not depleted
 * - Full regen: restore to max after 30-60 min if depleted
 */

class GatherPoint
{
    public static function computeLimit(int $level): int {
        return max(1, $level);
    }

    public static function registerPoint(array $data): array {
        $pdo = db();

        $name     = trim($data['name'] ?? '');
        $baitId   = (int)($data['bait_id'] ?? 0);
        $region   = trim($data['region'] ?? '');
        $posX     = (float)($data['pos_x'] ?? 0);
        $posY     = (float)($data['pos_y'] ?? 0);
        $posZ     = (float)($data['pos_z'] ?? 0);
        $ownerKey = trim($data['owner_key'] ?? '');

        if ($name === '') json_error('Gather point name is required');
        if ($baitId <= 0) json_error('Invalid bait_id');

        $stmt = $pdo->prepare('SELECT id, name FROM bait_types WHERE id = :id');
        $stmt->execute([':id' => $baitId]);
        $bait = $stmt->fetch();
        if (!$bait) json_error('Unknown bait type', 400);

        $playerId = null;
        $isSystem = 1;
        if ($ownerKey !== '') {
            $stmt = $pdo->prepare('SELECT id, level FROM players WHERE uuid = :uuid');
            $stmt->execute([':uuid' => $ownerKey]);
            $owner = $stmt->fetch();
            if ($owner) {
                $playerId = (int)$owner['id'];
                $isSystem = 0;

                // Check for existing spot at this position (re-register doesn't count)
                $stmt = $pdo->prepare('
                    SELECT id FROM bait_gather_points
                    WHERE region_name = :r AND ABS(pos_x - :x) < 3 AND ABS(pos_y - :y) < 3 AND ABS(pos_z - :z) < 3
                    LIMIT 1
                ');
                $stmt->execute([':r' => $region, ':x' => $posX, ':y' => $posY, ':z' => $posZ]);
                $existing = $stmt->fetch();

                if (!$existing) {
                    $stmt = $pdo->prepare('
                        SELECT COUNT(*) FROM bait_gather_points
                        WHERE player_id = :pid AND is_system = 0
                    ');
                    $stmt->execute([':pid' => $playerId]);
                    $currentCount = (int)$stmt->fetchColumn();
                    $limit = self::computeLimit((int)$owner['level']);

                    if ($currentCount >= $limit) {
                        json_error(
                            "Gather point limit reached ({$currentCount}/{$limit}). Level up for more.",
                            403
                        );
                    }
                }
            }
        }

        // Check for existing point at this position
        $stmt = $pdo->prepare('
            SELECT id FROM bait_gather_points
            WHERE region_name = :r AND ABS(pos_x - :x) < 3 AND ABS(pos_y - :y) < 3 AND ABS(pos_z - :z) < 3
            LIMIT 1
        ');
        $stmt->execute([':r' => $region, ':x' => $posX, ':y' => $posY, ':z' => $posZ]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare('
                UPDATE bait_gather_points
                SET name = :n, bait_id = :bid, player_id = :pid, is_system = :sys, is_active = 1
                WHERE id = :id
            ');
            $stmt->execute([':n' => $name, ':bid' => $baitId, ':pid' => $playerId, ':sys' => $isSystem, ':id' => $existing['id']]);
            return ['point_id' => (int)$existing['id'], 'bait_name' => $bait['name'], 'message' => 'Gather point updated', 'created' => false];
        }

        $stmt = $pdo->prepare('
            INSERT INTO bait_gather_points
                (name, bait_id, player_id, is_system, region_name, pos_x, pos_y, pos_z, max_stock, current_stock)
            VALUES (:n, :bid, :pid, :sys, :r, :x, :y, :z, 25, 25)
        ');
        $stmt->execute([':n' => $name, ':bid' => $baitId, ':pid' => $playerId, ':sys' => $isSystem,
                         ':r' => $region, ':x' => $posX, ':y' => $posY, ':z' => $posZ]);

        return ['point_id' => (int)$pdo->lastInsertId(), 'bait_name' => $bait['name'], 'message' => 'Gather point registered', 'created' => true];
    }

    /**
     * Process regen and return status + next check interval.
     * Depleted: full restore after 30-60 min.
     * Partial: gain 1-5 every 10 min.
     */
    public static function checkStatus(int $pointId): array {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT gp.*, bt.name AS bait_name
            FROM bait_gather_points gp
            JOIN bait_types bt ON bt.id = gp.bait_id
            WHERE gp.id = :id
        ');
        $stmt->execute([':id' => $pointId]);
        $point = $stmt->fetch();
        if (!$point) return ['exists' => false, 'next_check' => 600];

        $currentStock = (int)$point['current_stock'];
        $maxStock = (int)$point['max_stock'];

        // Full stock — no regen needed
        if ($currentStock >= $maxStock) {
            return self::buildStatus($point, $currentStock, 600);
        }

        // Depleted — check for full restore
        if ($currentStock <= 0 && $point['last_depleted']) {
            $elapsed = time() - strtotime($point['last_depleted']);
            $restoreTime = 3600; // 1 hour max

            if ($elapsed >= $restoreTime) {
                $currentStock = $maxStock;
                $pdo->prepare('UPDATE bait_gather_points SET current_stock = :s, last_depleted = NULL, last_regen = NOW() WHERE id = :id')
                    ->execute([':s' => $maxStock, ':id' => $pointId]);
                return self::buildStatus($point, $currentStock, 600);
            }
            return self::buildStatus($point, 0, min($restoreTime - $elapsed, 600));
        }

        // Partial — gain 1-5 every 10 min
        $lastRegen = $point['last_regen'] ? strtotime($point['last_regen']) : 0;
        $elapsed = time() - $lastRegen;

        if ($elapsed >= 600) {
            $gain = mt_rand(1, 5);
            $currentStock = min($currentStock + $gain, $maxStock);
            $pdo->prepare('UPDATE bait_gather_points SET current_stock = :s, last_regen = NOW() WHERE id = :id')
                ->execute([':s' => $currentStock, ':id' => $pointId]);
            return self::buildStatus($point, $currentStock, 600);
        }

        return self::buildStatus($point, $currentStock, 600 - $elapsed);
    }

    private static function buildStatus(array $point, int $currentStock, int $nextCheck): array {
        $maxStock = (int)$point['max_stock'];
        return [
            'exists'        => true,
            'is_active'     => (bool)$point['is_active'],
            'bait_name'     => $point['bait_name'],
            'bait_id'       => (int)$point['bait_id'],
            'current_stock' => $currentStock,
            'max_stock'     => $maxStock,
            'stock_pct'     => $maxStock > 0 ? round(($currentStock / $maxStock) * 100) : 0,
            'depleted'      => ($currentStock <= 0),
            'next_check'    => max(30, (int)$nextCheck),
        ];
    }

    /**
     * Gather bait from a point.
     */
    public static function gather(int $playerId, int $pointId): array {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT gp.*, bt.name AS bait_name
            FROM bait_gather_points gp
            JOIN bait_types bt ON bt.id = gp.bait_id
            WHERE gp.id = :id AND gp.is_active = 1
            FOR UPDATE
        ');
        $stmt->execute([':id' => $pointId]);
        $point = $stmt->fetch();

        if (!$point) return ['gathered' => 0, 'message' => 'Gather point inactive.', 'depleted' => true];

        $currentStock = (int)$point['current_stock'];
        $maxStock = (int)$point['max_stock'];

        if ($currentStock <= 0) {
            return ['gathered' => 0, 'depleted' => true, 'message' => 'Picked clean. Come back later.', 'stock_pct' => 0];
        }

        $amount = mt_rand(1, 5);
        if ($amount > $currentStock) $amount = $currentStock;
        $newStock = $currentStock - $amount;

        $sql = 'UPDATE bait_gather_points SET current_stock = :s' . ($newStock <= 0 ? ', last_depleted = NOW()' : '') . ' WHERE id = :id';
        $pdo->prepare($sql)->execute([':s' => $newStock, ':id' => $pointId]);

        $baitId = (int)$point['bait_id'];
        $pdo->prepare('INSERT INTO player_bait (player_id, bait_id, quantity) VALUES (:pid, :bid, :qty) ON DUPLICATE KEY UPDATE quantity = quantity + :qty2')
            ->execute([':pid' => $playerId, ':bid' => $baitId, ':qty' => $amount, ':qty2' => $amount]);

        $stmt = $pdo->prepare('SELECT quantity FROM player_bait WHERE player_id = :pid AND bait_id = :bid');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        $totalQty = (int)$stmt->fetchColumn();

        $pct = $maxStock > 0 ? round(($newStock / $maxStock) * 100) : 0;

        // Track gather quest progress
        $questMsg = '';
        try {
            $qu = Quest::trackGather($playerId, $amount);
            foreach ($qu as $q) {
                if (!empty($q['ready_to_claim'])) $questMsg .= $q['message'];
                elseif (!empty($q['progress'])) $questMsg .= $q['quest_title'] . ': ' . $q['progress'] . "\n";
            }
        } catch (\Throwable $e) { error_log("Quest gather error: " . $e->getMessage()); }

        return [
            'gathered' => $amount, 'bait_name' => $point['bait_name'], 'bait_id' => $baitId,
            'total_quantity' => $totalQty, 'depleted' => ($newStock <= 0), 'stock_pct' => $pct,
            'quest_msg' => trim($questMsg),
            'message' => "Found {$amount} {$point['bait_name']}! (Total: {$totalQty})",
        ];
    }

    // ═══════════════════════════════════════
    // ADMIN
    // ═══════════════════════════════════════

    public static function listAll(string $search = ''): array {
        $pdo = db();
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE gp.name LIKE :s OR gp.region_name LIKE :s2 OR p.display_name LIKE :s3';
            $params = [':s' => "%$search%", ':s2' => "%$search%", ':s3' => "%$search%"];
        }
        $stmt = $pdo->prepare("
            SELECT gp.*, bt.name AS bait_name, p.display_name AS owner_name
            FROM bait_gather_points gp
            JOIN bait_types bt ON bt.id = gp.bait_id
            LEFT JOIN players p ON p.id = gp.player_id
            $where ORDER BY gp.id ASC
        ");
        $stmt->execute($params);
        return ['gather_points' => $stmt->fetchAll()];
    }

    public static function adminUpdate(int $id, array $data): array {
        $allowed = ['name','bait_id','max_stock','current_stock','is_active','is_system','player_id','respawn_minutes'];
        $sets = []; $params = [':id' => $id];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                if ($f === 'player_id' && ($data[$f] === '' || $data[$f] === 'null')) $sets[] = "player_id = NULL";
                else { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
            }
        }
        if (empty($sets)) json_error('Nothing to update');
        db()->prepare('UPDATE bait_gather_points SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        return ['message' => 'Gather point updated'];
    }

    public static function adminDelete(int $id): array {
        db()->prepare('DELETE FROM bait_gather_points WHERE id = :id')->execute([':id' => $id]);
        return ['message' => 'Gather point deleted'];
    }

    public static function adminRefill(int $id): array {
        db()->prepare('UPDATE bait_gather_points SET current_stock = max_stock, last_depleted = NULL, last_regen = NOW() WHERE id = :id')->execute([':id' => $id]);
        return ['message' => 'Gather point refilled'];
    }
}
