<?php

declare(strict_types=1);

namespace App\Monitoring;

final class Incident
{
    public const SEVERITY_LABELS = [
        'critical' => '심각',
        'major' => '높음',
        'minor' => '보통',
        'info' => '정보',
    ];

    public const STATUS_LABELS = [
        'open' => '진행 중',
        'acknowledged' => '확인됨',
        'resolved' => '복구됨',
    ];

    /**
     * 화면에서 바꿀 수 있는 상태 흐름. 복구된 장애를 다시 열면 '진행 중'이 된다.
     */
    public const TRANSITIONS = [
        'open' => ['acknowledged', 'resolved'],
        'acknowledged' => ['resolved'],
        'resolved' => ['open'],
    ];

    public const SOURCE_LABELS = [
        'uptime_kuma' => 'Uptime Kuma',
        'cloudwatch' => 'CloudWatch',
        'dashboard' => '대시보드 점검',
        'manual' => '수동 등록',
    ];

    public const EVENT_LABELS = [
        'opened' => '장애 발생',
        'alert' => '알림 반복',
        'acknowledged' => '확인',
        'note' => '메모',
        'assigned' => '담당자 지정',
        'resolved' => '복구',
        'reopened' => '재발생',
    ];
}
