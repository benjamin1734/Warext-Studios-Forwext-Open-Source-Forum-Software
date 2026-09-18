<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserAuthenticationAvailability;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseDisciplineAuthenticationAvailability implements UserAuthenticationAvailability
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function allows(EntityId $userId): bool
    {
        UserId::assert($userId);

        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_discipline_actions "
            . "WHERE user_id = :user_id "
            . "AND action_type IN ('suspension','ban') "
            . "AND revoked_at_utc IS NULL "
            . "AND starts_at_utc <= UTC_TIMESTAMP(6) "
            . "AND (expires_at_utc IS NULL OR expires_at_utc > UTC_TIMESTAMP(6))",
            ['user_id' => $userId->value()],
        )) === 0;
    }
}
