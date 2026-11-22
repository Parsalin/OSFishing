<?php
/**
 * Clear equipped_bait_id where it names a bait type that doesn't exist
 * (audit item 58: player 9 had bait 22 equipped, from the old server's
 * data). Casting with it failed with "No bait remaining".
 */
return function (PDO $pdo): void {
    $pdo->exec('
        UPDATE players p
        LEFT JOIN bait_types bt ON bt.id = p.equipped_bait_id
        SET p.equipped_bait_id = NULL
        WHERE p.equipped_bait_id IS NOT NULL AND bt.id IS NULL
    ');
};
