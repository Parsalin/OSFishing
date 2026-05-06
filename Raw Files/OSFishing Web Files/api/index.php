<?php
/**
 * Fishing Game - API Router
 * Single entry point: /api/index.php
 *
 * All requests come as POST with an 'action' parameter.
 * HUD requests include uuid, timestamp, signature for HMAC auth.
 * Web requests include a session cookie.
 *
 * Example HUD request:
 *   POST /api/ { action: "cast", uuid: "...", timestamp: "...", signature: "...", spot_id: 5, cast_power: 0.8 }
 *
 * Example Web request:
 *   POST /api/ { action: "web_dashboard" }  (with fishing_session cookie)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Player.php';
require_once __DIR__ . '/../includes/Fishing.php';
require_once __DIR__ . '/../includes/Bait.php';
require_once __DIR__ . '/../includes/Line.php';
require_once __DIR__ . '/../includes/FishInventory.php';
require_once __DIR__ . '/../includes/Quest.php';
require_once __DIR__ . '/../includes/Shop.php';
require_once __DIR__ . '/../includes/Leaderboard.php';
require_once __DIR__ . '/../includes/PairingAuth.php';
require_once __DIR__ . '/../includes/Admin.php';
require_once __DIR__ . '/../includes/GatherPoint.php';
require_once __DIR__ . '/../includes/FAQ.php';
require_once __DIR__ . '/../includes/Tournament.php';
require_once __DIR__ . '/../includes/Butcher.php';
require_once __DIR__ . '/../includes/Grid.php';
require_once __DIR__ . '/../includes/Buff.php';
require_once __DIR__ . '/../includes/ShopSystem.php';
require_once __DIR__ . '/../includes/Trophy.php';
require_once __DIR__ . '/../includes/PrimCallback.php';
require_once __DIR__ . '/../includes/Tutorial.php';

// ── Handle CORS for web portal ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ── Accept JSON body or form POST ──
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $json = json_decode(file_get_contents('php://input'), true);
    if ($json) $_POST = array_merge($_POST, $json);
}

// ── Route by action ──
$action = require_param('action');

// ── Embed auth fallback: if embed token params are present, bootstrap a session ──
// This allows the HUD media prim to authenticate without cookies.
// We use a generous timeout (1 hour) since the panel stays open.
$GLOBALS['_embed_player_id'] = null;
if (isset($_POST['_embed_token_id']) && isset($_POST['_embed_sig'])) {
    $eTokenId = (int)$_POST['_embed_token_id'];
    $eSig = $_POST['_embed_sig'];
    $eTs = $_POST['_embed_ts'] ?? '0';
    if (abs(time() - (int)$eTs) <= 3600) {
        $stmt = db()->prepare('
            SELECT ht.token, ht.player_id FROM hud_tokens ht
            WHERE ht.id = :id AND ht.is_active = 1
        ');
        $stmt->execute([':id' => $eTokenId]);
        $eRow = $stmt->fetch();
        if ($eRow) {
            $eExpected = hash('sha256', $eTokenId . ':' . $eTs . ':' . $eRow['token']);
            if (hash_equals($eExpected, $eSig)) {
                $GLOBALS['_embed_player_id'] = (int)$eRow['player_id'];
            }
        }
    }
}

// Override Auth::requireWeb to support embed auth from HUD media prim.
// When embed token is validated, skip session auth entirely.
class AuthEmbed {
    public static function requireWeb(): array {
        // If embed auth was validated at the top of this request, use it directly
        $pid = $GLOBALS['_embed_player_id'] ?? null;
        if ($pid) {
            $stmt = db()->prepare('SELECT * FROM players WHERE id = :id');
            $stmt->execute([':id' => $pid]);
            $player = $stmt->fetch();
            if (!$player) json_error('Player not found', 401);
            if (!empty($player['is_banned'])) json_error('Account banned', 403);
            return $player;
        }

        // No embed auth present — use normal session auth
        return Auth::requireWeb();
    }
}

try {
    switch ($action) {

        // ════════════════════════════════════════════════
        // HUD ENDPOINTS (HMAC auth via uuid/timestamp/signature)
        // ════════════════════════════════════════════════

        // ════════════════════════════════════════════════
        // PAIRING ENDPOINTS (no auth required)
        // ════════════════════════════════════════════════

        case 'check_account':
            $uuid = require_param('uuid');
            json_success(PairingAuth::checkAccount($uuid));

        case 'password_reset':
            $uuid = require_param('uuid');
            $newPassword = require_param('new_password');
            if (strlen($newPassword) < 6) json_error('Password must be at least 6 characters');
            $stmt = db()->prepare('SELECT id, display_name FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $player = $stmt->fetch();
            if (!$player) json_error('Account not found');
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare('UPDATE players SET password_hash = :h WHERE id = :id')
                ->execute([':h' => $hash, ':id' => $player['id']]);
            json_success(['message' => 'Password updated for ' . $player['display_name']]);

        case 'register_callback':
            $player = PairingAuth::requireHUD($action);
            $url = require_param('callback_url');
            // $player has token_id from requireHUD
            $tokenId = (int)($_POST['token_id'] ?? 0);
            json_success(PairingAuth::registerCallback($tokenId, $url));

        case 'web_auto_login':
            // Auto-login from HUD media panel using pairing token signature.
            // Creates a real web session with cookie so subsequent page navigation works.
            $tokenId = (int)require_param('token_id');
            $sig = require_param('sig');
            $ts = optional_param('ts', '0');
            if (abs(time() - (int)$ts) > 3600) {
                json_error('Auth link expired. Close and reopen the panel.', 403);
            }
            $stmt = db()->prepare('
                SELECT ht.token, ht.player_id, p.*
                FROM hud_tokens ht
                JOIN players p ON p.id = ht.player_id
                WHERE ht.id = :id AND ht.is_active = 1
            ');
            $stmt->execute([':id' => $tokenId]);
            $row = $stmt->fetch();
            if (!$row) json_error('Invalid token', 403);
            $expected = hash('sha256', $tokenId . ':' . $ts . ':' . $row['token']);
            if (!hash_equals($expected, $sig)) json_error('Invalid signature', 403);
            // Create a real web session (same as webLogin does)
            $sessionId = bin2hex(random_bytes(64));
            $expires = date('Y-m-d H:i:s', time() + (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400));
            $stmt = db()->prepare('
                INSERT INTO web_sessions (id, player_id, ip_address, expires_at)
                VALUES (:id, :pid, :ip, :exp)
            ');
            $stmt->execute([
                ':id'  => $sessionId,
                ':pid' => (int)$row['player_id'],
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
                ':exp' => $expires,
            ]);
            setcookie('fishing_session', $sessionId, [
                'expires'  => time() + (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 86400),
                'path'     => '/',
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
            json_success([
                'player' => Auth::publicPlayerData($row),
                'message' => 'Auto-logged in from HUD',
            ]);

        case 'spot_register':
            // Check grid approval
            $gridName = optional_param('grid_name', '');
            if ($gridName !== '') Grid::requireApproved($gridName);
            json_success(PairingAuth::registerSpot($_POST));

        case 'spot_setup_info':
            $uuid = require_param('uuid');
            $gridName = optional_param('grid_name', '');
            if ($gridName !== '') Grid::requireApproved($gridName);
            json_success(PairingAuth::getSpotSetupInfo($uuid));

        case 'spot_archived_list':
            $uuid = require_param('uuid');
            $region = require_param('region');
            $gridName = optional_param('grid_name', '');
            json_success(PairingAuth::getArchivedSpots($uuid, $region, $gridName));

        case 'spot_restore':
            $uuid = require_param('uuid');
            $spotId = (int)require_param('spot_id');
            $primUuid = require_param('prim_uuid');
            $posX = (float)require_param('pos_x');
            $posY = (float)require_param('pos_y');
            $posZ = (float)require_param('pos_z');
            $region = require_param('region');
            $gridName = optional_param('grid_name', '');
            if ($gridName !== '') Grid::requireApproved($gridName);
            json_success(PairingAuth::restoreSpot($uuid, $spotId, $primUuid, $posX, $posY, $posZ, $region, $gridName));

        case 'spot_update':
            $uuid = require_param('uuid');
            $spotId = (int)require_param('spot_id');
            json_success(PairingAuth::updateSpot($uuid, $spotId, $_POST));

        case 'spot_add_junk':
            $uuid = require_param('uuid');
            $spotId = (int)require_param('spot_id');
            $itemName = require_param('item_name');
            $rarityWeight = (float)optional_param('rarity_weight', 1.0);
            $rarityLabel = optional_param('rarity_label', 'common');
            json_success(PairingAuth::addJunkItem($uuid, $spotId, $itemName, $rarityWeight, $rarityLabel));

        case 'spot_list_junk':
            $spotId = (int)require_param('spot_id');
            json_success(PairingAuth::listJunkItems($spotId));

        case 'spot_remove_junk':
            $uuid = require_param('uuid');
            $junkId = (int)require_param('junk_id');
            json_success(PairingAuth::removeJunkItem($uuid, $junkId));

        case 'spot_buffs':
            $spotId = (int)require_param('spot_id');
            json_success(PairingAuth::getSpotBuffs($spotId));

        case 'grid_map':
            $gridName = optional_param('grid_name', '');
            json_success(PairingAuth::getGridMap($gridName));

        case 'spot_status':
            $spotId = (int)require_param('spot_id');
            json_success(PairingAuth::spotStatus($spotId));

        // ── Line break report (small XP, magnet loss) ──
        case 'line_break':
            $player = PairingAuth::requireHUD($action);
            $spotId = (int)optional_param('spot_id', 0);
            json_success(Fishing::reportLineBreak((int)$player['id'], $spotId));

        case 'spot_delete':
            $uuid = require_param('uuid');
            $spotId = (int)require_param('spot_id');
            // Verify ownership or admin
            $stmt = db()->prepare('SELECT id, is_admin FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $player = $stmt->fetch();
            if (!$player) json_error('Player not found');
            $stmt = db()->prepare('SELECT player_id FROM fishing_spots WHERE id = :id');
            $stmt->execute([':id' => $spotId]);
            $spot = $stmt->fetch();
            if (!$spot) json_error('Spot not found');
            if ((int)$spot['player_id'] !== (int)$player['id'] && !(int)$player['is_admin'])
                json_error('Not authorized', 403);
            // Use admin delete which handles FK cleanup
            json_success(Admin::deleteSpot($spotId));

        // ── Gather point: self-register from in-world object ──
        case 'gather_register':
            json_success(GatherPoint::registerPoint($_POST));

        // ── Gather point: check status (stock level, respawn) ──
        case 'gather_status':
            $gpId = (int)require_param('point_id');
            json_success(GatherPoint::checkStatus($gpId));

        // ── Gather point: gather one tick of bait (called while sitting) ──
        case 'gather_tick':
            $player = PairingAuth::requireHUD($action);
            $gpId   = (int)require_param('point_id');
            $result = GatherPoint::gather((int)$player['id'], $gpId);
            // Push updated bait count to HUD
            if ($result['gathered'] > 0) {
                PairingAuth::pushEquipUpdate((int)$player['id']);
            }
            json_success($result);

        case 'web_my_spots':
            $player = AuthEmbed::requireWeb();
            $spots = PairingAuth::mySpots((int)$player['id']);
            $stats = PairingAuth::spotStats((int)$player['id'], (int)$player['level']);
            json_success(['spots' => $spots, 'stats' => $stats]);

        case 'web_spot_rename':
            $player = AuthEmbed::requireWeb();
            $spotId = (int)require_param('spot_id');
            $name   = require_param('name');
            json_success(PairingAuth::renameSpot((int)$player['id'], $spotId, $name));

        case 'web_spot_toggle':
            $player = AuthEmbed::requireWeb();
            $spotId = (int)require_param('spot_id');
            $active = (bool)(int)require_param('active');
            json_success(PairingAuth::toggleSpot((int)$player['id'], $spotId, $active));

        case 'web_spot_delete':
            $player = AuthEmbed::requireWeb();
            $spotId = (int)require_param('spot_id');
            json_success(PairingAuth::deleteSpot((int)$player['id'], $spotId));

        case 'web_register':
            $uuid = require_param('uuid');
            $username = require_param('username');
            $password = require_param('password');
            $displayName = optional_param('display_name', '');
            json_success(PairingAuth::webRegister($uuid, $username, $password, $displayName));

        case 'pair_request':
            $uuid = require_param('uuid');
            $gridName = optional_param('grid_name', '');
            if ($gridName !== '') Grid::requireApproved($gridName);
            json_success(PairingAuth::requestCode($uuid, $gridName));

        case 'pair_status':
            $uuid = require_param('uuid');
            $code = require_param('code');
            json_success(PairingAuth::checkStatus($uuid, $code));

        case 'web_pair_claim':
            $player = AuthEmbed::requireWeb();
            $code = require_param('code');
            $deviceName = optional_param('device_name', 'HUD');
            json_success(PairingAuth::claimCode((int)$player['id'], $code, $deviceName));

        case 'web_paired_devices':
            $player = AuthEmbed::requireWeb();
            json_success(['devices' => PairingAuth::listDevices((int)$player['id'])]);

        case 'web_set_home_grid':
            $player = AuthEmbed::requireWeb();
            $g = trim(optional_param('grid_name', ''));
            db()->prepare('UPDATE players SET home_grid = :g WHERE id = :pid')
                ->execute([':g' => $g ?: null, ':pid' => (int)$player['id']]);
            json_success(['message' => $g ? 'Home grid set' : 'Home grid cleared']);

        case 'web_pair_attempts':
            $player = AuthEmbed::requireWeb();
            json_success(['attempts' => PairingAuth::listPairAttempts((int)$player['id'])]);

        case 'web_revoke_device':
            $player = AuthEmbed::requireWeb();
            $deviceId = (int)require_param('device_id');
            json_success(PairingAuth::revokeDevice((int)$player['id'], $deviceId));

        // ── Registration (no auth — first-time HUD attach) ──
        case 'register':
            $uuid = require_param('uuid');
            $name = require_param('display_name');
            json_success(Player::register($uuid, $name));

        // ── Player status (HUD login / refresh) ──
        case 'hud_status':
            $player = PairingAuth::requireHUD($action);
            json_success(Player::getProfile((int)$player['id']));

        // ── Cast ──
        case 'cast':
            $player    = PairingAuth::requireHUD($action);
            $spotId    = (int)require_param('spot_id');
            $castPower = (float)require_param('cast_power');
            json_success(Fishing::cast($player, $spotId, $castPower));

        // ── Roll next minute (subsequent bite windows after first 60s) ──
        case 'roll_minute':
            $player  = PairingAuth::requireHUD($action);
            $spotId  = (int)require_param('spot_id');
            $minute  = (int)require_param('minute');
            json_success(Fishing::rollMinute((int)$player['id'], $spotId, $minute));

        // ── Reel in without a fish (determines bait loss) ──
        case 'reel_in':
            $player = PairingAuth::requireHUD($action);
            $elapsed = (float)require_param('cast_elapsed');
            $caught = (bool)(int)optional_param('caught_fish', 0);
            $broken = (bool)(int)optional_param('line_broken', 0);
            json_success(Fishing::reelIn((int)$player['id'], $elapsed, $caught, $broken));

        // ── Confirm catch (after successful fight) ──
        case 'confirm_catch':
            $player        = PairingAuth::requireHUD($action);
            $catchToken    = require_param('catch_token');
            $spotId        = (int)require_param('spot_id');
            $fightDuration = (float)require_param('fight_duration');
            $castDistance   = (float)optional_param('cast_distance', 0);
            json_success(Fishing::confirmCatch($player, $catchToken, $spotId, $fightDuration, $castDistance));

        // ── Line: list for player (tackle page) ──
        case 'list_lines':
        case 'web_list_lines':
            if ($action === 'web_list_lines') $player = AuthEmbed::requireWeb();
            else $player = PairingAuth::requireHUD($action);
            json_success(['lines' => Line::listForPlayer((int)$player['id'])]);

        // ── Line: equip ──
        case 'line_equip':
        case 'web_line_equip':
            if ($action === 'web_line_equip') $player = AuthEmbed::requireWeb();
            else $player = PairingAuth::requireHUD($action);
            $lineId = (int)require_param('line_id');
            $result = Line::equip((int)$player['id'], $lineId);
            if ($action === 'web_line_equip') PairingAuth::pushEquipUpdate((int)$player['id']);
            json_success($result);

        // ── Line: buy ──
        case 'line_buy':
        case 'web_line_buy':
            if ($action === 'web_line_buy') $player = AuthEmbed::requireWeb();
            else $player = PairingAuth::requireHUD($action);
            $lineId = (int)require_param('line_id');
            $result = Line::buy((int)$player['id'], $lineId);
            if ($action === 'web_line_buy') PairingAuth::pushEquipUpdate((int)$player['id']);
            json_success($result);

        // ── Rod gallery (cosmetic only, no auth needed) ──
        case 'list_rods':
            $rods = db()->query('
                SELECT id, name, tier, description, shop_price AS cost_points, min_level
                FROM rod_types ORDER BY min_level ASC, id ASC
            ')->fetchAll();
            json_success(['rods' => $rods]);

        // ── Bait: get inventory (for HUD equip menu) ──
        case 'bait_inventory':
            $player = PairingAuth::requireHUD($action);
            json_success(['bait' => Bait::getInventory((int)$player['id'])]);

        // ── Bait: equip ──
        case 'bait_equip':
            $player = PairingAuth::requireHUD($action);
            $baitId = (int)require_param('bait_id');
            json_success(Bait::equip((int)$player['id'], $baitId));

        // ── Bait: gather from world ──
        case 'bait_gather':
            $player  = PairingAuth::requireHUD($action);
            $baitId  = (int)require_param('bait_id');
            $spotKey = require_param('spot_key');
            json_success(Bait::gather((int)$player['id'], $baitId, $spotKey));

        // ── Bait: cut fish into bait ──
        case 'bait_cut_fish':
            $player     = PairingAuth::requireHUD($action);
            $fishId     = (int)require_param('fish_id');
            json_success(Bait::cutFishIntoBait((int)$player['id'], $fishId));

        // ── Rod: equip ──
        case 'rod_equip':
            $player = PairingAuth::requireHUD($action);
            $rodId  = (int)require_param('rod_id');
            $rod    = Player::equipRod((int)$player['id'], $rodId);
            json_success(['rod' => $rod, 'message' => "Equipped {$rod['name']}"]);

        // ── Fish: sell specific fish ──
        case 'fish_sell':
            $player  = PairingAuth::requireHUD($action);
            $fishIds = json_decode(require_param('fish_ids'), true);
            if (!is_array($fishIds) || empty($fishIds)) json_error('Provide fish_ids as JSON array');
            json_success(FishInventory::sellFish((int)$player['id'], $fishIds));

        // ── Fish: sell all of a rarity ──
        case 'fish_sell_rarity':
            $player   = PairingAuth::requireHUD($action);
            $rarityId = (int)require_param('rarity_id');
            json_success(FishInventory::sellByRarity((int)$player['id'], $rarityId));

        // ── Fish: sell all of a species ──
        case 'fish_sell_species':
            $player    = PairingAuth::requireHUD($action);
            $speciesId = (int)require_param('species_id');
            json_success(FishInventory::sellBySpecies((int)$player['id'], $speciesId));

        // ── Fish: request physical copy ──
        case 'fish_trophy':
            $player = PairingAuth::requireHUD($action);
            $fishId = (int)require_param('fish_id');
            json_success(FishInventory::requestPhysicalCopy((int)$player['id'], $fishId));

        // ── Fish: get inventory grouped (for HUD) ──
        case 'fish_inventory_grouped':
            $player = PairingAuth::requireHUD($action);
            json_success(['fish' => FishInventory::getGrouped((int)$player['id'])]);

        // ── Quest: get available ──
        case 'quest_available':
            $player = PairingAuth::requireHUD($action);
            json_success(['quests' => Quest::getAvailable((int)$player['id'], (int)$player['level'])]);

        // ── Quest: get active ──
        case 'quest_active':
            $player = PairingAuth::requireHUD($action);
            json_success(['quests' => Quest::getActive((int)$player['id'])]);

        // ── Quest: accept ──
        case 'quest_accept':
            $player  = PairingAuth::requireHUD($action);
            $questId = (int)require_param('quest_id');
            json_success(Quest::accept((int)$player['id'], $questId, (int)$player['level']));

        // ── Quest: turn in fish ──
        case 'quest_turnin':
            $player        = PairingAuth::requireHUD($action);
            $playerQuestId = (int)require_param('player_quest_id');
            $reqId         = (int)require_param('requirement_id');
            $fishIds       = json_decode(require_param('fish_ids'), true);
            if (!is_array($fishIds)) json_error('Provide fish_ids as JSON array');
            json_success(Quest::turnInFish((int)$player['id'], $playerQuestId, $reqId, $fishIds));

        // ── Quest: abandon ──
        case 'quest_abandon':
            $player        = PairingAuth::requireHUD($action);
            $playerQuestId = (int)require_param('player_quest_id');
            json_success(Quest::abandon((int)$player['id'], $playerQuestId));

        // ── Shop: list items ──
        case 'shop_items':
            $player = PairingAuth::requireHUD($action);
            json_success(Shop::getItems((int)$player['level']));

        // ── Shop: buy bait ──
        case 'shop_buy_bait':
            $player  = PairingAuth::requireHUD($action);
            $baitId  = (int)require_param('bait_id');
            $bundles = (int)optional_param('bundles', 1);
            json_success(Shop::buyBait((int)$player['id'], $baitId, $bundles));

        // ── Shop: buy rod ──
        case 'shop_buy_rod':
            $player = PairingAuth::requireHUD($action);
            $rodId  = (int)require_param('rod_id');
            json_success(Shop::buyRod((int)$player['id'], $rodId, (int)$player['level']));

        // ── Leaderboards (no auth needed) ──
        case 'leaderboard':
            $metric     = optional_param('metric', 'weight');
            $scope      = optional_param('scope', 'world');
            $scopeValue = optional_param('scope_value', null);
            $waterType  = optional_param('water_type', null);
            $spotId     = optional_param('spot_id', null);
            $waterTypeId = null;
            if ($waterType) {
                $stmt = db()->prepare('SELECT id FROM water_types WHERE name = :n');
                $stmt->execute([':n' => $waterType]);
                $waterTypeId = $stmt->fetchColumn() ?: null;
            }
            $limit = (int)optional_param('limit', 10);
            if ($limit < 1) $limit = 1;
            if ($limit > 25) $limit = 25;
            json_success([
                'leaderboard' => Leaderboard::get($metric, $scope, $scopeValue, $waterTypeId ? (int)$waterTypeId : null, $limit, $spotId ? (int)$spotId : null),
                'metric' => $metric,
                'scope' => $scope,
                'water_type' => $waterType,
                'spot_id' => $spotId,
            ]);

        case 'leaderboard_biggest':
            $speciesId = optional_param('species_id', null, 'POST') ?? optional_param('species_id', null, 'GET');
            json_success(['leaderboard' => Leaderboard::biggestFish($speciesId ? (int)$speciesId : null)]);

        case 'leaderboard_catches':
            $period = optional_param('period', 'alltime', 'POST') ?? optional_param('period', 'alltime', 'GET');
            json_success(['leaderboard' => Leaderboard::mostCatches($period)]);

        case 'leaderboard_points':
            json_success(['leaderboard' => Leaderboard::mostPoints()]);

        case 'leaderboard_rarest':
            json_success(['leaderboard' => Leaderboard::rarestCatchers()]);

        case 'leaderboard_level':
            json_success(['leaderboard' => Leaderboard::highestLevel()]);

        case 'leaderboard_quests':
            json_success(['leaderboard' => Leaderboard::mostQuests()]);

        case 'leaderboard_records':
            json_success(['records' => Leaderboard::speciesRecords()]);

        case 'tournament_standings':
            $tid = (int)require_param('tournament_id');
            json_success(Leaderboard::tournamentStandings($tid));

        case 'tournaments':
            json_success(['tournaments' => Leaderboard::getTournaments()]);


        // ════════════════════════════════════════════════
        // WEB PORTAL ENDPOINTS (session cookie auth)
        // ════════════════════════════════════════════════

        // ── Web: login ──
        case 'web_login':
            $username = require_param('username');
            $password = require_param('password');
            json_success(Auth::webLogin($username, $password));

        // ── Web: claim a deferred pair after login ──
        case 'web_claim_pending_pair':
            $player = AuthEmbed::requireWeb();
            if (!isset($_SESSION['pair_after_login'])) {
                json_error('No pending pairing', 404);
            }
            $pending = $_SESSION['pair_after_login'];
            unset($_SESSION['pair_after_login']);
            // Verify uuid matches
            if (strcasecmp($player['uuid'], $pending['uuid']) !== 0) {
                json_error('Pairing link is for a different account', 403);
            }
            json_success(PairingAuth::claimCode((int)$player['id'], $pending['code'], 'HUD'));

        // ── Web: logout ──
        case 'web_logout':
            Auth::webLogout();
            json_success(['message' => 'Logged out']);

        // ── Web: complete first-time setup ──
        case 'web_setup':
            $token    = require_param('token');
            $username = require_param('username');
            $password = require_param('password');
            json_success(Player::completeSetup($token, $username, $password));

        // ── Web: dashboard ──
        case 'web_dashboard':
            $player = AuthEmbed::requireWeb();
            json_success(Player::getProfile((int)$player['id']));

        // ── Web: full fish inventory (paginated) ──
        case 'web_fish_inventory':
            $player = AuthEmbed::requireWeb();
            $sort   = optional_param('sort', 'caught_at');
            $order  = optional_param('order', 'DESC');
            $limit  = (int)optional_param('limit', 50);
            $offset = (int)optional_param('offset', 0);
            json_success(FishInventory::getHeld((int)$player['id'], $sort, $order, $limit, $offset));

        // ── Web: catch log ──
        case 'web_catch_log':
            $player = AuthEmbed::requireWeb();
            $limit  = (int)optional_param('limit', 50);
            $offset = (int)optional_param('offset', 0);
            json_success(['log' => FishInventory::getCatchLog((int)$player['id'], $limit, $offset)]);

        // ── Web: fish collection / journal ──
        case 'web_collection':
            $player = AuthEmbed::requireWeb();
            json_success(FishInventory::getCollection((int)$player['id']));

        // ── Web: bait inventory ──
        case 'web_bait_inventory':
            $player = AuthEmbed::requireWeb();
            json_success(['bait' => Bait::getInventory((int)$player['id'])]);

        // ── Web: equip bait from web ──
        case 'web_bait_equip':
            $player = AuthEmbed::requireWeb();
            $baitId = (int)require_param('bait_id');
            $result = Bait::equip((int)$player['id'], $baitId);
            PairingAuth::pushEquipUpdate((int)$player['id']);
            Tutorial::pushEvent((int)$player['id'], 'bait_equipped');
            json_success($result);

        // ── Web: sell fish ──
        case 'web_fish_sell':
            $player  = AuthEmbed::requireWeb();
            $fishIds = json_decode(require_param('fish_ids'), true);
            if (!is_array($fishIds)) json_error('Provide fish_ids as JSON array');
            $result = FishInventory::sellFish((int)$player['id'], $fishIds);
            PairingAuth::pushEquipUpdate((int)$player['id']);
            Tutorial::pushEvent((int)$player['id'], 'fish_sold', ['count' => count($fishIds)]);
            json_success($result);

        // ── Web: sell by rarity ──
        case 'web_fish_sell_rarity':
            $player   = AuthEmbed::requireWeb();
            $rarityId = (int)require_param('rarity_id');
            $result = FishInventory::sellByRarity((int)$player['id'], $rarityId);
            PairingAuth::pushEquipUpdate((int)$player['id']);
            json_success($result);

        // ── Web: quest log ──
        case 'web_quest_active':
            $player = AuthEmbed::requireWeb();
            json_success(['quests' => Quest::getActive((int)$player['id'])]);

        case 'web_quest_available':
            $player = AuthEmbed::requireWeb();
            json_success(['quests' => Quest::getAvailable((int)$player['id'], (int)$player['level'])]);

        case 'web_quest_completed':
            $player = AuthEmbed::requireWeb();
            json_success(['quests' => Quest::getCompleted((int)$player['id'])]);

        case 'web_quest_accept':
            $player  = AuthEmbed::requireWeb();
            $questId = (int)require_param('quest_id');
            json_success(Quest::accept((int)$player['id'], $questId, (int)$player['level']));

        case 'web_quest_turnin':
            $player        = AuthEmbed::requireWeb();
            $playerQuestId = (int)require_param('player_quest_id');
            $reqId         = (int)require_param('requirement_id');
            $fishIds       = json_decode(require_param('fish_ids'), true);
            json_success(Quest::turnInFish((int)$player['id'], $playerQuestId, $reqId, $fishIds));

        case 'web_quest_abandon':
            $player        = AuthEmbed::requireWeb();
            $playerQuestId = (int)require_param('player_quest_id');
            json_success(Quest::abandon((int)$player['id'], $playerQuestId));

        case 'web_quest_claim':
            $player        = AuthEmbed::requireWeb();
            $playerQuestId = (int)require_param('player_quest_id');
            $result = Quest::claimRewards((int)$player['id'], $playerQuestId);
            PairingAuth::pushEquipUpdate((int)$player['id']);
            json_success($result);

        // ── Web: shop ──
        case 'web_shop':
            $player = AuthEmbed::requireWeb();
            json_success(Shop::getItems((int)$player['level']));

        case 'web_shop_buy_bait':
            $player  = AuthEmbed::requireWeb();
            $baitId  = (int)require_param('bait_id');
            $bundles = (int)optional_param('bundles', 1);

            // Check if this is a magnet (exempt from daily limit)
            $stmt = db()->prepare('SELECT name FROM bait_types WHERE id = :id');
            $stmt->execute([':id' => $baitId]);
            $baitName = $stmt->fetchColumn();
            $isMagnet = (strtolower($baitName ?: '') === 'magnet');

            if (!$isMagnet) {
                // Check daily web purchase limit
                $dailyCheck = ShopSystem::checkWebDailyLimit((int)$player['id']);
                $qty = $bundles * 10;  // bundles of 10
                if ($dailyCheck['remaining'] < $qty) {
                    json_error("Daily web limit: {$dailyCheck['remaining']} bait remaining today. Visit an in-world shop for more!");
                }
                // Increment after purchase
                for ($i = 0; $i < $qty; $i++) ShopSystem::incrementWebPurchase((int)$player['id']);
            }

            json_success(Shop::buyBait((int)$player['id'], $baitId, $bundles));

        case 'web_shop_buy_rod':
            $player = AuthEmbed::requireWeb();
            $rodId  = (int)require_param('rod_id');
            json_success(Shop::buyRod((int)$player['id'], $rodId, (int)$player['level']));

        // ── Web: owned rods ──
        case 'web_rods':
            $player = AuthEmbed::requireWeb();
            $equipped = (int)$player['equipped_rod_id'];
            $rods = Shop::getOwnedRods((int)$player['id']);
            foreach ($rods as &$r) $r['is_equipped'] = ((int)$r['id'] === $equipped);
            json_success(['rods' => $rods]);

        case 'web_rod_equip':
            $player = AuthEmbed::requireWeb();
            $rodId  = (int)require_param('rod_id');
            $rod    = Player::equipRod((int)$player['id'], $rodId);
            json_success(['rod' => $rod]);

        // ── Web: player titles ──
        case 'web_titles':
            $player = AuthEmbed::requireWeb();
            $stmt = db()->prepare('SELECT * FROM player_titles WHERE player_id = :pid ORDER BY earned_at DESC');
            $stmt->execute([':pid' => $player['id']]);
            json_success(['titles' => $stmt->fetchAll()]);

        case 'web_title_activate':
            $player  = AuthEmbed::requireWeb();
            $titleId = (int)require_param('title_id');
            // Deactivate all, activate chosen
            db()->prepare('UPDATE player_titles SET is_active = 0 WHERE player_id = :pid')
                ->execute([':pid' => $player['id']]);
            db()->prepare('UPDATE player_titles SET is_active = 1 WHERE id = :id AND player_id = :pid')
                ->execute([':id' => $titleId, ':pid' => $player['id']]);
            json_success(['message' => 'Title updated']);

        // ── Web: change password ──
        case 'web_change_password':
            $player  = AuthEmbed::requireWeb();
            $current = require_param('current_password');
            $newPass = require_param('new_password');
            if (!password_verify($current, $player['password_hash'])) {
                json_error('Current password incorrect');
            }
            if (strlen($newPass) < 6) json_error('New password must be at least 6 characters');
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare('UPDATE players SET password_hash = :h WHERE id = :id')
                ->execute([':h' => $hash, ':id' => $player['id']]);
            json_success(['message' => 'Password changed']);


        // ════════════════════════════════════════════════
        // ADMIN ENDPOINTS
        // ════════════════════════════════════════════════

        case 'admin_announce':
            $player = AuthEmbed::requireWeb();
            Auth::requireAdmin($player);
            $message  = require_param('message');
            $priority = optional_param('priority', 'normal');
            $expires  = optional_param('expires_at');
            $stmt = db()->prepare('
                INSERT INTO announcements (message, priority, expires_at, created_by)
                VALUES (:msg, :pri, :exp, :by)
            ');
            $stmt->execute([':msg' => $message, ':pri' => $priority, ':exp' => $expires, ':by' => $player['id']]);
            json_success(['message' => 'Announcement posted']);

        case 'admin_event_toggle':
            $player = AuthEmbed::requireWeb();
            Auth::requireAdmin($player);
            $eventKey = require_param('event_key');
            $active   = (int)require_param('active');
            $stmt = db()->prepare('UPDATE world_events SET is_active = :a WHERE event_key = :ek');
            $stmt->execute([':a' => $active, ':ek' => $eventKey]);
            json_success(['message' => "Event {$eventKey} " . ($active ? 'activated' : 'deactivated')]);

        case 'admin_grant_points':
            $player = AuthEmbed::requireWeb();
            Auth::requireAdmin($player);
            $targetUuid = require_param('target_uuid');
            $amount     = (int)require_param('amount');
            $target     = Auth::getPlayerByUUID($targetUuid);
            if (!$target) json_error('Player not found');
            $newBal = Player::addPoints((int)$target['id'], $amount);
            json_success(['message' => "Granted {$amount} points", 'new_balance' => $newBal]);

        case 'admin_grant_bait':
            $player = AuthEmbed::requireWeb();
            Auth::requireAdmin($player);
            $targetUuid = require_param('target_uuid');
            $baitId     = (int)require_param('bait_id');
            $quantity   = (int)require_param('quantity');
            $target     = Auth::getPlayerByUUID($targetUuid);
            if (!$target) json_error('Player not found');
            $stmt = db()->prepare('
                INSERT INTO player_bait (player_id, bait_id, quantity)
                VALUES (:pid, :bid, :qty)
                ON DUPLICATE KEY UPDATE quantity = LEAST(quantity + :qty2, :max)
            ');
            $stmt->execute([
                ':pid' => $target['id'], ':bid' => $baitId,
                ':qty' => $quantity, ':qty2' => $quantity, ':max' => MAX_BAIT_STACK,
            ]);
            json_success(['message' => "Granted {$quantity} bait"]);

        case 'admin_players':
            $player = AuthEmbed::requireWeb();
            Auth::requireAdmin($player);
            $stmt = db()->prepare('
                SELECT p.id, p.uuid, p.username, p.display_name, p.level, p.xp, p.fishing_points,
                       p.total_casts, p.total_catches, p.created_at, p.last_fished,
                       (SELECT COUNT(*) FROM hud_exclusion_triggers
                        WHERE player_id = p.id) AS exclusion_triggers
                FROM players p
                ORDER BY p.last_fished DESC
            ');
            $stmt->execute();
            json_success(['players' => $stmt->fetchAll()]);

        case 'admin_player_exclusions':
            $player = AuthEmbed::requireWeb();
            Auth::requireAdmin($player);
            $pid = (int)require_param('player_id');
            json_success(PairingAuth::getExclusionStats($pid));

        // ── Check announcements (HUD polls this) ──
        case 'announcements':
            $stmt = db()->prepare('
                SELECT message, priority FROM announcements
                WHERE starts_at <= NOW() AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY priority DESC, id DESC LIMIT 5
            ');
            $stmt->execute();
            json_success(['announcements' => $stmt->fetchAll()]);


        // ════════════════════════════════════════════════
        // ADMIN ENDPOINTS
        // ════════════════════════════════════════════════

        // ── FAQ & Help (public + admin) ──
        case 'get_faq':
            json_success(['faqs' => FAQ::getAll()]);

        case 'submit_question':
            $pid = null; $pname = null;
            try { $p = AuthEmbed::requireWeb(); $pid = (int)$p['id']; $pname = $p['display_name'] ?? $p['username'] ?? null; } catch (\Throwable $e) {}
            $q = require_param('question');
            json_success(FAQ::submitQuestion($pid, $pname, $q));

        case 'admin_list_questions':
            Admin::requireAdmin();
            json_success(FAQ::listQuestions(optional_param('status', 'all')));

        case 'admin_reply_question':
            Admin::requireAdmin();
            json_success(FAQ::replyQuestion((int)require_param('question_id'), require_param('reply')));

        case 'admin_dismiss_question':
            Admin::requireAdmin();
            json_success(FAQ::dismissQuestion((int)require_param('question_id')));

        case 'admin_list_faq':
            Admin::requireAdmin();
            json_success(FAQ::listAllFAQ());

        case 'admin_create_faq':
            Admin::requireAdmin();
            json_success(FAQ::createFAQ(require_param('question'), require_param('answer'), optional_param('category', 'general')));

        case 'admin_update_faq':
            Admin::requireAdmin();
            json_success(FAQ::updateFAQ((int)require_param('faq_id'), $_POST));

        case 'admin_delete_faq':
            Admin::requireAdmin();
            json_success(FAQ::deleteFAQ((int)require_param('faq_id')));

        case 'admin_stats':
            Admin::requireAdmin();
            json_success(Admin::globalStats());

        case 'admin_list_players':
            Admin::requireAdmin();
            $search = optional_param('search', '');
            $limit  = (int)optional_param('limit', 50);
            $offset = (int)optional_param('offset', 0);
            json_success(Admin::listPlayers($search, $limit, $offset));

        case 'admin_get_player':
            Admin::requireAdmin();
            $pid = (int)require_param('player_id');
            json_success(Admin::getPlayer($pid));

        case 'admin_update_player':
            Admin::requireAdmin();
            $pid = (int)require_param('player_id');
            json_success(Admin::updatePlayer($pid, $_POST));

        case 'admin_grant_bait':
            Admin::requireAdmin();
            $pid = (int)require_param('player_id');
            $bid = (int)require_param('bait_id');
            $qty = (int)require_param('quantity');
            json_success(Admin::grantBait($pid, $bid, $qty));

        case 'admin_grant_line':
            Admin::requireAdmin();
            $pid = (int)require_param('player_id');
            $lid = (int)require_param('line_id');
            json_success(Admin::grantLine($pid, $lid));

        case 'admin_list_spots':
            Admin::requireAdmin();
            $search = optional_param('search', '');
            $view = optional_param('view', 'active');
            json_success(Admin::listSpots($search, $view));

        case 'admin_unarchive_spot':
            Admin::requireAdmin();
            $spotId = (int)require_param('spot_id');
            json_success(Admin::unarchiveSpot($spotId));

        case 'admin_update_spot':
            Admin::requireAdmin();
            $sid = (int)require_param('spot_id');
            json_success(Admin::updateSpot($sid, $_POST));

        case 'admin_delete_spot':
            Admin::requireAdmin();
            $sid = (int)require_param('spot_id');
            json_success(Admin::deleteSpot($sid));

        case 'admin_list_fish':
            Admin::requireAdmin();
            json_success(Admin::listFishSpecies());

        case 'admin_update_fish':
            Admin::requireAdmin();
            $fid = (int)require_param('fish_id');
            json_success(Admin::updateFish($fid, $_POST));

        case 'admin_list_lines':
            Admin::requireAdmin();
            json_success(Admin::listLineTypes());

        case 'admin_update_line':
            Admin::requireAdmin();
            $lid = (int)require_param('line_id');
            json_success(Admin::updateLine($lid, $_POST));

        case 'admin_list_bait':
            Admin::requireAdmin();
            json_success(Admin::listBaitTypes());

        case 'admin_update_bait':
            Admin::requireAdmin();
            $bid = (int)require_param('bait_id');
            json_success(Admin::updateBait($bid, $_POST));

        case 'admin_bait_affinity':
            Admin::requireAdmin();
            $fid = (int)require_param('fish_id');
            json_success(Admin::listBaitAffinity($fid));

        case 'admin_set_affinity':
            Admin::requireAdmin();
            $fid = (int)require_param('fish_id');
            $bid = (int)require_param('bait_id');
            $aff = (float)require_param('affinity');
            json_success(Admin::setBaitAffinity($fid, $bid, $aff));

        case 'admin_list_announcements':
            Admin::requireAdmin();
            json_success(Admin::listAnnouncements());

        case 'admin_create_announcement':
            $admin = Admin::requireAdmin();
            $title = require_param('title');
            $body  = require_param('body');
            $pri   = optional_param('priority', 'info');
            $exp   = optional_param('expires_at', null);
            json_success(Admin::createAnnouncement((int)$admin['id'], $title, $body, $pri, $exp));

        case 'admin_toggle_announcement':
            Admin::requireAdmin();
            $aid = (int)require_param('announcement_id');
            $act = (bool)(int)require_param('active');
            json_success(Admin::toggleAnnouncement($aid, $act));

        case 'admin_delete_announcement':
            Admin::requireAdmin();
            $aid = (int)require_param('announcement_id');
            json_success(Admin::deleteAnnouncement($aid));

        // ── Butchering (UUID auth from in-world table) ──
        case 'butcher_list':
        case 'web_butcher_list':
            $uuid = optional_param('uuid', null);
            if ($uuid) {
                $stmt = db()->prepare('SELECT * FROM players WHERE uuid = :u');
                $stmt->execute([':u' => $uuid]);
                $player = $stmt->fetch();
                if (!$player) json_error('Player not found');
            } else {
                $player = AuthEmbed::requireWeb();
            }
            json_success(Butcher::listFish((int)$player['id']));

        case 'butcher_fish':
        case 'web_butcher_fish':
            $uuid = optional_param('uuid', null);
            if ($uuid) {
                $stmt = db()->prepare('SELECT * FROM players WHERE uuid = :u');
                $stmt->execute([':u' => $uuid]);
                $player = $stmt->fetch();
                if (!$player) json_error('Player not found');
            } else {
                $player = AuthEmbed::requireWeb();
            }
            $fishId = (int)require_param('player_fish_id');
            json_success(Butcher::butcher((int)$player['id'], $fishId));

        // ── Tournaments ──
        case 'tournament_leaderboard':
            $tid = (int)require_param('tournament_id');
            json_success(Tournament::getLeaderboard($tid));

        case 'tournament_list':
            json_success(Tournament::listAll());

        case 'admin_tournament_create':
            $admin = Admin::requireAdmin();
            $_POST['admin_id'] = (int)$admin['id'];
            json_success(Tournament::create($_POST));

        case 'admin_tournament_end':
            Admin::requireAdmin();
            json_success(Tournament::endTournament((int)require_param('tournament_id')));

        case 'admin_tournament_delete':
            Admin::requireAdmin();
            json_success(Tournament::deleteTournament((int)require_param('tournament_id')));

        // ── Admin: gather points ──
        case 'admin_list_gather':
            Admin::requireAdmin();
            json_success(GatherPoint::listAll(optional_param('search', '')));

        case 'admin_update_gather':
            Admin::requireAdmin();
            $gid = (int)require_param('point_id');
            json_success(GatherPoint::adminUpdate($gid, $_POST));

        case 'admin_delete_gather':
            Admin::requireAdmin();
            $gid = (int)require_param('point_id');
            json_success(GatherPoint::adminDelete($gid));

        case 'admin_refill_gather':
            Admin::requireAdmin();
            $gid = (int)require_param('point_id');
            json_success(GatherPoint::adminRefill($gid));

        // ── Buff system ──
        case 'buff_items':
            json_success(Buff::listItems());

        case 'buff_inventory':
            $uuid = require_param('uuid');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(Buff::playerInventory((int)$p['id']));

        case 'buff_spot_status':
            $spotId = (int)require_param('spot_id');
            json_success(Buff::getSpotBuffs($spotId));

        case 'buff_activate':
            $uuid = require_param('uuid');
            $spotId = (int)require_param('spot_id');
            $buffItemId = (int)require_param('buff_item_id');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(Buff::activate((int)$p['id'], $spotId, $buffItemId));

        case 'buff_buy':
            $uuid = require_param('uuid');
            $buffItemId = (int)require_param('buff_item_id');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(Buff::buyFromShop((int)$p['id'], $buffItemId));

        case 'web_buff_inventory':
            $player = AuthEmbed::requireWeb();
            json_success(Buff::playerInventory((int)$player['id']));

        case 'web_buff_activate':
            $player = AuthEmbed::requireWeb();
            $spotId = (int)require_param('spot_id');
            $buffItemId = (int)require_param('buff_item_id');
            json_success(Buff::activate((int)$player['id'], $spotId, $buffItemId));

        case 'web_buff_buy':
            $player = AuthEmbed::requireWeb();
            $buffItemId = (int)require_param('buff_item_id');
            json_success(Buff::buyFromShop((int)$player['id'], $buffItemId));

        // ── Crafting (at butcher table) ──
        case 'craft_recipes':
            $uuid = require_param('uuid');
            $stmt = db()->prepare('SELECT level FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(Buff::getCraftingRecipes((int)$p['level']));

        case 'craft_item':
            $uuid = require_param('uuid');
            $buffType = require_param('buff_type');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(Buff::craft((int)$p['id'], $buffType));

        case 'admin_buff_grant':
            Admin::requireAdmin();
            $playerId = (int)require_param('player_id');
            $buffItemId = (int)require_param('buff_item_id');
            $qty = (int)optional_param('quantity', 1);
            json_success(Buff::adminGrant($playerId, $buffItemId, $qty));

        // ── Prim callback registration ──
        case 'register_prim_callback':
            $primUuid = require_param('prim_uuid');
            $primType = require_param('prim_type');
            $callbackUrl = require_param('callback_url');
            $refId = isset($_POST['ref_id']) ? (int)$_POST['ref_id'] : null;
            $regionName = optional_param('region_name', '');
            $gridName = optional_param('grid_name', '');
            json_success(PrimCallback::register($primUuid, $primType, $callbackUrl, $refId, $regionName, $gridName));

        case 'unregister_prim_callback':
            $primUuid = require_param('prim_uuid');
            json_success(PrimCallback::unregister($primUuid));

        case 'prim_heartbeat':
            $primUuid = require_param('prim_uuid');
            PrimCallback::heartbeat($primUuid);
            // Opportunistic cleanup: ~5% chance per heartbeat, this prevents
            // every heartbeat from doing a sweep but keeps the table tidy.
            if (mt_rand(1, 20) === 1) {
                PrimCallback::cleanupStale();
            }
            json_success(['ok' => true]);

        // ── Tutorial ──
        case 'hud_tutorial_state':
            $auth = PairingAuth::requireHUD('hud_tutorial_state');
            json_success(Tutorial::getState((int)$auth['id']));

        case 'hud_tutorial_set_step':
            $auth = PairingAuth::requireHUD('hud_tutorial_set_step');
            $step = (int)require_param('step');
            Tutorial::setStep((int)$auth['id'], $step);
            json_success(['ok' => true, 'step' => $step]);

        case 'hud_tutorial_complete':
            $auth = PairingAuth::requireHUD('hud_tutorial_complete');
            Tutorial::complete((int)$auth['id']);
            json_success(['ok' => true]);

        case 'web_tutorial_restart':
            $player = AuthEmbed::requireWeb();
            Tutorial::restart((int)$player['id']);
            // Push event to HUD if connected
            Tutorial::pushEvent((int)$player['id'], 'tutorial_restart');
            json_success(['ok' => true, 'message' => 'Tutorial reset. Click your HUD to begin.']);

        // ── Trophy / Saved Fish ──
        case 'web_trophy_save':
            $player = AuthEmbed::requireWeb();
            $fishId = (int)require_param('fish_id');
            $note = optional_param('note', '');
            json_success(Trophy::saveFish((int)$player['id'], $fishId, $note));

        case 'web_trophy_return':
            $player = AuthEmbed::requireWeb();
            $savedId = (int)require_param('saved_id');
            json_success(Trophy::returnToInventory((int)$player['id'], $savedId));

        case 'web_trophy_note':
            $player = AuthEmbed::requireWeb();
            $savedId = (int)require_param('saved_id');
            $note = optional_param('note', '');
            json_success(Trophy::updateNote((int)$player['id'], $savedId, $note));

        case 'web_trophy_list':
            $player = AuthEmbed::requireWeb();
            json_success(Trophy::getSaved((int)$player['id']));

        case 'trophy_for_plaque':
            $uuid = require_param('uuid');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(Trophy::getForPlaque((int)$p['id']));

        // ── Physical Shops ──
        case 'shop_register':
            json_success(ShopSystem::registerShop($_POST));

        case 'shops_in_region':
            $region = require_param('region');
            $gridName = optional_param('grid_name', '');
            json_success(ShopSystem::getShopsInRegion($region, $gridName));

        case 'bait_list_shop':
            // All sellable bait types (for vendor setup) — shop_enabled only
            $bait = db()->query('
                SELECT id, name, shop_price FROM bait_types
                WHERE shop_enabled = 1 AND shop_price > 0
                ORDER BY shop_price ASC, name ASC
            ')->fetchAll();
            json_success(['bait' => $bait]);

        case 'shop_deposit':
            $uuid = require_param('uuid');
            $listingId = (int)require_param('listing_id');
            $qty = (int)require_param('quantity');
            json_success(ShopSystem::deposit($uuid, $listingId, $qty));

        case 'shop_withdraw':
            $uuid = require_param('uuid');
            $listingId = (int)require_param('listing_id');
            $qty = (int)require_param('quantity');
            json_success(ShopSystem::withdraw($uuid, $listingId, $qty));

        case 'admin_shop_restock':
            $uuid = require_param('uuid');
            $listingId = (int)require_param('listing_id');
            json_success(ShopSystem::adminRestock($uuid, $listingId));

        case 'shop_register_listing':
            json_success(ShopSystem::registerListing($_POST));

        case 'shop_listing_status':
            json_success(ShopSystem::getListingStatus((int)require_param('listing_id')));

        case 'shop_buy':
            $uuid = require_param('uuid');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            $qty = (int)optional_param('quantity', 1);
            json_success(ShopSystem::buy((int)$p['id'], (int)require_param('listing_id'), $qty));

        case 'shop_sell':
            $uuid = require_param('uuid');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            $qty = (int)optional_param('quantity', 1);
            json_success(ShopSystem::sell((int)$p['id'], (int)require_param('listing_id'), $qty));

        case 'shop_transactions':
            $uuid = require_param('uuid');
            $stmt = db()->prepare('SELECT id FROM players WHERE uuid = :u');
            $stmt->execute([':u' => $uuid]);
            $p = $stmt->fetch();
            if (!$p) json_error('Player not found');
            json_success(ShopSystem::playerTransactions((int)$p['id']));

        case 'shop_set_modifier':
            $uuid = require_param('uuid');
            json_success(ShopSystem::setModifier($uuid, (int)require_param('listing_id'), (float)require_param('modifier')));

        case 'shop_map':
            json_success(ShopSystem::getShopsForMap(optional_param('grid_name', '')));

        case 'web_shop_transactions':
            $player = AuthEmbed::requireWeb();
            json_success(ShopSystem::playerTransactions((int)$player['id']));

        case 'web_daily_limit':
            $player = AuthEmbed::requireWeb();
            json_success(ShopSystem::checkWebDailyLimit((int)$player['id']));

        case 'grid_list_approved':
            json_success(Grid::listApproved());

        case 'grid_check':
            $gridName = require_param('grid_name');
            json_success(Grid::checkGrid($gridName));

        case 'admin_grid_list':
            Admin::requireAdmin();
            json_success(Grid::listAll());

        case 'admin_grid_approve':
            Admin::requireAdmin();
            json_success(Grid::approve((int)require_param('grid_id')));

        case 'admin_grid_deny':
            Admin::requireAdmin();
            json_success(Grid::deny((int)require_param('grid_id')));

        case 'admin_grid_nickname':
            Admin::requireAdmin();
            json_success(Grid::setNickname((int)require_param('grid_id'), require_param('nickname')));

        case 'admin_grid_hop_gateway':
            Admin::requireAdmin();
            $gridId = (int)require_param('grid_id');
            $gw = trim(optional_param('hop_gateway', ''));
            db()->prepare('UPDATE grids SET hop_gateway = :g WHERE id = :id')
                ->execute([':g' => $gw ?: null, ':id' => $gridId]);
            json_success(['message' => 'Hop gateway updated']);

        case 'admin_grid_delete':
            Admin::requireAdmin();
            json_success(Grid::deleteGrid((int)require_param('grid_id')));


        // ════════════════════════════════════════════════
        default:
            json_error("Unknown action: {$action}", 404);
    }

} catch (PDOException $e) {
    error_log("Fishing API DB Error: " . $e->getMessage());
    json_error('A database error occurred', 500);
} catch (\Exception $e) {
    error_log("Fishing API Error: " . $e->getMessage());
    json_error('An internal error occurred', 500);
}
