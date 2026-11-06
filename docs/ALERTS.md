# 외부 알림 연동

Uptime Kuma 점검 결과와 CloudWatch 알람을 대시보드 장애 센터로 받고, 장애를 문자로 알리는 방법. 대시보드가 멈춰도 각 도구의 자체 알림(이메일 등)은 그대로 동작해야 하므로, 아래 설정은 **기존 알림에 추가**한다.

| 경로 | 보내는 쪽 | 인증 |
|---|---|---|
| `POST /api/alerts/uptime-kuma` | Uptime Kuma Webhook | `Authorization: Bearer <ALERT_WEBHOOK_TOKEN>` |
| `POST /api/alerts/sns` | CloudWatch 알람 → SNS HTTPS 구독 | SNS 메시지 서명 + `SNS_TOPIC_ARNS` |

두 경로는 로그인·IP 제한에서 제외된다(SNS 발신 IP가 고정되지 않음). 설정값이 비어 있으면 503으로 거부한다.

## 처리 규칙

- 원본은 `alert_events`에 저장한다. 같은 알림(SNS `MessageId`, Uptime Kuma 하트비트)을 다시 받으면 무시한다. 처리 중 오류가 난 알림은 재전송 시 다시 처리한다.
- 장애 발생 알림: 같은 `alert_key`로 진행 중인 장애가 있으면 타임라인에 '알림 반복'만 추가하고, 없으면 새 장애를 연다.
- 복구 알림: 진행 중인 장애를 자동으로 복구 처리한다. 그 리소스에 남은 장애가 없으면 리소스 상태를 정상으로 돌린다.
- 비활성화한 서비스·리소스는 대상에서 빠진다.
- 대상 리소스를 찾지 못한 알림은 `alert_events.error`에 이유를 남기고 장애는 만들지 않는다.

| 알림 | 장애 심각도 | 리소스 상태 | alert_key |
|---|---|---|---|
| Uptime Kuma DOWN | 심각 | 장애 | `uptime_kuma:http:<resource id>` |
| Uptime Kuma UP | (복구) | 정상 | 〃 |
| Uptime Kuma 인증서 만료 예정 | 정보 | 경고 | `uptime_kuma:ssl:<resource id>` (갱신 후 수동 복구) |
| CloudWatch `StatusCheckFailed*` ALARM | 심각 | 장애 | `cloudwatch:<계정 ID>:<알람 이름>` |
| CloudWatch 그 외 ALARM | 높음 | 경고 | 〃 |
| CloudWatch OK | (복구) | | 〃 |
| CloudWatch INSUFFICIENT_DATA | 무시 | | |

## cron

지표 수집과 문자 재발송은 1분마다 도는 `bin/cron.php`가 한다.

```
* * * * * cd /var/www/html && php bin/cron.php >> storage/logs/cron.log 2>&1
```

## Uptime Kuma 지표 수집 (API 모니터링)

웹/API 점검 자체는 Uptime Kuma가 하고(점검 주기·재시도 횟수도 Uptime Kuma 모니터 설정), 대시보드는 `/metrics`를 1분마다 가져온다.

1. Uptime Kuma → 설정 → API 키에서 키를 발급해 `.env`의 `UPTIME_KUMA_URL`(예: `http://uptime-kuma:3001`), `UPTIME_KUMA_API_KEY`에 넣는다.
2. 매 실행마다 HTTP 점검 1건(상태, 응답 시간)을 `check_results`에 남긴다 → 24시간 가용률·응답 시간 추이. SSL 남은 일수는 하루 한 번.
3. 리소스 상태: DOWN → 장애, PENDING(재시도 중)·응답 2초 이상·인증서 30일 이내 → 경고, MAINTENANCE → 알 수 없음.
4. 상태가 장애로 바뀌면 장애를 열고, 벗어나면 복구한다. Webhook과 같은 `alert_key`를 쓰므로 둘 다 켜도 장애가 두 번 생기지 않는다(먼저 온 쪽이 열고, 나중 쪽은 타임라인에 추가).
5. 먼저 **서비스 관리**(`/settings/systems`)에서 서비스를 만들고 웹/API 리소스를 추가한다. 모니터와 리소스 연결은 아래 Webhook과 같다(URL 또는 `external_ref` = 모니터 ID, 모니터 ID는 Uptime Kuma 2.x부터 지표에 포함). 연결되지 않은 모니터는 `cron.log`에 나온다.

## 문자 알림 (알리고)

- **보내는 경우**: 심각·높음 장애가 새로 열릴 때, 그 장애가 복구될 때(자동 복구와 장애 센터의 수동 복구 모두). 반복 알림, 보통·정보 등급(SSL 만료 예정 등)은 보내지 않는다.
- **받는 사람**: 사용자별 '장애 문자 알림'(`users.sms_scope`)으로 정한다. 활성 계정이고 휴대폰 번호가 있어야 한다.
  - **전체 서비스** (`all`): 모든 서비스 장애. 운영자 이상만 고를 수 있다. 관리자 기본값.
  - **담당 서비스** (`owned`): 담당자로 지정된 서비스(`system_owners`)만. 운영자·조회 기본값.
  - **안함** (`none`): 받지 않는다 (담당자여도).
- **설정**: `.env`의 `ALIGO_API_KEY`, `ALIGO_USER_ID`, `ALIGO_SENDER`(알리고에 등록한 발신번호). 알리고 관리 화면에 발송 서버 IP도 등록해야 한다. 처음에는 `ALIGO_TESTMODE=true`로 확인한다.
- **기록·재발송**: 모든 알림은 `notifications` 테이블에 남는다. 장애를 받은 요청에서 바로 보내고, 실패하면 cron이 최대 3회까지 다시 보낸다. 설정이 없거나 수신자가 없으면 `skipped`와 이유를 남긴다.

```bash
sqlite3 storage/database.sqlite "SELECT id, kind, status, attempts, recipients, error, created_at FROM notifications ORDER BY id DESC LIMIT 20"
```

## Uptime Kuma Webhook

지표 수집(1분 주기)보다 빨리 반영하고, 장애 메시지(오류 내용)와 인증서 만료 예정 알림을 받으려면 Webhook도 켠다.

1. 토큰을 만들어 `.env`의 `ALERT_WEBHOOK_TOKEN`에 넣는다: `php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'`
2. Uptime Kuma → 설정 → 알림 → 알림 추가
   - 알림 유형: **Webhook**, Post URL: `https://<대시보드 주소>/api/alerts/uptime-kuma`
   - 요청 본문: **application/json**
   - 추가 헤더: `{"Authorization": "Bearer <토큰>"}`
3. 각 모니터에 이 알림을 켠다.
4. 모니터와 대시보드 리소스를 연결한다. 서비스 관리에서 등록한 웹/API 리소스의 점검 주소가 모니터 URL과 같으면 자동으로 연결되고, 다르면 리소스의 'Uptime Kuma 모니터 ID'에 모니터 ID(모니터 주소창의 `/dashboard/<ID>`)를 넣는다.


## CloudWatch (SNS) — AWS 연동 단계에서 사용

1. 알람용 SNS 토픽(예: `monitoring-alarms`)에 기존 이메일 구독은 그대로 두고 **HTTPS 구독**을 추가한다.
   - 엔드포인트: `https://<대시보드 주소>/api/alerts/sns` (HTTPS 필수, 공인 인증서)
   - 원시 메시지 전송(raw message delivery)은 **끈다**(서명 검증에 SNS 봉투가 필요).
2. `.env`의 `SNS_TOPIC_ARNS`에 토픽 ARN을 넣는다(여러 개면 쉼표). 구독 확인은 대시보드가 자동으로 한다. 이 설정 전에 구독을 만들었다면 콘솔에서 '구독 확인 요청'을 다시 보낸다.
3. 각 CloudWatch 알람의 ALARM/OK 작업에 이 토픽을 지정한다. 알람은 차원으로 리소스를 찾는다: EC2는 `InstanceId`, RDS는 `DBInstanceIdentifier`가 서비스 관리에 등록한 인스턴스 ID / DB 식별자와 같아야 한다.
4. 여러 AWS 계정이면 계정마다 토픽을 만들어 ARN을 모두 넣거나, 한 토픽에 교차 계정 게시 권한을 준다.

## 확인

```bash
curl -X POST https://<대시보드 주소>/api/alerts/uptime-kuma \
  -H 'Authorization: Bearer <토큰>' -H 'Content-Type: application/json' \
  -d '{"heartbeat":null,"monitor":null,"msg":"test"}'
# {"result":"ignored"}  (Uptime Kuma 알림 설정의 '테스트' 버튼도 같은 결과)
```

받은 알림과 처리 결과는 `alert_events` 테이블에서 본다:

```bash
sqlite3 storage/database.sqlite "SELECT id, source, received_at, processed_at, error FROM alert_events ORDER BY id DESC LIMIT 20"
```
