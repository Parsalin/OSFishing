<?php
/**
 * Admin.php - Admin panel management functions
 */

class Admin
{
    /**
     * Verify the current user is an admin. Returns player row or errors.
     */
    public static function requireAdmin(): array {
        $player = Auth::requireWeb();
        if (!(int)$player['is_admin']) {
            json_error('Admin access required', 403);
        }
        return $player;
    }

    // ═══════════════════════════════════════
    // PLAYER MANAGEMENT
    // ═══════════════════════════════════════

    public static function listPlayers(string $search = '', int $limit = 50, int $offset = 0): array {
        $pdo = db();
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE p.username LIKE :s OR p.display_name LIKE :s2 OR p.uuid LIKE :s3';
            $params = [':s' => "%$search%", ':s2' => "%$search%", ':s3' => "%$search%"];
        }
        $stmt = $pdo->prepare("
            SELECT p.id, p.uuid, p.username, p.display_name, p.level, p.xp,
                   p.fishing_points, p.total_casts, p.total_catches,
                   p.is_admin, p.is_banned, p.spot_limit_override,
                   p.equipped_line_id, p.equipped_bait_id,
                   p.last_fished, p.created_at,
                   lt.name AS line_name,
                   bt.name AS bait_name
            FROM players p
            LEFT JOIN line_types lt ON lt.id = p.equipped_line_id
            LEFT JOIN bait_types bt ON bt.id = p.equipped_bait_id
            $where
            ORDER BY p.id ASC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $players = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM players p $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        return ['players' => $players, 'total' => $total];
    }

    public static function getPlayer(int $playerId): array {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT p.*,
                   lt.name AS line_name, lt.weight_lb AS line_weight,
                   bt.name AS bait_name,
                   (SELECT COUNT(*) FROM hud_exclusion_triggers
                    WHERE player_id = p.id) AS exclusion_triggers
            FROM players p
            LEFT JOIN line_types lt ON lt.id = p.equipped_line_id
            LEFT JOIN bait_types bt ON bt.id = p.equipped_bait_id
            WHERE p.id = :id
        ');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found', 404);

        // Get their bait inventory
        $stmt = $pdo->prepare('
            SELECT bt.id, bt.name, pb.quantity
            FROM player_bait pb JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE pb.player_id = :pid ORDER BY bt.name
        ');
        $stmt->execute([':pid' => $playerId]);
        $bait = $stmt->fetchAll();

        // Get their lines
        $stmt = $pdo->prepare('
            SELECT lt.id, lt.name, lt.weight_lb, lt.visibility,
                   CASE WHEN p.equipped_line_id = lt.id THEN 1 ELSE 0 END AS equipped
            FROM player_lines pl
            JOIN line_types lt ON lt.id = pl.line_id
            JOIN players p ON p.id = pl.player_id
            WHERE pl.player_id = :pid ORDER BY lt.sort_order
        ');
        $stmt->execute([':pid' => $playerId]);
        $lines = $stmt->fetchAll();

        // Get their spots
        $stmt = $pdo->prepare('
            SELECT fs.id, fs.name, fs.region_name, fs.is_active,
                   wt.name AS water_type
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            WHERE fs.player_id = :pid AND fs.is_system = 0
            ORDER BY fs.created_at DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        $spots = $stmt->fetchAll();

        // Recent catches
        $stmt = $pdo->prepare('
            SELECT cl.weight, cl.caught_at, fs2.name AS species_name,
                   rt.name AS rarity_name
            FROM catch_log cl
            JOIN fish_species fs2 ON fs2.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = cl.rarity_id
            WHERE cl.player_id = :pid
            ORDER BY cl.caught_at DESC LIMIT 10
        ');
        $stmt->execute([':pid' => $playerId]);
        $catches = $stmt->fetchAll();

        // Remove sensitive fields
        unset($player['password_hash'], $player['setup_token'], $player['token_expires']);

        return [
            'player'  => $player,
            'bait'    => $bait,
            'lines'   => $lines,
            'spots'   => $spots,
            'catches' => $catches,
        ];
    }

    public static function updatePlayer(int $playerId, array $data): array {
        $pdo = db();
        $allowed = ['level','xp','fishing_points','is_admin','is_banned','spot_limit_override','display_name'];
        $sets = [];
        $params = [':id' => $playerId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if ($field === 'spot_limit_override' && ($val === '' || $val === 'null')) {
                    $sets[] = "$field = NULL";
                } else {
                    $sets[] = "$field = :$field";
                    $params[":$field"] = $val;
                }
            }
        }

        if (empty($sets)) json_error('Nothing to update');

        $sql = 'UPDATE players SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return ['message' => 'Player updated', 'rows_affected' => $stmt->rowCount()];
    }

    public static function grantBait(int $playerId, int $baitId, int $qty): array {
        $stmt = db()->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity)
            VALUES (:pid, :bid, :qty)
            ON DUPLICATE KEY UPDATE quantity = quantity + :qty2
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId, ':qty' => $qty, ':qty2' => $qty]);
        return ['message' => "Granted $qty bait"];
    }

    public static function grantLine(int $playerId, int $lineId): array {
        $stmt = db()->prepare('
            INSERT IGNORE INTO player_lines (player_id, line_id) VALUES (:pid, :lid)
        ');
        $stmt->execute([':pid' => $playerId, ':lid' => $lineId]);
        return ['message' => 'Line granted'];
    }

    // ═══════════════════════════════════════
    // FISHING SPOTS
    // ═══════════════════════════════════════

    public static function listSpots(string $search = '', string $view = 'active'): array {
        $pdo = db();
        $conditions = [];
        $params = [];
        // Archive filter
        if ($view === 'archived') {
            $conditions[] = 'fs.archived = 1';
        } else {
            $conditions[] = 'fs.archived = 0';
        }
        if ($search !== '') {
            $conditions[] = '(fs.name LIKE :s OR fs.region_name LIKE :s2 OR p.display_name LIKE :s3)';
            $params[':s']  = "%$search%";
            $params[':s2'] = "%$search%";
            $params[':s3'] = "%$search%";
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $stmt = $pdo->prepare("
            SELECT fs.*, wt.name AS water_type, p.display_name AS owner_name
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            LEFT JOIN players p ON p.id = fs.player_id
            $where
            ORDER BY fs.id ASC
        ");
        $stmt->execute($params);
        return ['spots' => $stmt->fetchAll()];
    }

    /**
     * Restore an archived spot to active list (inactive state).
     */
    public static function unarchiveSpot(int $spotId): array {
        db()->prepare('
            UPDATE fishing_spots
            SET archived = 0, archived_at = NULL, is_active = 0
            WHERE id = :id
        ')->execute([':id' => $spotId]);
        return ['message' => 'Spot un-archived (now inactive)'];
    }

    public static function updateSpot(int $spotId, array $data): array {
        $pdo = db();
        $allowed = ['name','water_type_id','is_active','is_system','player_id','region_name'];
        $sets = [];
        $params = [':id' => $spotId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $val = $data[$field];
                if ($field === 'player_id' && ($val === '' || $val === 'null')) {
                    $sets[] = "player_id = NULL";
                } else {
                    $sets[] = "$field = :$field";
                    $params[":$field"] = $val;
                }
            }
        }

        if (empty($sets)) json_error('Nothing to update');

        $sql = 'UPDATE fishing_spots SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return ['message' => 'Spot updated'];
    }

    public static function deleteSpot(int $spotId): array {
        $pdo = db();
        // Archive instead of delete — catch history and leaderboards stay intact
        $pdo->prepare('
            UPDATE fishing_spots
            SET archived = 1, archived_at = NOW(), is_active = 0
            WHERE id = :id
        ')->execute([':id' => $spotId]);
        return ['message' => 'Spot archived', 'deleted' => true];
    }

    // ═══════════════════════════════════════
    // GAME BALANCE
    // ═══════════════════════════════════════

    public static function listFishSpecies(): array {
        $stmt = db()->query('
            SELECT fs.*, rt.name AS rarity_name
            FROM fish_species fs
            JOIN rarity_tiers rt ON rt.id = fs.rarity_id
            ORDER BY fs.id
        ');
        return ['fish' => $stmt->fetchAll()];
    }

    public static function updateFish(int $fishId, array $data): array {
        $allowed = ['line_tolerance','min_weight','max_weight','fight_power','fight_stamina',
                     'fight_speed','fight_unpredictability','base_points','base_xp','is_active'];
        $sets = [];
        $params = [':id' => $fishId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($sets)) json_error('Nothing to update');

        $sql = 'UPDATE fish_species SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return ['message' => 'Fish updated'];
    }

    public static function listLineTypes(): array {
        return ['lines' => db()->query('SELECT * FROM line_types ORDER BY sort_order')->fetchAll()];
    }

    public static function updateLine(int $lineId, array $data): array {
        $allowed = ['weight_lb','visibility','min_level','cost_points','name','description'];
        $sets = [];
        $params = [':id' => $lineId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($sets)) json_error('Nothing to update');

        $sql = 'UPDATE line_types SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return ['message' => 'Line type updated'];
    }

    public static function listBaitTypes(): array {
        return ['bait' => db()->query('SELECT * FROM bait_types ORDER BY id')->fetchAll()];
    }

    public static function updateBait(int $baitId, array $data): array {
        $allowed = ['name','description','shop_price','shop_quantity','catch_rate_mod','rarity_mod','gather_location','shop_enabled'];
        $sets = [];
        $params = [':id' => $baitId];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                $params[":$field"] = $data[$field];
            }
        }

        if (empty($sets)) json_error('Nothing to update');

        $sql = 'UPDATE bait_types SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return ['message' => 'Bait type updated'];
    }

    public static function listBaitAffinity(int $fishId): array {
        $stmt = db()->prepare('
            SELECT fba.*, bt.name AS bait_name
            FROM fish_bait_affinity fba
            JOIN bait_types bt ON bt.id = fba.bait_id
            WHERE fba.fish_id = :fid
            ORDER BY bt.name
        ');
        $stmt->execute([':fid' => $fishId]);
        return ['affinities' => $stmt->fetchAll()];
    }

    public static function setBaitAffinity(int $fishId, int $baitId, float $affinity): array {
        $stmt = db()->prepare('
            INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity)
            VALUES (:fid, :bid, :aff)
            ON DUPLICATE KEY UPDATE affinity = :aff2
        ');
        $stmt->execute([':fid' => $fishId, ':bid' => $baitId, ':aff' => $affinity, ':aff2' => $affinity]);
        return ['message' => 'Affinity set'];
    }

    // ═══════════════════════════════════════
    // STATS & ANNOUNCEMENTS
    // ═══════════════════════════════════════

    public static function globalStats(): array {
        $pdo = db();
        return [
            'total_players'  => (int)$pdo->query('SELECT COUNT(*) FROM players')->fetchColumn(),
            'active_players' => (int)$pdo->query('SELECT COUNT(*) FROM players WHERE last_fished > DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
            'total_catches'  => (int)$pdo->query('SELECT COALESCE(SUM(total_catches),0) FROM players')->fetchColumn(),
            'total_casts'    => (int)$pdo->query('SELECT COALESCE(SUM(total_casts),0) FROM players')->fetchColumn(),
            'total_spots'    => (int)$pdo->query('SELECT COUNT(*) FROM fishing_spots WHERE is_active=1')->fetchColumn(),
            'total_fish_held'=> (int)$pdo->query("SELECT COUNT(*) FROM player_fish WHERE status='held'")->fetchColumn(),
        ];
    }

    public static function listAnnouncements(): array {
        return ['announcements' => db()->query('
            SELECT a.*, p.display_name AS author
            FROM announcements a
            LEFT JOIN players p ON p.id = a.created_by
            ORDER BY a.created_at DESC
        ')->fetchAll()];
    }

    public static function createAnnouncement(int $adminId, string $title, string $body, string $priority = 'info', ?string $expiresAt = null): array {
        $stmt = db()->prepare('
            INSERT INTO announcements (title, body, priority, created_by, expires_at)
            VALUES (:t, :b, :p, :aid, :exp)
        ');
        $stmt->execute([
            ':t' => $title, ':b' => $body, ':p' => $priority,
            ':aid' => $adminId, ':exp' => $expiresAt
        ]);
        return ['message' => 'Announcement created', 'id' => (int)db()->lastInsertId()];
    }

    public static function toggleAnnouncement(int $annId, bool $active): array {
        $stmt = db()->prepare('UPDATE announcements SET is_active = :a WHERE id = :id');
        $stmt->execute([':a' => $active ? 1 : 0, ':id' => $annId]);
        return ['message' => $active ? 'Activated' : 'Deactivated'];
    }

    public static function deleteAnnouncement(int $annId): array {
        $stmt = db()->prepare('DELETE FROM announcements WHERE id = :id');
        $stmt->execute([':id' => $annId]);
        return ['message' => 'Announcement deleted'];
    }
}
