<?php
/**
 * tournaments: rebuild in the shape includes/Tournament.php (and the
 * TournamentBoard it serves) actually uses — name, metric, spot_id,
 * region_name, grid_name, start_time, end_time, status, created_by.
 *
 * The baseline table came from tournament_migration.sql (title,
 * scoring_type, target_species_id, ...), a second design that nothing ever
 * read or wrote, so every tournament action failed. tournament_entries and
 * tournament_rewards belonged to that design, are unused, and are dropped.
 * All three were empty when this was written; the migration refuses to run
 * if the old table has rows rather than drop them.
 */
return function (PDO $pdo): void {
    $hasTable = static function (string $t) use ($pdo): bool {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
        $s->execute([':t' => $t]);
        return (int)$s->fetchColumn() > 0;
    };

    if ($hasTable('tournaments')) {
        $cols = $pdo->query('SHOW COLUMNS FROM tournaments')->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('metric', $cols, true)) return;   // already rebuilt
        if ((int)$pdo->query('SELECT COUNT(*) FROM tournaments')->fetchColumn() > 0) {
            throw new RuntimeException('tournaments (old design) has rows; convert them by hand before this migration');
        }
    }

    foreach (['tournament_entries', 'tournament_rewards'] as $t) {
        if ($hasTable($t) && (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn() > 0) {
            throw new RuntimeException("{$t} has rows; it belongs to the unused design, check before dropping");
        }
    }
    $pdo->exec('DROP TABLE IF EXISTS tournament_entries');
    $pdo->exec('DROP TABLE IF EXISTS tournament_rewards');
    $pdo->exec('DROP TABLE IF EXISTS tournaments');

    $pdo->exec("
        CREATE TABLE tournaments (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            metric      ENUM('weight','catches') NOT NULL DEFAULT 'weight',
            spot_id     INT UNSIGNED DEFAULT NULL,
            region_name VARCHAR(64)  DEFAULT NULL,
            grid_name   VARCHAR(64)  DEFAULT NULL,
            start_time  DATETIME NOT NULL,
            end_time    DATETIME NOT NULL,
            status      ENUM('upcoming','active','ended') NOT NULL DEFAULT 'upcoming',
            created_by  INT UNSIGNED DEFAULT NULL,
            created_at  DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
            INDEX idx_status_end (status, end_time),
            INDEX idx_spot (spot_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
