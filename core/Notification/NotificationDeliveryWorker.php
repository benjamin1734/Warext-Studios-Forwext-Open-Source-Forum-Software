<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class NotificationDeliveryWorker
{
    /** @var array<string, NotificationChannelTransport> */
    private array $transports = [];

    /** @param iterable<NotificationChannelTransport> $transports */
    public function __construct(private readonly NotificationRepository $repository, iterable $transports)
    {
        foreach ($transports as $transport) {
            $channel = $transport->channel();
            if ($channel === NotificationChannel::InApp) {
                throw new NotificationException('In-app notifications are persisted directly and cannot use a transport.');
            }
            if (isset($this->transports[$channel->value])) {
                throw new NotificationException(sprintf('Duplicate notification transport for "%s".', $channel->value));
            }
            $this->transports[$channel->value] = $transport;
        }
    }

    public function run(int $limit = 100, ?DateTimeImmutable $now = null): int
    {
        if ($limit < 1 || $limit > 500) throw new NotificationException('Notification delivery batch size must be 1-500.');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $processed = 0;
        foreach ($this->repository->dueDeliveries($now, $limit) as $delivery) {
            ++$processed;
            $transport = $this->transports[$delivery->channel->value] ?? null;
            try {
                $result = $transport?->deliver($delivery->notification)
                    ?? NotificationDeliveryResult::failed('transport_unavailable', true);
            } catch (Throwable) {
                $result = NotificationDeliveryResult::failed('transport_exception', true);
            }

            if ($result->successful) {
                $this->repository->markDeliverySent($delivery->notification->id, $delivery->channel, $now);
                continue;
            }

            $attempts = $delivery->attempts + 1;
            $nextAttemptAt = $result->retryable && $attempts < 8
                ? $now->add(new DateInterval('PT' . $this->backoffSeconds($attempts) . 'S'))
                : null;
            $this->repository->markDeliveryFailed(
                $delivery->notification->id,
                $delivery->channel,
                $attempts,
                $nextAttemptAt,
                $result->errorCode ?? 'delivery_failed',
            );
        }
        return $processed;
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(86400, 30 * (2 ** max(0, $attempt - 1)));
    }
}
