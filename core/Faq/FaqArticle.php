<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class FaqArticle
{
    /** @var list<string> */
    public array $tags;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        public EntityId $articleId,
        public string $categoryKey,
        public string $slug,
        public string $question,
        public string $answer,
        array $tags,
        public FaqVisibility $visibility,
        public string $language,
        public int $sortOrder,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public bool $active,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->categoryKey) !== 1) {
            throw new InvalidArgumentException('FAQ article category key is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,159}$/D', $this->slug) !== 1) {
            throw new InvalidArgumentException('FAQ article slug is invalid.');
        }
        if (trim($this->question) === '' || strlen($this->question) > 300) {
            throw new InvalidArgumentException('FAQ question must contain 1-300 UTF-8 bytes.');
        }
        if (trim($this->answer) === '' || strlen($this->answer) > 50000) {
            throw new InvalidArgumentException('FAQ answer must contain 1-50000 UTF-8 bytes.');
        }
        FaqCategory::assertLanguage($this->language);
        if ($this->sortOrder < 0 || $this->sortOrder > 65535) {
            throw new InvalidArgumentException('FAQ article sort order is invalid.');
        }
        if ($this->seoTitle !== null && (trim($this->seoTitle) === '' || strlen($this->seoTitle) > 200)) {
            throw new InvalidArgumentException('FAQ SEO title must contain 1-200 UTF-8 bytes when provided.');
        }
        if ($this->seoDescription !== null && strlen($this->seoDescription) > 320) {
            throw new InvalidArgumentException('FAQ SEO description cannot exceed 320 UTF-8 bytes.');
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('FAQ tags must be strings.');
            }
            $tag = strtolower(trim($tag));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $tag) !== 1) {
                throw new InvalidArgumentException('FAQ tag is invalid.');
            }
            $normalized[$tag] = true;
        }
        if (count($normalized) > 32) {
            throw new InvalidArgumentException('FAQ article cannot contain more than 32 tags.');
        }
        $this->tags = array_keys($normalized);

        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function effectiveVisibility(FaqCategory $category): FaqVisibility
    {
        return FaqVisibility::restrictive($this->visibility, $category->visibility);
    }
}
