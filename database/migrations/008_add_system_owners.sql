-- 서비스 담당자를 여러 명 둔다 (systems.owner_user_id 한 명 → system_owners 여러 명).
-- 담당자는 그 서비스의 장애 문자를 받는다. 계정이나 서비스가 지워지면 연결도 지운다.
CREATE TABLE system_owners (
    system_id   INTEGER NOT NULL REFERENCES systems (id) ON DELETE CASCADE,
    user_id     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    PRIMARY KEY (system_id, user_id)
);
CREATE INDEX idx_system_owners_user ON system_owners (user_id);

INSERT INTO system_owners (system_id, user_id)
SELECT id, owner_user_id FROM systems WHERE owner_user_id IS NOT NULL;

-- 예전 열은 외래 키라 SQLite 에서 바로 지울 수 없어 남겨 두되 더는 쓰지 않는다 (값을 비워 혼동을 막는다)
UPDATE systems SET owner_user_id = NULL;
