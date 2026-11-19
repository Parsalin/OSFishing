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

$steps = [
    'web_sessions (expired)'           => 'DELETE FROM web_sessions WHERE expires_at < UTC_TIMESTAMP()',
    'hud_pairing_codes (>1 day)'       => 'DELETE FROM hud_pairing_codes WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    'login_tokens (>1 day past expiry)'=> 'DELETE FROM login_tokens WHERE expires_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    'login_attempts (>1 day)'          => 'DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY',
    // Players see their own pair attempts on Settings; 90 days is plenty.
    'hud_pair_attempts (>90 days)'     => 'DELETE FROM hud_pair_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 90 DAY',
    'hud_exclusion_triggers (>90 days)'=> 'DELETE FROM hud_exclusion_triggers WHERE triggered_at < UTC_TIMESTAMP() - INTERVAL 90 DAY',
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
    $r = PrimCallback::cleanupStale();
    echo "{$stamp} prim_callbacks (stale): {$r['removed_callbacks']} removed, {$r['deactivated_spots']} spots deactivated\n";
} catch (Throwable $e) {
    $failed = true;
    echo "{$stamp} push/prim sweep: FAILED " . $e->getMessage() . "\n";
}

exit($failed ? 1 : 0);
