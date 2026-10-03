<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateDirectConversationSystem;
use PHPUnit\Framework\TestCase;

final class DirectConversationMigrationTest extends TestCase
{
    public function testMigrationCreatesPrivateConversationSchemaAndPermission(): void
    {
        $database = new DirectConversationMigrationRecordingDatabase();
        $migration = new CreateDirectConversationSystem();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261003150000_direct_conversation_system', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));

        foreach ([
            'forwext_direct_conversations',
            'forwext_direct_conversation_participants',
            'forwext_direct_messages',
            'uq_forwext_direct_pair',
            'conversation.use',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
        self::assertStringContainsString('ON DELETE SET NULL', $sql);

        $templateRules = array_filter(
            $database->executedQueries,
            static fn (CompiledQuery $query): bool => isset($query->parameters['template_key']),
        );
        self::assertCount(5, $templateRules);
    }

    public function testVerificationRequiresTablesForeignKeysUniquePairPermissionAndRules(): void
    {
        $database = new DirectConversationMigrationRecordingDatabase();
        $database->fetchValues = [3, 4, 1, 1, 5];

        $result = (new CreateDirectConversationSystem())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(5, $database->verificationQueries);
    }

    public function testVerificationFailsClosedForIncompletePermissionRules(): void
    {
        $database = new DirectConversationMigrationRecordingDatabase();
        $database->fetchValues = [3, 4, 1, 1, 4];

        self::assertFalse(
            (new CreateDirectConversationSystem())->verify(new MigrationContext($database))->isPassed(),
        );
    }
}

final class DirectConversationMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];
    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        unset($query);
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        unset($query);
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->verificationQueries[] = $query;
        return array_shift($this->fetchValues) ?? 0;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}
