-- 서비스에 관리회사를 직접 지정 (AWS 계정이 없는 Vercel 등).
-- 서비스의 관리회사 = systems.company_id, 비어 있으면 AWS 서비스는 AWS 계정의 관리회사.
ALTER TABLE systems ADD COLUMN company_id INTEGER NULL REFERENCES companies (id);
