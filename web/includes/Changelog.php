<?php
/**
 * Player-facing change log, shown on the portal's "What's New" page.
 *
 * RULE: every commit that changes something a player can see or do adds an
 * entry here IN THE SAME COMMIT. Skip only admin-only, internal, refactor or
 * docs-only commits (and say so in the commit report).
 *
 * Writing entries:
 *  - Plain player language: what they see or can now do, not how it works.
 *  - Never spoil a discovery (hidden fish, secret baits, drop rates).
 *  - Merge related commits from the same day into one entry.
 *  - Newest first. `id` is the publish key: never rename or reuse a shipped id.
 *  - type: new | changed | fixed
 *
 * Entries start HIDDEN. Players only see an entry once an admin ticks
 * "Published" on the What's New page (changelog_published).
 */

require_once __DIR__ . '/bootstrap.php';

class Changelog
{
    const ENTRIES = [
        ['id' => '2026-11-21-catch-rules', 'date' => '2026-11-21', 'type' => 'fixed',
         'text' => 'Several fishing rules didn\'t take effect until now. Your choice of bait now changes how often fish bite, and some baits bring up rare fish more often. Some fish keep to certain hours or seasons (by server time, UTC). Levelling up a fishing spot makes rare fish more likely there. Lucky Lure Oil and Golden Hour now raise your chance of rare fish, as they say they do. Buffs that run out now disappear from every spot right away, not just the one someone was fishing at.'],
        ['id' => '2026-11-21-spot-push', 'date' => '2026-11-21', 'type' => 'fixed',
         'text' => 'Fishing spots in-world now really do update the moment something changes: a buff used on the spot, or settings changed on the spot page (name, on/off, public/private, level, junk). Before, the spot only caught up when it next checked in, up to several minutes later.'],
        ['id' => '2026-11-21-shops-fair', 'date' => '2026-11-21', 'type' => 'fixed',
         'text' => 'Website shop: Grasshoppers, Crickets, Minnows, Cut Bait, Leeches and Shrimp took your points but gave no bait. Each bundle now gives 10, and a purchase that fails no longer uses up your daily limit. Bait vendors no longer show "A database error occurred" after you buy or sell, and Shop → History now lists your vendor trades. Player-run shops: the owner stocks them from their own bait (Deposit / Withdraw), pays for bait sold back to the shop, and can\'t buy from their own shop. Crafting a Chum Bucket now uses only rare fish, never your epic or legendary ones.'],
        ['id' => '2026-11-21-cast-record', 'date' => '2026-11-21', 'type' => 'fixed',
         'text' => 'Casting distance now matters: a full-power cast reaches fish up to 10% heavier. Double Hook now gives you the XP for both fish, not just one. A snapped line with a Magnet on costs one Magnet, not two. If you walk away with your line still in the water, your bait now follows the same rules as reeling in after a minute.'],
        ['id' => '2026-11-22-catch-error', 'date' => '2026-11-22', 'type' => 'fixed',
         'text' => 'Landing a fish showed "A database error occurred" on your HUD. Catches now finish normally again, and the rod gallery on the website loads again.'],
        ['id' => '2026-11-21-animations', 'date' => '2026-11-21', 'type' => 'new',
         'text' => 'Your avatar now acts out the fishing: winding up and casting, waiting with the rod, leaning in at a nibble, setting the hook, cranking the reel, and fighting the fish (leaning the way it runs, straining when it pulls hard). Then you hold up your catch, or shake your head when one gets away. Gathering bait has you digging in the ground, and the prep table has you chopping. (Needs the updated HUD, gather points and prep tables.)'],
        ['id' => '2026-11-20-my-spots-blank', 'date' => '2026-11-20', 'type' => 'fixed',
         'text' => 'My Spots showed a blank page if you owned any fishing spots. It now lists them again.'],
        ['id' => '2026-11-20-inventory-tab', 'date' => '2026-11-20', 'type' => 'changed',
         'text' => 'Your fish, tackle, rods, trophies and fishing spots are now together under one Inventory tab, with a button for each along the top. My Spots is now in the panel on your HUD too, so you can open and edit your spots from there.'],
        ['id' => '2026-11-19-bigger-text', 'date' => '2026-11-19', 'type' => 'changed',
         'text' => 'All text on the website, and in the panel on your HUD, is 10% bigger and easier to read.'],
        ['id' => '2026-11-19-spot-page', 'date' => '2026-11-19', 'type' => 'new',
         'text' => 'Fishing spots: click one to pick it as the spot you fish at, or hold the click for a second to open its page on your HUD: where it is (with a teleport link), its level and XP, active buffs, catch stats, recent catches and junk loot, plus every fish that lives there. The ones you have caught at that spot show your best weight; the rest stay ?? until you catch them. You can use your buff items on the spot right from that page. If the spot is yours, the same page lets you rename it, turn it on or off, make it public or private, level it up, add or remove junk items or delete it, and the spot in-world updates straight away. My Spots has an Open button for the same page. (Needs the new HUD and updated fishing spots.)'],
        ['id' => '2026-11-19-spot-buff-show', 'date' => '2026-11-19', 'type' => 'fixed',
         'text' => 'A buff used on a fishing spot now shows on the spot right away, instead of up to five minutes later. (Needs updated fishing spots.)'],
        ['id' => '2026-11-19-hud-version', 'date' => '2026-11-19', 'type' => 'new',
         'text' => 'Your paired HUDs under Settings now show which HUD version each one is running, so you can tell whether you have the latest. (Needs the new HUD.)'],
        ['id' => '2026-11-19-new-hud-bar', 'date' => '2026-11-19', 'type' => 'new',
         'text' => 'A new, smaller HUD: one blue bar with your bait and how much is left, a FISH / STOP button, and the OSFishing logo in the middle. Tap the logo for everything else (fish, quests, shop, ranks and more) in a panel that opens above the bar. The fight now has its own strip on the bar: a meter for line tension and a fish that shows which way it\'s running. Open the panel while fishing to watch the whole cast and fight in a bigger, livelier view. That part is optional; the bar alone is enough to fish. (Needs the new HUD.)'],
        ['id' => '2026-11-19-gather-stock-fix', 'date' => '2026-11-19', 'type' => 'fixed',
         'text' => 'Bait gather points could show the stock of a different gather point in the same region after someone gathered there. (Needs the updated HUD.)'],
        ['id' => '2026-11-19-vendor-text-fallback', 'date' => '2026-11-19', 'type' => 'fixed',
         'text' => 'Bait vendors on grids that don\'t allow their drawn screen stopped working. After a reset they now show their prices and stock as floating text instead. (Needs the updated vendor.)'],
        ['id' => '2026-11-19-hud-pairing-stall', 'date' => '2026-11-19', 'type' => 'fixed',
         'text' => 'HUD: pairing could stall and never notice you had entered the code if a message on the HUD faded at the wrong moment. (Needs the updated HUD.)'],
        ['id' => '2026-11-19-login-lockout', 'date' => '2026-11-19', 'type' => 'changed',
         'text' => 'After five wrong passwords in a row, logins to that account pause for 15 minutes, so nobody can keep guessing your password.'],
        ['id' => '2026-11-19-signup-from-hud', 'date' => '2026-11-19', 'type' => 'changed',
         'text' => 'New accounts are created from the link your HUD gives you, and your HUD pairs as soon as the account is made. This stops anyone from signing up with someone else\'s avatar.'],
        ['id' => '2026-11-19-linked-avatars', 'date' => '2026-11-19', 'type' => 'new',
         'text' => 'Play one account from more than one avatar, for example your avatar on another grid. Wear the HUD on the new avatar, open its pairing link while logged in, and confirm. Manage them under Settings → Linked Avatars.'],
        ['id' => '2026-11-19-recovery-terminal', 'date' => '2026-11-19', 'type' => 'new',
         'text' => 'Forgot your password? Touch an Account Recovery terminal in-world with your avatar for a one-time link to set a new one. Terminals are listed on the login page. The old password-reset kiosk has been retired. Changing your password now logs out your other sessions.'],
        ['id' => '2026-11-19-local-times', 'date' => '2026-11-19', 'type' => 'changed',
         'text' => 'Times on the website (catches, quests, pairing activity) now show in your own time zone.'],
        ['id' => '2026-11-19-pairing-fixes', 'date' => '2026-11-19', 'type' => 'fixed',
         'text' => 'The one-click pairing link from the HUD works when you are already logged in, the website remembers your login when you reload the page, and new regions on an approved grid no longer have to wait for approval before you can fish or pair there.'],
    ];

    /** Entries the viewer may see, with published / unseen flags. */
    public static function forViewer(?array $player): array {
        $pdo = db();
        $isAdmin = $player && (int)$player['is_admin'] === 1;
        $published = [];
        foreach ($pdo->query('SELECT entry_id, published_at FROM changelog_published') as $r) {
            $published[$r['entry_id']] = $r['published_at'];
        }
        $seenAt = null;
        if ($player) {
            $s = $pdo->prepare('SELECT seen_at FROM changelog_seen WHERE player_id = :p');
            $s->execute([':p' => $player['id']]);
            $seenAt = $s->fetchColumn() ?: null;
        }
        $out = [];
        foreach (self::ENTRIES as $e) {
            $pub = $published[$e['id']] ?? null;
            if (!$pub && !$isAdmin) continue;
            $e['published']    = $pub !== null;
            $e['published_at'] = $pub;
            // "New since your last visit" for logged-in players; logged-out
            // visitors compare published_at in their browser.
            $e['unseen'] = $pub !== null && $player && ($seenAt === null || $pub > $seenAt);
            $out[] = $e;
        }
        if ($player) {
            $pdo->prepare('INSERT INTO changelog_seen (player_id, seen_at) VALUES (:p, UTC_TIMESTAMP())
                           ON DUPLICATE KEY UPDATE seen_at = UTC_TIMESTAMP()')->execute([':p' => $player['id']]);
        }
        return ['entries' => $out, 'is_admin' => $isAdmin];
    }

    /** Count of published entries newer than the player's last visit (sidebar dot). */
    public static function unseenCount(int $playerId): int {
        $s = db()->prepare('SELECT COUNT(*) FROM changelog_published cp
                             WHERE cp.published_at > COALESCE((SELECT seen_at FROM changelog_seen WHERE player_id = :p), "1970-01-01")');
        $s->execute([':p' => $playerId]);
        return (int)$s->fetchColumn();
    }

    public static function setPublished(string $entryId, bool $on, int $adminId): array {
        $known = array_column(self::ENTRIES, 'id');
        if (!in_array($entryId, $known, true)) json_error('Unknown changelog entry', 404);
        if ($on) {
            db()->prepare('INSERT IGNORE INTO changelog_published (entry_id, published_by) VALUES (:e, :a)')
                ->execute([':e' => $entryId, ':a' => $adminId]);
        } else {
            db()->prepare('DELETE FROM changelog_published WHERE entry_id = :e')->execute([':e' => $entryId]);
        }
        return ['message' => $on ? 'Published' : 'Hidden'];
    }
}
