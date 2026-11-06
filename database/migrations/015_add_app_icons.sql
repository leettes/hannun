-- 앱 아이콘 (cron 이 스토어에서 내려받아 public/app-icons/ 에 저장). CSP 가 외부 이미지를 막아 직접 보관한다.
ALTER TABLE apps ADD COLUMN icon_file TEXT NULL;          -- public/app-icons/ 안의 파일 이름
ALTER TABLE apps ADD COLUMN icon_checked_at TEXT NULL;    -- NULL 이면 다음 cron 에서 가져온다
ALTER TABLE apps ADD COLUMN icon_error TEXT NULL;
