<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Metadata;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
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
use Forwext\Core\Forum\Metadata\CustomFieldDefinition;
use Forwext\Core\Forum\Metadata\CustomFieldKey;
use Forwext\Core\Forum\Metadata\CustomFieldTarget;
use Forwext\Core\Forum\Metadata\CustomFieldType;
use Forwext\Core\Forum\Metadata\CustomFieldValue;
use Forwext\Core\Forum\Metadata\ForumContentConfiguration;
use Forwext\Core\Forum\Metadata\ForumMetadataAdminService;
use Forwext\Core\Forum\Metadata\ForumMetadataRepository;
use Forwext\Core\Forum\Metadata\MetadataOperationException;
use Forwext\Core\Forum\Metadata\PrefixGroup;
use Forwext\Core\Forum\Metadata\Tag;
use Forwext\Core\Forum\Metadata\TagName;
use Forwext\Core\Forum\Metadata\ThreadMetadata;
use Forwext\Core\Forum\Metadata\ThreadMetadataService;
use Forwext\Core\Forum\Metadata\ThreadPrefix;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use PHPUnit\Framework\TestCase;

final class ForumMetadataServiceTest extends TestCase
{
    public function testOwnerCanUpdateConfiguredMetadataWithEditOwnPermission(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $actor);
        $prefix = new ThreadPrefix($this->id('c'), $this->id('d'), 'Release', 0, true);
        $field = $this->threadField(true);
        $metadata = new MetadataServiceRepositoryStub(
            new ForumContentConfiguration(
                $forum->id(),
                [$prefix->groupId()],
                [$field->key()],
                true,
                false,
                2,
            ),
            [$prefix],
            [$field],
        );
        $service = $this->threadService($actor, $forum, $thread, $metadata, [
            'forum.view',
            'forum.thread.edit_own',
        ]);

        $result = $service->update(
            $thread->id(),
            $prefix->id(),
            ['PHP', 'Security'],
            ['thread.version' => '1.0'],
        );

        self::assertSame($prefix->id()->value(), $result->prefixId()?->value());
        self::assertCount(2, $result->tags());
        self::assertSame('1.0', $result->fieldValues()['thread.version']->value);
        self::assertSame($result, $metadata->lastThreadMetadata);
        self::assertFalse($metadata->lastAllowNewTags);
    }

    public function testEditOwnCannotModifyAnotherUsersThread(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $this->id('2'));
        $metadata = new MetadataServiceRepositoryStub(
            new ForumContentConfiguration($forum->id(), [], [], false, false, 0),
        );
        $service = $this->threadService($actor, $forum, $thread, $metadata, [
            'forum.view',
            'forum.thread.edit_own',
        ]);

        $this->expectException(PermissionDeniedException::class);
        $service->update($thread->id(), null, [], []);
    }

    public function testRequiredFieldAndPrefixEligibilityFailBeforePersistence(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $actor);
        $field = $this->threadField(true);
        $metadata = new MetadataServiceRepositoryStub(
            new ForumContentConfiguration($forum->id(), [], [$field->key()], false, false, 0),
            [],
            [$field],
        );
        $service = $this->threadService($actor, $forum, $thread, $metadata, [
            'forum.view',
            'forum.thread.edit_own',
        ]);

        try {
            $service->update($thread->id(), $this->id('c'), [], ['thread.version' => '1.0']);
            self::fail('Unconfigured prefix must fail.');
        } catch (MetadataOperationException) {
            self::assertNull($metadata->lastThreadMetadata);
        }

        $this->expectException(MetadataOperationException::class);
        $service->update($thread->id(), null, [], []);
    }

    public function testTagAutocompleteIsHiddenWhenForumTagsAreDisabled(): void
    {
        $actor = $this->id('1');
        $forum = $this->forum();
        $thread = $this->thread($forum->id(), $actor);
        $metadata = new MetadataServiceRepositoryStub(
            new ForumContentConfiguration($forum->id(), [], [], false, false, 0),
        );
        $metadata->autocomplete = [new Tag($this->id('e'), TagName::fromString('php'))];
        $service = $this->threadService($actor, $forum, $thread, $metadata, ['forum.view']);

        self::assertSame([], $service->autocompleteTags($forum->id(), 'ph'));
        self::assertSame(0, $metadata->autocompleteCalls);
    }

    public function testAdminConfigurationRequiresAcpManageAndRejectsForumFieldAsThreadField(): void
    {
        $actor = $this->id('9');
        $forum = $this->forum();
        $metadata = new MetadataServiceRepositoryStub(
            new ForumContentConfiguration($forum->id(), [], [], false, false, 0),
            [],
            [new CustomFieldDefinition(
                CustomFieldKey::fromString('forum.region'),
                CustomFieldTarget::Forum,
                'Region',
                CustomFieldType::Text,
            )],
        );
        $nodes = new MetadataServiceNodeRepository([$forum]);
        $denied = new ForumMetadataAdminService($nodes, $metadata, $this->gate($actor, $forum->id(), []));

        try {
            $denied->configureForum($forum->id(), [], [], false, false, 0);
            self::fail('ACP management must require permission.');
        } catch (PermissionDeniedException) {
            self::assertTrue(true);
        }

        $allowed = new ForumMetadataAdminService($nodes, $metadata, $this->gate($actor, $forum->id(), ['acp.manage']));
        $this->expectException(MetadataOperationException::class);
        $allowed->configureForum(
            $forum->id(),
            [],
            [CustomFieldKey::fromString('forum.region')],
            false,
            false,
            0,
        );
    }

    private function threadService(
        EntityId $actor,
        ForumNode $forum,
        Thread $thread,
        MetadataServiceRepositoryStub $metadata,
        array $permissions,
    ): ThreadMetadataService {
        return new ThreadMetadataService(
            new MetadataServiceNodeRepository([$forum]),
            new MetadataServiceThreadRepository([$thread]),
            $metadata,
            $this->gate($actor, $forum->id(), $permissions),
        );
    }

    /** @param list<string> $permissions */
    private function gate(EntityId $actor, EntityId $forumId, array $permissions): PermissionGate
    {
        return new PermissionGate(
            new PermissionAuthorizer(
                new PermissionEngine(new MetadataServicePermissionRepository($actor, $forumId, $permissions)),
                new MetadataServiceAssignmentProvider(new UserAccessAssignment($actor, $this->id('f'))),
            ),
            $actor,
        );
    }

    private function threadField(bool $required): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            CustomFieldKey::fromString('thread.version'),
            CustomFieldTarget::Thread,
            'Version',
            CustomFieldType::Text,
            $required,
            1,
            20,
        );
    }

    private function forum(): ForumNode
    {
        return ForumNode::forum(
            $this->id('a'),
            null,
            'Forum',
            ForumNodeSlug::fromString('forum'),
            new ForumSettings(),
        );
    }

    private function thread(EntityId $forumId, EntityId $author): Thread
    {
        return Thread::hydrate(
            $this->id('b'),
            $forumId,
            $author,
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Thread'),
            ThreadModerationState::Visible,
            false,
            false,
            false,
            $this->time('2026-09-15 21:00:00.000000'),
            $this->time('2026-09-15 21:00:00.000000'),
            1,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class MetadataServiceNodeRepository implements ForumNodeRepository
{
    /** @param list<ForumNode> $nodes */
    public function __construct(private array $nodes)
    {
    }

    public function find(EntityId $nodeId): ?ForumNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id()->equals($nodeId)) {
                return $node;
            }
        }
        return null;
    }

    public function findBySlug(ForumNodeSlug $slug): ?ForumNode
    {
        return null;
    }

    public function all(): array
    {
        return $this->nodes;
    }

    public function save(ForumNode $node): void
    {
        throw new \LogicException('Not used.');
    }

    public function delete(EntityId $nodeId): void
    {
        throw new \LogicException('Not used.');
    }
}

final class MetadataServiceThreadRepository implements ThreadRepository
{
    /** @var array<string, Thread> */
    private array $threads = [];

    /** @param list<Thread> $threads */
    public function __construct(array $threads)
    {
        foreach ($threads as $thread) {
            $this->threads[$thread->id()->value()] = $thread;
        }
    }

    public function find(EntityId $threadId): ?Thread
    {
        return $this->threads[$threadId->value()] ?? null;
    }

    public function findByForum(EntityId $forumNodeId, int $limit = 50, int $offset = 0): array
    {
        return [];
    }

    public function save(Thread $thread): void
    {
        throw new \LogicException('Not used.');
    }
}

final class MetadataServiceRepositoryStub implements ForumMetadataRepository
{
    /** @var array<string, CustomFieldDefinition> */
    private array $definitions = [];
    public ?ThreadMetadata $lastThreadMetadata = null;
    public bool $lastAllowNewTags = false;
    /** @var list<Tag> */
    public array $autocomplete = [];
    public int $autocompleteCalls = 0;

    /**
     * @param list<ThreadPrefix> $prefixes
     * @param list<CustomFieldDefinition> $definitions
     */
    public function __construct(
        private ForumContentConfiguration $configuration,
        private array $prefixes = [],
        array $definitions = [],
    ) {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->key()->value()] = $definition;
        }
    }

    public function savePrefixGroup(PrefixGroup $group): void
    {
    }

    public function savePrefix(ThreadPrefix $prefix): void
    {
        $this->prefixes[] = $prefix;
    }

    public function findPrefix(EntityId $prefixId): ?ThreadPrefix
    {
        foreach ($this->prefixes as $prefix) {
            if ($prefix->id()->equals($prefixId)) {
                return $prefix;
            }
        }
        return null;
    }

    public function prefixesForForum(EntityId $forumNodeId): array
    {
        return $this->prefixes;
    }

    public function configuration(EntityId $forumNodeId): ForumContentConfiguration
    {
        return $this->configuration;
    }

    public function saveConfiguration(ForumContentConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    public function saveFieldDefinition(CustomFieldDefinition $definition): void
    {
        $this->definitions[$definition->key()->value()] = $definition;
    }

    public function fieldDefinition(CustomFieldKey $key): ?CustomFieldDefinition
    {
        return $this->definitions[$key->value()] ?? null;
    }

    public function fieldDefinitions(CustomFieldTarget $target): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (CustomFieldDefinition $definition): bool => $definition->target() === $target,
        ));
    }

    public function autocompleteTags(string $query, int $limit = 10): array
    {
        $this->autocompleteCalls++;
        return $this->autocomplete;
    }

    public function threadMetadata(EntityId $threadId): ThreadMetadata
    {
        return $this->lastThreadMetadata ?? new ThreadMetadata(null, [], []);
    }

    public function replaceThreadMetadata(EntityId $threadId, ThreadMetadata $metadata, bool $allowNewTags): void
    {
        $this->lastThreadMetadata = $metadata;
        $this->lastAllowNewTags = $allowNewTags;
    }

    public function forumFieldValues(EntityId $forumNodeId): array
    {
        return [];
    }

    public function replaceForumFieldValues(EntityId $forumNodeId, array $values): void
    {
    }
}

final readonly class MetadataServiceAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}

final class MetadataServicePermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $permissions */
    public function __construct(
        private readonly EntityId $actor,
        private readonly EntityId $forumId,
        private readonly array $permissions,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (!$assignment->userId()->equals($this->actor) || !in_array($key->value(), $this->permissions, true)) {
            return [];
        }
        if ($key->value() === 'acp.manage') {
            return [new PermissionRule(PermissionSubjectType::User, $this->actor, PermissionEffect::Allow)];
        }
        if ($nodeId === null || !$nodeId->equals($this->forumId)) {
            return [];
        }
        return [new PermissionRule(
            PermissionSubjectType::User,
            $this->actor,
            PermissionEffect::Allow,
            $this->forumId,
        )];
    }
}
