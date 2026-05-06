<?php
/**
 * Fishing Game - Bait Manager
 * Handles bait gathering, equipping, and inventory queries.
 */

require_once __DIR__ . '/../config.php';

class Bait {

    /**
     * Get all bait owned by a player with type details.
     */
    public static function getInventory(int $playerId): array {
        $stmt = db()->prepare('
            SELECT bt.*, pb.quantity
            FROM player_bait pb
            JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE pb.player_id = :pid AND pb.quantity > 0
            ORDER BY bt.name
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Equip a bait type (must own at least 1).
     */
    public static function equip(int $playerId, int $baitId): array {
        $pdo = db();

        // Verify ownership
        $stmt = $pdo->prepare('
            SELECT pb.quantity, bt.name FROM player_bait pb
            JOIN bait_types bt ON bt.id = pb.bait_id
            WHERE pb.player_id = :pid AND pb.bait_id = :bid
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        $row = $stmt->fetch();

        if (!$row || (int)$row['quantity'] <= 0) {
            json_error('You do not have that bait');
        }

        $stmt = $pdo->prepare('UPDATE players SET equipped_bait_id = :bid WHERE id = :pid');
        $stmt->execute([':bid' => $baitId, ':pid' => $playerId]);

        return [
            'equipped_bait_id' => $baitId,
            'bait_name'        => $row['name'],
            'quantity'         => (int)$row['quantity'],
        ];
    }

    /**
     * Gather bait from an in-world gathering point.
     * Validates cooldown server-side.
     */
    public static function gather(int $playerId, int $baitId, string $spotKey): array {
        $pdo = db();

        // Validate bait type
        $stmt = $pdo->prepare('SELECT * FROM bait_types WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $baitId]);
        $bait = $stmt->fetch();

        if (!$bait) {
            json_error('Invalid bait type');
        }

        // Check cooldown
        $cooldown = (int)($bait['gather_cooldown'] ?: DEFAULT_GATHER_COOLDOWN);

        $stmt = $pdo->prepare('
            SELECT last_gathered FROM gather_cooldowns
            WHERE player_id = :pid AND bait_id = :bid AND spot_key = :sk
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId, ':sk' => $spotKey]);
        $lastGather = $stmt->fetch();

        if ($lastGather) {
            $elapsed = time() - strtotime($lastGather['last_gathered']);
            if ($elapsed < $cooldown) {
                $remaining = $cooldown - $elapsed;
                json_error("Please wait {$remaining} seconds before gathering again");
            }
        }

        // Roll quantity
        $minYield = (int)$bait['gather_min'];
        $maxYield = (int)$bait['gather_max'];
        $quantity = rand($minYield, $maxYield);

        // Add to inventory (cap at MAX_BAIT_STACK)
        $stmt = $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity)
            VALUES (:pid, :bid, :qty)
            ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + :qty2, :max)
        ');
        $stmt->execute([
            ':pid' => $playerId,
            ':bid' => $baitId,
            ':qty' => $quantity,
            ':qty2'=> $quantity,
            ':max' => MAX_BAIT_STACK,
        ]);

        // Update cooldown
        $stmt = $pdo->prepare('
            INSERT INTO gather_cooldowns (player_id, bait_id, spot_key, last_gathered)
            VALUES (:pid, :bid, :sk, NOW())
            ON DUPLICATE KEY UPDATE last_gathered = NOW()
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId, ':sk' => $spotKey]);

        // Get new total
        $stmt = $pdo->prepare('
            SELECT quantity FROM player_bait WHERE player_id = :pid AND bait_id = :bid
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $baitId]);
        $newTotal = (int)$stmt->fetchColumn();

        return [
            'bait_name' => $bait['name'],
            'gathered'  => $quantity,
            'total'     => $newTotal,
            'message'   => "You gathered {$quantity} {$bait['name']}!",
        ];
    }

    /**
     * Cut a caught fish into bait at the bait prep table.
     * Consumes a fish from player inventory, produces cut bait.
     */
    public static function cutFishIntoBait(int $playerId, int $playerFishId): array {
        $pdo = db();

        // Get the fish
        $stmt = $pdo->prepare('
            SELECT pf.*, fs.name as species_name, fs.min_weight, fs.max_weight
            FROM player_fish pf
            JOIN fish_species fs ON fs.id = pf.fish_species_id
            WHERE pf.id = :id AND pf.player_id = :pid AND pf.status = \'held\'
        ');
        $stmt->execute([':id' => $playerFishId, ':pid' => $playerId]);
        $fish = $stmt->fetch();

        if (!$fish) {
            json_error('Fish not found in your inventory');
        }

        // Calculate bait yield based on fish weight
        $weight = (float)$fish['weight'];
        $yield = max(2, (int)floor($weight * 1.5));

        // Remove fish from inventory
        $stmt = $pdo->prepare('
            UPDATE player_fish SET status = \'sold\', sold_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([':id' => $playerFishId]);

        // Add cut bait (id=5)
        $stmt = $pdo->prepare('
            INSERT INTO player_bait (player_id, bait_id, quantity)
            VALUES (:pid, 5, :qty)
            ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + :qty2, :max)
        ');
        $stmt->execute([
            ':pid' => $playerId,
            ':qty' => $yield,
            ':qty2'=> $yield,
            ':max' => MAX_BAIT_STACK,
        ]);

        return [
            'fish_used'  => $fish['species_name'],
            'bait_gained'=> $yield,
            'message'    => "Cut up {$fish['species_name']} into {$yield} pieces of Cut Bait.",
        ];
    }

    /**
     * Get all bait types (for shop display, info pages).
     */
    public static function getAllTypes(): array {
        $stmt = db()->prepare('SELECT * FROM bait_types WHERE is_active = 1 ORDER BY id');
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
