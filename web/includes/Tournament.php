<?php
/**
 * Tournament.php - Tournament management and leaderboard queries
 */

class Tournament
{
    /**
     * Get tournament info + leaderboard.
     */
    public static function getLeaderboard(int $tournamentId, int $limit = 10): array {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT t.*, fs.name AS spot_name
            FROM tournaments t
            LEFT JOIN fishing_spots fs ON fs.id = t.spot_id
            WHERE t.id = :id
        ');
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch();
        if (!$tournament) json_error('Tournament not found', 404);

        // Auto-update status
        $now = time();
        $start = strtotime($tournament['start_time']);
        $end = strtotime($tournament['end_time']);
        $newStatus = $tournament['status'];

        if ($now < $start) $newStatus = 'upcoming';
        elseif ($now >= $start && $now < $end) $newStatus = 'active';
        else $newStatus = 'ended';

        if ($newStatus !== $tournament['status']) {
            $pdo->prepare('UPDATE tournaments SET status = :s WHERE id = :id')
                ->execute([':s' => $newStatus, ':id' => $tournamentId]);
            $tournament['status'] = $newStatus;
        }

        // Time remaining
        $remaining = max(0, $end - $now);
        $tournament['seconds_remaining'] = $remaining;
        $tournament['hours_remaining'] = round($remaining / 3600, 1);

        // Build leaderboard from catch_log within the time window
        $where = 'cl.caught_at >= :start_time AND cl.caught_at < :end_time';
        $params = [':start_time' => $tournament['start_time'], ':end_time' => $tournament['end_time']];

        if ($tournament['spot_id']) {
            $where .= ' AND cl.spot_id = :sid';
            $params[':sid'] = (int)$tournament['spot_id'];
        } elseif ($tournament['region_name']) {
            $where .= ' AND fs.region_name = :rn';
            $params[':rn'] = $tournament['region_name'];
        }

        if ($tournament['metric'] === 'weight') {
            $stmt = $pdo->prepare("
                SELECT p.display_name, p.username,
                       fsp.name AS fish_name,
                       cl.weight, cl.caught_at,
                       rt.name AS rarity_name
                FROM catch_log cl
                JOIN players p ON p.id = cl.player_id
                JOIN fish_species fsp ON fsp.id = cl.fish_species_id
                JOIN rarity_tiers rt ON rt.id = cl.rarity_id
                LEFT JOIN fishing_spots fs ON fs.id = cl.spot_id
                WHERE $where
                ORDER BY cl.weight DESC
                LIMIT :lim
            ");
        } else {
            $stmt = $pdo->prepare("
                SELECT p.display_name, p.username,
                       COUNT(*) AS total_catches,
                       MAX(cl.weight) AS best_weight
                FROM catch_log cl
                JOIN players p ON p.id = cl.player_id
                LEFT JOIN fishing_spots fs ON fs.id = cl.spot_id
                WHERE $where
                GROUP BY p.id
                ORDER BY total_catches DESC
                LIMIT :lim
            ");
        }
        $params[':lim'] = $limit;
        $stmt->execute($params);
        $leaderboard = $stmt->fetchAll();

        // Total participants
        $stmt2 = $pdo->prepare("
            SELECT COUNT(DISTINCT cl.player_id) AS participants
            FROM catch_log cl
            LEFT JOIN fishing_spots fs ON fs.id = cl.spot_id
            WHERE $where
        ");
        unset($params[':lim']);
        $stmt2->execute($params);
        $tournament['participants'] = (int)$stmt2->fetchColumn();

        return [
            'tournament'   => $tournament,
            'leaderboard'  => $leaderboard,
        ];
    }

    /**
     * Create a tournament.
     */
    public static function create(array $data): array {
        $name     = trim($data['name'] ?? '');
        $metric   = $data['metric'] ?? 'weight';
        $hours    = (float)($data['hours'] ?? 1);
        $spotId   = !empty($data['spot_id']) ? (int)$data['spot_id'] : null;
        $region   = trim($data['region_name'] ?? '');
        $grid     = trim($data['grid_name'] ?? '');
        $adminId  = !empty($data['admin_id']) ? (int)$data['admin_id'] : null;

        if ($name === '') json_error('Tournament name required');
        if ($hours < 0.25 || $hours > 168) json_error('Duration must be 0.25 to 168 hours');
        if (!in_array($metric, ['weight', 'catches'])) json_error('Metric must be weight or catches');

        $startTime = date('Y-m-d H:i:s');
        $endTime = date('Y-m-d H:i:s', time() + (int)($hours * 3600));

        $stmt = db()->prepare('
            INSERT INTO tournaments (name, metric, spot_id, region_name, grid_name, start_time, end_time, status, created_by)
            VALUES (:n, :m, :sid, :rn, :gn, :st, :et, \'active\', :aid)
        ');
        $stmt->execute([
            ':n' => $name, ':m' => $metric, ':sid' => $spotId,
            ':rn' => $region ?: null, ':gn' => $grid ?: null,
            ':st' => $startTime, ':et' => $endTime, ':aid' => $adminId,
        ]);

        return [
            'tournament_id' => (int)db()->lastInsertId(),
            'name' => $name,
            'end_time' => $endTime,
            'message' => "Tournament '$name' started! Ends in $hours hours.",
        ];
    }

    /**
     * List tournaments (active + recent ended).
     */
    public static function listAll(): array {
        $stmt = db()->query('
            SELECT t.*, fs.name AS spot_name
            FROM tournaments t
            LEFT JOIN fishing_spots fs ON fs.id = t.spot_id
            ORDER BY t.start_time DESC
            LIMIT 20
        ');
        return ['tournaments' => $stmt->fetchAll()];
    }

    /**
     * End a tournament early.
     */
    public static function endTournament(int $id): array {
        $stmt = db()->prepare('UPDATE tournaments SET end_time = NOW(), status = \'ended\' WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return ['message' => 'Tournament ended'];
    }

    /**
     * Delete a tournament.
     */
    public static function deleteTournament(int $id): array {
        db()->prepare('DELETE FROM tournaments WHERE id = :id')->execute([':id' => $id]);
        return ['message' => 'Tournament deleted'];
    }
}
