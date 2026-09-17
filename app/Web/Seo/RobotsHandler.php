<?php

declare(strict_types=1);

namespace Forwext\App\Web\Seo;

use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Seo\SeoContext;

final readonly class RobotsHandler implements RequestHandlerInterface
{
    /** @var list<string> */
    private const PRIVATE_PATHS = [
        '/account/',
        '/editor/',
        '/attachments/',
        '/profile-posts/',
        '/profile-comments/',
        '/users/',
        '/search',
        '/api/',
        '/install.php',
    ];

    public function __construct(private SeoContext $context)
    {
    }

    public function handle(Request $request): Response
    {
        $lines = ['User-agent: *', 'Allow: ' . $this->context->basePath()->prepend('/')];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: ' . $this->context->basePath()->prepend($path);
        }
        $lines[] = 'Sitemap: ' . $this->context->absolute('/sitemap.xml');

        return Response::text(implode("\n", $lines) . "\n")
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
