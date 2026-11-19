<?php
/**
 * One collation for every table: utf8mb4_unicode_ci (the database default).
 *
 * Tables from the old server's dump were utf8mb4_uca1400_ai_ci, others
 * utf8mb4_unicode_ci, and this MariaDB creates new tables as uca1400 even
 * though @@collation_database says unicode_ci. Comparing text columns across
 * the two is an error ("Illegal mix of collations"), which broke the admin
 * Grids tab and every same-grid query (grids.grid_name vs fishing_spots /
 * hud_tokens / shops .grid_name). No foreign key is on a text column, so the
 * conversion can't trip a constraint. Each ALTER is atomic per table.
 *
 * Every new CREATE TABLE must state COLLATE=utf8mb4_unicode_ci explicitly.
 */
return function (PDO $pdo): void {
    $tables = $pdo->query("
        SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
           AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'
         ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $t) {
        $pdo->exec("ALTER TABLE `{$t}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "    {$t}\n";
    }
    echo '    converted ' . count($tables) . " tables\n";
};
