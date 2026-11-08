-- 서비스 관리자 페이지 주소 (서비스 관리에서 입력, 앱 상세·서비스 상세에서 바로가기). 설정 데이터라 database/config.sql 에 들어간다.
ALTER TABLE systems ADD COLUMN admin_url TEXT NULL;
