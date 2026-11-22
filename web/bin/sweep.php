<?php
/**
 * Daily housekeeping: delete rows that have expired or aged out.
 *
 *   php bin/sweep.php
 *
 * Run from the osfishing crontab (installed by deploy/deploy.sh). Each step
 * is independent; one failing doesn't stop the rest. Prints one line per
 * table so the log shows what was removed.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/PushQueue.php';
require_once __DIR__ . '/../includes/PrimCallback.php';
require_once __DIR__ . '/../includes/Buff.php';

// `--buffs` (every 10 min): only expire buffs and tell their spots, so a
// spot drops an expired buff within minutes even when nobody fishes there.
if (in_array('--buffs', $argv, true)) {
    try {
        $n = Buff::expireDue();
        if ($n) echo gmdate('Y-m-d\TH:i:s\Z') . " spot_buffs expired: {$n}\n";
        exit(0);
    } catch (Throwable $e) {
        echo gmdate('Y-m-d\TH:i:s\Z') . ' spot_buffs: FAILED ' . $e->getMessage() . "\n";
        exit(1);
    }
}

$steps = [
    'web_sessions (expired)'           => 'DELETE FROM web_sessions WHERE expires_at < UTC_TIMESTAMP()',
    'hud_pairing_codes (>1 day)'       => 'DELETE FROM hud_pairing_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    'login_tokens (>1 day past expiry)'=> 'DELETE FROM login_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    'login_attempts (>1 day)'          => 'DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    // Players see their own pair attempts on Settings; 90 days is plenty.
    'hud_pair_attempts (>90 days)'     => 'DELETE FROM hud_pair_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 90 DAY',
    'hud_exclusion_triggers (>90 days)'=> 'DELETE FROM hud_exclusion_triggers WHERE triggered_at < UTC_TIMESTAMP() - INTERVAL 90 DAY',
    // Tables that only grew (audit item 56)
    'logic_error_log (>90 days)'       => 'DELETE FROM logic_error_log WHERE logged_at < UTC_TIMESTAMP() - INTERVAL 90 DAY',
    'admin_alerts (read, >90 days)'    => 'DELETE FROM admin_alerts WHERE read_at IS NOT NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 90 DAY',
    'admin_object_log (>180 days)'     => 'DELETE FROM admin_object_log WHERE created_at < UTC_TIMESTAMP() - INTERVAL 180 DAY',
    // Only today's rows matter (the daily spot-XP cap)
    'spot_xp_daily (>30 days)'         => 'DELETE FROM spot_xp_daily WHERE log_date < UTC_DATE() - INTERVAL 30 DAY',
    'gather_cooldowns (>1 day)'        => 'DELETE FROM gather_cooldowns WHERE last_gathered < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    'daily_purchase_limits (>7 days)'  => 'DELETE FROM daily_purchase_limits WHERE purchase_date < UTC_DATE() - INTERVAL 7 DAY',
];

$failed = false;
$stamp = gmdate('Y-m-d\TH:i:s\Z');
foreach ($steps as $label => $sql) {
    try {
        echo "{$stamp} {$label}: " . db()->exec($sql) . "\n";
    } catch (Throwable $e) {
        $failed = true;
        echo "{$stamp} {$label}: FAILED " . $e->getMessage() . "\n";
    }
}

// The same sweeps prim_heartbeat runs on a 5% roll, so they also happen
// when no prim is checking in.
try {
    echo "{$stamp} push_queue (>" . PushQueue::MAX_AGE_HOURS . " h): " . PushQueue::sweep() . "\n";
    echo "{$stamp} spot_buffs (expired, spots told): " . Buff::expireDue() . "\n";
    $r = PrimCallback::cleanupStale();
    echo "{$stamp} prim_callbacks (silent " . PrimCallback::FORGET_DAYS . " d): {$r['removed_callbacks']} removed\n";
} catch (Throwable $e) {
    $failed = true;
    echo "{$stamp} push/prim sweep: FAILED " . $e->getMessage() . "\n";
}

exit($failed ? 1 : 0);
