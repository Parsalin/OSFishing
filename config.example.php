<?php
/**
 * Fishing Game - Configuration Template
 * Copy this file to config.php and fill in your values.
 * Never commit config.php — it is listed in .gitignore.
 */

// ── Database ──────────────────────────────────────────────
// Option A: define credentials directly here
define('DB_HOST',    'localhost');
define('DB_NAME',    'fishing');
define('DB_USER',    'your_db_user');
define('DB_PASS',    'your_db_password');
define('DB_CHARSET', 'utf8mb4');

// Option B: pull from a file outside the web root (more secure)
// require_once '/var/www/configs/fishing/db.php';

// ── Security ──────────────────────────────────────────────
// Shared secret burned into every HUD — used for HMAC auth.
// Generate with: php -r "echo bin2hex(random_bytes(32));"
define('HUD_SECRET', 'replace_with_a_random_64_char_hex_string');

// Web session lifetime in seconds (default: 8 hours)
define('SESSION_LIFETIME', 28800);

// Setup token lifetime in seconds (default: 24 hours)
define('SETUP_TOKEN_LIFETIME', 86400);

// ── Game Tuning ───────────────────────────────────────────

// Cast
define('MIN_CAST_POWER',    0.1);   // Minimum power multiplier
define('MAX_CAST_DISTANCE', 30.0);  // Absolute max meters (rod modifies this)

// Bite timing
define('BITE_WAIT_MIN',      5);    // Seconds minimum before bite
define('BITE_WAIT_MAX',      45);   // Seconds maximum before bite
define('BITE_WINDOW',        2.5);  // Seconds player has to yank
define('FAKE_NIBBLE_CHANCE', 0.3);  // Chance of fake nibbles during wait

// Fight
define('FIGHT_TICK_RATE',              0.5);  // Seconds per fight tick
define('BASE_TENSION_DECAY',           2.0);  // Tension lost per tick when matching direction
define('TENSION_WRONG_DIR',            8.0);  // Tension gained per tick pulling wrong way
define('TENSION_REEL_AGAINST_RUN',    12.0);  // Tension gained reeling against a running fish
define('BASE_REEL_RATE',               2.0);  // Distance reduced per reel tick
define('FISH_RUN_RATE',                3.0);  // Distance gained per tick when fish runs and not reeling
define('STAMINA_DECAY_PER_TICK',       0.5);  // Fish stamina lost per tick
define('STAMINA_DIRECTION_CHANGE_COST', 3.0); // Extra stamina lost on direction change

// XP and points
define('WEIGHT_BONUS_MULTIPLIER', 0.5); // Extra XP/points per lb over min weight
define('XP_PER_CAST',             1);   // Participation XP just for casting

// Gathering
define('DEFAULT_GATHER_COOLDOWN', 30);  // Fallback seconds between gathers

// Bait
define('MAX_BAIT_STACK', 999);          // Max of any single bait type

// ── Helpers ───────────────────────────────────────────────

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['success' => false, 'error' => $message], $status);
}

function json_success(array $data = []): void {
    json_response(array_merge(['success' => true], $data));
}

function require_param(string $key, string $method = 'POST'): string {
    $source = $method === 'POST' ? $_POST : $_GET;
    if (!isset($source[$key]) || trim($source[$key]) === '') {
        json_error("Missing required parameter: {$key}");
    }
    return trim($source[$key]);
}

function optional_param(string $key, $default = null, string $method = 'POST') {
    $source = $method === 'POST' ? $_POST : $_GET;
    return isset($source[$key]) && trim($source[$key]) !== '' ? trim($source[$key]) : $default;
}
