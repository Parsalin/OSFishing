<?php
/**
 * Bring a database built from schema.sql up to what the live server has.
 * schema.sql predates the push queue (push_queue_migration.sql was applied by
 * hand), so a fresh build was missing it.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_queue (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            target_kind   ENUM('player','prim') NOT NULL,
            player_id     INT UNSIGNED DEFAULT NULL,
            prim_type     VARCHAR(32)  DEFAULT NULL,
            ref_id        INT UNSIGNED DEFAULT NULL,
            push_type     VARCHAR(48) NOT NULL,
            collapsible   TINYINT(1) NOT NULL DEFAULT 0,
            payload       TEXT NOT NULL,
            attempts      INT UNSIGNED NOT NULL DEFAULT 0,
            last_error    VARCHAR(255) DEFAULT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_player  (player_id, push_type),
            INDEX idx_prim    (prim_type, ref_id, push_type),
            INDEX idx_retry   (next_retry_at),
            INDEX idx_created (created_at),
            CONSTRAINT fk_push_queue_player
                FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("ALTER TABLE hud_tokens ADD COLUMN IF NOT EXISTS no_http_in TINYINT(1) NOT NULL DEFAULT 0 AFTER callback_url");
};
