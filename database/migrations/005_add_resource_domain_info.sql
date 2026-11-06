-- 웹/API 리소스의 현재 연결 IP(DNS 조회)와 도메인 등록 정보(RDAP: 등록업체, 만료일, 네임서버).
-- bin/cron.php 가 갱신한다. IP 는 매 실행, 도메인 정보는 하루 한 번.
ALTER TABLE resources ADD COLUMN resolved_ips TEXT NULL;   -- 쉼표 구분, 정렬
ALTER TABLE resources ADD COLUMN resolved_at TEXT NULL;
ALTER TABLE resources ADD COLUMN domain_info TEXT NULL CHECK (domain_info IS NULL OR json_valid(domain_info));
