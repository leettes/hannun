# 한눈 (Hannun)

여러 서비스를 한눈에 보는 통합 모니터링. 5개 운영 시스템(미리, 코이 등)의 웹/API, EC2, RDS 상태를 한곳에서 보는 PHP 통합 관리 대시보드.

- 구축 계획: [docs/PLAN.md](docs/PLAN.md)
- DB 설계: [docs/DB.md](docs/DB.md)
- 운영 서버 배포 (Ubuntu micro, Nginx·PHP-FPM, Uptime Kuma Docker, cron, 백업): [docs/DEPLOY.md](docs/DEPLOY.md)
- AWS 설정 (EC2·RDS 지표 조회용 IAM 역할, 비용·권장 구성): [docs/AWS.md](docs/AWS.md)
- 알림 연동 (Uptime Kuma 수집·Webhook, 문자 알림, CloudWatch/SNS): [docs/ALERTS.md](docs/ALERTS.md)
- 서비스별 앱 관리 기획안 (앱, 개발자 계정, 시안, 작업 단계): [docs/APPS.md](docs/APPS.md)
- 시스템 구성도 구성 요소 직접 입력 (작업 노트, 다음 할 일): [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- 회의록·기능 추적 기획안 (직원 전용, 2단계 인증·본문 암호화·감사 로그): [docs/MEETINGS.md](docs/MEETINGS.md)

## 개발 환경

- PHP 8.2 이상, Composer

```bash
composer install
cp .env.example .env
composer migrate     # SQLite, 경로는 .env 의 DB_DATABASE
composer seed:sample # (선택) 빈 DB 에 화면 확인용 샘플 데이터 적재
composer user:create -- --email=admin@example.com --name="관리자"
composer serve       # http://localhost:8000
php bin/cron.php     # Uptime Kuma 수집 + iOS 앱 조회·리뷰 수집 + 문자 재발송 (운영에서는 cron 1분)
composer reviews:collect -- --all  # iOS 리뷰 지금 바로 받기 (평소에는 cron 이 앱마다 하루 한 번)
composer test
```

## 화면

| 경로 | 화면 |
|---|---|
| `/` | 대시보드: 상태 요약, 서비스 카드, 최근 장애. 서비스 상태는 전체 / 관리회사별(`?view=company`, 서비스에 직접 지정한 관리회사, 없으면 AWS 계정의 관리회사 기준) 보기 전환, 고른 보기는 세션에 기억 |
| `/systems/{code}` | 서비스 상세: 웹/API, EC2, RDS 지표, 장애 이력 |
| `/architecture` | 시스템 구성도 (모든 역할): 사용자 → 도메인·보안 → 웹 서버 → 데이터 → 외부 연동 계층으로 그린다. 전체 / 서비스별(`?system={code}`) 탭. 점검 상태·도메인·앱·개발자 계정은 DB 값, Nginx·PHP·MySQL 은 공통 스택 표시(버전 미수집), 결제·푸시 등 기록 없는 외부 연동은 '확인 필요'. EC2·RDS 는 `AWS_RESOURCES=true` 이고 등록돼 있을 때 실제 값. 서비스 상세의 '구성도 보기'에서도 연결 |
| `/incidents` | 장애 센터: 상태별 탭(`?status=open` 등) |
| `/incidents/{id}` | 장애 상세: 타임라인, 조치 기록 (운영자 이상 저장) |
| `/settings/systems` | 서비스 관리 (관리자 전용): 서비스 등록(관리자 페이지 주소 포함, 클라우드 기본 AWS, AWS 계정 연결), 웹/API 리소스 등록, 도메인·연결 IP, 비활성화. EC2·RDS 는 `.env` `AWS_RESOURCES=true` 일 때만 표시 |
| `/settings/domains/{domain}` | 도메인 정보 수정 (관리자 전용): 서비스 관리의 도메인을 눌러 등록업체·만료일·네임서버·메모를 직접 입력. 입력한 항목은 자동 수집(RDAP·WHOIS) 값보다 우선, 모두 비우면 자동 값으로 되돌림 |
| `/settings/aws-accounts` | AWS 계정 (관리자 전용): 계정 ID, 별칭, 루트 이메일, 루트 MFA 여부, 메모. 비밀번호·액세스 키는 저장하지 않음 |
| `/settings/companies` | 관리회사 (관리자 전용): 법인명, 대표자, 사업자번호(검증번호 확인, 중복 불가), 메모. AWS 계정 화면에서 계정마다 관리회사 지정, Vercel 등 AWS 계정이 없는 서비스는 서비스 관리에서 직접 지정(서비스 지정이 우선) |
| `/apps/{id}` | 앱 상세 (모든 역할): 같은 서비스·같은 이름의 iOS·Android·웹앱을 한 화면에. 기본 정보, 스토어별 정보(번들 ID·버전·업데이트일·개발자 계정, iOS 평점), 다운로드 통계(최근 7·30·90일), 리뷰(별점 분포·최근 리뷰·답변 여부, '전체 보기'로 `/apps/{id}/reviews` 전체 목록: 30개씩 쪽 나누기, 별점·플랫폼·미답변 필터, 개발자 답변 표시), 서버 & 인프라, 도메인 & SSL, 빠른 작업(관리자 페이지, App Store Connect·Google Play Console, 개발자 계정). iOS 리뷰는 App Store 공개 피드에서 하루 한 번 수집, 다운로드·Android 리뷰는 연동 전이라 빈 영역 ([docs/APPS.md](docs/APPS.md) 5단계) |
| `/settings/apps` | 앱 (관리자 전용): 서비스별 iOS·Android·웹앱. 번들 ID(플랫폼별 중복 불가), 스토어 주소, 개발자 계정, 버전·업데이트일(iOS 는 cron 이 하루 한 번 App Store 에서 자동 조회, Android 는 직접 입력), 비활성화. 플랫폼 탭 + 관리회사 필터(`?company=ID`/`none`), 스토어 상세 버튼, 아이콘(cron 이 스토어에서 내려받아 `public/app-icons/` 에 저장). 서비스 상세에도 '앱' 섹션 |
| `/settings/app-accounts` | 개발자 계정 (관리자 전용): Apple Developer·Google Play Console. 팀 ID, 로그인 이메일, 관리회사, Apple 멤버십 만료일(30일 안이면 경고). 비밀번호·키는 저장하지 않음 |
| `/users` | 사용자 관리 (관리자 전용): 추가, 역할 변경, 비활성화, 비밀번호 재설정 |
| `/login` | 관리자 로그인 |

## 권한

| 역할 | 할 수 있는 일 |
|---|---|
| 조회(viewer) | 모든 화면 조회 |
| 운영자(operator) | + 장애 처리: 확인/복구/다시 열기, 담당자 지정, 원인·조치 내용, 메모 |
| 관리자(admin) | + 서비스·리소스 등록, 사용자 관리 |

경로마다 필요한 최소 역할은 `AppFactory`에서 `$router->get('/users', [...], 'admin')` 처럼 세 번째 인자로 지정하며, 부족하면 403을 반환합니다. 관리자는 자기 계정의 관리자 권한 해제나 비활성화를 할 수 없습니다(관리자 0명 방지). 계정·서비스·리소스는 장애 이력이 참조하므로 삭제 대신 비활성화합니다. 서비스·리소스는 진행 중인 장애를 복구한 뒤에 비활성화할 수 있고, 비활성화하면 대시보드에서 숨기고 Uptime Kuma 수집·알림 대상에서도 빠집니다. 서비스 코드(화면 주소)와 리소스 종류는 등록 후 바꿀 수 없습니다.

화면 데이터는 DB(`src/Monitoring/DatabaseMonitoringRepository.php`)에서 읽습니다. 수집기 연동 전에는 `composer seed:sample`로 `src/Sample/SampleData.php`의 샘플을 DB에 넣어 확인합니다. 상태 판정 기준은 `src/Monitoring/Thresholds.php`에 있으며 `docs/PLAN.md` 3장과 맞춥니다.

## 구조

```
public/      웹 루트 (index.php, assets/app.css)
config/      부트스트랩, 라우트 등록
src/         App\ 네임스페이스 (Router, Controller, Monitoring, Sample)
templates/   PHP 템플릿 (layout, 페이지, partials)
tests/       PHPUnit 테스트
storage/     로그
docs/        계획 및 운영 문서
```
