<?php
/**
 * admin_alerts: one shared inbox for all admins (new grids, no-HTTP-in,
 * admin-object refusals, security checks). Shown on the admin panel's
 * Alerts tab with an unread badge.
 *
 * logic_error_log: silent self-repairs and fail-closed checks — data that
 * should never exist being corrected, or an allowlist refusing something
 * unexpectedly. Never normal gameplay events.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_alerts (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            created_at  DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            category    VARCHAR(48)  NOT NULL,
            message     TEXT         NOT NULL,
            link        VARCHAR(255) DEFAULT NULL,
            data_json   TEXT         DEFAULT NULL,
            dedupe_key  VARCHAR(191) DEFAULT NULL,
            read_at     DATETIME     DEFAULT NULL,
            read_by     INT UNSIGNED DEFAULT NULL,
            INDEX idx_unread (read_at, created_at),
            INDEX idx_dedupe (dedupe_key, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logic_error_log (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            logged_at    DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            category     VARCHAR(64)  NOT NULL,
            player_id    INT UNSIGNED DEFAULT NULL,
            message      VARCHAR(500) NOT NULL,
            context_json TEXT         DEFAULT NULL,
            INDEX idx_cat_time (category, logged_at),
            INDEX idx_time (logged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
