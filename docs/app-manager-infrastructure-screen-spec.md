# App Manager - 서버 인프라 정보 화면 설계서

## 1. 목적

여러 서비스의 AWS 인프라를 한 화면에서 조회하고,
서버·데이터베이스·네트워크·도메인·인증서·백업 구성을 관리하기 위한
관리자 화면이다.

실시간 CPU·메모리·API 응답속도 추이는 별도의 **모니터링** 화면에서
제공하고, 이 화면은 인프라 자원의 구성과 연결 관계를 보여준다.

## 2. 메뉴 구조

-   인프라 개요
-   서버(EC2)
-   데이터베이스(RDS)
-   네트워크(VPC)
-   도메인 및 DNS
-   SSL 인증서
-   스토리지 및 백업
-   보안 및 접근
-   변경 이력

## 3. 인프라 개요

### 상단 요약 카드

  카드            표시 정보
  --------------- ----------------------------
  전체 서버       EC2 수 / 실행 중 / 중지
  데이터베이스    RDS 수 / 가용 상태
  서비스 도메인   등록 도메인 수
  SSL 인증서      정상 / 만료 임박 / 만료
  백업            최근 성공 시각 / 실패 건수
  리전            사용 중인 AWS 리전 수

### 인프라 구성도

``` text
인터넷
  |
Route 53 / DNS
  |
CloudFront 또는 ALB
  |
EC2 (Nginx + PHP-FPM)
  |
RDS MySQL
  |
S3 (파일 및 백업)

외부 연동:
- Apple App Store Connect
- Google Play Developer API
- 결제 및 알림 서비스
```

실제 연결된 자원만 선으로 연결한다. 관계를 확인할 수 없는 경우 임의로
연결하지 않고 `연결 정보 미등록`으로 표시한다.

### 서비스별 인프라 요약

  ----------------------------------------------------------------------------
  서비스      도메인      웹 서버     DB          리전             상태
  ----------- ----------- ----------- ----------- ---------------- -----------
  미리도화    등록 도메인 연결된 EC2  연결된 RDS  ap-northeast-2   상태 조회

  명당        등록 도메인 연결된 EC2  연결된 RDS  ap-northeast-2   상태 조회

  노르미      등록 도메인 연결된 EC2  연결된 RDS  ap-northeast-2   상태 조회

  엘림        등록 도메인 연결된 EC2  연결된 RDS  ap-northeast-2   상태 조회
  ----------------------------------------------------------------------------

위 서비스 행은 화면 구성 예시이며 실제 자원 정보로 치환한다.

## 4. 서버(EC2)

### 목록 컬럼

  항목            설명
  --------------- --------------------------------------
  서버명          Name 태그 또는 내부 별칭
  인스턴스 ID     AWS EC2 Instance ID
  연결 서비스     담당 서비스
  계정            AWS 계정 별칭
  리전 / AZ       예: ap-northeast-2 / ap-northeast-2a
  인스턴스 유형   예: t3.small, t4g.micro
  OS              Ubuntu 등
  사설 IP         VPC 내부 IP
  공인 IP         Elastic IP 또는 Public IP
  상태            running / stopped 등
  관리            상세 보기

### 상세 화면 탭

**기본 정보** - 서버명, 설명, AWS 계정 별칭 - 리전 및 가용 영역 -
인스턴스 ID 및 유형 - 운영체제 및 커널 버전 - 생성 시각, 시작 시각, 현재
상태

**네트워크** - VPC ID 및 이름 - Subnet ID 및 이름 - 사설 IP / 공인 IP -
Elastic IP 연결 여부 - 보안 그룹 목록 - 네트워크 인터페이스 - 연결된
로드밸런서 및 Target Group

**컴퓨팅 및 디스크** - vCPU 및 인스턴스 유형 기준 메모리 - 루트 볼륨 및
추가 EBS 볼륨 - 볼륨 크기 / 유형 / 암호화 여부 - 마운트 정보(SSM 또는
에이전트 수집 시)

**소프트웨어** - Nginx 등 웹 서버 버전 - PHP 및 PHP-FPM 버전 - 주요
런타임 버전 - 배포 애플리케이션 및 경로 - 에이전트 수집 여부와 마지막
수집 시각

**운영 정보** - 담당자 또는 담당 팀 - 운영 / 개발 / 스테이징 환경 -
서비스 중요도, 점검 일정, 비고

## 5. 데이터베이스(RDS)

### 목록 컬럼

  항목              설명
  ----------------- ------------------------
  DB 식별자         DBInstanceIdentifier
  연결 서비스       사용 서비스
  엔진 / 버전       MySQL 등
  인스턴스 클래스   예: db.t4g.micro
  리전 / AZ         배포 위치
  배포 구성         Single-AZ / Multi-AZ
  스토리지          할당 용량 및 유형
  엔드포인트        호스트명과 포트
  상태              available 등
  백업              보존 기간 및 최근 백업

### 상세 정보

-   DB 식별자, 엔진 및 버전
-   인스턴스 클래스 및 스토리지
-   엔드포인트와 포트
-   VPC / Subnet Group / 보안 그룹
-   파라미터 그룹 및 옵션 그룹
-   백업 보존 기간 및 유지관리 시간
-   암호화, 삭제 방지, Multi-AZ 여부
-   연결 서비스 목록

DB 비밀번호나 Secret 값은 화면에 노출하지 않는다. 필요하면 Secrets
Manager의 참조 이름만 표시한다.

## 6. 네트워크(VPC)

### VPC 및 서브넷

-   VPC ID, 이름, CIDR
-   Subnet ID, CIDR, AZ
-   Route Table 연결 정보
-   Internet Gateway / NAT Gateway
-   연결된 EC2 및 RDS

### 보안 그룹 요약

-   보안 그룹 ID와 이름
-   연결된 EC2 / RDS
-   인바운드 / 아웃바운드 규칙 수
-   `0.0.0.0/0` 외부 공개 규칙 여부
-   SSH(22), DB(3306) 등 주요 포트 노출 경고

초기 버전은 조회 전용으로 제공하고 보안 그룹 변경 기능은 제외한다.

## 7. 도메인 및 SSL 인증서

### 도메인 목록

  항목          설명
  ------------- --------------------------
  도메인        FQDN
  연결 서비스   서비스명
  DNS 제공자    Route 53 등
  레코드        A / AAAA / CNAME
  대상          IP, ALB 또는 다른 도메인
  SSL 인증서    인증서 식별자
  만료일        인증서 만료일
  상태          정상 / 점검 필요

### 인증서 알림 기준

-   만료 30일 전: 안내
-   만료 14일 전: 경고
-   만료 7일 전: 긴급
-   만료 또는 검증 실패: 장애 알림

ACM 외에 서버에 직접 설치된 인증서는 별도 수집 작업으로 만료일을
확인한다.

## 8. 스토리지 및 백업

### 스토리지

-   EC2 EBS 볼륨
-   S3 버킷
-   RDS 스토리지
-   용량, 유형, 암호화 여부
-   연결된 서버 또는 서비스

### 백업 현황

  항목        설명
  ----------- --------------------------------
  대상        RDS / EBS / S3
  백업 방식   자동 백업 / 스냅샷 / 별도 백업
  보존 기간   설정된 보존 기간
  최근 성공   마지막 성공 시각
  최근 실패   마지막 실패 시각
  상태        정상 / 실패 / 확인 필요

백업 설정 여부와 복구 검증 여부를 구분한다. 복구 테스트 일자와 결과를
별도 기록한다.

## 9. 보안 및 접근 정보

조회할 항목: - IAM Role 및 인스턴스 프로파일 - SSM 관리형 인스턴스
여부 - 보안 그룹 외부 노출 규칙 - EBS / RDS 암호화 여부 - CloudTrail
수집 여부 - AWS 계정별 접근 상태

SSH 개인키, API Secret, DB 비밀번호는 저장하거나 표시하지 않는다. 서버
접속은 승인된 SSH 또는 SSM 절차를 이용한다.

## 10. 검색 및 필터

공통 필터: - 서비스 - 환경(운영 / 개발 / 스테이징) - AWS 계정 - 리전 -
자원 유형 - 상태

검색 대상: - 서버명 및 인스턴스 ID - 사설 / 공인 IP - 도메인 - DB 식별자

## 11. 데이터 수집 아키텍처

``` text
App Manager (PHP)
      |
      +-- Infrastructure API
      |       |
      |       +-- AWS SDK for PHP
      |       |      +-- EC2
      |       |      +-- RDS
      |       |      +-- ELBv2
      |       |      +-- VPC / Security Groups
      |       |      +-- Route 53
      |       |      +-- ACM
      |       |      +-- S3
      |       |
      |       +-- SSM / 제한된 서버 에이전트
      |
      +-- MySQL
              +-- 자원 메타데이터
              +-- 서비스 연결 정보
              +-- 수집 이력
              +-- 변경 이력
```

AWS 자원 목록과 구성은 AWS API로 수집한다. OS 내부의 PHP, Nginx 버전 및
디스크 사용량은 SSM 또는 제한된 에이전트로 수집한다.

### 권장 수집 주기

  데이터                  수집 주기
  ----------------------- -----------
  EC2 / RDS 상태          1\~5분
  EC2 / RDS 구성          15\~60분
  네트워크 및 보안 그룹   30\~60분
  도메인 DNS 레코드       1\~6시간
  SSL 만료일              6\~12시간
  백업 상태               1\~6시간
  OS / 런타임 버전        6\~24시간

계정·리전 단위로 수집을 분산하고 AWS API 호출 제한을 고려한다.

## 12. MySQL 테이블 설계 초안

### `infra_accounts`

AWS 계정 정보를 관리한다.

  컬럼             타입           설명
  ---------------- -------------- ----------------
  id               BIGINT         PK
  account_key      VARCHAR(50)    내부 계정 별칭
  aws_account_id   VARCHAR(20)    AWS 계정 ID
  display_name     VARCHAR(100)   화면 표시명
  is_active        TINYINT        사용 여부
  created_at       DATETIME       생성 시각
  updated_at       DATETIME       수정 시각

### `infra_resources`

AWS 자원 공통 정보를 관리한다.

  컬럼             타입           설명
  ---------------- -------------- ------------------------
  id               BIGINT         PK
  account_id       BIGINT         계정 FK
  region           VARCHAR(30)    리전
  resource_type    VARCHAR(30)    ec2 / rds / s3 등
  resource_id      VARCHAR(255)   AWS 자원 ID
  resource_name    VARCHAR(255)   자원명
  service_key      VARCHAR(100)   연결 서비스
  environment      VARCHAR(30)    prod / dev / stage
  status           VARCHAR(50)    AWS 상태
  metadata_json    JSON           비밀정보를 제외한 구성
  last_synced_at   DATETIME       마지막 수집
  created_at       DATETIME       생성 시각
  updated_at       DATETIME       수정 시각

권장 UNIQUE KEY: `(account_id, region, resource_type, resource_id)`

### `infra_resource_relations`

  컬럼                 타입          설명
  -------------------- ------------- -----------------------------------
  id                   BIGINT        PK
  source_resource_id   BIGINT        출발 자원
  target_resource_id   BIGINT        대상 자원
  relation_type        VARCHAR(50)   uses / attached_to / routes_to 등
  metadata_json        JSON          관계 부가정보
  created_at           DATETIME      생성 시각

### `infra_domains`

  컬럼              타입           설명
  ----------------- -------------- --------------
  id                BIGINT         PK
  domain_name       VARCHAR(255)   도메인
  service_key       VARCHAR(100)   서비스
  dns_provider      VARCHAR(50)    DNS 제공자
  record_type       VARCHAR(20)    A / CNAME 등
  record_value      TEXT           레코드 값
  ssl_expires_at    DATETIME       SSL 만료일
  last_checked_at   DATETIME       마지막 확인
  created_at        DATETIME       생성 시각
  updated_at        DATETIME       수정 시각

### `infra_sync_logs`

  컬럼            타입          설명
  --------------- ------------- ------------------
  id              BIGINT        PK
  resource_type   VARCHAR(30)   수집 대상
  account_id      BIGINT        계정
  region          VARCHAR(30)   리전
  status          VARCHAR(20)   success / failed
  item_count      INT           처리 건수
  error_message   TEXT          오류 요약
  started_at      DATETIME      시작
  finished_at     DATETIME      종료

오류 메시지에는 인증 토큰, Secret, 비밀번호 및 민감한 요청 헤더가
포함되지 않도록 마스킹한다.

## 13. PHP 구현 구조 예시

``` text
app/
  Controllers/
    InfrastructureController.php
    Ec2Controller.php
    RdsController.php
    NetworkController.php
    DomainController.php
  Services/
    Infrastructure/
      AwsClientFactory.php
      InfrastructureSyncService.php
      Ec2Service.php
      RdsService.php
      NetworkService.php
      Route53Service.php
      AcmService.php
      BackupService.php
  Repositories/
    InfrastructureRepository.php
    ResourceRelationRepository.php
  Commands/
    SyncInfrastructureCommand.php
```

AWS SDK for PHP를 사용한다. 여러 AWS 계정을 관리한다면 각 계정에 교차
계정 AssumeRole을 구성하고 읽기 전용 IAM 정책을 적용한다. 가능하면 장기
Access Key 대신 임시 자격 증명을 사용한다.

## 14. API 설계 예시

  Method   Endpoint                 설명
  -------- ------------------------ ------------------
  GET      `/api/infra/overview`    인프라 요약
  GET      `/api/infra/resources`   전체 자원 목록
  GET      `/api/infra/ec2`         EC2 목록
  GET      `/api/infra/ec2/{id}`    EC2 상세
  GET      `/api/infra/rds`         RDS 목록
  GET      `/api/infra/rds/{id}`    RDS 상세
  GET      `/api/infra/network`     네트워크 정보
  GET      `/api/infra/domains`     도메인 및 인증서
  GET      `/api/infra/backups`     백업 현황
  GET      `/api/infra/sync-logs`   수집 이력

초기 버전은 조회 API만 제공한다. 인스턴스 시작·중지, 보안 그룹 수정, DB
변경은 별도 승인 및 감사 로그 체계가 마련된 뒤 추가한다.

## 15. 모니터링 화면과 역할 구분

  -----------------------------------------------------------------------
  구분                    인프라 정보 화면        모니터링 화면
  ----------------------- ----------------------- -----------------------
  목적                    무엇이 어떻게 구성되어  현재 얼마나 정상적으로
                          있는가                  동작하는가

  서버                    인스턴스 ID, 유형, OS,  CPU, 메모리, 디스크
                          IP                      사용률

  DB                      엔진, 버전, 클래스,     연결 수, CPU, 지연,
                          엔드포인트              오류

  API                     연결 서비스 및 서버     평균 / P95 / P99
                          정보                    응답시간

  도메인                  DNS 레코드, 대상 서버   DNS 확인 및 SSL 만료
                                                  알림

  백업                    백업 정책 및 보존 기간  최근 성공·실패 및 알림
  -----------------------------------------------------------------------

## 16. 단계별 개발 순서

### 1단계: 자원 등록 및 기본 조회

-   AWS 계정 및 리전 등록
-   EC2 목록 및 상세
-   RDS 목록 및 상세
-   서비스와 자원 연결
-   인프라 개요 화면

### 2단계: 네트워크 및 도메인

-   VPC / Subnet / Security Group 조회
-   Route 53 레코드 조회
-   SSL 인증서 만료일 조회
-   자원 연결 관계 표시

### 3단계: 서버 내부 정보 및 백업

-   SSM 기반 OS / 런타임 정보 수집
-   EBS / S3 / RDS 백업 현황
-   수집 실패 알림 및 이력

### 4단계: 운영 고도화

-   자원 변경 감지
-   변경 이력 비교
-   계정별 권한 관리
-   인프라 구성도 자동 생성

## 17. 완료 기준

-   등록된 AWS 계정과 리전의 EC2 / RDS 자원이 조회된다.
-   자원 상세에서 실제 AWS 구성 정보를 확인할 수 있다.
-   서비스와 서버·DB의 연결 관계를 확인할 수 있다.
-   도메인과 SSL 인증서 만료일을 확인할 수 있다.
-   수집 실패 및 마지막 수집 시각을 확인할 수 있다.
-   비밀정보가 UI, DB 메타데이터, 로그에 노출되지 않는다.
-   인프라 조회 기능은 읽기 전용 IAM 권한으로 동작한다.
