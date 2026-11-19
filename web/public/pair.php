<?php
/**
 * /pair?uuid=X&code=Y — the link the HUD opens for its owner.
 *
 *  - Not logged in: offer log in (the portal sends you back here after) or
 *    create an account for this avatar.
 *  - Avatar already on your account: pair at once.
 *  - Avatar on no account: ask "link this avatar to <your account>?" and
 *    pair on confirm (POST). This is how a second avatar, e.g. on another
 *    grid, joins an existing account.
 *  - Avatar on a different account: say so; nothing happens.
 *
 * Login is the web_sessions cookie (Auth::currentWebPlayer), same as the API.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/PairingAuth.php';
require_once __DIR__ . '/../includes/Throttle.php';

function pair_page(string $title, string $bodyHtml, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $t = htmlspecialchars($title);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t} — OSFishing</title>
<style>
:root{--bg:#f4f6f8;--card:#fff;--text:#1d2430;--muted:#667085;--accent:#1f7ae0;--danger:#c0392b;--border:#dde3ea}
@media (prefers-color-scheme: dark){:root{--bg:#12161c;--card:#1b222c;--text:#e8edf3;--muted:#9aa6b5;--accent:#4ea1ff;--danger:#ff6b5b;--border:#2a3441}}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--text);margin:0;padding:40px 16px}
.card{max-width:520px;margin:0 auto;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:28px}
h1{font-size:20px;margin:0 0 12px}p{line-height:1.55;color:var(--muted)}strong{color:var(--text)}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
.btn{display:inline-block;padding:10px 16px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text);font:inherit;font-weight:600;text-decoration:none;cursor:pointer}
.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff}
.who{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin:14px 0;font-size:14px}
.who div{margin:2px 0}.mono{font-family:ui-monospace,monospace;font-size:12px;color:var(--muted)}
</style></head><body><div class="card">{$bodyHtml}</div></body></html>
HTML;
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

$uuid = strtolower(trim((string)($_GET['uuid'] ?? $_POST['uuid'] ?? '')));
$code = trim((string)($_GET['code'] ?? $_POST['code'] ?? ''));

if ($uuid === '' || $code === '' || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
    pair_page('Invalid pairing link', '<h1>Invalid pairing link</h1><p>This link is missing its avatar or code. Touch your HUD for a new one.</p><div class="row"><a class="btn" href="/">Go to the portal</a></div>', 400);
}

$back = '/pair?uuid=' . rawurlencode($uuid) . '&code=' . rawurlencode($code);

try {
    $player = Auth::currentWebPlayer();

    if (!$player) {
        // Peek at the code only to say who it is for; nothing is claimed.
        $stmt = db()->prepare('SELECT avatar_name FROM hud_pairing_codes WHERE player_uuid = :u AND code = :c AND claimed = 0 AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([':u' => $uuid, ':c' => preg_replace('/^(\d{3})(\d{3})$/', '$1-$2', preg_replace('/\D/', '', $code))]);
        $name = $stmt->fetchColumn();
        $hasAccount = PairingAuth::playerIdForUuid($uuid) !== null;
        $loginUrl = '/?pair_next=' . rawurlencode($back);
        $regUrl = '/register.php?uuid=' . rawurlencode($uuid) . '&code=' . rawurlencode($code) . ($name ? '&name=' . rawurlencode($name) : '');
        pair_page('Pair your HUD',
            '<h1>Pair your HUD</h1>'
            . '<p>Log in to your OSFishing account to pair ' . ($name ? '<strong>' . $h($name) . '</strong>' : 'this avatar') . '.</p>'
            . ($hasAccount ? '' : '<p>This avatar isn\'t linked to an account yet. If you already play with another avatar, log in to that account and you can add this avatar to it. Otherwise create a new account.</p>')
            . '<div class="row"><a class="btn btn-primary" href="' . $h($loginUrl) . '">Log in</a>'
            . ($hasAccount ? '' : '<a class="btn" href="' . $h($regUrl) . '">Create a new account</a>')
            . '</div>');
    }

    $playerId = (int)$player['id'];
    // Same cap as web_pair_claim: this page can claim codes too.
    Throttle::check('claim:' . $playerId, 10, 30, 15, 'Too many wrong codes. Wait 15 minutes and try again.');
    $info = PairingAuth::describeCode($uuid, $code, $playerId);
    if (!$info) {
        Throttle::hit('claim:' . $playerId);
        pair_page('Pairing link expired', '<h1>This pairing link has expired</h1><p>Pairing codes last 5 minutes. Touch your HUD to get a new one.</p><div class="row"><a class="btn" href="/">Go to the portal</a></div>', 410);
    }

    $accountLabel = $player['display_name'] ?: $player['username'];
    $avatarLabel  = $info['avatar_name'] ?: $uuid;
    $gridLabel    = $info['grid_identity'] ?: 'an unknown grid';

    if ($info['effect'] === 'other_account') {
        pair_page('Different account',
            '<h1>This avatar belongs to a different account</h1>'
            . '<p><strong>' . $h($avatarLabel) . '</strong> is already linked to another OSFishing account, not <strong>' . $h($accountLabel) . '</strong>.</p>'
            . '<p>Log out and log in with that account to pair this HUD.</p>'
            . '<div class="row"><a class="btn" href="/">Go to the portal</a></div>', 403);
    }

    if ($info['effect'] === 'link') {
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        // The confirm form carries a value tied to this login, so a page on
        // another site can't submit it for you (the cookie is SameSite=Lax too).
        $expect = hash_hmac('sha256', 'link:' . $uuid . ':' . $code, (string)($_COOKIE['fishing_session'] ?? ''));
        if (!$isPost || !hash_equals($expect, (string)($_POST['confirm'] ?? ''))) {
            pair_page('Link this avatar?',
                '<h1>Add this avatar to your account?</h1>'
                . '<p>The HUD that opened this link is worn by an avatar that isn\'t on any OSFishing account yet.</p>'
                . '<div class="who"><div>Avatar: <strong>' . $h($avatarLabel) . '</strong></div>'
                . '<div>Grid: <strong>' . $h($gridLabel) . '</strong></div>'
                . '<div class="mono">' . $h($uuid) . '</div>'
                . '<div style="margin-top:8px">Your account: <strong>' . $h($accountLabel) . '</strong></div></div>'
                . '<p>Only do this if this avatar is yours. It will share this account\'s fish, points, gear and quests, and you can remove it later under Settings → Linked Avatars.</p>'
                . '<form method="post" action="/pair" class="row">'
                . '<input type="hidden" name="uuid" value="' . $h($uuid) . '"><input type="hidden" name="code" value="' . $h($code) . '">'
                . '<input type="hidden" name="confirm" value="' . $h($expect) . '">'
                . '<button class="btn btn-primary" type="submit">Yes, link it and pair</button>'
                . '<a class="btn" href="/">No, cancel</a></form>');
        }
        PairingAuth::claimCode($playerId, $code, 'HUD', true);
        header('Location: /?paired=1&linked=1');
        exit;
    }

    PairingAuth::claimCode($playerId, $code, 'HUD');
    header('Location: /?paired=1');
    exit;

} catch (\Throwable $e) {
    error_log('Auto-pair error: ' . $e->getMessage());
    pair_page('Pairing failed', '<h1>Pairing failed</h1><p>Something went wrong. Try entering the code from your HUD on the portal\'s Settings page.</p><div class="row"><a class="btn" href="/">Go to the portal</a></div>', 500);
}
