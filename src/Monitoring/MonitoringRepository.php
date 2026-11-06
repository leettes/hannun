<?php

declare(strict_types=1);

namespace App\Monitoring;

use DateTimeImmutable;

/**
 * 화면이 읽는 모니터링 데이터. 반환 구조는 템플릿이 그대로 사용한다.
 *
 * system: code, name, environment, owner, description, aws_account, region,
 *         http[], ec2[], rds[], http_status, ec2_status, rds_status, status, last_checked_at
 * incident: id, system_code, system_name, resource, source, severity, status, title, assignee,
 *           started_at, acknowledged_at, resolved_at, root_cause, action_taken (+ incident() 는 events[])
 */
interface MonitoringRepository
{
    /**
     * @return array<string, array<string, mixed>> code => system
     */
    public function systems(): array;

    /**
     * @return array<string, mixed>|null
     */
    public function system(string $code): ?array;

    /**
     * @param bool $hideDismissed true 면 대시보드 '최근 장애'에서 확인한 장애를 뺀다
     * @return list<array<string, mixed>> 최근 발생 순
     */
    public function incidents(?string $status = null, ?string $systemCode = null, ?int $limit = null, bool $hideDismissed = false): array;

    /**
     * @return array<string, mixed>|null
     */
    public function incident(int $id): ?array;

    /**
     * 복구되지 않은(진행 중 + 확인됨) 장애 수
     */
    public function activeIncidentCount(?string $systemCode = null): int;

    public function lastCheckedAt(): ?DateTimeImmutable;

    /**
     * 서비스의 웹/API 응답 시간을 기간 안에서 묶음 간격별로 모은다.
     * down 점검은 응답 시간에서 빼고 down 횟수로만 센다. 점검이 없는 묶음은 빠진다.
     *
     * @return list<array{name: string, url: string, points: list<array{t: int, avg: ?int, max: ?int, down: int, n: int}>}>
     *         t 는 묶음 시작 시각(unix 초), 리소스 등록 순
     */
    public function responseTimes(string $systemCode, TimeRange $range): array;
}
