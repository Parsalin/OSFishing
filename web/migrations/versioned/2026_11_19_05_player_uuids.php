<?php
/**
 * One account, several avatars.
 *
 * Avatar UUIDs are per grid: the same person on a second grid is a different
 * UUID. players.uuid (UNIQUE) let an account hold exactly one, so a second
 * avatar could never pair to an existing account. player_uuids maps every
 * avatar UUID to its account; every "who is this avatar" lookup goes through
 * it. players.uuid stays as the account's primary avatar.
 *
 * A UUID belongs to at most one account (UNIQUE uuid). A hypergrid visitor
 * keeps their UUID, so that is still one avatar, one account.
 *
 * hud_tokens.avatar_uuid binds each token to the avatar that paired it.
 * hud_pairing_codes.player_id becomes nullable: an avatar with no account
 * yet can request a code, and the logged-in player who claims it links it.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS player_uuids (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            player_id     INT UNSIGNED NOT NULL,
            uuid          CHAR(36)     NOT NULL,
            grid_identity VARCHAR(128) DEFAULT NULL,
            avatar_name   VARCHAR(128) DEFAULT NULL,
            linked_at     DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
            last_used_at  DATETIME     DEFAULT NULL,
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_player (player_id),
            CONSTRAINT fk_player_uuids_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("ALTER TABLE hud_tokens ADD COLUMN IF NOT EXISTS avatar_uuid CHAR(36)
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER player_id");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_avatar ON hud_tokens (avatar_uuid)");
    // player_id carries a foreign key; some MariaDB builds refuse to alter
    // such a column while FK checks are on. Nullability only, no type change.
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    try {
        $pdo->exec("ALTER TABLE hud_pairing_codes MODIFY player_id INT UNSIGNED NULL");
    } finally {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    }
    $pdo->exec("ALTER TABLE hud_pairing_codes ADD COLUMN IF NOT EXISTS avatar_name VARCHAR(128) DEFAULT NULL AFTER player_uuid");

    // Every existing account's avatar, with the grid it last used (if any).
    $players = $pdo->query('SELECT id, uuid, display_name FROM players')->fetchAll();
    $ins = $pdo->prepare('INSERT IGNORE INTO player_uuids (player_id, uuid, grid_identity, avatar_name, last_used_at)
                          VALUES (:pid, :u, :gi, :n, :lu)');
    $tok = $pdo->prepare('SELECT grid_name, last_used FROM hud_tokens WHERE player_id = :pid AND grid_name IS NOT NULL
                          ORDER BY last_used DESC LIMIT 1');
    foreach ($players as $p) {
        $tok->execute([':pid' => $p['id']]);
        $t = $tok->fetch();
        $gi = null;
        if ($t && $t['grid_name']) {
            require_once __DIR__ . '/../../includes/Grid.php';
            $gi = Grid::identityOf($t['grid_name']);
        }
        $ins->execute([':pid' => $p['id'], ':u' => strtolower($p['uuid']), ':gi' => $gi,
                       ':n' => $p['display_name'], ':lu' => $t['last_used'] ?? null]);
    }

    // Existing tokens belong to the account's (only) avatar.
    $pdo->exec("UPDATE hud_tokens ht JOIN players p ON p.id = ht.player_id
                   SET ht.avatar_uuid = LOWER(p.uuid)
                 WHERE ht.avatar_uuid IS NULL");
    echo '    linked ' . count($players) . " existing avatars\n";
};
