<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Bug\Diagnostic;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Auth\AuthenticationFingerprint;
use Forwext\Core\Bug\Diagnostic\BugBrowserDeviceClassifier;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContext;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextCollector;
use Forwext\Core\Bug\Diagnostic\BugDiagnosticContextRepository;
use Forwext\Core\Bug\Diagnostic\BugReportSubmissionService;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportHistoryEntry;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Database\CompiledQuery;
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
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Routing\Router;
use Forwext\Core\Security\Secret\SecretStore;
use LogicException;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

final class BugDiagnosticContextTest extends TestCase
{
    public function testCollectorDropsQuerySecretsAndBuildsPrivacySafeTechnicalContext(): void
    {
        $collector = $this->collector();
        $forumId = str_repeat('a', 32);
        $threadId = str_repeat('b', 32);
        $postId = str_repeat('c', 32);
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36';

        $request = (new Request(
            HttpMethod::Get,
            '/forums/' . $forumId . '/threads/' . $threadId . '?token=top-secret&email=user@example.com',
            new HeaderBag(['User-Agent'=>$ua]),
        ))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'forum.thread.view')
            ->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, [
                'forumId'=>$forumId,
                'threadId'=>$threadId,
                'postId'=>$postId,
            ])
            ->withAttribute(BugDiagnosticContextCollector::ATTRIBUTE_THEME_KEY, 'dark')
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-12345678');

        $context = $collector->collect(
            $request,
            EntityId::fromString(str_repeat('d', 32)),
            EntityId::fromString(str_repeat('1', 32)),
            $this->time('2026-09-18 19:00:00.000000'),
        );

        self::assertSame('/forums/' . $forumId . '/threads/' . $threadId, $context->urlPath);
        self::assertStringNotContainsString('top-secret', $context->urlPath);
        self::assertStringNotContainsString('user@example.com', $context->urlPath);
        self::assertSame('forum.thread.view', $context->routeName);
        self::assertSame($forumId, $context->forumId?->value());
        self::assertSame($threadId, $context->threadId?->value());
        self::assertSame($postId, $context->postId?->value());
        self::assertSame('dark', $context->themeKey);
        self::assertSame('forum', $context->moduleKey);
        self::assertSame('chrome', $context->client->browserFamily);
        self::assertSame(142, $context->client->browserMajor);
        self::assertSame('android', $context->client->osFamily);
        self::assertSame('mobile', $context->client->deviceClass);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $context->client->userAgentFingerprint);
        self::assertNotSame($ua, $context->client->userAgentFingerprint);
        self::assertSame('req-12345678', $context->requestId);
    }

    public function testCollectorUsesSafeFallbacksWithoutInventingEntityContext(): void
    {
        $request = (new Request(HttpMethod::Get, '/support/new?draft=1'))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'support.ticket.new')
            ->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, [
                'forumId'=>'not-an-id',
                'threadId'=>'123',
            ])
            ->withAttribute(BugDiagnosticContextCollector::ATTRIBUTE_THEME_KEY, '<invalid>');

        $context = $this->collector()->collect(
            $request,
            EntityId::fromString(str_repeat('e', 32)),
            null,
            $this->time('2026-09-18 19:05:00.000000'),
        );

        self::assertSame('/support/new', $context->urlPath);
        self::assertSame('default', $context->themeKey);
        self::assertSame('support', $context->moduleKey);
        self::assertNull($context->forumId);
        self::assertNull($context->threadId);
        self::assertNull($context->postId);
        self::assertSame('unknown', $context->client->browserFamily);
        self::assertSame('unknown', $context->client->deviceClass);
        self::assertNull($context->client->userAgentFingerprint);
        self::assertNull($context->requestId);
    }

    public function testSubmissionPersistsReportAndDiagnosticContextInsideOneTransaction(): void
    {
        $database = new DiagnosticTransactionDatabase();
        $actor = EntityId::fromString(str_repeat('1', 32));
        $reports = new DiagnosticBugReportRepository($database);
        $reports->category = new BugReportCategory(
            'general',
            'General',
            '',
            BugReportSeverity::Medium,
            10,
            true,
        );
        $diagnostics = new DiagnosticMemoryContextRepository($database);
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new DiagnosticPermissionRepository($actor, ['bug.report.create','bug.report.view_own'])),
            new DiagnosticAssignmentProvider($actor),
        );
        $reportService = new BugReportService(
            $database,
            $reports,
            new PermissionGate($authorizer, $actor),
            $authorizer,
        );
        $submission = new BugReportSubmissionService(
            $database,
            $reportService,
            $this->collector(),
            $diagnostics,
        );
        $request = (new Request(
            HttpMethod::Post,
            '/threads/' . str_repeat('b', 32) . '?csrf=secret',
            new HeaderBag(['User-Agent'=>'Mozilla/5.0 Firefox/145.0']),
        ))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_NAME, 'thread.reply')
            ->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, ['threadId'=>str_repeat('b', 32)])
            ->withAttribute(RequestIdMiddleware::ATTRIBUTE, 'req-submit-1234');

        $receipt = $submission->create(
            $request,
            'general',
            'Broken reply',
            'Reply action fails.',
            null,
            $this->time('2026-09-18 19:10:00.000000'),
        );

        self::assertTrue($reports->createdInsideTransaction);
        self::assertTrue($diagnostics->savedInsideTransaction);
        self::assertSame($receipt->report->reportId->value(), $receipt->diagnosticContext->reportId->value());
        self::assertSame($actor->value(), $receipt->diagnosticContext->actorUserId?->value());
        self::assertSame('thread', $receipt->diagnosticContext->moduleKey);
        self::assertStringNotContainsString('csrf', $receipt->diagnosticContext->urlPath);
    }

    private function collector(): BugDiagnosticContextCollector
    {
        return new BugDiagnosticContextCollector(new BugBrowserDeviceClassifier(
            new AuthenticationFingerprint(new DiagnosticSecretStore([
                'authentication.fingerprint_key'=>str_repeat('k', 64),
            ])),
        ));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class DiagnosticTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside = false;

    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }

    public function transaction(Closure $callback): mixed
    {
        $before = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $before;
        }
    }
}

final class DiagnosticBugReportRepository implements BugReportRepository
{
    public ?BugReportCategory $category = null;
    public bool $createdInsideTransaction = false;
    /** @var array<string,BugReport> */
    public array $reports = [];
    /** @var list<BugReportHistoryEntry> */
    public array $historyEntries = [];

    public function __construct(private DiagnosticTransactionDatabase $database)
    {
    }

    public function activeCategories(): array { return $this->category === null ? [] : [$this->category]; }
    public function category(string $key): ?BugReportCategory { return $this->category?->key === $key ? $this->category : null; }
    public function saveCategory(BugReportCategory $category): void { $this->category = $category; }

    public function create(BugReport $report): void
    {
        $this->createdInsideTransaction = $this->database->inTransaction();
        $this->reports[$report->reportId->value()] = $report;
    }

    public function find(EntityId $reportId): ?BugReport { return $this->reports[$reportId->value()] ?? null; }
    public function forReporter(EntityId $reporterUserId, int $limit = 50): array { return []; }
    public function assign(EntityId $reportId, ?EntityId $assignedUserId, int $expectedVersion, DateTimeImmutable $now): BugReport { throw new LogicException('Unused.'); }
    public function changeStatus(EntityId $reportId, BugReportStatus $status, ?DateTimeImmutable $finalizedAt, int $expectedVersion, DateTimeImmutable $now): BugReport { throw new LogicException('Unused.'); }
    public function changeSeverity(EntityId $reportId, BugReportSeverity $severity, int $expectedVersion, DateTimeImmutable $now): BugReport { throw new LogicException('Unused.'); }
    public function changeCategory(EntityId $reportId, string $categoryKey, int $expectedVersion, DateTimeImmutable $now): BugReport { throw new LogicException('Unused.'); }
    public function appendHistory(BugReportHistoryEntry $entry): void { $this->historyEntries[] = $entry; }
    public function history(EntityId $reportId, bool $includeStaff, int $limit = 200): array { return []; }
}

final class DiagnosticMemoryContextRepository implements BugDiagnosticContextRepository
{
    public bool $savedInsideTransaction = false;
    public ?BugDiagnosticContext $context = null;

    public function __construct(private DiagnosticTransactionDatabase $database)
    {
    }

    public function save(BugDiagnosticContext $context): void
    {
        $this->savedInsideTransaction = $this->database->inTransaction();
        $this->context = $context;
    }

    public function find(EntityId $reportId): ?BugDiagnosticContext
    {
        return $this->context?->reportId->equals($reportId) ? $this->context : null;
    }
}

final readonly class DiagnosticAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f', 32)))
            : null;
    }
}

final readonly class DiagnosticPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(private EntityId $actor, private array $permissions)
    {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if ($nodeId !== null
            || !$assignment->userId()->equals($this->actor)
            || !in_array($key->value(), $this->permissions, true)
        ) {
            return [];
        }

        return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow)];
    }
}

final class DiagnosticSecretStore implements SecretStore
{
    /** @param array<string,string> $secrets */
    public function __construct(private array $secrets)
    {
    }

    public function has(string $name): bool { return array_key_exists($name, $this->secrets); }
    public function get(string $name): ?string { return $this->secrets[$name] ?? null; }
    public function set(string $name, #[SensitiveParameter] string $value): void { $this->secrets[$name] = $value; }
    public function delete(string $name): bool
    {
        if (!array_key_exists($name, $this->secrets)) return false;
        unset($this->secrets[$name]);
        return true;
    }
    public function all(): array { return $this->secrets; }
}
