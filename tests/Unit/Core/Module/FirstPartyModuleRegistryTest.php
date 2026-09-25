<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Module;

use Forwext\Core\Module\FirstParty\FirstPartyModuleDefinition;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FirstPartyModuleRegistryTest extends TestCase
{
    public function testCoreRegistryContainsExpectedFirstPartyGraphAndRouteMappings(): void
    {
        $registry = FirstPartyModuleRegistry::withCoreDefaults();
        $definitions = $registry->all();

        self::assertCount(19, $definitions);
        self::assertSame('marketplace', $registry->moduleForRoute('marketplace.detail')?->key);
        self::assertSame('payments', $registry->moduleForRoute('payment.webhook')?->key);
        self::assertSame('spellcheck', $registry->moduleForRoute('editor.spellcheck')?->key);
        self::assertSame('analytics', $registry->moduleForRoute('analytics.reports')?->key);
        self::assertNull($registry->moduleForRoute('admin.modules'));

        $payment = $registry->require('payments');
        self::assertTrue(in_array('marketplace', $payment->dependencies, true));

        $rewardDependents = array_map(
            static fn (FirstPartyModuleDefinition $definition): string => $definition->key,
            $registry->dependentsOf('rewards'),
        );
        foreach (['referral', 'giveaway', 'trophies', 'promotions'] as $expected) {
            self::assertTrue(in_array($expected, $rewardDependents, true));
        }
    }

    public function testRegistryRejectsDependencyCycles(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FirstPartyModuleRegistry([
            new FirstPartyModuleDefinition('alpha', 'Alpha', 'Alpha module.', dependencies:['beta']),
            new FirstPartyModuleDefinition('beta', 'Beta', 'Beta module.', dependencies:['alpha']),
        ]);
    }

    public function testConflictsAreResolvedSymmetrically(): void
    {
        $registry = new FirstPartyModuleRegistry([
            new FirstPartyModuleDefinition('alpha', 'Alpha', 'Alpha module.', conflicts:['beta']),
            new FirstPartyModuleDefinition('beta', 'Beta', 'Beta module.'),
        ]);

        self::assertSame('beta', $registry->conflictsOf('alpha')[0]->key);
        self::assertSame('alpha', $registry->conflictsOf('beta')[0]->key);
    }
}
