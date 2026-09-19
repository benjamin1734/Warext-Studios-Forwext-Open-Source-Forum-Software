<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\EasterEgg;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\EasterEgg\EasterEggMiddleware;
use Forwext\App\Web\EasterEgg\EasterEggRenderer;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\EasterEgg\EasterEggAnimation;
use Forwext\Core\EasterEgg\EasterEggDefinition;
use Forwext\Core\EasterEgg\EasterEggGroupOption;
use Forwext\Core\EasterEgg\EasterEggRepository;
use Forwext\Core\EasterEgg\EasterEggRuntimeContext;
use Forwext\Core\EasterEgg\EasterEggService;
use Forwext\Core\EasterEgg\EasterEggTriggerType;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\CallableRequestHandler;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EasterEggServiceMiddlewareTest extends TestCase
{
    public function testGlobalKillSwitchDateRouteTokenAndGroupVisibilityAreEnforced(): void
    {
        $repo = new MemoryEasterEggRepository();
        $group = EntityId::fromString('11111111111111111111111111111111');
        $now = $this->at('2026-09-19 16:00:00');

        $auto = $this->egg(
            'group-surprise',
            EasterEggTriggerType::Automatic,
            null,
            'giveaway.index',
            '/giveaways*',
            $now->modify('-1 hour'),
            $now->modify('+1 hour'),
        );
        $token = $this->egg(
            'query-surprise',
            EasterEggTriggerType::QueryToken,
            'open-sesame',
            'giveaway.index',
            '/giveaways*',
            null,
            null,
        );
        $repo->save($auto);
        $repo->save($token);
        $repo->replaceGroups($auto->easterEggId, [$group]);

        $service = $this->service($repo, []);

        self::assertSame([], $service->activeFor(new EasterEggRuntimeContext(
            'giveaway.index',
            '/giveaways',
            'open-sesame',
            [$group],
            $now,
        )), 'Global kill-switch must default to off.');

        $repo->setGlobalEnabled(true, $now);

        $visible = $service->activeFor(new EasterEggRuntimeContext(
            'giveaway.index',
            '/giveaways/active',
            'open-sesame',
            [$group],
            $now,
        ));
        self::assertCount(2, $visible);

        $withoutGroup = $service->activeFor(new EasterEggRuntimeContext(
            'giveaway.index',
            '/giveaways',
            'open-sesame',
            [],
            $now,
        ));
        self::assertCount(1, $withoutGroup);
        self::assertSame('query-surprise', $withoutGroup[0]->key);

        self::assertSame([], $service->activeFor(new EasterEggRuntimeContext(
            'forum.index',
            '/forums',
            'open-sesame',
            [$group],
            $now,
        )));
    }

    public function testManagementPermissionAndAuditAreBackendAuthoritative(): void
    {
        $repo = new MemoryEasterEggRepository();
        $manager = UserId::generate();
        $member = UserId::generate();
        $audit = new EasterEggAuditRecorder();
        $service = $this->service($repo, [
            $manager->value()=>['easteregg.manage'=>true],
            $member->value()=>['easteregg.manage'=>false],
        ], $audit);

        try {
            $service->manageList($member);
            self::fail('Member without easteregg.manage should be denied.');
        } catch (PermissionDeniedException) {
            self::assertFalse($repo->globalEnabled());
        }

        $at = $this->at('2026-09-19 16:10:00');
        $service->setGlobalEnabled($manager, true, $at);
        self::assertTrue($repo->globalEnabled());

        $egg = $this->egg('managed-egg', EasterEggTriggerType::Automatic, null, 'home', '/', null, null);
        $service->save($manager, $egg, [], $at);

        self::assertCount(2, $audit->events);
        self::assertSame('easteregg.global_toggle', $audit->events[0]->action->value());
        self::assertSame('easteregg.create', $audit->events[1]->action->value());
    }

    public function testMiddlewareEscapesMarkupAndFailsOpenWhenSubsystemBreaks(): void
    {
        $repo = new MemoryEasterEggRepository();
        $repo->setGlobalEnabled(true, $this->at('2026-09-19 16:00:00'));
        $egg = new EasterEggDefinition(
            EasterEggDefinition::generateId(),
            'safe-render',
            '<img src=x onerror=alert(1)>',
            true,
            100,
            EasterEggTriggerType::QueryToken,
            'secret',
            'demo.route',
            '/demo',
            null,
            null,
            '<script>alert(1)</script>',
            EasterEggAnimation::Glow,
            '<b>badge</b>',
            $this->at('2026-09-19 15:00:00'),
            $this->at('2026-09-19 15:00:00'),
        );
        $repo->save($egg);

        $service = $this->service($repo, []);
        $middleware = new EasterEggMiddleware(
            $service,
            new StaticEasterEggViewer(null),
            new StaticEasterEggAssignments([]),
            new BasePath(),
            new EasterEggRenderer(),
        );
        $request = (new Request(
            HttpMethod::Get,
            '/demo?egg=secret',
            new HeaderBag(),
            ['egg'=>'secret'],
        ))->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'demo.route');
        $response = $middleware->process(
            $request,
            new CallableRequestHandler(static fn (Request $_): Response => Response::html(
                '<!doctype html><html><body><main>Original</main></body></html>',
            )),
        );

        self::assertStringContainsString('data-easter-egg="safe-render"', $response->body());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
        self::assertStringContainsString('prefers-reduced-motion', $response->body());

        $broken = new EasterEggMiddleware(
            $this->service(new ThrowingEasterEggRepository(), []),
            new StaticEasterEggViewer(null),
            new StaticEasterEggAssignments([]),
            new BasePath(),
            new EasterEggRenderer(),
        );
        $original = Response::html('<html><body>Still works</body></html>');
        $fallback = $broken->process(
            $request,
            new CallableRequestHandler(static fn (Request $_): Response => $original),
        );
        self::assertSame($original->body(), $fallback->body());
        self::assertSame(200, $fallback->status());
    }

    private function service(
        EasterEggRepository $repo,
        array $permissions,
        ?EasterEggAuditRecorder $audit = null,
    ): EasterEggService {
        return new EasterEggService(
            new EasterEggDatabase(),
            $repo,
            new PermissionAuthorizer(
                new PermissionEngine(new EasterEggPermissionRules($permissions)),
                new EasterEggPermissionAssignments(array_keys($permissions)),
            ),
            $audit ?? new EasterEggAuditRecorder(),
        );
    }

    private function egg(
        string $key,
        EasterEggTriggerType $trigger,
        ?string $token,
        ?string $route,
        ?string $path,
        ?DateTimeImmutable $starts,
        ?DateTimeImmutable $ends,
    ): EasterEggDefinition {
        $created = $this->at('2026-09-19 15:00:00');
        return new EasterEggDefinition(
            EasterEggDefinition::generateId(),
            $key,
            ucfirst(str_replace('-', ' ', $key)),
            true,
            100,
            $trigger,
            $token,
            $route,
            $path,
            $starts,
            $ends,
            'Hidden surprise',
            EasterEggAnimation::Pulse,
            'Sürpriz',
            $created,
            $created,
        );
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

class MemoryEasterEggRepository implements EasterEggRepository
{
    protected bool $global = false;
    /** @var array<string,EasterEggDefinition> */
    protected array $items = [];
    /** @var array<string,list<EntityId>> */
    protected array $groups = [];

    public function globalEnabled(): bool { return $this->global; }
    public function setGlobalEnabled(bool $enabled, DateTimeImmutable $at): void { $this->global = $enabled; }
    public function all(int $limit = 200): array { return array_slice(array_values($this->items), 0, $limit); }
    public function find(EntityId $easterEggId): ?EasterEggDefinition { return $this->items[$easterEggId->value()] ?? null; }
    public function activeAt(DateTimeImmutable $at, int $limit = 50): array
    {
        return array_slice(array_values($this->items), 0, $limit);
    }
    public function save(EasterEggDefinition $definition): void { $this->items[$definition->easterEggId->value()] = $definition; }
    public function groupIds(EntityId $easterEggId): array { return $this->groups[$easterEggId->value()] ?? []; }
    public function replaceGroups(EntityId $easterEggId, array $groupIds): void { $this->groups[$easterEggId->value()] = array_values($groupIds); }
    public function availableGroups(): array
    {
        return [new EasterEggGroupOption(
            EntityId::fromString('11111111111111111111111111111111'),
            'Members',
        )];
    }
}

final class ThrowingEasterEggRepository extends MemoryEasterEggRepository
{
    public function globalEnabled(): bool
    {
        throw new RuntimeException('Simulated Easter Egg storage outage.');
    }
}

final class StaticEasterEggViewer implements ProfileViewerResolver
{
    public function __construct(private ?EntityId $viewer) {}
    public function resolve(Request $request): ?EntityId { return $this->viewer; }
}

final class StaticEasterEggAssignments implements UserAccessAssignmentProvider
{
    /** @param array<string,UserAccessAssignment> $assignments */
    public function __construct(private array $assignments) {}
    public function find(EntityId $userId): ?UserAccessAssignment { return $this->assignments[$userId->value()] ?? null; }
}

final readonly class EasterEggPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions) {}
    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }
    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        $allowed = $this->permissions[$assignment->userId()->value()][$key->value()] ?? false;
        return [new PermissionRule(
            PermissionSubjectType::User,
            $assignment->userId(),
            $allowed ? PermissionEffect::Allow : PermissionEffect::Deny,
        )];
    }
}

final readonly class EasterEggPermissionAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $users;
    /** @param list<string> $ids */
    public function __construct(array $ids) { $this->users = array_fill_keys($ids, true); }
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!isset($this->users[$userId->value()])) return null;
        return new UserAccessAssignment($userId, EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }
}

final class EasterEggAuditRecorder implements AuditRecorder
{
    /** @var list<AuditEvent> */
    public array $events = [];
    public function append(AuditEvent $event): void { $this->events[] = $event; }
    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        $result = $mutation();
        $this->events[] = $event;
        return $result;
    }
}

final class EasterEggDatabase implements TransactionalQueryExecutor
{
    private int $depth = 0;
    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->depth > 0; }
    public function transaction(Closure $callback): mixed
    {
        ++$this->depth;
        try { return $callback($this); } finally { --$this->depth; }
    }
}
