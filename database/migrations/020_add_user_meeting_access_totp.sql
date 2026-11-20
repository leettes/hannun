-- 회의록 권한과 2단계 인증(TOTP). docs/MEETINGS.md
-- 모두 database/config.sql 에 넣지 않는다 (ConfigSnapshot::TABLES 에서 제외)
ALTER TABLE users ADD COLUMN meeting_access TEXT NOT NULL DEFAULT 'none' CHECK (meeting_access IN ('none', 'read', 'write'));
ALTER TABLE users ADD COLUMN totp_secret TEXT NULL;        -- APP_KEY 로 암호화 (Crypto)
ALTER TABLE users ADD COLUMN totp_enabled_at TEXT NULL;    -- NULL 이면 미등록
ALTER TABLE users ADD COLUMN totp_last_step INTEGER NULL;  -- 마지막으로 통과한 30초 단계 (같은 코드 재사용 방지)
