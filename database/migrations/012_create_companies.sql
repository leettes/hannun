-- 관리회사 (AWS 계정을 관리·결제하는 법인). 계정이 참조하므로 삭제하지 않는다.
CREATE TABLE companies (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT NOT NULL,                 -- 법인명
    ceo_name     TEXT NULL,                     -- 대표자
    business_no  TEXT NULL UNIQUE CHECK (business_no IS NULL OR (length(business_no) = 10 AND business_no NOT GLOB '*[^0-9]*')),  -- 사업자번호 숫자 10자리
    notes        TEXT NULL,
    created_at   TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at   TEXT NOT NULL DEFAULT (datetime('now', '+9 hours'))
);

ALTER TABLE aws_accounts ADD COLUMN company_id INTEGER NULL REFERENCES companies (id);
