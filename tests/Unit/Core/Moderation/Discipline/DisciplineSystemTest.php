<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation\Discipline;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\DomainEventDispatcher;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Moderation\Discipline\DatabaseDisciplineAuthenticationAvailability;
use Forwext\Core\Moderation\Discipline\DisciplineAction;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineAppealHookEvent;
use Forwext\Core\Moderation\Discipline\DisciplineNotifier;
use Forwext\Core\Moderation\Discipline\DisciplineRepository;
use Forwext\Core\Moderation\Discipline\DisciplineRestrictionKey;
use Forwext\Core\Moderation\Discipline\DisciplineService;
use Forwext\Core\Moderation\Discipline\WarningDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DisciplineSystemTest extends TestCase
{
    public function testWarningUsesDefinitionPointsExpiryAuditNotificationAndAppealHook(): void
    {
        $actor = $this->user('1', 'moderator');
        $target = $this->user('2', 'member');
        $repository = new DisciplineMemoryRepository([
            new WarningDefinition('standard', 'Standart', 'Orta seviye ihlal.', 3, 90, true, 10),
        ]);
        $audit = new DisciplineMemoryAuditStore();
        $notifier = new DisciplineMemoryNotifier();
        $events = new DomainEventDispatcher();
        $captured = [];
        $events->listen('moderation.discipline.appeal_available', static function ($event) use (&$captured): void {
            $captured[] = $event;
        });
        $service = $this->service(
            $actor,
            [$actor, $target],
            $repository,
            $audit,
            $notifier,
            [
                'moderation.access',
                DisciplineService::WARNING_ISSUE_PERMISSION,
            ],
            $events,
        );
        $at = new DateTimeImmutable('2026-09-18T10:00:00+00:00');

        $action = $service->issueWarning(
            $target->id(),
            'standard',
            ModerationReasonCode::fromString('rules.spam'),
            'Tekrarlanan istenmeyen içerik.',
            ModerationRequestId::fromString('discipline-warning-test'),
            $at,
        );

        self::assertSame(3, $action->points);
        self::assertSame('standard', $action->warningDefinitionKey);
        self::assertSame('2026-12-17 10:00:00', $action->expiresAt?->format('Y-m-d H:i:s'));
        self::assertSame($action, $repository->actions[$action->actionId->value()] ?? null);
        self::assertSame([ModerationAuditAction::WarningIssue], array_map(
            static fn (ModerationAuditEvent $event): ModerationAuditAction => $event->action,
            $audit->events,
        ));
        self::assertSame([$action->actionId->value()], $notifier->issuedIds);
        self::assertCount(1, $captured);
        self::assertInstanceOf(DisciplineAppealHookEvent::class, $captured[0]);
        self::assertSame('discipline:' . $action->actionId->value(), $captured[0]->appealReference);
    }

    public function testSuspensionIsTemporaryAndBanMayBePermanent(): void
    {
        $actor = $this->user('1', 'moderator');
        $target = $this->user('2', 'member');
        $repository = new DisciplineMemoryRepository();
        $service = $this->service(
            $actor,
            [$actor, $target],
            $repository,
            new DisciplineMemoryAuditStore(),
            new DisciplineMemoryNotifier(),
            ['moderation.access', DisciplineService::BAN_MANAGE_PERMISSION],
        );
        $at = new DateTimeImmutable('2026-09-18T11:00:00+00:00');

        $suspension = $service->suspend(
            $target->id(),
            ModerationReasonCode::fromString('discipline.temp'),
            'Geçici inceleme kısıtı.',
            ModerationRequestId::fromString('discipline-suspend-test'),
            $at->modify('+24 hours'),
            $at,
        );
        $ban = $service->ban(
            $target->id(),
            ModerationReasonCode::fromString('discipline.permanent'),
            'Kalıcı ban gerekçesi.',
            ModerationRequestId::fromString('discipline-ban-test'),
            null,
            $at,
        );

        self::assertSame(DisciplineActionType::Suspension, $suspension->type);
        self::assertFalse($suspension->isPermanent());
        self::assertSame(DisciplineActionType::Ban, $ban->type);
        self::assertTrue($ban->isPermanent());
    }

    public function testModeratorCannotApplyDisciplineToOwnAccount(): void
    {
        $actor = $this->user('1', 'moderator');
        $repository = new DisciplineMemoryRepository([
            new WarningDefinition('minor', 'Hafif', '', 1, 30, true),
        ]);
        $service = $this->service(
            $actor,
            [$actor],
            $repository,
            new DisciplineMemoryAuditStore(),
            new DisciplineMemoryNotifier(),
            ['moderation.access', DisciplineService::WARNING_ISSUE_PERMISSION],
        );

        $this->expectException(InvalidArgumentException::class);
        try {
            $service->issueWarning(
                $actor->id(),
                'minor',
                ModerationReasonCode::fromString('rules.self'),
                'Kendi hesabına işlem denenmemeli.',
                ModerationRequestId::fromString('discipline-self-test'),
                new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
            );
        } finally {
            self::assertSame([], $repository->actions);
        }
    }

    public function testDatabaseAuthenticationAvailabilityFailsClosedForActiveBanOrSuspension(): void
    {
        $database = new DisciplineAvailabilityDatabase();
        $availability = new DatabaseDisciplineAuthenticationAvailability($database);
        $userId = EntityId::fromString(str_repeat('a', 32));

        $database->value = 1;
        self::assertFalse($availability->allows($userId));
        self::assertSame($userId->value(), $database->lastQuery?->parameters['user_id']);

        $database->value = 0;
        self::assertTrue($availability->allows($userId));
    }

    public function testRestrictionActionRequiresTypedRestrictionAndCarriesAppealReference(): void
    {
        $action = new DisciplineAction(
            EntityId::fromString(str_repeat('a', 32)),
            EntityId::fromString(str_repeat('b', 32)),
            EntityId::fromString(str_repeat('c', 32)),
            DisciplineActionType::Restriction,
            ModerationReasonCode::fromString('rules.posting'),
            'Posting restriction.',
            0,
            null,
            [DisciplineRestrictionKey::Posting],
            true,
            new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
            new DateTimeImmutable('2026-09-19T10:00:00+00:00'),
        );

        self::assertSame('discipline:' . str_repeat('a', 32), $action->appealReference());
        self::assertTrue($action->isActiveAt(new DateTimeImmutable('2026-09-18T11:00:00+00:00')));
        self::assertSame('expired', $action->statusAt(new DateTimeImmutable('2026-09-20T10:00:00+00:00')));
    }

    /**
     * @param list<User> $users
     * @param list<string> $permissions
     */
    private function service(
        User $actor,
        array $users,
        DisciplineMemoryRepository $repository,
        DisciplineMemoryAuditStore $audit,
        DisciplineMemoryNotifier $notifier,
        array $permissions,
        ?DomainEventDispatcher $events = null,
    ): DisciplineService {
        $assignment = new UserAccessAssignment($actor->id(), EntityId::fromString('group:moderator'));
        $gate = new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new DisciplinePermissionRepository($actor->id(), $permissions)),
                new DisciplineAssignmentProvider($assignment),
            ),
            $actor->id(),
        );

        return new DisciplineService(
            new DisciplineTransactionDatabase(),
            $repository,
            new DisciplineUserRepository($users),
            $gate,
            $audit,
            $notifier,
            $events,
        );
    }

    private function user(string $seed, string $username): User
    {
        $at = new DateTimeImmutable('2026-09-18T09:00:00+00:00');
        return User::create(
            EntityId::fromString(str_repeat($seed, 32)),
            Username::fromString($username),
            EmailAddress::fromString($username . '@example.test'),
            UserStatus::Active,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('Europe/Istanbul'),
            $at,
        );
    }
}

final class DisciplineMemoryRepository implements DisciplineRepository
{
    /** @var array<string,WarningDefinition> */
    public array $definitions = [];
    /** @var array<string,DisciplineAction> */
    public array $actions = [];

    /** @param list<WarningDefinition> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->key] = $definition;
        }
    }

    public function warningDefinitions(bool $includeInactive = false): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (WarningDefinition $definition): bool => $includeInactive || $definition->active,
        ));
    }

    public function warningDefinition(string $key): ?WarningDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function saveWarningDefinition(WarningDefinition $definition, DateTimeImmutable $at): void
    {
        $this->definitions[$definition->key] = $definition;
    }

    public function insertAction(DisciplineAction $action): void
    {
        $this->actions[$action->actionId->value()] = $action;
    }

    public function action(EntityId $actionId): ?DisciplineAction
    {
        return $this->actions[$actionId->value()] ?? null;
    }

    public function revoke(EntityId $actionId, EntityId $actorUserId, string $reason, DateTimeImmutable $at): DisciplineAction
    {
        $before = $this->actions[$actionId->value()] ?? throw new InvalidArgumentException('Missing action.');
        $after = new DisciplineAction(
            $before->actionId,
            $before->userId,
            $before->actorUserId,
            $before->type,
            $before->reasonCode,
            $before->reasonText,
            $before->points,
            $before->warningDefinitionKey,
            $before->restrictions,
            $before->appealable,
            $before->startsAt,
            $before->expiresAt,
            $at,
            $actorUserId,
            $reason,
        );
        return $this->actions[$actionId->value()] = $after;
    }

    public function forUser(EntityId $userId, int $limit = 100): array
    {
        return array_slice(array_values(array_filter(
            $this->actions,
            static fn (DisciplineAction $action): bool => $action->userId->equals($userId),
        )), 0, $limit);
    }

    public function recent(array $types, int $limit = 50): array
    {
        return array_slice(array_values(array_filter(
            $this->actions,
            static fn (DisciplineAction $action): bool => in_array($action->type, $types, true),
        )), 0, $limit);
    }

    public function activeCount(array $types, DateTimeImmutable $at): int
    {
        return count(array_filter(
            $this->actions,
            static fn (DisciplineAction $action): bool =>
                in_array($action->type, $types, true) && $action->isActiveAt($at),
        ));
    }

    public function activePoints(EntityId $userId, DateTimeImmutable $at): int
    {
        $points = 0;
        foreach ($this->actions as $action) {
            if ($action->userId->equals($userId)
                && $action->type === DisciplineActionType::Warning
                && $action->isActiveAt($at)
            ) {
                $points += $action->points;
            }
        }
        return $points;
    }
}

final class DisciplineUserRepository implements UserRepository
{
    /** @var array<string,User> */
    private array $users = [];

    /** @param list<User> $users */
    public function __construct(array $users)
    {
        foreach ($users as $user) $this->users[$user->id()->value()] = $user;
    }

    public function find(EntityId $id): ?User { return $this->users[$id->value()] ?? null; }
    public function findByUsername(Username $username): ?User
    {
        foreach ($this->users as $user) if ($user->username()->key() === $username->key()) return $user;
        return null;
    }
    public function findByEmail(EmailAddress $email): ?User
    {
        foreach ($this->users as $user) if ($user->email()->key() === $email->key()) return $user;
        return null;
    }
    public function save(User $user): void { $this->users[$user->id()->value()] = $user; }
    public function history(EntityId $id, int $limit = 100, int $offset = 0): array { return []; }
}

final class DisciplineMemoryAuditStore implements ModerationAuditStore
{
    /** @var list<ModerationAuditEvent> */
    public array $events = [];
    public function append(ModerationAuditEvent $event): void { $this->events[] = $event; }
    public function recentForTarget(string $targetType, string $targetId, int $limit = 100): array { return []; }
}

final class DisciplineMemoryNotifier implements DisciplineNotifier
{
    /** @var list<string> */
    public array $issuedIds = [];
    /** @var list<string> */
    public array $revokedIds = [];
    public function issued(DisciplineAction $action): void { $this->issuedIds[] = $action->actionId->value(); }
    public function revoked(DisciplineAction $action): void { $this->revokedIds[] = $action->actionId->value(); }
}

final readonly class DisciplineAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment) {}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final readonly class DisciplinePermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private EntityId $actor, private array $permissions) {}

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($nodeId !== null || !$assignment->userId()->equals($this->actor)
            || !in_array($key->value(), $this->permissions, true)
        ) {
            return [];
        }
        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            PermissionEffect::Allow,
        )];
    }
}

final class DisciplineTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;
    public function execute(CompiledQuery $query): int { return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }
    public function transaction(Closure $callback): mixed
    {
        $previous = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $previous;
        }
    }
}

final class DisciplineAvailabilityDatabase implements QueryExecutor
{
    public int $value = 0;
    public ?CompiledQuery $lastQuery = null;
    public function execute(CompiledQuery $query): int { return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->lastQuery = $query;
        return $this->value;
    }
}
