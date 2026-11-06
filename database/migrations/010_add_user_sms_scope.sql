-- 장애 문자 받는 범위: none(안함) / all(전체 서비스) / owned(담당 서비스)
-- 관리자는 전체, 나머지는 담당 서비스로 시작한다. notify_sms 는 더 이상 쓰지 않는다.
ALTER TABLE users ADD COLUMN sms_scope TEXT NOT NULL DEFAULT 'owned' CHECK (sms_scope IN ('none', 'all', 'owned'));
UPDATE users SET sms_scope = 'all' WHERE role = 'admin';
