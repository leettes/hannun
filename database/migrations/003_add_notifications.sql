-- 장애 문자 알림: 사용자 연락처 + 발송 기록(대기열)

ALTER TABLE users ADD COLUMN phone TEXT NULL;                       -- 숫자만 (예: 01012345678)
ALTER TABLE users ADD COLUMN notify_sms INTEGER NOT NULL DEFAULT 0 CHECK (notify_sms IN (0, 1));

CREATE TABLE notifications (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    incident_id     INTEGER NOT NULL REFERENCES incidents (id) ON DELETE CASCADE,
    kind            TEXT NOT NULL CHECK (kind IN ('opened', 'resolved')),
    channel         TEXT NOT NULL DEFAULT 'sms' CHECK (channel IN ('sms')),
    recipients      TEXT NOT NULL,              -- 쉼표 구분 번호 (대기열에 넣는 시점 기준)
    message         TEXT NOT NULL,
    status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sending', 'sent', 'failed', 'skipped')),
    attempts        INTEGER NOT NULL DEFAULT 0,
    error           TEXT NULL,
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL,
    sent_at         TEXT NULL
);
CREATE INDEX idx_notifications_status ON notifications (status, id);
CREATE INDEX idx_notifications_incident ON notifications (incident_id);
