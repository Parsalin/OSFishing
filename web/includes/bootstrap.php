<?php
/**
 * Loaded by every entry point and every class file instead of config.php.
 *
 * Pins the whole app to UTC. The VPS and its MariaDB run on
 * America/New_York, so without this every NOW(), DEFAULT CURRENT_TIMESTAMP
 * and UNIX_TIMESTAMP(col) went through local time while PHP (UTC) wrote
 * expiry times with date() — the two disagreed by 4-5 hours. With the DB
 * session on +00:00, SQL time functions, column defaults and PHP all agree.
 *
 * config.php is server-only (gitignored) and defines db(), so the session
 * setting lives here rather than in db() itself.
 */

require_once __DIR__ . '/../config.php';

date_default_timezone_set('UTC');

if (!defined('OSF_UTC_SESSION')) {
    define('OSF_UTC_SESSION', true);
    db()->exec("SET time_zone = '+00:00'");
}
