<?php

declare(strict_types=1);

namespace App\Alerts;

use App\Monitoring\Status;
use Throwable;

/**
 * CloudWatch 알람 상태 변경 알림(SNS 메시지 본문) 처리.
 * 알람의 Dimensions 에서 InstanceId(EC2) 또는 DBInstanceIdentifier(RDS)로 리소스를 찾는다.
 */
final class CloudWatchAlarm
{
    public const SOURCE = 'cloudwatch';

    private const DIMENSIONS = ['InstanceId' => 'ec2', 'DBInstanceIdentifier' => 'rds'];

    public function __construct(private readonly AlertIngestor $ingestor)
    {
    }

    /**
     * @param int $eventId receive() 로 저장한 alert_events id
     * @return array{result: string, incident_id?: int|null}
     */
    public function handle(int $eventId, string $message): array
    {
        $alarm = json_decode($message, true);
        if (!is_array($alarm) || !isset($alarm['AlarmName'], $alarm['NewStateValue'])) {
            $this->ingestor->finish($eventId, null, 'CloudWatch 알람 메시지가 아닙니다.');

            return ['result' => 'ignored'];
        }

        $resource = null;
        foreach ($alarm['Trigger']['Dimensions'] ?? [] as $dimension) {
            $type = self::DIMENSIONS[$dimension['name'] ?? ''] ?? null;
            if ($type !== null) {
                $resource = $this->ingestor->findResource($type, null, (string) ($dimension['value'] ?? ''));
                break;
            }
        }
        if ($resource === null) {
            $this->ingestor->finish($eventId, null, "알람 {$alarm['AlarmName']} 의 대상 리소스를 찾을 수 없습니다 (EC2 InstanceId / RDS DBInstanceIdentifier 차원 필요).");

            return ['result' => 'unmatched'];
        }

        $alertKey = sprintf('%s:%s:%s', self::SOURCE, $alarm['AWSAccountId'] ?? '-', $alarm['AlarmName']);
        $reason = is_string($alarm['NewStateReason'] ?? null) ? $alarm['NewStateReason'] : null;
        $metric = (string) ($alarm['Trigger']['MetricName'] ?? '');

        try {
            $incidentId = match ($alarm['NewStateValue']) {
                'ALARM' => str_starts_with($metric, 'StatusCheckFailed')
                    ? $this->ingestor->raise($resource, self::SOURCE, $alertKey, 'critical', "{$resource['name']} 상태 검사 실패 ({$alarm['AlarmName']})", $reason, Status::DOWN)
                    : $this->ingestor->raise($resource, self::SOURCE, $alertKey, 'major', "{$resource['name']} {$metric} 임계치 초과 ({$alarm['AlarmName']})", $reason, Status::WARNING),
                'OK' => $this->ingestor->clear($resource, $alertKey, 'CloudWatch 알람 OK' . ($reason === null ? '' : ": {$reason}")),
                // INSUFFICIENT_DATA: 데이터 부족은 장애로 보지 않는다
                default => null,
            };
        } catch (Throwable $e) {
            $this->ingestor->finish($eventId, null, $e->getMessage(), processed: false);
            throw $e;
        }
        $this->ingestor->finish($eventId, $incidentId);

        return ['result' => $incidentId === null && $alarm['NewStateValue'] !== 'OK' ? 'ignored' : 'processed', 'incident_id' => $incidentId];
    }
}
