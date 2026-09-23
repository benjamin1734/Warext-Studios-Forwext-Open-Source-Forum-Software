<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Guide;

use InvalidArgumentException;

final readonly class AppearanceGuideItem
{
    /**
     * @param list<string> $keywords
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public AppearanceGuideLevel $level,
        public string $path,
        public string $requiredPermission,
        public array $keywords,
        public bool $previewCapable,
        public string $recoveryHint,
    ) {
        if (preg_match('/^appearance\.[a-z0-9.-]{2,63}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Appearance guide item key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 120) {
            throw new InvalidArgumentException('Appearance guide item label is invalid.');
        }
        if ($this->description === '' || strlen($this->description) > 600) {
            throw new InvalidArgumentException('Appearance guide item description is invalid.');
        }
        if (
            !str_starts_with($this->path, '/admin/appearance')
            || str_starts_with($this->path, '//')
            || str_contains($this->path, '?')
            || str_contains($this->path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $this->path) === 1
        ) {
            throw new InvalidArgumentException('Appearance guide item path must be a safe same-origin admin path.');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D', $this->requiredPermission) !== 1) {
            throw new InvalidArgumentException('Appearance guide item permission is invalid.');
        }
        if (count($this->keywords) > 20) {
            throw new InvalidArgumentException('Appearance guide item has too many keywords.');
        }
        foreach ($this->keywords as $keyword) {
            if (!is_string($keyword) || $keyword === '' || strlen($keyword) > 60) {
                throw new InvalidArgumentException('Appearance guide item keyword is invalid.');
            }
        }
        if ($this->recoveryHint === '' || strlen($this->recoveryHint) > 300) {
            throw new InvalidArgumentException('Appearance guide item recovery hint is invalid.');
        }
    }

    public function matches(string $query): bool
    {
        if ($query === '') {
            return true;
        }

        $haystack = strtolower(implode(' ', [
            $this->key,
            $this->label,
            $this->description,
            $this->path,
            $this->requiredPermission,
            $this->recoveryHint,
            ...$this->keywords,
        ]));

        return str_contains($haystack, strtolower($query));
    }
}
