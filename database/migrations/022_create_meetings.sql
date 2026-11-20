-- 회의록 (docs/MEETINGS.md 2단계). 본문·결정 사항은 APP_KEY 로 암호화 (Crypto). database/config.sql 에 넣지 않는다
CREATE TABLE meetings (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    title           TEXT NOT NULL,                   -- 평문 (목록·검색용). 민감한 내용은 쓰지 않도록 안내
    held_at         TEXT NOT NULL,                   -- Y-m-d H:i
    system_id       INTEGER NULL REFERENCES systems (id),
    visibility      TEXT NOT NULL DEFAULT 'attendees' CHECK (visibility IN ('all', 'attendees', 'private')),
    body_enc        TEXT NOT NULL,
    decisions_enc   TEXT NOT NULL,
    author_user_id  INTEGER NOT NULL REFERENCES users (id),
    deleted_at      TEXT NULL,                       -- 휴지통 (작성자만 보고 되살릴 수 있다)
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_meetings_held_at ON meetings (deleted_at, held_at);

CREATE TABLE meeting_attendees (
    meeting_id  INTEGER NOT NULL REFERENCES meetings (id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users (id),
    PRIMARY KEY (meeting_id, user_id)
);
CREATE INDEX idx_meeting_attendees_user ON meeting_attendees (user_id);
