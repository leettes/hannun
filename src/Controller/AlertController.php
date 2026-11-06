<?php

declare(strict_types=1);

namespace App\Controller;

use App\Alerts\AlertIngestor;
use App\Alerts\CloudWatchAlarm;
use App\Alerts\InvalidAlert;
use App\Alerts\SnsMessage;
use App\Alerts\UptimeKumaWebhook;
use App\Http\Request;
use App\Http\Response;
use App\Notifications\Notifier;
use Throwable;

/**
 * 외부 알림 수신 API. 로그인·세션·IP 제한 대신 토큰(Uptime Kuma)과 SNS 서명으로 인증한다.
 */
final class AlertController
{
    public function __construct(
        private readonly AlertIngestor $ingestor,
        private readonly SnsMessage $sns,
        private readonly string $webhookToken,
        private readonly Notifier $notifier,
    ) {
    }

    public function uptimeKuma(Request $request): Response
    {
        if ($this->webhookToken === '') {
            return Response::json(['error' => 'ALERT_WEBHOOK_TOKEN 이 설정되지 않았습니다.'], 503);
        }
        $sent = (string) preg_replace('/^Bearer\s+/i', '', $request->header('Authorization') ?? '');
        if (!hash_equals($this->webhookToken, $sent)) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        try {
            $result = (new UptimeKumaWebhook($this->ingestor))->handle($request->content);
        } catch (InvalidAlert $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
        $this->sendNotifications();

        return Response::json($result);
    }

    public function sns(Request $request): Response
    {
        if (!$this->sns->configured()) {
            return Response::json(['error' => 'SNS_TOPIC_ARNS 가 설정되지 않았습니다.'], 503);
        }

        try {
            $message = $this->sns->parse($request->content);
        } catch (InvalidAlert $e) {
            error_log('[alerts] sns rejected: ' . $e->getMessage());

            return Response::json(['error' => $e->getMessage()], 400);
        }

        if ($message['Type'] === 'SubscriptionConfirmation') {
            $confirmed = $this->sns->confirmSubscription($message);
            error_log(sprintf('[alerts] sns subscription %s: %s', $confirmed ? 'confirmed' : 'confirm failed', $message['TopicArn']));

            return Response::json(['result' => $confirmed ? 'subscribed' : 'confirm_failed'], $confirmed ? 200 : 502);
        }
        if ($message['Type'] !== 'Notification') {
            return Response::json(['result' => 'ignored']);
        }

        $eventId = $this->ingestor->receive(CloudWatchAlarm::SOURCE, (string) $message['MessageId'], $request->content);
        if ($eventId === null) {
            return Response::json(['result' => 'duplicate']);
        }

        $result = (new CloudWatchAlarm($this->ingestor))->handle($eventId, (string) $message['Message']);
        $this->sendNotifications();

        return Response::json($result);
    }

    /**
     * 방금 쌓인 문자 알림을 바로 보낸다. 실패해도 알림 수신 응답에는 영향을 주지 않는다 (cron 이 재발송).
     */
    private function sendNotifications(): void
    {
        try {
            $this->notifier->dispatch();
        } catch (Throwable $e) {
            error_log('[notifications] dispatch failed: ' . $e->getMessage());
        }
    }
}
