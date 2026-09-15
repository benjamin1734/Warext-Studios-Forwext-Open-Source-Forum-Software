<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Permission\Template;

use Closure;
use DomainException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\Permission\Template\BuiltInPermissionTemplate;
use Forwext\Core\Domain\Access\Permission\Template\DatabasePermissionTemplateRuleWriter;
use Forwext\Core\Domain\Access\Permission\Template\PermissionTemplate;
use Forwext\Core\Domain\Access\Permission\Template\PermissionTemplateApplier;
use Forwext\Core\Domain\Access\Permission\Template\PermissionTemplateKey;
use Forwext\Core\Domain\Access\Permission\Template\PermissionTemplateRepository;
use Forwext\Core\Domain\Access\Permission\Template\PermissionTemplateRule;
use Forwext\Core\Domain\Access\Permission\Template\PermissionTemplateRuleWriter;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PermissionTemplateTest extends TestCase
{
    public function testBuiltInCatalogExposesRequiredStarterProfiles(): void
    {
        self::assertSame(
            ['new_user', 'member', 'verified', 'moderator', 'administrator'],
            array_map(static fn (BuiltInPermissionTemplate $template): string => $template->value, BuiltInPermissionTemplate::cases()),
        );
    }

    public function testTemplateRejectsDuplicatePermissionKeys(): void
    {
        $rule = $this->flagRule('forum.view', PermissionEffect::Allow);

        $this->expectException(InvalidArgumentException::class);
        new PermissionTemplate(
            PermissionTemplateKey::fromString('member'),
            'Member',
            'Member starter profile.',
            true,
            [$rule, $rule],
        );
    }

    public function testNumericAllowRequiresLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PermissionTemplateRule(
            new PermissionDefinition(PermissionKey::fromString('forum.content.daily_limit'), PermissionValueType::Numeric),
            PermissionEffect::Allow,
        );
    }

    public function testApplierFailsClosedForUnknownTemplate(): void
    {
        $repository = new PermissionTemplateFakeRepository(null);
        $writer = new PermissionTemplateFakeWriter();

        $this->expectException(DomainException::class);
        (new PermissionTemplateApplier($repository, $writer))->apply(
            PermissionTemplateKey::fromString('missing'),
            PermissionSubjectType::Group,
            EntityId::fromString('group:member'),
        );
    }

    public function testDatabaseWriterAppliesOnlyTemplateRulesTransactionallyAndWithBoundValues(): void
    {
        $database = new PermissionTemplateRecordingDatabase();
        $template = new PermissionTemplate(
            PermissionTemplateKey::fromString('member'),
            'Member',
            'Member starter profile.',
            true,
            [
                $this->flagRule('forum.view', PermissionEffect::Allow),
                new PermissionTemplateRule(
                    new PermissionDefinition(PermissionKey::fromString('forum.content.daily_limit'), PermissionValueType::Numeric),
                    PermissionEffect::Allow,
                    100,
                ),
            ],
        );

        $count = (new DatabasePermissionTemplateRuleWriter($database))->apply(
            $template,
            PermissionSubjectType::Group,
            EntityId::fromString('group:member'),
        );

        self::assertSame(2, $count);
        self::assertSame(1, $database->transactions);
        self::assertCount(2, $database->queries);
        self::assertStringNotContainsString('group:member', $database->queries[0]->sql);
        self::assertSame('group:member', $database->queries[0]->parameters['subject_id']);
        self::assertSame('forum.view', $database->queries[0]->parameters['permission_key']);
        self::assertSame(100, $database->queries[1]->parameters['numeric_limit']);
        self::assertStringNotContainsString('DELETE', strtoupper($database->queries[0]->sql));
    }

    private function flagRule(string $key, PermissionEffect $effect): PermissionTemplateRule
    {
        return new PermissionTemplateRule(
            new PermissionDefinition(PermissionKey::fromString($key), PermissionValueType::Flag),
            $effect,
        );
    }
}

final readonly class PermissionTemplateFakeRepository implements PermissionTemplateRepository
{
    public function __construct(private ?PermissionTemplate $template)
    {
    }

    public function find(PermissionTemplateKey $key): ?PermissionTemplate
    {
        return $this->template;
    }

    public function all(): array
    {
        return $this->template === null ? [] : [$this->template];
    }
}

final class PermissionTemplateFakeWriter implements PermissionTemplateRuleWriter
{
    public function apply(PermissionTemplate $template, PermissionSubjectType $subjectType, EntityId $subjectId): int
    {
        return count($template->rules());
    }
}

final class PermissionTemplateRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];

    public int $transactions = 0;

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
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
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        return $callback($this);
    }
}
