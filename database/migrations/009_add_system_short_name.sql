-- 사이드바 서비스 타일에 쓰는 약칭 (최대 2글자). 비우면 이름 앞 두 글자.
ALTER TABLE systems ADD COLUMN short_name TEXT NULL;
