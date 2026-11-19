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
