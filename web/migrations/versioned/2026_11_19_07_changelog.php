<?php
/**
 * Changelog publish state and each player's last visit. The entries
 * themselves live in includes/Changelog.php.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changelog_published (
            entry_id     VARCHAR(64)  NOT NULL PRIMARY KEY,
            published_at DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
            published_by INT UNSIGNED DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS changelog_seen (
            player_id INT UNSIGNED NOT NULL PRIMARY KEY,
            seen_at   DATETIME     NOT NULL,
            CONSTRAINT fk_changelog_seen_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
