<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileVisibility;

final readonly class DatabaseProfileMusicStore implements ProfileMusicStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function find(EntityId $userId): ?ProfileMusicSettings
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `enabled`, `source_type`, `upload_path`, `external_url`, `title`, `visibility`, '
            . '`volume`, `muted`, `autoplay`, `loop`, `moderation_status`, `moderation_reason_code`, '
            . '`moderated_by_user_id`, `moderated_at_utc`, `updated_at_utc` '
            . 'FROM `forwext_user_profile_music` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $userId->value()],
        ));
        if ($row === null) {
            return null;
        }

        $source = is_string($row['source_type'] ?? null)
            ? ProfileMusicSourceType::from($row['source_type'])
            : null;
        $moderator = is_string($row['moderated_by_user_id'] ?? null)
            ? UserId::fromStored($row['moderated_by_user_id'])
            : null;
        $moderatedAt = is_string($row['moderated_at_utc'] ?? null)
            ? self::parse($row['moderated_at_utc'])
            : null;

        return new ProfileMusicSettings(
            $userId,
            (int) $row['enabled'] === 1,
            $source,
            is_string($row['upload_path'] ?? null) ? $row['upload_path'] : null,
            is_string($row['external_url'] ?? null) ? $row['external_url'] : null,
            (string) $row['title'],
            ProfileVisibility::from((string) $row['visibility']),
            (int) $row['volume'],
            (int) $row['muted'] === 1,
            (int) $row['autoplay'] === 1,
            (int) $row['loop'] === 1,
            ProfileMusicModerationStatus::from((string) $row['moderation_status']),
            is_string($row['moderation_reason_code'] ?? null) ? $row['moderation_reason_code'] : null,
            $moderator,
            $moderatedAt,
            self::parse((string) $row['updated_at_utc']),
        );
    }

    public function save(ProfileMusicSettings $settings): void
    {
        $this->persist($this->database, $settings);
    }

    public function saveModeration(ProfileMusicSettings $settings, ProfileMusicModerationEvent $event): void
    {
        if (!$settings->userId->equals($event->userId)) {
            throw new ProfileException('Profile music moderation event targets the wrong user.');
        }

        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($settings, $event): void {
            $this->persist($database, $settings);
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_profile_music_moderation` '
                . '(`user_id`, `actor_user_id`, `action`, `reason_code`, `occurred_at_utc`) '
                . 'VALUES (:user_id, :actor_user_id, :action, :reason_code, :occurred_at_utc)',
                [
                    'user_id' => $event->userId->value(),
                    'actor_user_id' => $event->actorId->value(),
                    'action' => $event->action->value,
                    'reason_code' => $event->reasonCode,
                    'occurred_at_utc' => self::format($event->occurredAt),
                ],
            ));
        });
    }

    private function persist(TransactionalQueryExecutor $database, ProfileMusicSettings $settings): void
    {
        UserId::assert($settings->userId);
        $database->execute(new CompiledQuery(
            'INSERT INTO `forwext_user_profile_music` '
            . '(`user_id`, `enabled`, `source_type`, `upload_path`, `external_url`, `title`, `visibility`, '
            . '`volume`, `muted`, `autoplay`, `loop`, `moderation_status`, `moderation_reason_code`, '
            . '`moderated_by_user_id`, `moderated_at_utc`, `updated_at_utc`) '
            . 'VALUES (:user_id, :enabled, :source_type, :upload_path, :external_url, :title, :visibility, '
            . ':volume, :muted, :autoplay, :loop, :moderation_status, :moderation_reason_code, '
            . ':moderated_by_user_id, :moderated_at_utc, :updated_at_utc) '
            . 'ON DUPLICATE KEY UPDATE '
            . '`enabled` = VALUES(`enabled`), `source_type` = VALUES(`source_type`), '
            . '`upload_path` = VALUES(`upload_path`), `external_url` = VALUES(`external_url`), '
            . '`title` = VALUES(`title`), `visibility` = VALUES(`visibility`), '
            . '`volume` = VALUES(`volume`), `muted` = VALUES(`muted`), '
            . '`autoplay` = VALUES(`autoplay`), `loop` = VALUES(`loop`), '
            . '`moderation_status` = VALUES(`moderation_status`), '
            . '`moderation_reason_code` = VALUES(`moderation_reason_code`), '
            . '`moderated_by_user_id` = VALUES(`moderated_by_user_id`), '
            . '`moderated_at_utc` = VALUES(`moderated_at_utc`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'user_id' => $settings->userId->value(),
                'enabled' => $settings->enabled ? 1 : 0,
                'source_type' => $settings->sourceType?->value,
                'upload_path' => $settings->uploadPath,
                'external_url' => $settings->externalUrl,
                'title' => $settings->title,
                'visibility' => $settings->visibility->value,
                'volume' => $settings->volume,
                'muted' => $settings->muted ? 1 : 0,
                'autoplay' => $settings->autoplay ? 1 : 0,
                'loop' => $settings->loop ? 1 : 0,
                'moderation_status' => $settings->moderationStatus->value,
                'moderation_reason_code' => $settings->moderationReasonCode,
                'moderated_by_user_id' => $settings->moderatedByUserId?->value(),
                'moderated_at_utc' => $settings->moderatedAt !== null ? self::format($settings->moderatedAt) : null,
                'updated_at_utc' => self::format($settings->updatedAt),
            ],
        ));
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new ProfileException('Stored profile music timestamp is invalid.');
        }
        return $date;
    }
}
