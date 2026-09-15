<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateAttachmentPipelineTables;
use PHPUnit\Framework\TestCase;

final class AttachmentPipelineMigrationTest extends TestCase
{
    public function testMigrationCreatesPrivateMetadataSchemaAndPermissionMatrix(): void
    {
        $database = new AttachmentMigrationRecordingDatabase();
        $migration = new CreateAttachmentPipelineTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260916001000_attachment_pipeline', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('forwext_attachments', $sql);
        self::assertStringContainsString('idx_forwext_attachments_owner_state', $sql);
        self::assertStringContainsString('idx_forwext_attachments_expiry', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);

        $rules = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'], $query->parameters['effect'])) {
                $rules[(string) $query->parameters['template_key']][(string) $query->parameters['permission_key']]
                    = (string) $query->parameters['effect'];
            }
        }
        self::assertCount(5, $rules);
        self::assertSame('deny', $rules['new_user']['forum.attachment.upload']);
        self::assertSame('allow', $rules['new_user']['forum.attachment.download']);
        self::assertSame('deny', $rules['member']['forum.attachment.manage_any']);
        self::assertSame('allow', $rules['moderator']['forum.attachment.manage_any']);
        self::assertSame('allow', $rules['administrator']['forum.attachment.upload']);
    }

    public function testVerificationRequiresTableForeignKeysIndexesPermissionsAndAllRules(): void
    {
        $database = new AttachmentMigrationRecordingDatabase();
        $database->fetchValues = [1, 3, 3, 3, 15];

        $result = (new CreateAttachmentPipelineTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(5, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenRulesAreIncomplete(): void
    {
        $database = new AttachmentMigrationRecordingDatabase();
        $database->fetchValues = [1, 3, 3, 3, 14];

        self::assertFalse((new CreateAttachmentPipelineTables())->verify(new MigrationContext($database))->isPassed());
    }
}

final class AttachmentMigrationRecordingDatabase implements TransactionalQueryExecutor
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
