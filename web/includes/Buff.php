<?php
/**
 * Buff.php - Spot buff system
 *
 * Buff items are held in player inventory (player_buff_items).
 * Players activate buffs on fishing spots (spot_buffs).
 * Same buff type stacks duration additively, max 2 hours.
 * Different buff types all active simultaneously.
 */

class Buff
{
    const MAX_DURATION_MINS = 120; // 2 hours max per buff type

    /**
     * Get all buff item definitions.
     */
    public static function listItems(): array {
        $stmt = db()->query('SELECT * FROM buff_items WHERE is_active = 1 ORDER BY source, min_level, name');
        return ['buff_items' => $stmt->fetchAll()];
    }

    /**
     * Get player's buff item inventory.
     */
    public static function playerInventory(int $playerId): array {
        $stmt = db()->prepare('
            SELECT pbi.id, pbi.quantity, bi.id AS buff_item_id, bi.name, bi.buff_type,
                   bi.effect_value, bi.duration_mins, bi.description, bi.source
            FROM player_buff_items pbi
            JOIN buff_items bi ON bi.id = pbi.buff_item_id
            WHERE pbi.player_id = :pid AND pbi.quantity > 0
            ORDER BY bi.name
        ');
        $stmt->execute([':pid' => $playerId]);
        return ['buff_inventory' => $stmt->fetchAll()];
    }

    /**
     * Grant a buff item to a player.
     */
    public static function grantItem(int $playerId, int $buffItemId, int $quantity = 1): array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT name FROM buff_items WHERE id = :id');
        $stmt->execute([':id' => $buffItemId]);
        $item = $stmt->fetch();
        if (!$item) json_error('Buff item not found');

        $pdo->prepare('
            INSERT INTO player_buff_items (player_id, buff_item_id, quantity)
            VALUES (:pid, :bid, :qty)
            ON DUPLICATE KEY UPDATE quantity = quantity + :qty2
        ')->execute([':pid' => $playerId, ':bid' => $buffItemId, ':qty' => $quantity, ':qty2' => $quantity]);

        return ['message' => "Granted {$quantity}x {$item['name']}", 'item_name' => $item['name'], 'quantity' => $quantity];
    }

    /**
     * Get active buffs on a spot with time remaining.
     */
    public static function getSpotBuffs(int $spotId): array {
        $pdo = db();

        // Clean expired buffs
        $pdo->prepare('DELETE FROM spot_buffs WHERE expires_at <= NOW()')->execute();

        $stmt = $pdo->prepare('
            SELECT sb.id, sb.buff_type, sb.buff_value, sb.activated_at, sb.expires_at,
                   bi.name AS buff_name, bi.description,
                   p.display_name AS activated_by_name,
                   TIMESTAMPDIFF(MINUTE, NOW(), sb.expires_at) AS mins_remaining,
                   TIMESTAMPDIFF(SECOND, NOW(), sb.expires_at) AS secs_remaining
            FROM spot_buffs sb
            JOIN buff_items bi ON bi.buff_type = sb.buff_type AND bi.is_active = 1
            LEFT JOIN players p ON p.id = sb.activated_by
            WHERE sb.spot_id = :sid AND sb.expires_at > NOW()
            GROUP BY sb.buff_type
            ORDER BY sb.expires_at ASC
        ');
        $stmt->execute([':sid' => $spotId]);
        $buffs = $stmt->fetchAll();

        // Calculate total remaining per buff type
        $result = [];
        foreach ($buffs as $b) {
            $result[] = [
                'buff_type' => $b['buff_type'],
                'buff_name' => $b['buff_name'],
                'buff_value' => (float)$b['buff_value'],
                'description' => $b['description'],
                'mins_remaining' => max(0, (int)$b['mins_remaining']),
                'secs_remaining' => max(0, (int)$b['secs_remaining']),
                'activated_by' => $b['activated_by_name'],
                'max_mins' => self::MAX_DURATION_MINS,
            ];
        }

        return ['buffs' => $result, 'is_buffed' => count($result) > 0];
    }

    /**
     * Activate a buff on a spot.
     * Consumes one item from player inventory.
     * Same buff type: extends duration (additive, max 2hr).
     * Different buff type: adds new buff.
     */
    public static function activate(int $playerId, int $spotId, int $buffItemId): array {
        $pdo = db();

        // Verify spot exists
        $stmt = $pdo->prepare('SELECT id, name FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Fishing spot not found');

        // Get buff item
        $stmt = $pdo->prepare('SELECT * FROM buff_items WHERE id = :id AND is_active = 1');
        $stmt->execute([':id' => $buffItemId]);
        $item = $stmt->fetch();
        if (!$item) json_error('Buff item not found');

        // Check player level
        $stmt = $pdo->prepare('SELECT level FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();
        if ((int)$player['level'] < (int)$item['min_level']) {
            json_error("Need level {$item['min_level']} to use {$item['name']}");
        }

        // Check player has the item
        $stmt = $pdo->prepare('
            SELECT quantity FROM player_buff_items
            WHERE player_id = :pid AND buff_item_id = :bid
        ');
        $stmt->execute([':pid' => $playerId, ':bid' => $buffItemId]);
        $held = $stmt->fetch();
        if (!$held || (int)$held['quantity'] < 1) {
            json_error("You don't have any {$item['name']}");
        }

        $buffType = $item['buff_type'];
        $durationMins = (int)$item['duration_mins'];

        // Check current duration of this buff type on this spot
        $stmt = $pdo->prepare('
            SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) AS mins_left
            FROM spot_buffs
            WHERE spot_id = :sid AND buff_type = :bt AND expires_at > NOW()
        ');
        $stmt->execute([':sid' => $spotId, ':bt' => $buffType]);
        $existing = $stmt->fetch();
        $currentMinsLeft = $existing ? max(0, (int)$existing['mins_left']) : 0;

        // Check 2-hour cap
        if ($currentMinsLeft + $durationMins > self::MAX_DURATION_MINS) {
            $canAdd = self::MAX_DURATION_MINS - $currentMinsLeft;
            if ($canAdd <= 0) {
                json_error("{$item['name']} is at max duration (2 hours). Wait before adding more.");
            }
            json_error("{$item['name']} would exceed 2 hour max. {$currentMinsLeft} min remaining, can add {$canAdd} more min.");
        }

        // Consume item
        $pdo->prepare('
            UPDATE player_buff_items SET quantity = quantity - 1
            WHERE player_id = :pid AND buff_item_id = :bid
        ')->execute([':pid' => $playerId, ':bid' => $buffItemId]);

        // Activate or extend
        if ($existing) {
            // Extend existing buff
            $pdo->prepare('
                UPDATE spot_buffs
                SET expires_at = DATE_ADD(expires_at, INTERVAL :mins MINUTE)
                WHERE spot_id = :sid AND buff_type = :bt AND expires_at > NOW()
            ')->execute([':mins' => $durationMins, ':sid' => $spotId, ':bt' => $buffType]);
        } else {
            // New buff
            $pdo->prepare('
                INSERT INTO spot_buffs (spot_id, buff_type, buff_value, activated_by, expires_at)
                VALUES (:sid, :bt, :val, :pid, DATE_ADD(NOW(), INTERVAL :mins MINUTE))
            ')->execute([
                ':sid' => $spotId, ':bt' => $buffType,
                ':val' => (float)$item['effect_value'],
                ':pid' => $playerId, ':mins' => $durationMins,
            ]);
        }

        $totalMins = $currentMinsLeft + $durationMins;

        // ── Push update to fishing spot for live display ──
        $stmt = $pdo->prepare('SELECT display_name FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $activator = $stmt->fetchColumn() ?: 'Someone';

        // Format effect text
        $effectText = self::formatEffectText($item);

        // Get final expires_at timestamp
        $stmt = $pdo->prepare('
            SELECT UNIX_TIMESTAMP(expires_at) AS expires_at
            FROM spot_buffs WHERE spot_id = :sid AND buff_type = :bt AND expires_at > NOW()
        ');
        $stmt->execute([':sid' => $spotId, ':bt' => $buffType]);
        $expiresAt = (int)($stmt->fetchColumn() ?: 0);

        if (class_exists('PrimCallback')) {
            PrimCallback::pushToSpot($spotId, [
                'type'                   => 'buff_active',
                'buff_type'              => $buffType,
                'buff_label'             => $item['name'],
                'effect_text'            => $effectText,
                'activator_display_name' => $activator,
                'spot_name'              => $spot['name'],
                'duration_minutes'       => $durationMins,
                'stacked_total_minutes'  => $totalMins,
                'expires_at'             => $expiresAt,
                'multiplier'             => (float)$item['effect_value'],
            ]);
        }

        return [
            'message' => "{$item['name']} activated on {$spot['name']}! ({$totalMins} min total)",
            'buff_name' => $item['name'],
            'buff_type' => $buffType,
            'total_mins' => $totalMins,
        ];
    }

    /**
     * Format human-readable effect text for a buff item.
     */
    public static function formatEffectText(array $item): string {
        $val = (float)$item['effect_value'];
        $pct = round($val * 100);
        switch ($item['buff_type']) {
            case 'chum':         return '+' . $pct . '% bite chance';
            case 'lure_oil':     return '+' . $pct . '% rare fish chance';
            case 'growth':       return '+' . $pct . '% fish weight';
            case 'blessing':     return '+' . $pct . '% XP';
            case 'treasure':     return '+' . $pct . '% junk chance';
            case 'calm':         return '-' . $pct . '% fight difficulty';
            case 'bait_saver':   return $pct . '% bait save chance';
            case 'double_hook':  return $pct . '% double catch chance';
            case 'golden_hour':  return '+' . $pct . '% to all rolls';
            default:             return 'Active';
        }
    }

    /**
     * Get buff multipliers for a spot (used by fishing engine).
     * Returns associative array of buff_type => value for active buffs.
     */
    public static function getActiveMultipliers(int $spotId): array {
        $pdo = db();

        // Find expired buffs that need notifying
        $stmt = $pdo->prepare('
            SELECT sb.buff_type, bi.name AS buff_name, fs.name AS spot_name
            FROM spot_buffs sb
            LEFT JOIN buff_items bi ON bi.buff_type = sb.buff_type
            LEFT JOIN fishing_spots fs ON fs.id = sb.spot_id
            WHERE sb.spot_id = :sid AND sb.expires_at <= NOW()
            GROUP BY sb.buff_type
        ');
        $stmt->execute([':sid' => $spotId]);
        $expired = $stmt->fetchAll();

        // Delete expired
        $pdo->prepare('DELETE FROM spot_buffs WHERE expires_at <= NOW()')->execute();

        // Push expiration notifications
        if (!empty($expired) && class_exists('PrimCallback')) {
            foreach ($expired as $exp) {
                PrimCallback::pushToSpot($spotId, [
                    'type'       => 'buff_expired',
                    'buff_type'  => $exp['buff_type'],
                    'buff_label' => $exp['buff_name'] ?: $exp['buff_type'],
                    'spot_name'  => $exp['spot_name'] ?: 'this spot',
                ]);
            }
        }

        $stmt = $pdo->prepare('
            SELECT buff_type, buff_value FROM spot_buffs
            WHERE spot_id = :sid AND expires_at > NOW()
        ');
        $stmt->execute([':sid' => $spotId]);

        $multipliers = [];
        while ($row = $stmt->fetch()) {
            $multipliers[$row['buff_type']] = (float)$row['buff_value'];
        }
        return $multipliers;
    }

    // ═══════════════════════════════════════
    // CRAFTING
    // ═══════════════════════════════════════

    /**
     * Get available crafting recipes for a player's level.
     */
    public static function getCraftingRecipes(int $playerLevel): array {
        $recipes = [];

        if ($playerLevel >= 5) {
            $recipes[] = [
                'buff_item' => 'Chum Bucket',
                'buff_type' => 'chum',
                'requires' => '2 rare fish (any species)',
                'require_rarity' => 3,  // rare
                'require_count' => 2,
                'min_level' => 5,
            ];
            $recipes[] = [
                'buff_item' => 'Lucky Lure Oil',
                'buff_type' => 'lure_oil',
                'requires' => '1 legendary fish (any species)',
                'require_rarity' => 5,  // legendary
                'require_count' => 1,
                'min_level' => 5,
            ];
        }

        return ['recipes' => $recipes];
    }

    /**
     * Craft a buff item at the butchering table.
     */
    public static function craft(int $playerId, string $buffType): array {
        $pdo = db();

        // Get player level
        $stmt = $pdo->prepare('SELECT level FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');
        $level = (int)$player['level'];

        // Define recipes
        $recipes = [
            'chum' => ['name' => 'Chum Bucket', 'rarity_id' => 3, 'count' => 2, 'min_level' => 5],
            'lure_oil' => ['name' => 'Lucky Lure Oil', 'rarity_id' => 5, 'count' => 1, 'min_level' => 5],
        ];

        if (!isset($recipes[$buffType])) json_error('Unknown recipe');
        $recipe = $recipes[$buffType];

        if ($level < $recipe['min_level']) {
            json_error("Need level {$recipe['min_level']} to craft {$recipe['name']}");
        }

        // Check if player has enough fish of required rarity
        $reqCount = $recipe['count'];
        $stmt = $pdo->prepare('
            SELECT id, fish_species_id, weight, rarity_id
            FROM player_fish
            WHERE player_id = :pid AND status = \'held\' AND rarity_id >= :rid
            ORDER BY weight ASC
            LIMIT ' . (int)$reqCount . '
        ');
        $stmt->execute([':pid' => $playerId, ':rid' => $recipe['rarity_id']]);
        $fish = $stmt->fetchAll();

        if (count($fish) < $recipe['count']) {
            $rarityNames = [1=>'common',2=>'uncommon',3=>'rare',4=>'epic',5=>'legendary'];
            $rn = $rarityNames[$recipe['rarity_id']] ?? 'rare';
            json_error("Need {$recipe['count']} {$rn}+ fish. You have " . count($fish) . ".");
        }

        // Consume the fish (use lowest weight first)
        $consumed = [];
        for ($i = 0; $i < $recipe['count']; $i++) {
            $f = $fish[$i];
            $pdo->prepare('UPDATE player_fish SET status = \'sold\' WHERE id = :id')
                ->execute([':id' => $f['id']]);
            $consumed[] = $f['id'];
        }

        // Grant the buff item
        $stmt = $pdo->prepare('SELECT id FROM buff_items WHERE buff_type = :bt LIMIT 1');
        $stmt->execute([':bt' => $buffType]);
        $buffItem = $stmt->fetch();
        if (!$buffItem) json_error('Buff item not configured');

        self::grantItem($playerId, (int)$buffItem['id']);

        return [
            'message' => "Crafted 1x {$recipe['name']}!",
            'item_name' => $recipe['name'],
            'fish_consumed' => count($consumed),
        ];
    }

    // ═══════════════════════════════════════
    // SHOP
    // ═══════════════════════════════════════

    /**
     * Buy a buff item from the shop.
     */
    public static function buyFromShop(int $playerId, int $buffItemId): array {
        $pdo = db();

        $stmt = $pdo->prepare('SELECT * FROM buff_items WHERE id = :id AND source = \'shop\' AND is_active = 1');
        $stmt->execute([':id' => $buffItemId]);
        $item = $stmt->fetch();
        if (!$item) json_error('Item not available in shop');

        if (!$item['shop_price']) json_error('Item has no shop price');

        // Check level
        $stmt = $pdo->prepare('SELECT level, fishing_points FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $player = $stmt->fetch();
        if ((int)$player['level'] < (int)$item['min_level']) {
            json_error("Need level {$item['min_level']} to buy {$item['name']}");
        }

        $price = (int)$item['shop_price'];
        if ((int)$player['fishing_points'] < $price) {
            json_error("Need {$price} points. You have {$player['fishing_points']}.");
        }

        // Deduct points
        $pdo->prepare('UPDATE players SET fishing_points = fishing_points - :p WHERE id = :id')
            ->execute([':p' => $price, ':id' => $playerId]);

        // Grant item
        self::grantItem($playerId, $buffItemId);

        return [
            'message' => "Bought {$item['name']} for {$price} points!",
            'item_name' => $item['name'],
            'points_spent' => $price,
        ];
    }

    // ═══════════════════════════════════════
    // ADMIN
    // ═══════════════════════════════════════

    /**
     * Admin grant buff item to a player.
     */
    public static function adminGrant(int $playerId, int $buffItemId, int $quantity = 1): array {
        return self::grantItem($playerId, $buffItemId, $quantity);
    }
}
