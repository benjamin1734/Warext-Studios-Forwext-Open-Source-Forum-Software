<?php

declare(strict_types=1);

namespace Forwext\Core\Seo;

use InvalidArgumentException;

final readonly class SeoMetadata
{
    /**
     * @param list<array<string, mixed>> $structuredData
     */
    public function __construct(
        public string $title,
        public string $description,
        public ?string $canonicalUrl,
        public bool $indexable = false,
        public string $openGraphType = 'website',
        public array $structuredData = [],
    ) {
        if ($this->title === '' || strlen($this->title) > 200) {
            throw new InvalidArgumentException('SEO title must contain 1..200 bytes.');
        }
        if (strlen($this->description) > 320) {
            throw new InvalidArgumentException('SEO description cannot exceed 320 bytes.');
        }
        if (
            $this->canonicalUrl !== null
            && (
                preg_match('/^https?:\/\/[^\/\s]+(?:\/.*)?$/Di', $this->canonicalUrl) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $this->canonicalUrl) === 1
            )
        ) {
            throw new InvalidArgumentException('SEO canonical URL must be an absolute HTTP(S) URL.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->openGraphType) !== 1) {
            throw new InvalidArgumentException('OpenGraph type is invalid.');
        }
    }
}
