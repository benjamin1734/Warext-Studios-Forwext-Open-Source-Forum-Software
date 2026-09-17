<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class NotificationInboxService
{
    public function __construct(
        private NotificationRepository $repository,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    /** @return list<Notification> */
    public function inbox(EntityId $userId, int $limit = 50, int $offset = 0): array
    {
        $this->requirePermission($userId, 'notification.alert.view');
        if ($limit < 1 || $limit > 100 || $offset < 0) throw new NotificationException('Invalid notification inbox pagination.');
        return $this->repository->inbox($userId, $limit, $offset);
    }

    public function unreadCount(EntityId $userId): int
    {
        $this->requirePermission($userId, 'notification.alert.view');
        return $this->repository->unreadCount($userId);
    }

    public function markRead(EntityId $userId, EntityId $notificationId, ?DateTimeImmutable $now = null): bool
    {
        $this->requirePermission($userId, 'notification.alert.view');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $this->repository->markRead($userId, $notificationId, $now);
    }

    public function setPreference(
        EntityId $userId,
        string $categoryKey,
        NotificationChannel $channel,
        bool $enabled,
        ?DateTimeImmutable $now = null,
    ): void {
        $this->requirePermission($userId, 'notification.preference.manage');
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $categoryKey) !== 1) {
            throw new NotificationException('Invalid notification category key.');
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->repository->setPreference($userId, $categoryKey, $channel, $enabled, $now);
    }

    private function requirePermission(EntityId $userId, string $permissionKey): void
    {
        if (!$this->authorizer->allows($userId, PermissionKey::fromString($permissionKey))) {
            throw new PermissionDeniedException(sprintf('Permission "%s" is required.', $permissionKey));
        }
    }
}
