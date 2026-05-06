<?php
/**
 * Fishing Game - Authentication
 * Handles HUD HMAC auth and web session auth.
 */

require_once __DIR__ . '/../config.php';

class Auth {

    /**
     * Authenticate an in-world HUD request.
     * Expects POST params: uuid, timestamp, signature
     * Signature = HMAC-SHA256 of "uuid:timestamp" with HUD_SECRET
     *
     * Returns the player row or dies with error.
     */
    public static function requireHUD(): array {
        $uuid      = require_param('uuid');
        $timestamp = require_param('timestamp');
        $signature = require_param('signature');

        // Reject requests older than 5 minutes (replay protection)
        if (abs(time() - (int)$timestamp) > 300) {
            json_error('Request expired', 401);
        }

        $expected = hash_hmac('sha256', "{$uuid}:{$timestamp}", HUD_SECRET);
        if (!hash_equals($expected, $signature)) {
            json_error('Invalid signature', 401);
        }

        $player = self::getPlayerByUUID($uuid);
        if (!$player) {
            json_error('Player not found. Attach HUD to register.', 404);
        }

        return $player;
    }

    /**
     * Authenticate a web portal request via session token.
     * Expects a session cookie or Authorization header.
     *
     * Returns the player row or dies with error.
     */
    public static function requireWeb(): array {
        $sessionId = $_COOKIE['fishing_session'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if (!$sessionId) {
            json_error('Not authenticated', 401);
        }

        // Strip "Bearer " prefix if present
        $sessionId = str_replace('Bearer ', '', $sessionId);

        $stmt = db()->prepare('
            SELECT p.* FROM web_sessions s
            JOIN players p ON p.id = s.player_id
            WHERE s.id = :sid AND s.expires_at > NOW()
        ');
        $stmt->execute([':sid' => $sessionId]);
        $player = $stmt->fetch();

        if (!$player) {
            json_error('Session expired or invalid', 401);
        }

        return $player;
    }

    /**
     * Require admin privileges (works for both HUD and web).
     */
    public static function requireAdmin(array $player): void {
        if (!$player['is_admin']) {
            json_error('Admin access required', 403);
        }
    }

    /**
     * Web login: validate username + password, create session.
     */
    public static function webLogin(string $username, string $password): array {
        $stmt = db()->prepare('SELECT * FROM players WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $player = $stmt->fetch();

        if (!$player || !password_verify($password, $player['password_hash'])) {
            json_error('Invalid username or password', 401);
        }

        // Create session
        $sessionId = bin2hex(random_bytes(64));
        $expires   = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $stmt = db()->prepare('
            INSERT INTO web_sessions (id, player_id, ip_address, expires_at)
            VALUES (:id, :pid, :ip, :exp)
        ');
        $stmt->execute([
            ':id'  => $sessionId,
            ':pid' => $player['id'],
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
            ':exp' => $expires,
        ]);

        // Update last login
        $stmt = db()->prepare('UPDATE players SET last_login = NOW() WHERE id = :id');
        $stmt->execute([':id' => $player['id']]);

        // Set cookie
        setcookie('fishing_session', $sessionId, [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);

        return [
            'session_id' => $sessionId,
            'player'     => self::publicPlayerData($player),
        ];
    }

    /**
     * Web logout: destroy session.
     */
    public static function webLogout(): void {
        $sessionId = $_COOKIE['fishing_session'] ?? null;
        if ($sessionId) {
            $stmt = db()->prepare('DELETE FROM web_sessions WHERE id = :id');
            $stmt->execute([':id' => $sessionId]);
            setcookie('fishing_session', '', ['expires' => 1, 'path' => '/']);
        }
    }

    /**
     * Get player by OpenSim UUID.
     */
    public static function getPlayerByUUID(string $uuid): ?array {
        $stmt = db()->prepare('SELECT * FROM players WHERE uuid = :uuid');
        $stmt->execute([':uuid' => $uuid]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Strip sensitive fields for public/API output.
     */
    public static function publicPlayerData(array $player): array {
        return [
            'id'             => (int)$player['id'],
            'uuid'           => $player['uuid'],
            'display_name'   => $player['display_name'],
            'username'       => $player['username'],
            'level'          => (int)$player['level'],
            'xp'             => (int)$player['xp'],
            'fishing_points' => (int)$player['fishing_points'],
            'equipped_bait_id' => $player['equipped_bait_id'] ? (int)$player['equipped_bait_id'] : null,
            'equipped_rod_id'  => $player['equipped_rod_id'] ? (int)$player['equipped_rod_id'] : null,
            'total_casts'    => (int)$player['total_casts'],
            'total_catches'  => (int)$player['total_catches'],
            'created_at'     => $player['created_at'],
            'is_admin'       => (bool)$player['is_admin'],
        ];
    }
}
