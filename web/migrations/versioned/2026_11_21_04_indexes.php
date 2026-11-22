<?php
/**
 * Indexes (audit item 55).
 *  - catch_log (spot_id, caught_at): spot pages, the spot leaderboard and
 *    the archived-spots list count catches per spot, and there was no index
 *    on spot_id at all.
 *  - Drop plain indexes that duplicate a UNIQUE one on the same column
 *    (hud_tokens.token, players.uuid, players.username): every write paid
 *    for two copies.
 */
return function (PDO $pdo): void {
    $has = function (string $table, string $key) use ($pdo): bool {
        $s = $pdo->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = :k");
        $s->execute([':k' => $key]);
        return (bool)$s->fetch();
    };

    if (!$has('catch_log', 'idx_spot_time')) {
        $pdo->exec('ALTER TABLE catch_log ADD INDEX idx_spot_time (spot_id, caught_at)');
    }
    foreach ([['hud_tokens', 'idx_token', 'token'], ['players', 'idx_uuid', 'uuid'], ['players', 'idx_username', 'username']] as [$t, $dup, $unique]) {
        if ($has($t, $dup) && $has($t, $unique)) {
            $pdo->exec("ALTER TABLE `{$t}` DROP INDEX `{$dup}`");
        }
    }
};
