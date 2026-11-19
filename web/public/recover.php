<?php
/**
 * /recover.php?token=… — one-time login link from an Account Recovery
 * terminal. Consumes the token (it works once, within 15 minutes), logs the
 * player in, and marks the session so Change Password skips the current
 * password once (web_sessions.recovery_until). Never GET this link yourself
 * to test it: that uses it up.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AdminObjects.php';

$token = (string)($_GET['token'] ?? '');
$playerId = preg_match('/^[0-9a-f]{48}$/', $token) ? AdminObjects::consumeLoginToken($token) : null;

if (!$playerId) {
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link expired — OSFishing</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#12161c;color:#e8edf3;max-width:520px;margin:60px auto;padding:0 16px}a{color:#4ea1ff}</style></head><body>'
       . '<h1>This recovery link has expired</h1><p>Recovery links work once, within 15 minutes. Touch the Account Recovery terminal again for a new one.</p>'
       . '<p><a href="/">Go to the portal</a></p></body></html>';
    exit;
}

$lifetime  = defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 28800;
$sessionId = bin2hex(random_bytes(64));
db()->prepare('
    INSERT INTO web_sessions (id, player_id, ip_address, expires_at, recovery_until)
    VALUES (:id, :pid, :ip, :exp, UTC_TIMESTAMP() + INTERVAL :ttl SECOND)
')->execute([
    ':id'  => $sessionId,
    ':pid' => $playerId,
    ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ':exp' => gmdate('Y-m-d H:i:s', time() + $lifetime),
    ':ttl' => AdminObjects::RECOVERY_LINK_TTL,
]);
db()->prepare('UPDATE players SET last_login = UTC_TIMESTAMP() WHERE id = :id')->execute([':id' => $playerId]);

setcookie('fishing_session', $sessionId, [
    'expires'  => time() + $lifetime,
    'path'     => '/',
    'httponly' => true,
    'secure'   => true,
    'samesite' => 'Lax',
]);
header('Location: /?page=settings&recovery=1');
