-- Hop gateway for hypergrid URLs (e.g. "playground.darkheartsos.com:8002")
ALTER TABLE grids ADD COLUMN hop_gateway VARCHAR(128) DEFAULT NULL AFTER nickname;
