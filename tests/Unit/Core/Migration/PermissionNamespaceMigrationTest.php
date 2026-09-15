<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Access\Permission\FirstPartyPermissionCatalog;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\RegisterFirstPartyPermissionNamespaces;
use PHPUnit\Framework\TestCase;

final class PermissionNamespaceMigrationTest extends TestCase
{
    public function testMigrationSeedsFullCatalogCompatibilityAndTemplateRules(): void
    {
        $database = new PermissionNamespaceMigrationRecordingDatabase();
        $migration = new RegisterFirstPartyPermissionNamespaces();

        $migration->up(new MigrationContext($database));

        $catalogCount = count(FirstPartyPermissionCatalog::entries());
        self::assertSame('20260915235900_permission_namespaces', $migration->id()->value());
        self::assertCount($catalogCount + 6 + 30, $database->queries);

        $catalogKeys = [];
        $compatibilityKeys = [];
        $templatePairs = [];
        foreach ($database->queries as $query) {
            if (isset($query->parameters['description'], $query->parameters['permission_key'])) {
                $catalogKeys[] = (string) $query->parameters['permission_key'];
            }
            if (($query->parameters['subject_id'] ?? null) === DatabaseUserAccessAssignmentProvider::UNASSIGNED_GROUP_ID) {
                $compatibilityKeys[(string) $query->parameters['permission_key']] = (string) $query->parameters['effect'];
            }
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'])) {
                $templatePairs[] = (string) $query->parameters['template_key']
                    . ':' . (string) $query->parameters['permission_key'];
            }
        }

        self::assertCount($catalogCount, array_unique($catalogKeys));
        foreach ([
            'support.ticket.manage',
            'bug.report.manage',
            'audit.review',
            'spellcheck.dictionary.manage_site',
            'content_manager.execute',
            'freshness.manage',
            'giveaway.manage',
            'easteregg.manage',
            'trophy.award',
            'promotion.manage',
            'referral.manage',
            'portfolio.manage_all',
            'marketplace.order.manage',
            'payment.refund',
            'subscription.manage_all',
            'ads.manage',
            'notice.manage',
            'analytics.export',
            'ai.manage',
            'appearance.advanced',
            'api.manage',
        ] as $requiredKey) {
            self::assertContains($requiredKey, $catalogKeys);
        }
        self::assertSame([
            'profile.custom_url.use' => 'allow',
            'profile.music.use' => 'allow',
            'profile.music.upload' => 'allow',
            'profile.music.external' => 'deny',
            'profile.music.autoplay' => 'allow',
            'profile.music.moderate' => 'deny',
        ], $compatibilityKeys);
        self::assertCount(30, $templatePairs);
        self::assertContains('administrator:profile.music.moderate', $templatePairs);
        self::assertContains('member:profile.music.external', $templatePairs);
    }

    public function testVerificationPassesOnlyWhenAllNamespaceSeedsExist(): void
    {
        $catalogCount = count(FirstPartyPermissionCatalog::entries());
        $database = new PermissionNamespaceMigrationRecordingDatabase();
        $database->fetchValues = [$catalogCount, 6, 30];

        $result = (new RegisterFirstPartyPermissionNamespaces())
            ->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(3, $database->verificationQueries);
        self::assertCount($catalogCount, $database->verificationQueries[0]->parameters);
    }

    public function testVerificationFailsClosedWhenCatalogIsIncomplete(): void
    {
        $catalogCount = count(FirstPartyPermissionCatalog::entries());
        $database = new PermissionNamespaceMigrationRecordingDatabase();
        $database->fetchValues = [$catalogCount - 1, 6, 30];

        $result = (new RegisterFirstPartyPermissionNamespaces())
            ->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class PermissionNamespaceMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];

    /** @var list<int> */
    public array $fetchValues = [];

    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];

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
