<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class TrophyDefinition
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $trophyId,
        public string $key,
        public string $name,
        public string $description,
        public TrophyKind $kind,
        public bool $active,
        public int $priority,
        public ?string $iconPath,
        public ?string $bannerPath,
        public TrophyRuleType $ruleType,
        public ?int $threshold,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->trophyId->value()) !== 1) {
            throw new InvalidArgumentException('Trophy id is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Trophy key is invalid.');
        }
        self::text($this->name, 120, 'name', false);
        self::text($this->description, 2000, 'description', true);
        if ($this->priority < 0 || $this->priority > 65535) {
            throw new InvalidArgumentException('Trophy priority is invalid.');
        }
        self::assetPath($this->iconPath, 'icon');
        self::assetPath($this->bannerPath, 'banner');

        if ($this->ruleType === TrophyRuleType::Manual) {
            if ($this->threshold !== null) {
                throw new InvalidArgumentException('Manual trophy rule cannot have a threshold.');
            }
        } elseif ($this->threshold === null || $this->threshold < 1 || $this->threshold > 1_000_000_000) {
            throw new InvalidArgumentException('Rule-based trophy threshold is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Trophy timestamps are invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    private static function text(string $value, int $maxBytes, string $label, bool $allowEmpty): void
    {
        if (preg_match('//u', $value) !== 1
            || strlen($value) > $maxBytes
            || (!$allowEmpty && trim($value) === '')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Trophy ' . $label . ' is invalid.');
        }
    }

    private static function assetPath(?string $path, string $label): void
    {
        if ($path === null) {
            return;
        }
        if ($path === '' || strlen($path) > 512 || $path[0] !== '/'
            || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            throw new InvalidArgumentException('Trophy ' . $label . ' path is invalid.');
        }
    }
}
