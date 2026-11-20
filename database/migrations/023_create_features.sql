-- 기능 추적 (docs/MEETINGS.md 3단계). 서비스·앱 단위 기능을 요청부터 배포까지. 삭제하지 않고 dropped(취소)로 둔다
CREATE TABLE features (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    system_id           INTEGER NOT NULL REFERENCES systems (id),
    app_id              INTEGER NULL REFERENCES apps (id),
    title               TEXT NOT NULL,
    description         TEXT NULL,
    status              TEXT NOT NULL DEFAULT 'requested'
                        CHECK (status IN ('requested', 'planned', 'in_progress', 'released', 'on_hold', 'dropped')),
    target_version      TEXT NULL,
    released_version    TEXT NULL,
    released_at         TEXT NULL,
    meeting_id          INTEGER NULL REFERENCES meetings (id),   -- 출처 회의 (볼 수 있는 사람에게만 링크)
    incident_id         INTEGER NULL REFERENCES incidents (id),
    assignee_user_id    INTEGER NULL REFERENCES users (id),
    created_by_user_id  INTEGER NULL REFERENCES users (id),
    created_at          TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_features_system ON features (system_id, status);
CREATE INDEX idx_features_app ON features (app_id, status);
CREATE INDEX idx_features_meeting ON features (meeting_id);

-- 기능 타임라인 (incident_events 와 같은 방식): 등록, 상태 변경, 수정, 메모
CREATE TABLE feature_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    feature_id  INTEGER NOT NULL REFERENCES features (id),
    user_id     INTEGER NULL REFERENCES users (id),
    type        TEXT NOT NULL CHECK (type IN ('created', 'status', 'edit', 'note')),
    from_value  TEXT NULL,
    to_value    TEXT NULL,
    note        TEXT NULL,
    created_at  TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_feature_events_feature ON feature_events (feature_id, id);
