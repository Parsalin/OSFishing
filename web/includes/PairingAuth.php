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

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Grid.php';

class PairingAuth {

    /** The account id an avatar UUID is linked to, or null. */
    public static function playerIdForUuid(string $uuid): ?int {
        $stmt = db()->prepare('SELECT player_id FROM player_uuids WHERE uuid = :u');
        $stmt->execute([':u' => strtolower(trim($uuid))]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Avatar name the sim stamps on the request (cannot be set by a script). */
    private static function headerAvatarName(): string {
        $n = trim((string)($_SERVER['HTTP_X_SECONDLIFE_OWNER_NAME'] ?? ''));
        return mb_substr($n, 0, 128);
    }

    /**
     * Step 1: HUD requests a pairing code for a UUID.
     *
     * An avatar already linked to an account gets a code only that account
     * can claim. An avatar with no account still gets a code: whoever claims
     * it on the website (logged in, after confirming) links this avatar to
     * their account. That is how a second avatar joins an existing account.
     */
    public static function requestCode(string $uuid, string $gridName = ''): array {
        $pdo = db();
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            json_error('Invalid avatar UUID', 400);
        }

        $playerId   = self::playerIdForUuid($uuid);
        $avatarName = self::headerAvatarName();

        // Log the attempt regardless of outcome
        $pdo->prepare('
            INSERT INTO hud_pair_attempts (player_id, attempted_uuid, grid_name, avatar_name, ip_address, succeeded)
            VALUES (:pid, :uuid, :gn, :av, :ip, 0)
        ')->execute([
            ':pid'  => $playerId,
            ':uuid' => $uuid,
            ':gn'   => $gridName ?: null,
            ':av'   => $avatarName !== '' ? $avatarName : null,
            ':ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        // Invalidate any older unclaimed codes for this UUID (any grid)
        $stmt = $pdo->prepare('
            UPDATE hud_pairing_codes
            SET expires_at = UTC_TIMESTAMP()
            WHERE player_uuid = :uuid AND claimed = 0 AND expires_at > UTC_TIMESTAMP()
        ');
        $stmt->execute([':uuid' => $uuid]);

        // Generate a unique 6-digit code (formatted XXX-XXX)
        $tries = 0;
        do {
            $n = (string)random_int(100000, 999999);
            $code = substr($n, 0, 3) . '-' . substr($n, 3, 3);

            $stmt = $pdo->prepare('
                SELECT id FROM hud_pairing_codes
                WHERE code = :c AND expires_at > UTC_TIMESTAMP() AND claimed = 0
            ');
            $stmt->execute([':c' => $code]);
            $exists = $stmt->fetch();
            $tries++;
        } while ($exists && $tries < 10);

        $expires = gmdate('Y-m-d H:i:s', time() + 300); // 5 minute expiry

        $stmt = $pdo->prepare('
            INSERT INTO hud_pairing_codes (code, player_uuid, avatar_name, grid_name, player_id, expires_at)
            VALUES (:c, :uuid, :an, :gn, :pid, :exp)
        ');
        $stmt->execute([
            ':c'    => $code,
            ':uuid' => $uuid,
            ':an'   => $avatarName !== '' ? $avatarName : null,
            ':gn'   => $gridName ?: null,
            ':pid'  => $playerId,
            ':exp'  => $expires,
        ]);

        return [
            'pairing_code' => $code,
            'expires_in'   => 300,
            'new_avatar'   => $playerId === null,
            'pair_url'     => self::baseUrl() . '/pair?uuid=' . urlencode($uuid) . '&code=' . urlencode($code),
            'message'      => $playerId === null
                ? 'This avatar is not linked to an account yet. Open the link to add it to your account, or create one.'
                : 'Enter this code on the web portal under Settings > Pair HUD',
        ];
    }

    /**
     * A live, unclaimed code for this avatar, with what claiming it would do
     * for $playerId: 'pair' (avatar already on this account), 'link' (avatar
     * on no account — claiming links it), or 'other_account'.
     */
    public static function describeCode(string $uuid, string $code, int $playerId): ?array {
        $code = self::normalizeCode($code);
        $stmt = db()->prepare('
            SELECT * FROM hud_pairing_codes
            WHERE code = :c AND player_uuid = :u AND claimed = 0 AND expires_at > UTC_TIMESTAMP()
        ');
        $stmt->execute([':c' => $code, ':u' => strtolower(trim($uuid))]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $owner = self::playerIdForUuid($row['player_uuid']);
        $row['effect'] = $owner === null ? 'link' : ($owner === $playerId ? 'pair' : 'other_account');
        $row['grid_identity'] = $row['grid_name'] ? Grid::identityOf($row['grid_name']) : '';
        return $row;
    }

    /**
     * Public base URL of this install, e.g. "https://osfishing.flamesfall.net".
     *
     * Prefers the SITE_URL constant when config.php defines it. Falls back to
     * the request's own scheme+host so a fresh install works before anyone
     * sets it. Set SITE_URL in production: this value is handed to the HUD as
     * a clickable pair link, and Host is client-supplied, so an attacker who
     * could reach the API could otherwise induce a link to their own domain.
     */
    private static function baseUrl(): string {
        if (defined('SITE_URL') && SITE_URL !== '') {
            return rtrim(SITE_URL, '/');
        }
        $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        // Strip anything that cannot legally appear in a host[:port].
        $host   = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host);
        return $scheme . '://' . $host;
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
            require_once __DIR__ . '/Throttle.php';
            Throttle::hit('pair:' . strtolower(trim($uuid)));
            json_error('Pairing code not found', 404);
        }

        if (strtotime($row['expires_at']) < time() && !$row['claimed']) {
            json_error('Pairing code expired. Request a new one.', 410);
        }

        if (!$row['claimed']) {
            return ['claimed' => false, 'message' => 'Waiting for code entry on web portal...'];
        }

        // Hand the token out once (BP 5). The atomic UPDATE means two polls
        // racing can't both get it. A HUD whose reply was lost just keeps
        // waiting until the code expires and then asks for a new one.
        $stmt = $pdo->prepare('UPDATE hud_pairing_codes SET token_collected_at = UTC_TIMESTAMP()
                               WHERE id = :id AND token_collected_at IS NULL');
        $stmt->execute([':id' => $row['id']]);
        if ($stmt->rowCount() !== 1) {
            return ['claimed' => false, 'message' => 'This code was already used. Touch the HUD to pair again.'];
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
     *
     * The code's avatar must be on this account already, or on no account —
     * then claiming links it here, but only with $confirmLink: without it the
     * caller gets needs_confirm + who the avatar is, so the player confirms
     * "add this avatar to my account" knowingly. (Entering someone else's
     * code would otherwise hand their avatar control of your account.)
     */
    public static function claimCode(int $playerId, string $code, string $deviceName = 'HUD', bool $confirmLink = false): array {
        $code = self::normalizeCode($code);
        $pdo = db();

        $stmt = $pdo->prepare('
            SELECT * FROM hud_pairing_codes
            WHERE code = :c AND claimed = 0 AND expires_at > UTC_TIMESTAMP()
              AND (player_id = :pid OR player_id IS NULL)
        ');
        $stmt->execute([':c' => $code, ':pid' => $playerId]);
        $row = $stmt->fetch();

        if (!$row) {
            require_once __DIR__ . '/Throttle.php';
            Throttle::hit('claim:' . $playerId);
            json_error('Invalid or expired pairing code, or it does not belong to your account.', 400);
        }

        $avatarUuid = strtolower($row['player_uuid']);
        $gridName   = $row['grid_name'] ?? null;
        $owner      = self::playerIdForUuid($avatarUuid);
        if ($owner !== null && $owner !== $playerId) {
            json_error('That avatar belongs to a different account.', 403);
        }
        $linking = $owner === null;
        if ($linking && !$confirmLink) {
            return [
                'needs_confirm' => true,
                'avatar_uuid'   => $avatarUuid,
                'avatar_name'   => $row['avatar_name'],
                'grid'          => $gridName ? Grid::identityOf($gridName) : '',
                'message'       => 'This avatar is not on your account yet. Confirm to link it.',
            ];
        }

        // Generate the device token (long random string)
        $token = bin2hex(random_bytes(48));

        $pdo->beginTransaction();
        try {
            if ($linking) {
                $pdo->prepare('
                    INSERT INTO player_uuids (player_id, uuid, grid_identity, avatar_name, last_used_at)
                    VALUES (:pid, :u, :gi, :n, UTC_TIMESTAMP())
                ')->execute([
                    ':pid' => $playerId, ':u' => $avatarUuid,
                    ':gi'  => $gridName ? Grid::identityOf($gridName) : null,
                    ':n'   => $row['avatar_name'],
                ]);
            }

            // Re-pair of the SAME avatar on the SAME grid revokes that token only.
            // Other grids and other avatars stay alive.
            $pdo->prepare('UPDATE hud_tokens
                           SET is_active = 0, revoked_at = UTC_TIMESTAMP()
                           WHERE player_id = :pid AND grid_name <=> :gn
                             AND (avatar_uuid = :au OR avatar_uuid IS NULL) AND revoked_at IS NULL')
                ->execute([':pid' => $playerId, ':gn' => $gridName, ':au' => $avatarUuid]);

            // Mark this pairing attempt as succeeded
            $pdo->prepare('UPDATE hud_pair_attempts
                           SET succeeded = 1
                           WHERE attempted_uuid = :uuid AND grid_name <=> :gn
                             AND succeeded = 0
                           ORDER BY id DESC LIMIT 1')
                ->execute([':uuid' => $row['player_uuid'] ?? '', ':gn' => $gridName]);

            // Insert the token with grid scope
            $stmt = $pdo->prepare('
                INSERT INTO hud_tokens (player_id, avatar_uuid, token, device_name, grid_name, last_used, last_ip)
                VALUES (:pid, :au, :tok, :dn, :gn, UTC_TIMESTAMP(), :ip)
            ');
            $stmt->execute([
                ':pid' => $playerId,
                ':au'  => $avatarUuid,
                ':tok' => $token,
                ':dn'  => $deviceName,
                ':gn'  => $gridName,
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $tokenId = (int)$pdo->lastInsertId();

            // Mark the pairing code as claimed
            $stmt = $pdo->prepare('
                UPDATE hud_pairing_codes
                SET claimed = 1, claimed_at = UTC_TIMESTAMP(), issued_token_id = :tid
                WHERE id = :id
            ');
            $stmt->execute([':tid' => $tokenId, ':id' => $row['id']]);

            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'message'       => $linking ? 'Avatar linked and HUD paired!' : 'HUD paired successfully!',
            'device_id'     => $tokenId,
            'linked_avatar' => $linking,
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
        if (!empty($row['is_banned'])) {
            json_error('Account banned', 403);
        }

        // The token belongs to the avatar that paired it. (Tokens from before
        // linked avatars have avatar_uuid backfilled to the account's uuid.)
        $tokenAvatar = strtolower((string)($row['avatar_uuid'] ?: $row['uuid']));
        if (strcasecmp($tokenAvatar, $uuid) !== 0) {
            json_error('UUID mismatch', 401);
        }

        // Every HUD request comes from a sim, and the sim stamps
        // X-Secondlife-Owner-Key; a script cannot set it. Require it and
        // cross-check: a token leaked from one player's HUD cannot be replayed
        // from another avatar's attachment. Only FishingHUD signs requests, so
        // nothing legitimate arrives without it. curl tests must send it.
        $ownerKey = $_SERVER['HTTP_X_SECONDLIFE_OWNER_KEY'] ?? '';
        if ($ownerKey === '') {
            json_error('not_from_a_sim', 401);
        }
        if (strcasecmp($ownerKey, $uuid) !== 0) {
            error_log("owner_mismatch: header={$ownerKey} uuid={$uuid} action={$action}");
            json_error('owner_mismatch', 401);
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
              AND last_used > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
            LIMIT 1
        ');
        $stmt->execute([':pid' => $playerId, ':tid' => $tokenId]);
        $blockingRow = $stmt->fetch();
        if ($blockingRow) {
            // Find the UUID currently holding the lock
            $blockerStmt = $pdo->prepare('SELECT COALESCE(ht.avatar_uuid, p.uuid) FROM hud_tokens ht
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
            UPDATE hud_tokens SET is_active = 1, last_nonce = :n, last_used = UTC_TIMESTAMP(), last_ip = :ip
            WHERE id = :tid
        ');
        $stmt->execute([
            ':n'   => $nonce,
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':tid' => $tokenId,
        ]);
        // Record the grid an avatar plays from, the first time it's known.
        // Account recovery only trusts an avatar on the grid it's linked on.
        $pdo->prepare('UPDATE player_uuids SET last_used_at = UTC_TIMESTAMP(),
                              grid_identity = COALESCE(grid_identity, :gi)
                        WHERE uuid = :u')
            ->execute([':u' => $tokenAvatar, ':gi' => !empty($row['grid_name']) ? Grid::identityOf($row['grid_name']) : null]);

        // Return the player record. 'uuid' is the avatar making this request
        // (an account can have several); 'account_uuid' is its primary one.
        return [
            'id' => (int)$row['player_id'],
            'uuid' => $tokenAvatar,
            'account_uuid' => $row['uuid'],
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
     * Create an account for the avatar whose HUD issued $code.
     *
     * The live, unclaimed pairing code is the proof that the person
     * registering wears that avatar's HUD; a typed UUID alone would let
     * anyone squat someone else's avatar before they sign up. On success the
     * code is claimed for the new account, so the HUD pairs at once.
     */
    public static function webRegister(string $uuid, string $code, string $username, string $password, string $displayName = ''): array {
        $pdo = db();
        $uuid = strtolower(trim($uuid));

        // Validate UUID format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            json_error('Invalid avatar UUID format');
        }

        $code = self::normalizeCode($code);
        $stmt = $pdo->prepare('
            SELECT grid_name FROM hud_pairing_codes
            WHERE code = :c AND player_uuid = :u AND claimed = 0 AND expires_at > UTC_TIMESTAMP()
        ');
        $stmt->execute([':c' => $code, ':u' => $uuid]);
        $codeRow = $stmt->fetch();
        if (!$codeRow) {
            json_error('This sign-up link has expired. Touch your HUD for a new one (links last 5 minutes).', 410);
        }
        $gridIdentity = $codeRow['grid_name'] ? Grid::identityOf($codeRow['grid_name']) : null;

        // Check if UUID already has an account
        $stmt = $pdo->prepare('SELECT id FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :uuid)');
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

        // players + player_uuids together: player_uuids.uuid is UNIQUE, so a
        // second sign-up racing this one fails there and leaves no orphan row.
        $pdo->beginTransaction();
        try {
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
            $pdo->prepare('INSERT INTO player_uuids (player_id, uuid, grid_identity, avatar_name) VALUES (:pid, :u, :gi, :n)')
                ->execute([':pid' => $playerId, ':u' => $uuid, ':gi' => $gridIdentity, ':n' => $displayName]);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') json_error('An account already exists for this avatar or username. Try logging in instead.', 409);
            throw $e;
        }

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

        // Pair the HUD that issued the code; its next poll collects the token.
        self::claimCode($playerId, $code, 'HUD');

        return [
            'message'   => 'Account created and your HUD is paired! You can now log in.',
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
        $stmt = $pdo->prepare('SELECT id, level, is_admin, spot_limit_override FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
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
            $gridClause    = ' AND ' . Grid::sameGridSql('fs.grid_name', ':gi');
            $params[':gi'] = Grid::identityOf($gridName);
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
                $sysWhere      .= ' AND ' . Grid::sameGridSql('fs.grid_name', ':gi');
                $sysParams[':gi'] = Grid::identityOf($gridName);
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
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
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

        $stmt = $pdo->prepare('SELECT id, level, is_admin, spot_limit_override FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :uuid)');
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
        // Same region means same region name AND same grid: region names
        // repeat across grids.
        $posParams = [':r' => $region, ':x' => $posX, ':y' => $posY, ':z' => $posZ];
        $gridCond  = '';
        if ($gridName !== '') {
            $gridCond = ' AND ' . Grid::sameGridSql('grid_name', ':gi');
            $posParams[':gi'] = Grid::identityOf($gridName);
        }
        $stmt = $pdo->prepare('
            SELECT id, archived, player_id FROM fishing_spots
            WHERE region_name = :r AND ABS(pos_x - :x) < 3 AND ABS(pos_y - :y) < 3 AND ABS(pos_z - :z) < 3' . $gridCond . '
            ORDER BY archived ASC
            LIMIT 1
        ');
        $stmt->execute($posParams);
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
        $stmt = $pdo->prepare('SELECT id, level, is_admin, spot_limit_override FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :uuid)');
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
        $stmt = db()->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :uuid)');
        $stmt->execute([':uuid' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');
        return self::updateSpotFor($player, $spotId, $data);
    }

    /**
     * Change a spot's name / active / public settings as $player (a players
     * row with id + is_admin). Owner, or an admin for any spot. Archived
     * spots can't be changed. Used by the prim's dialog and the spot page.
     */
    public static function updateSpotFor(array $player, int $spotId, array $data): array {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT * FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);

        if ((int)$spot['player_id'] !== (int)$player['id'] && !(int)$player['is_admin'])
            json_error('Not authorized', 403);
        if ((int)$spot['archived']) json_error('This spot was deleted. Rez a spot to restore it.', 403);

        $allowed = ['name', 'is_active', 'is_public'];
        $sets = []; $params = [':id' => $spotId];
        foreach ($allowed as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = $data[$f];
            if ($f === 'name') {
                $v = trim((string)$v);
                if ($v === '' || mb_strlen($v) > 128) json_error('Spot name must be 1-128 characters');
            } else {
                $v = (int)(bool)(int)$v;
            }
            $sets[] = "$f = :$f"; $params[":$f"] = $v;
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
        $stmt = $pdo->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :uuid)');
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
        $stmt = db()->prepare('SELECT id, is_admin FROM players WHERE id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :uuid)');
        $stmt->execute([':uuid' => $ownerUuid]);
        $player = $stmt->fetch();
        if (!$player) json_error('Player not found');
        return self::removeJunkItemFor($player, $junkId);
    }

    /** Remove a junk item as $player (owner of its spot, or admin). */
    public static function removeJunkItemFor(array $player, int $junkId): array {
        $pdo = db();
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
            WHERE sb.spot_id = :sid AND sb.expires_at > UTC_TIMESTAMP()
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
        $identity = $gridName !== '' ? Grid::identityOf($gridName) : '';
        if ($identity !== '') { $where .= ' AND ' . Grid::sameGridSql('fs.grid_name', ':gi'); $params[':gi'] = $identity; }

        $stmt = $pdo->prepare("
            SELECT fs.id, fs.name, fs.region_name, fs.grid_name,
                   fs.region_x, fs.region_y, fs.pos_x, fs.pos_y, fs.pos_z,
                   fs.is_active, fs.player_id, fs.spot_level,
                   wt.name AS water_type, p.display_name AS owner_name,
                   (SELECT COUNT(*) FROM spot_junk_items sji WHERE sji.spot_id = fs.id) AS junk_count,
                   (SELECT COUNT(*) FROM spot_buffs sb WHERE sb.spot_id = fs.id AND sb.expires_at > UTC_TIMESTAMP()) AS active_buffs
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            LEFT JOIN players p ON p.id = fs.player_id
            WHERE $where
            ORDER BY fs.grid_name, fs.region_name, fs.name
        ");
        $stmt->execute($params);
        $spots = $stmt->fetchAll();

        // Look up hop_gateway for the requested grid
        $hopGateway = $identity !== '' ? Grid::hopGatewayFor($identity) : null;

        $regions = [];
        foreach ($spots as $spot) {
            // Region names repeat across grids: key on grid + region.
            $key = Grid::identityOf((string)$spot['grid_name']) . '|' . $spot['region_name'];
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
                   fs.is_active, fs.is_public, fs.is_system, fs.player_id, fs.archived,
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
            'archived'        => (bool)$row['archived'],
            'name'            => $row['name'],
            'water_type'      => $row['water_type'],
            'spot_level'      => $level,
            'spot_xp'         => $xp,
            'spot_xp_to_next' => $xpToNext,
            'spot_level_ready'=> (bool)$row['spot_level_ready'],
        ];
    }

    /**
     * Everything the spot page shows (opened by clicking the spot in-world,
     * or from My Spots). Anyone logged in may view a live spot; can_manage
     * (owner, or admin) unlocks the settings. A deleted spot is only shown
     * to whoever can manage it.
     */
    public static function spotDetail(array $player, int $spotId): array {
        require_once __DIR__ . '/Buff.php';
        $pdo = db();
        $stmt = $pdo->prepare('
            SELECT fs.id, fs.name, fs.player_id, wt.name AS water_type, wt.min_level AS water_min_level,
                   fs.region_name, fs.grid_name, fs.region_x, fs.region_y,
                   fs.pos_x, fs.pos_y, fs.pos_z,
                   fs.is_active, fs.is_public, fs.is_system, fs.archived, fs.created_at,
                   fs.spot_level, fs.spot_xp, fs.spot_level_ready, fs.loot_modifier,
                   p.display_name AS owner_name
            FROM fishing_spots fs
            JOIN water_types wt ON wt.id = fs.water_type_id
            LEFT JOIN players p ON p.id = fs.player_id
            WHERE fs.id = :id
        ');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);

        $isOwner   = (int)$spot['player_id'] === (int)$player['id'] && !(int)$spot['is_system'];
        $canManage = $isOwner || (int)$player['is_admin'];
        if ((int)$spot['archived'] && !$canManage) json_error('This spot is no longer in use', 404);

        $level = (int)$spot['spot_level'];
        $spot['spot_xp_to_next'] = Fishing::xpThresholdForNextLevel($level);

        $identity = Grid::identityOf((string)$spot['grid_name']);
        $g = $pdo->prepare("SELECT nickname FROM grids WHERE grid_identity = :gi AND nickname IS NOT NULL AND nickname <> '' LIMIT 1");
        $g->execute([':gi' => $identity]);
        $spot['grid_label']  = $g->fetchColumn() ?: $identity;
        $spot['hop_gateway'] = $identity !== '' ? Grid::hopGatewayFor($identity) : null;

        // When the in-world prim last checked in (null = never / swept)
        $ls = $pdo->prepare("SELECT MAX(last_seen) FROM prim_callbacks WHERE prim_type = 'fishing_spot' AND ref_id = :id");
        $ls->execute([':id' => $spotId]);
        $spot['prim_last_seen'] = $ls->fetchColumn() ?: null;

        $st = $pdo->prepare('
            SELECT COUNT(*) AS catches, COUNT(DISTINCT player_id) AS anglers,
                   SUM(caught_at >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS today
            FROM catch_log WHERE spot_id = :id
        ');
        $st->execute([':id' => $spotId]);
        $stats = $st->fetch();

        $best = $pdo->prepare('
            SELECT fsp.name AS fish_name, cl.weight, rt.name AS rarity_name, p.display_name AS angler, cl.caught_at
            FROM catch_log cl
            JOIN fish_species fsp ON fsp.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = cl.rarity_id
            JOIN players p ON p.id = cl.player_id
            WHERE cl.spot_id = :id ORDER BY cl.weight DESC LIMIT 1
        ');
        $best->execute([':id' => $spotId]);

        $recent = $pdo->prepare('
            SELECT fsp.name AS fish_name, cl.weight, rt.name AS rarity_name, p.display_name AS angler, cl.caught_at
            FROM catch_log cl
            JOIN fish_species fsp ON fsp.id = cl.fish_species_id
            JOIN rarity_tiers rt ON rt.id = cl.rarity_id
            JOIN players p ON p.id = cl.player_id
            WHERE cl.spot_id = :id ORDER BY cl.caught_at DESC LIMIT 8
        ');
        $recent->execute([':id' => $spotId]);

        // Every fish this water can give, with what the viewer has caught
        // HERE. Fish they haven't caught come back nameless ("??" on the
        // page): never spoil a discovery.
        $mf = $pdo->prepare('
            SELECT fsp.id, fsp.name, rt.name AS rarity_name,
                   MAX(cl.weight) AS best_weight, COUNT(cl.id) AS times
            FROM fish_species fsp
            JOIN fish_water_types fwt ON fwt.fish_id = fsp.id
            JOIN fishing_spots s ON s.id = :sid AND s.water_type_id = fwt.water_type_id
            JOIN rarity_tiers rt ON rt.id = fsp.rarity_id
            LEFT JOIN catch_log cl ON cl.fish_species_id = fsp.id
                 AND cl.spot_id = :sid2 AND cl.player_id = :pid
            WHERE fsp.is_active = 1
            GROUP BY fsp.id, fsp.name, rt.id, rt.name
            ORDER BY (COUNT(cl.id) = 0), rt.id, fsp.id
        ');
        $mf->execute([':sid' => $spotId, ':sid2' => $spotId, ':pid' => (int)$player['id']]);
        $myFish = [];
        foreach ($mf->fetchAll() as $f) {
            $caught = (int)$f['times'] > 0;
            $myFish[] = [
                'caught'      => $caught,
                'name'        => $caught ? $f['name'] : null,
                'rarity_name' => $caught ? $f['rarity_name'] : null,
                'best_weight' => $caught ? round((float)$f['best_weight'], 2) : null,
                'times'       => (int)$f['times'],
            ];
        }

        $out = [
            'spot'       => $spot,
            'my_fish'    => $myFish,
            'can_manage' => $canManage,
            'is_owner'   => $isOwner,
            'stats'      => [
                'catches' => (int)$stats['catches'],
                'anglers' => (int)$stats['anglers'],
                'today'   => (int)$stats['today'],
                'best'    => $best->fetch() ?: null,
            ],
            'recent'     => $recent->fetchAll(),
            'buffs'      => Buff::getSpotBuffs($spotId)['buffs'],
            'junk_items' => self::listJunkItems($spotId)['junk_items'],
        ];
        if ($isOwner) {
            $p = $pdo->prepare('SELECT level, spot_limit_override FROM players WHERE id = :id');
            $p->execute([':id' => (int)$player['id']]);
            $pr = $p->fetch();
            $out['spot_stats'] = self::spotStats((int)$player['id'], (int)$pr['level'],
                $pr['spot_limit_override'] !== null ? (int)$pr['spot_limit_override'] : null);
        }
        return $out;
    }

    /**
     * Tell the in-world spot its settings changed on the website, so its
     * hover text and player detection follow. Queued if the prim is offline
     * (flushed on its next heartbeat); a newer refresh replaces an older one.
     */
    public static function pushSpotRefresh(int $spotId, bool $removed = false): void {
        require_once __DIR__ . '/PrimCallback.php';
        PrimCallback::pushToSpot($spotId, ['type' => $removed ? 'spot_removed' : 'spot_refresh']);
    }

    /**
     * Owner pressed "Add junk" on the spot page: ask the in-world spot to
     * show its add-junk box (it needs objects from its own inventory).
     * Sent live only, never queued: a prompt popping up hours later would
     * be confusing. The spot shows it only if its owner is in the region.
     */
    public static function spotJunkPrompt(array $player, int $spotId): array {
        require_once __DIR__ . '/PushQueue.php';
        $stmt = db()->prepare('SELECT player_id, is_system, archived FROM fishing_spots WHERE id = :id');
        $stmt->execute([':id' => $spotId]);
        $spot = $stmt->fetch();
        if (!$spot) json_error('Spot not found', 404);
        $isOwner = (int)$spot['player_id'] === (int)$player['id'] && !(int)$spot['is_system'];
        if (!$isOwner && !(int)$player['is_admin']) json_error('Not authorized', 403);
        if ((int)$spot['archived']) json_error('This spot was deleted', 403);

        $u = db()->prepare("SELECT callback_url FROM prim_callbacks WHERE prim_type = 'fishing_spot' AND ref_id = :id");
        $u->execute([':id' => $spotId]);
        $urls = $u->fetchAll(PDO::FETCH_COLUMN);
        if (!$urls) json_error('The spot isn\'t online right now. Go to it in-world and try again.');
        foreach ($urls as $url) PushQueue::sendLater($url, ['type' => 'junk_prompt']);
        return ['message' => 'Sent. A box from the spot will ask which object to add (you need to be in its region).'];
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
            SET archived = 1, archived_at = UTC_TIMESTAMP(), is_active = 0
            WHERE id = :id
        ')->execute([':id' => $spotId]);
        return ['message' => 'Spot archived. Catch history is preserved.'];
    }

    /**
     * Register a callback URL for server-push updates to the HUD.
     */
    public static function registerCallback(int $tokenId, string $callbackUrl): array {
        require_once __DIR__ . '/PushQueue.php';

        $g = db()->prepare('SELECT grid_name FROM hud_tokens WHERE id = :id');
        $g->execute([':id' => $tokenId]);
        Grid::checkPushLater((string)$g->fetchColumn(), $callbackUrl);

        $stmt = db()->prepare('
            UPDATE hud_tokens SET callback_url = :url, no_http_in = 0 WHERE id = :id
        ');
        $stmt->execute([':url' => $callbackUrl, ':id' => $tokenId]);

        // A HUD that just registered a URL is reachable right now, and may
        // have missed pushes while detached. Deliver them once this reply
        // has gone out.
        $stmt = db()->prepare('SELECT player_id FROM hud_tokens WHERE id = :id');
        $stmt->execute([':id' => $tokenId]);
        $playerId = (int)$stmt->fetchColumn();

        if ($playerId) PushQueue::later(static fn() => PushQueue::flushPlayer($playerId, $callbackUrl));

        return ['message' => 'Callback registered'];
    }

    /**
     * Record that this HUD cannot receive pushes at all — the grid denied
     * llRequestURL(), so delivery for it is pull-based only.
     */
    public static function markNoHttpIn(int $tokenId, string $who = '', string $region = ''): array {
        db()->prepare('
            UPDATE hud_tokens SET no_http_in = 1, callback_url = NULL WHERE id = :id
        ')->execute([':id' => $tokenId]);
        $g = db()->prepare('SELECT grid_name FROM hud_tokens WHERE id = :id');
        $g->execute([':id' => $tokenId]);
        $gridName = (string)$g->fetchColumn();
        if ($gridName !== '') Grid::markNoHttpIn($gridName, $who, $region);
        return ['message' => 'Recorded: no HTTP-in on this grid'];
    }

    /**
     * Push an update to a player's HUD via their registered callback URL.
     * Queues the message if the HUD is unreachable — never drops it.
     */
    public static function pushToPlayer(int $playerId, array $data): void {
        require_once __DIR__ . '/PushQueue.php';

        $stmt = db()->prepare('
            SELECT callback_url FROM hud_tokens
            WHERE player_id = :pid AND is_active = 1 AND revoked_at IS NULL
              AND callback_url IS NOT NULL
            ORDER BY last_used DESC LIMIT 1
        ');
        $stmt->execute([':pid' => $playerId]);
        $url = $stmt->fetchColumn();

        // No callback URL — the HUD is detached, or the grid denies
        // llRequestURL() so it can never be pushed to. Queue it; it will be
        // delivered on the HUD's next inbound contact.
        if (!$url) {
            PushQueue::queueForPlayer($playerId, $data);
            return;
        }

        // Sent after the reply (PushQueue outbox), so a slow sim never
        // delays the request that caused the push.
        PushQueue::sendLater($url, $data, static function (array $res) use ($playerId, $data, $url) {
            if ($res['ok']) return;

            // Requeue rather than drop: a transient failure used to lose the
            // message silently.
            PushQueue::queueForPlayer($playerId, $data);

            // Only clear the URL when the sim says it is genuinely gone. A
            // timeout or a 5xx means "not right now", not "never again".
            if ($res['dead']) {
                db()->prepare('
                    UPDATE hud_tokens SET callback_url = NULL
                    WHERE player_id = :pid AND callback_url = :url
                ')->execute([':pid' => $playerId, ':url' => $url]);
            }
        });
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
        // Exists or not, nothing more: it's unauthenticated, so returning the
        // display name let anyone look up the account name for any UUID.
        return ['exists' => self::playerIdForUuid($uuid) !== null];
    }

    /**
     * List all paired devices for a player (web portal).
     */
    public static function listDevices(int $playerId): array {
        $stmt = db()->prepare('
            SELECT id, device_name, hud_version, grid_name, avatar_uuid, issued_at, last_used, last_ip, is_active
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
            LEFT JOIN player_uuids pu ON pu.uuid = het.triggered_uuid
            LEFT JOIN players p ON p.id = pu.player_id
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

    /** Avatars linked to an account, primary first. */
    public static function linkedAvatars(int $playerId): array {
        $stmt = db()->prepare('
            SELECT pu.uuid, pu.grid_identity, pu.avatar_name, pu.linked_at, pu.last_used_at,
                   (pu.uuid = LOWER(p.uuid) COLLATE utf8mb4_unicode_ci) AS is_primary,
                   (SELECT COUNT(*) FROM hud_tokens ht
                     WHERE ht.player_id = pu.player_id AND ht.avatar_uuid = pu.uuid AND ht.revoked_at IS NULL) AS hud_count,
                   (SELECT MAX(g.nickname) FROM grids g WHERE g.grid_identity = pu.grid_identity) AS grid_nickname
              FROM player_uuids pu
              JOIN players p ON p.id = pu.player_id
             WHERE pu.player_id = :pid
             ORDER BY is_primary DESC, pu.last_used_at DESC
        ');
        $stmt->execute([':pid' => $playerId]);
        return $stmt->fetchAll();
    }

    /**
     * Remove an avatar from an account and revoke its HUDs. The last avatar
     * can't be removed. If it was the primary, another becomes primary.
     */
    public static function unlinkAvatar(int $playerId, string $uuid): array {
        $pdo = db();
        $uuid = strtolower(trim($uuid));
        $owner = self::playerIdForUuid($uuid);
        if ($owner !== $playerId) json_error('That avatar is not on this account', 404);
        $count = $pdo->prepare('SELECT COUNT(*) FROM player_uuids WHERE player_id = :pid');
        $count->execute([':pid' => $playerId]);
        if ((int)$count->fetchColumn() <= 1) json_error('This is the only avatar on the account; it cannot be removed.', 400);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE hud_tokens SET is_active = 0, revoked_at = UTC_TIMESTAMP()
                            WHERE player_id = :pid AND avatar_uuid = :u AND revoked_at IS NULL')
                ->execute([':pid' => $playerId, ':u' => $uuid]);
            $pdo->prepare('DELETE FROM player_uuids WHERE player_id = :pid AND uuid = :u')
                ->execute([':pid' => $playerId, ':u' => $uuid]);
            $prim = $pdo->prepare('SELECT LOWER(uuid) FROM players WHERE id = :pid');
            $prim->execute([':pid' => $playerId]);
            if ($prim->fetchColumn() === $uuid) {
                $next = $pdo->prepare('SELECT uuid FROM player_uuids WHERE player_id = :pid ORDER BY last_used_at DESC, id LIMIT 1');
                $next->execute([':pid' => $playerId]);
                $pdo->prepare('UPDATE players SET uuid = :u WHERE id = :pid')
                    ->execute([':u' => $next->fetchColumn(), ':pid' => $playerId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['message' => 'Avatar removed from the account and its HUDs revoked.'];
    }

    /**
     * Revoke a paired device.
     */
    public static function revokeDevice(int $playerId, int $deviceId): array {
        $stmt = db()->prepare('
            UPDATE hud_tokens SET is_active = 0, revoked_at = UTC_TIMESTAMP()
            WHERE id = :id AND player_id = :pid
        ');
        $stmt->execute([':id' => $deviceId, ':pid' => $playerId]);

        if ($stmt->rowCount() === 0) {
            json_error('Device not found', 404);
        }

        return ['message' => 'Device revoked. The HUD will need to re-pair.'];
    }
}
