-- 스토어 수집 작업 이력 (docs/app-manager-store-integration-spec.md 5.7). 017 처럼 app_store_id 대신 app_id(apps.id)
-- 수집기가 다음 실행 시각(성공 후 하루, 실패 후 1시간)을 정하고, 앱 상세 화면이 마지막 수집 결과를 보여 준다.
-- 오류 메시지는 민감정보 없이 사유만 남긴다. 90일 지난 행은 수집기가 지운다.
CREATE TABLE app_store_sync_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id          INTEGER NOT NULL REFERENCES apps (id),
    sync_type       TEXT NOT NULL,                -- reviews, stats, ...
    sync_status     TEXT NOT NULL CHECK (sync_status IN ('running', 'success', 'failed', 'partial')),
    records_count   INTEGER NOT NULL DEFAULT 0,   -- 새로 넣거나 바뀐 행 수
    started_at      TEXT NOT NULL,
    finished_at     TEXT NULL,
    error_code      TEXT NULL,
    error_message   TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);
CREATE INDEX app_store_sync_logs_app ON app_store_sync_logs (app_id, sync_type, started_at);

-- 리뷰를 어디서 받았는지. 출처마다 주는 정보가 달라 화면이 구분한다
-- (App Store 공개 피드 itunes_rss 는 개발자 답변을 주지 않으므로 '미답변'으로 세지 않는다)
ALTER TABLE app_store_reviews ADD COLUMN source TEXT NULL;
