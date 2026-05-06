-- ============================================================
-- FISHING GAME - LINE SYSTEM + ROD TEARDOWN MIGRATION
-- ============================================================
-- Changes:
--   1. Create line_types table with 7 tiers (Twine through Titanium Leader)
--   2. Create player_lines inventory table
--   3. Add equipped_line_id to players
--   4. Add line_tolerance to fish_species
--   5. Grant Twine to all existing players, auto-equip
--   6. Leave rod_types and equipped_rod_id intact (used for gallery only)
-- ============================================================

-- ── line_types table ──
CREATE TABLE IF NOT EXISTS line_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64) NOT NULL UNIQUE,
    weight_lb       FLOAT NOT NULL,       -- tension cap during fight
    visibility      FLOAT NOT NULL,       -- how easily fish see it
    min_level       INT UNSIGNED NOT NULL DEFAULT 1,
    cost_points     INT UNSIGNED NOT NULL DEFAULT 0,
    description     TEXT,
    sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO line_types (name, weight_lb, visibility, min_level, cost_points, description, sort_order) VALUES
    ('Twine',                 1.5,  2.0,  0,    0, 'Rough twine. Starter line everyone gets. Weaker but also not that hard to see.', 0),
    ('Thin Monofilament',     2.0,  1.5,  1,   50, 'Clear thin line. Almost invisible to small fish. Snaps on anything bigger than a sunfish.', 1),
    ('Standard Monofilament', 6.0,  3.0,  3,  150, 'Reliable all-purpose pond and river line.', 2),
    ('Braided Nylon',        12.0,  6.5,  5,  400, 'Tough line for medium river and small lake work. Pond fish can see it.', 3),
    ('Heavy Braid',          25.0, 14.0, 10, 1000, 'Big lake line. Visible enough that small fish avoid it entirely.', 4),
    ('Deep Sea Cable',       50.0, 30.0, 15, 2500, 'Thick ocean line for big saltwater fish.', 5),
    ('Titanium Leader',     100.0, 70.0, 20, 6000, 'Premium leader for sharks and marlins. Only monsters will bite this.', 6);

-- ── player_lines table ──
CREATE TABLE IF NOT EXISTS player_lines (
    player_id   INT UNSIGNED NOT NULL,
    line_id     INT UNSIGNED NOT NULL,
    acquired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (player_id, line_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (line_id) REFERENCES line_types(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── equipped_line_id on players ──
ALTER TABLE players
    ADD COLUMN equipped_line_id INT UNSIGNED DEFAULT NULL AFTER equipped_rod_id,
    ADD CONSTRAINT fk_players_equipped_line
        FOREIGN KEY (equipped_line_id) REFERENCES line_types(id) ON DELETE SET NULL;

-- ── line_tolerance on fish_species ──
ALTER TABLE fish_species
    ADD COLUMN line_tolerance FLOAT NOT NULL DEFAULT 5.0 AFTER max_weight;

-- ── Set tolerance values per species ──
-- Pond fish
UPDATE fish_species SET line_tolerance = 2.5 WHERE name IN ('Sunfish', 'Bluegill');
UPDATE fish_species SET line_tolerance = 4.0 WHERE name IN ('Bullhead Catfish', 'Koi', 'Snapping Turtle');
UPDATE fish_species SET line_tolerance = 6.0 WHERE name IN ('Largemouth Bass', 'Golden Koi');

-- River fish
UPDATE fish_species SET line_tolerance = 4.0  WHERE name = 'Creek Chub';
UPDATE fish_species SET line_tolerance = 6.0  WHERE name IN ('Rainbow Trout', 'Brown Trout');
UPDATE fish_species SET line_tolerance = 10.0 WHERE name IN ('Smallmouth Bass', 'Walleye');
UPDATE fish_species SET line_tolerance = 18.0 WHERE name IN ('Salmon', 'River Sturgeon');
UPDATE fish_species SET line_tolerance = 8.0  WHERE name = 'Ghost Catfish';

-- Lake fish
UPDATE fish_species SET line_tolerance = 15.0 WHERE name IN ('Perch', 'Crappie');
UPDATE fish_species SET line_tolerance = 18.0 WHERE name IN ('Channel Catfish', 'Lake Largemouth Bass', 'Carp');
UPDATE fish_species SET line_tolerance = 25.0 WHERE name IN ('Northern Pike', 'Lake Trout');
UPDATE fish_species SET line_tolerance = 30.0 WHERE name IN ('Muskie', 'Albino Muskie');

-- Ocean fish
UPDATE fish_species SET line_tolerance = 20.0 WHERE name IN ('Mackerel', 'Sea Bass');
UPDATE fish_species SET line_tolerance = 25.0 WHERE name IN ('Flounder', 'Red Snapper');
UPDATE fish_species SET line_tolerance = 45.0 WHERE name IN ('Yellowfin Tuna', 'Barracuda');
UPDATE fish_species SET line_tolerance = 70.0 WHERE name IN ('Swordfish', 'Hammerhead Shark');
UPDATE fish_species SET line_tolerance = 100.0 WHERE name IN ('Blue Marlin', 'Spectral Jellyfish');

-- Multi-water (Eel)
UPDATE fish_species SET line_tolerance = 12.0 WHERE name = 'Eel';

-- ── Grant Twine to all existing players and auto-equip ──
INSERT IGNORE INTO player_lines (player_id, line_id)
    SELECT p.id, lt.id
    FROM players p
    CROSS JOIN line_types lt
    WHERE lt.name = 'Twine';

UPDATE players p
    SET p.equipped_line_id = (SELECT id FROM line_types WHERE name = 'Twine')
    WHERE p.equipped_line_id IS NULL;

-- ── Rod teardown note ──
-- Not dropping equipped_rod_id or rod_types - kept for cosmetic gallery display.
-- Server no longer reads equipped_rod_id for fight params.
