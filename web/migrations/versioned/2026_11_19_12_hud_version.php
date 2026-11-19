<?php
/**
 * hud_tokens.hud_version: the HUD script version (HUD_VERSION in
 * lsl/FishingHUD.lsl, "YYYY.MM.DD.NN") reported on each hud_status.
 * NULL = a HUD from before versioning.
 */
return function (PDO $pdo): void {
    $cols = $pdo->query('SHOW COLUMNS FROM hud_tokens')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('hud_version', $cols, true)) {
        $pdo->exec('ALTER TABLE hud_tokens ADD COLUMN hud_version VARCHAR(40) DEFAULT NULL AFTER device_name');
    }
};
