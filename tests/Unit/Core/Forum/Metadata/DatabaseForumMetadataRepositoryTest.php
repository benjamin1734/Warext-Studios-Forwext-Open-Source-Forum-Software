<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Metadata;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Metadata\CustomFieldDefinition;
use Forwext\Core\Forum\Metadata\CustomFieldKey;
use Forwext\Core\Forum\Metadata\CustomFieldTarget;
use Forwext\Core\Forum\Metadata\CustomFieldType;
use Forwext\Core\Forum\Metadata\DatabaseForumMetadataRepository;
use Forwext\Core\Forum\Metadata\ForumContentConfiguration;
use Forwext\Core\Forum\Metadata\TagName;
use Forwext\Core\Forum\Metadata\ThreadMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseForumMetadataRepositoryTest extends TestCase
{
    public function testMissingForumConfigurationUsesSafeDisabledTagDefaults(): void
    {
        $database = new ForumMetadataRecordingDatabase();
        $database->fetchOneResults = [null];
        $database->fetchAllResults = [[], []];
        $repository = new DatabaseForumMetadataRepository($database);

        $configuration = $repository->configuration($this->id('a'));

        self::assertFalse($configuration->tagsEnabled());
        self::assertFalse($configuration->allowNewTags());
        self::assertSame(0, $configuration->maxTags());
    }

    public function testAutocompleteUsesBoundParameterAndEscapesWildcardInput(): void
    {
        $database = new ForumMetadataRecordingDatabase();
        $database->fetchAllResults = [[
            ['tag_id' => str_repeat('b', 32), 'name' => '100% safe'],
        ]];
        $repository = new DatabaseForumMetadataRepository($database);

        $tags = $repository->autocompleteTags('100%', 5);

        self::assertCount(1, $tags);
        self::assertSame('100% safe', $tags[0]->name()->value());
        self::assertCount(1, $database->fetchAllQueries);
        self::assertStringNotContainsString('100%', $database->fetchAllQueries[0]->sql);
        self::assertSame('100\\%%', $database->fetchAllQueries[0]->parameters['query']);
        self::assertStringContainsString('LIMIT 5', $database->fetchAllQueries[0]->sql);
    }

    public function testReplaceThreadMetadataIsTransactionalAndUsesExistingTags(): void
    {
        $database = new ForumMetadataRecordingDatabase();
        $database->fetchOneResults = [
            ['tag_id' => str_repeat('d', 32)],
        ];
        $repository = new DatabaseForumMetadataRepository($database);
        $field = new CustomFieldDefinition(
            CustomFieldKey::fromString('thread.version'),
            CustomFieldTarget::Thread,
            'Version',
            CustomFieldType::Text,
            true,
            1,
            20,
        );
        $metadata = new ThreadMetadata(
            $this->id('c'),
            [TagName::fromString('php')],
            ['thread.version' => $field->validate('1.0')],
        );

        $repository->replaceThreadMetadata($this->id('a'), $metadata, false);

        self::assertTrue($database->transactionUsed);
        self::assertTrue($database->fetchOneQueries[0]->requiresTransaction);
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('forwext_thread_prefix_assignments', $sql);
        self::assertStringContainsString('forwext_thread_tags', $sql);
        self::assertStringContainsString('forwext_thread_custom_field_values', $sql);
        self::assertStringNotContainsString('INSERT INTO `forwext_tags`', $sql);
    }

    public function testReplaceThreadMetadataRejectsUnknownTagWhenCreationDisabled(): void
    {
        $database = new ForumMetadataRecordingDatabase();
        $database->fetchOneResults = [null];
        $repository = new DatabaseForumMetadataRepository($database);
        $metadata = new ThreadMetadata(null, [TagName::fromString('unknown')], []);

        $this->expectException(InvalidArgumentException::class);
        $repository->replaceThreadMetadata($this->id('a'), $metadata, false);
    }

    public function testSaveConfigurationReplacesForumMappingsInsideOneTransaction(): void
    {
        $database = new ForumMetadataRecordingDatabase();
        $repository = new DatabaseForumMetadataRepository($database);
        $configuration = new ForumContentConfiguration(
            $this->id('a'),
            [$this->id('b')],
            [CustomFieldKey::fromString('thread.version')],
            true,
            false,
            3,
        );

        $repository->saveConfiguration($configuration);

        self::assertTrue($database->transactionUsed);
        self::assertCount(5, $database->executedQueries);
        self::assertSame(3, $database->executedQueries[0]->parameters['max_tags']);
        self::assertSame('thread.version', $database->executedQueries[4]->parameters['field_key']);
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final class ForumMetadataRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchValueQueries = [];
    /** @var list<array<string, mixed>|null> */
    public array $fetchOneResults = [];
    /** @var list<list<array<string, mixed>>> */
    public array $fetchAllResults = [];
    /** @var list<mixed> */
    public array $fetchValues = [];
    public bool $transactionUsed = false;
    private bool $inside = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->fetchOneQueries[] = $query;
        return array_shift($this->fetchOneResults) ?? null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllResults) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->fetchValueQueries[] = $query;
        return array_shift($this->fetchValues) ?? 0;
    }

    public function inTransaction(): bool
    {
        return $this->inside;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transactionUsed = true;
        $previous = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $previous;
        }
    }
}
