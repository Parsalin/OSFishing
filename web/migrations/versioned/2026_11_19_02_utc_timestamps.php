<?php
/**
 * Convert every stored DATETIME from the server's local time to UTC.
 *
 * Until now the DB session ran on the server's zone (America/New_York on the
 * flamesfall VPS), so every NOW() and DEFAULT CURRENT_TIMESTAMP wrote local
 * wall-clock time. bootstrap.php now pins the session to UTC, so the old rows
 * have to move to match. Every DATETIME column in the database is shifted,
 * per row, by that zone's offset at that moment (DST-aware).
 *
 * Not shifted — written by PHP's date(), which already ran in UTC:
 *   web_sessions.expires_at, hud_pairing_codes.expires_at,
 *   players.token_expires, player_quests.expires_at, shop_listings.last_restock
 * DATE columns (daily-limit / daily-XP keys) are left alone too.
 *
 * Safety: the zone is read from the DB server and cross-checked against the
 * live offset; on any mismatch it stops rather than guess. If the server is
 * already on UTC it changes nothing.
 */
return function (PDO $pdo): void {
    $skip = [
        'web_sessions.expires_at', 'hud_pairing_codes.expires_at',
        'players.token_expires', 'player_quests.expires_at',
        'shop_listings.last_restock', 'schema_migrations.applied_at',
    ];

    // ── Which zone did the old rows use? Ask the server, outside our UTC session.
    $global = (string)$pdo->query('SELECT @@global.time_zone')->fetchColumn();
    $system = (string)$pdo->query('SELECT @@system_time_zone')->fetchColumn();
    $pdo->exec('SET time_zone = @@global.time_zone');
    $liveOffset = (int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
    $pdo->exec("SET time_zone = '+00:00'");

    echo "    server time zone: global={$global} system={$system} offset={$liveOffset}s\n";

    if ($liveOffset === 0 && in_array(strtoupper($system), ['UTC', 'GMT', 'Z', ''], true)) {
        echo "    server already on UTC — nothing to convert\n";
        return;
    }

    $abbrev = [
        'EST' => 'America/New_York',    'EDT' => 'America/New_York',
        'CST' => 'America/Chicago',     'CDT' => 'America/Chicago',
        'MST' => 'America/Denver',      'MDT' => 'America/Denver',
        'PST' => 'America/Los_Angeles', 'PDT' => 'America/Los_Angeles',
    ];
    $zoneName = null;
    if ($global !== 'SYSTEM') {
        $zoneName = $global;                          // named zone or "+hh:mm"
    } elseif (isset($abbrev[strtoupper($system)])) {
        $zoneName = $abbrev[strtoupper($system)];
    } else {
        try { new DateTimeZone($system); $zoneName = $system; } catch (Throwable $e) {}
    }
    if ($zoneName === null) {
        throw new RuntimeException("Can't tell which zone '{$system}' is; convert by hand.");
    }
    $tz = new DateTimeZone($zoneName);
    $phpOffset = $tz->getOffset(new DateTime('now', new DateTimeZone('UTC')));
    if ($phpOffset !== $liveOffset) {
        throw new RuntimeException("Zone {$zoneName} gives offset {$phpOffset}s but the DB reports {$liveOffset}s; refusing to guess.");
    }

    // ── Local-time ranges and their offsets, 2015-2031, as one CASE expression.
    // A local wall time in [start+off, end+off) was written while the offset
    // was `off`; UTC = local - off. The repeated hour at a fall-back matches the
    // first (summer) range, the best that can be done for an ambiguous time.
    $from  = (new DateTime('2015-01-01', new DateTimeZone('UTC')))->getTimestamp();
    $until = (new DateTime('2031-01-01', new DateTimeZone('UTC')))->getTimestamp();
    $trans = $tz->getTransitions($from, $until) ?: [['ts' => $from, 'offset' => $phpOffset]];
    $ranges = [];
    for ($i = 0; $i < count($trans); $i++) {
        $start = $trans[$i]['ts'];
        $end   = $trans[$i + 1]['ts'] ?? $until;
        $off   = (int)$trans[$i]['offset'];
        $ranges[] = [gmdate('Y-m-d H:i:s', $start + $off), gmdate('Y-m-d H:i:s', $end + $off), $off];
    }
    $defaultOff = $phpOffset;
    $shiftExpr = static function (string $col) use ($ranges, $defaultOff): string {
        $sql = 'CASE';
        foreach ($ranges as [$s, $e, $off]) {
            $sql .= " WHEN {$col} >= '{$s}' AND {$col} < '{$e}' THEN {$col} - INTERVAL {$off} SECOND";
        }
        return $sql . " ELSE {$col} - INTERVAL {$defaultOff} SECOND END";
    };

    // ── Every DATETIME column in this database.
    $cols = $pdo->query("
        SELECT c.TABLE_NAME, c.COLUMN_NAME
          FROM information_schema.COLUMNS c
          JOIN information_schema.TABLES t
            ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
         WHERE c.TABLE_SCHEMA = DATABASE()
           AND c.DATA_TYPE = 'datetime'
           AND t.TABLE_TYPE = 'BASE TABLE'
         ORDER BY c.TABLE_NAME, c.COLUMN_NAME
    ")->fetchAll(PDO::FETCH_NUM);

    $byTable = [];
    foreach ($cols as [$table, $col]) {
        if (in_array("{$table}.{$col}", $skip, true)) continue;
        $byTable[$table][] = $col;
    }

    $pdo->beginTransaction();
    try {
        foreach ($byTable as $table => $columns) {
            $sets = [];
            foreach ($columns as $col) {
                $q = "`{$col}`";
                // NULLs and zero/placeholder dates stay exactly as they are.
                $sets[] = "{$q} = IF({$q} IS NULL OR {$q} < '1971-01-01', {$q}, " . $shiftExpr($q) . ')';
            }
            $n = $pdo->exec("UPDATE `{$table}` SET " . implode(', ', $sets));
            echo "    {$table}: " . implode(', ', $columns) . " ({$n} rows)\n";
        }
        $pdo->prepare('INSERT INTO schema_migrations (id) VALUES (:id)')
            ->execute([':id' => '2026_11_19_02_utc_timestamps']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
};
