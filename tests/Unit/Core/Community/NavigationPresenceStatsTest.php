<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Community;

use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Stats\ForumStatsService;
use Forwext\Core\Presence\DatabasePresenceRepository;
use Forwext\Core\Profile\ProfileDirectoryReader;
use Forwext\Core\Search\Access\SearchAccessScopeProvider;
use Forwext\Core\Ui\Navigation\NavigationAudience;
use Forwext\Core\Ui\Navigation\NavigationItem;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use PHPUnit\Framework\TestCase;

final class NavigationPresenceStatsTest extends TestCase
{
    public function testNavigationRegistryOrdersCoreAndSupportsOwnedModuleEntries(): void
    {
        $registry = NavigationRegistry::withCoreDefaults();
        $registry->registerModule('support', new NavigationItem(
            'module.support',
            'Destek',
            '/support',
            250,
            NavigationAudience::Member,
            'support',
        ));

        self::assertSame(
            ['search', 'members', 'members.online', 'portfolio', 'faq', 'forum.stats'],
            array_map(static fn (NavigationItem $item): string => $item->key, $registry->visible(false)),
        );
        self::assertContains(
            'module.support',
            array_map(static fn (NavigationItem $item): string => $item->key, $registry->visible(true)),
        );
        self::assertContains(
            'giveaways',
            array_map(static fn (NavigationItem $item): string => $item->key, $registry->visible(true)),
        );
        self::assertNotContains(
            'giveaways',
            array_map(static fn (NavigationItem $item): string => $item->key, $registry->visible(false)),
        );
    }

    public function testMemberDirectoryEscapesLikeWildcardsAndKeepsPublicProfileFilter(): void
    {
        $database = new RecordingCommunityQueryExecutor();
        $reader = new ProfileDirectoryReader($database);
        $reader->searchPublic('a%_=', 'username', 24, 0);

        self::assertNotNull($database->lastQuery);
        self::assertSame('%a=%=_==%', $database->lastQuery->parameters['username_query'] ?? null);
        self::assertStringContainsString('profile_visibility', $database->lastQuery->sql);
        self::assertStringContainsString("u.`status` = 'active'", $database->lastQuery->sql);
    }

    public function testAnonymousOnlineQueryOnlyAcceptsExplicitPublicPresence(): void
    {
        $database = new RecordingCommunityQueryExecutor(allRows: []);
        $repository = new DatabasePresenceRepository($database);
        $repository->online(false, new DateTimeImmutable('2026-09-17T20:00:00+00:00'), 50);

        self::assertNotNull($database->lastQuery);
        self::assertStringContainsString("`pr`.`visibility` = 'public'", $database->lastQuery->sql);
        self::assertStringContainsString('profile_visibility', $database->lastQuery->sql);
        self::assertStringNotContainsString("'members'", $database->lastQuery->sql);
    }

    public function testForumStatsUseOnlyServerDerivedForumScopes(): void
    {
        $database = new RecordingCommunityQueryExecutor(values: [9, 2, 12, 44]);
        $service = new ForumStatsService($database, new FixedForumScopes([
            'forum.node:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'forum.node:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'public',
        ]));
        $stats = $service->forUser(EntityId::fromString('99999999999999999999999999999999'));

        self::assertSame(2, $stats->forums);
        self::assertSame(12, $stats->threads);
        self::assertSame(44, $stats->posts);
        self::assertSame(9, $stats->activeMembers);
        foreach (array_slice($database->queries, 1) as $query) {
            self::assertSame(
                'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                $query->parameters['forum_0'] ?? null,
            );
            self::assertSame(
                'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                $query->parameters['forum_1'] ?? null,
            );
        }
    }
}

final readonly class FixedForumScopes implements SearchAccessScopeProvider
{
    /** @param list<string> $scopes */
    public function __construct(private array $scopes)
    {
    }

    public function scopes(EntityId $userId): array
    {
        return $this->scopes;
    }
}

final class RecordingCommunityQueryExecutor implements QueryExecutor
{
    public ?CompiledQuery $lastQuery = null;
    /** @var list<CompiledQuery> */
    public array $queries = [];

    /**
     * @param list<array<string, mixed>> $allRows
     * @param list<mixed> $values
     */
    public function __construct(
        private array $allRows = [],
        private array $values = [],
    ) {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->record($query);
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->record($query);
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->record($query);
        return $this->allRows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->record($query);
        return array_shift($this->values);
    }

    private function record(CompiledQuery $query): void
    {
        $this->lastQuery = $query;
        $this->queries[] = $query;
    }
}
