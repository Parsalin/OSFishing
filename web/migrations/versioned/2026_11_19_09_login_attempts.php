<?php
/**
 * login_attempts: one row per failed web login, for throttling password
 * guessing (Auth::webLogin). Successful logins clear that username's rows.
 * Rows older than a day are pruned by the daily sweep.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username     VARCHAR(64) NOT NULL,
            ip_address   VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            INDEX idx_user_time (username, attempted_at),
            INDEX idx_ip_time (ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
