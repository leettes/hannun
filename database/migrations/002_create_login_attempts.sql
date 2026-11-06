-- 로그인 시도 기록 (무차별 대입 방지 + 접속 감사)

CREATE TABLE login_attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT NOT NULL COLLATE NOCASE,
    ip              TEXT NOT NULL,
    success         INTEGER NOT NULL CHECK (success IN (0, 1)),
    user_agent      TEXT NULL,
    created_at      TEXT NOT NULL
);
CREATE INDEX idx_login_attempts_email_time ON login_attempts (email, created_at);
CREATE INDEX idx_login_attempts_ip_time ON login_attempts (ip, created_at);
