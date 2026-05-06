-- Per-player home grid for the map view
ALTER TABLE players ADD COLUMN home_grid VARCHAR(64) DEFAULT NULL AFTER display_name;
