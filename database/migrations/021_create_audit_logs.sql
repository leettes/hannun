-- 감사 로그: 회의록·권한·2단계 인증 관련 행위. 앱에는 INSERT 만 있고 수정·삭제 경로가 없다 (1년 지난 기록은 cron 이 정리)
CREATE TABLE audit_logs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,  -- NULL = 시스템(cron)
    action       TEXT NOT NULL,
    target_type  TEXT NULL,
    target_id    INTEGER NULL,
    ip           TEXT NULL,
    user_agent   TEXT NULL,
    detail       TEXT NULL,  -- 변경 요약 JSON (본문 내용은 넣지 않음)
    created_at   TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_audit_logs_created ON audit_logs (created_at);
CREATE INDEX idx_audit_logs_user_action ON audit_logs (user_id, action, created_at);
