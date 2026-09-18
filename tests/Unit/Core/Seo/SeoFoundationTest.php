<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Seo;

use DateTimeImmutable;
use Forwext\App\Web\Seo\FeedFormat;
use Forwext\App\Web\Seo\FeedHandler;
use Forwext\App\Web\Seo\RobotsHandler;
use Forwext\App\Web\Seo\SeoResponseDecorator;
use Forwext\App\Web\Seo\SitemapHandler;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Faq\Seo\DatabaseFaqSeoReader;
use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Seo\Discovery\DatabasePublicProfileSeoReader;
use Forwext\Core\Seo\Discovery\PublicDiscoveryEntry;
use Forwext\Core\Seo\Discovery\PublicDiscoveryService;
use Forwext\Core\Seo\Discovery\PublicDiscoverySource;
use Forwext\Core\Seo\SeoContext;
use Forwext\Core\Seo\SeoHeadRenderer;
use Forwext\Core\Seo\SeoMetadata;
use PHPUnit\Framework\TestCase;

final class SeoFoundationTest extends TestCase
{
    public function testStructuredDataCannotBreakOutOfJsonLdScript(): void
    {
        $html = SeoHeadRenderer::render(new SeoMetadata(
            'Example',
            'Description',
            'https://example.test/profile',
            true,
            'profile',
            [[
                '@context' => 'https://schema.org',
                '@type' => 'Person',
                'name' => '</script><script>alert(1)</script>',
            ]],
        ));

        self::assertStringContainsString('name="robots" content="index,follow"', $html);
        self::assertStringNotContainsString('</script><script>', $html);
        self::assertStringContainsString('\\u003C/script\\u003E', $html);
    }

    public function testPublicProfileReaderSelectsOnlyPublicActiveMinimalFields(): void
    {
        $executor = new RecordingSeoExecutor(
            allRows: [[
                'username' => 'Benjamin17',
                'slug_key' => 'benjamin17',
                'discovery_updated_at' => '2026-09-17 20:00:00.000000',
            ]],
        );
        $reader = new DatabasePublicProfileSeoReader($executor);
        $profiles = $reader->latest(25);

        self::assertCount(1, $profiles);
        self::assertSame('/u/benjamin17', $profiles[0]->canonicalPath());
        self::assertNotNull($executor->lastQuery);
        $sql = strtolower($executor->lastQuery->sql);
        self::assertStringContainsString("`u`.`status` = 'active'", $sql);
        self::assertStringContainsString('profile_visibility', $sql);
        self::assertStringNotContainsString('`email`', $sql);
        self::assertStringNotContainsString('`about`', $sql);
    }

    public function testSitemapAndFeedsUseCanonicalSubfolderUrlsAndEscapeXml(): void
    {
        $context = new SeoContext(new CanonicalUrl('https://forum.example.test/community'));
        $service = new PublicDiscoveryService([
            new FakeDiscoverySource([
                new PublicDiscoveryEntry(
                    '/members/Alice',
                    'Alice & Bob',
                    'Profile <public>',
                    new DateTimeImmutable('2026-09-17T20:00:00+00:00'),
                ),
            ]),
        ]);
        $request = new Request(HttpMethod::Get, '/community/sitemap.xml');

        $sitemap = (new SitemapHandler($service, $context))->handle($request);
        self::assertSame('application/xml; charset=utf-8', $sitemap->headers()->first('content-type'));
        self::assertStringContainsString(
            'https://forum.example.test/community/members/Alice',
            $sitemap->body(),
        );

        $rss = (new FeedHandler($service, $context, FeedFormat::Rss))->handle($request)->body();
        self::assertStringContainsString('Alice &amp; Bob', $rss);
        self::assertStringContainsString('Profile &lt;public&gt;', $rss);

        $atomResponse = (new FeedHandler($service, $context, FeedFormat::Atom))->handle($request);
        self::assertStringContainsString(
            'application/atom+xml',
            $atomResponse->headers()->first('content-type') ?? '',
        );
        self::assertStringContainsString('<feed xmlns="http://www.w3.org/2005/Atom">', $atomResponse->body());
    }

    public function testRobotsUsesCanonicalBasePathAndBlocksPrivateSurfaces(): void
    {
        $context = new SeoContext(new CanonicalUrl('https://example.test/forum'));
        $body = (new RobotsHandler($context))->handle(
            new Request(HttpMethod::Get, '/forum/robots.txt'),
        )->body();

        self::assertStringContainsString('Disallow: /forum/account/', $body);
        self::assertStringContainsString('Disallow: /forum/search', $body);
        self::assertStringContainsString('Sitemap: https://example.test/forum/sitemap.xml', $body);
    }

    public function testDecoratorBuildsFaqPageMetadataOnlyFromPublicFaqReader(): void
    {
        $executor = new RecordingSeoExecutor(oneRow: [
            'language'=>'tr',
            'slug'=>'nasil-calisir',
            'question'=>'Nasıl çalışır?',
            'answer'=>'Güvenli bir açıklama.',
            'seo_title'=>'Özel SEO başlığı',
            'seo_description'=>'Özel SEO açıklaması',
            'updated_at_utc'=>'2026-09-18 18:00:00.000000',
        ]);
        $decorator = new SeoResponseDecorator(
            new SeoContext(new CanonicalUrl('https://example.test/community')),
            null,
            new DatabaseFaqSeoReader($executor),
        );
        $response = Response::html('<!doctype html><html><head><title>FAQ</title></head><body>x</body></html>');

        $decorated = $decorator->decorate(
            new Request(HttpMethod::Get, '/community/faq/tr/nasil-calisir'),
            $response,
        );

        self::assertNull($decorated->headers()->first('x-robots-tag'));
        self::assertStringContainsString('name="robots" content="index,follow"', $decorated->body());
        self::assertStringContainsString('FAQPage', $decorated->body());
        self::assertStringContainsString('https://example.test/community/faq/tr/nasil-calisir', $decorated->body());
        self::assertNotNull($executor->lastQuery);
        $sql = strtolower($executor->lastQuery->sql);
        self::assertStringContainsString("a.visibility='public'", $sql);
        self::assertStringContainsString("c.visibility='public'", $sql);
    }

    public function testDecoratorKeepsAuthenticatedOnlyProfileOutOfSeo(): void
    {
        $executor = new RecordingSeoExecutor(oneRow: null);
        $decorator = new SeoResponseDecorator(
            new SeoContext(new CanonicalUrl('https://example.test')),
            new DatabasePublicProfileSeoReader($executor),
        );
        $response = Response::html('<!doctype html><html><head><title>Private</title></head><body>x</body></html>');

        $decorated = $decorator->decorate(
            new Request(HttpMethod::Get, '/members/PrivateUser'),
            $response,
        );

        self::assertSame('noindex, nofollow', $decorated->headers()->first('x-robots-tag'));
        self::assertStringContainsString('name="robots" content="noindex,nofollow"', $decorated->body());
        self::assertStringNotContainsString('application/ld+json', $decorated->body());
    }
}

final readonly class FakeDiscoverySource implements PublicDiscoverySource
{
    /** @param list<PublicDiscoveryEntry> $entries */
    public function __construct(private array $entries)
    {
    }

    public function sitemapEntries(int $limit): array
    {
        return array_slice($this->entries, 0, $limit);
    }

    public function feedEntries(int $limit): array
    {
        return array_slice($this->entries, 0, $limit);
    }
}

final class RecordingSeoExecutor implements QueryExecutor
{
    public ?CompiledQuery $lastQuery = null;

    /**
     * @param list<array<string, mixed>> $allRows
     * @param array<string, mixed>|null $oneRow
     */
    public function __construct(
        private array $allRows = [],
        private ?array $oneRow = null,
    ) {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->lastQuery = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->lastQuery = $query;
        return $this->oneRow;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->lastQuery = $query;
        return $this->allRows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->lastQuery = $query;
        return null;
    }
}
