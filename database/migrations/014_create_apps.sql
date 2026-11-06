-- 서비스별 앱(iOS / Android / 웹앱)과 앱을 올리는 개발자 계정(Apple Developer / Google Play Console). docs/APPS.md
-- 비밀번호·API 키·인증서는 저장하지 않는다. 참조되므로 삭제하지 않고 비활성화한다.
CREATE TABLE app_store_accounts (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    platform               TEXT NOT NULL CHECK (platform IN ('apple', 'google')),
    name                   TEXT NOT NULL,
    team_id                TEXT NULL,                    -- Apple 팀 ID
    login_email            TEXT NULL,
    company_id             INTEGER NULL REFERENCES companies (id),
    membership_expires_at  TEXT NULL,                    -- Y-m-d, Apple 연간 멤버십
    notes                  TEXT NULL,
    is_active              INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at             TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at             TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);

CREATE TABLE apps (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    system_id    INTEGER NOT NULL REFERENCES systems (id),
    platform     TEXT NOT NULL CHECK (platform IN ('ios', 'android', 'web')),
    name         TEXT NOT NULL,
    bundle_id    TEXT NULL,                              -- iOS 번들 ID / Android 패키지명
    store_url    TEXT NULL,                              -- 스토어 주소 (웹앱은 앱 주소)
    account_id   INTEGER NULL REFERENCES app_store_accounts (id),
    version      TEXT NULL,
    released_at  TEXT NULL,                              -- Y-m-d, 최근 업데이트일
    store_info   TEXT NULL CHECK (store_info IS NULL OR json_valid(store_info)),   -- 자동 조회 원본 (2단계)
    checked_at   TEXT NULL,
    notes        TEXT NULL,
    is_active    INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at   TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);

CREATE UNIQUE INDEX apps_platform_bundle_id ON apps (platform, bundle_id) WHERE bundle_id IS NOT NULL;
CREATE INDEX apps_system_id ON apps (system_id);
