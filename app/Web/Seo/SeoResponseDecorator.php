<?php

declare(strict_types=1);

namespace Forwext\App\Web\Seo;

use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Seo\Discovery\DatabasePublicProfileSeoReader;
use Forwext\Core\Seo\SeoContext;
use Forwext\Core\Seo\SeoHeadRenderer;
use Forwext\Core\Seo\SeoMetadata;

final readonly class SeoResponseDecorator
{
    public function __construct(
        private SeoContext $context,
        private ?DatabasePublicProfileSeoReader $profiles = null,
    ) {
    }

    public function decorate(Request $request, Response $response): Response
    {
        $path = self::requestPath($request);
        $routePath = $path === null ? null : $this->context->basePath()->strip($path);
        $metadata = $response->status() === 200 && $routePath !== null
            ? $this->metadataFor($routePath)
            : null;

        $decorated = $this->injectHtmlMetadata($response, $metadata);
        if ($metadata === null || !$metadata->indexable) {
            return $decorated->withHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        return $decorated;
    }

    private function metadataFor(string $routePath): ?SeoMetadata
    {
        if ($routePath === '/') {
            $url = $this->context->absolute('/');
            return new SeoMetadata(
                $this->context->siteName() . ' — Open Source Forum Platform',
                $this->context->siteDescription(),
                $url,
                true,
                'website',
                [[
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => $this->context->siteName(),
                    'url' => $url,
                ]],
            );
        }

        if ($routePath === '/members') {
            $url = $this->context->absolute('/members');
            return new SeoMetadata(
                'Üyeler · ' . $this->context->siteName(),
                'Herkese açık ' . $this->context->siteName() . ' üye dizini.',
                $url,
                true,
                'website',
                [[
                    '@context' => 'https://schema.org',
                    '@type' => 'CollectionPage',
                    'name' => 'Üyeler',
                    'url' => $url,
                ]],
            );
        }

        if (preg_match('#^/members/([^/]+)$#D', $routePath, $matches) === 1) {
            $profile = $this->profiles?->findByUsername($matches[1]);
            return $profile === null ? null : $this->profileMetadata($profile->username, $profile->canonicalPath());
        }

        if (preg_match('#^/u/([^/]+)$#D', $routePath, $matches) === 1) {
            $profile = $this->profiles?->findBySlug($matches[1]);
            return $profile === null ? null : $this->profileMetadata($profile->username, $profile->canonicalPath());
        }

        return null;
    }

    private function profileMetadata(string $username, string $canonicalPath): SeoMetadata
    {
        $url = $this->context->absolute($canonicalPath);
        return new SeoMetadata(
            $username . ' · ' . $this->context->siteName(),
            $username . ' kullanıcısının herkese açık ' . $this->context->siteName() . ' profili.',
            $url,
            true,
            'profile',
            [[
                '@context' => 'https://schema.org',
                '@type' => 'Person',
                'name' => $username,
                'url' => $url,
            ]],
        );
    }

    private function injectHtmlMetadata(Response $response, ?SeoMetadata $metadata): Response
    {
        $contentType = $response->headers()->first('content-type');
        if ($contentType === null || !str_starts_with(strtolower($contentType), 'text/html')) {
            return $response;
        }

        $body = $response->body();
        if (str_contains($body, 'data-forwext-seo="1"')) {
            return $response;
        }

        $position = stripos($body, '</head>');
        if ($position === false) {
            return $response;
        }

        $fragment = SeoHeadRenderer::render($metadata);
        $body = substr($body, 0, $position) . $fragment . substr($body, $position);

        return new Response($body, $response->status(), $response->headers());
    }

    private static function requestPath(Request $request): ?string
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : null;
    }
}
