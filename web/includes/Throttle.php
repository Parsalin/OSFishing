<?php
/**
 * Count-and-refuse throttling on the login_attempts table.
 *
 * Each row is one counted event under a key (`username` column) from an
 * address. Web logins use the bare username (Auth::webLogin); everything
 * else prefixes its key ("pair:", "preq:", "claim:") so the namespaces never
 * collide with a real username (usernames can't contain ':').
 * bin/sweep.php deletes rows older than a day.
 */

require_once __DIR__ . '/bootstrap.php';

class Throttle {

    /**
     * Refuse with 429 if $key has $maxKey or more events, or the caller's
     * address $maxIp or more events under the same prefix, in the last
     * $windowMin minutes.
     */
    public static function check(string $key, int $maxKey, int $maxIp, int $windowMin = 15, string $message = 'Too many attempts. Wait a few minutes and try again.'): void {
        $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $prefix = strstr($key, ':', true);
        $like   = ($prefix === false ? $key : $prefix . ':') . '%';
        $s = db()->prepare('
            SELECT SUM(username = :k) AS by_key, SUM(ip_address = :ip AND username LIKE :like) AS by_ip
            FROM login_attempts
            WHERE attempted_at > UTC_TIMESTAMP() - INTERVAL ' . (int)$windowMin . ' MINUTE
              AND (username = :k2 OR ip_address = :ip2)
        ');
        $s->execute([':k' => $key, ':ip' => $ip, ':like' => $like, ':k2' => $key, ':ip2' => $ip]);
        $r = $s->fetch();
        if ((int)$r['by_key'] >= $maxKey || (int)$r['by_ip'] >= $maxIp) {
            json_error($message, 429);
        }
    }

    /** Count one event for $key from the caller's address. */
    public static function hit(string $key): void {
        db()->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (:k, :ip)')
            ->execute([':k' => substr($key, 0, 64), ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
    }
}
