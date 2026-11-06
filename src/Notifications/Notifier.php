<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Database;
use App\Env;
use App\Monitoring\Incident;
use Closure;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * 장애 문자 알림.
 *
 * 장애를 바꾸는 트랜잭션 안에서 queue() 로 notifications 에 넣고, 커밋 후 dispatch() 가 발송한다.
 * 발송이 실패하면 대기열에 남아 다음 dispatch() (cron 포함) 때 다시 보낸다.
 */
final class Notifier
{
    /** 문자를 보내는 심각도 */
    public const SEVERITIES = ['critical', 'major'];

    public const MAX_ATTEMPTS = 3;

    /** 발송 중 상태로 이 시간(초) 넘게 멈춘 건은 다시 보낸다 (프로세스 중단 등) */
    private const STALE_SENDING = 300;

    /**
     * @param Closure(): PDO $connection
     * @param SmsSender|null $sms null 이면 발송하지 않고 skipped 로 기록
     * @param string $appUrl 문자에 넣을 대시보드 주소 (비우면 생략)
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly ?SmsSender $sms,
        private readonly string $appUrl = '',
    ) {
    }

    /**
     * .env 의 ALIGO_* 설정으로 만든다. 설정이 없으면 기록만 한다.
     *
     * @param Closure(): PDO $connection
     */
    public static function fromEnv(Closure $connection): self
    {
        $key = Env::get('ALIGO_API_KEY', '') ?? '';
        $sms = $key === '' ? null : new AligoSms(
            $key,
            Env::get('ALIGO_USER_ID', '') ?? '',
            (string) preg_replace('/\D/', '', Env::get('ALIGO_SENDER', '') ?? ''),
            filter_var(Env::get('ALIGO_TESTMODE', 'false'), FILTER_VALIDATE_BOOL),
            AligoSms::httpPost(...),
        );

        return new self($connection, $sms, rtrim(Env::get('APP_URL', '') ?? '', '/'));
    }

    /**
     * 장애 발생/복구 알림을 대기열에 넣는다. 호출한 쪽의 트랜잭션 안에서 실행한다.
     * 심각도가 SEVERITIES 가 아니면 아무것도 하지 않는다.
     */
    public function queue(PDO $pdo, int $incidentId, string $kind): void
    {
        $statement = $pdo->prepare(
            'SELECT i.id, i.system_id, i.severity, i.title, i.started_at, i.resolved_at, s.name AS system_name
             FROM incidents i JOIN systems s ON s.id = i.system_id WHERE i.id = ?'
        );
        $statement->execute([$incidentId]);
        $incident = $statement->fetch(PDO::FETCH_ASSOC);
        if ($incident === false || !in_array($incident['severity'], self::SEVERITIES, true)) {
            return;
        }

        $recipients = $this->recipients($pdo, (int) $incident['system_id']);
        $now = Database::now();
        $pdo->prepare(
            'INSERT INTO notifications (incident_id, kind, recipients, message, status, error, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $incidentId,
            $kind,
            implode(',', $recipients),
            $this->message($incident, $kind),
            $recipients === [] ? 'skipped' : 'pending',
            $recipients === [] ? '수신자 없음 (문자 범위와 휴대폰 번호 확인)' : null,
            $now,
            $now,
        ]);
    }

    /**
     * 대기 중인 알림을 보낸다.
     *
     * @return array{sent: int, failed: int, skipped: int}
     */
    public function dispatch(int $limit = 20): array
    {
        $pdo = ($this->connection)();
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0];

        $pdo->prepare("UPDATE notifications SET status = 'pending', updated_at = ? WHERE status = 'sending' AND updated_at < ?")
            ->execute([Database::now(), date('Y-m-d H:i:s', time() - self::STALE_SENDING)]);

        $pending = $pdo->query("SELECT id, recipients, message, attempts FROM notifications WHERE status = 'pending' ORDER BY id LIMIT " . max(1, $limit))
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pending as $row) {
            // 다른 프로세스(웹 요청과 cron)가 같은 건을 보내지 않게 먼저 차지한다
            $claim = $pdo->prepare("UPDATE notifications SET status = 'sending', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'pending'");
            $claim->execute([Database::now(), $row['id']]);
            if ($claim->rowCount() !== 1) {
                continue;
            }

            if ($this->sms === null) {
                $this->finish($pdo, (int) $row['id'], 'skipped', '문자 발송 설정 없음 (ALIGO_API_KEY)');
                $counts['skipped']++;
                continue;
            }

            try {
                $this->sms->send(explode(',', $row['recipients']), $row['message']);
                $this->finish($pdo, (int) $row['id'], 'sent', null);
                $counts['sent']++;
            } catch (Throwable $e) {
                error_log('[notifications] sms failed #' . $row['id'] . ': ' . $e->getMessage());
                $retry = (int) $row['attempts'] + 1 < self::MAX_ATTEMPTS;
                $this->finish($pdo, (int) $row['id'], $retry ? 'pending' : 'failed', $e->getMessage());
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * 문자 범위가 '전체 서비스'인 운영자 이상 + '담당 서비스'인 이 서비스 담당자.
     * '안함'은 담당자여도 받지 않는다. 활성 계정이고 번호가 있어야 한다.
     *
     * @return list<string>
     */
    private function recipients(PDO $pdo, int $systemId): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT u.phone FROM users u
             WHERE u.is_active = 1 AND u.phone IS NOT NULL AND u.phone <> ''
               AND ((u.sms_scope = 'all' AND u.role IN ('operator', 'admin'))
                    OR (u.sms_scope = 'owned' AND u.id IN (SELECT user_id FROM system_owners WHERE system_id = ?)))
             ORDER BY u.phone"
        );
        $statement->execute([$systemId]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string, mixed> $incident
     */
    private function message(array $incident, string $kind): string
    {
        $started = new DateTimeImmutable($incident['started_at']);
        $text = $kind === 'opened'
            ? sprintf('[장애] %s %s (%s) %s', $incident['system_name'], $incident['title'],
                Incident::SEVERITY_LABELS[$incident['severity']] ?? $incident['severity'], $started->format('m-d H:i'))
            : sprintf('[복구] %s %s (%s 지속)', $incident['system_name'], $incident['title'],
                duration($started, $incident['resolved_at'] === null ? null : new DateTimeImmutable($incident['resolved_at'])));

        return $this->appUrl === '' ? $text : $text . "\n" . $this->appUrl . '/incidents/' . $incident['id'];
    }

    private function finish(PDO $pdo, int $id, string $status, ?string $error): void
    {
        $pdo->prepare('UPDATE notifications SET status = ?, error = ?, updated_at = ?, sent_at = ? WHERE id = ?')
            ->execute([$status, $error === null ? null : mb_substr($error, 0, 500), Database::now(), $status === 'sent' ? Database::now() : null, $id]);
    }
}
