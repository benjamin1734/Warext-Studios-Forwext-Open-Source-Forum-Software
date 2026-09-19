<?php

declare(strict_types=1);

namespace Forwext\Core\EasterEgg;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class EasterEggDefinition
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $startsAt;
    public ?DateTimeImmutable $endsAt;

    public function __construct(
        public EntityId $easterEggId,
        public string $key,
        public string $name,
        public bool $enabled,
        public int $priority,
        public EasterEggTriggerType $triggerType,
        public ?string $triggerValue,
        public ?string $routeName,
        public ?string $pathPattern,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        public string $message,
        public EasterEggAnimation $animation,
        public ?string $badgeLabel,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->easterEggId->value()) !== 1) {
            throw new InvalidArgumentException('Easter egg id is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Easter egg key is invalid.');
        }
        self::text($this->name, 100, 'name', false);
        self::text($this->message, 1000, 'message', false);
        if ($this->badgeLabel !== null) {
            self::text($this->badgeLabel, 40, 'badge label', false);
        }
        if ($this->priority < 0 || $this->priority > 65535) {
            throw new InvalidArgumentException('Easter egg priority is invalid.');
        }
        if ($this->routeName === null && $this->pathPattern === null) {
            throw new InvalidArgumentException('Easter egg requires a route name or path pattern.');
        }
        if ($this->routeName !== null
            && preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,127}$/D', $this->routeName) !== 1
        ) {
            throw new InvalidArgumentException('Easter egg route name is invalid.');
        }
        if ($this->pathPattern !== null) {
            self::path($this->pathPattern);
        }
        if ($this->triggerType === EasterEggTriggerType::Automatic && $this->triggerValue !== null) {
            throw new InvalidArgumentException('Automatic easter egg trigger cannot have a token.');
        }
        if ($this->triggerType === EasterEggTriggerType::QueryToken
            && ($this->triggerValue === null
                || preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $this->triggerValue) !== 1)
        ) {
            throw new InvalidArgumentException('Query-token easter egg trigger is invalid.');
        }

        $utc = new DateTimeZone('UTC');
        $this->startsAt = $startsAt?->setTimezone($utc);
        $this->endsAt = $endsAt?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
        if ($this->startsAt !== null && $this->endsAt !== null && $this->endsAt <= $this->startsAt) {
            throw new InvalidArgumentException('Easter egg end time must be after start time.');
        }
        if ($this->updatedAt < $this->createdAt) {
            throw new InvalidArgumentException('Easter egg update timestamp is invalid.');
        }
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function activeAt(DateTimeImmutable $at): bool
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return $this->enabled
            && ($this->startsAt === null || $at >= $this->startsAt)
            && ($this->endsAt === null || $at < $this->endsAt);
    }

    public function matchesRoute(string $routeName, string $path): bool
    {
        if ($this->routeName !== null && !hash_equals($this->routeName, $routeName)) {
            return false;
        }
        if ($this->pathPattern === null) {
            return true;
        }
        if (str_ends_with($this->pathPattern, '*')) {
            return str_starts_with($path, substr($this->pathPattern, 0, -1));
        }
        return hash_equals($this->pathPattern, $path);
    }

    public function triggerSatisfied(?string $queryToken): bool
    {
        return $this->triggerType === EasterEggTriggerType::Automatic
            || ($queryToken !== null && $this->triggerValue !== null && hash_equals($this->triggerValue, $queryToken));
    }

    private static function path(string $path): void
    {
        if ($path === '' || $path[0] !== '/' || strlen($path) > 255
            || str_contains($path, '?') || str_contains($path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || substr_count($path, '*') > 1
            || (str_contains($path, '*') && !str_ends_with($path, '*'))
        ) {
            throw new InvalidArgumentException('Easter egg path pattern is invalid.');
        }
    }

    private static function text(string $value, int $maxBytes, string $label, bool $allowEmpty): void
    {
        if (preg_match('//u', $value) !== 1 || strlen($value) > $maxBytes
            || (!$allowEmpty && trim($value) === '')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException('Easter egg ' . $label . ' is invalid.');
        }
    }
}
