-- 대시보드 '최근 장애'에서 확인한 장애는 목록에서 뺀다. 장애 상태(진행 중/확인됨/복구됨)와는 별개다.
-- 확인 사유 메모는 incident_events(note)에 남긴다. 장애를 다시 열면 확인이 풀린다.
ALTER TABLE incidents ADD COLUMN dismissed_at TEXT NULL;
ALTER TABLE incidents ADD COLUMN dismissed_by_user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL;
