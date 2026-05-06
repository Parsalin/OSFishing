<?php
/**
 * Fishing Game - Quest Manager
 * Accept quests, track progress, turn in fish, claim rewards.
 */

require_once __DIR__ . '/../config.php';

class Quest {

    /**
     * Get all available quests for a player (level-appropriate, not already active/completed).
     */
    public static function getAvailable(int $playerId, int $playerLevel): array {
        $pdo = db();
        $season = self::getCurrentSeason();

        // Non-repeatable quests: exclude active and completed
        $stmt = $pdo->prepare('
            SELECT q.* FROM quests q
            WHERE q.is_active = 1
              AND q.min_level <= :lvl
              AND (q.season IS NULL OR q.season = :season)
              AND q.repeat_type = \'none\'
              AND q.id NOT IN (
                  SELECT quest_id FROM player_quests
                  WHERE player_id = :pid AND status IN (\'active\', \'completed\')
              )
            ORDER BY q.min_level, q.id
        ');
        $stmt->execute([':lvl' => $playerLevel, ':season' => $season, ':pid' => $playerId]);
        $quests = $stmt->fetchAll();

        // Repeatable quests: sorted by group + tier DESC so highest tier comes first
        // Exclude entire quest groups that already have an active quest
        $stmt = $pdo->prepare('
            SELECT q.* FROM quests q
            WHERE q.is_active = 1
              AND q.min_level <= :lvl
              AND (q.season IS NULL OR q.season = :season)
              AND q.repeat_type != \'none\'
              AND q.id NOT IN (
                  SELECT quest_id FROM player_quests
                  WHERE player_id = :pid AND status = \'active\'
              )
              AND (
                  q.quest_group IS NULL
                  OR q.quest_group NOT IN (
                      SELECT DISTINCT q2.quest_group FROM player_quests pq2
                      JOIN quests q2 ON q2.id = pq2.quest_id
                      WHERE pq2.player_id = :pid2 AND pq2.status = \'active\'
                      AND q2.quest_group IS NOT NULL
                  )
              )
            ORDER BY q.quest_group, q.quest_tier DESC
        ');
        $stmt->execute([':lvl' => $playerLevel, ':season' => $season, ':pid' => $playerId, ':pid2' => $playerId]);
        $repeatables = $stmt->fetchAll();

        // Group by quest_group — only keep highest tier per group
        $grouped = [];
        $ungrouped = [];
        foreach ($repeatables as $rq) {
            if (!empty($rq['quest_group'])) {
                if (!isset($grouped[$rq['quest_group']])) {
                    $grouped[$rq['quest_group']] = $rq;
                }
            } else {
                $ungrouped[] = $rq;
            }
        }
        $repeatablesToCheck = array_merge(array_values($grouped), $ungrouped);

        foreach ($repeatablesToCheck as $rq) {
            // Check last completion — for grouped quests, check any tier in the same group
            if (!empty($rq['quest_group'])) {
                $stmt = $pdo->prepare('
                    SELECT MAX(pq.completed_at) AS last_done
                    FROM player_quests pq
                    JOIN quests q ON q.id = pq.quest_id
                    WHERE pq.player_id = :pid AND pq.status = \'completed\'
                      AND q.quest_group = :grp
                ');
                $stmt->execute([':pid' => $playerId, ':grp' => $rq['quest_group']]);
            } else {
                $stmt = $pdo->prepare('
                    SELECT MAX(completed_at) AS last_done
                    FROM player_quests
                    WHERE player_id = :pid AND quest_id = :qid AND status = \'completed\'
                ');
                $stmt->execute([':pid' => $playerId, ':qid' => $rq['id']]);
            }
            $lastDone = $stmt->fetchColumn();

            $canAccept = true;
            if ($lastDone) {
                if ($rq['repeat_type'] === 'daily') {
                    $canAccept = date('Y-m-d', strtotime($lastDone)) !== date('Y-m-d');
                } elseif ($rq['repeat_type'] === 'weekly') {
                    $thisMonday = date('Y-m-d', strtotime('monday this week'));
                    $canAccept = date('Y-m-d', strtotime($lastDone)) < $thisMonday;
                }
            }

            if ($canAccept) {
                $rq['is_daily']  = ($rq['repeat_type'] === 'daily');
                $rq['is_weekly'] = ($rq['repeat_type'] === 'weekly');
                $quests[] = $rq;
            }
        }

        // Attach requirements and rewards
        foreach ($quests as &$q) {
            $q['requirements'] = self::getRequirements((int)$q['id']);
            $q['rewards']      = self::getRewards((int)$q['id']);
        }

        return $quests;
    }

    /**
     * Get a player's active quests with progress.
     */
    public static function getActive(int $playerId): array {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT pq.*, q.title, q.description, q.quest_type, q.time_limit,
                   q.repeat_type, q.quest_group, q.quest_tier
            FROM player_quests pq
            JOIN quests q ON q.id = pq.quest_id
            WHERE pq.player_id = :pid AND pq.status = \'active\'
            ORDER BY pq.accepted_at DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        $activeQuests = $stmt->fetchAll();

        foreach ($activeQuests as &$aq) {
            $aq['requirements'] = self::getRequirements((int)$aq['quest_id']);
            $aq['rewards']      = self::getRewards((int)$aq['quest_id']);
            $aq['progress']     = self::getProgress((int)$aq['id']);

            // Check if timed and expired
            if ($aq['expires_at'] && strtotime($aq['expires_at']) < time()) {
                self::failQuest((int)$aq['id']);
                $aq['status'] = 'failed';
            }
        }

        return $activeQuests;
    }

    /**
     * Accept a quest.
     */
    public static function accept(int $playerId, int $questId, int $playerLevel): array {
        $pdo = db();

        // Validate quest
        $stmt = $pdo->prepare('SELECT * FROM quests WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $questId]);
        $quest = $stmt->fetch();

        if (!$quest) {
            json_error('Quest not found');
        }

        if ($playerLevel < (int)$quest['min_level']) {
            json_error("Requires level {$quest['min_level']}");
        }

        // Check if already active
        $stmt = $pdo->prepare('
            SELECT id FROM player_quests
            WHERE player_id = :pid AND quest_id = :qid AND status = \'active\'
        ');
        $stmt->execute([':pid' => $playerId, ':qid' => $questId]);
        if ($stmt->fetch()) {
            json_error('Quest already active');
        }

        // Check if another quest from the same group is already active
        if (!empty($quest['quest_group'])) {
            $stmt = $pdo->prepare('
                SELECT pq.id FROM player_quests pq
                JOIN quests q ON q.id = pq.quest_id
                WHERE pq.player_id = :pid AND pq.status = \'active\'
                  AND q.quest_group = :grp
            ');
            $stmt->execute([':pid' => $playerId, ':grp' => $quest['quest_group']]);
            if ($stmt->fetch()) {
                json_error('You already have a ' . $quest['quest_group'] . ' quest active. Complete or abandon it first.');
            }
        }

        // Check chain prerequisite
        if ($quest['chain_id'] && (int)$quest['chain_order'] > 1) {
            $stmt = $pdo->prepare('
                SELECT q.id FROM quests q
                JOIN player_quests pq ON pq.quest_id = q.id AND pq.player_id = :pid
                WHERE q.chain_id = :cid AND q.chain_order = :co AND pq.status = \'completed\'
            ');
            $stmt->execute([
                ':pid' => $playerId,
                ':cid' => $quest['chain_id'],
                ':co'  => (int)$quest['chain_order'] - 1,
            ]);
            if (!$stmt->fetch()) {
                json_error('Complete the previous quest in this chain first');
            }
        }

        // Calculate expiry for timed quests
        $expiresAt = null;
        if ($quest['time_limit']) {
            $expiresAt = date('Y-m-d H:i:s', time() + ((int)$quest['time_limit'] * 60));
        }

        // Create player quest entry
        $stmt = $pdo->prepare('
            INSERT INTO player_quests (player_id, quest_id, expires_at)
            VALUES (:pid, :qid, :exp)
        ');
        $stmt->execute([':pid' => $playerId, ':qid' => $questId, ':exp' => $expiresAt]);
        $playerQuestId = (int)$pdo->lastInsertId();

        // Initialize progress for each requirement
        $requirements = self::getRequirements($questId);
        foreach ($requirements as $req) {
            $stmt = $pdo->prepare('
                INSERT INTO player_quest_progress (player_quest_id, requirement_id, current_count)
                VALUES (:pqid, :rid, 0)
            ');
            $stmt->execute([':pqid' => $playerQuestId, ':rid' => $req['id']]);
        }

        // Tutorial advancement: notify HUD if waiting on this step
        Tutorial::pushEvent($playerId, 'quest_accepted', [
            'quest_title' => $quest['title'],
        ]);

        return [
            'player_quest_id' => $playerQuestId,
            'quest_title'     => $quest['title'],
            'expires_at'      => $expiresAt,
            'message'         => "Quest accepted: {$quest['title']}",
        ];
    }

    /**
     * Auto-track quest progress when a fish is caught.
     * Called from Fishing::confirmCatch() after every successful catch.
     * Does NOT consume fish — just increments counters for matching requirements.
     *
     * Returns array of progress updates for the HUD to display.
     */
    public static function trackCatch(int $playerId, int $fishSpeciesId, float $weight, int $spotId, ?int $baitUsedId, ?int $waterTypeId, ?int $rarityId = null): array {
        $pdo = db();
        $updates = [];

        // Get all active quests for this player
        $stmt = $pdo->prepare('
            SELECT pq.id AS player_quest_id, pq.quest_id, q.title, q.quest_type
            FROM player_quests pq
            JOIN quests q ON q.id = pq.quest_id
            WHERE pq.player_id = :pid AND pq.status = \'active\'
        ');
        $stmt->execute([':pid' => $playerId]);
        $activeQuests = $stmt->fetchAll();

        foreach ($activeQuests as $aq) {
            $pqId = (int)$aq['player_quest_id'];
            $questId = (int)$aq['quest_id'];

            // Rarity-based quests: per-requirement min_rarity_id handles filtering below

            // Get incomplete catch-type requirements for this quest
            $stmt = $pdo->prepare('
                SELECT qr.*, pqp.id AS progress_id, pqp.current_count, pqp.is_complete
                FROM quest_requirements qr
                JOIN player_quest_progress pqp ON pqp.requirement_id = qr.id AND pqp.player_quest_id = :pqid
                WHERE qr.quest_id = :qid AND pqp.is_complete = 0
                  AND (qr.track_type = \'catch\' OR qr.track_type IS NULL)
            ');
            $stmt->execute([':pqid' => $pqId, ':qid' => $questId]);
            $requirements = $stmt->fetchAll();

            foreach ($requirements as $req) {
                // Check if this catch matches the requirement

                // Species filter
                if ($req['fish_species_id'] && (int)$req['fish_species_id'] !== $fishSpeciesId) continue;

                // Water type filter
                if ($req['water_type_id'] && (int)$req['water_type_id'] !== $waterTypeId) continue;

                // Bait filter
                if ($req['bait_required_id'] && (int)$req['bait_required_id'] !== $baitUsedId) continue;

                // Spot filter
                if ($req['spot_id'] && (int)$req['spot_id'] !== $spotId) continue;

                // Weight filter
                if ($req['min_weight'] && $weight < (float)$req['min_weight']) continue;

                // Rarity filter (per-requirement min_rarity_id)
                if (!empty($req['min_rarity_id']) && $rarityId !== null && $rarityId < (int)$req['min_rarity_id']) continue;

                // This catch matches — increment progress
                $newCount = (int)$req['current_count'] + 1;
                $isComplete = $newCount >= (int)$req['quantity'] ? 1 : 0;

                $stmt = $pdo->prepare('
                    UPDATE player_quest_progress
                    SET current_count = :cnt, is_complete = :done
                    WHERE id = :id
                ');
                $stmt->execute([
                    ':cnt'  => $newCount,
                    ':done' => $isComplete,
                    ':id'   => $req['progress_id'],
                ]);

                $updates[] = [
                    'quest_title' => $aq['title'],
                    'requirement' => $req['description'] ?? 'Requirement',
                    'progress'    => $newCount . '/' . $req['quantity'],
                    'complete'    => (bool)$isComplete,
                ];
            }

            // Check if quest is now ready to turn in (all requirements complete)
            if (!empty($updates)) {
                $allDone = self::checkAllComplete($pqId);
                if ($allDone) {
                    $updates[] = [
                        'quest_title'  => $aq['title'],
                        'ready_to_claim' => true,
                        'message'      => "Quest ready: {$aq['title']}! Turn it in to claim rewards!",
                    ];
                }
            }
        }

        return $updates;
    }

    /**
     * Auto-track quest progress when bait is gathered.
     * Called from GatherPoint::gather() after each gather tick.
     * Only updates 'gather' type requirements.
     */
    public static function trackGather(int $playerId, int $amount): array {
        $pdo = db();
        $updates = [];

        $stmt = $pdo->prepare('
            SELECT pq.id AS player_quest_id, pq.quest_id, q.title
            FROM player_quests pq
            JOIN quests q ON q.id = pq.quest_id
            WHERE pq.player_id = :pid AND pq.status = \'active\'
        ');
        $stmt->execute([':pid' => $playerId]);
        $activeQuests = $stmt->fetchAll();

        foreach ($activeQuests as $aq) {
            $pqId = (int)$aq['player_quest_id'];
            $questId = (int)$aq['quest_id'];

            $stmt = $pdo->prepare('
                SELECT qr.*, pqp.id AS progress_id, pqp.current_count, pqp.is_complete
                FROM quest_requirements qr
                JOIN player_quest_progress pqp ON pqp.requirement_id = qr.id AND pqp.player_quest_id = :pqid
                WHERE qr.quest_id = :qid AND pqp.is_complete = 0 AND qr.track_type = \'gather\'
            ');
            $stmt->execute([':pqid' => $pqId, ':qid' => $questId]);
            $requirements = $stmt->fetchAll();

            foreach ($requirements as $req) {
                $newCount = min((int)$req['current_count'] + $amount, (int)$req['quantity']);
                $isComplete = $newCount >= (int)$req['quantity'] ? 1 : 0;

                $stmt = $pdo->prepare('
                    UPDATE player_quest_progress
                    SET current_count = :cnt, is_complete = :done
                    WHERE id = :id
                ');
                $stmt->execute([':cnt' => $newCount, ':done' => $isComplete, ':id' => $req['progress_id']]);

                $updates[] = [
                    'quest_title' => $aq['title'],
                    'progress'    => $newCount . '/' . $req['quantity'],
                    'complete'    => (bool)$isComplete,
                ];

                if ($isComplete && self::checkAllComplete($pqId)) {
                    $updates[] = [
                        'quest_title'    => $aq['title'],
                        'ready_to_claim' => true,
                        'message'        => "Quest ready: {$aq['title']}! Turn it in!",
                    ];
                }
            }
        }

        return $updates;
    }

    /**
     * Claim rewards for a completed quest (all requirements auto-tracked to 100%).
     * Does NOT consume fish — just grants rewards.
     */
    public static function claimRewards(int $playerId, int $playerQuestId): array {
        $pdo = db();

        // Validate quest ownership and status
        $stmt = $pdo->prepare('
            SELECT pq.*, q.title FROM player_quests pq
            JOIN quests q ON q.id = pq.quest_id
            WHERE pq.id = :id AND pq.player_id = :pid AND pq.status = \'active\'
        ');
        $stmt->execute([':id' => $playerQuestId, ':pid' => $playerId]);
        $pq = $stmt->fetch();

        if (!$pq) json_error('Quest not found or not active');

        // Check all requirements are complete
        if (!self::checkAllComplete($playerQuestId)) {
            json_error('Quest requirements not yet complete');
        }

        // Grant rewards and mark complete
        $rewards = self::completeQuest($playerId, $playerQuestId, (int)$pq['quest_id']);

        return [
            'quest_title' => $pq['title'],
            'rewards'     => $rewards,
            'message'     => "Quest complete: {$pq['title']}!",
        ];
    }

    /**
     * Turn in fish toward a quest requirement.
     * Consumes fish from inventory.
     */
    public static function turnInFish(int $playerId, int $playerQuestId, int $requirementId, array $fishIds): array {
        $pdo = db();
        $pdo->beginTransaction();

        try {
            // Validate quest ownership and status
            $stmt = $pdo->prepare('
                SELECT pq.*, q.title FROM player_quests pq
                JOIN quests q ON q.id = pq.quest_id
                WHERE pq.id = :id AND pq.player_id = :pid AND pq.status = \'active\'
            ');
            $stmt->execute([':id' => $playerQuestId, ':pid' => $playerId]);
            $pq = $stmt->fetch();

            if (!$pq) {
                $pdo->rollBack();
                json_error('Quest not active');
            }

            // Get the requirement
            $stmt = $pdo->prepare('SELECT * FROM quest_requirements WHERE id = :id AND quest_id = :qid');
            $stmt->execute([':id' => $requirementId, ':qid' => $pq['quest_id']]);
            $req = $stmt->fetch();

            if (!$req) {
                $pdo->rollBack();
                json_error('Invalid requirement');
            }

            // Get current progress
            $stmt = $pdo->prepare('
                SELECT * FROM player_quest_progress
                WHERE player_quest_id = :pqid AND requirement_id = :rid
            ');
            $stmt->execute([':pqid' => $playerQuestId, ':rid' => $requirementId]);
            $progress = $stmt->fetch();

            if ((int)$progress['is_complete']) {
                $pdo->rollBack();
                json_error('This requirement is already complete');
            }

            $needed = (int)$req['quantity'] - (int)$progress['current_count'];
            $accepted = 0;

            foreach ($fishIds as $fishId) {
                if ($accepted >= $needed) break;

                // Get fish and validate against requirement
                $stmt = $pdo->prepare('
                    SELECT * FROM player_fish
                    WHERE id = :id AND player_id = :pid AND status = \'held\'
                ');
                $stmt->execute([':id' => (int)$fishId, ':pid' => $playerId]);
                $fish = $stmt->fetch();

                if (!$fish) continue;

                // Check species match
                if ($req['fish_species_id'] && (int)$fish['fish_species_id'] !== (int)$req['fish_species_id']) continue;

                // Check weight threshold
                if ($req['min_weight'] && (float)$fish['weight'] < (float)$req['min_weight']) continue;

                // Check spot requirement
                if ($req['spot_id'] && (int)$fish['spot_id'] !== (int)$req['spot_id']) continue;

                // Check bait requirement
                if ($req['bait_required_id'] && (int)$fish['bait_used_id'] !== (int)$req['bait_required_id']) continue;

                // Fish qualifies — consume it
                $stmt = $pdo->prepare('
                    UPDATE player_fish
                    SET status = \'quest\', quest_id = :qid
                    WHERE id = :id
                ');
                $stmt->execute([':qid' => $pq['quest_id'], ':id' => $fish['id']]);
                $accepted++;
            }

            if ($accepted === 0) {
                $pdo->rollBack();
                json_error('None of those fish meet the requirement');
            }

            // Update progress
            $newCount = (int)$progress['current_count'] + $accepted;
            $isComplete = $newCount >= (int)$req['quantity'] ? 1 : 0;

            $stmt = $pdo->prepare('
                UPDATE player_quest_progress
                SET current_count = :cnt, is_complete = :done
                WHERE player_quest_id = :pqid AND requirement_id = :rid
            ');
            $stmt->execute([
                ':cnt'  => $newCount,
                ':done' => $isComplete,
                ':pqid' => $playerQuestId,
                ':rid'  => $requirementId,
            ]);

            // Check if all requirements are now complete
            $allComplete = self::checkAllComplete($playerQuestId);
            $rewards = null;

            if ($allComplete) {
                $rewards = self::completeQuest($playerId, $playerQuestId, $pq['quest_id']);
            }

            $pdo->commit();

            return [
                'fish_accepted'  => $accepted,
                'progress'       => "{$newCount}/{$req['quantity']}",
                'req_complete'   => (bool)$isComplete,
                'quest_complete' => $allComplete,
                'rewards'        => $rewards,
                'message'        => $allComplete
                    ? "Quest complete: {$pq['title']}!"
                    : "Turned in {$accepted} fish. Progress: {$newCount}/{$req['quantity']}",
            ];

        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Check if all requirements for a player quest are complete.
     */
    private static function checkAllComplete(int $playerQuestId): bool {
        $stmt = db()->prepare('
            SELECT COUNT(*) as total,
                   SUM(is_complete) as done
            FROM player_quest_progress
            WHERE player_quest_id = :pqid
        ');
        $stmt->execute([':pqid' => $playerQuestId]);
        $row = $stmt->fetch();
        return (int)$row['total'] === (int)$row['done'];
    }

    /**
     * Complete a quest and grant rewards.
     */
    private static function completeQuest(int $playerId, int $playerQuestId, int $questId): array {
        $pdo = db();

        // Mark complete
        $stmt = $pdo->prepare('
            UPDATE player_quests SET status = \'completed\', completed_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([':id' => $playerQuestId]);

        // Get and grant rewards
        $rewards = self::getRewards($questId);
        $granted = [];

        foreach ($rewards as $r) {
            switch ($r['reward_type']) {
                case 'points':
                    Player::addPoints($playerId, (int)$r['reward_value']);
                    $granted[] = "{$r['reward_value']} Fishing Points";
                    break;

                case 'xp':
                    $lvl = Player::awardXP($playerId, (int)$r['reward_value']);
                    $granted[] = "{$r['reward_value']} XP";
                    if ($lvl) $granted[] = "Level up! Now level {$lvl['new_level']}";
                    break;

                case 'bait':
                    if ($r['reward_ref_id']) {
                        $stmt = $pdo->prepare('
                            INSERT INTO player_bait (player_id, bait_id, quantity)
                            VALUES (:pid, :bid, :qty)
                            ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + :qty2, :max)
                        ');
                        $stmt->execute([
                            ':pid' => $playerId,
                            ':bid' => $r['reward_ref_id'],
                            ':qty' => $r['reward_value'],
                            ':qty2'=> $r['reward_value'],
                            ':max' => MAX_BAIT_STACK,
                        ]);
                        $granted[] = "{$r['reward_value']}x bait";
                    }
                    break;

                case 'rod':
                    if ($r['reward_ref_id']) {
                        $stmt = $pdo->prepare('
                            INSERT IGNORE INTO player_rods (player_id, rod_id)
                            VALUES (:pid, :rid)
                        ');
                        $stmt->execute([':pid' => $playerId, ':rid' => $r['reward_ref_id']]);
                        $granted[] = "New rod unlocked!";
                    }
                    break;

                case 'title':
                    if ($r['reward_title']) {
                        $stmt = $pdo->prepare('
                            INSERT IGNORE INTO player_titles (player_id, title, source)
                            VALUES (:pid, :title, \'quest\')
                        ');
                        $stmt->execute([':pid' => $playerId, ':title' => $r['reward_title']]);
                        $granted[] = "Title earned: {$r['reward_title']}";
                    }
                    break;

                case 'buff_item':
                    if ($r['reward_ref_id']) {
                        $qty = max(1, (int)$r['reward_value']);
                        Buff::grantItem($playerId, (int)$r['reward_ref_id'], $qty);
                        // Get item name
                        $stmt = $pdo->prepare('SELECT name FROM buff_items WHERE id = :id');
                        $stmt->execute([':id' => $r['reward_ref_id']]);
                        $itemName = $stmt->fetchColumn() ?: 'buff item';
                        $granted[] = "{$qty}x {$itemName}";
                    }
                    break;
            }
        }

        return $granted;
    }

    /**
     * Fail a quest (timed out or abandoned).
     */
    private static function failQuest(int $playerQuestId): void {
        $stmt = db()->prepare('UPDATE player_quests SET status = \'failed\' WHERE id = :id');
        $stmt->execute([':id' => $playerQuestId]);
    }

    /**
     * Abandon a quest voluntarily.
     */
    public static function abandon(int $playerId, int $playerQuestId): array {
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT pq.*, q.title FROM player_quests pq
            JOIN quests q ON q.id = pq.quest_id
            WHERE pq.id = :id AND pq.player_id = :pid AND pq.status = \'active\'
        ');
        $stmt->execute([':id' => $playerQuestId, ':pid' => $playerId]);
        $pq = $stmt->fetch();

        if (!$pq) {
            json_error('Quest not found or not active');
        }

        // Return any fish consumed by this quest back to held
        $stmt = $pdo->prepare('
            UPDATE player_fish SET status = \'held\', quest_id = NULL
            WHERE player_id = :pid AND quest_id = :qid AND status = \'quest\'
        ');
        $stmt->execute([':pid' => $playerId, ':qid' => $pq['quest_id']]);
        $returned = $stmt->rowCount();

        $stmt = $pdo->prepare('UPDATE player_quests SET status = \'abandoned\' WHERE id = :id');
        $stmt->execute([':id' => $playerQuestId]);

        return [
            'message'       => "Abandoned quest: {$pq['title']}",
            'fish_returned' => $returned,
        ];
    }

    /**
     * Get completed quests history.
     */
    public static function getCompleted(int $playerId): array {
        $pdo = db();

        // Get unique completed quests with count, most recent first
        $stmt = $pdo->prepare('
            SELECT
                q.id AS quest_id,
                q.title,
                q.description,
                q.repeat_type,
                q.quest_group,
                q.quest_tier,
                COUNT(pq.id) AS times_completed,
                MAX(pq.completed_at) AS completed_at
            FROM player_quests pq
            JOIN quests q ON q.id = pq.quest_id
            WHERE pq.player_id = :pid AND pq.status = \'completed\'
            GROUP BY q.id
            ORDER BY MAX(pq.completed_at) DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    // ── Helpers ──

    private static function getRequirements(int $questId): array {
        $stmt = db()->prepare('
            SELECT qr.*, fs.name as fish_name, bt.name as bait_name, fspot.name as spot_name
            FROM quest_requirements qr
            LEFT JOIN fish_species fs ON fs.id = qr.fish_species_id
            LEFT JOIN bait_types bt ON bt.id = qr.bait_required_id
            LEFT JOIN fishing_spots fspot ON fspot.id = qr.spot_id
            WHERE qr.quest_id = :qid
        ');
        $stmt->execute([':qid' => $questId]);
        return $stmt->fetchAll();
    }

    private static function getRewards(int $questId): array {
        $stmt = db()->prepare('SELECT * FROM quest_rewards WHERE quest_id = :qid');
        $stmt->execute([':qid' => $questId]);
        return $stmt->fetchAll();
    }

    private static function getProgress(int $playerQuestId): array {
        $stmt = db()->prepare('
            SELECT pqp.*, qr.description as req_description, qr.quantity as required_qty,
                   fs.name as fish_name
            FROM player_quest_progress pqp
            JOIN quest_requirements qr ON qr.id = pqp.requirement_id
            LEFT JOIN fish_species fs ON fs.id = qr.fish_species_id
            WHERE pqp.player_quest_id = :pqid
        ');
        $stmt->execute([':pqid' => $playerQuestId]);
        return $stmt->fetchAll();
    }

    private static function getCurrentSeason(): string {
        $month = (int)date('n');
        if ($month >= 3 && $month <= 5) return 'spring';
        if ($month >= 6 && $month <= 8) return 'summer';
        if ($month >= 9 && $month <= 11) return 'fall';
        return 'winter';
    }
}
