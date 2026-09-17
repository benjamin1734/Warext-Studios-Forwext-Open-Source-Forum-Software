<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class NotificationDispatcher
{
    public function __construct(
        private NotificationRegistry $registry,
        private NotificationRepository $repository,
        private NotificationTemplateRenderer $renderer = new NotificationTemplateRenderer(),
    ) {
    }

    public function dispatch(NotificationRequest $request, ?DateTimeImmutable $now = null): ?Notification
    {
        $definition = $this->registry->require($request->typeKey);
        $channels = $this->effectiveChannels($request->recipientUserId, $definition);
        if ($channels === []) return null;

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($request->dedupeKey !== null) {
            $existing = $this->repository->findByDedupe($request->recipientUserId, $request->dedupeKey);
            if ($existing !== null) return $existing;
        }

        $title = $this->renderer->render($definition->titleTemplate, $request->variables);
        $body = $this->renderer->render($definition->bodyTemplate, $request->variables);
        if (strlen($title) > 255 || strlen($body) > 2000) {
            throw new NotificationException('Rendered notification content exceeds storage limits.');
        }

        $notification = null;
        if ($request->groupKey !== null) {
            $notification = $this->repository->findOpenGroup($request->recipientUserId, $definition->typeKey, $request->groupKey);
        }
        if ($notification !== null) {
            $notification = $this->repository->incrementGroup(
                $notification->id,
                $title,
                $body,
                $request->actionPath,
                $request->payload,
                in_array(NotificationChannel::InApp, $channels, true),
                $now,
            );
        } else {
            $notification = new Notification(
                EntityId::fromString(bin2hex(random_bytes(16))),
                $request->recipientUserId,
                $definition->typeKey,
                $definition->categoryKey,
                $title,
                $body,
                $request->actionPath,
                $request->payload,
                in_array(NotificationChannel::InApp, $channels, true),
                1,
                $now,
                $now,
            );
            $this->repository->insert($notification, $request->groupKey);
        }

        if ($request->dedupeKey !== null) {
            $this->repository->rememberDedupe($request->recipientUserId, $request->dedupeKey, $notification->id, $now);
        }
        foreach ($channels as $channel) {
            if ($channel !== NotificationChannel::InApp) {
                $this->repository->queueDelivery($notification->id, $channel, $now);
            }
        }
        return $notification;
    }

    /** @return list<NotificationChannel> */
    private function effectiveChannels(EntityId $userId, NotificationDefinition $definition): array
    {
        $channels = [];
        foreach (NotificationChannel::cases() as $channel) {
            $preference = $this->repository->preference($userId, $definition->categoryKey, $channel);
            $enabled = $preference ?? in_array($channel, $definition->defaultChannels, true);
            if ($enabled) $channels[] = $channel;
        }
        return $channels;
    }
}
