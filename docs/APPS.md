# 서비스별 앱 관리 — 기획안

서비스마다 운영하는 앱(iOS / Android / 웹앱)과 앱을 올리는 개발자 계정(Apple Developer, Google Play Console)을 한곳에서 관리한다.
지금 있는 **AWS 계정 → 관리회사** 구조와 같은 방식으로 만든다.

> 상태: **A안으로 1·2단계 완료** (2026-11-06). 3단계(만료 알림)부터 남음.
> 앱 상세 화면과 다운로드·리뷰 테이블(5단계 설계), iOS 리뷰 수집은 2026-11-08 추가. 다운로드·Android 리뷰 수집기는 아직 없음. 수집기 상세 설계는 `docs/app-manager-store-integration-spec.md`.

## 1. 결정할 것

- [x] **"앱"의 범위**
  - **A. 서비스가 내놓은 앱 (미리 iOS 앱, 코이 안드로이드 앱 등) ← 결정 (2026-11-06)**
  - B. 서비스가 쓰는 외부 앱·SaaS (Firebase, 알리고, PG사, Sentry 등): 계정, 요금제, 결제 회사, 갱신일
  - A + B 둘 다 (B 는 별도 "외부 서비스" 화면으로)
- [x] 개발자 계정까지 관리할지, 앱 목록만 둘지 → 개발자 계정도 관리 (1단계에 포함)
- [ ] 대시보드 서비스 카드에 앱 버전 줄을 넣을지
- [ ] 웹앱(PWA 등)을 앱으로 따로 관리할지, 지금처럼 웹/API 리소스로 충분한지
- [ ] Apple 멤버십 만료 알림을 받을 사람 (관리자 전체 / 서비스 담당자)

## 2. 현황 조사 (구현 전)

- [ ] 서비스별 앱 목록: 앱 이름, 플랫폼, 번들 ID 또는 패키지명, 스토어 주소
- [ ] 개발자 계정 목록: Apple / Google, 계정 이름, 팀 ID, 로그인 이메일, 소유 법인(관리회사)
- [ ] Apple Developer 멤버십 갱신일 (계정마다 연 1회)
- [ ] 계정 하나를 여러 서비스가 같이 쓰는지 확인
- [ ] 계정 비밀번호·2단계 인증 기기를 누가 어디에 보관하는지 (화면에는 보관 위치만 메모)

## 3. 데이터 구조

```
관리회사 ─ 1:N ─ 개발자 계정 (Apple Developer / Google Play Console)
                       │ 1:N
서비스   ─ 1:N ─ 앱 (iOS / Android / 웹앱)
```

### `app_store_accounts` (개발자 계정)

| 컬럼 | 내용 |
|---|---|
| `platform` | `apple` / `google` |
| `name` | 계정 이름 (예: 미리 Apple) |
| `team_id` | Apple 팀 ID (Google 은 비움) |
| `login_email` | 로그인 이메일 |
| `company_id` | 관리회사 (`companies`) |
| `membership_expires_at` | 멤버십 만료일 (Apple. Google 은 일회성 등록이라 비움) |
| `notes` | 메모 (비밀번호 보관 위치, 결제 카드 등) |

- 비밀번호, API 키, 인증서 파일은 저장하지 않는다 (AWS 계정과 같은 방침)
- 앱이 참조하므로 삭제하지 않는다

### `apps` (앱)

| 컬럼 | 내용 |
|---|---|
| `system_id` | 서비스 |
| `platform` | `ios` / `android` / `web` |
| `name` | 앱 이름 |
| `bundle_id` | 번들 ID 또는 패키지명 (플랫폼 안에서 중복 불가) |
| `store_url` | 스토어 주소 (iOS 는 비우면 자동 조회) |
| `account_id` | 개발자 계정 (`app_store_accounts`) |
| `version`, `released_at` | 현재 버전, 최근 업데이트일 (iOS 는 자동, Android 는 직접 입력) |
| `store_info`, `checked_at` | 자동 조회 결과 원본(JSON), 조회 시각 |
| `notes`, `is_active` | 메모, 사용 여부 (삭제 대신 비활성화) |

### 관리회사 판단

- 앱의 관리회사는 개발자 계정의 관리회사를 쓴다
- 개발자 계정에 관리회사가 없으면 서비스의 관리회사를 쓴다 (서비스 직접 지정, 없으면 AWS 계정의 관리회사)

### 1단계 구현 메모

- 플랫폼(앱 iOS/Android/웹앱, 계정 Apple/Google)은 등록 후 바꾸지 않는다
- 웹앱은 번들 ID·개발자 계정을 받지 않는다. 스토어 주소 칸이 앱 주소
- 팀 ID·멤버십 만료일은 Apple 계정만
- 사용 중인 앱이 있는 개발자 계정은 비활성화할 수 없다 (앱의 계정을 먼저 바꾼다)
- 새 앱에는 비활성 서비스·계정을 고를 수 없다. 이미 연결된 것은 그대로 저장할 수 있다
- 코드: `src/Monitoring/AppRegistry.php`, `src/Controller/AppController.php`, `src/Controller/AppAccountController.php`, `database/migrations/014_create_apps.sql`

## 4. 화면 시안

### ① 서비스 상세 (`/systems/{code}`): "앱" 섹션 추가

```
앱                                                  iOS 버전은 하루 한 번 자동 확인
┌──────────┬─────────┬──────────────────┬────────┬────────────┬──────────────────────┐
│ 이름      │ 플랫폼   │ 번들 ID / 패키지   │ 버전    │ 업데이트     │ 개발자 계정             │
├──────────┼─────────┼──────────────────┼────────┼────────────┼──────────────────────┤
│ 미리      │ iOS     │ kr.miri.app      │ 2.4.1  │ 2026-10-28 │ 미리 Apple (주)예시    │
│ 미리      │ Android │ kr.miri.app      │ 2.4.0  │ 2026-10-21 │ 미리 Google (주)예시   │
└──────────┴─────────┴──────────────────┴────────┴────────────┴──────────────────────┘
```

### ② 설정 > 앱 (`/settings/apps`, 관리자 전용)

```
앱                                                            [앱 등록]
[전체 12] [iOS 5] [Android 5] [웹앱 2]

서비스   이름      플랫폼    번들 ID          버전    개발자 계정        관리회사
미리     미리      iOS      kr.miri.app     2.4.1  미리 Apple        (주)예시
미리     미리      Android  kr.miri.app     2.4.0  미리 Google       (주)예시
코이     코이      iOS      com.koi.ios     1.9.0  공용 Apple   ⚠    (주)다른회사
```

### ③ 설정 > 개발자 계정 (`/settings/app-accounts`, 관리자 전용)

```
개발자 계정                                                     [계정 등록]

이름          플랫폼    팀 ID        관리회사      멤버십 만료         앱
미리 Apple    Apple    AB12CD34EF  (주)예시      2027-03-02 (116일)  미리
공용 Apple    Apple    ZX98YW76VU  (주)다른회사   2026-11-20 (14일) ⚠ 코이, sys-c
미리 Google   Google   -           (주)예시      - (만료 없음)        미리
```

- 만료가 30일 안으로 다가오면 경고색 (도메인 만료와 같은 기준)

### ④ 앱 등록 폼

```
서비스        [미리 ▼]
플랫폼        (●) iOS  ( ) Android  ( ) 웹앱
앱 이름       [미리            ]
번들 ID       [kr.miri.app     ]   ← iOS 는 이 값으로 스토어 정보 자동 조회
스토어 주소    [https://apps.apple.com/...]  (비우면 자동)
개발자 계정    [미리 Apple ▼]   계정 등록
메모          [                ]
```

### ⑤ 대시보드 서비스 카드 (선택)

```
┌ 미리 ──────────── 정상 ┐
│ ● API        120ms   │
│ 📱 iOS 2.4.1 · And 2.4.0 │   ← 앱 버전 한 줄
└──────────────────────┘
```

## 5. 작업 단계

### 1단계 · 기본 관리

- [x] 마이그레이션: `app_store_accounts`, `apps`
- [x] 설정 > 개발자 계정: 목록, 등록, 수정, 관리회사 지정
- [x] 설정 > 앱: 목록(플랫폼 탭), 등록, 수정, 비활성화
- [x] 서비스 상세에 "앱" 섹션
- [x] 관리회사 목록에 개발자 계정 표시 (앱은 개발자 계정 목록과 앱 목록의 관리회사 열에서 확인)
- [x] 설정 메뉴에 "앱", "개발자 계정" 추가
- [x] 테스트: 권한(관리자 전용), 입력 검증(번들 ID 형식·중복), 관리회사 판단
- [x] 문서: `README.md` 화면 표, `docs/DB.md` 테이블

### 2단계 · iOS 자동 조회

- [x] cron 에서 하루 한 번 Apple 공개 조회 API(`https://itunes.apple.com/lookup?id=...&country=kr`, 스토어 주소에 id 가 없으면 `bundleId=`, 무료, 키 불필요)로 버전·업데이트일 갱신 (`src/Monitoring/AppStoreLookup.php`)
- [x] 실패하면 이전 값을 두고 오류만 표시 (도메인 정보 조회와 같은 방식). 1시간 뒤 재시도, 목록에 ⚠
- [x] 번들 ID·스토어 주소가 비어 있으면 조회 결과로 채움 (다른 iOS 앱이 쓰는 번들 ID 면 채우지 않음). iOS 는 App Store 주소만으로 등록 가능
- [x] iOS 버전·업데이트일은 자동 값만 사용 (폼에서는 읽기 전용). 스토어 주소·번들 ID 를 바꾸면 다음 cron 에서 다시 조회
- [ ] Google Play 는 공식 공개 API 가 없어 직접 입력 (스크래핑은 쉽게 깨져서 하지 않음)

### 추가 구현 (2026-11-06)

- [x] 앱 목록 관리회사 필터 (플랫폼 탭과 함께 유지)
- [x] 스토어 상세 이동 버튼 (App Store / Google Play / 웹앱 열기)
- [x] 앱 아이콘 자동: cron 이 iOS 는 App Store 조회 API, Android 는 Play 상세 페이지, 웹앱은 apple-touch-icon 에서 내려받아 `public/app-icons/` 에 저장. 새 앱·주소 변경 시 바로, 이후 7일마다, 실패 시 6시간 뒤 재시도 (`src/Monitoring/AppIconFetcher.php`)

### 3단계 · 만료 알림

- [ ] Apple 멤버십 만료 30일 전, 7일 전에 문자 알림 (기존 알림 기능 사용)
- [ ] 같은 만료로 중복 발송하지 않기
- [ ] (선택) 대시보드에 "곧 만료" 요약 (도메인 만료 포함)

### 4단계 · 선택 사항

- [ ] 대시보드 카드에 앱 버전 줄 (⑤)
- [ ] B안: 외부 서비스(SaaS) 관리. 서비스, 이름, 계정, 요금제, 결제 관리회사, 갱신일, 메모
- [ ] APNs 키·푸시 인증서 만료일 (직접 입력, 만료 알림)

### 5단계 · 앱 상세, 다운로드·리뷰 (2026-11-08 설계)

시안: `docs/시안/앱상세.png`. 지금 있는 데이터로 채울 수 있는 부분은 구현했고, 다운로드·리뷰는 **화면 영역과 테이블만** 만들었다 (수집기 없음 → 빈 상태 안내).

#### 화면 (`/apps/{id}`, 모든 역할)

- "앱" 하나 = **같은 서비스 + 같은 이름**(대소문자·앞뒤 공백 무시)의 iOS·Android·웹앱 행 묶음. 어느 플랫폼 행으로 들어와도 같은 화면 (`AppRegistry::siblings`)
- 앱 목록(설정 > 앱, 서비스 상세)의 앱 이름 → 상세. 관리자는 이름 옆 연필 아이콘 → 수정

| 영역 | 데이터 | 상태 |
|---|---|---|
| 상단 카드: 아이콘·이름·운영 상태·플랫폼별 버전 | `apps` | 구현 |
| 총 다운로드 수 (누적, 직전 기간 대비 증감, 추이) | `app_store_daily_stats.downloads` | 영역만 ("수집 전") |
| 평균 평점 | iOS: `apps.store_info` 의 `averageUserRating`·`userRatingCount` (App Store 조회 API, 이미 수집 중). 여러 플랫폼이면 평점 수 가중 평균. 없으면 수집한 리뷰 평균 | iOS 구현 |
| 최신 버전 | `apps.version`·`released_at` | 구현 |
| 기본 정보 (서비스, 설명, 대표 이미지, 관리회사, 담당자, 등록·수정일) | `apps`, `systems`, `system_owners` | 구현 |
| 스토어 정보 (플랫폼별 링크, 번들 ID, 버전, 업데이트일, 개발자 계정, 평점, 정보 출처) | `apps` | 구현. 미등록 플랫폼은 "등록하기" |
| 다운로드 통계 (최근 7·30·90일, 플랫폼별 합계, 일별 누적 막대) | `app_store_daily_stats` | 영역만 |
| 리뷰 (평균·별점 분포·최근 3개·답변 대기 수) | `app_store_reviews` | 영역만 |
| 서버 & 인프라, 도메인 & SSL | 서비스의 웹/API·EC2·RDS·도메인 정보 | 구현 |
| 빠른 작업 (스토어 열기, 관리자 페이지, 개발자 콘솔, 서비스 상태, 설정 수정, 개발자 계정) | - | 구현 |
| 관리자 페이지 바로가기 (상단·기본 정보·빠른 작업) | `systems.admin_url` (서비스 관리에서 입력, 019) | 구현 |
| 개발자 콘솔 (스토어 열마다) | iOS: App Store 앱 id 가 있으면 `appstoreconnect.apple.com/apps/{id}/distribution`. Android: Play Console 은 개발자·앱 내부 id 가 있어야 앱 화면으로 가서 콘솔 첫 화면으로. 개발자 계정 로그인 이메일은 관리자에게만 | 구현 |
| 메모 | `apps.notes` | 구현 |

시안에서 뺀 것: 총 매출(결제 데이터 없음), 신규 가입·MAU(서비스 DB 지표라 앱 스토어와 별개), 배포 정보(GitHub 연동 없음), 연관 서비스(4단계 B안), CDN·자동 갱신 토글, 앱 삭제 버튼(삭제하지 않고 비활성화하는 원칙).

#### 테이블 (`database/migrations/017_create_app_metrics.sql`)

`docs/app-manager-store-integration-spec.md`(스토어 통합 연동 명세서) 5.4·5.5 의 테이블·컬럼 이름을 따르고 한눈 구조에 맞췄다.

- **명세서 `app_stores` = 한눈 `apps`**: 한눈은 앱을 플랫폼마다 한 행(iOS 행, Android 행)으로 두므로 그 행이 곧 "앱 × 스토어". 명세서의 `app_store_id` 자리에 `app_id`(apps.id)
- `app_store_daily_stats` — UNIQUE(`app_id`, `stat_date`, `source`). 출처별로 하루 한 행, 다시 받으면 덮어쓴다 (리포트가 1~2일 늦고 나중에 고쳐지기도 함)
  - 다운로드·설치·활성 사용자, 매출·환불·통화, 스토어 평점·평점 수, 수집 리뷰 평균·수, 별점별 개수, `metric_definition`, `collected_at`
  - NULL = 미제공·미수집, 0 = 실제 0. 수집하지 않은 값을 0 으로 채우지 않는다. 통화가 다르면 합산하지 않는다
  - 화면은 같은 앱·날짜에 출처가 여럿이면 큰 값 하나만 쓴다 (더하면 중복)
- `app_store_reviews` — UNIQUE(`app_id`, `external_review_id`). 수정된 리뷰·개발자 답변은 덮어쓴다. `raw_data` 에 토큰·인증 정보는 넣지 않는다
- 명세서와 다른 점: SQLite(명세서는 MySQL), 시각은 KST 텍스트, CASCADE 없음(앱은 삭제하지 않고 비활성화), `app_stores`·`app_store_credentials`·`app_store_versions`·`app_store_sync_logs`·`app_store_reports` 는 수집기를 붙일 때 추가
- 둘 다 실행 데이터라 `database/config.sql` 에 넣지 않는다
- 화면의 기간은 오늘이 아니라 **마지막으로 집계된 날짜**에서 끝난다 (오늘을 0 으로 세면 증감이 틀어짐)

#### 수집 방안 (다음 작업)

| 데이터 | iOS | Android |
|---|---|---|
| 다운로드 | App Store Connect API **Sales and Trends** (`GET /v1/salesReports`, `reportType=SALES`, `frequency=DAILY`, 일 1회). 상품 유형 1·1F = 신규, 3·3F = 재설치, 7·7F = 업데이트 | Play Console 통계 CSV 가 Google Cloud Storage 버킷(`pubsite_prod_rev_…/stats/installs/`)에 매일 올라온다. 서비스 계정으로 내려받아 `Daily User Installs`·`Active Device Installs`·`Daily User Uninstalls` 사용 |
| 평점 | 이미 수집 중 (App Store 조회 API). 매일 `app_store_daily_stats.store_rating*` 에도 남겨 추이를 본다 | 통계 CSV(`stats/ratings/`)의 `Total Average Rating` |
| 리뷰 | 1차: 공개 RSS(`https://itunes.apple.com/kr/rss/customerreviews/id={trackId}/sortby=mostrecent/json`, 키 불필요, 최근 500개). 2차: App Store Connect API `GET /v1/apps/{id}/customerReviews` (답변 포함, 답변 작성도 가능) | Google Play Developer API `reviews.list` (서비스 계정. 최근 1주일 리뷰만 주므로 매일 받아 쌓는다). 답변은 `reviews.reply` |

- 인증 키는 DB 에 저장하지 않는다 (§6 원칙). App Store Connect API 키(`.p8`)·Google 서비스 계정 JSON 은 서버 파일로 두고 `.env` 에 경로만 (`ASC_KEY_PATH`, `ASC_KEY_ID`, `ASC_ISSUER_ID`, `GOOGLE_PLAY_CREDENTIALS`, `GOOGLE_PLAY_BUCKET`). 키는 개발자 계정마다 다를 수 있어, 여러 개면 `app_store_accounts` 의 계정 이름과 맞춘 파일 이름 규칙으로 찾는다
- 실행: `bin/cron.php` 에 하루 한 번 (새벽, iOS 앱 조회와 같은 시간대). 실패하면 이전 값을 두고 오류만 기록 (도메인·App Store 조회와 같은 방식)
- 키를 받기 전에는 CSV 를 손으로 올리는 방법(`source = 'manual'`)도 둘 수 있다

#### iOS 리뷰 수집 (구현, 2026-11-08)

- 출처: App Store 공개 리뷰 피드 `https://itunes.apple.com/kr/rss/customerreviews/page={1~10}/id={trackId}/sortby=mostrecent/json` (키 불필요). trackId 는 스토어 주소의 `/id123`, 없으면 App Store 조회 결과(`store_info.trackId`)
- 일정: `bin/cron.php` 가 1분마다 부르고, 앱마다 **성공 후 하루, 실패 후 1시간** 뒤 다시 받는다. 한 번에 3개 앱. 바로 받으려면 `composer reviews:collect -- --all` 또는 `php bin/collect-reviews.php --app=ID`
- 저장: `app_store_reviews` (source `itunes_rss`, territory `KOR`). 리뷰 ID 로 중복 없이 넣고 별점·제목·내용·버전이 바뀌면 덮어쓴다. 받은 그대로인 페이지가 나오면 그 뒤는 받지 않는다
- 실행 기록: `app_store_sync_logs` (sync_type `reviews`, success / partial / failed, 새로 넣거나 바뀐 수, 오류 사유). 90일 지난 기록은 지운다. 앱 상세 리뷰 영역 아래에 마지막 결과 표시
- 확인된 피드 특성 (2026-11-08)
  - **브라우저처럼 보이는 User-Agent(`Mozilla/5.0 (compatible; …)`)를 보내면 빈 결과**를 준다. 그래서 수집기는 평범한 User-Agent(`hannun-review-collector/1.0`)로 따로 요청한다 (`AppReviewCollector::httpGet`)
  - 앱에 따라 1페이지(최근 50개)만 주는 경우가 많다 (미리: 평점 1,022개인데 피드는 50개). 매일 받아 쌓으면 그 뒤로는 빠짐없이 모인다. 과거 전체는 App Store Connect API 가 필요
  - 같은 주소가 가끔 빈 응답을 준다. 그래서 빈 응답은 "0건 성공"으로 두고 **지우지 않는다**
  - 피드에는 리뷰 작성일이 없고 마지막 수정 시각(`updated`)만 있다. 처음 본 값을 `review_created_at` 으로 둔다
  - 개발자 답변이 없다. 그래서 화면은 `AppRegistry::REPLY_SOURCES`(app_store_connect, google_play) 출처의 리뷰만 답변함/미답변을 표시하고 "답변 대기"로 센다
- 첫 실행 결과 (개발 DB): 미리 50개, 나머지 iOS 앱(우반시·Nekoya·koibridge·kaybridge·명당·하이어)은 피드에 리뷰 없음

#### 남은 결정

- [ ] App Store Connect API 키 발급 담당 (계정 관리자 권한 필요, 키 역할은 Sales/Finance 이상)
- [ ] Google Play 서비스 계정 연결 (Play Console → 사용자 및 권한에 서비스 계정 초대, "앱 정보 보기·재무 데이터 보기")
- [ ] 리뷰 답변을 이 화면에서 쓸지, 보기만 할지
- [ ] 별점 1~2점 새 리뷰를 문자로 알릴지

#### 작업

- [x] 앱 상세 화면 (`src/Controller/AppDetailController.php`, `templates/app.php`)
- [x] 테이블 `app_store_daily_stats`, `app_store_reviews` 와 조회 (`AppRegistry::dailyStats`·`downloadTotals`·`reviewSummary`·`recentReviews`)
- [x] 테스트 (`tests/AppDetailTest.php`)
- [x] iOS 리뷰 수집 (2026-11-08, `src/Monitoring/AppReviewCollector.php`, `tests/AppReviewCollectorTest.php`) — 아래 "iOS 리뷰 수집" 참고
- [ ] 매일 평점 기록 (`app_store_daily_stats.store_rating`·`store_rating_count`, source `itunes_lookup`)
- [ ] App Store Connect 판매 리포트 → 다운로드
- [ ] Play Console 통계 CSV → 다운로드·평점, Play Developer API → 리뷰

## 6. 원칙

- 비밀번호, API 키, 인증서 파일은 저장하지 않는다. 보관 위치만 메모한다
- 참조되는 데이터(계정, 앱)는 삭제하지 않고 비활성화한다
- 자동 조회 값과 직접 입력 값을 구분해서 보여 준다 (도메인 정보 수동 입력과 같은 방식)
- 화면 권한: 조회는 모든 역할, 등록·수정은 관리자
