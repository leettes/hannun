-- migrator: foreign_keys=off
-- 대시보드 자체 HTTP 점검(bin/cron.php)이 연 장애를 구분하기 위해 incidents.source 에 'dashboard' 를 추가한다.
-- SQLite 는 CHECK 제약을 바꿀 수 없어 테이블을 다시 만든다. 외래 키를 끈 상태로 실행해야
-- DROP TABLE 이 incident_events 등으로 연쇄 삭제되지 않는다 (Migrator 가 첫 줄 표시를 보고 끈다).

CREATE TABLE incidents_new (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    system_id       INTEGER NOT NULL REFERENCES systems (id) ON DELETE CASCADE,
    resource_id     INTEGER NULL REFERENCES resources (id) ON DELETE SET NULL,
    source          TEXT NOT NULL CHECK (source IN ('uptime_kuma', 'cloudwatch', 'dashboard', 'manual')),
    alert_key       TEXT NOT NULL,              -- 중복 알림 묶음 키 (예: cloudwatch:alarm-name)
    severity        TEXT NOT NULL DEFAULT 'major' CHECK (severity IN ('critical', 'major', 'minor', 'info')),
    status          TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'acknowledged', 'resolved')),
    title           TEXT NOT NULL,
    assignee_user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    started_at      TEXT NOT NULL,
    acknowledged_at TEXT NULL,
    resolved_at     TEXT NULL,
    root_cause      TEXT NULL,
    action_taken    TEXT NULL,                  -- 조치 내용
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);

INSERT INTO incidents_new SELECT * FROM incidents;
DROP TABLE incidents;
ALTER TABLE incidents_new RENAME TO incidents;

CREATE INDEX idx_incidents_status_started ON incidents (status, started_at);
CREATE INDEX idx_incidents_alert_key_status ON incidents (alert_key, status);
CREATE INDEX idx_incidents_system_started ON incidents (system_id, started_at);
CREATE UNIQUE INDEX uq_incidents_active_alert_key ON incidents (alert_key) WHERE status <> 'resolved';

CREATE TRIGGER trg_incidents_updated_at AFTER UPDATE ON incidents
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE incidents SET updated_at = datetime('now', '+9 hours') WHERE id = NEW.id;
END;
