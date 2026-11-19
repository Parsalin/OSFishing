<?php
/**
 * Tutorial.php - tutorial state and event tracking
 */

class Tutorial
{
    /**
     * Get a player's current tutorial state.
     */
    public static function getState(int $playerId): array {
        $stmt = db()->prepare('SELECT tutorial_completed, tutorial_step FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['completed' => 0, 'step' => 0];
        }
        return [
            'completed' => (int)$row['tutorial_completed'],
            'step'      => (int)$row['tutorial_step'],
        ];
    }

    /**
     * Update the tutorial step. Used by the HUD to advance.
     */
    public static function setStep(int $playerId, int $step): void {
        db()->prepare('UPDATE players SET tutorial_step = :s WHERE id = :id')
            ->execute([':s' => $step, ':id' => $playerId]);
    }

    /**
     * Mark the tutorial as complete (or skipped).
     */
    public static function complete(int $playerId): void {
        db()->prepare('UPDATE players SET tutorial_completed = 1 WHERE id = :id')
            ->execute([':id' => $playerId]);
    }

    /**
     * Restart the tutorial.
     */
    public static function restart(int $playerId): void {
        db()->prepare('UPDATE players SET tutorial_completed = 0, tutorial_step = 0 WHERE id = :id')
            ->execute([':id' => $playerId]);
    }

    /**
     * Push a tutorial event to the player's HUD.
     * Used when actions on the website trigger tutorial advancement
     * (quest accepted, bait equipped, fish sold) since the user can't
     * click through media prims.
     */
    public static function pushEvent(int $playerId, string $event, array $extra = []): void {
        // pushToPlayer queues on no URL or a failed POST, so an event is
        // never dropped (that would strand the player mid-tutorial).
        require_once __DIR__ . '/PairingAuth.php';
        PairingAuth::pushToPlayer($playerId, array_merge([
            'type'  => 'tutorial_event',
            'event' => $event,
        ], $extra));
    }
}
