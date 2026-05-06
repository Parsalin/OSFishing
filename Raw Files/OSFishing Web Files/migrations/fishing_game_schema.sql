-- ============================================================
-- FISHING GAME DATABASE SCHEMA
-- OpenSim-NGC Tranquillity 0.9.3.9333
-- ============================================================

-- ============================================================
-- PLAYER SYSTEM
-- ============================================================

CREATE TABLE players (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            VARCHAR(36) NOT NULL UNIQUE,           -- OpenSim avatar UUID
    username        VARCHAR(64) NOT NULL UNIQUE,            -- Web portal login
    password_hash   VARCHAR(255) NOT NULL,                  -- bcrypt hashed password
    display_name    VARCHAR(128) NOT NULL,                  -- In-world display name
    level           INT UNSIGNED NOT NULL DEFAULT 1,
    xp              INT UNSIGNED NOT NULL DEFAULT 0,
    fishing_points  INT UNSIGNED NOT NULL DEFAULT 0,
    equipped_bait_id INT UNSIGNED DEFAULT NULL,
    equipped_rod_id  INT UNSIGNED DEFAULT NULL,
    setup_token     VARCHAR(128) DEFAULT NULL,              -- One-time web registration token
    token_expires   DATETIME DEFAULT NULL,
    is_admin        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login      DATETIME DEFAULT NULL,
    last_fished     DATETIME DEFAULT NULL,
    total_casts     INT UNSIGNED NOT NULL DEFAULT 0,
    total_catches   INT UNSIGNED NOT NULL DEFAULT 0,
    total_points_earned INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX idx_uuid (uuid),
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- LEVEL THRESHOLDS
-- ============================================================

CREATE TABLE levels (
    level           INT UNSIGNED PRIMARY KEY,
    xp_required     INT UNSIGNED NOT NULL,                  -- Total XP needed to reach this level
    title           VARCHAR(64) DEFAULT NULL,                -- Optional title earned at this level
    unlock_notes    TEXT DEFAULT NULL                        -- Description of what unlocks
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO levels (level, xp_required, title, unlock_notes) VALUES
(1,  0,       'Beginner',        'Pond access, basic rod'),
(2,  100,     'Novice',          NULL),
(3,  300,     'Apprentice',      'River access'),
(4,  600,     NULL,              NULL),
(5,  1000,    'Journeyman',      'Rod upgrade tier 2, lake access'),
(6,  1500,    NULL,              NULL),
(7,  2200,    'Skilled Angler',  NULL),
(8,  3000,    NULL,              'Rod upgrade tier 3'),
(9,  4000,    NULL,              NULL),
(10, 5500,    'Expert',          'Ocean access'),
(11, 7000,    NULL,              NULL),
(12, 9000,    NULL,              'Rod upgrade tier 4'),
(13, 11500,   NULL,              NULL),
(14, 14500,   'Master Angler',   NULL),
(15, 18000,   NULL,              'Rod upgrade tier 5 (max)'),
(16, 22000,   NULL,              NULL),
(17, 27000,   NULL,              NULL),
(18, 33000,   'Grand Master',    NULL),
(19, 40000,   NULL,              NULL),
(20, 50000,   'Legendary Angler', 'All content unlocked');

-- ============================================================
-- WATER BODIES
-- ============================================================

CREATE TABLE water_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(32) NOT NULL UNIQUE,            -- pond, river, lake, ocean
    description     TEXT DEFAULT NULL,
    min_level       INT UNSIGNED NOT NULL DEFAULT 1          -- Level required to fish here
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO water_types (id, name, description, min_level) VALUES
(1, 'pond',  'Calm shallow waters. Ideal for beginners.', 1),
(2, 'river', 'Flowing currents with stronger fish.', 3),
(3, 'lake',  'Deep open water with wide variety.', 5),
(4, 'ocean', 'Vast saltwater. The biggest catches await.', 10);

-- ============================================================
-- FISHING SPOTS
-- ============================================================

CREATE TABLE fishing_spots (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(128) NOT NULL,
    water_type_id   INT UNSIGNED NOT NULL,
    region_name     VARCHAR(64) DEFAULT NULL,               -- OpenSim region
    pos_x           FLOAT DEFAULT NULL,                     -- World coordinates
    pos_y           FLOAT DEFAULT NULL,
    pos_z           FLOAT DEFAULT NULL,
    min_level       INT UNSIGNED NOT NULL DEFAULT 1,
    loot_modifier   FLOAT NOT NULL DEFAULT 1.0,             -- Multiplier for rarity rolls
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    description     TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (water_type_id) REFERENCES water_types(id),
    INDEX idx_water_type (water_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- RARITY TIERS
-- ============================================================

CREATE TABLE rarity_tiers (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(32) NOT NULL UNIQUE,            -- common, uncommon, rare, epic, legendary
    color_hex       VARCHAR(7) NOT NULL DEFAULT '#FFFFFF',  -- Display color
    base_xp         INT UNSIGNED NOT NULL DEFAULT 0,        -- Base XP award for this tier
    point_multiplier FLOAT NOT NULL DEFAULT 1.0,            -- Multiplier for point calculations
    catch_weight    FLOAT NOT NULL DEFAULT 1.0              -- Loot table weight (higher = more common)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO rarity_tiers (id, name, color_hex, base_xp, point_multiplier, catch_weight) VALUES
(1, 'common',    '#9D9D9D', 5,   1.0,  50.0),
(2, 'uncommon',  '#1EFF00', 15,  1.5,  25.0),
(3, 'rare',      '#0070FF', 40,  2.5,  10.0),
(4, 'epic',      '#A335EE', 100, 5.0,   3.0),
(5, 'legendary', '#FF8000', 300, 15.0,  0.5);

-- ============================================================
-- FISH SPECIES
-- ============================================================

CREATE TABLE fish_species (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64) NOT NULL,
    rarity_id       INT UNSIGNED NOT NULL,
    min_weight      FLOAT NOT NULL,                         -- Pounds
    max_weight      FLOAT NOT NULL,
    base_points     INT UNSIGNED NOT NULL,                  -- Before rarity/weight multipliers
    description     TEXT DEFAULT NULL,

    -- Fight behavior
    fight_stamina       FLOAT NOT NULL DEFAULT 50.0,        -- How long the fish fights
    fight_speed         FLOAT NOT NULL DEFAULT 1.0,         -- Direction change frequency
    fight_power         FLOAT NOT NULL DEFAULT 1.0,         -- How much tension each pull adds
    fight_line_pull     FLOAT NOT NULL DEFAULT 1.0,         -- How fast it pulls line out
    fight_unpredictability FLOAT NOT NULL DEFAULT 0.5,      -- 0=predictable, 1=chaotic

    -- Time and condition modifiers
    time_modifier       VARCHAR(32) DEFAULT NULL,            -- dawn, day, dusk, night, any
    season_modifier     VARCHAR(32) DEFAULT NULL,            -- spring, summer, fall, winter, any
    special_condition   VARCHAR(128) DEFAULT NULL,            -- e.g. 'bioluminescent_tide'

    -- Visual
    trophy_prim_uuid    VARCHAR(36) DEFAULT NULL,            -- UUID of the display prim
    texture_uuid        VARCHAR(36) DEFAULT NULL,            -- Fish texture

    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (rarity_id) REFERENCES rarity_tiers(id),
    INDEX idx_rarity (rarity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FISH-TO-WATER MAPPING (many-to-many for multi-water fish)
-- ============================================================

CREATE TABLE fish_water_types (
    fish_id         INT UNSIGNED NOT NULL,
    water_type_id   INT UNSIGNED NOT NULL,
    catch_weight_override FLOAT DEFAULT NULL,               -- Override rarity weight for this water
    PRIMARY KEY (fish_id, water_type_id),
    FOREIGN KEY (fish_id) REFERENCES fish_species(id),
    FOREIGN KEY (water_type_id) REFERENCES water_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BAIT TYPES
-- ============================================================

CREATE TABLE bait_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64) NOT NULL,
    description     TEXT DEFAULT NULL,
    gather_location VARCHAR(256) DEFAULT NULL,               -- Where to find it in-world
    gather_method   VARCHAR(128) DEFAULT NULL,               -- How the player gathers it
    shop_price      INT UNSIGNED DEFAULT NULL,               -- Fishing points cost, NULL = not sold
    shop_quantity   INT UNSIGNED DEFAULT 10,                 -- How many per purchase
    gather_min      INT UNSIGNED NOT NULL DEFAULT 1,         -- Min yield per gather
    gather_max      INT UNSIGNED NOT NULL DEFAULT 3,         -- Max yield per gather
    gather_cooldown INT UNSIGNED NOT NULL DEFAULT 30,        -- Seconds between gathers
    catch_rate_mod  FLOAT NOT NULL DEFAULT 1.0,              -- Global catch rate modifier
    rarity_mod      FLOAT NOT NULL DEFAULT 1.0,              -- Shifts rarity rolls (higher = rarer catches)
    icon_texture    VARCHAR(36) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO bait_types (id, name, description, gather_location, gather_method, shop_price, shop_quantity, gather_min, gather_max, gather_cooldown, catch_rate_mod, rarity_mod) VALUES
(1,  'Earthworms',    'Versatile all-purpose freshwater bait.',           'Compost pile / muddy garden',     'Click dirt mound, digging animation',        5,  10, 1, 3, 30,  1.0, 1.0),
(2,  'Grasshoppers',  'Great for surface feeders. Tricky to catch.',      'Tall grass meadow',               'Click grass clumps, snatching animation',    NULL, NULL, 1, 2, 25, 1.1, 1.0),
(3,  'Crickets',      'Best at evening and night. Good freshwater bait.', 'Under rocks/logs in wooded area', 'Click rock/log to flip and collect',          NULL, NULL, 1, 3, 30,  1.1, 1.0),
(4,  'Minnows',       'Live bait for larger predatory fish.',             'Shallow stream / tide pool',      'Click net object to scoop',                  NULL, NULL, 1, 2, 45,  1.2, 1.1),
(5,  'Cut Bait',      'Chunks of fish. Strong for catfish and ocean.',    'Bait preparation table',          'Bring a caught fish, click table to cut',    NULL, NULL, 2, 5, 0,   1.2, 1.1),
(6,  'Leeches',       'Premium freshwater bait. Great for deep water.',   'Swampy bog area',                 'Click submerged rocks/logs in swamp',        NULL, NULL, 1, 2, 60,  1.3, 1.3),
(7,  'Corn Kernels',  'Cheap bulk bait. Carp love it.',                   'Farm stand / corn field',         'Click corn stalks or bushel basket',         3,  10, 2, 5, 20,  0.9, 0.8),
(8,  'Shrimp',        'Essential saltwater bait. Good for ocean fish.',   'Coastal tidal flat / dock',       'Click shrimp trap or dip net at waters edge', NULL, NULL, 1, 3, 45, 1.2, 1.1),
(9,  'Bread Dough',   'Basic starter bait. Easy to get.',                 'Bakery / kitchen area',           'Click table with bread to ball up dough',    3,  10, 3, 6, 15,  0.8, 0.7),
(10, 'Glow Grubs',    'Rare luminous grubs. Key to legendary catches.',   'Cave / underground area',         'Click glowing spots on walls or under rocks', NULL, NULL, 1, 1, 300, 1.5, 2.5);

-- ============================================================
-- FISH-TO-BAIT AFFINITY (many-to-many)
-- ============================================================

CREATE TABLE fish_bait_affinity (
    fish_id         INT UNSIGNED NOT NULL,
    bait_id         INT UNSIGNED NOT NULL,
    affinity        FLOAT NOT NULL DEFAULT 1.0,             -- Multiplier: >1 = fish prefers this bait
    is_required     TINYINT(1) NOT NULL DEFAULT 0,          -- 1 = ONLY bites on this bait
    PRIMARY KEY (fish_id, bait_id),
    FOREIGN KEY (fish_id) REFERENCES fish_species(id),
    FOREIGN KEY (bait_id) REFERENCES bait_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ROD TYPES
-- ============================================================

CREATE TABLE rod_types (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(64) NOT NULL,
    description     TEXT DEFAULT NULL,
    tier            INT UNSIGNED NOT NULL DEFAULT 1,         -- 1-5
    min_level       INT UNSIGNED NOT NULL DEFAULT 1,         -- Level required to use
    cast_range      FLOAT NOT NULL DEFAULT 10.0,             -- Max cast distance in meters
    reel_speed      FLOAT NOT NULL DEFAULT 1.0,              -- Reel rate multiplier
    tension_buffer  FLOAT NOT NULL DEFAULT 1.0,              -- Higher = more forgiving tension
    line_strength   FLOAT NOT NULL DEFAULT 100.0,            -- Tension max before snap
    cost            INT UNSIGNED NOT NULL DEFAULT 0,          -- Fishing points to buy
    prim_uuid       VARCHAR(36) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO rod_types (id, name, tier, min_level, cast_range, reel_speed, tension_buffer, line_strength, cost) VALUES
(1, 'Bamboo Rod',       1, 1,  10.0, 1.0, 1.0, 100.0, 0),
(2, 'Fiberglass Rod',   2, 5,  15.0, 1.2, 1.1, 120.0, 200),
(3, 'Graphite Rod',     3, 8,  20.0, 1.4, 1.2, 150.0, 500),
(4, 'Carbon Fiber Rod', 4, 12, 25.0, 1.6, 1.4, 200.0, 1200),
(5, 'Mastercraft Rod',  5, 15, 30.0, 2.0, 1.7, 275.0, 3000);

-- ============================================================
-- PLAYER BAIT INVENTORY
-- ============================================================

CREATE TABLE player_bait (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    bait_id         INT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uk_player_bait (player_id, bait_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (bait_id) REFERENCES bait_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PLAYER ROD INVENTORY
-- ============================================================

CREATE TABLE player_rods (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    rod_id          INT UNSIGNED NOT NULL,
    acquired_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_player_rod (player_id, rod_id),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (rod_id) REFERENCES rod_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PLAYER FISH INVENTORY (the bank of caught fish)
-- ============================================================

CREATE TABLE player_fish (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    fish_species_id INT UNSIGNED NOT NULL,
    weight          FLOAT NOT NULL,
    rarity_id       INT UNSIGNED NOT NULL,
    spot_id         INT UNSIGNED DEFAULT NULL,
    bait_used_id    INT UNSIGNED DEFAULT NULL,
    rod_used_id     INT UNSIGNED DEFAULT NULL,
    caught_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status          ENUM('held','sold','quest','trophy') NOT NULL DEFAULT 'held',
    sold_at         DATETIME DEFAULT NULL,
    points_received INT UNSIGNED DEFAULT NULL,              -- Points gained when sold
    quest_id        INT UNSIGNED DEFAULT NULL,              -- Which quest consumed it
    physical_copy   TINYINT(1) NOT NULL DEFAULT 0,          -- Has a physical prim been rezzed

    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (fish_species_id) REFERENCES fish_species(id),
    FOREIGN KEY (rarity_id) REFERENCES rarity_tiers(id),
    FOREIGN KEY (spot_id) REFERENCES fishing_spots(id),
    FOREIGN KEY (bait_used_id) REFERENCES bait_types(id),
    FOREIGN KEY (rod_used_id) REFERENCES rod_types(id),
    INDEX idx_player_status (player_id, status),
    INDEX idx_player_species (player_id, fish_species_id),
    INDEX idx_caught_at (caught_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- CATCH LOG (permanent history, never deleted even when fish are sold)
-- ============================================================

CREATE TABLE catch_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    fish_species_id INT UNSIGNED NOT NULL,
    weight          FLOAT NOT NULL,
    rarity_id       INT UNSIGNED NOT NULL,
    spot_id         INT UNSIGNED DEFAULT NULL,
    bait_used_id    INT UNSIGNED DEFAULT NULL,
    rod_used_id     INT UNSIGNED DEFAULT NULL,
    cast_distance   FLOAT DEFAULT NULL,
    fight_duration  INT UNSIGNED DEFAULT NULL,              -- Seconds
    xp_awarded      INT UNSIGNED NOT NULL DEFAULT 0,
    caught_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (fish_species_id) REFERENCES fish_species(id),
    FOREIGN KEY (rarity_id) REFERENCES rarity_tiers(id),
    INDEX idx_player (player_id),
    INDEX idx_species (fish_species_id),
    INDEX idx_caught_at (caught_at),
    INDEX idx_leaderboard (fish_species_id, weight DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- QUEST DEFINITIONS
-- ============================================================

CREATE TABLE quests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(128) NOT NULL,
    description     TEXT NOT NULL,
    quest_type      ENUM('collection','size','multi_species','rare_catch','timed','seasonal') NOT NULL,
    chain_id        INT UNSIGNED DEFAULT NULL,               -- Links quests into chains
    chain_order     INT UNSIGNED DEFAULT NULL,               -- Order within a chain
    min_level       INT UNSIGNED NOT NULL DEFAULT 1,
    is_repeatable   TINYINT(1) NOT NULL DEFAULT 0,
    repeat_cooldown INT UNSIGNED DEFAULT NULL,               -- Hours before repeatable
    time_limit      INT UNSIGNED DEFAULT NULL,               -- Minutes to complete (timed quests)
    season          VARCHAR(32) DEFAULT NULL,                 -- NULL = always available
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chain (chain_id, chain_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- QUEST REQUIREMENTS (what fish/conditions are needed)
-- ============================================================

CREATE TABLE quest_requirements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quest_id        INT UNSIGNED NOT NULL,
    fish_species_id INT UNSIGNED DEFAULT NULL,               -- NULL = any species
    water_type_id   INT UNSIGNED DEFAULT NULL,               -- NULL = any water
    bait_required_id INT UNSIGNED DEFAULT NULL,              -- NULL = any bait
    spot_id         INT UNSIGNED DEFAULT NULL,               -- NULL = any spot
    min_weight      FLOAT DEFAULT NULL,                      -- NULL = any size
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,
    time_of_day     VARCHAR(32) DEFAULT NULL,                -- dawn, night, etc. NULL = any
    description     VARCHAR(256) DEFAULT NULL,               -- Human-readable requirement
    FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE,
    FOREIGN KEY (fish_species_id) REFERENCES fish_species(id),
    FOREIGN KEY (water_type_id) REFERENCES water_types(id),
    FOREIGN KEY (bait_required_id) REFERENCES bait_types(id),
    FOREIGN KEY (spot_id) REFERENCES fishing_spots(id),
    INDEX idx_quest (quest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- QUEST REWARDS
-- ============================================================

CREATE TABLE quest_rewards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quest_id        INT UNSIGNED NOT NULL,
    reward_type     ENUM('points','xp','bait','rod','title','trophy','special') NOT NULL,
    reward_value    INT UNSIGNED NOT NULL DEFAULT 0,         -- Points/XP amount, or quantity
    reward_ref_id   INT UNSIGNED DEFAULT NULL,               -- Bait ID, Rod ID, etc.
    reward_title    VARCHAR(64) DEFAULT NULL,                -- Title string if reward_type = title
    description     VARCHAR(256) DEFAULT NULL,
    FOREIGN KEY (quest_id) REFERENCES quests(id) ON DELETE CASCADE,
    INDEX idx_quest (quest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PLAYER QUEST TRACKING
-- ============================================================

CREATE TABLE player_quests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    quest_id        INT UNSIGNED NOT NULL,
    status          ENUM('active','completed','failed','abandoned') NOT NULL DEFAULT 'active',
    accepted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    expires_at      DATETIME DEFAULT NULL,                   -- For timed quests
    INDEX idx_player_status (player_id, status),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (quest_id) REFERENCES quests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PLAYER QUEST PROGRESS (per requirement)
-- ============================================================

CREATE TABLE player_quest_progress (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_quest_id INT UNSIGNED NOT NULL,
    requirement_id  INT UNSIGNED NOT NULL,
    current_count   INT UNSIGNED NOT NULL DEFAULT 0,
    is_complete     TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_progress (player_quest_id, requirement_id),
    FOREIGN KEY (player_quest_id) REFERENCES player_quests(id) ON DELETE CASCADE,
    FOREIGN KEY (requirement_id) REFERENCES quest_requirements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- PLAYER TITLES (earned from levels and quests)
-- ============================================================

CREATE TABLE player_titles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    title           VARCHAR(64) NOT NULL,
    source          ENUM('level','quest','tournament','special') NOT NULL,
    earned_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active       TINYINT(1) NOT NULL DEFAULT 0,          -- Currently displayed title
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BAIT GATHERING COOLDOWNS (server-side anti-spam)
-- ============================================================

CREATE TABLE gather_cooldowns (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    bait_id         INT UNSIGNED NOT NULL,
    spot_key        VARCHAR(64) DEFAULT NULL,                -- Specific gather spot identifier
    last_gathered   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cooldown (player_id, bait_id, spot_key),
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    FOREIGN KEY (bait_id) REFERENCES bait_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SHOP TRANSACTIONS
-- ============================================================

CREATE TABLE shop_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    item_type       ENUM('bait','rod','cosmetic','special') NOT NULL,
    item_ref_id     INT UNSIGNED NOT NULL,                   -- Bait ID or Rod ID
    quantity        INT UNSIGNED NOT NULL DEFAULT 1,
    points_spent    INT UNSIGNED NOT NULL,
    purchased_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_player (player_id),
    INDEX idx_date (purchased_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FISH SELL TRANSACTIONS
-- ============================================================

CREATE TABLE sell_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    fish_count      INT UNSIGNED NOT NULL,
    total_points    INT UNSIGNED NOT NULL,
    sold_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    details         JSON DEFAULT NULL,                       -- Breakdown of what was sold
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TOURNAMENTS / EVENTS
-- ============================================================

CREATE TABLE tournaments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(128) NOT NULL,
    description     TEXT DEFAULT NULL,
    scoring_type    ENUM('biggest_single','total_weight','most_catches','rarest_catch') NOT NULL,
    target_species_id INT UNSIGNED DEFAULT NULL,             -- NULL = any fish counts
    target_water_id INT UNSIGNED DEFAULT NULL,               -- NULL = any water
    starts_at       DATETIME NOT NULL,
    ends_at         DATETIME NOT NULL,
    min_level       INT UNSIGNED NOT NULL DEFAULT 1,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (target_species_id) REFERENCES fish_species(id),
    FOREIGN KEY (target_water_id) REFERENCES water_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tournament_rewards (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id   INT UNSIGNED NOT NULL,
    placement       INT UNSIGNED NOT NULL,                   -- 1st, 2nd, 3rd etc.
    reward_type     ENUM('points','xp','bait','rod','title','trophy','special') NOT NULL,
    reward_value    INT UNSIGNED NOT NULL DEFAULT 0,
    reward_ref_id   INT UNSIGNED DEFAULT NULL,
    reward_title    VARCHAR(64) DEFAULT NULL,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    INDEX idx_tournament (tournament_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tournament_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tournament_id   INT UNSIGNED NOT NULL,
    player_id       INT UNSIGNED NOT NULL,
    score           FLOAT NOT NULL DEFAULT 0,                -- Depends on scoring_type
    best_catch_id   INT UNSIGNED DEFAULT NULL,               -- Reference to catch_log
    total_catches   INT UNSIGNED NOT NULL DEFAULT 0,
    last_updated    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_entry (tournament_id, player_id),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_score (tournament_id, score DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SPECIAL EVENTS (like bioluminescent tide)
-- ============================================================

CREATE TABLE world_events (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key       VARCHAR(64) NOT NULL UNIQUE,             -- e.g. 'bioluminescent_tide'
    name            VARCHAR(128) NOT NULL,
    description     TEXT DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 0,
    starts_at       DATETIME DEFAULT NULL,
    ends_at         DATETIME DEFAULT NULL,
    auto_trigger    TINYINT(1) NOT NULL DEFAULT 0,           -- PHP auto-activates on schedule
    trigger_chance  FLOAT DEFAULT NULL,                      -- Random trigger probability per check
    last_triggered  DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO world_events (event_key, name, description, auto_trigger, trigger_chance) VALUES
('bioluminescent_tide', 'Bioluminescent Tide', 'The ocean glows with an eerie light. Strange creatures stir beneath the waves.', 1, 0.05);

-- ============================================================
-- HUD AUTH TOKENS (secure HUD-to-server communication)
-- ============================================================

CREATE TABLE hud_tokens (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(255) NOT NULL,                   -- HMAC token for HUD auth
    issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used       DATETIME DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_player (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ADMIN ANNOUNCEMENTS (pushed to HUDs)
-- ============================================================

CREATE TABLE announcements (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message         TEXT NOT NULL,
    priority        ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    starts_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- WEB SESSION MANAGEMENT
-- ============================================================

CREATE TABLE web_sessions (
    id              VARCHAR(128) PRIMARY KEY,
    player_id       INT UNSIGNED NOT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NOT NULL,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_player (player_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA: FISH SPECIES
-- ============================================================

-- POND FISH
INSERT INTO fish_species (id, name, rarity_id, min_weight, max_weight, base_points, fight_stamina, fight_speed, fight_power, fight_line_pull, fight_unpredictability, time_modifier) VALUES
(1,  'Sunfish',          1, 0.5,  2,    3,   15, 0.5, 0.3, 0.3, 0.2, 'any'),
(2,  'Bluegill',         1, 0.5,  3,    5,   20, 0.8, 0.4, 0.3, 0.3, 'any'),
(3,  'Bullhead Catfish', 1, 1,    5,    6,   25, 0.6, 0.6, 0.5, 0.4, 'night'),
(4,  'Koi',              2, 3,    10,   18,  40, 0.7, 0.7, 0.6, 0.5, 'any'),
(5,  'Largemouth Bass',  2, 2,    8,    15,  45, 1.0, 1.0, 0.8, 0.6, 'any'),
(6,  'Snapping Turtle',  3, 10,   30,   45,  70, 0.3, 1.5, 2.0, 0.3, 'any'),
(7,  'Golden Koi',       5, 8,    15,   400, 90, 1.2, 1.0, 0.8, 0.9, 'any');

-- RIVER FISH
INSERT INTO fish_species (id, name, rarity_id, min_weight, max_weight, base_points, fight_stamina, fight_speed, fight_power, fight_line_pull, fight_unpredictability, time_modifier) VALUES
(8,  'Creek Chub',       1, 0.25, 2,    4,   15, 1.0, 0.3, 0.2, 0.2, 'any'),
(9,  'Rainbow Trout',    1, 1,    6,    8,   30, 1.2, 0.5, 0.5, 0.5, 'any'),
(10, 'Brown Trout',      2, 2,    10,   20,  45, 1.0, 0.7, 0.7, 0.6, 'dawn_dusk'),
(11, 'Smallmouth Bass',  2, 2,    7,    18,  50, 1.2, 1.0, 0.8, 0.7, 'any'),
(12, 'Walleye',          3, 3,    15,   50,  65, 0.8, 1.2, 1.0, 0.5, 'dawn_dusk'),
(13, 'Salmon',           3, 5,    25,   60,  80, 1.5, 1.0, 1.2, 0.6, 'any'),
(14, 'River Sturgeon',   4, 20,   80,   150, 95, 0.5, 1.8, 1.5, 0.4, 'any'),
(15, 'Ghost Catfish',    5, 15,   40,   450, 90, 1.5, 1.3, 1.5, 0.9, 'night');

-- LAKE FISH
INSERT INTO fish_species (id, name, rarity_id, min_weight, max_weight, base_points, fight_stamina, fight_speed, fight_power, fight_line_pull, fight_unpredictability, time_modifier) VALUES
(16, 'Perch',            1, 0.5,  3,    5,   18, 0.6, 0.4, 0.3, 0.3, 'any'),
(17, 'Crappie',          1, 0.5,  4,    6,   22, 0.9, 0.5, 0.3, 0.4, 'any'),
(18, 'Channel Catfish',  2, 3,    20,   22,  50, 0.6, 1.0, 0.9, 0.5, 'night'),
(19, 'Lake LM Bass',    2, 3,    12,   20,  50, 1.1, 1.0, 0.9, 0.7, 'any'),
(20, 'Carp',             2, 5,    30,   14,  60, 0.5, 0.8, 0.8, 0.4, 'any'),
(21, 'Northern Pike',    3, 5,    25,   55,  70, 1.3, 1.5, 1.2, 0.7, 'any'),
(22, 'Lake Trout',       3, 5,    30,   55,  75, 0.7, 1.3, 1.0, 0.5, 'any'),
(23, 'Muskie',           4, 10,   50,   180, 95, 1.5, 1.5, 1.3, 0.8, 'any'),
(24, 'Albino Muskie',    5, 20,   60,   500, 99, 1.8, 1.8, 1.5, 0.95,'any');

-- OCEAN FISH
INSERT INTO fish_species (id, name, rarity_id, min_weight, max_weight, base_points, fight_stamina, fight_speed, fight_power, fight_line_pull, fight_unpredictability, time_modifier) VALUES
(25, 'Mackerel',         1, 1,    5,    7,   25, 1.2, 0.5, 0.4, 0.5, 'any'),
(26, 'Sea Bass',         1, 2,    10,   10,  30, 0.8, 0.7, 0.6, 0.5, 'any'),
(27, 'Flounder',         2, 2,    12,   20,  40, 0.4, 1.0, 0.8, 0.3, 'any'),
(28, 'Red Snapper',      2, 3,    15,   25,  50, 0.8, 1.0, 0.9, 0.5, 'any'),
(29, 'Yellowfin Tuna',   3, 15,   80,   75,  85, 1.5, 1.0, 1.5, 0.6, 'any'),
(30, 'Barracuda',        3, 5,    30,   65,  60, 1.8, 1.5, 1.3, 0.8, 'any'),
(31, 'Swordfish',        4, 50,   200,  200, 95, 0.7, 1.8, 1.8, 0.6, 'any'),
(32, 'Hammerhead Shark',  4, 80,  300,  250, 90, 1.5, 2.0, 2.0, 0.7, 'any'),
(33, 'Blue Marlin',      5, 100,  500,  600, 99, 1.8, 2.0, 2.0, 0.95,'any'),
(34, 'Spectral Jellyfish',5, 2,   5,    350, 80, 2.0, 0.5, 0.3, 1.0, 'any');

-- MULTI-WATER FISH
INSERT INTO fish_species (id, name, rarity_id, min_weight, max_weight, base_points, fight_stamina, fight_speed, fight_power, fight_line_pull, fight_unpredictability, time_modifier) VALUES
(35, 'Eel',              3, 2,    8,    40,  55, 1.0, 0.8, 0.6, 0.7, 'night');

-- Set special condition for Spectral Jellyfish
UPDATE fish_species SET special_condition = 'bioluminescent_tide' WHERE id = 34;

-- Set salmon season
UPDATE fish_species SET season_modifier = 'fall' WHERE id = 13;

-- ============================================================
-- SEED DATA: FISH-WATER MAPPINGS
-- ============================================================

-- Pond
INSERT INTO fish_water_types (fish_id, water_type_id) VALUES
(1, 1), (2, 1), (3, 1), (4, 1), (5, 1), (6, 1), (7, 1);

-- River
INSERT INTO fish_water_types (fish_id, water_type_id) VALUES
(8, 2), (9, 2), (10, 2), (11, 2), (12, 2), (13, 2), (14, 2), (15, 2);

-- Lake
INSERT INTO fish_water_types (fish_id, water_type_id) VALUES
(16, 3), (17, 3), (18, 3), (19, 3), (20, 3), (21, 3), (22, 3), (23, 3), (24, 3);

-- Ocean
INSERT INTO fish_water_types (fish_id, water_type_id) VALUES
(25, 4), (26, 4), (27, 4), (28, 4), (29, 4), (30, 4), (31, 4), (32, 4), (33, 4), (34, 4);

-- Multi-water: Eel in river + lake
INSERT INTO fish_water_types (fish_id, water_type_id) VALUES
(35, 2), (35, 3);

-- Multi-water: Largemouth Bass also in lake (already in pond as id 5, lake version is id 19)

-- ============================================================
-- SEED DATA: FISH-BAIT AFFINITIES
-- ============================================================

-- Pond fish
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity, is_required) VALUES
-- Sunfish: bread dough + worms
(1, 9, 1.5, 0), (1, 1, 1.3, 0),
-- Bluegill: crickets + worms
(2, 3, 1.5, 0), (2, 1, 1.3, 0),
-- Bullhead Catfish: worms + cut bait
(3, 1, 1.5, 0), (3, 5, 1.3, 0),
-- Koi: corn + bread dough
(4, 7, 1.5, 0), (4, 9, 1.3, 0),
-- Largemouth Bass (pond): minnows + grasshoppers
(5, 4, 1.5, 0), (5, 2, 1.3, 0),
-- Snapping Turtle: cut bait + minnows
(6, 5, 1.5, 0), (6, 4, 1.3, 0),
-- Golden Koi: glow grubs only
(7, 10, 2.0, 1);

-- River fish
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity, is_required) VALUES
-- Creek Chub: worms + crickets + bread
(8, 1, 1.3, 0), (8, 3, 1.2, 0), (8, 9, 1.0, 0),
-- Rainbow Trout: grasshoppers + crickets
(9, 2, 1.5, 0), (9, 3, 1.3, 0),
-- Brown Trout: minnows + leeches
(10, 4, 1.5, 0), (10, 6, 1.3, 0),
-- Smallmouth Bass: grasshoppers + minnows + crickets
(11, 2, 1.5, 0), (11, 4, 1.3, 0), (11, 3, 1.1, 0),
-- Walleye: leeches + minnows
(12, 6, 1.5, 0), (12, 4, 1.3, 0),
-- Salmon: minnows + shrimp
(13, 4, 1.5, 0), (13, 8, 1.3, 0),
-- River Sturgeon: cut bait + leeches
(14, 5, 1.5, 0), (14, 6, 1.3, 0),
-- Ghost Catfish: glow grubs or leeches
(15, 10, 2.0, 0), (15, 6, 1.5, 0);

-- Lake fish
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity, is_required) VALUES
-- Perch: worms + crickets + minnows
(16, 1, 1.3, 0), (16, 3, 1.2, 0), (16, 4, 1.1, 0),
-- Crappie: minnows + crickets
(17, 4, 1.5, 0), (17, 3, 1.3, 0),
-- Channel Catfish: cut bait + worms + shrimp
(18, 5, 1.5, 0), (18, 1, 1.2, 0), (18, 8, 1.1, 0),
-- Lake LM Bass: minnows + grasshoppers
(19, 4, 1.5, 0), (19, 2, 1.3, 0),
-- Carp: corn + bread dough
(20, 7, 1.5, 0), (20, 9, 1.3, 0),
-- Northern Pike: minnows + cut bait
(21, 4, 1.5, 0), (21, 5, 1.3, 0),
-- Lake Trout: minnows + leeches
(22, 4, 1.5, 0), (22, 6, 1.3, 0),
-- Muskie: minnows + cut bait
(23, 4, 1.5, 0), (23, 5, 1.3, 0),
-- Albino Muskie: glow grubs only
(24, 10, 2.0, 1);

-- Ocean fish
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity, is_required) VALUES
-- Mackerel: shrimp + cut bait + minnows
(25, 8, 1.5, 0), (25, 5, 1.2, 0), (25, 4, 1.0, 0),
-- Sea Bass: shrimp + cut bait + worms
(26, 8, 1.5, 0), (26, 5, 1.2, 0), (26, 1, 1.0, 0),
-- Flounder: shrimp + cut bait
(27, 8, 1.5, 0), (27, 5, 1.3, 0),
-- Red Snapper: shrimp + cut bait
(28, 8, 1.5, 0), (28, 5, 1.3, 0),
-- Yellowfin Tuna: shrimp + minnows
(29, 8, 1.5, 0), (29, 4, 1.3, 0),
-- Barracuda: minnows + cut bait
(30, 4, 1.5, 0), (30, 5, 1.3, 0),
-- Swordfish: cut bait + shrimp
(31, 5, 1.5, 0), (31, 8, 1.3, 0),
-- Hammerhead Shark: cut bait only
(32, 5, 1.8, 0),
-- Blue Marlin: glow grubs or cut bait (premium)
(33, 10, 2.0, 0), (33, 5, 1.5, 0),
-- Spectral Jellyfish: glow grubs only
(34, 10, 2.0, 1);

-- Multi-water: Eel
INSERT INTO fish_bait_affinity (fish_id, bait_id, affinity, is_required) VALUES
(35, 1, 1.5, 0), (35, 5, 1.3, 0);
