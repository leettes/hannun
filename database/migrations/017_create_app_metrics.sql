-- 앱 스토어 통계·리뷰 (앱 상세 화면). docs/APPS.md 5단계, docs/app-manager-store-integration-spec.md 5.4·5.5
-- 지금은 테이블과 화면만 있다. 수집기(App Store Connect / Google Play)는 다음 단계에서 붙인다.
-- 실행 데이터라 database/config.sql 에는 넣지 않는다.
--
-- 명세서와 다른 점 (SQLite·한눈 구조에 맞춤)
--   - 명세서의 app_stores(앱 × 스토어) 한 행 = 한눈의 apps 한 행 (iOS / Android 가 각각 한 행). 그래서 app_store_id 대신 app_id(apps.id)
--   - 앱은 삭제하지 않고 비활성화하므로 ON DELETE CASCADE 를 두지 않는다
--   - 시각은 다른 테이블처럼 KST 'Y-m-d H:i:s' 텍스트
--   - app_stores·credentials·versions·sync_logs·reports 는 수집기를 붙일 때 추가한다

-- 일별 통계. 스토어 리포트는 1~2일 늦게 나오고 나중에 고쳐지기도 하므로 (app_id, stat_date, source) 기준으로 덮어쓴다.
-- NULL = 미제공·미수집, 0 = 실제로 0 (명세서 4.1). 수집하지 않은 값을 0 으로 채우지 않는다.
CREATE TABLE app_store_daily_stats (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id              INTEGER NOT NULL REFERENCES apps (id),
    stat_date           TEXT NOT NULL,                -- Y-m-d (스토어 리포트 기준일)

    downloads           INTEGER NULL,                 -- 스토어가 정의한 다운로드 (Apple: First-time downloads)
    installs            INTEGER NULL,                 -- 설치 지표 (Google: Install events 등. 정의는 metric_definition)
    active_users        INTEGER NULL,                 -- 스토어가 주는 활성 사용자·기기

    revenue_gross       REAL NULL,                    -- 원천 보고서 매출 (원본 통화)
    refunds_amount      REAL NULL,
    currency            TEXT NULL,                    -- ISO 4217. 통화가 다르면 합산하지 않는다

    store_rating        REAL NULL,                    -- 스토어 표시 평점 (누적)
    store_rating_count  INTEGER NULL,
    review_average      REAL NULL,                    -- 수집한 리뷰 기준 평균 (store_rating 과 구분)
    review_count        INTEGER NULL,
    rating_1            INTEGER NULL,
    rating_2            INTEGER NULL,
    rating_3            INTEGER NULL,
    rating_4            INTEGER NULL,
    rating_5            INTEGER NULL,

    source              TEXT NOT NULL,                -- asc_sales_report, play_stats_installs, itunes_lookup, manual 등
    metric_definition   TEXT NULL,                    -- 지표 정의 메모 (예: 'Daily User Installs')
    collected_at        TEXT NOT NULL,                -- 실제 수집 시각
    created_at          TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    UNIQUE (app_id, stat_date, source)
);
CREATE INDEX app_store_daily_stats_date ON app_store_daily_stats (stat_date);

-- 스토어 리뷰. 스토어 리뷰 ID 로 중복 없이 쌓고, 수정된 리뷰·답변은 덮어쓴다.
CREATE TABLE app_store_reviews (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    app_id              INTEGER NOT NULL REFERENCES apps (id),
    external_review_id  TEXT NOT NULL,                -- Apple customerReviews id / Google reviewId

    rating              INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    title               TEXT NULL,                    -- Apple 만 있음
    content             TEXT NULL,
    reviewer_name       TEXT NULL,                    -- 스토어에 보이는 닉네임

    territory           TEXT NULL,                    -- 국가 (KOR 등)
    language            TEXT NULL,
    app_version         TEXT NULL,

    review_created_at   TEXT NULL,
    review_updated_at   TEXT NULL,

    developer_reply     TEXT NULL,
    developer_reply_at  TEXT NULL,

    raw_data            TEXT NULL CHECK (raw_data IS NULL OR json_valid(raw_data)),   -- 토큰·인증 정보는 넣지 않는다
    created_at          TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    updated_at          TEXT NOT NULL DEFAULT (datetime('now', '+9 hours')),
    UNIQUE (app_id, external_review_id)
);
CREATE INDEX app_store_reviews_date ON app_store_reviews (app_id, review_created_at);
CREATE INDEX app_store_reviews_rating ON app_store_reviews (app_id, rating);
