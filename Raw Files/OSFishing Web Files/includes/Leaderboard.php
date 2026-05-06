<?php
/**
 * Leaderboard.php - Fishing leaderboards
 *
 * Supports:
 *   Scope:  world (all), grid (same grid_name), sim (same region_name)
 *   Metric: weight (heaviest single catch), catches (total fish caught)
 *   Filter: water type (pond/river/lake/ocean or all)
 */

class Leaderboard
{
    /**
     * Get leaderboard data.
     *
     * @param string $metric   'weight' or 'catches'
     * @param string $scope    'world', 'grid', or 'sim'
     * @param string|null $scopeValue  grid_name or region_name (required for grid/sim)
     * @param int|null $waterTypeId  Filter by water type (null = all)
     * @param int $limit       Number of entries to return
     */
    public static function get(
        string $metric = 'weight',
        string $scope = 'world',
        ?string $scopeValue = null,
        ?int $waterTypeId = null,
        int $limit = 10,
        ?int $spotId = null
    ): array {
        $pdo = db();

        if ($metric === 'weight') {
            return self::byWeight($pdo, $scope, $scopeValue, $waterTypeId, $limit, $spotId);
        } else {
            return self::byCatches($pdo, $scope, $scopeValue, $waterTypeId, $limit, $spotId);
        }
    }

    /**
     * Heaviest single catch leaderboard.
     */
    private static function byWeight(PDO $pdo, string $scope, ?string $scopeValue, ?int $waterTypeId, int $limit, ?int $spotId = null): array {
        $joins = '';
        $where = [];
        $params = [];

        // Always join fishing_spots for scope/water filtering
        $joins .= ' LEFT JOIN fishing_spots fs ON fs.id = cl.spot_id';

        // Spot filter overrides scope
        if ($spotId) {
            $where[] = 'cl.spot_id = :spot_id';
            $params[':spot_id'] = $spotId;
        } elseif ($scope === 'grid' && $scopeValue) {
            $where[] = 'fs.grid_name = :scope_val';
            $params[':scope_val'] = $scopeValue;
        } elseif ($scope === 'sim' && $scopeValue) {
            $where[] = 'fs.region_name = :scope_val';
            $params[':scope_val'] = $scopeValue;
        }

        // Water type filter
        if ($waterTypeId) {
            $where[] = 'fs.water_type_id = :wt';
            $params[':wt'] = $waterTypeId;
        }

        $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT p.display_name, p.username, p.level,
                   fsp.name AS fish_name,
                   cl.weight, cl.caught_at,
                   wt.name AS water_type,
                   fs.region_name,
                   rt.name AS rarity_name
            FROM catch_log cl
            JOIN players p ON p.id = cl.player_id
            JOIN fish_species fsp ON fsp.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = cl.rarity_id
            $joins
            LEFT JOIN water_types wt ON wt.id = fs.water_type_id
            INNER JOIN (
                SELECT cl2.player_id, MAX(cl2.weight) AS max_weight
                FROM catch_log cl2
                LEFT JOIN fishing_spots fs2 ON fs2.id = cl2.spot_id
                " . self::buildSubqueryWhere($scope, $scopeValue, $waterTypeId, $spotId) . "
                GROUP BY cl2.player_id
            ) maxes ON maxes.player_id = cl.player_id AND maxes.max_weight = cl.weight
            $whereClause
            GROUP BY cl.player_id
            ORDER BY cl.weight DESC
            LIMIT :lim
        ");
        $params[':lim'] = $limit;
        // Bind subquery params too if any
        foreach (self::buildSubqueryParams($scope, $scopeValue, $waterTypeId, $spotId) as $k => $v) {
            $params[$k] = $v;
        }
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    private static function buildSubqueryWhere(string $scope, ?string $scopeValue, ?int $waterTypeId, ?int $spotId): string {
        $where = [];
        if ($spotId) {
            $where[] = 'cl2.spot_id = :sub_spot_id';
        } elseif ($scope === 'grid' && $scopeValue) {
            $where[] = 'fs2.grid_name = :sub_scope_val';
        } elseif ($scope === 'sim' && $scopeValue) {
            $where[] = 'fs2.region_name = :sub_scope_val';
        }
        if ($waterTypeId) {
            $where[] = 'fs2.water_type_id = :sub_wt';
        }
        return count($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    }

    private static function buildSubqueryParams(string $scope, ?string $scopeValue, ?int $waterTypeId, ?int $spotId): array {
        $params = [];
        if ($spotId) {
            $params[':sub_spot_id'] = $spotId;
        } elseif (($scope === 'grid' || $scope === 'sim') && $scopeValue) {
            $params[':sub_scope_val'] = $scopeValue;
        }
        if ($waterTypeId) {
            $params[':sub_wt'] = $waterTypeId;
        }
        return $params;
    }

    /**
     * Most fish caught leaderboard.
     */
    private static function byCatches(PDO $pdo, string $scope, ?string $scopeValue, ?int $waterTypeId, int $limit, ?int $spotId = null): array {
        $joins = '';
        $where = [];
        $params = [];

        $joins .= ' LEFT JOIN fishing_spots fs ON fs.id = cl.spot_id';

        if ($spotId) {
            $where[] = 'cl.spot_id = :spot_id';
            $params[':spot_id'] = $spotId;
        } elseif ($scope === 'grid' && $scopeValue) {
            $where[] = 'fs.grid_name = :scope_val';
            $params[':scope_val'] = $scopeValue;
        } elseif ($scope === 'sim' && $scopeValue) {
            $where[] = 'fs.region_name = :scope_val';
            $params[':scope_val'] = $scopeValue;
        }

        if ($waterTypeId) {
            $where[] = 'fs.water_type_id = :wt';
            $params[':wt'] = $waterTypeId;
        }

        $whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("
            SELECT p.display_name, p.username, p.level,
                   COUNT(*) AS total_catches,
                   MAX(cl.weight) AS best_weight,
                   MAX(cl.caught_at) AS last_catch
            FROM catch_log cl
            JOIN players p ON p.id = cl.player_id
            $joins
            $whereClause
            GROUP BY p.id
            ORDER BY total_catches DESC
            LIMIT :lim
        ");
        $params[':lim'] = $limit;
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // ── Legacy methods for portal compatibility ──

    public static function biggestFish(?int $speciesId = null): array {
        $pdo = db();
        $where = '';
        $subWhere = '';
        $params = [];
        if ($speciesId) {
            $where = 'WHERE cl.fish_species_id = :fid';
            $subWhere = 'WHERE cl2.fish_species_id = :fid2';
            $params[':fid'] = $speciesId;
            $params[':fid2'] = $speciesId;
        }
        $stmt = $pdo->prepare("
            SELECT p.display_name, fsp.name AS fish_name, cl.weight,
                   rt.name AS rarity_name, cl.caught_at
            FROM catch_log cl
            JOIN players p ON p.id = cl.player_id
            JOIN fish_species fsp ON fsp.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = cl.rarity_id
            INNER JOIN (
                SELECT cl2.player_id, MAX(cl2.weight) AS max_weight
                FROM catch_log cl2
                $subWhere
                GROUP BY cl2.player_id
            ) maxes ON maxes.player_id = cl.player_id AND maxes.max_weight = cl.weight
            $where
            GROUP BY cl.player_id
            ORDER BY cl.weight DESC LIMIT 10
        ");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function mostCatches(string $period = 'alltime'): array {
        $where = '';
        if ($period === 'weekly') $where = 'WHERE cl.caught_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
        elseif ($period === 'monthly') $where = 'WHERE cl.caught_at > DATE_SUB(NOW(), INTERVAL 30 DAY)';

        $stmt = db()->prepare("
            SELECT p.display_name, COUNT(*) AS total_catches,
                   MAX(cl.weight) AS best_weight
            FROM catch_log cl
            JOIN players p ON p.id = cl.player_id
            $where
            GROUP BY p.id ORDER BY total_catches DESC LIMIT 10
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function mostPoints(): array {
        return db()->query('
            SELECT display_name, fishing_points, level
            FROM players ORDER BY fishing_points DESC LIMIT 10
        ')->fetchAll();
    }

    public static function rarestCatchers(): array {
        return db()->query('
            SELECT p.display_name, COUNT(*) AS rare_count
            FROM catch_log cl
            JOIN players p ON p.id = cl.player_id
            WHERE cl.rarity_id >= 3
            GROUP BY p.id ORDER BY rare_count DESC LIMIT 10
        ')->fetchAll();
    }
}
