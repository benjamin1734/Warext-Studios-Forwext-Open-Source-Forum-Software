<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use JsonException;
use ValueError;

final readonly class DatabaseNotificationRepository implements NotificationRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function withRecipientLock(EntityId $recipientUserId, Closure $callback): mixed
    {
        UserId::assert($recipientUserId);

        return $this->database->transaction(function (TransactionalQueryExecutor $_database) use ($recipientUserId, $callback): mixed {
            $lockedUserId = $this->database->fetchValue(new CompiledQuery(
                'SELECT `user_id` FROM `forwext_users` WHERE `user_id` = :user_id FOR UPDATE',
                ['user_id' => $recipientUserId->value()],
            ));
            if (!is_string($lockedUserId) || !hash_equals($recipientUserId->value(), $lockedUserId)) {
                throw new NotificationException('Notification recipient is unavailable.');
            }

            return $callback();
        });
    }

    public function findByDedupe(EntityId $recipientUserId, string $dedupeKey): ?Notification
    {
        UserId::assert($recipientUserId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT n.* FROM `forwext_notification_dedupes` d '
            . 'INNER JOIN `forwext_notifications` n ON n.`notification_id` = d.`notification_id` '
            . 'WHERE d.`recipient_user_id` = :recipient_user_id AND d.`dedupe_key` = :dedupe_key LIMIT 1',
            ['recipient_user_id' => $recipientUserId->value(), 'dedupe_key' => $dedupeKey],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function findOpenGroup(EntityId $recipientUserId, string $typeKey, string $groupKey): ?Notification
    {
        UserId::assert($recipientUserId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM `forwext_notifications` WHERE `recipient_user_id` = :recipient_user_id '
            . 'AND `type_key` = :type_key AND `group_key` = :group_key AND `read_at_utc` IS NULL '
            . 'ORDER BY `updated_at_utc` DESC LIMIT 1',
            ['recipient_user_id' => $recipientUserId->value(), 'type_key' => $typeKey, 'group_key' => $groupKey],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function insert(Notification $notification, ?string $groupKey): void
    {
        UserId::assert($notification->recipientUserId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_notifications` '
            . '(`notification_id`,`recipient_user_id`,`type_key`,`category_key`,`group_key`,`title`,`body`,`action_path`,`payload_json`,`in_app_visible`,`occurrences`,`created_at_utc`,`updated_at_utc`,`read_at_utc`) '
            . 'VALUES (:notification_id,:recipient_user_id,:type_key,:category_key,:group_key,:title,:body,:action_path,:payload_json,:in_app_visible,:occurrences,:created_at_utc,:updated_at_utc,:read_at_utc)',
            [
                'notification_id' => $notification->id->value(),
                'recipient_user_id' => $notification->recipientUserId->value(),
                'type_key' => $notification->typeKey,
                'category_key' => $notification->categoryKey,
                'group_key' => $groupKey,
                'title' => $notification->title,
                'body' => $notification->body,
                'action_path' => $notification->actionPath,
                'payload_json' => $this->encodePayload($notification->payload),
                'in_app_visible' => $notification->inAppVisible ? 1 : 0,
                'occurrences' => $notification->occurrences,
                'created_at_utc' => $this->format($notification->createdAt),
                'updated_at_utc' => $this->format($notification->updatedAt),
                'read_at_utc' => $notification->readAt === null ? null : $this->format($notification->readAt),
            ],
        ));
    }

    public function incrementGroup(
        EntityId $notificationId,
        string $title,
        string $body,
        ?string $actionPath,
        array $payload,
        bool $inAppVisible,
        DateTimeImmutable $now,
    ): Notification {
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_notifications` SET `occurrences` = `occurrences` + 1, `title` = :title, `body` = :body, '
            . '`action_path` = :action_path, `payload_json` = :payload_json, `in_app_visible` = :in_app_visible, `updated_at_utc` = :updated_at_utc '
            . 'WHERE `notification_id` = :notification_id AND `read_at_utc` IS NULL',
            [
                'title' => $title,
                'body' => $body,
                'action_path' => $actionPath,
                'payload_json' => $this->encodePayload($payload),
                'in_app_visible' => $inAppVisible ? 1 : 0,
                'updated_at_utc' => $this->format($now),
                'notification_id' => $notificationId->value(),
            ],
        ));
        return $this->findById($notificationId)
            ?? throw new NotificationException('Grouped notification disappeared during update.');
    }

    public function rememberDedupe(EntityId $recipientUserId, string $dedupeKey, EntityId $notificationId, DateTimeImmutable $now): void
    {
        UserId::assert($recipientUserId);
        $this->database->execute(new CompiledQuery(
            'INSERT IGNORE INTO `forwext_notification_dedupes` (`recipient_user_id`,`dedupe_key`,`notification_id`,`created_at_utc`) '
            . 'VALUES (:recipient_user_id,:dedupe_key,:notification_id,:created_at_utc)',
            [
                'recipient_user_id' => $recipientUserId->value(),
                'dedupe_key' => $dedupeKey,
                'notification_id' => $notificationId->value(),
                'created_at_utc' => $this->format($now),
            ],
        ));
    }

    public function preference(EntityId $userId, string $categoryKey, NotificationChannel $channel): ?bool
    {
        UserId::assert($userId);
        $value = $this->database->fetchValue(new CompiledQuery(
            'SELECT `enabled` FROM `forwext_notification_preferences` WHERE `user_id` = :user_id '
            . 'AND `category_key` = :category_key AND `channel` = :channel LIMIT 1',
            ['user_id' => $userId->value(), 'category_key' => $categoryKey, 'channel' => $channel->value],
        ));
        return $value === null ? null : (bool) $value;
    }

    public function setPreference(
        EntityId $userId,
        string $categoryKey,
        NotificationChannel $channel,
        bool $enabled,
        DateTimeImmutable $now,
    ): void {
        UserId::assert($userId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_notification_preferences` (`user_id`,`category_key`,`channel`,`enabled`,`updated_at_utc`) '
            . 'VALUES (:user_id,:category_key,:channel,:enabled,:updated_at_utc) '
            . 'ON DUPLICATE KEY UPDATE `enabled` = VALUES(`enabled`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'user_id' => $userId->value(), 'category_key' => $categoryKey, 'channel' => $channel->value,
                'enabled' => $enabled ? 1 : 0, 'updated_at_utc' => $this->format($now),
            ],
        ));
    }

    public function queueDelivery(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void
    {
        if ($channel === NotificationChannel::InApp) throw new NotificationException('In-app channel is not queued.');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_notification_deliveries` '
            . '(`notification_id`,`channel`,`status`,`attempts`,`available_at_utc`,`sent_at_utc`,`last_error_code`,`updated_at_utc`) '
            . "VALUES (:notification_id,:channel,'pending',0,:available_at_utc,NULL,NULL,:updated_at_utc) "
            . "ON DUPLICATE KEY UPDATE `status`='pending', `attempts`=0, `available_at_utc`=VALUES(`available_at_utc`), "
            . '`sent_at_utc`=NULL, `last_error_code`=NULL, `updated_at_utc`=VALUES(`updated_at_utc`)',
            [
                'notification_id' => $notificationId->value(), 'channel' => $channel->value,
                'available_at_utc' => $this->format($now), 'updated_at_utc' => $this->format($now),
            ],
        ));
    }

    public function dueDeliveries(DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 500) throw new NotificationException('Invalid notification delivery limit.');
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT n.*, d.`channel` AS `delivery_channel`, d.`attempts` AS `delivery_attempts` '
            . 'FROM `forwext_notification_deliveries` d INNER JOIN `forwext_notifications` n '
            . 'ON n.`notification_id` = d.`notification_id` '
            . "WHERE d.`status` IN ('pending','failed') AND d.`available_at_utc` IS NOT NULL "
            . 'AND d.`available_at_utc` <= :available_before AND d.`attempts` < 8 '
            . 'ORDER BY d.`available_at_utc` ASC LIMIT ' . $limit,
            ['available_before' => $this->format($now)],
        ));
        $deliveries = [];
        foreach ($rows as $row) {
            try {
                $channel = NotificationChannel::from((string) ($row['delivery_channel'] ?? ''));
            } catch (ValueError) {
                throw new NotificationException('Stored notification delivery channel is invalid.');
            }
            if ($channel === NotificationChannel::InApp) throw new NotificationException('Stored in-app delivery must not be queued.');
            $deliveries[] = new NotificationDelivery($this->hydrate($row), $channel, max(0, (int) ($row['delivery_attempts'] ?? 0)));
        }
        return $deliveries;
    }

    public function markDeliverySent(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_notification_deliveries` SET `status` = \'sent\', `attempts` = `attempts` + 1, '
            . '`available_at_utc` = NULL, `sent_at_utc` = :sent_at_utc, `last_error_code` = NULL, `updated_at_utc` = :updated_at_utc '
            . 'WHERE `notification_id` = :notification_id AND `channel` = :channel',
            [
                'sent_at_utc' => $this->format($now), 'updated_at_utc' => $this->format($now),
                'notification_id' => $notificationId->value(), 'channel' => $channel->value,
            ],
        ));
    }

    public function markDeliveryFailed(
        EntityId $notificationId,
        NotificationChannel $channel,
        int $attempts,
        ?DateTimeImmutable $nextAttemptAt,
        string $errorCode,
    ): void {
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_notification_deliveries` SET `status` = \'failed\', `attempts` = :attempts, '
            . '`available_at_utc` = :available_at_utc, `last_error_code` = :last_error_code, `updated_at_utc` = UTC_TIMESTAMP(6) '
            . 'WHERE `notification_id` = :notification_id AND `channel` = :channel',
            [
                'attempts' => $attempts,
                'available_at_utc' => $nextAttemptAt === null ? null : $this->format($nextAttemptAt),
                'last_error_code' => $errorCode,
                'notification_id' => $notificationId->value(),
                'channel' => $channel->value,
            ],
        ));
    }

    public function inbox(EntityId $userId, int $limit, int $offset): array
    {
        UserId::assert($userId);
        if ($limit < 1 || $limit > 100 || $offset < 0) throw new NotificationException('Invalid notification inbox pagination.');
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM `forwext_notifications` WHERE `recipient_user_id` = :recipient_user_id AND `in_app_visible` = 1 '
            . 'ORDER BY `updated_at_utc` DESC, `notification_id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['recipient_user_id' => $userId->value()],
        ));
        return array_map(fn (array $row): Notification => $this->hydrate($row), $rows);
    }

    public function unreadCount(EntityId $userId): int
    {
        UserId::assert($userId);
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_notifications` WHERE `recipient_user_id` = :recipient_user_id '
            . 'AND `in_app_visible` = 1 AND `read_at_utc` IS NULL',
            ['recipient_user_id' => $userId->value()],
        ));
    }

    public function markRead(EntityId $userId, EntityId $notificationId, DateTimeImmutable $now): bool
    {
        UserId::assert($userId);
        return $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_notifications` SET `read_at_utc` = COALESCE(`read_at_utc`, :read_at_utc), `updated_at_utc` = `updated_at_utc` '
            . 'WHERE `notification_id` = :notification_id AND `recipient_user_id` = :recipient_user_id AND `in_app_visible` = 1',
            [
                'read_at_utc' => $this->format($now), 'notification_id' => $notificationId->value(),
                'recipient_user_id' => $userId->value(),
            ],
        )) > 0;
    }

    private function findById(EntityId $notificationId): ?Notification
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM `forwext_notifications` WHERE `notification_id` = :notification_id LIMIT 1',
            ['notification_id' => $notificationId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Notification
    {
        try {
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new NotificationException('Stored notification payload is invalid.');
        }
        if (!is_array($payload)) throw new NotificationException('Stored notification payload must be an object.');
        /** @var array<string, scalar|null> $safePayload */
        $safePayload = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new NotificationException('Stored notification payload contains unsupported values.');
            }
            $safePayload[$key] = $value;
        }
        return new Notification(
            EntityId::fromString((string) $row['notification_id']),
            UserId::fromStored((string) $row['recipient_user_id']),
            (string) $row['type_key'],
            (string) $row['category_key'],
            (string) $row['title'],
            (string) $row['body'],
            isset($row['action_path']) && is_string($row['action_path']) ? $row['action_path'] : null,
            $safePayload,
            (bool) ($row['in_app_visible'] ?? false),
            max(1, (int) ($row['occurrences'] ?? 1)),
            $this->parseDate((string) $row['created_at_utc']),
            $this->parseDate((string) $row['updated_at_utc']),
            isset($row['read_at_utc']) && is_string($row['read_at_utc']) ? $this->parseDate($row['read_at_utc']) : null,
        );
    }

    /** @param array<string, scalar|null> $payload */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new NotificationException('Notification payload could not be encoded.', previous: $exception);
        }
    }

    private function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) throw new NotificationException('Stored notification timestamp is invalid.');
        return $date;
    }
}
