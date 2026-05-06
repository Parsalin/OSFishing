-- Distinguish user-revoked tokens from those deactivated by another HUD logging in.
-- - revoked_at NULL = token can be reactivated by re-use (just inactive because another grid took over)
-- - revoked_at set  = user explicitly revoked, requires re-pair

ALTER TABLE hud_tokens ADD COLUMN revoked_at DATETIME DEFAULT NULL AFTER is_active;
