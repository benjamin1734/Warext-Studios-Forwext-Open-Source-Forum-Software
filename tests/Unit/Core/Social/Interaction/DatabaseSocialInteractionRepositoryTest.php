<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Social\Interaction;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Social\Interaction\DatabaseSocialInteractionRepository;
use Forwext\Core\Social\Interaction\SocialInteractionException;
use PHPUnit\Framework\TestCase;

final class DatabaseSocialInteractionRepositoryTest extends TestCase
{
    public function testIgnoreLocksActorAndAtomicallyRemovesFollowBeforePersistingIgnore(): void
    {
        $database = new InteractionRecordingDatabase();
        $database->fetchOneQueue[] = ['user_id' => str_repeat('1', 32)];
        $repository = new DatabaseSocialInteractionRepository($database);

        $repository->ignore($this->id('1'), $this->id('2'));

        self::assertSame(1, $database->transactions);
        self::assertCount(1, $database->fetchOneQueries);
        self::assertTrue($database->fetchOneQueries[0]->requiresTransaction);
        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertCount(2, $database->executedQueries);
        self::assertStringContainsString('DELETE FROM `forwext_user_follows`', $database->executedQueries[0]->sql);
        self::assertStringContainsString('INSERT INTO `forwext_user_ignores`', $database->executedQueries[1]->sql);
    }

    public function testFollowFailsWhenTargetIsAlreadyIgnoredAfterActorLock(): void
    {
        $database = new InteractionRecordingDatabase();
        $database->fetchOneQueue[] = ['user_id' => str_repeat('1', 32)];
        $database->fetchOneQueue[] = ['ignored_user_id' => str_repeat('2', 32)];
        $repository = new DatabaseSocialInteractionRepository($database);

        $this->expectException(SocialInteractionException::class);
        try {
            $repository->follow($this->id('1'), $this->id('2'));
        } finally {
            self::assertCount(0, $database->executedQueries);
            self::assertCount(2, $database->fetchOneQueries);
        }
    }

    public function testReactionSummaryAggregatesCountsAndScores(): void
    {
        $database = new InteractionRecordingDatabase();
        $database->fetchAllQueue[] = [
            ['reaction_key' => 'like', 'reaction_count' => 3, 'reaction_score' => 3],
            ['reaction_key' => 'sad', 'reaction_count' => 2, 'reaction_score' => 0],
        ];
        $summary = (new DatabaseSocialInteractionRepository($database))->reactionSummary($this->id('3'));

        self::assertSame(5, $summary->total);
        self::assertSame(3, $summary->score);
        self::assertSame(['like' => 3, 'sad' => 2], $summary->counts);
    }

    public function testSelfRelationshipsFailBeforeDatabaseMutation(): void
    {
        $repository = new DatabaseSocialInteractionRepository(new InteractionRecordingDatabase());

        $this->expectException(SocialInteractionException::class);
        $repository->ignore($this->id('1'), $this->id('1'));
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final class InteractionRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<array<string,mixed>|null> */
    public array $fetchOneQueue = [];
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllQueue = [];
    public int $transactions = 0;

    public function execute(CompiledQuery $query): int { $this->executedQueries[] = $query; return 1; }
    public function fetchOne(CompiledQuery $query): ?array { $this->fetchOneQueries[] = $query; return array_shift($this->fetchOneQueue); }
    public function fetchAll(CompiledQuery $query): array { return array_shift($this->fetchAllQueue) ?? []; }
    public function fetchValue(CompiledQuery $query): mixed { return 0; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { ++$this->transactions; return $callback($this); }
}
