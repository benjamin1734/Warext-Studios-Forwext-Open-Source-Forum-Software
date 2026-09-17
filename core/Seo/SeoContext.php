<?php

declare(strict_types=1);

namespace Forwext\Core\Seo;

use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class SeoContext
{
    public function __construct(
        private CanonicalUrl $canonicalUrl,
        private string $siteName = 'Forwext',
        private string $siteDescription = 'Forwext — Open Source Forum Platform',
    ) {
        if ($this->siteName === '' || strlen($this->siteName) > 120) {
            throw new InvalidArgumentException('SEO site name must contain 1..120 bytes.');
        }
        if ($this->siteDescription === '' || strlen($this->siteDescription) > 320) {
            throw new InvalidArgumentException('SEO site description must contain 1..320 bytes.');
        }
    }

    public function siteName(): string
    {
        return $this->siteName;
    }

    public function siteDescription(): string
    {
        return $this->siteDescription;
    }

    public function basePath(): BasePath
    {
        return $this->canonicalUrl->basePath();
    }

    public function absolute(string $routePath): string
    {
        return $this->canonicalUrl->origin() . $this->canonicalUrl->basePath()->prepend($routePath);
    }
}
