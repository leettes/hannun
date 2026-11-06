-- 도메인 정보 수동 입력 (관리자). 등록 단위 도메인(example.com)마다 하나.
-- 값이 있는 항목은 자동 수집(RDAP·WHOIS) 값 대신 화면에 쓴다. cron 은 이 테이블을 건드리지 않는다.
CREATE TABLE domain_overrides (
    domain              TEXT PRIMARY KEY,
    registrar           TEXT NULL,
    expires_at          TEXT NULL,   -- Y-m-d
    nameservers         TEXT NULL CHECK (nameservers IS NULL OR json_valid(nameservers)),   -- JSON 배열
    memo                TEXT NULL,
    updated_by_user_id  INTEGER NULL REFERENCES users(id),
    updated_at          TEXT NOT NULL
);
