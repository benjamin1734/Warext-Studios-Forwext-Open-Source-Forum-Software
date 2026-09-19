<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Portfolio;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Pipeline\AbuseContentPipelineProcessor;
use Forwext\Core\Content\Pipeline\ContentPipeline;
use Forwext\Core\Content\Pipeline\DefaultContentValidationProcessor;
use Forwext\Core\Content\Pipeline\DefaultModerationPolicyProcessor;
use Forwext\Core\Content\Pipeline\PassThroughAiModerationProcessor;
use Forwext\Core\Content\Pipeline\PassThroughSpellcheckProcessor;
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
use Forwext\Core\Portfolio\PortfolioCategory;
use Forwext\Core\Portfolio\PortfolioComment;
use Forwext\Core\Portfolio\PortfolioCommentState;
use Forwext\Core\Portfolio\PortfolioProject;
use Forwext\Core\Portfolio\PortfolioReactionSummary;
use Forwext\Core\Portfolio\PortfolioRepository;
use Forwext\Core\Portfolio\PortfolioService;
use Forwext\Core\Portfolio\PortfolioState;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PortfolioPermissionLifecycleTest extends TestCase
{
    public function testOrdinaryOwnerPublishRequestQueuesReviewAndCannotSelfFeature(): void
    {
        $owner = UserId::generate();
        $repo = new MemoryPortfolioRepository();
        $service = $this->service($repo, [
            $owner->value() => [
                'portfolio.create' => true,
                'portfolio.manage_own' => true,
                'portfolio.view' => true,
            ],
        ]);

        $candidate = $this->project($owner, PortfolioState::Published, featured: true);
        $saved = $service->saveProject($owner, $candidate);

        self::assertSame(PortfolioState::Pending, $saved->state);
        self::assertFalse($saved->featured);
        self::assertSame($saved, $repo->project($saved->projectId));
        self::assertSame('project.create', $repo->history[0]['action'] ?? null);
    }

    public function testManageAllCanPublishAndFeatureWithoutBypassingPipelineValidation(): void
    {
        $staff = UserId::generate();
        $repo = new MemoryPortfolioRepository();
        $service = $this->service($repo, [
            $staff->value() => [
                'portfolio.create' => true,
                'portfolio.manage_all' => true,
                'portfolio.view' => true,
            ],
        ]);

        $candidate = $this->project($staff, PortfolioState::Published, featured: true);
        $saved = $service->saveProject($staff, $candidate);

        self::assertSame(PortfolioState::Published, $saved->state);
        self::assertTrue($saved->featured);
    }

    public function testDifferentUserCannotEditProjectWithoutManageAllPermission(): void
    {
        $owner = UserId::generate();
        $attacker = UserId::generate();
        $repo = new MemoryPortfolioRepository();
        $existing = $this->project($owner, PortfolioState::Published);
        $repo->saveProject($existing);

        $service = $this->service($repo, [
            $owner->value() => [
                'portfolio.manage_own' => true,
                'portfolio.view' => true,
            ],
            $attacker->value() => [
                'portfolio.create' => true,
                'portfolio.manage_own' => true,
                'portfolio.view' => true,
            ],
        ]);

        $tampered = new PortfolioProject(
            $existing->projectId,
            $owner,
            'general',
            'stolen-edit',
            'Tampered',
            '',
            'Unauthorized edit',
            [],
            [],
            PortfolioState::Published,
            true,
            $existing->createdAt,
            $existing->updatedAt,
        );

        $this->expectException(PermissionDeniedException::class);
        $service->saveProject($attacker, $tampered);
    }

    public function testCommentPermissionAndSelfReactionRulesRemainBackendAuthoritative(): void
    {
        $owner = UserId::generate();
        $member = UserId::generate();
        $repo = new MemoryPortfolioRepository();
        $project = $this->project($owner, PortfolioState::Published);
        $repo->saveProject($project);

        $service = $this->service($repo, [
            $owner->value() => [
                'portfolio.view' => true,
                'portfolio.reaction.use' => true,
            ],
            $member->value() => [
                'portfolio.view' => true,
                'portfolio.comment.create' => true,
                'portfolio.reaction.use' => true,
            ],
        ]);

        $comment = $service->addComment($member, $project->projectId, 'Useful project.');
        self::assertSame(PortfolioCommentState::Visible, $comment->state);
        self::assertCount(1, $repo->comments($project->projectId));

        $this->expectException(InvalidArgumentException::class);
        $service->react($owner, $project->projectId, 'like');
    }

    private function service(MemoryPortfolioRepository $repo, array $permissions): PortfolioService
    {
        $db = new MemoryTransactionalQueryExecutor();
        $rules = new MemoryPortfolioPermissionRules($permissions);
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine($rules),
            new MemoryPortfolioAssignments(array_keys($permissions)),
        );
        $pipeline = new ContentPipeline($db, [
            new DefaultContentValidationProcessor(),
            new AbuseContentPipelineProcessor(),
            new PassThroughSpellcheckProcessor(),
            new PassThroughAiModerationProcessor(),
            new DefaultModerationPolicyProcessor(),
        ]);

        return new PortfolioService(
            $db,
            $repo,
            $authorizer,
            $pipeline,
            new MemoryPortfolioSearchChanges(),
        );
    }

    private function project(
        EntityId $owner,
        PortfolioState $state,
        bool $featured = false,
    ): PortfolioProject {
        $now = new DateTimeImmutable('2026-09-19 10:00:00', new DateTimeZone('UTC'));
        return new PortfolioProject(
            PortfolioProject::generateId(),
            $owner,
            'general',
            'project-' . substr(bin2hex(random_bytes(8)), 0, 12),
            'Project',
            'Summary',
            'Description',
            ['forwext'],
            [],
            $state,
            $featured,
            $now,
            $now,
        );
    }
}

final class MemoryPortfolioRepository implements PortfolioRepository
{
    /** @var array<string,PortfolioProject> */
    private array $projects = [];
    /** @var array<string,list<PortfolioComment>> */
    private array $comments = [];
    /** @var array<string,array<string,string>> */
    private array $reactions = [];
    /** @var list<array{project:string,actor:string,action:string,from:?string,to:?string}> */
    public array $history = [];

    public function categories(bool $activeOnly = true): array
    {
        return [new PortfolioCategory('general', 'General', '', 10, true)];
    }

    public function category(string $key): ?PortfolioCategory
    {
        return $key === 'general' ? new PortfolioCategory('general', 'General', '', 10, true) : null;
    }

    public function saveCategory(PortfolioCategory $category): void
    {
    }

    public function project(EntityId $projectId): ?PortfolioProject
    {
        return $this->projects[$projectId->value()] ?? null;
    }

    public function projects(
        ?EntityId $ownerUserId = null,
        bool $publishedOnly = true,
        bool $featuredOnly = false,
        int $limit = 100,
    ): array {
        $items = array_values(array_filter(
            $this->projects,
            static fn (PortfolioProject $project): bool =>
                ($ownerUserId === null || $project->ownerUserId->equals($ownerUserId))
                && (!$publishedOnly || $project->state === PortfolioState::Published)
                && (!$featuredOnly || $project->featured),
        ));
        return array_slice($items, 0, $limit);
    }

    public function saveProject(PortfolioProject $project): void
    {
        $this->projects[$project->projectId->value()] = $project;
    }

    public function comments(EntityId $projectId, bool $visibleOnly = true, int $limit = 100): array
    {
        $items = $this->comments[$projectId->value()] ?? [];
        if ($visibleOnly) {
            $items = array_values(array_filter(
                $items,
                static fn (PortfolioComment $comment): bool => $comment->state === PortfolioCommentState::Visible,
            ));
        }
        return array_slice($items, 0, $limit);
    }

    public function saveComment(PortfolioComment $comment): void
    {
        $this->comments[$comment->projectId->value()][] = $comment;
    }

    public function setReaction(EntityId $actorUserId, EntityId $projectId, string $reactionKey): void
    {
        $this->reactions[$projectId->value()][$actorUserId->value()] = $reactionKey;
    }

    public function removeReaction(EntityId $actorUserId, EntityId $projectId): void
    {
        unset($this->reactions[$projectId->value()][$actorUserId->value()]);
    }

    public function reactionSummary(EntityId $projectId): PortfolioReactionSummary
    {
        $values = array_values($this->reactions[$projectId->value()] ?? []);
        $counts = array_count_values($values);
        return new PortfolioReactionSummary(count($values), count($values), $counts);
    }

    public function recordHistory(
        EntityId $projectId,
        EntityId $actorUserId,
        string $action,
        ?string $fromState,
        ?string $toState,
    ): void {
        $this->history[] = [
            'project' => $projectId->value(),
            'actor' => $actorUserId->value(),
            'action' => $action,
            'from' => $fromState,
            'to' => $toState,
        ];
    }
}

final readonly class MemoryPortfolioPermissionRules implements PermissionRuleRepository
{
    /** @param array<string,array<string,bool>> $permissions */
    public function __construct(private array $permissions)
    {
    }

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

final readonly class MemoryPortfolioAssignments implements UserAccessAssignmentProvider
{
    /** @var array<string,true> */
    private array $users;

    /** @param list<string> $userIds */
    public function __construct(array $userIds)
    {
        $this->users = array_fill_keys($userIds, true);
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        if (!isset($this->users[$userId->value()])) {
            return null;
        }
        return new UserAccessAssignment(
            $userId,
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        );
    }
}

final class MemoryTransactionalQueryExecutor implements TransactionalQueryExecutor
{
    private bool $transaction = false;

    public function execute(CompiledQuery $query): int
    {
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function transaction(Closure $callback): mixed
    {
        $previous = $this->transaction;
        $this->transaction = true;
        try {
            return $callback($this);
        } finally {
            $this->transaction = $previous;
        }
    }
}

final class MemoryPortfolioSearchChanges implements SearchIndexChangeStore
{
    /** @var list<array{type:string,id:string}> */
    public array $records = [];

    public function record(string $documentType, string $documentId): void
    {
        $this->records[] = ['type' => $documentType, 'id' => $documentId];
    }

    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array
    {
        return [];
    }

    public function acknowledge(SearchIndexChange $change): bool
    {
        return true;
    }

    public function retry(
        SearchIndexChange $change,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $errorCode,
    ): bool {
        return true;
    }
}
