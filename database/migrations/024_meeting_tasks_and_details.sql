-- 회의록 시안(__bak/회의/시안-메인·상세.html) 반영: 회의 요약·태그·녹취 원문, 참석자 라벨, 회의 할 일
-- 회의 데이터는 모두 database/config.sql 에 넣지 않는다

ALTER TABLE meetings ADD COLUMN subtitle TEXT NULL;          -- 주제 한 줄 (평문, 카드·제목 옆)
ALTER TABLE meetings ADD COLUMN summary_enc TEXT NULL;       -- 요약 문단 (암호화, 카드)
ALTER TABLE meetings ADD COLUMN topics TEXT NULL;            -- 서비스·주제 태그 JSON 배열 (평문)
ALTER TABLE meetings ADD COLUMN duration_minutes INTEGER NULL;
ALTER TABLE meetings ADD COLUMN transcript_enc TEXT NULL;    -- 녹취 원문 (암호화)
ALTER TABLE meetings ADD COLUMN transcript_name TEXT NULL;
ALTER TABLE meetings ADD COLUMN transcript_bytes INTEGER NULL;
ALTER TABLE meetings ADD COLUMN version INTEGER NOT NULL DEFAULT 1;  -- 본문을 고칠 때마다 +1

-- 참석자: 녹취의 '참석자 N' 같은 라벨. 직원 계정과 연결되면 user_id (공개 범위 '참석자만' 판단에 쓴다)
CREATE TABLE meeting_participants (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    meeting_id  INTEGER NOT NULL REFERENCES meetings (id) ON DELETE CASCADE,
    label       TEXT NOT NULL,
    user_id     INTEGER NULL REFERENCES users (id),
    sort        INTEGER NOT NULL DEFAULT 0,
    UNIQUE (meeting_id, label)
);
CREATE INDEX idx_meeting_participants_user ON meeting_participants (user_id);

-- 기존 참석자(직원 계정)를 옮기고 예전 테이블은 지운다
INSERT INTO meeting_participants (meeting_id, label, user_id, sort)
    SELECT a.meeting_id, u.name || CASE WHEN (SELECT COUNT(*) FROM users x WHERE x.name = u.name) > 1 THEN ' #' || u.id ELSE '' END, a.user_id, 0
    FROM meeting_attendees a JOIN users u ON u.id = a.user_id;
DROP TABLE meeting_attendees;

-- 회의에서 나온 할 일. 항목·할 일·비고는 암호화 (서비스·상태·담당은 집계용 평문)
CREATE TABLE meeting_tasks (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    meeting_id        INTEGER NOT NULL REFERENCES meetings (id),
    service           TEXT NOT NULL,
    item_enc          TEXT NOT NULL,
    task_enc          TEXT NOT NULL,
    note_enc          TEXT NULL,
    assignee_user_id  INTEGER NULL REFERENCES users (id),
    assignee_label    TEXT NULL,                 -- '참석자 5' 처럼 회의에서 나온 담당 (계정 연결 전)
    status            TEXT NOT NULL DEFAULT 'todo' CHECK (status IN ('todo', 'doing', 'done', 'hold')),
    done_at           TEXT NULL,
    sort              INTEGER NOT NULL DEFAULT 0,
    created_by        INTEGER NULL REFERENCES users (id),
    created_at        TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_meeting_tasks_meeting ON meeting_tasks (meeting_id, sort);
CREATE INDEX idx_meeting_tasks_status ON meeting_tasks (status, service);

CREATE TABLE meeting_task_events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id     INTEGER NOT NULL REFERENCES meeting_tasks (id),
    user_id     INTEGER NULL REFERENCES users (id),
    type        TEXT NOT NULL CHECK (type IN ('created', 'status', 'assignee', 'edit', 'note')),
    from_value  TEXT NULL,
    to_value    TEXT NULL,
    note        TEXT NULL,
    created_at  TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX idx_meeting_task_events_task ON meeting_task_events (task_id, id);
