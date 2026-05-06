-- ============================================================
-- FISHING GAME - TOURNAMENTS TABLE
-- ============================================================

CREATE TABLE IF NOT EXISTS tournaments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(128) NOT NULL,
    metric       ENUM('weight','catches') NOT NULL DEFAULT 'weight',
    spot_id      INT UNSIGNED DEFAULT NULL,          -- NULL = whole sim/region
    region_name  VARCHAR(64) DEFAULT NULL,            -- For sim-scoped tournaments
    grid_name    VARCHAR(64) DEFAULT NULL,
    start_time   DATETIME NOT NULL,
    end_time     DATETIME NOT NULL,
    status       ENUM('upcoming','active','ended') NOT NULL DEFAULT 'upcoming',
    created_by   INT UNSIGNED DEFAULT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (spot_id) REFERENCES fishing_spots(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_time (start_time, end_time)
) ENGINE=InnoDB;
