<?php
/**
 * Fishing Game - Pairing Authentication
 * Replaces the shared HUD_SECRET model with per-device tokens.
 *
 * Flow:
 *   1. HUD calls pair_request -> server returns 6-digit code
 *   2. Player enters code on web portal -> server marks code claimed and stores token
 *   3. HUD polls pair_status -> when claimed, server returns the token
 *   4. HUD uses token + nonce + HMAC for all future requests
 */

require_once __DIR__ . '/../config.php';

class PairingAuth {

    /**
     * Step 1: HUD requests a pairing code for a UUID.
     * UUID must already have an account (created via web setup).
     */
    public static function requestCode(string $uuid, string $gridName = ''): array {
        $pdo = db();

        // Verify the UUID has an account
        $stmt = $pdo->prepare('SELECT id, display_name FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        $player = $stmt->fetch();

        // Log the attempt regardless of outcome
        $pdo->prepare('
            INSERT INTO hud_pair_attempts (player_id, attempted_uuid, grid_name, avatar_name, ip_address, succeeded)
            VALUES (:pid, :uuid, :gn, :av, :ip, 0)
        ')->execute([
            ':pid'  => $player ? (int)$player['id'] : null,
            ':uuid' => $uuid,
            ':gn'   => $gridName ?: null,
            ':av'   => $player ? ($player['display_name'] ?? null) : null,
            ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        if (!$player) {
            json_error('No account exists for this avatar. Please register on the website first.', 404);
        }

        // Invalidate any older unclaimed codes for this UUID (any grid)
        $stmt = $pdo->prepare('
            UPDATE hud_pairing_codes
            SET expires_at = NOW()
            WHERE player_uuid = :uuid AND claimed = 0 AND expires_at > NOW()
        ');
        $stmt->execute([':uuid' => $uuid]);

        // Generate a unique 6-digit code (formatted XXX-XXX)
        $tries = 0;
        do {
            $n = (string)random_int(100000, 999999);
            $code = substr($n, 0, 3) . '-' . substr($n, 3, 3);

            $stmt = $pdo->prepare('
                SELECT id FROM hud_pairing_codes
                WHERE code = :c AND expires_at > NOW() AND claimed = 0
            ');
            $stmt->execute([':c' => $code]);
            $exists = $stmt->fetch();
            $tries++;
        } while ($exists && $tries < 10);

        $expires = date('Y-m-d H:i:s', time() + 300); // 5 minute expiry

        $stmt = $pdo->prepare('
            INSERT INTO hud_pairing_codes (code, player_uuid, grid_name, player_id, expires_at)
            VALUES (:c, :uuid, :gn, :pid, :exp)
        ');
        $stmt->execute([
            ':c'    => $code,
            ':uuid' => $uuid,
            ':gn'   => $gridName ?: null,
            ':pid'  => $player['id'],
            ':exp'  => $expires,
        ]);

        return [
            'pairing_code' => $code,
            'expires_in'   => 300,
            'pair_url'     => 'https://sp.wa.darkheartsos.net/fishing/pair?uuid=' . urlencode($uuid) . '&code=' . urlencode($code),
            'message'      => 'Enter this code on the web portal under Settings > Pair HUD',
        ];
    }

    /**
     * Normalize a pairing code to XXX-XXX format.
     * Accepts "384291", "384-291", "384 291", etc.
     */
    private static function normalizeCode(string $code): string {
        $digits = preg_replace('/\D/', '', $code);
        if (strlen($digits) !== 6) return $code; // Let validation fail normally
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3);
    }

    /**
     * Step 3: HUD polls to check if its code has been claimed.
     * If claimed, returns the device token.
     */
    public static function checkStatus(string $uuid, string $code): array {
        $code = self::normalizeCode($code);
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT pc.*, ht.token, ht.id AS token_id
            FROM hud_pairing_codes pc
            LEFT JOIN hud_tokens ht ON ht.id = pc.issued_token_id
            WHERE pc.code = :c AND pc.player_uuid = :uuid
        ');
        $stmt->execute([':c' => $code, ':uuid' => $uuid]);
        $row = $stmt->fetch();

        if (!$row) {
            json_error('Pairing code not found', 404);
        }

        if (strtotime($row['expires_at']) < time() && !$row['claimed']) {
            json_error('Pairing code expired. Request a new one.', 410);
        }

        if (!$row['claimed']) {
            return ['claimed' => false, 'message' => 'Waiting for code entry on web portal...'];
        }

        return [
            'claimed'  => true,
            'token'    => $row['token'],
            'token_id' => (int)$row['token_id'],
            'message'  => 'HUD paired successfully!',
        ];
    }

    /**
     * Step 2: Web portal claims a code (player is logged in via session).
     */
    public static function claimCode(int $playerId, string $code, string $deviceName = 'HUD'): array {
        $code = self::normalizeCode($code);
        $pdo = db();

        // Find the code (must be unclaimed, not expired, and belong to this player)
        $stmt = $pdo->prepare('
            SELECT * FROM hud_pairing_codes
            WHERE code = :c AND player_id = :pid AND claimed = 0 AND expires_at > NOW()
        ');
        $stmt->execute([':c' => $code, ':pid' => $playerId]);
        $row = $stmt->fetch();

        if (!$row) {
            json_error('Invalid or expired pairing code, or it does not belong to your account.', 400);
        }

        // Get grid_name from the pairing code
        $gridName = $row['grid_name'] ?? null;

        // Generate the device token (long random string)
        $token = bin2hex(random_bytes(48));

        $pdo->beginTransaction();
        try {
            // Re-pair on the SAME grid revokes that grid's old token only.
            // Other grids stay alive (hypergrid travelers re-visiting paired grids).
            if ($gridName !== null && $gridName !== '') {
                $pdo->prepare('UPDATE hud_tokens
                               SET is_active = 0, revoked_at = NOW()
                               WHERE player_id = :pid AND grid_name = :gn AND revoked_at IS NULL')
                    ->execute([':pid' => $playerId, ':gn' => $gridName]);
            } else {
                $pdo->prepare('UPDATE hud_tokens
                               SET is_active = 0, revoked_at = NOW()
                               WHERE player_id = :pid AND grid_name IS NULL AND revoked_at IS NULL')
                    ->execute([':pid' => $playerId]);
            }

            // Mark this pairing attempt as succeeded
            $pdo->prepare('UPDATE hud_pair_attempts
                           SET succeeded = 1
                           WHERE attempted_uuid = :uuid AND grid_name <=> :gn
                             AND succeeded = 0
                           ORDER BY id DESC LIMIT 1')
                ->execute([':uuid' => $row['player_uuid'] ?? '', ':gn' => $gridName]);

            // Insert the token with grid scope
            $stmt = $pdo->prepare('
                INSERT INTO hud_tokens (player_id, token, device_name, grid_name, last_used, last_ip)
                VALUES (:pid, :tok, :dn, :gn, NOW(), :ip)
            ');
            $stmt->execute([
                ':pid' => $playerId,
                ':tok' => $token,
                ':dn'  => $deviceName,
                ':gn'  => $gridName,
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $tokenId = (int)$pdo->lastInsertId();

            // Mark the pairing code as claimed
            $stmt = $pdo->prepare('
                UPDATE hud_pairing_codes
                SET claimed = 1, claimed_at = NOW(), issued_token_id = :tid
                WHERE id = :id
            ');
            $stmt->execute([':tid' => $tokenId, ':id' => $row['id']]);

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'message'   => 'HUD paired successfully!',
            'device_id' => $tokenId,
        ];
    }

    /**
     * Validate an authenticated HUD request.
     * Required POST: uuid, token_id, timestamp, nonce, signature
     * Signature = HMAC-SHA256("uuid:timestamp:nonce:action", token)
     */
    public static function requireHUD(string $action): array {
        $uuid      = require_param('uuid');
        $tokenId   = (int)require_param('token_id');
        $timestamp = (int)require_param('timestamp');
        $nonce     = (int)require_param('nonce');
        $signature = require_param('signature');

        // Reject old requests (60 second window)
        if (abs(time() - $timestamp) > 60) {
            json_error('Request expired', 401);
        }

        // Look up the token (active OR inactive, as long as not revoked)
        $stmt = db()->prepare('
            SELECT ht.*, p.* FROM hud_tokens ht
            JOIN players p ON p.id = ht.player_id
            WHERE ht.id = :tid AND ht.revoked_at IS NULL
        ');
        $stmt->execute([':tid' => $tokenId]);
        $row = $stmt->fetch();

        if (!$row) {
            json_error('Invalid or revoked token. Re-pair your HUD.', 401);
        }

        // Verify UUID matches the player
        if ($row['uuid'] !== $uuid) {
            json_error('UUID mismatch', 401);
        }

        // Verify nonce is greater than last seen (replay protection)
        if ($nonce <= (int)$row['last_nonce']) {
            json_error('Invalid nonce (replay detected)', 401);
        }

        // Verify signature — SHA256 of message + token
        $message  = "{$uuid}:{$timestamp}:{$nonce}:{$action}:{$row['token']}";
        $expected = hash('sha256', $message);
        if (!hash_equals($expected, $signature)) {
            error_log("Sig mismatch: action={$action} expected={$expected} got={$signature}");
            json_error('Invalid signature', 401);
        }

        // ── 60-second mutual exclusion ──
        // Only one HUD can be active across all grids at any time.
        // If another token for this player was used in the last 60s, block this one.
        $playerId = (int)$row['player_id'];
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT id, last_used FROM hud_tokens
            WHERE player_id = :pid AND id != :tid
              AND revoked_at IS NULL AND is_active = 1
              AND last_used > DATE_SUB(NOW(), INTERVAL 60 SECOND)
            LIMIT 1
        ');
        $stmt->execute([':pid' => $playerId, ':tid' => $tokenId]);
        $blockingRow = $stmt->fetch();
        if ($blockingRow) {
            // Find the UUID currently holding the lock
            $blockerStmt = $pdo->prepare('SELECT p.uuid FROM hud_tokens ht
                JOIN players p ON p.id = ht.player_id
                WHERE ht.id = :id');
            $blockerStmt->execute([':id' => $blockingRow['id']]);
            $blockingUuid = $blockerStmt->fetchColumn() ?: null;

            // Log the exclusion trigger
            $pdo->prepare('
                INSERT INTO hud_exclusion_triggers (player_id, triggered_uuid, blocking_uuid)
                VALUES (:pid, :tu, :bu)
            ')->execute([':pid' => $playerId, ':tu' => $uuid, ':bu' => $blockingUuid]);

            json_error('Another device is currently active. Wait 60 seconds and try again.', 423);
        }

        // Single-active-HUD enforcement:
        // Mark this token active, deactivate any other tokens (kept revoked_at NULL so they can re-activate after lockout)
        $pdo->prepare('UPDATE hud_tokens SET is_active = 0
                       WHERE player_id = :pid AND id != :tid AND revoked_at IS NULL')
            ->execute([':pid' => $playerId, ':tid' => $tokenId]);

        // Update this token: active, last nonce, last used
        $stmt = $pdo->prepare('
            UPDATE hud_tokens SET is_active = 1, last_nonce = :n, last_used = NOW(), last_ip = :ip
            WHERE id = :tid
        ');
        $stmt->execute([
            ':n'   => $nonce,
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':tid' => $tokenId,
        ]);

        // Return the player record
        return [
            'id' => (int)$row['player_id'],
            'uuid' => $row['uuid'],
            'username' => $row['username'],
            'display_name' => $row['display_name'],
            'level' => (int)$row['level'],
            'xp' => (int)$row['xp'],
            'fishing_points' => (int)$row['fishing_points'],
            'equipped_bait_id' => $row['equipped_bait_id'],
            'equipped_rod_id' => $row['equipped_rod_id'],
            'total_casts' => (int)$row['total_casts'],
            'total_catches' => (int)$row['total_catches'],
            'is_admin' => (int)$row['is_admin'],
        ];
    }

    /**
     * Direct web registration - creates an account tied to a UUID.
     * No setup token needed - player provides UUID + username + password.
     */
    public static function webRegister(string $uuid, string $username, string $password, string $displayName = ''): array {
        $pdo = db();

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            json_error('Invalid avatar UUID format');
        }

        // Check if UUID already has an account
        $stmt = $pdo->prepare('SELECT id FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        if ($stmt->fetch()) {
            json_error('An account already exists for this avatar. Try logging in instead.', 409);
        }

        // Validate username
        $username = trim($username);
        if (strlen($username) < 3 || strlen($username) > 64) {
            json_error('Username must be 3-64 characters');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            json_error('Username can only contain letters, numbers, and underscores');
        }

        $stmt = $pdo->prepare('SELECT id FROM players WHERE username = :u');
        $stmt->execute([':u' => $username]);
        if ($stmt->fetch()) {
            json_error('Username already taken');
        }

        // Validate password
        if (strlen($password) < 6) {
            json_error('Password must be at least 6 characters');
        }

        $displayName = trim($displayName) ?: $username;
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $pdo->prepare('
            INSERT INTO players (uuid, username, password_hash, display_name)
            VALUES (:uuid, :u, :p, :d)
        ');
        $stmt->execute([
            ':uuid' => $uuid,
            ':u'    => $username,
            ':p'    => $hash,
            ':d'    => $displayName,
        ]);
        $playerId = (int)$pdo->lastInsertId();

        // Grant starter rod, line, and bait
        $pdo->prepare('INSERT IGNORE INTO player_rods (player_id, rod_id) VALUES (:pid, 1)')
            ->execute([':pid' => $playerId]);
        $pdo->prepare('UPDATE players SET equipped_rod_id = 1 WHERE id = :id')
            ->execute([':id' => $playerId]);

        // Grant and equip starter line (Twine)
        $twineId = $pdo->query("SELECT id FROM line_types WHERE name = 'Twine' LIMIT 1")->fetchColumn();
        if ($twineId) {
            $pdo->prepare('INSERT IGNORE INTO player_lines (player_id, line_id) VALUES (:pid, :lid)')
                ->execute([':pid' => $playerId, ':lid' => $twineId]);
            $pdo->prepare('UPDATE players SET equipped_line_id = :lid WHERE id = :id')
                ->execute([':lid' => $twineId, ':id' => $playerId]);
        }

        // Grant starter bait (20 Bread Dough)
        $pdo->prepare('INSERT INTO player_bait (player_id, bait_id, quantity) VALUES (:pid, 9, 20)')
            ->execute([':pid' => $playerId]);
        $pdo->prepare('UPDATE players SET equipped_bait_id = 9 WHERE id = :id')
            ->execute([':id' => $playerId]);

        return [
            'message'   => 'Account created! You can now log in.',
            'player_id' => $playerId,
        ];
    }

    /**
     * Compute max spots a player can own, based on their level.
     */
    public static function computeSpotLimit(int $level, ?int $override = null): int {
        if ($override !== null && $override > 0) return $override;
        if ($level >= 20) return 15;
        if ($level >= 15) return 10;
        if ($level >= 10) return 7;
        if ($level >= 5)  return 5;
        return 3;
    }

    /**
     * Spot self-registration with ownership and limits.
     */
    /**
     * List recoverable spots (archived or inactive) a player can load in the current region.
     * Also returns spot_count/spot_limit/is_admin so the LSL wizard can gate "New Spot"
     * without a second round-trip.
     */
    public static function getArchivedSpots(string $uuid, string $region, string $gridName = ''): array {
        $pdo  = db();
        $stmt = $pdo->prepare('SELECT id, level, is_admin, spot_limit_override FROM players WHERE uuid = :u');
        $stmt->execute([':u' => $uuid]);
        $player = $stmt->fetch();
        if (!$player) return ['archived' => [], 'inactive' => [], 'spot_count' => 0, 'spot_limit' => 3, 'is_admin' => 0];

        $pid = $player['id'];
        $baseSelect = '
            SELECT fs.id, fs.name,
                   (SELECT COUNT(*) FROM catch_log cl WHERE cl.spot_id = fs.id) AS catch_count
            FROM fishing_spots fs
            WHERE fs.player_id = :pid AND fs.region_name = :rn
        ';
        $params     = [':pid' => $pid, ':rn' => $region];
        $gridClause = '';
        if ($gridName !== '') {
            $gridClause    = ' AND fs.grid_name = :gn';
            $params[':gn'] = $gridName;
        }

        $stmtA = $pdo->prepare($baseSelect . ' AND fs.archived = 1' . $gridClause . ' ORDER BY fs.archived_at DESC');
        $stmtA->execute($params);
        $archived = $stmtA->fetchAll();

        $stmtI = $pdo->prepare($baseSelect . ' AND fs.archived = 0 AND fs.is_active = 0' . $gridClause . ' ORDER BY fs.id DESC');
        $stmtI->execute($params);
        $inactive = $stmtI->fetchAll();

        $stmtC = $pdo->prepare('
            SELECT COUNT(*) FROM fishing_spots
            WHERE player_id = :pid AND is_system = 0 AND archived = 0 AND is_active = 1
        ');
        $stmtC->execute([':pid' => $pid]);
        $spotCount = (int)$stmtC->fetchColumn();
        $limit     = self::computeSpotLimit(
            (int)$player['level'],
            $player['spot_limit_override'] !== null ? (int)$player['spot_limit_override'] : null
        );

        $systemSpots = [];
        if ((int)$player['is_admin']) {
            $sysParams = [':rn' => $region];
            $sysWhere  = 'fs.is_system = 1 AND fs.region_name = :rn AND (fs.archived = 1 OR fs.is_active = 0)';
            if ($gridName !== '') {
                $sysWhere      .= ' AND fs.grid_name = :gn';
                $sysParams[':gn'] = $gridName;
            }
            $stmtS = $pdo->prepare('
                SELECT fs.id, fs.name, fs.archived,
                       (SELECT COUNT(*) FROM catch_log cl WHERE cl.spot_id = fs.id) AS catch_count
                FROM fishing_spots fs
                WHERE ' . $sysWhere . '
                ORDER BY fs.archived DESC, fs.id DESC
            ');
            $stmtS->execute($sysParams);
            $systemSpots = $stmtS->fetchAll();
        }

        return [
            'archived'      => $archived,
            'inactive'      => $inactive,
            'system_spots'  => $systemSpots,
            'spot_count'    => $spotCount,
            'spot_limit'    => $limit,
            'is_admin'      => (int)$player['is_admin'],
        ];
    }

    /**
     * Restore an archived spot — re-binds to a new prim UUID.
     * Catch history and leaderboard entries are preserved.
     */
    public static function restoreSpot(string $uuid, int $spotId, string $primUuid,
                                        float $posX, float $posY, float $posZ,
                                        string $regionName, string $gridName = ''): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
        $stmt->execute([':u' => $uuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        // Admins can restore system spots; others must own the spot
        $stmt = $pdo->prepare('
            SELECT * FROM fishing_spots
            WHERE id = :id AND (archived = 1 OR is_active = 0)
        ');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found or not recoverable', 404);
        if ($spot['is_system'] && !(int)$player['is_admin']) json_error('Not authorized', 403);
        if (!$spot['is_system'] && (int)$spot['player_id'] !== (int)$player['id']) json_error('Spot not found or not yours', 404);

        // Restore — un-archive but leave inactive. The user can re-activate
        // explicitly (which goes through the normal limit check).
        $pdo->prepare('
            UPDATE fishing_spots
            SET archived = 0, archived_at = NULL, is_active = 0,
                pos_x = :x, pos_y = :y, pos_z = :z,
                region_name = :rn, grid_name = :gn
            WHERE id = :id
        ')->execute([
            ':x'  => $posX, ':y' => $posY, ':z' => $posZ,
            ':rn' => $regionName,
            ':gn' => $gridName ?: $spot['grid_name'],
            ':id' => $spotId,
        ]);

        return [
            'message'  => 'Spot restored.',
            'spot_id'  => $spotId,
            'name'     => $spot['name'],
            'status'   => $spot['archived'] ? 'archived' : 'inactive',
        ];
    }

    public static function registerSpot(array $data): array {
        $pdo = db();

        $name       = trim($data['name'] ?? '');
        $waterType  = trim($data['water_type'] ?? '');
        $region     = trim($data['region'] ?? '');
        $gridName   = trim($data['grid_name'] ?? '');
        $posX       = (float)($data['pos_x'] ?? 0);
        $posY       = (float)($data['pos_y'] ?? 0);
        $posZ       = (float)($data['pos_z'] ?? 0);
        $ownerKey   = trim($data['owner_key'] ?? '');
        $isPublic   = (int)($data['is_public'] ?? 1);
        $isSystem   = (int)($data['is_system'] ?? 0);
        $regionX    = isset($data['region_x']) ? (int)$data['region_x'] : null;
        $regionY    = isset($data['region_y']) ? (int)$data['region_y'] : null;
        $activate   = (int)($data['activate'] ?? 0);

        if ($name === '') json_error('Spot name is required');
        if ($waterType === '') json_error('Water type is required');
        if ($ownerKey === '') json_error('Missing owner_key');

        $stmt = $pdo->prepare('SELECT id, level, is_admin, spot_limit_override FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $ownerKey]);
        $player = $stmt->fetch();
        if (!$player) json_error('No fishing account. Register first.', 403);

        $playerId = (int)$player['id'];
        $playerLevel = (int)$player['level'];
        $isAdmin = (int)$player['is_admin'];
        $spotOverride = $player['spot_limit_override'] !== null ? (int)$player['spot_limit_override'] : null;

        if ($isSystem && !$isAdmin) $isSystem = 0;

        $stmt = $pdo->prepare('SELECT id, min_level FROM water_types WHERE name = :n');
        $stmt->execute([':n' => $waterType]);
        $wt = $stmt->fetch();
        if (!$wt) json_error("Unknown water type '{$waterType}'", 400);

        $waterTypeId = (int)$wt['id'];
        $waterMinLevel = (int)$wt['min_level'];

        if ($playerLevel < $waterMinLevel && !$isAdmin) {
            json_error("Need level {$waterMinLevel} for {$waterType}. You are level {$playerLevel}.", 403);
        }

        // Check for existing active (non-archived) spot at this position
        $stmt = $pdo->prepare('
            SELECT id, archived, player_id FROM fishing_spots
            WHERE region_name = :r AND ABS(pos_x - :x) < 3 AND ABS(pos_y - :y) < 3 AND ABS(pos_z - :z) < 3
            ORDER BY archived ASC
            LIMIT 1
        ');
        $stmt->execute([':r' => $region, ':x' => $posX, ':y' => $posY, ':z' => $posZ]);
        $existing = $stmt->fetch();

        if ($existing && (int)$existing['archived']) {
            // Found an archived spot at this position
            if ((int)$existing['player_id'] === $playerId) {
                // Owner's own archived spot — tell the prim to prompt for restore/fresh-setup
                json_error('This spot was deleted. Touch the prim to restore it or set up fresh.', 410);
            }
            // Someone else's archived spot — ignore it, fall through to limit check + INSERT
            $existing = null;
        }

        if ($existing) {
            $stmt = $pdo->prepare('
                UPDATE fishing_spots
                SET name = :n, water_type_id = :wt, grid_name = :gn,
                    pos_x = :x, pos_y = :y, pos_z = :z,
                    is_public = :pub, is_system = :sys,
                    region_x = :rx, region_y = :ry,
                    is_active = :act, setup_complete = 1
                WHERE id = :id
            ');
            $stmt->execute([
                ':n' => $name, ':wt' => $waterTypeId, ':gn' => $gridName,
                ':x' => $posX, ':y' => $posY, ':z' => $posZ,
                ':pub' => $isPublic, ':sys' => $isSystem,
                ':rx' => $regionX, ':ry' => $regionY,
                ':act' => $activate, ':id' => $existing['id'],
            ]);
            return ['spot_id' => (int)$existing['id'], 'name' => $name, 'message' => 'Spot updated', 'created' => false];
        }

        if (!$isSystem) {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) FROM fishing_spots
                WHERE player_id = :pid AND is_system = 0
                  AND archived = 0 AND is_active = 1
            ');
            $stmt->execute([':pid' => $playerId]);
            $currentCount = (int)$stmt->fetchColumn();
            $limit = self::computeSpotLimit($playerLevel, $spotOverride);
            if ($currentCount >= $limit) {
                json_error("Active spot limit reached ({$currentCount}/{$limit}). Deactivate or archive a spot, or level up for more.", 403);
            }
        }

        $stmt = $pdo->prepare('
            INSERT INTO fishing_spots
                (player_id, is_system, name, water_type_id, region_name, grid_name,
                 pos_x, pos_y, pos_z, min_level, is_active, is_public,
                 region_x, region_y, setup_complete)
            VALUES (:pid, :sys, :n, :wt, :r, :gn, :x, :y, :z, :ml, :act, :pub, :rx, :ry, 1)
        ');
        $stmt->execute([
            ':pid' => $isSystem ? null : $playerId,
            ':sys' => $isSystem, ':n' => $name, ':wt' => $waterTypeId,
            ':r' => $region, ':gn' => $gridName,
            ':x' => $posX, ':y' => $posY, ':z' => $posZ,
            ':ml' => $waterMinLevel, ':act' => $activate, ':pub' => $isPublic,
            ':rx' => $regionX, ':ry' => $regionY,
        ]);

        return ['spot_id' => (int)$pdo->lastInsertId(), 'name' => $name, 'message' => 'Spot registered', 'created' => true];
    }

    /**
     * Get player info for spot setup wizard.
     */
    public static function getSpotSetupInfo(string $uuid): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, level, is_admin, spot_limit_override FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('No fishing account found', 404);

        $playerLevel = (int)$player['level'];
        $isAdmin = (int)$player['is_admin'];

        $waterTypes = $pdo->query('SELECT id, name, min_level FROM water_types ORDER BY min_level ASC')->fetchAll();
        $available = [];
        foreach ($waterTypes as $wt) {
            if ($playerLevel >= (int)$wt['min_level'] || $isAdmin) $available[] = $wt['name'];
        }

        $stmt = $pdo->prepare('
            SELECT COUNT(*) FROM fishing_spots
            WHERE player_id = :pid AND is_system = 0
              AND archived = 0 AND is_active = 1
        ');
        $stmt->execute([':pid' => $player['id']]);
        $spotCount = (int)$stmt->fetchColumn();
        $limit = self::computeSpotLimit($playerLevel, $player['spot_limit_override'] !== null ? (int)$player['spot_limit_override'] : null);

        return ['player_id' => (int)$player['id'], 'level' => $playerLevel, 'is_admin' => $isAdmin,
                'water_types' => $available, 'spot_count' => $spotCount, 'spot_limit' => $limit];
    }

    /**
     * Update spot settings (owner or admin).
     */
    public static function updateSpot(string $ownerUuid, int $spotId, array $data): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        $stmt = $pdo->prepare('SELECT * FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);

        if ((int)$spot['player_id'] !== (int)$player['id'] && !(int)$player['is_admin'])
            json_error('Not authorized', 403);

        $allowed = ['name', 'is_active', 'is_public'];
        $sets = []; $params = [':id' => $spotId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) { $sets[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($sets)) json_error('Nothing to update');

        // If re-activating (from inactive→active), check spot limit
        $isReactivating = array_key_exists('is_active', $data)
            && (int)$data['is_active'] === 1
            && (int)$spot['is_active'] === 0
            && (int)$spot['is_system'] === 0;
        if ($isReactivating) {
            // Look up player level + override
            $pStmt = $pdo->prepare('SELECT level, spot_limit_override FROM players WHERE id = :pid');
            $pStmt->execute([':pid' => (int)$spot['player_id']]);
            $pRow = $pStmt->fetch();
            if ($pRow) {
                $cStmt = $pdo->prepare('
                    SELECT COUNT(*) FROM fishing_spots
                    WHERE player_id = :pid AND is_system = 0
                      AND archived = 0 AND is_active = 1
                ');
                $cStmt->execute([':pid' => (int)$spot['player_id']]);
                $currentCount = (int)$cStmt->fetchColumn();
                $limit = self::computeSpotLimit(
                    (int)$pRow['level'],
                    $pRow['spot_limit_override'] !== null ? (int)$pRow['spot_limit_override'] : null
                );
                if ($currentCount >= $limit) {
                    json_error("Active spot limit reached ({$currentCount}/{$limit}). Deactivate another spot first.", 403);
                }
            }
        }

        $pdo->prepare('UPDATE fishing_spots SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        return ['message' => 'Spot updated'];
    }

    /**
     * Add junk item to a spot.
     */
    public static function addJunkItem(string $ownerUuid, int $spotId, string $itemName, float $rarityWeight = 1.0, string $rarityLabel = 'common'): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        $stmt = $pdo->prepare('SELECT player_id FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);
        if ((int)$spot['player_id'] !== (int)$player['id'] && !(int)$player['is_admin'])
            json_error('Not authorized', 403);

        $pdo->prepare('INSERT INTO spot_junk_items (spot_id, item_name, rarity_weight, rarity_label) VALUES (:sid, :n, :rw, :rl)')
            ->execute([':sid' => $spotId, ':n' => trim($itemName), ':rw' => $rarityWeight, ':rl' => $rarityLabel]);
        return ['message' => "Junk item '$itemName' ($rarityLabel) added"];
    }

    /**
     * List junk items for a spot.
     */
    public static function listJunkItems(int $spotId): array {
        $stmt = db()->prepare('SELECT id, item_name, rarity_weight, rarity_label FROM spot_junk_items WHERE spot_id = :id ORDER BY rarity_weight DESC');
        $stmt->execute([':id' => $spotId]);
        return ['junk_items' => $stmt->fetchAll()];
    }

    /**
     * Remove a junk item.
     */
    public static function removeJunkItem(string $ownerUuid, int $junkId): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');

        $stmt = $pdo->prepare('SELECT sji.spot_id, fs.player_id FROM spot_junk_items sji JOIN fishing_spots fs ON fs.id = sji.spot_id WHERE sji.id = :id');
        $stmt->execute([':id' => $junkId]);
        $item = $stmt->fetch();
        if (!$item) json_error('Item not found');
        if ((int)$item['player_id'] !== (int)$player['id'] && !(int)$player['is_admin'])
            json_error('Not authorized', 403);

        $pdo->prepare('DELETE FROM spot_junk_items WHERE id = :id')->execute([':id' => $junkId]);
        return ['message' => 'Junk item removed'];
    }

    /**
     * Get active buffs for a spot.
     */
    public static function getSpotBuffs(int $spotId): array {
        $stmt = db()->prepare('
            SELECT sb.*, p.display_name AS activated_by_name
            FROM spot_buffs sb LEFT JOIN players p ON p.id = sb.activated_by
            WHERE sb.spot_id = :sid AND sb.expires_at > NOW()
            ORDER BY sb.expires_at ASC
        ');
        $stmt->execute([':sid' => $spotId]);
        return ['buffs' => $stmt->fetchAll()];
    }

    /**
     * Grid map: all public spots grouped by region.
     */
    public static function getGridMap(string $gridName = ''): array {
        $pdo = db();
        $where = 'fs.is_public = 1 AND fs.setup_complete = 1 AND fs.archived = 0';
        $params = [];
        if ($gridName !== '') { $where .= ' AND fs.grid_name = :gn'; $params[':gn'] = $gridName; }

        $stmt = $pdo->prepare("
            SELECT fs.id, fs.name, fs.region_name, fs.grid_name,
                   fs.region_x, fs.region_y, fs.pos_x, fs.pos_y, fs.pos_z,
                   fs.is_active, fs.player_id, fs.spot_level,
                   wt.name AS water_type, p.display_name AS owner_name,
                   (SELECT COUNT(*) FROM spot_junk_items sji WHERE sji.spot_id = fs.id) AS junk_count,
                   (SELECT COUNT(*) FROM spot_buffs sb WHERE sb.spot_id = fs.id AND sb.expires_at > NOW()) AS active_buffs
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            LEFT JOIN players p ON p.id = fs.player_id
            WHERE $where
            ORDER BY fs.grid_name, fs.region_name, fs.name
        ");
        $stmt->execute($params);
        $spots = $stmt->fetchAll();

        // Look up hop_gateway for the requested grid
        $hopGateway = null;
        if ($gridName !== '') {
            $g = $pdo->prepare('SELECT hop_gateway FROM grids WHERE grid_name = :gn');
            $g->execute([':gn' => $gridName]);
            $hopGateway = $g->fetchColumn() ?: null;
        }

        $regions = [];
        foreach ($spots as $spot) {
            $key = $spot['region_name'];
            if (!isset($regions[$key])) {
                $regions[$key] = ['region_name' => $spot['region_name'], 'grid_name' => $spot['grid_name'],
                                  'region_x' => $spot['region_x'], 'region_y' => $spot['region_y'], 'spots' => []];
            }
            $regions[$key]['spots'][] = $spot;
        }
        return ['regions' => array_values($regions), 'hop_gateway' => $hopGateway];
    }

    /**
     * Check status of a spot (for LSL periodic re-check).
     */
    public static function spotStatus(int $spotId): array {
        $stmt = db()->prepare('
            SELECT fs.id, fs.name, wt.name AS water_type,
                   fs.is_active, fs.is_public, fs.is_system, fs.player_id,
                   fs.spot_level, fs.spot_xp, fs.spot_level_ready
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            WHERE fs.id = :id
        ');
        $stmt->execute([':id' => $spotId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['exists' => false, 'is_active' => false];
        }
        $level    = (int)$row['spot_level'];
        $xp       = (int)$row['spot_xp'];
        $xpToNext = Fishing::xpThresholdForNextLevel($level);
        return [
            'exists'          => true,
            'is_active'       => (bool)$row['is_active'],
            'is_public'       => (bool)$row['is_public'],
            'is_system'       => (bool)$row['is_system'],
            'name'            => $row['name'],
            'water_type'      => $row['water_type'],
            'spot_level'      => $level,
            'spot_xp'         => $xp,
            'spot_xp_to_next' => $xpToNext,
            'spot_level_ready'=> (bool)$row['spot_level_ready'],
        ];
    }

    /**
     * List a player's owned spots (web portal).
     */
    public static function mySpots(int $playerId): array {
        $stmt = db()->prepare('
            SELECT fs.id, fs.name, wt.name AS water_type,
                   fs.region_name, fs.pos_x, fs.pos_y, fs.pos_z,
                   fs.is_active, fs.created_at,
                   fs.spot_level, fs.spot_xp, fs.spot_level_ready
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            WHERE fs.player_id = :pid AND fs.is_system = 0 AND fs.archived = 0
            ORDER BY fs.created_at DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['spot_xp_to_next'] = Fishing::xpThresholdForNextLevel((int)$row['spot_level']);
        }
        return $rows;
    }

    /**
     * Get spot ownership stats for a player.
     */
    public static function spotStats(int $playerId, int $playerLevel, ?int $override = null): array {
        $stmt = db()->prepare('
            SELECT COUNT(*) FROM fishing_spots
            WHERE player_id = :pid AND is_system = 0
              AND archived = 0 AND is_active = 1
        ');
        $stmt->execute([':pid' => $playerId]);
        $used = (int)$stmt->fetchColumn();
        return [
            'used'  => $used,
            'limit' => self::computeSpotLimit($playerLevel, $override),
        ];
    }

    /**
     * Owner confirms a pending level-up on their fishing spot.
     * Can also be called by admin (playerId = 0 with $isAdmin = true).
     */
    public static function confirmSpotLevelUp(int $playerId, int $spotId, bool $isAdmin = false): array {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT id, player_id, is_system, spot_level, spot_level_ready
            FROM fishing_spots WHERE id = :id
        ');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);
        if ((int)$spot['is_system']) json_error('System spots cannot be leveled up', 403);
        if (!$isAdmin && (int)$spot['player_id'] !== $playerId) json_error('Not your spot', 403);
        if (!(int)$spot['spot_level_ready']) json_error('Spot is not ready to level up', 400);

        $newLevel   = (int)$spot['spot_level'] + 1;
        $newMod     = Fishing::lootModifierForLevel($newLevel);
        $xpToNext   = Fishing::xpThresholdForNextLevel($newLevel);

        $pdo->prepare('
            UPDATE fishing_spots
            SET spot_level = :lvl, loot_modifier = :mod, spot_level_ready = 0
            WHERE id = :id
        ')->execute([':lvl' => $newLevel, ':mod' => $newMod, ':id' => $spotId]);

        return [
            'success'          => true,
            'new_level'        => $newLevel,
            'new_loot_modifier'=> $newMod,
            'spot_xp_to_next'  => $xpToNext,
            'message'          => 'Spot leveled up to ' . $newLevel . '!',
        ];
    }

    /**
     * Public spot leaderboard: top 50 non-system public spots by level then XP.
     */
    public static function spotLeaderboard(): array {
        $stmt = db()->prepare('
            SELECT fs.id, fs.name, wt.name AS water_type,
                   fs.spot_level, fs.spot_xp,
                   p.display_name AS owner_name,
                   fs.region_name, fs.grid_name,
                   (SELECT COUNT(*) FROM catch_log cl WHERE cl.spot_id = fs.id) AS total_catches
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            LEFT JOIN players p ON p.id = fs.player_id
            WHERE fs.is_system = 0
              AND fs.is_public  = 1
              AND fs.archived   = 0
              AND fs.is_active  = 1
            ORDER BY fs.spot_level DESC, fs.spot_xp DESC
            LIMIT 50
        ');
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Rename a player's spot.
     */
    public static function renameSpot(int $playerId, int $spotId, string $newName): array {
        $newName = trim($newName);
        if (strlen($newName) < 1 || strlen($newName) > 128) {
            json_error('Spot name must be 1-128 characters');
        }
        $stmt = db()->prepare('
            UPDATE fishing_spots SET name = :n
            WHERE id = :id AND player_id = :pid AND is_system = 0
        ');
        $stmt->execute([':n' => $newName, ':id' => $spotId, ':pid' => $playerId]);
        if ($stmt->rowCount() === 0) {
            json_error('Spot not found or not owned by you', 404);
        }
        return ['message' => 'Spot renamed'];
    }

    /**
     * Toggle spot active status.
     */
    public static function toggleSpot(int $playerId, int $spotId, bool $active): array {
        $pdo = db();

        if ($active) {
            $row = $pdo->prepare('SELECT is_active, archived FROM fishing_spots WHERE id = :id AND player_id = :pid AND is_system = 0');
            $row->execute([':id' => $spotId, ':pid' => $playerId]);
            $spot = $row->fetch();
            if (!$spot) json_error('Spot not found or not owned by you', 404);
            if ((int)$spot['archived']) json_error('Cannot activate a deleted spot. Restore it first.', 403);

            if (!(int)$spot['is_active']) {
                $pRow = $pdo->prepare('SELECT level, spot_limit_override FROM players WHERE id = :pid');
                $pRow->execute([':pid' => $playerId]);
                $p = $pRow->fetch();
                if ($p) {
                    $cStmt = $pdo->prepare('SELECT COUNT(*) FROM fishing_spots WHERE player_id = :pid AND is_system = 0 AND archived = 0 AND is_active = 1');
                    $cStmt->execute([':pid' => $playerId]);
                    $count = (int)$cStmt->fetchColumn();
                    $limit = self::computeSpotLimit((int)$p['level'], $p['spot_limit_override'] !== null ? (int)$p['spot_limit_override'] : null);
                    if ($count >= $limit) {
                        json_error("Active spot limit reached ({$count}/{$limit}). Deactivate another spot first.", 403);
                    }
                }
            }
        }

        $stmt = $pdo->prepare('
            UPDATE fishing_spots SET is_active = :a
            WHERE id = :id AND player_id = :pid AND is_system = 0
              AND (archived = 0 OR :a2 = 0)
        ');
        $stmt->execute([':a' => $active ? 1 : 0, ':a2' => $active ? 1 : 0, ':id' => $spotId, ':pid' => $playerId]);
        if ($stmt->rowCount() === 0) {
            json_error('Spot not found or not owned by you', 404);
        }
        return ['message' => $active ? 'Spot activated' : 'Spot deactivated'];
    }

    /**
     * Delete a player's spot.
     */
    public static function deleteSpot(int $playerId, int $spotId): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id FROM fishing_spots WHERE id = :id AND player_id = :pid AND is_system = 0');
        $stmt->execute([':id' => $spotId, ':pid' => $playerId]);
        if (!$stmt->fetch()) json_error('Spot not found or not owned by you', 404);

        // Archive instead of delete so catch_log and leaderboards keep their references
        $pdo->prepare('
            UPDATE fishing_spots
            SET archived = 1, archived_at = NOW(), is_active = 0
            WHERE id = :id
        ')->execute([':id' => $spotId]);
        return ['message' => 'Spot archived. Catch history is preserved.'];
    }

    /**
     * Register a callback URL for server-push updates to the HUD.
     */
    public static function registerCallback(int $tokenId, string $callbackUrl): array {
        $stmt = db()->prepare('UPDATE hud_tokens SET callback_url = :url WHERE id = :id');
        $stmt->execute([':url' => $callbackUrl, ':id' => $tokenId]);
        return ['message' => 'Callback registered'];
    }

    /**
     * Push an update to a player's HUD via their registered callback URL.
     * Silently fails if no URL registered or URL is stale.
     */
    public static function pushToPlayer(int $playerId, array $data): void {
        $stmt = db()->prepare('
            SELECT callback_url FROM hud_tokens
            WHERE player_id = :pid AND is_active = 1 AND callback_url IS NOT NULL
            ORDER BY last_used DESC LIMIT 1
        ');
        $stmt->execute([':pid' => $playerId]);
        $url = $stmt->fetchColumn();
        if (!$url) return;

        $json = json_encode($data);

        // Fire-and-forget HTTP POST to the HUD's URL
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If the URL is dead (404, connection refused, etc.), clear it
        if ($httpCode === 0 || $httpCode >= 400) {
            $stmt = db()->prepare('
                UPDATE hud_tokens SET callback_url = NULL
                WHERE player_id = :pid AND callback_url = :url
            ');
            $stmt->execute([':pid' => $playerId, ':url' => $url]);
        }
    }

    /**
     * Push current equipment state to a player's HUD.
     * Call this after any web portal action that changes equipped gear or points.
     */
    public static function pushEquipUpdate(int $playerId): void {
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT p.level, p.xp, p.fishing_points,
                   p.equipped_bait_id, p.equipped_line_id,
                   bt.name AS bait_name,
                   lt.name AS line_name, lt.weight_lb AS line_weight, lt.visibility AS line_visibility
            FROM players p
            LEFT JOIN bait_types bt ON bt.id = p.equipped_bait_id
            LEFT JOIN line_types lt ON lt.id = p.equipped_line_id
            WHERE p.id = :pid
        ');
        $stmt->execute([':pid' => $playerId]);
        $row = $stmt->fetch();
        if (!$row) return;

        // Get bait quantity
        $baitQty = 0;
        if ($row['equipped_bait_id']) {
            $stmt2 = $pdo->prepare('
                SELECT quantity FROM player_bait
                WHERE player_id = :pid AND bait_id = :bid
            ');
            $stmt2->execute([':pid' => $playerId, ':bid' => $row['equipped_bait_id']]);
            $baitQty = (int)$stmt2->fetchColumn();
        }

        // Get XP to next level
        $stmt2 = $pdo->prepare('
            SELECT xp_required FROM levels WHERE level = :lvl
        ');
        $stmt2->execute([':lvl' => (int)$row['level'] + 1]);
        $nextLvlXp = $stmt2->fetchColumn();
        $xpToNext = $nextLvlXp ? (int)$nextLvlXp - (int)$row['xp'] : 0;

        // Get current level XP threshold for bar calculation
        $stmt2 = $pdo->prepare('SELECT xp_required FROM levels WHERE level = :lvl');
        $stmt2->execute([':lvl' => (int)$row['level']]);
        $curLvlXp = (int)$stmt2->fetchColumn();
        $xpLevelTotal = $nextLvlXp ? (int)$nextLvlXp - $curLvlXp : 0;

        self::pushToPlayer($playerId, [
            'type'            => 'equip_update',
            'level'           => (int)$row['level'],
            'xp'              => (int)$row['xp'],
            'xp_to_next'      => $xpToNext,
            'xp_level_total'  => $xpLevelTotal,
            'fishing_points'  => (int)$row['fishing_points'],
            'bait_id'         => (int)$row['equipped_bait_id'],
            'bait_name'       => $row['bait_name'] ?: '',
            'bait_remaining'  => $baitQty,
            'line_name'       => $row['line_name'] ?: '',
            'line_weight'     => (float)($row['line_weight'] ?? 0),
            'line_visibility' => (float)($row['line_visibility'] ?? 0),
        ]);
    }

    /**
     * Check if a UUID has an account (no auth needed).
     */
    public static function checkAccount(string $uuid): array {
        $stmt = db()->prepare('SELECT id, display_name FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        $player = $stmt->fetch();
        if (!$player) return ['exists' => false];
        return ['exists' => true, 'display_name' => $player['display_name']];
    }

    /**
     * List all paired devices for a player (web portal).
     */
    public static function listDevices(int $playerId): array {
        $stmt = db()->prepare('
            SELECT id, device_name, grid_name, issued_at, last_used, last_ip, is_active
            FROM hud_tokens
            WHERE player_id = :pid AND revoked_at IS NULL
            ORDER BY last_used DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Audit log: who has tried to pair to this account.
     */
    public static function listPairAttempts(int $playerId, int $limit = 50): array {
        $stmt = db()->prepare('
            SELECT attempted_uuid, grid_name, avatar_name, ip_address, succeeded, attempted_at
            FROM hud_pair_attempts
            WHERE player_id = :pid
            ORDER BY attempted_at DESC
            LIMIT ' . (int)$limit
        );
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Admin: count of times this player triggered the 60s mutual exclusion,
     * grouped by the UUID/avatar that got blocked.
     */
    public static function getExclusionStats(int $playerId): array {
        $pdo = db();

        // Total triggers
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM hud_exclusion_triggers WHERE player_id = :pid');
        $stmt->execute([':pid' => $playerId]);
        $total = (int)$stmt->fetchColumn();

        // Per-UUID breakdown with display name where available
        $stmt = $pdo->prepare('
            SELECT
                het.triggered_uuid,
                p.display_name AS triggered_name,
                p.username AS triggered_username,
                COUNT(*) AS trigger_count,
                MAX(het.triggered_at) AS last_triggered
            FROM hud_exclusion_triggers het
            LEFT JOIN players p ON p.uuid = het.triggered_uuid
            WHERE het.player_id = :pid
            GROUP BY het.triggered_uuid, p.display_name, p.username
            ORDER BY trigger_count DESC
            LIMIT 50
        ');
        $stmt->execute([':pid' => $playerId]);
        $byUuid = $stmt->fetchAll();

        return [
            'total_triggers' => $total,
            'by_uuid' => $byUuid,
        ];
    }

    /**
     * Revoke a paired device.
     */
    public static function revokeDevice(int $playerId, int $deviceId): array {
        $stmt = db()->prepare('
            UPDATE hud_tokens SET is_active = 0, revoked_at = NOW()
            WHERE id = :id AND player_id = :pid
        ');
        $stmt->execute([':id' => $deviceId, ':pid' => $playerId]);

        if ($stmt->rowCount() === 0) {
            json_error('Device not found', 404);
        }

        return ['message' => 'Device revoked. The HUD will need to re-pair.'];
    }
}
