<?php
/**
 * Cross-checks for the in-world actions that act on a `uuid` param with no
 * token (audit item 1). LOG-ONLY until ENFORCE is flipped (BP 11).
 *
 * These checks are a stopgap, not proof. Real sims stamp
 * X-SecondLife-Owner-Key / -Object-Key and a script can't change them, but a
 * hand-made request can send any headers. What they do catch today is the
 * easy attack: a plain request naming someone else's UUID. The real fix is
 * in docs/AUDIT.md item 1 (player actions through the HUD, object tokens).
 *
 * Two kinds of action:
 *  - OWNER: `uuid` is the object's owner acting on their own thing (spot
 *    settings, vendor stock, plaque). Must equal the owner header, unless
 *    the object owner is an admin (an admin's object may act for anyone).
 *  - TOUCHER: `uuid` is whoever touched someone else's object (buy, sell,
 *    buff, craft). The server can't verify the toucher at all; we only
 *    require that the request came from an object in a sim.
 *
 * Failures go to the Logic Log as `inworld_unverified`, at most once per
 * action+reason+address per hour, so the log shows which grids or objects
 * would break before ENFORCE is turned on.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/LogicErrorLog.php';

class InWorldAuth {

    /** Flip once the Logic Log shows no real object failing. */
    const ENFORCE = false;

    const OWNER   = 'owner';
    const TOUCHER = 'toucher';

    /**
     * Check a request that claims to act as $uuid. Returns normally when it
     * passes, or when it fails and ENFORCE is off; otherwise replies 401.
     */
    public static function check(string $action, string $uuid, string $kind): void {
        $uuid      = strtolower(trim($uuid));
        $ownerKey  = strtolower(trim((string)($_SERVER['HTTP_X_SECONDLIFE_OWNER_KEY'] ?? '')));
        $objectKey = strtolower(trim((string)($_SERVER['HTTP_X_SECONDLIFE_OBJECT_KEY'] ?? '')));

        $reason = null;
        if ($ownerKey === '' || $objectKey === '') {
            $reason = 'not_from_a_sim';
        } elseif ($kind === self::OWNER && $ownerKey !== $uuid && !self::isAdmin($ownerKey)) {
            // The OBJECT'S owner (the sim-stamped header) must be an admin to
            // act for someone else. Checking the claimed $uuid instead let any
            // request that named an admin's UUID through.
            $reason = 'owner_mismatch';
        }
        if ($reason === null) return;

        self::logOnce($action, $reason, [
            'uuid'       => $uuid,
            'owner_key'  => $ownerKey,
            'object_key' => $objectKey,
            'object'     => (string)($_SERVER['HTTP_X_SECONDLIFE_OBJECT_NAME'] ?? ''),
            'region'     => (string)($_SERVER['HTTP_X_SECONDLIFE_REGION'] ?? ''),
            'ip'         => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'enforced'   => self::ENFORCE,
        ]);
        if (self::ENFORCE) json_error($reason, 401);
    }

    private static function isAdmin(string $uuid): bool {
        try {
            $s = db()->prepare('SELECT p.is_admin FROM players p WHERE p.id = (SELECT pu.player_id FROM player_uuids pu WHERE pu.uuid = :u)');
            $s->execute([':u' => $uuid]);
            return (int)$s->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function logOnce(string $action, string $reason, array $ctx): void {
        try {
            $s = db()->prepare('
                SELECT 1 FROM logic_error_log
                WHERE category = "inworld_unverified"
                  AND logged_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR
                  AND message = :m
                LIMIT 1
            ');
            $msg = "{$action}: {$reason} from {$ctx['ip']}";
            $s->execute([':m' => $msg]);
            if ($s->fetchColumn()) return;
            LogicErrorLog::log('inworld_unverified', null, $msg, $ctx);
        } catch (Throwable $e) {
            error_log('InWorldAuth log failed: ' . $e->getMessage());
        }
    }
}
