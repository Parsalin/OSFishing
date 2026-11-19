<?php
/**
 * Look up an account before doing anything with a player someone names.
 *
 *   php bin/find_account.php <username | display name | avatar name | any avatar UUID | player id>
 *
 * Prints the player id (use it for every game-data query), username, flags,
 * and every linked avatar with its grid and when it was last used — most
 * recent first, so the first avatar is the one in use. An ambiguous query
 * prints every candidate.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Admin.php';

$query = trim(implode(' ', array_slice($argv, 1)));
if ($query === '') {
    fwrite(STDERR, "usage: php bin/find_account.php <username|name|uuid|player id>\n");
    exit(2);
}

// json_error() exits with JSON; fine for a CLI tool too.
$res = Admin::findAccount($query);
if (!$res['accounts']) {
    echo "No account matches \"{$query}\".\n";
    exit(1);
}
if (count($res['accounts']) > 1) {
    echo count($res['accounts']) . " candidates (partial match) — refine the query:\n\n";
}
foreach ($res['accounts'] as $a) {
    $flags = trim(($a['is_admin'] ? 'ADMIN ' : '') . ($a['is_banned'] ? 'BANNED' : ''));
    echo "player_id    {$a['id']}" . ($flags !== '' ? "   [{$flags}]" : '') . "\n";
    echo "username     {$a['username']}\n";
    echo "display      {$a['display_name']}\n";
    echo "level        {$a['level']}\n";
    echo "created      {$a['created_at']} UTC   last login " . ($a['last_login'] ?: 'never') . "   last fished " . ($a['last_fished'] ?: 'never') . "\n";
    echo "matched by   {$res['match']}\n";
    echo "avatars:\n";
    foreach ($a['avatars'] as $av) {
        $tags = [];
        if ($av['is_primary']) $tags[] = 'primary';
        if ((int)$av['hud_count'] > 0) $tags[] = $av['hud_count'] . ' HUD' . ($av['hud_count'] == 1 ? '' : 's');
        $grid = $av['grid_nickname'] ?: ($av['grid_identity'] ?: 'grid unknown');
        printf("  %s  %-24s %-22s last used %s%s\n",
            $av['uuid'], mb_substr((string)$av['avatar_name'], 0, 24), mb_substr($grid, 0, 22),
            $av['last_used_at'] ?: 'never', $tags ? '  [' . implode(', ', $tags) . ']' : '');
    }
    echo "\n";
}
