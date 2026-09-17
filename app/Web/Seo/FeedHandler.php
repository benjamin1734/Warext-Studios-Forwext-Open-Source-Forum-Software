<?php

declare(strict_types=1);

namespace Forwext\App\Web\Seo;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Seo\Discovery\PublicDiscoveryEntry;
use Forwext\Core\Seo\Discovery\PublicDiscoveryService;
use Forwext\Core\Seo\SeoContext;

final readonly class FeedHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublicDiscoveryService $discovery,
        private SeoContext $context,
        private FeedFormat $format,
        private int $limit = 50,
    ) {
    }

    public function handle(Request $request): Response
    {
        $entries = $this->discovery->feedEntries($this->limit);
        $generatedAt = $entries[0]->updatedAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return Response::text(
            $this->format === FeedFormat::Rss
                ? $this->rss($entries, $generatedAt)
                : $this->atom($entries, $generatedAt),
        )
            ->withHeader(
                'Content-Type',
                $this->format === FeedFormat::Rss
                    ? 'application/rss+xml; charset=utf-8'
                    : 'application/atom+xml; charset=utf-8',
            )
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    /** @param list<PublicDiscoveryEntry> $entries */
    private function rss(array $entries, DateTimeImmutable $generatedAt): string
    {
        $root = $this->context->absolute('/');
        $self = $this->context->absolute('/feed.rss');
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
            . '<title>' . self::xml($this->context->siteName()) . '</title>'
            . '<link>' . self::xml($root) . '</link>'
            . '<description>' . self::xml($this->context->siteDescription()) . '</description>'
            . '<lastBuildDate>' . self::xml($generatedAt->format(DATE_RSS)) . '</lastBuildDate>'
            . '<atom:link href="' . self::xml($self)
            . '" rel="self" type="application/rss+xml"/>';

        foreach ($entries as $entry) {
            $url = $this->context->absolute($entry->path);
            $body .= '<item><title>' . self::xml($entry->title) . '</title>'
                . '<link>' . self::xml($url) . '</link>'
                . '<guid isPermaLink="true">' . self::xml($url) . '</guid>';
            if ($entry->updatedAt !== null) {
                $body .= '<pubDate>' . self::xml($entry->updatedAt->format(DATE_RSS)) . '</pubDate>';
            }
            $body .= '<description>' . self::xml($entry->summary) . '</description></item>';
        }

        return $body . '</channel></rss>';
    }

    /** @param list<PublicDiscoveryEntry> $entries */
    private function atom(array $entries, DateTimeImmutable $generatedAt): string
    {
        $root = $this->context->absolute('/');
        $self = $this->context->absolute('/feed.atom');
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<feed xmlns="http://www.w3.org/2005/Atom">'
            . '<id>' . self::xml($root) . '</id>'
            . '<title>' . self::xml($this->context->siteName()) . '</title>'
            . '<updated>' . self::xml($generatedAt->format(DATE_ATOM)) . '</updated>'
            . '<link href="' . self::xml($root) . '"/>'
            . '<link rel="self" type="application/atom+xml" href="' . self::xml($self) . '"/>';

        foreach ($entries as $entry) {
            $url = $this->context->absolute($entry->path);
            $updated = $entry->updatedAt ?? $generatedAt;
            $body .= '<entry><id>' . self::xml($url) . '</id>'
                . '<title>' . self::xml($entry->title) . '</title>'
                . '<updated>' . self::xml($updated->format(DATE_ATOM)) . '</updated>'
                . '<link href="' . self::xml($url) . '"/>'
                . '<summary>' . self::xml($entry->summary) . '</summary></entry>';
        }

        return $body . '</feed>';
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
