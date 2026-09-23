<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use InvalidArgumentException;

final class AdminNavigationPreferences
{
    private const MAX_FAVORITES = 12;
    private const MAX_RECENT = 10;

    /** @var list<string> */
    private array $favorites;

    /** @var list<string> */
    private array $recent;

    /**
     * @param list<string> $favorites
     * @param list<string> $recent
     */
    public function __construct(array $favorites = [], array $recent = [])
    {
        $this->favorites = $this->normalize($favorites, self::MAX_FAVORITES);
        $this->recent = $this->normalize($recent, self::MAX_RECENT);
    }

    /** @return list<string> */
    public function favorites(): array
    {
        return $this->favorites;
    }

    /** @return list<string> */
    public function recent(): array
    {
        return $this->recent;
    }

    public function toggledFavorite(string $key): self
    {
        self::assertKey($key);
        $favorites = $this->favorites;
        $index = array_search($key, $favorites, true);
        if (is_int($index)) {
            array_splice($favorites, $index, 1);
        } else {
            array_unshift($favorites, $key);
            $favorites = array_slice($favorites, 0, self::MAX_FAVORITES);
        }

        return new self($favorites, $this->recent);
    }

    public function recordedRecent(string $key): self
    {
        self::assertKey($key);
        $recent = array_values(array_filter(
            $this->recent,
            static fn (string $candidate): bool => $candidate !== $key,
        ));
        array_unshift($recent, $key);

        return new self($this->favorites, array_slice($recent, 0, self::MAX_RECENT));
    }

    /** @param array<string,bool> $allowedKeys */
    public function filtered(array $allowedKeys): self
    {
        return new self(
            array_values(array_filter(
                $this->favorites,
                static fn (string $key): bool => isset($allowedKeys[$key]),
            )),
            array_values(array_filter(
                $this->recent,
                static fn (string $key): bool => isset($allowedKeys[$key]),
            )),
        );
    }

    /** @param list<string> $keys @return list<string> */
    private function normalize(array $keys, int $limit): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Admin navigation preference key is invalid.');
            }
            self::assertKey($key);
            $result[$key] = $key;
        }

        return array_slice(array_values($result), 0, $limit);
    }

    private static function assertKey(string $key): void
    {
        if (preg_match('/^admin\.[a-z][a-z0-9.-]{1,62}$/D', $key) !== 1) {
            throw new InvalidArgumentException('Admin navigation preference key is invalid.');
        }
    }
}
