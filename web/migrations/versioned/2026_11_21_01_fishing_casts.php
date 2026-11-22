<?php
/**
 * fishing_casts: the server's record of every cast (audit items 21-25).
 *
 * A cast is opened by `cast`, and every later fishing call (roll_minute,
 * confirm_catch, line_break, reel_in) must match the player's open cast.
 * The cast fixes the spot, bait, line and power at cast time, so none of
 * them can be swapped afterwards. Catch tokens carry the cast id; closing
 * the cast is what makes a token single-use.
 *
 * Only the last 100 casts per player are kept (Fishing::pruneCasts).
 */
return function (PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fishing_casts (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            player_id         INT NOT NULL,
            spot_id           INT NOT NULL,
            bait_id           INT DEFAULT NULL,
            line_id           INT DEFAULT NULL,
            cast_power        DECIMAL(4,3) NOT NULL DEFAULT 0,
            cast_distance     DECIMAL(5,1) NOT NULL DEFAULT 0,
            status            ENUM('open','caught','broken','reeled','replaced')
                              NOT NULL DEFAULT 'open',
            last_minute       TINYINT UNSIGNED NOT NULL DEFAULT 1,
            minute_issued_at  DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            first_bite_at     DATETIME DEFAULT NULL,
            bait_consumed     TINYINT(1) NOT NULL DEFAULT 0,
            break_xp_given    TINYINT(1) NOT NULL DEFAULT 0,
            started_at        DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            ended_at          DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_player_status (player_id, status),
            KEY idx_player_id (player_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
