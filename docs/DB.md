# DB 설계

SQLite(WAL), 시간은 KST `YYYY-MM-DD HH:MM:SS` 텍스트로 저장. 스키마는 `database/migrations/*.sql`, 적용은 `composer migrate`.

## 관계

```
aws_accounts 1 ─── N systems 1 ─── N resources 1 ─── N check_results
                       │                 │
                       └──── N incidents N┘ (resource_id는 선택)
                                 │
                                 ├── N incident_events
                                 └── N alert_events (원본 알림)
users N ─── N systems (system_owners: 서비스 담당자, 여러 명)
users ── incidents.assignee / incident_events.user
```

## 테이블

| 테이블 | 용도 | 주요 컬럼 |
|---|---|---|
| `users` | 관리자 계정 | `email`, `password_hash`, `role`(admin/operator/viewer), `phone`, `sms_scope`(none/all/owned, 장애 문자 범위) |
| `aws_accounts` | AssumeRole 대상 계정 | `account_id`, `role_arn`, `external_id`, `default_region` |
| `companies` | 관리회사 (AWS 계정을 관리·결제하는 법인). `aws_accounts.company_id`·`systems.company_id`(서비스 직접 지정, 우선) 가 참조, 삭제하지 않음 | `name`(법인명), `ceo_name`, `business_no`(숫자 10자리, 유니크), `notes` |
| `app_store_accounts` | 개발자 계정 (Apple / Google). 비밀번호·키 없음, 삭제 대신 비활성화 | `platform`, `name`, `team_id`, `company_id`, `membership_expires_at` |
| `apps` | 서비스별 앱 (iOS / Android / 웹앱). 관리회사 = 계정의 관리회사, 없으면 서비스의 관리회사 | `system_id`, `platform`, `bundle_id`(플랫폼별 유니크), `account_id`, `version`·`released_at`(iOS 는 App Store 자동, `store_info`·`checked_at`·`store_error`), `icon_file`(public/app-icons/, cron 이 7일마다 갱신) |
| `app_store_daily_stats` | 앱(플랫폼 행)별 일별 스토어 통계. NULL=미제공, 0=실제 0. 수집기 연동 전 (docs/APPS.md 5단계, docs/app-manager-store-integration-spec.md 5.4) | UNIQUE(`app_id`, `stat_date`, `source`), `downloads`, `installs`, `active_users`, `revenue_gross`·`refunds_amount`·`currency`, `store_rating`·`store_rating_count`, `review_average`·`review_count`, `rating_1~5`, `metric_definition`, `collected_at`. 화면은 같은 날 여러 출처면 큰 값 하나만 사용 |
| `app_store_reviews` | 스토어 리뷰·개발자 답변. 리뷰 ID 로 중복 없이 쌓고 수정되면 덮어씀. 수집기 연동 전 (명세서 5.5) | UNIQUE(`app_id`, `external_review_id`), `rating`(1~5), `title`, `content`, `reviewer_name`, `territory`, `language`, `app_version`, `review_created_at`·`review_updated_at`, `developer_reply`·`developer_reply_at`, `raw_data`(JSON) |
| `systems` | 관리 대상 5개 시스템 | `code`, `name`, `environment` (`owner_user_id`는 008 이후 쓰지 않음) |
| `system_owners` | 서비스 담당자 (서비스마다 여러 명, 장애 문자 수신) | `system_id`, `user_id` |
| `resources` | 시스템별 EC2 / RDS / HTTP | `type`, `identifier`, `external_ref`(Uptime Kuma id), `config`(JSON), `current_status` |
| `domain_overrides` | 도메인 정보 수동 입력 (등록 도메인마다 하나). 값이 있는 항목은 `resources.domain_info`(자동 수집) 대신 표시, cron 은 건드리지 않음 | `domain`, `registrar`, `expires_at`, `nameservers`(JSON), `memo` |
| `check_results` | 점검 결과·지표 스냅샷 | `source`, `status`, `metric_name`, `metric_value`, `response_ms`, `checked_at` |
| `incidents` | 장애 센터 | `alert_key`, `severity`, `status`, `started_at`, `resolved_at`, `assignee_user_id`, `action_taken`, `dismissed_at`·`dismissed_by_user_id`(대시보드 최근 장애에서 확인) |
| `incident_events` | 장애 타임라인 | `type`(opened/alert/acknowledged/note/assigned/resolved/reopened), `message` |
| `alert_events` | Webhook/SNS 원본 수신 로그 | `source`, `external_id`, `payload`(JSON), `processed_at` |

## 설계 메모

- **최근 장애 확인** (`IncidentActions::dismiss`): 대시보드 '최근 장애'에서 운영자가 확인하면 `dismissed_at`을 채워 그 목록에서만 뺀다. 장애 상태는 바꾸지 않고 장애 센터에는 그대로 나온다. 사유(선택, 500자)는 `incident_events`에 note로 남긴다. 장애를 다시 열면 확인이 풀린다.

- **중복 알림 방지**: 알림 수신 시 `alert_key` + `status != 'resolved'` 인 장애가 있으면 새 장애를 만들지 않고 `incident_events`에 `alert` 이벤트만 추가한다.
- **화면에서의 장애 처리** (`IncidentActions`): 상태는 진행 중 → 확인됨 → 복구됨, 복구됨 → 진행 중(다시 열기)만 허용. 복구 시 `action_taken` 필수. 담당자는 운영자 이상 활성 계정. 변경마다 `incident_events`(assigned/acknowledged/resolved/reopened/note)에 작성자와 함께 기록. 폼은 화면에서 본 값을 함께 보내고, 그 사이 DB 값이 바뀐 필드는 덮어쓰지 않고 거부한다(메모만 추가는 항상 가능).
- **중복 수신 방지**: `alert_events (source, external_id)` 유니크. SNS `MessageId` 등을 넣는다.
- **현재 상태 조회**: 대시보드 화면은 `resources.current_status` / `last_checked_at`을 읽고, 이력은 `check_results`에서 조회한다.
- **지표 이름(`check_results.metric_name`)**: 대시보드는 아래 이름의 최신 값을 읽는다 (`DatabaseMonitoringRepository` 상수). 추이 그래프는 최근 24개 값.

  | 리소스 | metric_name | 값 |
  |---|---|---|
  | http | `http` | `status`(up/down), `response_ms`. 24시간 가용률은 이 행들의 up 비율 |
  | http | `ssl_days_left` | 점검 시점의 인증서 남은 일수 |
  | ec2 | `CPUUtilization`, `mem_used_percent`, `disk_used_percent` | % |
  | ec2 | `StatusCheckFailed` | 0 정상 / 1 이상 |
  | rds | `CPUUtilization` | % |
  | rds | `DatabaseConnections` | 연결 수 (`max_connections`는 `resources.config`) |
  | rds | `freeable_memory_percent`, `free_storage_percent` | 전체 대비 여유 % (수집기가 바이트를 비율로 변환) |

- **리소스 고정 정보(`resources.config`)**: ec2 `{"instance_type"}`, rds `{"engine", "max_connections"}`.
- **보관 주기**: `check_results`는 빠르게 증가하므로 일정 기간(예: 90일) 이후 삭제 또는 집계 테이블로 이관한다. (5단계에서 확정)
