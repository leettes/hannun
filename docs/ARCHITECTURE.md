# 시스템 구성도 — 구성 요소 직접 입력 (작업 노트)

`/architecture` 화면의 "확인 필요" 칸(결제·푸시·SMS 등)을 서비스 관리에서 직접 입력하게 만드는 작업.

> 상태: 구성도 화면은 완료 (2026-11-12). 구성 요소 입력은 **아직 안 함**. 이 문서대로 진행하면 된다. 예상 30~40분.

## 0. 다른 컴퓨터에서 시작하기 전에

- [ ] 구성도 작업이 커밋·푸시됐는지 확인 (`src/Controller/ArchitectureController.php`, `templates/architecture.php`, `app.css` 맨 아래 `/* 시스템 구성도 */` 블록 등)
- [ ] `git pull && composer install && composer migrate && composer config:import`
- [ ] `composer test` 통과 확인 후 시작

## 1. 지금 구조 (2026-11-12)

| 칸 | 값이 오는 곳 |
|---|---|
| 웹·API 상태, 응답 시간, SSL, 도메인(DNS·등록업체·만료), 연결 IP | DB (`resources`, `check_results`, `domain_info`) |
| 앱, 개발자 계정 | DB (`apps`, `app_store_accounts`) |
| EC2·RDS | DB `resources` (type `ec2`/`rds`). `.env` `AWS_RESOURCES=true` 일 때만 표시, 아니면 "AWS 연동 전" |
| Nginx·PHP-FPM·MySQL ("공통 스택") | 코드 상수 `ArchitectureController::STACK` |
| 푸시·결제·SMS·소셜 로그인 ("확인 필요") | 코드 상수 `ArchitectureController::EXTERNAL_TODO` |
| WAF·DDoS, 파일 스토리지(S3), 백업, Vercel 런타임 | `templates/architecture.php` 에 문구로 고정 |
| 한눈이 올라간 서버 | `ArchitectureController::HANNUN_HOST = 'koi'` |

이번 작업으로 위 표의 **코드 상수·고정 문구**를 DB 값으로 바꾼다.

## 2. 결정 사항

- 서비스마다 정해진 구성 요소 목록에 대해 **상태 + 업체·버전 + 메모**를 입력한다
- 상태 3가지
  - `used` 사용 → 실선 초록 칸, 업체·버전 표시
  - `unused` 사용 안 함 → 구성도에서 숨김
  - `unknown` 모름 (기본) → 지금처럼 점선 "확인 필요". 단 Nginx·PHP·MySQL 은 모름이어도 파란 "공통 스택"으로 둔다
- 모름이면서 업체·메모가 비면 행을 저장하지 않는다 (지운다)
- 비밀번호·API 키·계정 정보는 저장하지 않는다 (AWS 계정과 같은 방침). 업체 이름·버전·메모만
- 관리자만 수정 (서비스 관리 화면), 구성도는 모든 역할이 조회
- 설정 데이터이므로 `database/config.sql` 내보내기·불러오기에 포함

### 구성 요소 목록 (고정)

| key | 이름 | 계층 | 입력 예 | 앱 있는 서비스만 |
|---|---|---|---|---|
| `waf` | WAF · DDoS 방어 | 도메인·보안 | AWS WAF, Cloudflare | |
| `nginx` | Nginx | 웹 서버 | 1.24 | |
| `php` | PHP | 웹 서버 | PHP 8.1 / Laravel 10 | |
| `mysql` | MySQL | 데이터 | RDS MySQL 8.0, EC2 MariaDB 10.6 | |
| `storage` | 파일 스토리지 | 데이터 | S3 버킷명 | |
| `backup` | 백업 | 데이터 | RDS 자동 백업 7일 | |
| `push` | 푸시 | 외부 연동 | FCM, APNs | ✓ |
| `payment` | 결제 | 외부 연동 | 인앱 결제, 토스페이먼츠 | ✓ |
| `sms` | SMS · 본인인증 | 외부 연동 | 알리고, NICE | |
| `social` | 소셜 로그인 | 외부 연동 | Apple, Google, Kakao | |

Vercel 서비스(미리도화)는 `nginx`·`php` 를 입력 칸에서 빼거나 "사용 안 함" 기본. `mysql` 칸 이름은 "데이터 저장소"로 보여도 된다.

## 3. 작업 순서

### 3-1. 마이그레이션 `database/migrations/020_create_system_components.sql`

```sql
-- 서비스별 구성 요소 (시스템 구성도). 설정 데이터라 database/config.sql 에 들어간다.
CREATE TABLE system_components (
    system_id INTEGER NOT NULL REFERENCES systems(id),
    component TEXT NOT NULL,                 -- SystemComponents::ITEMS 의 key
    state TEXT NOT NULL DEFAULT 'unknown' CHECK (state IN ('used', 'unused', 'unknown')),
    provider TEXT NULL,                      -- 업체·버전
    notes TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (system_id, component)
);
```

`019_add_system_admin_url.sql` 처럼 SQLite 문법. 컬럼 이름·날짜 형식은 기존 테이블에 맞춘다.

### 3-2. 정의 클래스 `src/Monitoring/SystemComponents.php`

- `ITEMS` 상수: key => [이름, 계층(`security`/`web`/`data`/`external`), placeholder, appsOnly] (2장 표 그대로)
- `STATES = ['unknown' => '모름', 'used' => '사용', 'unused' => '사용 안 함']`
- `ArchitectureController::STACK`, `EXTERNAL_TODO` 는 이 클래스로 옮기고 지운다

### 3-3. 저장소 `src/Monitoring/SystemRegistry.php`

- `components(int $systemId): array` → key => [state, provider, notes]. 행이 없으면 unknown
- `allComponents(): array` → system_id => key => 행 (구성도 전체 보기용, 한 번에 조회)
- `saveComponents(int $systemId, array $values): void` → 트랜잭션. 모름 + 빈 값은 DELETE, 나머지는 `INSERT ... ON CONFLICT(system_id, component) DO UPDATE`. `setOwners()` 의 트랜잭션 패턴을 그대로 쓴다

### 3-4. 서비스 관리 화면

- `templates/settings-system-form.php`: 기존 입력 칸 아래 "구성 요소" 칸 추가. 계층별로 묶은 표, 행마다
  - `components[{key}][state]` select (모름/사용/사용 안 함)
  - `components[{key}][provider]` text, maxlength 100
  - `components[{key}][notes]` text, maxlength 200
- `src/Controller/SystemAdminController.php`
  - `systemInput()` 에 `components` 를 읽어 정리 (모르는 key 무시, trim)
  - `validateSystem()` 에 state 값·길이 검사. 오류 키는 `components.{key}.provider` 형식
  - `store()`·`update()` 에서 `updateSystem()`/`createSystem()` 뒤에 `saveComponents()` 호출
  - `edit()`/`systemForm()` 에서 기존 값을 채워 넘긴다
- 생성자에 이미 `SystemRegistry` 가 있으므로 `AppFactory` 수정은 필요 없다

### 3-5. 구성도 `ArchitectureController` / `templates/architecture.php`

- 컨트롤러에서 `$registry->allComponents()` 를 넘긴다. **`ArchitectureController` 생성자에 `SystemRegistry` 추가 → `AppFactory` 의 resolve 한 줄 수정**
- 서비스 탭: 고정 노드(WAF, Nginx, PHP, MySQL, S3, 백업, 푸시·결제·SMS·소셜) 대신 `SystemComponents::ITEMS` 를 돌며 계층별로 그린다
  - used → `$node(이름, 업체·버전 + 메모, 'ok', …, '사용')`
  - unused → 그리지 않음
  - unknown → nginx/php/mysql 은 `'stack'`, 나머지는 `'todo'`
  - EC2·RDS 실제 리소스가 있으면 그대로 우선 표시 (지금 로직 유지)
- 전체 탭: 구성 요소마다 "사용 n곳 · 모름 n곳" 과 업체 목록 (예: 결제 — 토스페이먼츠(코이, 미리) · 모름 3곳)
- 관리자에게는 서비스 탭 상단에 "구성 요소 수정 →" 링크 (`/settings/systems/{id}`). `$currentUser->can('admin')` 확인
- 하단 "채워야 할 정보" 목록은 unknown 이 남은 항목만 보이게

### 3-6. 설정 내보내기 `src/ConfigSnapshot.php`

- `TABLES` 에 `'system_components' => []` 추가 (`systems` 다음, 외래 키 순서)
- `SYNC_DELETE` 에 `'system_components'` 추가 (앱에서 행을 지우므로)
- 마이그레이션 후 `bin/config.php export` 하면 `config.sql` 의 `-- 스키마:` 줄이 `020_create_system_components` 로 바뀐다. 다른 환경은 `composer migrate` 후 `config:import` 해야 한다 (스키마가 다르면 import 가 거부함)

### 3-7. 테스트

- `tests/SystemAdminTest.php`: 구성 요소 저장 → 다시 열면 값 유지, 모름+빈 값이면 행 삭제, 잘못된 state 거부, 비관리자 403
- `tests/PagesTest.php`: 저장한 업체명이 `/architecture?system=…` 에 보임, unused 항목은 안 보임, 관리자에게만 "구성 요소 수정" 링크
- `tests/ConfigSnapshotTest.php`: export/import 에 `system_components` 포함, 파일에 없는 행 삭제
- `composer test` 전체 통과

### 3-8. 문서

- `README.md` 화면 표의 `/architecture`, `/settings/systems` 줄에 구성 요소 입력 반영
- 이 문서 상단 상태를 "완료"로

## 4. 입력할 값 모으기 (작업과 별개)

화면이 생기면 아래를 채워 서비스 관리에서 입력한다. 모르면 비워 두면 "확인 필요"로 남는다.

| 서비스 | WAF | Nginx | PHP | MySQL | 스토리지 | 백업 | 푸시 | 결제 | SMS | 소셜 로그인 |
|---|---|---|---|---|---|---|---|---|---|---|
| 우반시 | | | | | | | | | | |
| 하이어 | | | | | | | | | | |
| 명당 | | | | | | | | | | |
| 케데스 라이브 | | | | | | | — | — | | |
| 코이 | | | | | | | | | | |
| 미리 | | | | | | | | | | |
| 미리도화 (Vercel) | | — | — | | | | — | — | | |

참고로 이미 수집된 값: 우반시 `wapi.woobansi.com` DNS 는 AWS Route 53, 코이 `koi-api.pnexusai.com` DNS 는 Cloudflare (구성도 도메인 칸에 자동 표시됨).
