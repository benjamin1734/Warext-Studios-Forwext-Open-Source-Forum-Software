<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Domain\Access\Permission;

use Forwext\Core\Domain\Access\Permission\FirstPartyPermissionCatalog;
use Forwext\Core\Domain\Access\Permission\PermissionCatalogEntry;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionNamespace;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use PHPUnit\Framework\TestCase;

final class FirstPartyPermissionCatalogTest extends TestCase
{
    public function testCatalogContainsUniqueCanonicalKeysAcrossAllPlannedFirstPartyNamespaces(): void
    {
        $entries = FirstPartyPermissionCatalog::entries();
        $keys = array_map(
            static fn (PermissionCatalogEntry $entry): string => $entry->key()->value(),
            $entries,
        );
        $namespaces = array_map(
            static fn (PermissionNamespace $namespace): string => $namespace->value(),
            FirstPartyPermissionCatalog::namespaces(),
        );

        self::assertCount(85, $entries);
        self::assertCount(count($keys), array_unique($keys));
        self::assertSame([
            'acp',
            'ads',
            'ai',
            'analytics',
            'api',
            'appearance',
            'audit',
            'bug',
            'content_manager',
            'easteregg',
            'faq',
            'forum',
            'freshness',
            'giveaway',
            'invite',
            'marketplace',
            'moderation',
            'notice',
            'payment',
            'portfolio',
            'profile',
            'promotion',
            'referral',
            'report',
            'reward',
            'search',
            'spellcheck',
            'subscription',
            'support',
            'trophy',
        ], $namespaces);

        foreach ([
            'audit.review',
            'spellcheck.dictionary.manage_site',
            'content_manager.execute',
            'freshness.manage',
            'easteregg.manage',
            'trophy.award',
            'promotion.manage',
            'reward.manage',
            'referral.manage',
            'marketplace.external_link.use',
            'marketplace.internal_purchase.use',
            'payment.refund',
            'subscription.manage_all',
            'ads.manage',
            'notice.manage',
            'analytics.export',
            'appearance.advanced',
            'api.manage',
            'search.use',
            'report.create',
        ] as $requiredKey) {
            self::assertContains($requiredKey, $keys);
        }
    }

    public function testNamespaceIsDerivedFromTopLevelPermissionSegment(): void
    {
        $namespace = PermissionNamespace::fromKey(PermissionKey::fromString('marketplace.listing.create'));

        self::assertSame('marketplace', $namespace->value());
        self::assertTrue($namespace->contains(PermissionKey::fromString('marketplace.order.manage')));
        self::assertFalse($namespace->contains(PermissionKey::fromString('support.ticket.manage')));
    }

    public function testOnlyForumDailyContentLimitIsNumericInCurrentFirstPartyCatalog(): void
    {
        $numeric = array_values(array_filter(
            FirstPartyPermissionCatalog::entries(),
            static fn (PermissionCatalogEntry $entry): bool => $entry->valueType() === PermissionValueType::Numeric,
        ));

        self::assertCount(1, $numeric);
        self::assertSame('forum.content.daily_limit', $numeric[0]->key()->value());
    }
}
