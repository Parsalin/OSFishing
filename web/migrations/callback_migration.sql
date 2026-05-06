-- ============================================================
-- FISHING GAME - CALLBACK URL MIGRATION
-- Adds callback_url column to hud_tokens for server push.
-- ============================================================

ALTER TABLE hud_tokens
    ADD COLUMN callback_url VARCHAR(512) DEFAULT NULL AFTER last_ip;
