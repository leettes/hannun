# AWS 설정 (RDS CPU 조회용 IAM 역할)

한눈이 각 AWS 계정의 CloudWatch 에서 **RDS `CPUUtilization`** 을 읽어 서비스 상세 화면에 보여주기 위한 권한 설정.

> 상태: 권한 설정 문서. 한눈 쪽 수집 기능(cron)과 AWS 계정 화면의 역할 ARN 입력란은 아직 개발 전이다.

## 개요

```
[우반시 계정 851725350399]                               [RDS 계정 (우반시 포함, 계정마다)]
한눈 EC2 ── HannunServerRole ── sts:AssumeRole ──▶ HannunCloudWatchRead ──▶ CloudWatch AWS/RDS CPUUtilization (읽기)
```

- **역할 위임 방식**: 서버에 액세스 키를 두지 않는다. 한눈 서버(EC2)에 붙인 역할이 계정마다 만든 읽기 역할을 빌려(AssumeRole) 임시 키로 조회한다. 키는 자동으로 바뀐다.
- 읽기 역할은 **CloudWatch 지표 읽기만** 가능하다. RDS 안의 데이터·로그에는 접근할 수 없다.
- RDS 는 CPUUtilization 을 따로 켜지 않아도 1분 단위로 CloudWatch 에 올린다 (기본 지표, 무료). Enhanced Monitoring·Performance Insights 는 필요 없다.

## 비용

| 항목 | 비용 |
|---|---|
| RDS → CloudWatch 기본 지표 | 무료 |
| `GetMetricStatistics` 조회 | 계정당 월 100만 건 무료, 이후 1,000건당 $0.01 |
| `GetMetricData` 조회 | 무료 구간 없음, 지표 1,000개당 $0.01 |
| STS AssumeRole | 무료 |
| CloudWatch 알람 (화면 표시에는 불필요) | 알람 1개당 월 $0.10 |

RDS 1개를 1분마다 조회하면 월 약 43,200건 → `GetMetricStatistics` 로 조회하면 무료 한도 안(0원). 단가는 바뀔 수 있으니 AWS CloudWatch 요금 페이지에서 확인한다.

## 기준

- 한눈 서버: **우반시 AWS 계정(`851725350399`)의 EC2**. 아래 정책의 한눈 서버 계정 ID 는 모두 이 값으로 채워 두었다.
- 리전: `ap-northeast-2` (서울)

## 바꿔 넣을 값

| 자리표시 | 값 |
|---|---|
| `<EXTERNAL_ID>` | 임의의 긴 문자열. `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'` 결과. 모든 계정에 같은 값을 써도 된다 |

## 1. 한눈 서버 역할 `HannunServerRole`

**우반시 계정(`851725350399`)에 한 번만** 만든다. 2단계보다 먼저 만들어야 한다 (읽기 역할의 신뢰 정책이 이 역할을 가리킨다).

신뢰 정책 (EC2 가 이 역할을 쓴다):

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

권한 정책 (인라인, 이름 예: `AssumeHannunCloudWatchRead`):

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

계정을 고정하려면 `Resource` 를 `["arn:aws:iam::111111111111:role/HannunCloudWatchRead", ...]` 처럼 계정별로 나열한다.

콘솔 순서:

1. IAM → 역할 → 역할 생성 → 신뢰할 수 있는 엔터티 **AWS 서비스 → EC2** (위 신뢰 정책이 자동으로 들어간다)
2. 권한 추가는 건너뛰고 이름 `HannunServerRole` 로 생성
3. 생성된 역할 → 권한 추가 → **인라인 정책 생성** → JSON 에 위 권한 정책
4. EC2 (우반시 계정, 서울 리전) → 한눈 인스턴스 → 작업 → 보안 → **IAM 역할 수정** → `HannunServerRole`
5. 작업 → 인스턴스 설정 → 인스턴스 메타데이터 옵션 수정 → **IMDSv2 필수**

## 2. 읽기 역할 `HannunCloudWatchRead`

RDS 가 있는 **계정마다** 만든다. 한눈 서버가 있는 **우반시 계정에도 똑같이 만든다** (같은 계정이라도 이 역할을 거쳐 조회하면 모든 계정을 같은 방식으로 다룬다).

신뢰 정책 (한눈 서버 역할만, External ID 가 맞을 때만):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": { "AWS": "arn:aws:iam::851725350399:role/HannunServerRole" },
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
    }
  ]
}
```

- CloudWatch 조회 API 는 리소스 단위 권한을 지원하지 않아 `"Resource": "*"` 가 표준이다. 읽기 전용이라 지표를 바꾸거나 지울 수 없다.
- `rds:DescribeDBInstances` 는 RDS 식별자·엔진·상태 확인용. CPU 만 볼 거면 `DescribeRds` 블록은 빼도 된다.

콘솔 순서:

1. IAM → 역할 생성 → **사용자 지정 신뢰 정책** → 위 신뢰 정책 붙여 넣기
2. 권한 추가는 건너뛰고 이름 `HannunCloudWatchRead` 로 생성
3. 인라인 정책 → JSON 에 위 권한 정책
4. 역할 ARN `arn:aws:iam::<그 계정 ID>:role/HannunCloudWatchRead` 를 아래 표에 기록

## 3. 확인 (한눈 서버에서 AWS CLI)

```bash
# 1) 서버 역할이 붙었는지
aws sts get-caller-identity
# → "Arn": "arn:aws:sts::851725350399:assumed-role/HannunServerRole/i-..."

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
```

자주 나는 오류:

| 오류 | 원인 |
|---|---|
| `Unable to locate credentials` | EC2 에 `HannunServerRole` 이 안 붙음, 또는 IMDS 가 꺼짐 |
| `AccessDenied ... sts:AssumeRole` | 읽기 역할 신뢰 정책의 계정 ID·역할 이름 오타, External ID 불일치, 서버 역할 권한 정책 누락 |
| `Datapoints: []` | RDS 식별자 오타, 리전 다름, 기간이 너무 짧음 (`--period 300` 이상, 최근 15분 이상) |

## 4. 한눈에 넣을 값

계정별로 기록해 둔다. DB 의 `aws_accounts` 테이블에 `role_arn`, `external_id` 칸이 이미 있다 (AWS 계정 화면의 입력란은 수집 기능과 함께 추가 예정).

| 계정 (별칭) | 계정 ID | 역할 ARN | 완료 |
|---|---|---|---|
| robustx | 911532748873 | `arn:aws:iam::911532748873:role/HannunCloudWatchRead` | [ ] |
| 프리즘넥서스 | 021837494749 | `arn:aws:iam::021837494749:role/HannunCloudWatchRead` | [ ] |
| 드림다이브 | 765079047976 | `arn:aws:iam::765079047976:role/HannunCloudWatchRead` | [ ] |
| 우반시 (한눈 서버) | 851725350399 | `arn:aws:iam::851725350399:role/HannunCloudWatchRead` | [ ] |
| 가디언즈 | 637423544951 | `arn:aws:iam::637423544951:role/HannunCloudWatchRead` | [ ] |

External ID 는 비밀번호처럼 문서·저장소에 적지 말고 비밀번호 관리자에 둔다.

