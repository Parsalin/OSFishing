<?php
/**
 * Fishing Game - Player Manager
 * Handles registration, leveling, XP, and profile management.
 */

require_once __DIR__ . '/bootstrap.php';

class Player {

    /**
     * Award XP and handle level-ups.
     * Returns level-up info if the player leveled.
     */
    public static function awardXP(int $playerId, int $amount): ?array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT level, xp FROM players WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();

        $newXP = (int)$player['xp'] + $amount;
        $currentLevel = (int)$player['level'];
        $newLevel = $currentLevel;

        // Check for level-ups
        $stmt = $pdo->prepare('
            SELECT level, title, unlock_notes FROM levels
            WHERE xp_required <= :xp
            ORDER BY level DESC LIMIT 1
        ');
        $stmt->execute([':xp' => $newXP]);
        $levelRow = $stmt->fetch();

        $levelUpInfo = null;
        if ($levelRow && (int)$levelRow['level'] > $currentLevel) {
            $newLevel = (int)$levelRow['level'];
            $levelUpInfo = [
                'old_level'   => $currentLevel,
                'new_level'   => $newLevel,
                'title'       => $levelRow['title'],
                'unlock'      => $levelRow['unlock_notes'],
            ];

            // Grant title if one exists for this level
            if ($levelRow['title']) {
                $stmt = $pdo->prepare('
                    INSERT IGNORE INTO player_titles (player_id, title, source)
                    VALUES (:pid, :title, \'level\')
                ');
                $stmt->execute([':pid' => $playerId, ':title' => $levelRow['title']]);
            }
        }

        $stmt = $pdo->prepare('UPDATE players SET xp = :xp, level = :lvl WHERE id = :id');
        $stmt->execute([':xp' => $newXP, ':lvl' => $newLevel, ':id' => $playerId]);

        return $levelUpInfo;
    }

    /**
     * Add fishing points to a player.
     */
    public static function addPoints(int $playerId, int $amount): int {
        $stmt = db()->prepare('
            UPDATE players
            SET fishing_points = fishing_points + :pts,
                total_points_earned = total_points_earned + :pts2
            WHERE id = :id
        ');
        $stmt->execute([':pts' => $amount, ':pts2' => $amount, ':id' => $playerId]);

        $stmt = db()->prepare('SELECT fishing_points FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Deduct fishing points. Returns false if insufficient.
     */
    public static function deductPoints(int $playerId, int $amount): bool {
        $stmt = db()->prepare('
            UPDATE players
            SET fishing_points = fishing_points - :pts
            WHERE id = :id AND fishing_points >= :pts2
        ');
        $stmt->execute([':pts' => $amount, ':pts2' => $amount, ':id' => $playerId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get full player profile for web dashboard.
     */
    public static function getProfile(int $playerId): array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT * FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();

        // Get next level info
        $stmt = $pdo->prepare('SELECT level, xp_required FROM levels WHERE level = :lvl');
        $stmt->execute([':lvl' => (int)$player['level'] + 1]);
        $nextLevel = $stmt->fetch();

        // Get current level XP threshold
        $stmt = $pdo->prepare('SELECT xp_required FROM levels WHERE level = :lvl');
        $stmt->execute([':lvl' => (int)$player['level']]);
        $curLevelXp = (int)$stmt->fetchColumn();

        // Get active title
        $stmt = $pdo->prepare('
            SELECT title FROM player_titles
            WHERE player_id = :pid AND is_active = 1 LIMIT 1
        ');
        $stmt->execute([':pid' => $playerId]);
        $activeTitle = $stmt->fetchColumn() ?: null;

        // Get rod info (cosmetic only)
        $stmt = $pdo->prepare('SELECT * FROM rod_types WHERE id = :id');
        $stmt->execute([':id' => $player['equipped_rod_id']]);
        $rod = $stmt->fetch();

        // Get equipped line info
        $line = null;
        if ($player['equipped_line_id']) {
            $stmt = $pdo->prepare('
                SELECT id, name, weight_lb, visibility, min_level
                FROM line_types WHERE id = :id
            ');
            $stmt->execute([':id' => $player['equipped_line_id']]);
            $line = $stmt->fetch();
        }

        // Get bait info
        $bait = null;
        if ($player['equipped_bait_id']) {
            $stmt = $pdo->prepare('
                SELECT bt.*, pb.quantity
                FROM bait_types bt
                JOIN player_bait pb ON pb.bait_id = bt.id AND pb.player_id = :pid
                WHERE bt.id = :bid
            ');
            $stmt->execute([':pid' => $playerId, ':bid' => $player['equipped_bait_id']]);
            $bait = $stmt->fetch();
        }

        // Count held fish
        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM player_fish WHERE player_id = :pid AND status = \'held\'
        ');
        $stmt->execute([':pid' => $playerId]);
        $heldFish = (int)$stmt->fetchColumn();

        return [
            'player'        => Auth::publicPlayerData($player),
            'active_title'  => $activeTitle,
            'equipped_rod'  => $rod,
            'equipped_line' => $line,
            'equipped_bait' => $bait,
            'held_fish'     => $heldFish,
            'xp_to_next'    => $nextLevel ? (int)$nextLevel['xp_required'] - (int)$player['xp'] : 0,
            'xp_level_total' => $nextLevel ? (int)$nextLevel['xp_required'] - $curLevelXp : 0,
            'next_level'    => $nextLevel ? (int)$nextLevel['level'] : null,
            'home_grid'     => $player['home_grid'] ?? null,
        ];
    }

    /**
     * Increment cast counter.
     */
    public static function incrementCasts(int $playerId): void {
        $stmt = db()->prepare('
            UPDATE players SET total_casts = total_casts + 1, last_fished = UTC_TIMESTAMP()
            WHERE id = :id
        ');
        $stmt->execute([':id' => $playerId]);
    }

    /**
     * Increment catch counter.
     */
    public static function incrementCatches(int $playerId): void {
        $stmt = db()->prepare('UPDATE players SET total_catches = total_catches + 1 WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
    }

    /**
     * Equip a rod (must own it and meet level requirement).
     */
    public static function equipRod(int $playerId, int $rodId): array {
        $pdo = db();

        // Verify ownership
        $stmt = $pdo->prepare('
            SELECT r.* FROM player_rods pr
            JOIN rod_types r ON r.id = pr.rod_id
            WHERE pr.player_id = :pid AND pr.rod_id = :rid
        ');
        $stmt->execute([':pid' => $playerId, ':rid' => $rodId]);
        $rod = $stmt->fetch();

        if (!$rod) {
            json_error('You do not own that rod');
        }

        // Check level
        $stmt = $pdo->prepare('SELECT level FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $level = (int)$stmt->fetchColumn();

        if ($level < (int)$rod['min_level']) {
            json_error("Requires level {$rod['min_level']}");
        }

        $stmt = $pdo->prepare('UPDATE players SET equipped_rod_id = :rid WHERE id = :id');
        $stmt->execute([':rid' => $rodId, ':id' => $playerId]);

        return $rod;
    }
}
