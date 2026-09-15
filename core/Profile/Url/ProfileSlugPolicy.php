<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

final readonly class ProfileSlugPolicy
{
    /** @var array<string, true> */
    private array $reserved;

    /** @param list<string> $reserved */
    public function __construct(array $reserved)
    {
        $normalized = [];
        foreach ($reserved as $value) {
            if (!is_string($value)) {
                throw new ProfileUrlException('Reserved profile slug list contains an invalid entry.');
            }
            $slug = ProfileSlug::fromString($value);
            $normalized[$slug->value()] = true;
        }
        $this->reserved = $normalized;
    }

    public function assertAllowed(ProfileSlug $slug): void
    {
        if (isset($this->reserved[$slug->value()])) {
            throw new ProfileUrlException('Custom profile slug is reserved.');
        }
    }

    /** @return list<string> */
    public function reserved(): array
    {
        return array_keys($this->reserved);
    }
}
