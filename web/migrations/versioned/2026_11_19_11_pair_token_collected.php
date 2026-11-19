<?php
/**
 * hud_pairing_codes.token_collected_at: when the HUD's pair_status poll
 * collected the token. The token is handed out once (BP 5); before this,
 * every poll after the claim returned it again, to anyone who knew the
 * avatar UUID and the 6-digit code.
 */
return function (PDO $pdo): void {
    $cols = $pdo->query('SHOW COLUMNS FROM hud_pairing_codes')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('token_collected_at', $cols, true)) {
        $pdo->exec('ALTER TABLE hud_pairing_codes ADD COLUMN token_collected_at DATETIME DEFAULT NULL AFTER claimed_at');
    }
    // Codes claimed before this column existed have been collected already.
    $pdo->exec('UPDATE hud_pairing_codes SET token_collected_at = claimed_at WHERE claimed = 1 AND token_collected_at IS NULL');
};
