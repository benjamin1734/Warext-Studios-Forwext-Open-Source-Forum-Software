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

        self::assertCount(119, $entries);
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

        self::assertNotContains('freshness.renew_own', $keys);
        self::assertNotContains('freshness.review', $keys);
        self::assertNotContains('freshness.manage', $keys);

        foreach ([
            'audit.view',
            'audit.review',
            'ai.moderation.override',
            'spellcheck.dictionary.manage_site',
            'content_manager.execute',
            'forum.thread.freshness.renew_own',
            'forum.thread.freshness.renew_any',
            'forum.thread.freshness.review',
            'forum.thread.freshness.manage_policy',
            'easteregg.manage',
            'trophy.award',
            'promotion.manage',
            'reward.manage',
            'referral.manage',
            'portfolio.comment.create',
            'portfolio.reaction.use',
            'marketplace.category.manage',
            'marketplace.feature.manage',
            'marketplace.review.create',
            'marketplace.review.manage',
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
            'moderation.discipline.view',
            'moderation.warning.issue',
            'moderation.restriction.manage',
            'moderation.ban.manage',
            'moderation.discipline.revoke',
            'moderation.abuse.view',
            'moderation.abuse.manage_rules',
            'moderation.abuse.cleanup',
            'support.ticket.reply_all',
            'support.ticket.internal_note',
            'support.ticket.assign',
            'support.ticket.escalate',
            'support.ticket.merge',
            'support.ticket.split',
            'support.canned_response.manage',
            'support.faq_draft.suggest',
            'support.report.view',
            'support.audit.view',
            'bug.report.assign',
            'bug.report.reply_own',
            'bug.report.reply_all',
            'bug.report.export',
            'bug.audit.view',
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
