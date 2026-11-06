-- 통합 대시보드 핵심 테이블 (SQLite)
-- 시각은 KST 'YYYY-MM-DD HH:MM:SS' 텍스트로 저장한다. 기본값 datetime('now', '+9 hours') = KST 현재 시각.
-- 불리언은 INTEGER 0/1, JSON 은 TEXT(json_valid 검사).

CREATE TABLE users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT NOT NULL COLLATE NOCASE UNIQUE,
    name            TEXT NOT NULL,
    password_hash   TEXT NOT NULL,
    role            TEXT NOT NULL DEFAULT 'viewer' CHECK (role IN ('admin', 'operator', 'viewer')),
    is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    last_login_at   TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);

-- 지표 조회용 AWS 계정 (AssumeRole 대상). 여러 시스템이 한 계정을 공유할 수 있다.
CREATE TABLE aws_accounts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id      TEXT NOT NULL UNIQUE CHECK (length(account_id) = 12),
    alias           TEXT NOT NULL,
    default_region  TEXT NOT NULL DEFAULT 'ap-northeast-2',
    role_arn        TEXT NULL,
    external_id     TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);

-- 관리 대상 시스템 (미리, 코이 등)
CREATE TABLE systems (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    code            TEXT NOT NULL,
    name            TEXT NOT NULL,
    environment     TEXT NOT NULL DEFAULT 'prod' CHECK (environment IN ('prod', 'stage', 'dev')),
    aws_account_id  INTEGER NULL REFERENCES aws_accounts (id) ON DELETE SET NULL,
    owner_user_id   INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
    description     TEXT NULL,
    is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    UNIQUE (code, environment)
);

-- 시스템별 감시 리소스: EC2 인스턴스, RDS 인스턴스, HTTP(웹/API) 엔드포인트
CREATE TABLE resources (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    system_id       INTEGER NOT NULL REFERENCES systems (id) ON DELETE CASCADE,
    type            TEXT NOT NULL CHECK (type IN ('ec2', 'rds', 'http')),
    name            TEXT NOT NULL,
    identifier      TEXT NOT NULL,              -- EC2 instance id / RDS identifier / URL
    region          TEXT NULL,                  -- NULL 이면 aws_accounts.default_region
    external_ref    TEXT NULL,                  -- Uptime Kuma monitor id 등 외부 시스템 키
    config          TEXT NULL CHECK (config IS NULL OR json_valid(config)),  -- 임계치, 헬스체크 경로 등
    current_status  TEXT NOT NULL DEFAULT 'unknown' CHECK (current_status IN ('up', 'down', 'warning', 'unknown')),
    last_checked_at TEXT NULL,
    is_active       INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    UNIQUE (type, identifier)
);
CREATE INDEX idx_resources_system ON resources (system_id);

-- 점검 결과 / 지표 스냅샷 (상태 체크와 CloudWatch 지표를 함께 적재)
CREATE TABLE check_results (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    resource_id     INTEGER NOT NULL REFERENCES resources (id) ON DELETE CASCADE,
    source          TEXT NOT NULL CHECK (source IN ('uptime_kuma', 'cloudwatch', 'dashboard')),
    status          TEXT NOT NULL CHECK (status IN ('up', 'down', 'warning', 'unknown')),
    metric_name     TEXT NULL,                  -- 예: CPUUtilization, mem_used_percent, http
    metric_value    REAL NULL,
    response_ms     INTEGER NULL,
    message         TEXT NULL,
    checked_at      TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_check_results_resource_time ON check_results (resource_id, checked_at);
CREATE INDEX idx_check_results_metric_time ON check_results (resource_id, metric_name, checked_at);
CREATE INDEX idx_check_results_checked ON check_results (checked_at);  -- 보관 기간 지난 행 삭제용

-- 장애 (장애 센터)
CREATE TABLE incidents (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    system_id       INTEGER NOT NULL REFERENCES systems (id) ON DELETE CASCADE,
    resource_id     INTEGER NULL REFERENCES resources (id) ON DELETE SET NULL,
    source          TEXT NOT NULL CHECK (source IN ('uptime_kuma', 'cloudwatch', 'manual')),
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
CREATE INDEX idx_incidents_status_started ON incidents (status, started_at);
CREATE INDEX idx_incidents_alert_key_status ON incidents (alert_key, status);
CREATE INDEX idx_incidents_system_started ON incidents (system_id, started_at);
-- 같은 alert_key 로 복구되지 않은 장애는 하나만 (중복 알림 방지를 DB 에서도 보장)
CREATE UNIQUE INDEX uq_incidents_active_alert_key ON incidents (alert_key) WHERE status <> 'resolved';

-- 장애 타임라인 (발생, 반복 알림, 확인, 메모, 복구)
CREATE TABLE incident_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id     INTEGER NOT NULL REFERENCES incidents (id) ON DELETE CASCADE,
    type            TEXT NOT NULL CHECK (type IN ('opened', 'alert', 'acknowledged', 'note', 'assigned', 'resolved', 'reopened')),
    user_id         INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,  -- NULL 이면 시스템 자동 이벤트
    message         TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_incident_events_incident ON incident_events (incident_id, created_at);

-- 외부에서 받은 원본 알림 (Uptime Kuma webhook, SNS). 재처리·디버깅·중복 수신 방지용
CREATE TABLE alert_events (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    source          TEXT NOT NULL CHECK (source IN ('uptime_kuma', 'cloudwatch')),
    external_id     TEXT NULL,                  -- SNS MessageId 등
    payload         TEXT NOT NULL CHECK (json_valid(payload)),
    incident_id     INTEGER NULL REFERENCES incidents (id) ON DELETE SET NULL,
    received_at     TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    processed_at    TEXT NULL,
    error           TEXT NULL,
    UNIQUE (source, external_id)
);
CREATE INDEX idx_alert_events_received ON alert_events (received_at);

-- updated_at 자동 갱신 (MySQL 의 ON UPDATE CURRENT_TIMESTAMP 대체)
-- 직접 updated_at 을 바꾼 UPDATE 는 건드리지 않는다.
CREATE TRIGGER trg_users_updated_at AFTER UPDATE ON users
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE users SET updated_at = datetime('now', '+9 hours') WHERE id = NEW.id;
END;

CREATE TRIGGER trg_aws_accounts_updated_at AFTER UPDATE ON aws_accounts
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE aws_accounts SET updated_at = datetime('now', '+9 hours') WHERE id = NEW.id;
END;

CREATE TRIGGER trg_systems_updated_at AFTER UPDATE ON systems
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE systems SET updated_at = datetime('now', '+9 hours') WHERE id = NEW.id;
END;

CREATE TRIGGER trg_resources_updated_at AFTER UPDATE ON resources
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE resources SET updated_at = datetime('now', '+9 hours') WHERE id = NEW.id;
END;

CREATE TRIGGER trg_incidents_updated_at AFTER UPDATE ON incidents
    WHEN NEW.updated_at = OLD.updated_at
BEGIN
    UPDATE incidents SET updated_at = datetime('now', '+9 hours') WHERE id = NEW.id;
END;
