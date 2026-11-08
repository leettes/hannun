# AWS 설정 (EC2·RDS 지표 조회용 IAM 역할)

한눈이 각 AWS 계정의 CloudWatch 에서 **EC2·RDS 지표**(CPU, 상태 검사, 연결 수 등)를 읽어 대시보드·서비스 상세 화면에 보여주기 위한 권한 설정과 비용 기준.
화면 표시 여부는 `.env` 의 `AWS_RESOURCES` (연동 전까지 `false`).

> 상태: 1단계 진행 중 (2026-11-08). 한눈 쪽 수집 기능(cron)과 AWS 계정 화면의 역할 ARN 입력란은 아직 개발 전이다.

## 진행 순서 (단계별)

한눈 서버는 **코이 서버**(프리즘넥서스 계정 `021837494749`, 서울)에 둔다 (2026-11-08 결정, 처음 계획은 우반시 계정이었음).
첫 연동 대상도 **코이**로 한다. 한눈 서버와 같은 계정이라 계정 간 설정 없이 확인할 수 있다. 코이가 되면 미리(드림다이브) 등 다른 계정을 하나씩 붙인다.

| 단계 | 어디서 | 할 일 | 상태 |
|---|---|---|---|
| 1 | 프리즘넥서스 콘솔 | 코이 서버 EC2 에 한눈용 권한 붙이기 (§1) | [ ] |
| 2 | 프리즘넥서스 콘솔 | 읽기 역할 `HannunCloudWatchRead` 만들기 (§2, 같은 계정) | [ ] |
| 3 | 코이 서버 터미널 | AWS CLI 로 코이 EC2·RDS 지표 조회 확인 (§3) | [ ] |
| 4 | 콘솔 → 한눈 | 코이의 EC2 인스턴스 ID·RDS 식별자를 한눈 서비스 관리에 등록 | [ ] |
| 5 | 코드 | AWS 계정 화면에 역할 ARN·External ID 입력란, CloudWatch 수집기 (권장 구성 기준) | [ ] |
| 6 | 한눈 화면 | `AWS_RESOURCES=true`, 코이 서비스 상세에서 지표 확인 | [ ] |
| 7 | 각 계정 콘솔 | 미리(드림다이브) → 나머지 계정에 §2 읽기 역할 추가, §4 표 채우기 | [ ] |

주의
- **한눈이 코이 서버 안에서 돌기 때문에 코이 서버가 죽으면 한눈도 같이 멈춰 코이 장애 문자를 못 받는다.** 코이 서버만은 바깥(다른 계정의 Uptime Kuma 등)에서 따로 감시하는 방법을 정해 둔다
- 코이 서버는 운영 서버다. 한눈·Uptime Kuma 가 쓰는 메모리(대략 200~300MB)를 감안하고, 기존 IAM 역할·IMDS 설정을 바꾸지 않는다 (§1)

## 개요

```
[프리즘넥서스 계정 021837494749 (코이 서버)]          [EC2·RDS 가 있는 계정 (프리즘넥서스 포함, 계정마다)]
코이 서버 EC2 ── 서버 역할 ── sts:AssumeRole ──▶ HannunCloudWatchRead ──▶ CloudWatch AWS/EC2·AWS/RDS·CWAgent 지표 (읽기)
 (한눈 실행)    (HannunServerRole                                     └──▶ ec2:Describe*, rds:DescribeDBInstances (목록·상태)
                 또는 기존 역할)
```

- **역할 위임 방식**: 서버에 액세스 키를 두지 않는다. 한눈 서버(EC2)에 붙인 역할이 계정마다 만든 읽기 역할을 빌려(AssumeRole) 임시 키로 조회한다. 키는 자동으로 바뀐다.
- 읽기 역할은 **CloudWatch 지표 읽기와 EC2·RDS 목록 조회만** 가능하다. 서버 접속, RDS 안의 데이터·로그에는 접근할 수 없다.
- RDS 는 CPUUtilization 을 따로 켜지 않아도 1분 단위로 CloudWatch 에 올린다 (기본 지표, 무료). Enhanced Monitoring·Performance Insights 는 필요 없다.
- EC2 는 CPU·상태 검사를 5분 단위로 올린다 (기본 모니터링, 무료). 메모리·디스크는 기본 지표가 없어 CloudWatch 에이전트가 필요하다 (아래 "권장 구성" 4).

## 비용

| 항목 | 비용 |
|---|---|
| EC2 기본 지표 (CPU, 상태 검사, 5분 간격) | 무료 |
| RDS 기본 지표 (CPU, 연결 수, 여유 메모리·스토리지, 1분 간격) | 무료 |
| `GetMetricStatistics` 조회 | 계정당 월 100만 건 무료, 이후 1,000건당 $0.01 |
| `GetMetricData` 조회 | 무료 구간 없음, 지표 1,000개당 $0.01 → **쓰지 않는다** |
| `ec2:DescribeInstances`·`DescribeInstanceStatus`, `rds:DescribeDBInstances` | 무료 |
| STS AssumeRole | 무료 |
| CloudWatch 에이전트 (메모리·디스크) | 에이전트는 무료, 올리는 지표가 사용자 지정 지표로 **지표 1개당 월 약 $0.30** |
| EC2 상세 모니터링 (1분 간격) | 인스턴스당 월 몇 달러 → **켜지 않는다** |
| CloudWatch 알람 (화면 표시에는 불필요) | 알람 1개당 월 $0.10 |

- 조회 건수: 지표 1개를 1분마다 조회하면 월 약 43,200건, 5분마다면 약 8,640건. 무료 한도(100만 건)는 **계정마다** 따로다
- 예: 계정 하나에 EC2 4대 × 지표 4개(CPU·상태 검사·메모리·디스크)를 5분마다 + RDS 1대 × 지표 4개를 1분마다 → 월 약 31만 건 → 0원
- 메모리·디스크를 모든 서버에 켜면 서버 1대당 월 약 $0.6 (지표 2개). 디스크를 여러 개 올리면 디스크마다 1개씩 늘어난다
- 단가는 바뀔 수 있으니 연동 전에 AWS CloudWatch 요금 페이지에서 확인한다 (2026-11-08 기준 정리)

## 권장 구성

목표: **계정당 월 0원**, 메모리·디스크를 볼 서버만 대당 월 약 $0.6.

1. **조회 API 는 `GetMetricStatistics` 만** 쓴다. `GetMetricData` 는 무료 구간이 없다
2. **조회 주기**: cron 은 1분마다 돌지만 지표 조회는 지표가 갱신되는 주기에 맞춘다
   | 대상 | 지표 (`check_results.metric_name`) | 조회 주기 | 기간(period) |
   |---|---|---|---|
   | EC2 | `CPUUtilization`, `StatusCheckFailed` | 5분 | 300초 |
   | EC2 (에이전트 설치 서버만) | `mem_used_percent`, `disk_used_percent` | 5분 | 300초 |
   | RDS | `CPUUtilization`, `DatabaseConnections`, `FreeableMemory`, `FreeStorageSpace` | 1~5분 | 60초 |
   - 이름은 화면이 읽는 이름과 같게 저장한다 (`src/Monitoring/DatabaseMonitoringRepository.php` 상수, `docs/DB.md` 지표 이름 표). RDS 여유 메모리·스토리지는 바이트를 비율(`freeable_memory_percent`, `free_storage_percent`)로 바꿔 저장
3. **EC2 상세 모니터링(1분)은 켜지 않는다**. 대시보드는 5분 간격이면 충분하다
4. **메모리·디스크는 필요한 서버에만** CloudWatch 에이전트를 설치한다 (운영 서버 등). 에이전트 설정은 아래 최소 설정만 올린다
   - 그 서버(각 계정의 EC2)의 인스턴스 역할에 AWS 관리형 정책 `CloudWatchAgentServerPolicy` 를 붙인다 (한눈 서버 역할과 별개)
   - 최소 설정 (`/opt/aws/amazon-cloudwatch-agent/etc/amazon-cloudwatch-agent.json`): 네임스페이스 `CWAgent`, 5분 간격, 메모리 사용률과 루트(`/`) 디스크 사용률 두 개만
     ```json
     {
       "metrics": {
         "namespace": "CWAgent",
         "append_dimensions": { "InstanceId": "${aws:InstanceId}" },
         "metrics_collected": {
           "mem":  { "measurement": ["mem_used_percent"], "metrics_collection_interval": 300 },
           "disk": { "measurement": ["used_percent"], "resources": ["/"], "metrics_collection_interval": 300, "drop_device": true }
         }
       }
     }
     ```
     에이전트는 디스크 지표를 `disk_used_percent` 이름으로 올린다. 다른 항목(cpu 세부, swap, net 등)을 켜면 그만큼 지표가 늘어 과금된다
5. **CloudWatch 알람은 만들지 않는다**. 장애 판정은 한눈이 조회 값으로 한다 (`src/Monitoring/Thresholds.php`)
6. **계정마다 예산 알림**: Billing → Budgets → 비용 예산 월 $5 (실제 금액 80% 도달 시 이메일). 예상 밖 과금을 바로 알 수 있다
7. 조회 실패(권한·식별자 오류)는 다음 조회에서 다시 시도하고, 같은 오류로 매분 호출하지 않도록 실패한 리소스는 조회 주기를 늘린다 (도메인·App Store 조회와 같은 방식)

## 기준

- 한눈 서버: **코이 서버 = 프리즘넥서스 AWS 계정(`021837494749`)의 EC2**. 아래 정책의 한눈 서버 계정 ID 는 모두 이 값으로 채워 두었다.
- 서버 역할 이름: 코이 서버에 역할이 없으면 새로 만든 `HannunServerRole`, 이미 있으면 **그 기존 역할 이름** (§1). 아래 `<SERVER_ROLE>` 자리에 넣는다
- 리전: `ap-northeast-2` (서울)

## 바꿔 넣을 값

| 자리표시 | 값 |
|---|---|
| `<SERVER_ROLE>` | 코이 서버 EC2 에 붙은 IAM 역할 이름 (`HannunServerRole` 또는 기존 역할) |
| `<EXTERNAL_ID>` | 임의의 긴 문자열. `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'` 결과. 모든 계정에 같은 값을 써도 된다 |

## 1. 한눈 서버 권한 (코이 서버 EC2)

**프리즘넥서스 계정(`021837494749`)에서 한 번만** 한다. 2단계보다 먼저 해야 한다 (읽기 역할의 신뢰 정책이 이 역할을 가리킨다).

> **EC2 한 대에는 IAM 역할을 하나만 붙일 수 있다.** 코이 앱이 이미 역할을 쓰고 있다면(S3 업로드 등) 그 역할을 바꾸는 순간 코이 앱이 깨질 수 있다. 그래서 먼저 확인하고 A·B 중 하나로 한다.

### 1-1. 지금 붙어 있는 역할 확인

EC2 → 인스턴스(서울) → 코이 서버 → 아래 **보안** 탭 → **IAM 역할**

### 1-2-A. 역할이 비어 있으면: 새 역할 `HannunServerRole`

1. IAM → 역할 → 역할 생성 → 신뢰할 수 있는 엔터티 **AWS 서비스 → EC2** (아래 신뢰 정책이 자동으로 들어간다)
2. 권한 추가는 건너뛰고 이름 `HannunServerRole` 로 생성
3. 생성된 역할 → 권한 추가 → **인라인 정책 생성** → JSON 에 아래 권한 정책, 이름 `AssumeHannunCloudWatchRead`
4. EC2 → 코이 서버 → 작업 → 보안 → **IAM 역할 수정** → `HannunServerRole`

신뢰 정책 (EC2 가 이 역할을 쓴다, 1번에서 자동):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": { "Service": "ec2.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }
  ]
}
```

### 1-2-B. 이미 역할이 있으면: 그 역할에 정책만 추가

1. 보안 탭의 역할 이름을 눌러 IAM 으로 이동
2. 권한 추가 → **인라인 정책 생성** → JSON 에 아래 권한 정책, 이름 `AssumeHannunCloudWatchRead`
3. **EC2 의 IAM 역할 설정은 바꾸지 않는다**

### 권한 정책 (A·B 공통)

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AssumeReadRoleInEachAccount",
      "Effect": "Allow",
      "Action": "sts:AssumeRole",
      "Resource": "arn:aws:iam::*:role/HannunCloudWatchRead"
    }
  ]
}
```

- 각 계정의 `HannunCloudWatchRead` 를 빌리는 권한만 준다. 기존 역할에 넣어도 코이 앱이 쓰던 권한에는 영향이 없다
- 계정을 고정하려면 `Resource` 를 `["arn:aws:iam::111111111111:role/HannunCloudWatchRead", ...]` 처럼 계정별로 나열한다

### 1-3. IMDSv2

작업 → 인스턴스 설정 → 인스턴스 메타데이터 옵션 수정
- 이미 **필수**면 그대로
- **선택**이면 지금은 바꾸지 않는다. 필수로 바꾸면 코이 앱의 오래된 AWS SDK 가 인증을 못 받을 수 있다. 바꾸려면 코이 앱 SDK 버전을 먼저 확인한다

### 1-4. 확인 (코이 서버 터미널)

```bash
sudo apt -y install awscli   # 없으면
aws sts get-caller-identity
# → "Arn": "arn:aws:sts::021837494749:assumed-role/<SERVER_ROLE>/i-..."
```

결과에 나온 역할 이름이 2단계 신뢰 정책의 `<SERVER_ROLE>` 이다.

## 2. 읽기 역할 `HannunCloudWatchRead`

EC2·RDS 가 있는 **계정마다** 만든다. 한눈 서버가 있는 **프리즘넥서스 계정에도 똑같이 만든다** (같은 계정이라도 이 역할을 거쳐 조회하면 모든 계정을 같은 방식으로 다룬다). 첫 대상은 프리즘넥서스(코이), 다음은 드림다이브(미리).

신뢰 정책 (한눈 서버 역할만, External ID 가 맞을 때만):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": { "AWS": "arn:aws:iam::021837494749:role/<SERVER_ROLE>" },
      "Action": "sts:AssumeRole",
      "Condition": {
        "StringEquals": { "sts:ExternalId": "<EXTERNAL_ID>" }
      }
    }
  ]
}
```

권한 정책 (인라인, 이름 예: `CloudWatchRdsRead`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ReadMetrics",
      "Effect": "Allow",
      "Action": [
        "cloudwatch:GetMetricStatistics",
        "cloudwatch:GetMetricData",
        "cloudwatch:ListMetrics"
      ],
      "Resource": "*"
    },
    {
      "Sid": "DescribeRds",
      "Effect": "Allow",
      "Action": "rds:DescribeDBInstances",
      "Resource": "*"
    },
    {
      "Sid": "DescribeEc2",
      "Effect": "Allow",
      "Action": [
        "ec2:DescribeInstances",
        "ec2:DescribeInstanceStatus"
      ],
      "Resource": "*"
    }
  ]
}
```

- CloudWatch 조회 API 는 리소스 단위 권한을 지원하지 않아 `"Resource": "*"` 가 표준이다. 읽기 전용이라 지표를 바꾸거나 지울 수 없다.
- `rds:DescribeDBInstances` 는 RDS 식별자·엔진·상태 확인용. CPU 만 볼 거면 `DescribeRds` 블록은 빼도 된다.
- `ec2:DescribeInstances`·`DescribeInstanceStatus` 는 EC2 인스턴스 유형·실행 상태 확인용. EC2 가 없는 계정은 `DescribeEc2` 블록을 빼도 된다.
- 이미 RDS 용으로 만든 역할이 있으면 이 권한 정책만 바꾸면 된다 (역할·신뢰 정책은 그대로).

콘솔 순서:

1. IAM → 역할 생성 → **사용자 지정 신뢰 정책** → 위 신뢰 정책 붙여 넣기
2. 권한 추가는 건너뛰고 이름 `HannunCloudWatchRead` 로 생성
3. 인라인 정책 → JSON 에 위 권한 정책
4. 역할 ARN `arn:aws:iam::<그 계정 ID>:role/HannunCloudWatchRead` 를 아래 표에 기록

## 3. 확인 (코이 서버 = 한눈 서버에서 AWS CLI)

```bash
# 1) 서버 역할이 붙었는지
aws sts get-caller-identity
# → "Arn": "arn:aws:sts::021837494749:assumed-role/<SERVER_ROLE>/i-..."

# 2) 계정별 읽기 역할 빌리기
aws sts assume-role \
  --role-arn arn:aws:iam::<계정 ID>:role/HannunCloudWatchRead \
  --role-session-name hannun-test \
  --external-id <EXTERNAL_ID>
# → AccessKeyId / SecretAccessKey / SessionToken 이 나오면 성공

# 3) 받은 임시 키로 RDS CPU 조회
export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... AWS_SESSION_TOKEN=...
aws cloudwatch get-metric-statistics --region ap-northeast-2 \
  --namespace AWS/RDS --metric-name CPUUtilization \
  --dimensions Name=DBInstanceIdentifier,Value=<RDS 식별자> \
  --start-time $(date -u -d '-15 min' +%FT%TZ) --end-time $(date -u +%FT%TZ) \
  --period 300 --statistics Average
# → Datapoints 에 Average 값이 나오면 권한 설정 완료

# 4) EC2 CPU (기본 지표 5분) / 메모리 (에이전트 설치 서버만)
aws cloudwatch get-metric-statistics --region ap-northeast-2 \
  --namespace AWS/EC2 --metric-name CPUUtilization \
  --dimensions Name=InstanceId,Value=<인스턴스 ID> \
  --start-time $(date -u -d '-30 min' +%FT%TZ) --end-time $(date -u +%FT%TZ) \
  --period 300 --statistics Average
aws cloudwatch get-metric-statistics --region ap-northeast-2 \
  --namespace CWAgent --metric-name mem_used_percent \
  --dimensions Name=InstanceId,Value=<인스턴스 ID> \
  --start-time $(date -u -d '-30 min' +%FT%TZ) --end-time $(date -u +%FT%TZ) \
  --period 300 --statistics Average
```

자주 나는 오류:

| 오류 | 원인 |
|---|---|
| `Unable to locate credentials` | 코이 서버 EC2 에 역할이 안 붙음, 또는 IMDS 가 꺼짐 |
| `AccessDenied ... sts:AssumeRole` | 읽기 역할 신뢰 정책의 계정 ID·`<SERVER_ROLE>` 오타, External ID 불일치, 서버 역할에 `AssumeHannunCloudWatchRead` 정책 누락 |
| `Datapoints: []` | RDS 식별자·인스턴스 ID 오타, 리전 다름, 기간이 너무 짧음 (`--period 300` 이상, 최근 15분 이상). EC2 메모리·디스크는 에이전트 미설치 또는 `CloudWatchAgentServerPolicy` 누락 |

## 4. 한눈에 넣을 값

계정별로 기록해 둔다. DB 의 `aws_accounts` 테이블에 `role_arn`, `external_id` 칸이 이미 있다 (AWS 계정 화면의 입력란은 수집 기능과 함께 추가 예정).

| 계정 (별칭) | 계정 ID | 역할 ARN | 완료 |
|---|---|---|---|
| 프리즘넥서스 (한눈 서버, 코이) — 1번째 | 021837494749 | `arn:aws:iam::021837494749:role/HannunCloudWatchRead` | [ ] |
| 드림다이브 (미리) — 2번째 | 765079047976 | `arn:aws:iam::765079047976:role/HannunCloudWatchRead` | [ ] |
| robustx | 911532748873 | `arn:aws:iam::911532748873:role/HannunCloudWatchRead` | [ ] |
| 우반시 | 851725350399 | `arn:aws:iam::851725350399:role/HannunCloudWatchRead` | [ ] |
| 가디언즈 | 637423544951 | `arn:aws:iam::637423544951:role/HannunCloudWatchRead` | [ ] |

External ID 는 비밀번호처럼 문서·저장소에 적지 말고 비밀번호 관리자에 둔다.

