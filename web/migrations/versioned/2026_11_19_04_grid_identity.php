<?php
/**
 * Grids become per-grid (base domain) instead of per sim host.
 *
 * - grid_identity on every row (Grid::identityOf), indexed: "same grid" is
 *   now an identity match.
 * - no_http_in / public_host / push_problem / push_checked_at for the push
 *   delivery checks.
 * - Hosts of one grid get one decision: approved beats denied beats pending,
 *   and empty nickname / hop gateway are filled from an approved sibling.
 * - The "Tranquillity" row (the simulator's software name, captured before
 *   detectGrid() used hostnames) is removed when nothing references it.
 * - Darkhearts Playground is seeded approved if no darkheartsos.net row exists.
 * - players.home_grid now holds an identity (the map selector's value).
 */
require_once __DIR__ . '/../../includes/Grid.php';

return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE grids
        ADD COLUMN IF NOT EXISTS grid_identity   VARCHAR(128) NOT NULL DEFAULT '' AFTER grid_name,
        ADD COLUMN IF NOT EXISTS no_http_in      TINYINT(1)   NOT NULL DEFAULT 0,
        ADD COLUMN IF NOT EXISTS public_host     VARCHAR(128) DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS push_problem    VARCHAR(16)  DEFAULT NULL,
        ADD COLUMN IF NOT EXISTS push_checked_at DATETIME     DEFAULT NULL");
    $pdo->exec("ALTER TABLE grids MODIFY grid_name VARCHAR(128) NOT NULL");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_grid_identity ON grids (grid_identity)");

    // Wider grid_name columns elsewhere: a full sim host with port can pass 64.
    foreach (['fishing_spots', 'hud_tokens', 'prim_callbacks', 'hud_pairing_codes', 'hud_pair_attempts', 'shops'] as $t) {
        $col = $pdo->query("SELECT CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}' AND COLUMN_NAME = 'grid_name'")->fetch();
        if ($col && (int)$col['CHARACTER_MAXIMUM_LENGTH'] < 128) {
            $null = $col['IS_NULLABLE'] === 'YES' ? 'NULL DEFAULT NULL' : "NOT NULL DEFAULT ''";
            $pdo->exec("ALTER TABLE `{$t}` MODIFY grid_name VARCHAR(128) {$null}");
        }
    }
    $pdo->exec("ALTER TABLE players MODIFY home_grid VARCHAR(128) DEFAULT NULL");

    // Identity for every row.
    $upd = $pdo->prepare('UPDATE grids SET grid_identity = :gi WHERE id = :id');
    foreach ($pdo->query('SELECT id, grid_name FROM grids')->fetchAll() as $r) {
        $upd->execute([':gi' => Grid::identityOf($r['grid_name']), ':id' => $r['id']]);
    }

    // Drop the pre-hostname "Tranquillity" row if nothing uses it.
    $refs = 0;
    foreach (['fishing_spots', 'hud_tokens', 'prim_callbacks', 'shops'] as $t) {
        $refs += (int)$pdo->query("SELECT COUNT(*) FROM `{$t}` WHERE grid_name = 'Tranquillity'")->fetchColumn();
    }
    if ($refs === 0) {
        $pdo->exec("DELETE FROM grids WHERE grid_name = 'Tranquillity'");
        echo "    removed the unused 'Tranquillity' row\n";
    } else {
        echo "    kept 'Tranquillity' — {$refs} rows still reference it\n";
    }

    // Seed the primary grid so its hosts inherit approval.
    $has = (int)$pdo->query("SELECT COUNT(*) FROM grids WHERE grid_identity = 'darkheartsos.net'")->fetchColumn();
    if ($has === 0) {
        $pdo->exec("INSERT INTO grids (grid_name, grid_identity, nickname, hop_gateway, status, approved_at)
                    VALUES ('regions03-2.wa.darkheartsos.net:8002', 'darkheartsos.net', 'Darkhearts Playground',
                            'playground.darkheartsos.com:8002', 'approved', UTC_TIMESTAMP())");
        echo "    seeded Darkhearts Playground (darkheartsos.net) as approved\n";
    }

    // One decision per grid, and shared nickname / hop gateway.
    foreach ($pdo->query("SELECT DISTINCT grid_identity FROM grids WHERE grid_identity <> ''")->fetchAll(PDO::FETCH_COLUMN) as $gi) {
        $best = $pdo->prepare("SELECT status, nickname, hop_gateway FROM grids WHERE grid_identity = :gi
                                ORDER BY FIELD(status,'approved','denied','pending'), (nickname IS NULL), id LIMIT 1");
        $best->execute([':gi' => $gi]);
        $b = $best->fetch();
        $pdo->prepare("UPDATE grids SET status = :s,
                              approved_at = IF(:s2 = 'approved', COALESCE(approved_at, UTC_TIMESTAMP()), approved_at),
                              nickname = COALESCE(NULLIF(nickname, ''), :n),
                              hop_gateway = COALESCE(NULLIF(hop_gateway, ''), :h)
                        WHERE grid_identity = :gi")
            ->execute([':s' => $b['status'], ':s2' => $b['status'], ':n' => $b['nickname'], ':h' => $b['hop_gateway'], ':gi' => $gi]);
    }

    // Every host already in use gets a row, so identity lookups find it.
    foreach (['fishing_spots', 'hud_tokens', 'prim_callbacks', 'shops'] as $t) {
        foreach ($pdo->query("SELECT DISTINCT grid_name FROM `{$t}` WHERE grid_name IS NOT NULL AND grid_name <> ''")->fetchAll(PDO::FETCH_COLUMN) as $gn) {
            $exists = $pdo->prepare('SELECT 1 FROM grids WHERE grid_name = :gn');
            $exists->execute([':gn' => $gn]);
            if ($exists->fetchColumn()) continue;
            $gi = Grid::identityOf($gn);
            $sib = $pdo->prepare("SELECT status, nickname, hop_gateway FROM grids WHERE grid_identity = :gi
                                   ORDER BY FIELD(status,'approved','denied','pending') LIMIT 1");
            $sib->execute([':gi' => $gi]);
            $s = $sib->fetch() ?: ['status' => 'pending', 'nickname' => null, 'hop_gateway' => null];
            $pdo->prepare('INSERT INTO grids (grid_name, grid_identity, nickname, hop_gateway, status) VALUES (:gn, :gi, :n, :h, :s)')
                ->execute([':gn' => $gn, ':gi' => $gi, ':n' => $s['nickname'], ':h' => $s['hop_gateway'], ':s' => $s['status']]);
        }
    }

    // Home grid → identity.
    $set = $pdo->prepare('UPDATE players SET home_grid = :g WHERE id = :id');
    foreach ($pdo->query("SELECT id, home_grid FROM players WHERE home_grid IS NOT NULL AND home_grid <> ''")->fetchAll() as $p) {
        $set->execute([':g' => Grid::identityOf($p['home_grid']), ':id' => $p['id']]);
    }
};
