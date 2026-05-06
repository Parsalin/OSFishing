-- ============================================================
-- GRID REGISTRY - Multi-grid support
-- ============================================================

CREATE TABLE IF NOT EXISTS grids (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grid_name   VARCHAR(128) NOT NULL UNIQUE,
    nickname    VARCHAR(128) DEFAULT NULL,
    status      ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at DATETIME DEFAULT NULL,
    notes       TEXT DEFAULT NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Auto-approve the home grid
INSERT INTO grids (grid_name, nickname, status, approved_at)
VALUES ('Tranquillity', 'Tranquillity', 'approved', NOW())
ON DUPLICATE KEY UPDATE status = 'approved', approved_at = NOW();
