<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseProfileUrlStore implements ProfileUrlStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function findCurrent(EntityId $userId): ?ProfileUrlAssignment
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `slug_key`,`changed_at_utc`,`window_started_at_utc`,`changes_in_window` '
            . 'FROM `forwext_user_profile_urls` WHERE `user_id`=:user_id',
            ['user_id' => $userId->value()],
        ));
        return $row === null ? null : $this->assignment($userId, $row);
    }

    public function resolve(ProfileSlug $slug): ?ProfileUrlResolution
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT c.`user_id`,c.`retired_at_utc`,u.`slug_key` AS `current_slug` '
            . 'FROM `forwext_profile_url_claims` c '
            . 'LEFT JOIN `forwext_user_profile_urls` u ON u.`user_id`=c.`user_id` '
            . 'WHERE c.`slug_key`=:slug_key',
            ['slug_key' => $slug->value()],
        ));
        if ($row === null || !is_string($row['user_id'] ?? null) || !is_string($row['current_slug'] ?? null)) {
            return null;
        }

        $userId = UserId::fromString($row['user_id']);
        $current = ProfileSlug::fromString($row['current_slug']);
        $isCurrent = ($row['retired_at_utc'] ?? null) === null && $current->value() === $slug->value();
        return new ProfileUrlResolution($userId, $slug, $current, $isCurrent);
    }

    public function claim(
        EntityId $userId,
        ProfileSlug $slug,
        DateTimeImmutable $now,
        int $minimumChangeIntervalSeconds,
        int $changeWindowSeconds,
        int $maximumChangesPerWindow,
    ): ProfileUrlAssignment {
        UserId::assert($userId);
        $now = ProfileUrlAssignment::utc($now);

        return $this->database->transaction(function (TransactionalQueryExecutor $database) use (
            $userId,
            $slug,
            $now,
            $minimumChangeIntervalSeconds,
            $changeWindowSeconds,
            $maximumChangesPerWindow,
        ): ProfileUrlAssignment {
            $current = $database->fetchOne(new CompiledQuery(
                'SELECT `slug_key`,`changed_at_utc`,`window_started_at_utc`,`changes_in_window` '
                . 'FROM `forwext_user_profile_urls` WHERE `user_id`=:user_id FOR UPDATE',
                ['user_id' => $userId->value()],
            ));
            if ($current !== null && (string) $current['slug_key'] === $slug->value()) {
                return $this->assignment($userId, $current);
            }

            $claimed = $database->fetchOne(new CompiledQuery(
                'SELECT `slug_key` FROM `forwext_profile_url_claims` WHERE `slug_key`=:slug_key FOR UPDATE',
                ['slug_key' => $slug->value()],
            ));
            if ($claimed !== null) {
                throw new ProfileUrlException('Custom profile slug is unavailable.');
            }

            $changedAt = $now;
            $windowStartedAt = $now;
            $changes = 0;
            if ($current !== null) {
                $previousChangedAt = self::parse((string) $current['changed_at_utc']);
                $previousWindow = self::parse((string) $current['window_started_at_utc']);
                $elapsed = $now->getTimestamp() - $previousChangedAt->getTimestamp();
                if ($elapsed < 0 || $elapsed < $minimumChangeIntervalSeconds) {
                    throw new ProfileUrlException('Custom profile URL change cooldown is active.');
                }

                $windowElapsed = $now->getTimestamp() - $previousWindow->getTimestamp();
                if ($windowElapsed < 0) {
                    throw new ProfileUrlException('Custom profile URL change window is invalid.');
                }
                if ($windowElapsed >= $changeWindowSeconds) {
                    $windowStartedAt = $now;
                    $changes = 1;
                } else {
                    $windowStartedAt = $previousWindow;
                    $changes = ((int) $current['changes_in_window']) + 1;
                }
                if ($changes > $maximumChangesPerWindow) {
                    throw new ProfileUrlException('Custom profile URL change limit has been reached.');
                }
            }

            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_profile_url_claims` '
                . '(`slug_key`,`user_id`,`claimed_at_utc`,`retired_at_utc`) '
                . 'VALUES (:slug_key,:user_id,:claimed_at,NULL)',
                [
                    'slug_key' => $slug->value(),
                    'user_id' => $userId->value(),
                    'claimed_at' => self::format($now),
                ],
            ));

            if ($current !== null) {
                $database->execute(new CompiledQuery(
                    'UPDATE `forwext_profile_url_claims` SET `retired_at_utc`=:retired_at '
                    . 'WHERE `slug_key`=:old_slug AND `user_id`=:user_id AND `retired_at_utc` IS NULL',
                    [
                        'retired_at' => self::format($now),
                        'old_slug' => (string) $current['slug_key'],
                        'user_id' => $userId->value(),
                    ],
                ));
            }

            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_profile_urls` '
                . '(`user_id`,`slug_key`,`changed_at_utc`,`window_started_at_utc`,`changes_in_window`) '
                . 'VALUES (:user_id,:slug_key,:changed_at,:window_started_at,:changes) '
                . 'ON DUPLICATE KEY UPDATE `slug_key`=VALUES(`slug_key`),`changed_at_utc`=VALUES(`changed_at_utc`),'
                . '`window_started_at_utc`=VALUES(`window_started_at_utc`),`changes_in_window`=VALUES(`changes_in_window`)',
                [
                    'user_id' => $userId->value(),
                    'slug_key' => $slug->value(),
                    'changed_at' => self::format($changedAt),
                    'window_started_at' => self::format($windowStartedAt),
                    'changes' => $changes,
                ],
            ));

            return new ProfileUrlAssignment($userId, $slug, $changedAt, $windowStartedAt, $changes);
        });
    }

    /** @param array<string,mixed> $row */
    private function assignment(EntityId $userId, array $row): ProfileUrlAssignment
    {
        return new ProfileUrlAssignment(
            $userId,
            ProfileSlug::fromString((string) $row['slug_key']),
            self::parse((string) $row['changed_at_utc']),
            self::parse((string) $row['window_started_at_utc']),
            (int) $row['changes_in_window'],
        );
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new ProfileUrlException('Stored custom profile URL timestamp is invalid.');
        }
        return $date;
    }
}
