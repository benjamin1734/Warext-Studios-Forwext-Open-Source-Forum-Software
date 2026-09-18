<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\Seo;

use DateTimeImmutable;

final readonly class FaqSeoRecord
{
    public function __construct(
        public string $language,
        public string $slug,
        public string $question,
        public string $answer,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function canonicalPath(): string
    {
        return '/faq/' . rawurlencode($this->language) . '/' . rawurlencode($this->slug);
    }
}
