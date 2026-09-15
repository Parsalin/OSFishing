-- ============================================================
-- PUSH QUEUE — durable delivery for server-initiated pushes
-- ============================================================
-- Before this, every push path did a live POST and DROPPED the message on
-- failure (PairingAuth::pushToPlayer additionally cleared the callback URL).
-- A transient network blip silently lost a buff notification, and grids that
-- deny llRequestURL() could never receive a push at all.
--
-- Rule now enforced everywhere:
--     queue on empty callback URL; live POST on non-empty; REQUEUE on failure.
--
-- Delivery is pull-based as a fallback: whatever is still queued flushes on
-- the target's next inbound contact (hud_status / register_callback for
-- players, prim_heartbeat for prims).
-- ============================================================

CREATE TABLE IF NOT EXISTS push_queue (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Target. Exactly one of player_id / (prim_type, ref_id) identifies it.
    target_kind   ENUM('player','prim') NOT NULL,
    player_id     INT UNSIGNED DEFAULT NULL,
    prim_type     VARCHAR(32)  DEFAULT NULL,
    ref_id        INT UNSIGNED DEFAULT NULL,

    -- 'type' field of the payload, e.g. equip_update, buff_active.
    push_type     VARCHAR(48) NOT NULL,

    -- Collapsible pushes are state snapshots (equip_update sends the player's
    -- CURRENT level/xp/points). Only the newest is worth delivering, so a new
    -- one replaces any pending row with the same target + push_type.
    -- Non-collapsible pushes are discrete events (buff_active, buff_expired,
    -- tutorial_event); every one must be delivered, in order.
    collapsible   TINYINT(1) NOT NULL DEFAULT 0,

    payload       TEXT NOT NULL,              -- JSON, sent verbatim

    attempts      INT UNSIGNED NOT NULL DEFAULT 0,
    last_error    VARCHAR(255) DEFAULT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_retry_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_player  (player_id, push_type),
    INDEX idx_prim    (prim_type, ref_id, push_type),
    INDEX idx_retry   (next_retry_at),
    INDEX idx_created (created_at),

    CONSTRAINT fk_push_queue_player
        FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-grid record of HUDs that cannot receive pushes at all, so the admin
-- panel can show it and delivery can stay pull-based for them without
-- retrying a POST that can never succeed.
ALTER TABLE hud_tokens
    ADD COLUMN IF NOT EXISTS no_http_in TINYINT(1) NOT NULL DEFAULT 0 AFTER callback_url;
