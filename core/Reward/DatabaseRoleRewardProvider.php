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

final readonly class DatabaseRoleRewardProvider implements RewardProvider
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function key(): string
    {
        return 'role';
    }

    public function apply(EntityId $recipientUserId, EntityId $targetId, int $units, DateTimeImmutable $now): RewardProviderResult
    {
        UserId::assert($recipientUserId);
        if ($units !== 1) throw new RuntimeException('Role reward provider only supports one assignment unit.');

        $role = $this->database->fetchOne(new CompiledQuery(
            'SELECT kind,is_protected FROM forwext_roles WHERE role_id=:role_id LIMIT 1',
            ['role_id'=>$targetId->value()],
        ));
        if ($role === null) throw new RuntimeException('Reward role target was not found.');
        if ((bool) $role['is_protected'] || in_array((string) $role['kind'], ['staff','system'], true)) {
            throw new RuntimeException('Protected, staff or system roles cannot be granted by reward automation.');
        }

        $exists = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_user_role_assignments WHERE user_id=:user_id AND role_id=:role_id',
            ['user_id'=>$recipientUserId->value(),'role_id'=>$targetId->value()],
        )) === 1;
        if (!$exists) {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_user_role_assignments(user_id,role_id,assigned_at_utc) '
                . 'VALUES (:user_id,:role_id,:assigned_at)',
                [
                    'user_id'=>$recipientUserId->value(),
                    'role_id'=>$targetId->value(),
                    'assigned_at'=>self::format($now),
                ],
            ));
        }
        return new RewardProviderResult(!$exists);
    }

    public function revoke(EntityId $recipientUserId, EntityId $targetId, int $units, DateTimeImmutable $now): void
    {
        UserId::assert($recipientUserId);
        if ($units !== 1) throw new RuntimeException('Role reward provider only supports one assignment unit.');
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_user_role_assignments WHERE user_id=:user_id AND role_id=:role_id',
            ['user_id'=>$recipientUserId->value(),'role_id'=>$targetId->value()],
        ));
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
