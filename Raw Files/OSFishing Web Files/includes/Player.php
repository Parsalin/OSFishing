<?php
/**
 * Fishing Game - Player Manager
 * Handles registration, leveling, XP, and profile management.
 */

require_once __DIR__ . '/../config.php';

class Player {

    /**
     * Register a new player from in-world HUD first attach.
     * Creates the account with a setup token for web registration.
     */
    public static function register(string $uuid, string $displayName): array {
        $pdo = db();

        // Check if already registered
        $stmt = $pdo->prepare('SELECT id FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        if ($stmt->fetch()) {
            json_error('Player already registered', 409);
        }

        // Generate setup token for web portal registration
        $setupToken = bin2hex(random_bytes(32));
        $tokenExpires = date('Y-m-d H:i:s', time() + SETUP_TOKEN_LIFETIME);

        // Temporary username from display name until web setup
        $tempUsername = preg_replace('/[^a-zA-Z0-9_]/', '', $displayName) . '_' . substr(md5($uuid), 0, 6);

        // Temporary password hash (unusable until web setup)
        $tempPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

        $stmt = $pdo->prepare('
            INSERT INTO players (uuid, username, password_hash, display_name, setup_token, token_expires)
            VALUES (:uuid, :username, :pass, :name, :token, :expires)
        ');
        $stmt->execute([
            ':uuid'    => $uuid,
            ':username' => $tempUsername,
            ':pass'    => $tempPassword,
            ':name'    => $displayName,
            ':token'   => $setupToken,
            ':expires' => $tokenExpires,
        ]);

        $playerId = (int)$pdo->lastInsertId();

        // Grant the starter rod (Bamboo Rod, id=1)
        $stmt = $pdo->prepare('
            INSERT INTO player_rods (player_id, rod_id) VALUES (:pid, 1)
        ');
        $stmt->execute([':pid' => $playerId]);

        // Equip the starter rod
        $stmt = $pdo->prepare('UPDATE players SET equipped_rod_id = 1 WHERE id = :id');
        $stmt->execute([':id' => $playerId]);

        // Grant starter line: Twine (look up by name)
        $twineId = $pdo->query("SELECT id FROM line_types WHERE name = 'Twine' LIMIT 1")->fetchColumn();
        if ($twineId) {
            $stmt = $pdo->prepare('INSERT IGNORE INTO player_lines (player_id, line_id) VALUES (:pid, :lid)');
            $stmt->execute([':pid' => $playerId, ':lid' => $twineId]);
            $stmt = $pdo->prepare('UPDATE players SET equipped_line_id = :lid WHERE id = :id');
            $stmt->execute([':lid' => $twineId, ':id' => $playerId]);
        }

        // Grant starter bait: 20 bread dough (id=9)
        $stmt = $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity) VALUES (:pid, 9, 20)
        ');
        $stmt->execute([':pid' => $playerId]);

        return [
            'player_id'   => $playerId,
            'setup_token' => $setupToken,
            'setup_url'   => "/setup?token={$setupToken}",
            'message'     => 'Account created! Visit the setup URL to choose your username and password.',
        ];
    }

    /**
     * Complete web setup — player picks username and password.
     */
    public static function completeSetup(string $token, string $username, string $password): array {
        $pdo = db();

        // Validate token
        $stmt = $pdo->prepare('
            SELECT * FROM players
            WHERE setup_token = :token AND token_expires > NOW()
        ');
        $stmt->execute([':token' => $token]);
        $player = $stmt->fetch();

        if (!$player) {
            json_error('Invalid or expired setup token', 400);
        }

        // Validate username
        $username = trim($username);
        if (strlen($username) < 3 || strlen($username) > 64) {
            json_error('Username must be 3-64 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            json_error('Username can only contain letters, numbers, and underscores');
        }

        // Check username uniqueness
        $stmt = $pdo->prepare('SELECT id FROM players WHERE username = :u AND id != :id');
        $stmt->execute([':u' => $username, ':id' => $player['id']]);
        if ($stmt->fetch()) {
            json_error('Username already taken');
        }

        // Validate password
        if (strlen($password) < 6) {
            json_error('Password must be at least 6 characters');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare('
            UPDATE players
            SET username = :u, password_hash = :p, setup_token = NULL, token_expires = NULL
            WHERE id = :id
        ');
        $stmt->execute([
            ':u'  => $username,
            ':p'  => $hash,
            ':id' => $player['id'],
        ]);

        return ['message' => 'Account setup complete. You can now log in.'];
    }

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
            UPDATE players SET total_casts = total_casts + 1, last_fished = NOW()
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
