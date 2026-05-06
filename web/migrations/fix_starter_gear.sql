-- Grant Twine to any player who doesn't own any line
INSERT IGNORE INTO player_lines (player_id, line_id)
SELECT p.id, lt.id
FROM players p
CROSS JOIN line_types lt
WHERE lt.name = 'Twine'
  AND p.id NOT IN (SELECT player_id FROM player_lines);

-- Equip Twine for anyone with no equipped line
UPDATE players p
SET p.equipped_line_id = (SELECT id FROM line_types WHERE name = 'Twine' LIMIT 1)
WHERE p.equipped_line_id IS NULL OR p.equipped_line_id = 0;

-- Also equip bait for anyone with no equipped bait but has bait
UPDATE players p
SET p.equipped_bait_id = 9
WHERE (p.equipped_bait_id IS NULL OR p.equipped_bait_id = 0)
  AND EXISTS (SELECT 1 FROM player_bait pb WHERE pb.player_id = p.id AND pb.bait_id = 9 AND pb.quantity > 0);
