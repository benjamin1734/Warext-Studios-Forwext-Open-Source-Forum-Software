<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreatePollTables;
use PHPUnit\Framework\TestCase;

final class PollMigrationTest extends TestCase
{
    public function testMigrationCreatesPollSchemaAndCompleteStarterPermissionMatrix(): void
    {
        $database = new PollMigrationRecordingDatabase();
        $migration = new CreatePollTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260915235958_poll_system', $migration->id()->value());
        self::assertCount(34, $database->executedQueries);
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('forwext_polls', $sql);
        self::assertStringContainsString('forwext_poll_options', $sql);
        self::assertStringContainsString('forwext_poll_votes', $sql);
        self::assertStringContainsString('forwext_poll_vote_choices', $sql);
        self::assertStringContainsString('uq_forwext_polls_thread', $sql);
        self::assertStringContainsString('uq_forwext_poll_votes_user', $sql);

        $effects = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'])) {
                $effects[
                    (string) $query->parameters['template_key'] . ':' . (string) $query->parameters['permission_key']
                ] = (string) $query->parameters['effect'];
            }
        }
        self::assertCount(25, $effects);
        self::assertSame('deny', $effects['new_user:forum.poll.create']);
        self::assertSame('allow', $effects['new_user:forum.poll.vote']);
        self::assertSame('allow', $effects['member:forum.poll.view_voters']);
        self::assertSame('deny', $effects['verified:forum.poll.manage']);
        self::assertSame('allow', $effects['moderator:forum.poll.manage']);
        self::assertSame('allow', $effects['administrator:forum.poll.create']);
    }

    public function testVerificationRequiresTablesUniquenessPermissionsTemplatesAndForeignKeys(): void
    {
        $database = new PollMigrationRecordingDatabase();
        $database->fetchValues = [4, 1, 2, 5, 25, 7];

        $result = (new CreatePollTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(6, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenVoteUniquenessIsMissing(): void
    {
        $database = new PollMigrationRecordingDatabase();
        $database->fetchValues = [4, 1, 1, 5, 25, 7];

        $result = (new CreatePollTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class PollMigrationRecordingDatabase implements TransactionalQueryExecutor
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
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
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
