-- iOS 앱 버전 자동 조회 (App Store 조회 API, cron 하루 한 번). docs/APPS.md 2단계
-- version·released_at·store_info·checked_at 은 014 에 있다. 실패 사유만 추가한다.
ALTER TABLE apps ADD COLUMN store_error TEXT NULL;
