<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Editor;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Forum\Editor\DatabaseMentionSuggestionProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseMentionSuggestionProviderTest extends TestCase
{
    public function testSuggestionsAreActiveBoundedLiteralPrefixResults(): void
    {
        $database = new MentionRecordingDatabase();
        $database->fetchAllQueue[] = [
            ['user_id' => str_repeat('1', 32), 'username' => 'a_ben'],
            ['user_id' => str_repeat('2', 32), 'username' => 'a_beta'],
        ];

        $items = (new DatabaseMentionSuggestionProvider($database))->suggest('a_b', 2);

        self::assertCount(2, $items);
        self::assertSame('a_ben', $items[0]->username);
        self::assertCount(1, $database->fetchAllQueries);
        $query = $database->fetchAllQueries[0];
        self::assertStringContainsString("`status` = 'active'", $query->sql);
        self::assertStringContainsString("LIKE :prefix ESCAPE '='", $query->sql);
        self::assertStringContainsString('LIMIT 2', $query->sql);
        self::assertSame('a=_b%', $query->parameters['prefix']);
    }

    public function testWildcardAndMalformedQueriesFailWithoutDatabaseLookup(): void
    {
        $database = new MentionRecordingDatabase();
        $provider = new DatabaseMentionSuggestionProvider($database);

        self::assertSame([], $provider->suggest('%admin'));
        self::assertSame([], $provider->suggest('bad space'));
        self::assertSame([], $provider->suggest(''));
        self::assertSame([], $database->fetchAllQueries);
    }
}

final class MentionRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllQueue = [];

    public function execute(CompiledQuery $query): int { return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllQueue) ?? [];
    }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
