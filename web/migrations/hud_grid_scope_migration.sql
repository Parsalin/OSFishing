-- Add grid scoping to HUD tokens so users can have one HUD per grid
ALTER TABLE hud_tokens ADD COLUMN grid_name VARCHAR(64) DEFAULT NULL AFTER device_name;
ALTER TABLE hud_tokens ADD INDEX idx_player_grid (player_id, grid_name, is_active);

-- Also track grid in pairing codes so the right one gets associated with the token
ALTER TABLE hud_pairing_codes ADD COLUMN grid_name VARCHAR(64) DEFAULT NULL AFTER player_uuid;
