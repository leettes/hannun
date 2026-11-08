# App Manager 스토어 통합 연동 개발 명세서

-   **대상 스토어:** Apple App Store, Google Play
-   **기술 스택:** PHP 8.3, MySQL 8.0, Linux Cron
-   **문서 목적:** 앱별 스토어 연결, 통계·매출·평점·리뷰 자동 수집 및
    관리자 기능 구현
-   **작성 기준:** 2026-11-08

------------------------------------------------------------------------

## 1. 프로젝트 목표

App Manager에서 관리하는 각 앱에 Apple App Store와 Google Play 계정을
연결하고, 스토어별 데이터를 자동 수집해 하나의 관리 화면에서 조회한다.

### 1.1 관리 대상 데이터

  분류            수집 데이터
  --------------- --------------------------------------------------------
  앱 정보         스토어 앱 ID, 패키지명, 스토어 URL, 앱 버전, 출시 상태
  다운로드·설치   다운로드, 신규 설치, 설치 기반 지표
  이용 통계       활성 사용자 등 스토어가 제공하는 분석 지표
  매출            판매 금액, 환불, 통화, 순매출 산출용 원천 데이터
  평점            스토어 표시 평점, 평점 수, 별점 분포
  리뷰            리뷰 ID, 별점, 제목, 내용, 작성일, 국가, 앱 버전
  리뷰 답변       개발자 답변, 답변 등록일, 답변 상태
  운영            마지막 수집 시각, 수집 성공 여부, 오류 및 재시도 이력

### 1.2 설계 원칙

1.  스토어별 API 차이는 커넥터 내부에 격리한다.
2.  관리자 화면과 내부 API는 공통 데이터 모델을 사용한다.
3.  원본 데이터, 원본 통화, 원본 지표 정의를 보존한다.
4.  통계 기준일과 실제 수집 시각을 분리한다.
5.  API 응답 지연과 보고서 게시 지연을 고려해 재수집·보정 기능을 둔다.
6.  인증 정보와 개인키는 평문으로 저장하지 않는다.
7.  스토어에서 제공하지 않는 지표를 임의로 계산하거나 실측값처럼
    표시하지 않는다.

------------------------------------------------------------------------

## 2. 전체 시스템 구성

``` text
App Manager 관리자 화면
        |
        v
내부 관리자 API (PHP 8.3)
        |
        +-------------------------+
        |                         |
        v                         v
Apple Store Connector      Google Play Connector
        |                         |
        v                         v
App Store Connect API      Android Publisher API
Analytics / Sales Reports  Reviews API
Customer Reviews           Play Console Reports
                                  |
                                  v
                         Google Cloud Storage
        |                         |
        +------------+------------+
                     |
                     v
             수집·정규화 서비스
                     |
                     v
                MySQL 8.0
                     |
          +----------+----------+
          |          |          |
          v          v          v
        통계       리뷰       수집 로그
          |
          v
      관리자 대시보드
```

### 2.1 구성 요소

  구성 요소        역할
  ---------------- ---------------------------------------------------
  PHP 관리자 API   앱·스토어 설정, 통계·리뷰 조회, 수동 동기화
  Apple 커넥터     App Store Connect 인증 및 데이터 수집
  Google 커넥터    Google 인증, Android Publisher API 및 보고서 수집
  수집 엔진        스케줄 실행, 페이지네이션, 중복 제거, 재시도
  정규화 계층      스토어별 응답을 공통 데이터 모델로 변환
  MySQL            스토어 설정, 통계, 리뷰, 버전, 수집 이력 저장
  Cron             정기 수집 작업 실행
  관리자 UI        스토어 연결 상태와 수집 데이터 표시

------------------------------------------------------------------------

## 3. 스토어별 연동 방식

## 3.1 Apple App Store

### 인증 정보

App Store Connect API의 JWT 인증을 사용한다.

  항목           설명
  -------------- -----------------------------------------
  Issuer ID      App Store Connect 발급 Issuer ID
  Key ID         API Key 식별자
  Private Key    `.p8` 개인키
  Apple App ID   숫자형 앱 ID
  권한           필요한 앱 정보·분석·판매·리뷰 접근 권한

개인키는 운영 서버의 비밀 저장소 또는 암호화된 설정에 보관한다. 소스
저장소나 웹 공개 경로에 두지 않는다.

### 수집 기능 및 API 영역

  -----------------------------------------------------------------------
  기능                    API / 데이터 원천       비고
  ----------------------- ----------------------- -----------------------
  앱 기본 정보            App Store Connect Apps  앱 ID, 이름 등

  버전·출시 상태          App Store Versions 및   심사·출시 상태 확인
                          관련 리소스             

  고객 리뷰               Customer Reviews        리뷰 페이지네이션

  개발자 답변             Customer Review         지원 범위와 권한 확인
                          Responses 관련 API      

  다운로드·이용 분석      App Analytics Reports   보고서 종류별 제공 지표
                                                  확인

  판매·매출               Sales and Trends        보고서·통화·정산 기준
                          Reports                 확인

  평점                    리뷰 집계 및 제공       전체 스토어 표시 평점과
                          가능한 공식 보고서      구분
  -----------------------------------------------------------------------

공식 문서: https://developer.apple.com/documentation/appstoreconnectapi

### Apple 데이터 유의사항

-   Customer Reviews API에서 수집한 리뷰의 평균은 스토어 화면의 전체
    누적 평점과 다를 수 있다.
-   Analytics 및 Sales 보고서는 실시간 데이터가 아니다.
-   보고서 종류, 앱, 지역, 권한에 따라 제공 데이터가 다를 수 있다.
-   요청한 보고서가 아직 생성되지 않았거나 데이터가 없는 경우를 정상적인
    대기 상태로 처리한다.
-   Apple의 API 제한과 보고서 가용성을 고려해 요청량을 제어한다.

## 3.2 Google Play

### 인증 정보

Google Cloud 서비스 계정과 OAuth 2.0 인증을 사용한다.

  항목                   설명
  ---------------------- ---------------------------------
  Google Cloud Project   API가 활성화된 프로젝트
  Service Account        서비스 계정 이메일
  JSON Credential        서비스 계정 인증 파일
  Package Name           Android 앱 패키지명
  Play Console 권한      대상 앱의 필요한 조회·답변 권한
  Cloud Storage 접근     보고서 버킷 조회 권한

서비스 계정은 Play Console에서 해당 앱에 필요한 권한을 부여해야 한다.
JSON 키 파일은 웹 루트 밖에 보관하거나 비밀 저장소로 관리한다.

### 수집 기능 및 API 영역

  -----------------------------------------------------------------------
  기능                    API / 데이터 원천       비고
  ----------------------- ----------------------- -----------------------
  앱·출시 정보            Android Publisher API   패키지명, 트랙·릴리스
                                                  등

  리뷰                    Reviews API             리뷰 조회 및 답변

  다운로드·설치           Play Console Statistics Cloud Storage 보고서
                          Reports                 

  평점·분포               Play Console 통계       실제 제공 보고서 확인
                          보고서                  

  매출·환불               Play Console Financial  보고서 종류별 확인
                          Reports                 
  -----------------------------------------------------------------------

공식 문서: - https://developers.google.com/android-publisher -
https://developers.google.com/android-publisher/api-ref/rest/v3/reviews -
https://support.google.com/googleplay/android-developer/answer/6135870

### Google Play 데이터 유의사항

-   Reviews API는 전체 평점 통계 API와 동일하지 않다.
-   다운로드·설치·평점·매출 보고서는 Play Console 보고서 제공 범위에
    따른다.
-   보고서는 Google Cloud Storage에서 다운로드·파싱하는 작업이 필요하다.
-   통계 보고서 게시에는 지연이 발생할 수 있다. 데이터 기준일과 수집일을
    별도 관리한다.
-   보고서 파일 형식, 압축 방식, 컬럼은 실제 제공 파일을 확인한 뒤
    파서를 확정한다.

------------------------------------------------------------------------

## 4. 지표 정의 및 통합 정책

스토어별 지표 이름이 비슷해도 정의가 다를 수 있으므로 내부에서 단순히
같은 숫자로 취급하지 않는다.

  -----------------------------------------------------------------------
  내부 지표               의미                    주의사항
  ----------------------- ----------------------- -----------------------
  downloads               스토어가 정의한         Apple·Google 정의 차이
                          다운로드 수             보존

  installs                설치 관련 지표          사용자 수인지 이벤트
                                                  수인지 출처 확인

  active_users            스토어가 제공하는 활성  기간·집계 정의 보존
                          사용자 지표             

  revenue_gross           원천 보고서의 매출      통화 및 세금·수수료
                                                  기준 확인

  refunds                 환불 금액 또는 건수     금액과 건수를 별도
                                                  필드로 관리

  revenue_net             내부 정책으로 계산한    계산식·환율·기준일 기록
                          순매출                  

  store_rating            스토어가 제공하는 표시  제공되지 않으면 NULL
                          평점                    

  review_average          수집 리뷰를 기준으로    전체 스토어 평점과 구분
                          계산한 평균             

  rating_count            해당 평점 지표의 모집단 지표 출처를 함께 저장
                          수                      

  rating_1\~5             별점별 개수             제공되지 않으면 NULL
  -----------------------------------------------------------------------

### 4.1 NULL과 0 구분

-   `0`: 해당 날짜의 지표가 실제 0으로 확인됨
-   `NULL`: 미제공, 미수집, 보고서 미생성 또는 의미가 확인되지 않음

수집되지 않은 값을 0으로 채우지 않는다.

### 4.2 통화 정책

-   원본 통화와 원본 금액을 반드시 보존한다.
-   서로 다른 통화의 매출을 환산 없이 합산하지 않는다.
-   환산 매출이 필요하면 환율, 환율 기준일, 환산 통화를 별도 저장한다.
-   매출·환불·순매출의 산식을 문서화한다.

### 4.3 데이터 출처

각 통계 레코드에 `source`, `metric_definition`, `collected_at`을
기록한다. 대시보드에서는 값이 어느 보고서/API에서 왔는지 확인할 수
있어야 한다.

------------------------------------------------------------------------

## 5. MySQL 데이터베이스 설계

기존 `apps` 테이블이 있다고 가정한다. 실제 적용 전 기존 테이블의 PK
타입과 컬럼명을 확인하고 FK 타입을 맞춘다.

### 5.1 테이블 목록

  테이블                    목적
  ------------------------- -------------------------------------
  `app_stores`              앱별 Apple·Google 스토어 연결 정보
  `app_store_credentials`   암호화된 인증 정보 또는 Secret 참조
  `app_store_daily_stats`   일별 통계
  `app_store_reviews`       리뷰 및 개발자 답변
  `app_store_versions`      앱 버전·릴리스 상태 이력
  `app_store_sync_logs`     수집 작업 이력
  `app_store_reports`       원본 보고서 수집·처리 이력

### 5.2 `app_stores`

``` sql
CREATE TABLE app_stores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id BIGINT UNSIGNED NOT NULL,
    store_type ENUM('apple', 'google') NOT NULL,
    store_app_id VARCHAR(255) NOT NULL,
    package_name VARCHAR(255) DEFAULT NULL,
    store_url VARCHAR(1000) DEFAULT NULL,
    current_version VARCHAR(50) DEFAULT NULL,
    release_status VARCHAR(50) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_app_store (app_id, store_type),
    KEY idx_store_app_id (store_app_id),
    KEY idx_package_name (package_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.3 `app_store_credentials`

인증 정보 자체를 저장하기보다는 AWS Secrets Manager 등의 Secret 식별자를
저장하는 방식을 우선 권장한다. DB 저장이 불가피한 경우 애플리케이션 외부
키로 암호화한다.

``` sql
CREATE TABLE app_store_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_store_id BIGINT UNSIGNED NOT NULL,
    credential_type VARCHAR(30) NOT NULL,
    secret_ref VARCHAR(500) DEFAULT NULL,
    credential_data_enc MEDIUMTEXT DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    last_verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_store_credential (app_store_id, credential_type),
    CONSTRAINT fk_store_credential
        FOREIGN KEY (app_store_id) REFERENCES app_stores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`credential_data_enc`는 암호문만 저장한다. 암호화 키를 같은 DB에
저장하지 않는다. 로그·예외 메시지·관리자 응답에 토큰이나 개인키가
노출되지 않도록 한다.

### 5.4 `app_store_daily_stats`

``` sql
CREATE TABLE app_store_daily_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_store_id BIGINT UNSIGNED NOT NULL,
    stat_date DATE NOT NULL,

    downloads BIGINT UNSIGNED DEFAULT NULL,
    installs BIGINT UNSIGNED DEFAULT NULL,
    active_users BIGINT UNSIGNED DEFAULT NULL,

    revenue_gross DECIMAL(20,4) DEFAULT NULL,
    refunds_amount DECIMAL(20,4) DEFAULT NULL,
    currency CHAR(3) DEFAULT NULL,

    store_rating DECIMAL(3,2) DEFAULT NULL,
    store_rating_count BIGINT UNSIGNED DEFAULT NULL,

    review_average DECIMAL(3,2) DEFAULT NULL,
    review_count BIGINT UNSIGNED DEFAULT NULL,

    rating_1 BIGINT UNSIGNED DEFAULT NULL,
    rating_2 BIGINT UNSIGNED DEFAULT NULL,
    rating_3 BIGINT UNSIGNED DEFAULT NULL,
    rating_4 BIGINT UNSIGNED DEFAULT NULL,
    rating_5 BIGINT UNSIGNED DEFAULT NULL,

    source VARCHAR(100) NOT NULL,
    metric_definition VARCHAR(255) DEFAULT NULL,
    collected_at DATETIME NOT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_store_date_source (
        app_store_id, stat_date, source
    ),
    KEY idx_stat_date (stat_date),
    CONSTRAINT fk_daily_stats_store
        FOREIGN KEY (app_store_id) REFERENCES app_stores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

주의: 하나의 날짜에 동일한 `source`에서 여러 종류의 보고서가 들어온다면
`source`에 보고서 종류를 포함하거나 `report_type` 컬럼을 추가해 유일키를
확장한다. 보고서 원본의 집계 단위(국가, 기기, 상품 등)가 다르면 별도
차원 컬럼 또는 원천 테이블을 설계한다.

### 5.5 `app_store_reviews`

``` sql
CREATE TABLE app_store_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_store_id BIGINT UNSIGNED NOT NULL,
    external_review_id VARCHAR(255) NOT NULL,

    rating TINYINT UNSIGNED NOT NULL,
    title VARCHAR(500) DEFAULT NULL,
    content TEXT DEFAULT NULL,
    reviewer_name VARCHAR(255) DEFAULT NULL,

    territory VARCHAR(10) DEFAULT NULL,
    language VARCHAR(20) DEFAULT NULL,
    app_version VARCHAR(50) DEFAULT NULL,

    review_created_at DATETIME DEFAULT NULL,
    review_updated_at DATETIME DEFAULT NULL,

    developer_reply TEXT DEFAULT NULL,
    developer_reply_at DATETIME DEFAULT NULL,

    raw_data JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_external_review (
        app_store_id, external_review_id
    ),
    KEY idx_review_date (app_store_id, review_created_at),
    KEY idx_review_rating (app_store_id, rating),
    CONSTRAINT fk_reviews_store
        FOREIGN KEY (app_store_id) REFERENCES app_stores(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

리뷰 원문과 작성자 정보는 개인정보·스토어 정책을 고려해 필요한 범위만
보관하고 보존 기간을 정한다. `raw_data`에는 토큰이나 인증 정보가
포함되지 않도록 한다.

### 5.6 `app_store_versions`

``` sql
CREATE TABLE app_store_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_store_id BIGINT UNSIGNED NOT NULL,
    version VARCHAR(50) NOT NULL,
    build_number VARCHAR(50) DEFAULT NULL,
    release_status VARCHAR(50) DEFAULT NULL,
    release_date DATETIME DEFAULT NULL,
    platform_track VARCHAR(100) DEFAULT NULL,
    release_notes TEXT DEFAULT NULL,
    source VARCHAR(50) NOT NULL,
    observed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_store_version (
        app_store_id, version, build_number, platform_track
    ),
    KEY idx_version_observed (app_store_id, observed_at),
    CONSTRAINT fk_versions_store
        FOREIGN KEY (app_store_id) REFERENCES app_stores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.7 `app_store_sync_logs`

``` sql
CREATE TABLE app_store_sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_store_id BIGINT UNSIGNED NOT NULL,
    sync_type VARCHAR(50) NOT NULL,
    sync_status ENUM('running', 'success', 'failed', 'partial')
        NOT NULL,
    records_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL,
    finished_at DATETIME DEFAULT NULL,
    error_code VARCHAR(100) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_store_sync (
        app_store_id, sync_type, started_at
    ),
    CONSTRAINT fk_sync_logs_store
        FOREIGN KEY (app_store_id) REFERENCES app_stores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

오류 메시지는 민감정보를 제거한 뒤 저장한다. 원본 응답 전체를 로그에
남기지 않는다.

### 5.8 `app_store_reports`

보고서 다운로드와 파싱 상태를 추적한다.

``` sql
CREATE TABLE app_store_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_store_id BIGINT UNSIGNED NOT NULL,
    report_type VARCHAR(100) NOT NULL,
    report_date DATE DEFAULT NULL,
    source_file VARCHAR(1000) DEFAULT NULL,
    file_checksum CHAR(64) DEFAULT NULL,
    processing_status ENUM(
        'pending', 'downloaded', 'processed', 'failed'
    ) NOT NULL DEFAULT 'pending',
    records_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    downloaded_at DATETIME DEFAULT NULL,
    processed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_report_file (
        app_store_id, report_type, file_checksum
    ),
    KEY idx_report_status (
        app_store_id, processing_status, report_date
    ),
    CONSTRAINT fk_reports_store
        FOREIGN KEY (app_store_id) REFERENCES app_stores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

보고서 파일을 로컬에 보관한다면 접근 제한된 디렉터리나 S3를 사용하고
보존·삭제 정책을 정한다.

------------------------------------------------------------------------

## 6. PHP 프로젝트 디렉터리 구조

기존 PHP 프로젝트의 구조에 맞춰 네임스페이스와 파일 위치를 조정한다.

``` text
app/
├── Controllers/
│   └── Admin/
│       └── AppStoreController.php
├── Services/
│   └── AppStore/
│       ├── AppleStoreService.php
│       ├── GooglePlayService.php
│       ├── StoreSyncService.php
│       ├── StoreReviewService.php
│       ├── StoreReportService.php
│       └── StoreMetricsNormalizer.php
├── Libraries/
│   └── AppStore/
│       ├── HttpClient.php
│       ├── AppleJwt.php
│       ├── GoogleAuth.php
│       └── ReportParser.php
├── Repositories/
│   ├── AppStoreRepository.php
│   ├── AppStoreStatsRepository.php
│   ├── AppStoreReviewRepository.php
│   └── AppStoreSyncLogRepository.php
└── Commands/
    ├── SyncStoreReviews.php
    ├── SyncStoreStats.php
    ├── SyncStoreRevenue.php
    ├── SyncStoreVersions.php
    └── ReconcileStoreReports.php

cron/
└── app_store_sync.php
```

### 6.1 권장 라이브러리

  기능           구현
  -------------- ------------------------------------------------
  HTTP 통신      PHP cURL
  Apple JWT      OpenSSL 및 JWT 라이브러리
  Google OAuth   `google/apiclient` 또는 Google Auth 라이브러리
  DB             PDO MySQL
  CSV 파싱       `SplFileObject`
  압축 해제      PHP Zip 확장 또는 지원 압축 도구
  설정           환경변수 또는 Secret Manager

Composer 패키지는 PHP 8.3과 프로젝트의 기존 의존성에 맞춰 버전을
고정한다.

------------------------------------------------------------------------

## 7. 내부 관리자 API 명세

모든 API는 관리자 인증과 권한 검사를 적용한다. 앱 ID와 스토어 ID의 소유
관계도 매 요청마다 검증한다.

기본 Prefix:

``` text
/api/admin/apps/{app_id}/stores
```

### 7.1 스토어 관리

  Method   Endpoint                기능
  -------- ----------------------- --------------------
  GET      `/`                     연결된 스토어 목록
  POST     `/`                     스토어 등록
  GET      `/{store_id}`           스토어 상세
  PUT      `/{store_id}`           스토어 정보 수정
  DELETE   `/{store_id}`           연결 해제
  POST     `/{store_id}/connect`   인증 정보 등록
  POST     `/{store_id}/test`      연결 테스트

스토어 등록 예시:

``` json
{
  "store_type": "google",
  "store_app_id": "com.example.app",
  "package_name": "com.example.app",
  "store_url": "https://play.google.com/store/apps/details?id=com.example.app"
}
```

Apple 등록 시 `store_app_id`에는 숫자형 Apple 앱 ID를 저장한다. Google은
패키지명을 사용한다. 두 식별자를 혼동하지 않도록 한다.

### 7.2 통계 API

  Method   Endpoint                      기능
  -------- ----------------------------- ----------------
  GET      `/{store_id}/stats`           일별 통계
  GET      `/{store_id}/stats/summary`   기간 요약
  GET      `/{store_id}/stats/ratings`   평점 및 분포
  POST     `/{store_id}/sync/stats`      통계 수집 요청
  POST     `/{store_id}/sync/revenue`    매출 수집 요청

조회 예시:

``` http
GET /api/admin/apps/1/stores/10/stats
    ?from=2026-10-01
    &to=2026-11-08
    &interval=daily
```

응답 예시:

``` json
{
  "success": true,
  "data": {
    "store": "google",
    "period": {
      "from": "2026-10-01",
      "to": "2026-11-08"
    },
    "summary": {
      "downloads": 125320,
      "installs": 118420,
      "average_rating": 4.6,
      "rating_count": 12431,
      "revenue": 12500000,
      "currency": "KRW"
    },
    "daily": []
  }
}
```

실제 응답에서는 `average_rating`의 출처와 모집단, 통계 기준일, 마지막
수집 시각을 함께 반환한다. 예시 수치는 실제 스토어 데이터가 아니다.

### 7.3 리뷰 API

  Method   Endpoint                                  기능
  -------- ----------------------------------------- ----------------
  GET      `/{store_id}/reviews`                     리뷰 목록
  GET      `/{store_id}/reviews/{review_id}`         리뷰 상세
  POST     `/{store_id}/reviews/{review_id}/reply`   답변 등록
  POST     `/{store_id}/sync/reviews`                리뷰 수집 요청

검색 예시:

``` http
GET /api/admin/apps/1/stores/10/reviews
    ?rating=1
    &from=2026-10-01
    &to=2026-11-08
    &page=1
    &limit=20
```

답변 등록 예시:

``` json
{
  "text": "이용에 불편을 드려 죄송합니다. 문제를 확인하겠습니다."
}
```

답변 게시 전 관리자 확인을 받고, 게시 성공 여부와 외부 응답 ID를
기록한다.

### 7.4 수집 관리 API

  Method   Endpoint                       기능
  -------- ------------------------------ -----------------------
  POST     `/{store_id}/sync`             수집 작업 요청
  GET      `/{store_id}/sync-status`      최근 수집 상태
  GET      `/{store_id}/sync-logs`        수집 이력
  POST     `/{store_id}/sync/reconcile`   누락 데이터 보정 요청

수집 API는 작업을 큐 또는 작업 테이블에 등록하고 빠르게 응답한다. HTTP
요청에서 장시간 외부 보고서를 직접 수집하지 않는다.

------------------------------------------------------------------------

## 8. 자동 수집 스케줄

아래 시간은 초기 운영안이다. 서버 시간대, 스토어 보고서 게시 시점, API
제한을 확인한 뒤 조정한다.

  작업           초기 주기   내용
  -------------- ----------- -------------------------
  리뷰 수집      3시간마다   신규·수정 리뷰
  앱 버전 확인   매일 1회    버전·출시 상태
  통계 수집      매일 1회    다운로드·설치·이용 지표
  매출 보고서    매일 1회    판매·환불 보고서
  데이터 보정    매주 1회    지연·누락 보고서 재확인
  연결 확인      매일 1회    인증 및 권한 확인

### 8.1 Cron 예시

``` cron
# 리뷰 수집
0 */3 * * * php /var/www/app/cron/app_store_sync.php reviews

# 앱 버전 확인
10 2 * * * php /var/www/app/cron/app_store_sync.php versions

# 통계 수집
30 4 * * * php /var/www/app/cron/app_store_sync.php stats

# 매출 수집
0 6 * * * php /var/www/app/cron/app_store_sync.php revenue

# 누락 데이터 보정
0 5 * * 1 php /var/www/app/cron/app_store_sync.php reconcile
```

Cron 실행 시 다음 항목을 구현한다.

-   작업별 중복 실행 Lock
-   스토어별 작업 단위 분리
-   페이지네이션과 다음 페이지 처리
-   API 요청 제한 및 지수형 재시도
-   일시 오류와 영구 오류 구분
-   부분 성공 상태 기록
-   작업 시간 제한
-   실패 알림 및 수동 재실행
-   재수집 기간을 설정할 수 있는 보정 기능

### 8.2 재시도 정책

  오류                처리
  ------------------- ---------------------------------------
  HTTP 429            Retry-After 확인 후 대기
  HTTP 5xx            지수형 재시도
  네트워크 타임아웃   제한 횟수 재시도
  인증 오류           자동 반복을 중단하고 관리자 알림
  권한 오류           권한 설정 확인 요청
  데이터 없음         정상적인 빈 결과로 기록
  보고서 미생성       대기 상태로 두고 다음 실행에서 재확인
  CSV 파싱 오류       원본 파일·오류를 기록하고 재처리

------------------------------------------------------------------------

## 9. PHP 수집 서비스 구현 예시

아래는 전체 구조를 설명하기 위한 골격 코드다. 실제 API 경로, 요청
파라미터, 페이지네이션, 응답 변환은 각 공식 API 스키마에 맞춰 구현한다.

``` php
<?php

interface StoreServiceInterface
{
    public function syncReviews(): array;
    public function syncStats(): array;
    public function syncRevenue(): array;
    public function syncVersions(): array;
}
```

``` php
<?php

final class StoreSyncService
{
    public function __construct(
        private AppStoreRepository $stores,
        private SyncLogRepository $logs
    ) {}

    public function sync(int $storeId, string $type): array
    {
        $store = $this->stores->find($storeId);

        if (!$store || !$store['is_active']) {
            throw new RuntimeException('Invalid store');
        }

        $logId = $this->logs->start($storeId, $type);

        try {
            $service = match ($store['store_type']) {
                'apple' => new AppleStoreService($store),
                'google' => new GooglePlayService($store),
                default => throw new RuntimeException(
                    'Unsupported store'
                ),
            };

            $result = match ($type) {
                'reviews' => $service->syncReviews(),
                'stats' => $service->syncStats(),
                'revenue' => $service->syncRevenue(),
                'versions' => $service->syncVersions(),
                default => throw new RuntimeException(
                    'Unsupported sync type'
                ),
            };

            $this->logs->success(
                $logId,
                $result['count'] ?? 0
            );

            return $result;

        } catch (Throwable $e) {
            $this->logs->fail(
                $logId,
                get_class($e),
                sanitizeError($e->getMessage())
            );

            throw $e;
        }
    }
}
```

### 9.1 데이터 저장 원칙

-   통계는 `INSERT ... ON DUPLICATE KEY UPDATE`로 멱등성을 보장한다.
-   리뷰는 외부 리뷰 ID를 기준으로 upsert한다.
-   보고서 파일은 checksum으로 중복 처리를 방지한다.
-   보고서 파싱과 DB 저장은 트랜잭션을 사용한다.
-   대량 데이터는 적절한 단위로 나눠 저장한다.
-   외부 API 호출과 DB 트랜잭션을 장시간 하나로 묶지 않는다.

------------------------------------------------------------------------

## 10. 관리자 화면 명세

앱 상세 화면에 `스토어 정보` 탭을 추가한다.

### 10.1 스토어 연결 카드

각 스토어 카드에 다음 항목을 표시한다.

  항목          설명
  ------------- -----------------------------------
  스토어        Apple App Store / Google Play
  연결 상태     정상 / 인증 오류 / 미연결
  앱 ID         Apple 앱 ID 또는 Android 패키지명
  스토어 URL    실제 스토어 상세 페이지
  현재 버전     최신 확인 버전
  출시 상태     출시·심사·검토 등
  마지막 수집   성공한 마지막 수집 시각
  작업          연결 테스트, 동기화, 설정 수정

### 10.2 통합 통계

-   기간 선택: 7일, 30일, 90일, 직접 지정
-   스토어별 다운로드 비교
-   스토어별 설치 지표 비교
-   일별 매출 및 환불
-   평점 추이
-   별점 분포
-   지표별 데이터 출처 및 마지막 수집일

서로 정의가 다른 지표는 무조건 합산하지 않는다. 통합 합계를 표시할 때는
합산 가능 여부와 단위를 확인한다.

### 10.3 리뷰 관리

-   스토어 필터
-   별점 필터
-   국가·언어 필터
-   기간 검색
-   미답변 리뷰
-   최신순 정렬
-   리뷰 상세
-   답변 작성 및 게시
-   답변 게시 이력

### 10.4 수집 상태

-   마지막 성공 시각
-   마지막 실패 시각
-   수집 대상 기간
-   처리 레코드 수
-   오류 코드
-   재시도 횟수
-   수동 재수집 버튼

------------------------------------------------------------------------

## 11. 보안 및 운영 정책

### 11.1 인증 정보

-   Apple `.p8`, Google Service Account JSON은 Git에 커밋하지 않는다.
-   AWS Secrets Manager 또는 동등한 Secret 저장소를 우선 사용한다.
-   DB에 저장해야 한다면 AES-256-GCM 등 인증된 암호화를 사용한다.
-   암호화 키는 DB와 분리한다.
-   관리자 화면에서는 Secret 원문을 다시 표시하지 않는다.
-   키 교체·폐기 이력을 관리한다.

### 11.2 접근 제어

-   스토어 연결·인증 변경은 관리자 권한으로 제한한다.
-   리뷰 답변 게시 권한을 별도로 제어한다.
-   앱 ID와 스토어 ID의 관계를 서버에서 검증한다.
-   모든 변경 작업에 감사 로그를 남긴다.
-   CSRF 방어, 입력 검증, SQL 파라미터 바인딩을 적용한다.

### 11.3 개인정보 및 보존

-   리뷰 작성자 정보는 필요한 범위만 보관한다.
-   원문·응답 보존 기간과 삭제 정책을 정한다.
-   로그에 인증 토큰, 개인키, 불필요한 개인정보를 남기지 않는다.
-   백업 파일과 보고서 원본의 접근 권한을 제한한다.

------------------------------------------------------------------------

## 12. 개발 단계 및 완료 기준

### 1차: 스토어 연결

-   [ ] `app_stores` 및 인증 정보 테이블 생성
-   [ ] Apple API Key 등록 및 연결 테스트
-   [ ] Google Service Account 등록 및 연결 테스트
-   [ ] 앱 ID·패키지명 검증
-   [ ] 인증 오류 메시지와 재설정 기능

**완료 기준:** 두 스토어의 인증 상태를 관리자 화면에서 확인할 수 있다.

### 2차: 리뷰 수집

-   [ ] Apple 리뷰 조회
-   [ ] Google 리뷰 조회
-   [ ] 페이지네이션
-   [ ] 리뷰 upsert
-   [ ] 별점·국가·언어 필터
-   [ ] 개발자 답변 게시
-   [ ] 답변 이력 및 오류 처리

**완료 기준:** 두 스토어의 리뷰를 한 화면에서 조회하고 권한이 있는
관리자가 답변할 수 있다.

### 3차: 통계 수집

-   [ ] Apple Analytics 보고서 요청·조회
-   [ ] Apple Sales 보고서 수집
-   [ ] Google 통계 보고서 다운로드
-   [ ] Google 재무 보고서 다운로드
-   [ ] CSV 파싱
-   [ ] 지표 정규화
-   [ ] 일별 통계 저장
-   [ ] 통화 및 지표 정의 보존

**완료 기준:** 실제 보고서에 포함된 지표를 기준으로 일별 통계를 조회할
수 있다.

### 4차: 대시보드 및 자동화

-   [ ] 통합 통계 카드
-   [ ] 기간별 그래프
-   [ ] 스토어별 비교
-   [ ] 평점 및 리뷰 분석
-   [ ] Cron 자동 수집
-   [ ] 실패 알림
-   [ ] 수동 재수집
-   [ ] 누락 데이터 보정

**완료 기준:** 수집 작업이 자동으로 실행되고, 실패·지연·누락을 관리자
화면에서 확인할 수 있다.

------------------------------------------------------------------------

## 13. 테스트 체크리스트

  테스트               기대 결과
  -------------------- -----------------------------------------
  정상 Apple 인증      연결 성공
  정상 Google 인증     연결 성공
  잘못된 개인키        인증 실패 및 민감정보 미노출
  권한 없는 앱 조회    권한 오류
  리뷰 페이지네이션    누락 없이 전체 페이지 처리
  동일 리뷰 재수집     중복 레코드 없음
  수정된 리뷰 재수집   기존 레코드 갱신
  빈 보고서            0이 아닌 데이터 없음/미제공 상태로 처리
  보고서 지연          대기 상태 기록
  CSV 재처리           checksum으로 중복 방지
  API 429              요청 제한 준수 후 재시도
  API 5xx              제한된 재시도
  일부 수집 실패       성공 데이터 보존, 부분 실패 표시
  매출 통화 상이       통화별 분리
  날짜 경계            기준일과 수집일 일치
  Cron 중복 실행       Lock으로 중복 방지

------------------------------------------------------------------------

## 14. 공식 문서

### Apple

-   App Store Connect API:
    https://developer.apple.com/documentation/appstoreconnectapi
-   App Analytics:
    https://developer.apple.com/help/app-store-connect/view-app-analytics/
-   Sales and Trends:
    https://developer.apple.com/help/app-store-connect/view-sales-and-trends/
-   Customer Reviews API:
    https://developer.apple.com/documentation/appstoreconnectapi/customer_reviews

### Google

-   Google Play Developer API:
    https://developers.google.com/android-publisher
-   Reviews API:
    https://developers.google.com/android-publisher/api-ref/rest/v3/reviews
-   Google Play 통계 보고서:
    https://support.google.com/googleplay/android-developer/answer/6135870
-   Google Play Console: https://play.google.com/console/

------------------------------------------------------------------------

## 15. 구현 전 확인해야 할 사항

실제 개발을 시작하기 전에 아래 항목을 확인한다.

1.  기존 `apps` 테이블의 PK 타입과 컬럼 구조
2.  Apple App Store Connect API Key 발급 및 권한
3.  Apple 앱별 App ID
4.  Google Cloud Project 및 Android Publisher API 활성화
5.  Google 서비스 계정과 Play Console 권한
6.  Google Cloud Storage 보고서 접근 권한
7.  EC2에서 Secret Manager 접근 권한
8.  기존 PHP 프로젝트의 프레임워크·라우팅·DB 접근 방식
9.  실제 앱별 스토어 등록 여부와 앱 식별자
10. 스토어 보고서에서 제공되는 지표 및 데이터 지연 확인

**중요:** 이 문서는 구현 설계 및 개발 명세다. 실제 API 응답 필드, 보고서
컬럼, 앱별 권한은 연결 후 검증해야 한다. 특히 스토어 전체 평점과
매출·다운로드 지표는 스토어가 공식적으로 제공하는 범위 안에서만
표시한다.
