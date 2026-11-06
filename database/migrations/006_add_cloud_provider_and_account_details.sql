-- 서비스별 클라우드 종류(기본 AWS)와 AWS 계정(루트) 관리 정보.
-- 비밀번호·액세스 키는 저장하지 않는다 (비밀번호 관리자에 둔다).
ALTER TABLE systems ADD COLUMN cloud_provider TEXT NOT NULL DEFAULT 'aws'
    CHECK (cloud_provider IN ('aws', 'ncloud', 'gcp', 'azure', 'vercel', 'other'));

ALTER TABLE aws_accounts ADD COLUMN root_email TEXT NULL;
ALTER TABLE aws_accounts ADD COLUMN mfa_enabled INTEGER NULL CHECK (mfa_enabled IS NULL OR mfa_enabled IN (0, 1));  -- NULL = 모름
ALTER TABLE aws_accounts ADD COLUMN notes TEXT NULL;
