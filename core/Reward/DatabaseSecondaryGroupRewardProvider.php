<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;

final readonly class DatabaseSecondaryGroupRewardProvider implements RewardProvider
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function key(): string
    {
        return 'secondary_group';
    }

    public function apply(EntityId $recipientUserId, EntityId $targetId, int $units, DateTimeImmutable $now): RewardProviderResult
    {
        UserId::assert($recipientUserId);
        if ($units !== 1) throw new RuntimeException('Secondary-group reward provider only supports one assignment unit.');

        $group = $this->database->fetchOne(new CompiledQuery(
            'SELECT is_system FROM forwext_user_groups WHERE group_id=:group_id LIMIT 1',
            ['group_id'=>$targetId->value()],
        ));
        if ($group === null) throw new RuntimeException('Reward group target was not found.');
        if ((bool) $group['is_system']) {
            throw new RuntimeException('System groups cannot be granted by reward automation.');
        }

        $primary = $this->database->fetchValue(new CompiledQuery(
            'SELECT group_id FROM forwext_user_primary_groups WHERE user_id=:user_id LIMIT 1',
            ['user_id'=>$recipientUserId->value()],
        ));
        if (is_string($primary) && hash_equals($primary, $targetId->value())) {
            return new RewardProviderResult(false);
        }

        $exists = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_user_secondary_groups WHERE user_id=:user_id AND group_id=:group_id',
            ['user_id'=>$recipientUserId->value(),'group_id'=>$targetId->value()],
        )) === 1;
        if (!$exists) {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_secondary_groups(user_id,group_id,assigned_at_utc) '
                . 'VALUES (:user_id,:group_id,:assigned_at)',
                [
                    'user_id'=>$recipientUserId->value(),
                    'group_id'=>$targetId->value(),
                    'assigned_at'=>self::format($now),
                ],
            ));
        }
        return new RewardProviderResult(!$exists);
    }

    public function revoke(EntityId $recipientUserId, EntityId $targetId, int $units, DateTimeImmutable $now): void
    {
        UserId::assert($recipientUserId);
        if ($units !== 1) throw new RuntimeException('Secondary-group reward provider only supports one assignment unit.');
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_user_secondary_groups WHERE user_id=:user_id AND group_id=:group_id',
            ['user_id'=>$recipientUserId->value(),'group_id'=>$targetId->value()],
        ));
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
