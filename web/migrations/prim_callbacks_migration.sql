-- ============================================================
-- PRIM CALLBACKS - Server-to-prim push system
-- ============================================================
-- Each in-world prim that wants pushed updates registers its
-- llRequestURL callback. Server pushes events instead of prim
-- polling.
-- ============================================================

CREATE TABLE IF NOT EXISTS prim_callbacks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prim_uuid       CHAR(36) NOT NULL,
    prim_type       VARCHAR(32) NOT NULL,
    ref_id          INT UNSIGNED DEFAULT NULL,
    callback_url    VARCHAR(512) NOT NULL,
    region_name     VARCHAR(64) DEFAULT NULL,
    grid_name       VARCHAR(64) DEFAULT NULL,
    last_seen       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    registered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_prim (prim_uuid),
    INDEX idx_type_ref (prim_type, ref_id)
) ENGINE=InnoDB;
