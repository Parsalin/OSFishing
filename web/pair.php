<?php
/**
 * /fishing/pair - Auto-pair landing page
 *
 * Handles links from the HUD: /fishing/pair?uuid=X&code=Y
 *
 * Flow:
 *  - If not logged in: redirect to login, preserve params via session
 *  - If logged in: verify uuid matches the logged-in user, claim the code
 *  - On success: redirect to dashboard with a success flash
 */

session_start();
require_once __DIR__ . '/api/bootstrap.php';
require_once __DIR__ . '/api/Auth.php';
require_once __DIR__ . '/api/PairingAuth.php';

$uuid = $_GET['uuid'] ?? '';
$code = $_GET['code'] ?? '';

if (!$uuid || !$code) {
    http_response_code(400);
    echo '<h1>Invalid pairing link</h1><p>This link is missing required parameters.</p>';
    echo '<p><a href="/fishing/">Go to portal</a></p>';
    exit;
}

// Validate UUID format
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
    http_response_code(400);
    echo '<h1>Invalid pairing link</h1><p>UUID is not in a valid format.</p>';
    exit;
}

// Check if logged in
if (!isset($_SESSION['fishing_player_id'])) {
    // Not logged in — preserve the params and redirect to login
    $_SESSION['pair_after_login'] = ['uuid' => $uuid, 'code' => $code];
    header('Location: /fishing/?pair_pending=1');
    exit;
}

$playerId = (int)$_SESSION['fishing_player_id'];

// Verify the logged-in user matches the UUID
try {
    $stmt = db()->prepare('SELECT uuid FROM players WHERE id = :id');
    $stmt->execute([':id' => $playerId]);
    $accountUuid = $stmt->fetchColumn();

    if (!$accountUuid || strcasecmp($accountUuid, $uuid) !== 0) {
        // Logged in as a different account
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html><head><title>Pair HUD - Wrong Account</title>
        <style>body{font-family:system-ui;background:#1a1a1a;color:#eee;max-width:600px;margin:50px auto;padding:20px;}
        a{color:#5af;}</style></head><body>
        <h1>Wrong account</h1>
        <p>This pairing link is for a different avatar than the account you're logged in as.</p>
        <p>Please <a href="/fishing/?logout=1">log out</a> and log in with the correct account, or use the manual pairing code from your HUD.</p>
        </body></html>
        <?php
        exit;
    }

    // Match — claim the code
    $result = PairingAuth::claimCode($playerId, $code, 'HUD');

    // Success — redirect to portal
    $_SESSION['pair_success_message'] = $result['message'] ?? 'HUD paired successfully!';
    header('Location: /fishing/?paired=1');
    exit;

} catch (\Throwable $e) {
    error_log('Auto-pair error: ' . $e->getMessage());
    ?>
    <!DOCTYPE html>
    <html><head><title>Pairing failed</title>
    <style>body{font-family:system-ui;background:#1a1a1a;color:#eee;max-width:600px;margin:50px auto;padding:20px;}
    a{color:#5af;}</style></head><body>
    <h1>Pairing failed</h1>
    <p><?= htmlspecialchars($e->getMessage()) ?></p>
    <p>Try entering the pairing code manually from <a href="/fishing/">the portal</a> Settings page.</p>
    </body></html>
    <?php
}
