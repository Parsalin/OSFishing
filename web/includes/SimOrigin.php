<?php
/**
 * Does a request really come from the sim it says it's on?
 *
 * Unauthenticated "create something" actions (HUD pairing, admin-object
 * setup, spot / gather point / shop registration) trust the uuid they are
 * given. X-SecondLife-* headers can be faked by a hand-made request; the
 * connecting address can't. A real request comes from the sim server, so it
 * should be one of the addresses the reported sim hostname resolves to.
 *
 * LOG-ONLY for now (ENFORCE = false): some grids report a LAN address or an
 * odd hostname and would fail. Every failure goes to logic_error_log as
 * sim_origin_mismatch and alerts the admins once a day per grid. Switch
 * ENFORCE on once the log shows no real grid failing.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Grid.php';
require_once __DIR__ . '/AdminAlerts.php';
require_once __DIR__ . '/LogicErrorLog.php';

class SimOrigin
{
    const ENFORCE = false;

    /** @var array<string, string[]> per-request DNS cache */
    private static $resolved = [];

    /** @return array{ok: bool, reason: string} */
    public static function check(string $gridHost, string $ip): array {
        $host = strtolower(trim(explode(':', trim($gridHost))[0]));
        if ($host === '' || $host === 'unknown') return ['ok' => false, 'reason' => 'no sim hostname reported'];
        if ($ip === '') return ['ok' => false, 'reason' => 'no connecting address'];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (Grid::isPrivateHost($host)) {
                return ['ok' => false, 'reason' => "sim reports a private address ({$host}); came from {$ip}"];
            }
            return $host === $ip ? ['ok' => true, 'reason' => '']
                                 : ['ok' => false, 'reason' => "sim address {$host} but came from {$ip}"];
        }
        $addrs = self::resolve($host);
        if (!$addrs) return ['ok' => false, 'reason' => "sim hostname {$host} doesn't resolve; came from {$ip}"];
        return in_array($ip, $addrs, true)
            ? ['ok' => true, 'reason' => '']
            : ['ok' => false, 'reason' => "{$host} resolves to " . implode(', ', array_slice($addrs, 0, 4)) . " but came from {$ip}"];
    }

    /**
     * Run the check for an action. Returns true when the request may go ahead
     * (always, while log-only).
     */
    public static function allow(string $action, string $uuid, string $gridHost): bool {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $res = self::check($gridHost, $ip);
        if ($res['ok']) return true;

        $identity = Grid::identityOf($gridHost);
        $message  = 'Sim origin check failed on ' . ($identity !== '' ? $identity : 'an unknown grid');
        LogicErrorLog::log('sim_origin_mismatch', null, $message, [
            'action' => $action, 'uuid' => $uuid, 'grid' => $gridHost, 'ip' => $ip,
            'reason' => $res['reason'], 'enforced' => self::ENFORCE,
        ]);
        AdminAlerts::send('security',
            "[Security] {$action} for {$uuid} did not come from the sim it named: {$res['reason']}. "
            . (self::ENFORCE ? 'It was refused.' : 'Log-only for now: it was allowed. If this is a real grid, it would fail once the check is enforced.')
            . ' (Alerted once a day per grid; every case is in the Logic Log as sim_origin_mismatch.)',
            '/admin/?tab=logic', [], 'sim_origin:' . $identity, 24);
        return !self::ENFORCE;
    }

    /** @return string[] */
    private static function resolve(string $host): array {
        if (isset(self::$resolved[$host])) return self::$resolved[$host];
        $out = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) $out = $v4;
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $r) if (!empty($r['ipv6'])) $out[] = $r['ipv6'];
        }
        return self::$resolved[$host] = array_values(array_unique($out));
    }
}
