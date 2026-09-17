<?php

declare(strict_types=1);

namespace Forwext\App\Web\Seo;

use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Seo\Discovery\PublicDiscoveryService;
use Forwext\Core\Seo\SeoContext;

final readonly class SitemapHandler implements RequestHandlerInterface
{
    public function __construct(
        private PublicDiscoveryService $discovery,
        private SeoContext $context,
    ) {
    }

    public function handle(Request $request): Response
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($this->discovery->sitemapEntries() as $entry) {
            $body .= '<url><loc>' . self::xml($this->context->absolute($entry->path)) . '</loc>';
            if ($entry->updatedAt !== null) {
                $body .= '<lastmod>' . self::xml($entry->updatedAt->format(DATE_ATOM)) . '</lastmod>';
            }
            $body .= '</url>';
        }

        $body .= '</urlset>';

        return Response::text($body)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
