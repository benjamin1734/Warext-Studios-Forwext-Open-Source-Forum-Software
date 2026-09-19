<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Forwext\App\Web\Search\SearchHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Search\DiscoveryUx\GlobalDiscoveryCategory;
use Forwext\Core\Search\DiscoveryUx\GlobalDiscoveryRegistry;
use Forwext\Core\Search\SearchException;
use Forwext\Core\Search\SearchHit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GlobalDiscoveryUxTest extends TestCase
{
    public function testCoreCategoriesExposeStableOrderedDocumentTypeContracts(): void
    {
        $registry = GlobalDiscoveryRegistry::withCoreDefaults();

        self::assertSame(
            ['forum', 'support', 'faq', 'portfolio', 'giveaway', 'marketplace', 'members'],
            array_map(static fn (GlobalDiscoveryCategory $category): string => $category->key, $registry->categories()),
        );
        self::assertSame(['forum', 'thread', 'post'], $registry->resolveDocumentTypes('forum'));
        self::assertSame(['support.ticket', 'support.message'], $registry->resolveDocumentTypes('support'));
        self::assertSame(['faq.article'], $registry->resolveDocumentTypes('faq'));
        self::assertSame(['portfolio.item'], $registry->resolveDocumentTypes('portfolio'));
        self::assertSame(['giveaway.item'], $registry->resolveDocumentTypes('giveaway'));
        self::assertSame(['marketplace.listing'], $registry->resolveDocumentTypes('marketplace'));
        self::assertSame(['user'], $registry->resolveDocumentTypes('members'));
        self::assertSame('members', $registry->categoryForType('user')?->key);
    }

    public function testExplicitTypeFilterCanOnlyNarrowTheSelectedTab(): void
    {
        $registry = GlobalDiscoveryRegistry::withCoreDefaults();

        self::assertSame(['thread'], $registry->resolveDocumentTypes('forum', ['thread']));

        $this->expectException(SearchException::class);
        $registry->resolveDocumentTypes('forum', ['support.ticket']);
    }

    public function testUnknownAndDuplicateTypesAreRejected(): void
    {
        $registry = GlobalDiscoveryRegistry::withCoreDefaults();

        try {
            $registry->resolveDocumentTypes(GlobalDiscoveryRegistry::ALL, ['private.hidden']);
            self::fail('Unknown discovery document types must be rejected.');
        } catch (SearchException) {
            self::assertTrue(true);
        }

        $this->expectException(SearchException::class);
        $registry->resolveDocumentTypes(GlobalDiscoveryRegistry::ALL, ['thread', 'thread']);
    }

    public function testDocumentTypeOwnershipCannotBeRegisteredTwice(): void
    {
        $registry = GlobalDiscoveryRegistry::withCoreDefaults();

        $this->expectException(InvalidArgumentException::class);
        $registry->register(new GlobalDiscoveryCategory('other', 'Other', ['thread']));
    }

    public function testAllTabGroupsRealHitsAndDoesNotInventModuleRoutes(): void
    {
        $html = SearchHtml::page(
            new BasePath('/community'),
            'alpha',
            [
                new SearchHit('thread', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 10.0, 'Forum konusu'),
                new SearchHit('support.ticket', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 9.0, 'Destek kaydı'),
                new SearchHit('portfolio.item', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 8.5, 'Portfolyo projesi'),
                new SearchHit('giveaway.item', 'ffffffffffffffffffffffffffffffff', 8.25, 'Çekiliş'),
                new SearchHit('user', 'cccccccccccccccccccccccccccccccc', 8.0, 'alice'),
            ],
            ['q' => 'alpha'],
            null,
            [],
            discovery: GlobalDiscoveryRegistry::withCoreDefaults(),
            authenticated: true,
        );

        self::assertStringContainsString('>Forum <small', $html);
        self::assertStringContainsString('>Destek <small', $html);
        self::assertStringContainsString('>Üyeler <small', $html);
        self::assertStringContainsString('/community/portfolio/eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', $html);
        self::assertStringContainsString('/community/giveaways/ffffffffffffffffffffffffffffffff', $html);
        self::assertStringContainsString('/community/members/alice', $html);
        self::assertStringNotContainsString('href="/community/support', $html);
    }

    public function testSearchResultTitlesRemainHtmlEscaped(): void
    {
        $html = SearchHtml::page(
            new BasePath(),
            'unsafe',
            [new SearchHit('thread', 'dddddddddddddddddddddddddddddddd', 1.0, '<script>alert(1)</script>')],
            ['q' => 'unsafe'],
            null,
            [],
            discovery: GlobalDiscoveryRegistry::withCoreDefaults(),
            authenticated: true,
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
