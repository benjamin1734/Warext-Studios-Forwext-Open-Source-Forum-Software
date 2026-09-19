<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class PortfolioProject
{
    /** @var list<string> */
    public array $tags;
    /** @var list<PortfolioMedia> */
    public array $media;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    /** @param list<string> $tags @param list<PortfolioMedia> $media */
    public function __construct(
        public EntityId $projectId,
        public EntityId $ownerUserId,
        public string $categoryKey,
        public string $slug,
        public string $title,
        public string $summary,
        public string $description,
        array $tags,
        array $media,
        public PortfolioState $state,
        public bool $featured,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($this->ownerUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $this->categoryKey) !== 1) {
            throw new InvalidArgumentException('Portfolio category key is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,159}$/D', $this->slug) !== 1) {
            throw new InvalidArgumentException('Portfolio slug is invalid.');
        }
        self::text($this->title, 180, 'title', false);
        self::text($this->summary, 500, 'summary', true);
        self::text($this->description, 100000, 'description', false);

        $tagMap = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new InvalidArgumentException('Portfolio tags must be strings.');
            }
            $tag = strtolower(trim($tag));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $tag) !== 1) {
                throw new InvalidArgumentException('Portfolio tag is invalid.');
            }
            $tagMap[$tag] = true;
        }
        if (count($tagMap) > 32) {
            throw new InvalidArgumentException('Portfolio tag limit exceeded.');
        }
        $this->tags = array_keys($tagMap);

        if (count($media) > 12) {
            throw new InvalidArgumentException('Portfolio media limit exceeded.');
        }
        foreach ($media as $item) {
            if (!$item instanceof PortfolioMedia) {
                throw new InvalidArgumentException('Portfolio media list is invalid.');
            }
        }
        $this->media = array_values($media);

        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    private static function text(string $value, int $max, string $label, bool $allowEmpty): void
    {
        if (preg_match('//u', $value) !== 1 || strlen($value) > $max || (!$allowEmpty && trim($value) === '')) {
            throw new InvalidArgumentException('Portfolio ' . $label . ' is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Portfolio ' . $label . ' contains control characters.');
        }
    }
}
