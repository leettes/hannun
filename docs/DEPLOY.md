# 운영 서버 배포 (Ubuntu + micro 1대)

AWS t3.micro(또는 t2.micro, 메모리 1GB) 한 대에 **한눈(Nginx + PHP-FPM + SQLite)** 과 **Uptime Kuma(Docker)** 를 함께 올리는 방법.
Ubuntu 24.04 LTS 기준 (PHP 8.3 기본 패키지).

> 2026-11-08: 한눈은 전용 micro 대신 **코이 서버**(프리즘넥서스 계정)에 올리기로 했다. 아래 설치 순서는 그대로 쓰되, 운영 중인 코이 서버이므로 기존 Nginx 설정·IAM 역할·IMDS 를 바꾸지 않도록 주의한다 (Nginx 는 server 블록만 추가). AWS 권한은 [AWS.md](AWS.md) §1 (코이 서버 기준).

```
                 인터넷 (HTTPS 443)
                        │
                     Nginx ──────────────┬──────────────────────────┐
                        │                │                          │
 monitor.example.com    ▼                ▼  kuma.example.com        │
                   PHP-FPM (한눈)     127.0.0.1:3001 (Uptime Kuma, Docker)
                        │                ▲                          │
              storage/database.sqlite    │ /metrics 1분마다 (cron)   │
                        └────────────────┘                          │
                  Uptime Kuma Webhook → https://monitor.example.com/api/alerts/uptime-kuma
```

## 0. 메모리 예산과 주의점

| 항목 | 메모리 (대략) |
|---|---|
| Ubuntu 기본 | 150~250MB |
| Docker 데몬 | 50~100MB |
| Uptime Kuma (모니터 수십 개, SQLite) | 100~200MB |
| Nginx + PHP-FPM | 50~150MB |
| **합계** | **400~700MB / 1GB** → 스왑 필수 |

- **같은 서버의 한계**: 서버 자체가 죽으면 한눈과 Uptime Kuma가 함께 멈춰 아무도 알려주지 않는다. 이 서버는 외부에서 따로 감시한다 (EC2 상태 확인 CloudWatch 알람, 또는 UptimeRobot 같은 외부 무료 감시로 `https://monitor.example.com/health`).
- **t3 CPU 크레딧**: t3는 기본이 Unlimited 라 CPU를 오래 많이 쓰면 추가 요금이 나온다. 걱정되면 EC2 콘솔 → 인스턴스 → 크레딧 사양 변경에서 Standard 로.
- **보안 그룹**: 인바운드는 22(관리자 IP만), 80, 443 만 연다. 3001 은 열지 않는다.

## 1. 서버 준비

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Asia/Seoul

# 스왑 2GB
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
echo 'vm.swappiness=10' | sudo tee /etc/sysctl.d/99-swap.conf && sudo sysctl --system

# PHP·Nginx·SQLite (mbstring: 문자 길이 계산, curl: 웹/API 자동 구분, sqlite3: DB)
sudo apt -y install nginx php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-curl php8.3-xml \
  sqlite3 unzip git composer certbot python3-certbot-nginx

# Docker (Ubuntu 패키지)
sudo apt -y install docker.io docker-compose-v2
sudo systemctl enable --now docker
```

## 2. 한눈 설치

```bash
sudo mkdir -p /var/www/hannun && sudo chown $USER:www-data /var/www/hannun
git clone <저장소 주소> /var/www/hannun     # 또는 rsync 로 복사 (vendor, storage, .env, __bak 제외)
cd /var/www/hannun
composer install --no-dev --optimize-autoloader

cp .env.example .env
chmod 640 .env && sudo chgrp www-data .env

# storage 는 PHP-FPM(www-data)이 써야 한다 (SQLite 는 DB 파일이 있는 디렉터리에도 쓰기 권한 필요)
mkdir -p storage/logs
sudo chown -R www-data:www-data storage
# 앱 아이콘: cron(www-data)이 스토어에서 내려받아 저장하고 nginx 가 정적 파일로 보여 준다
sudo mkdir -p public/app-icons && sudo chown www-data:www-data public/app-icons
sudo chmod -R 770 storage

sudo -u www-data php bin/migrate.php
sudo -u www-data php bin/create-user.php --email=admin@example.com --name="관리자"
```

### .env 운영 값

| 키 | 운영 값 |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://monitor.example.com` (문자에 들어갈 주소) |
| `ALLOWED_IPS` | 사무실·VPN IP (쉼표 구분). **비우면 전부 차단**. Webhook(`/api/alerts/*`)과 `/health` 는 IP 제한 대상이 아니다 |
| `TRUSTED_PROXIES` | 비움 (앞단에 ALB 를 두면 ALB 대역) |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS) |
| `ALERT_WEBHOOK_TOKEN` | `php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'` 결과 |
| `UPTIME_KUMA_URL` | `http://127.0.0.1:3001` |
| `UPTIME_KUMA_API_KEY` | Uptime Kuma 설치 후 발급 (5단계) |
| `ALIGO_*` | 처음엔 `ALIGO_TESTMODE=true` 로 확인 후 `false`. 알리고에 이 서버의 공인 IP 등록 |
| `APP_PORT` | 운영에선 쓰지 않음 (로컬 docker compose 용) |

공인 IP 는 재부팅해도 바뀌지 않게 **탄력적 IP(Elastic IP)** 를 붙인다 (알리고 발송 IP 등록에 필요).

## 3. PHP-FPM (micro 용으로 줄이기)

`/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 30s
pm.max_requests = 500
```

`/etc/php/8.3/fpm/conf.d/99-hannun.ini`:

```ini
expose_php = Off
memory_limit = 128M
opcache.enable = 1
opcache.memory_consumption = 64
opcache.validate_timestamps = 1
```

```bash
sudo systemctl restart php8.3-fpm
```

## 4. Nginx + HTTPS

DNS 에 `monitor.example.com`, `kuma.example.com` A 레코드를 이 서버 IP 로 먼저 등록한다.

`/etc/nginx/sites-available/hannun`:

```nginx
server {
    listen 80;
    server_name monitor.example.com;
    root /var/www/hannun/public;
    index index.php;

    server_tokens off;
    client_max_body_size 2m;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location /assets/ {
        expires 30d;     # 파일은 ?v=수정시각 으로 캐시가 갱신된다
        access_log off;
    }

    location ~ /\. { deny all; }
}
```

`/etc/nginx/sites-available/kuma` (관리 화면은 IP 제한):

```nginx
server {
    listen 80;
    server_name kuma.example.com;
    server_tokens off;

    # 사무실·VPN IP 만
    allow 203.0.113.10;
    deny all;

    location / {
        proxy_pass http://127.0.0.1:3001;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;     # Uptime Kuma 는 WebSocket 사용
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/hannun /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/kuma /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d monitor.example.com -d kuma.example.com   # 443 설정·자동 갱신까지 해준다
```

## 5. Uptime Kuma (Docker)

`/opt/uptime-kuma/compose.yaml`:

```yaml
services:
  uptime-kuma:
    image: louislam/uptime-kuma:2
    container_name: uptime-kuma
    restart: unless-stopped
    ports:
      - "127.0.0.1:3001:3001"   # 반드시 127.0.0.1 — Docker 포트는 UFW 규칙을 우회해 외부에 열린다
    volumes:
      - ./data:/app/data
    mem_limit: 300m
    logging:
      driver: json-file
      options: { max-size: "10m", max-file: "3" }
```

```bash
sudo mkdir -p /opt/uptime-kuma && cd /opt/uptime-kuma
sudo docker compose up -d
sudo docker stats --no-stream uptime-kuma     # 메모리 확인
```

`https://kuma.example.com` 에 접속해:

1. 첫 화면에서 DB 는 **SQLite** 선택 (내장 MariaDB 는 micro 에 무겁다), 관리자 계정 생성.
2. 설정 → 모니터 기록: 보관 기간 **7~30일**.
3. 모니터 추가: 서비스별 웹/API 주소, 점검 주기 **60초**, 재시도 2~3회.
4. 설정 → API 키 → 발급한 키를 한눈 `.env` 의 `UPTIME_KUMA_API_KEY` 에.
5. 설정 → 알림 → **Webhook** 추가 (자세한 건 [ALERTS.md](ALERTS.md))
   - Post URL: `https://monitor.example.com/api/alerts/uptime-kuma` (컨테이너 안에서 `localhost` 는 컨테이너 자신이라 공개 주소를 쓴다)
   - 요청 본문 `application/json`, 추가 헤더 `{"Authorization": "Bearer <ALERT_WEBHOOK_TOKEN>"}`
   - 각 모니터에 이 알림과 기존 이메일 등 자체 알림을 함께 켠다.

한눈의 **서비스 관리**에 등록한 웹/API 리소스 주소가 모니터 URL 과 같으면 자동 연결된다. 다르면 리소스에 모니터 ID 를 넣는다.

## 6. cron

```bash
sudo crontab -u www-data -e
```

```cron
* * * * * cd /var/www/hannun && php bin/cron.php >> storage/logs/cron.log 2>&1
15 3 * * * /var/www/hannun/bin/backup.sh >> /var/www/hannun/storage/logs/backup.log 2>&1
```

몇 분 뒤 `tail storage/logs/cron.log` 로 수집 결과, 연결되지 않은 모니터를 확인한다.

로그 정리 `/etc/logrotate.d/hannun`:

```
/var/www/hannun/storage/logs/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

## 7. 백업

SQLite 는 쓰는 중에 파일을 그대로 복사하면 깨질 수 있으므로 `bin/backup.sh` 가 `sqlite3 .backup` 으로 뜬다.
`/var/backups/hannun/hannun-YYYYMMDD.sqlite.gz` 로 남기고 14일 지난 것은 지운다 (`BACKUP_DIR`, `KEEP_DAYS` 환경변수로 변경).

```bash
sudo mkdir -p /var/backups/hannun && sudo chown www-data /var/backups/hannun
sudo -u www-data /var/www/hannun/bin/backup.sh     # 한 번 직접 실행해 확인
```

복원: `gunzip -c hannun-20261105.sqlite.gz > storage/database.sqlite` (cron·PHP-FPM 잠시 멈춘 상태에서, 소유자 www-data 확인).

- Uptime Kuma 는 `/opt/uptime-kuma/data` 를 주 1회 정도 보관 (설정만 있으면 다시 만들 수 있으므로 덜 중요).
- 서버가 통째로 사라지는 경우 대비: EBS 스냅샷 수명 주기 정책(Data Lifecycle Manager)으로 매일 스냅샷 7개 보관, 또는 백업 파일을 S3 로 복사.
- `.env` 는 저장소에 없으므로 별도로 안전하게 보관.

## 8. 업데이트

```bash
cd /var/www/hannun
git pull                                   # 또는 rsync
composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/migrate.php       # 새 마이그레이션 적용 (예: 008~010)
sudo systemctl reload php8.3-fpm           # opcache 비우기

# Uptime Kuma
cd /opt/uptime-kuma && sudo docker compose pull && sudo docker compose up -d && sudo docker image prune -f
```

메모리가 부족하면 `composer install` 이 죽을 수 있다. 스왑이 켜져 있는지(`free -h`) 확인한다.

## 9. 확인 목록

- [ ] `curl -s https://monitor.example.com/health` 응답
- [ ] 허용하지 않은 IP 에서 대시보드 접속 시 403, `kuma.example.com` 도 403
- [ ] `curl -s http://<서버 공인 IP>:3001` 이 연결되지 않음 (127.0.0.1 바인딩 확인)
- [ ] 로그인 → 대시보드에 서비스·마지막 점검 시각 표시 (cron 동작)
- [ ] Webhook 테스트: [ALERTS.md](ALERTS.md) 의 '확인' curl 이 `{"result":"ignored"}`
- [ ] `ALIGO_TESTMODE=true` 상태로 장애 발생·복구 → `notifications` 테이블에 `sent`
- [ ] 사용자별 장애 문자 범위(안함/전체 서비스/담당 서비스)와 휴대폰 번호 확인
- [ ] `ALIGO_TESTMODE=false` 로 바꾸고 실제 문자 1회 확인
- [ ] 다음 날 `/var/backups/hannun` 에 백업 파일 생성
- [ ] 이 서버 자체에 대한 외부 감시(CloudWatch 상태 확인 알람 등) 설정
- [ ] `free -h`, `docker stats` 로 메모리 여유 확인
