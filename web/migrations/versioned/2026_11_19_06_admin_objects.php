<?php
/**
 * Admin-approved in-world objects (first type: the Account Recovery terminal)
 * and one-time login links.
 *
 * admin_objects: an object owned by an admin asks to pair; an admin approves
 * it on the website; it collects a token once. Only sha256(token) is stored.
 * Every later request must match the token, the object key, the owner (still
 * an admin), the address it was approved from, and its grid.
 *
 * login_tokens: one-time website login links (recovery). sha256 only.
 * web_sessions.recovery_until: a session opened from a recovery link may set
 * a new password without the old one, once, until this time.
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_objects (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            object_type     VARCHAR(24)  NOT NULL,
            object_key      CHAR(36)     NOT NULL,
            object_name     VARCHAR(128) DEFAULT NULL,
            label           VARCHAR(128) DEFAULT NULL,
            owner_uuid      CHAR(36)     NOT NULL,
            status          ENUM('pending','active','revoked','rejected') NOT NULL DEFAULT 'pending',
            pair_code       CHAR(32)     DEFAULT NULL,
            pair_expires_at DATETIME     DEFAULT NULL,
            token_hash      CHAR(64)     DEFAULT NULL,
            token_issued    TINYINT(1)   NOT NULL DEFAULT 0,
            source_ip       VARCHAR(45)  NOT NULL,
            pending_ip      VARCHAR(45)  DEFAULT NULL,
            grid_host       VARCHAR(128) DEFAULT NULL,
            grid_identity   VARCHAR(128) DEFAULT NULL,
            region          VARCHAR(128) DEFAULT NULL,
            position        VARCHAR(64)  DEFAULT NULL,
            alerts_json     TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
            approved_at     DATETIME     DEFAULT NULL,
            approved_by     INT UNSIGNED DEFAULT NULL,
            last_seen_at    DATETIME     DEFAULT NULL,
            revoked_at      DATETIME     DEFAULT NULL,
            revoke_reason   VARCHAR(255) DEFAULT NULL,
            UNIQUE KEY uk_pair_code (pair_code),
            UNIQUE KEY uk_token (token_hash),
            KEY idx_object (object_key),
            KEY idx_type_status (object_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_object_log (
            id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            object_id  INT UNSIGNED NOT NULL,
            created_at DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
            action     VARCHAR(32)  NOT NULL,
            result     VARCHAR(16)  NOT NULL,
            message    VARCHAR(500) NOT NULL,
            ip         VARCHAR(45)  DEFAULT NULL,
            KEY idx_object (object_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_tokens (
            token_hash  CHAR(64)     NOT NULL PRIMARY KEY,
            player_id   INT UNSIGNED NOT NULL,
            avatar_uuid CHAR(36)     DEFAULT NULL,
            purpose     VARCHAR(16)  NOT NULL,
            created_at  DATETIME     NOT NULL DEFAULT UTC_TIMESTAMP(),
            expires_at  DATETIME     NOT NULL,
            used_at     DATETIME     DEFAULT NULL,
            KEY idx_player (player_id, purpose, created_at),
            CONSTRAINT fk_login_tokens_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("ALTER TABLE web_sessions ADD COLUMN IF NOT EXISTS recovery_until DATETIME DEFAULT NULL");
};
