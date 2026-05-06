<?php
/**
 * Grid.php - Multi-grid registry
 *
 * Every connecting grid is registered. Only approved grids
 * can register spots, pair HUDs, and appear on the map.
 */

class Grid
{
    /**
     * Check if a grid is approved. If unknown, register as pending.
     * Returns the grid row or calls json_error.
     *
     * @param string $gridName  The grid_name from osGetGridName()
     * @param bool $errorOnFail If true, json_error on non-approved. If false, return null.
     * @return array|null
     */
    public static function requireApproved(string $gridName, bool $errorOnFail = true): ?array {
        if ($gridName === '') {
            if ($errorOnFail) json_error('No grid name provided', 400);
            return null;
        }

        $pdo = db();

        $stmt = $pdo->prepare('SELECT * FROM grids WHERE grid_name = :gn');
        $stmt->execute([':gn' => $gridName]);
        $grid = $stmt->fetch();

        if (!$grid) {
            // New grid — register as pending
            $pdo->prepare('INSERT IGNORE INTO grids (grid_name, status) VALUES (:gn, \'pending\')')
                ->execute([':gn' => $gridName]);

            if ($errorOnFail) {
                json_error(
                    "Grid '{$gridName}' is not yet registered. " .
                    "Your grid has been submitted for approval. Contact the admin.",
                    403
                );
            }
            return null;
        }

        if ($grid['status'] === 'pending') {
            if ($errorOnFail) {
                json_error(
                    "Grid '{$gridName}' is pending approval. Contact the admin.",
                    403
                );
            }
            return null;
        }

        if ($grid['status'] === 'denied') {
            if ($errorOnFail) {
                json_error("Grid '{$gridName}' is not authorized.", 403);
            }
            return null;
        }

        return $grid;
    }

    /**
     * Check grid without blocking — just register if new, return status.
     */
    public static function checkGrid(string $gridName): array {
        if ($gridName === '') return ['status' => 'unknown', 'message' => 'No grid name'];

        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM grids WHERE grid_name = :gn');
        $stmt->execute([':gn' => $gridName]);
        $grid = $stmt->fetch();

        if (!$grid) {
            $pdo->prepare('INSERT IGNORE INTO grids (grid_name, status) VALUES (:gn, \'pending\')')
                ->execute([':gn' => $gridName]);
            return ['status' => 'pending', 'message' => "Grid '{$gridName}' registered as pending."];
        }

        return [
            'status' => $grid['status'],
            'nickname' => $grid['nickname'] ?: $grid['grid_name'],
            'message' => "Grid status: {$grid['status']}",
        ];
    }

    // ═══════════════════════════════════════
    // ADMIN
    // ═══════════════════════════════════════

    public static function listAll(): array {
        $stmt = db()->query('SELECT * FROM grids ORDER BY status ASC, grid_name ASC');
        return ['grids' => $stmt->fetchAll()];
    }

    public static function approve(int $gridId): array {
        db()->prepare('UPDATE grids SET status = \'approved\', approved_at = NOW() WHERE id = :id')
            ->execute([':id' => $gridId]);
        return ['message' => 'Grid approved'];
    }

    public static function deny(int $gridId): array {
        db()->prepare('UPDATE grids SET status = \'denied\' WHERE id = :id')
            ->execute([':id' => $gridId]);
        return ['message' => 'Grid denied'];
    }

    public static function setNickname(int $gridId, string $nickname): array {
        db()->prepare('UPDATE grids SET nickname = :n WHERE id = :id')
            ->execute([':n' => trim($nickname), ':id' => $gridId]);
        return ['message' => 'Nickname updated'];
    }

    public static function deleteGrid(int $gridId): array {
        db()->prepare('DELETE FROM grids WHERE id = :id')->execute([':id' => $gridId]);
        return ['message' => 'Grid deleted'];
    }

    /**
     * Get list of approved grids (for map selector).
     */
    public static function listApproved(): array {
        $stmt = db()->query("
            SELECT id, grid_name, nickname
            FROM grids
            WHERE status = 'approved'
            ORDER BY grid_name
        ");
        return ['grids' => $stmt->fetchAll()];
    }
}
