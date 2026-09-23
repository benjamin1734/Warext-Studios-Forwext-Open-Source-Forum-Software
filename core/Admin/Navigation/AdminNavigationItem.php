<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use InvalidArgumentException;

final readonly class AdminNavigationItem
{
    /**
     * @param list<string> $requiredAnyPermissions
     * @param list<string> $keywords
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public AdminNavigationSection $section,
        public string $path,
        public array $requiredAnyPermissions,
        public array $keywords = [],
    ) {
        if (preg_match('/^admin\.[a-z][a-z0-9.-]{1,62}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Admin navigation key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 120) {
            throw new InvalidArgumentException('Admin navigation label is invalid.');
        }
        if ($this->description === '' || strlen($this->description) > 500) {
            throw new InvalidArgumentException('Admin navigation description is invalid.');
        }
        if (!$this->safeAdminPath($this->path)) {
            throw new InvalidArgumentException('Admin navigation path must be a safe first-party management path.');
        }
        if ($this->requiredAnyPermissions === [] || count($this->requiredAnyPermissions) > 8) {
            throw new InvalidArgumentException('Admin navigation permission list is invalid.');
        }
        foreach ($this->requiredAnyPermissions as $permission) {
            if (!is_string($permission) || preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $permission) !== 1) {
                throw new InvalidArgumentException('Admin navigation permission is invalid.');
            }
        }
        if (count($this->keywords) > 24) {
            throw new InvalidArgumentException('Admin navigation keyword list is too large.');
        }
        foreach ($this->keywords as $keyword) {
            if (!is_string($keyword) || $keyword === '' || strlen($keyword) > 80) {
                throw new InvalidArgumentException('Admin navigation keyword is invalid.');
            }
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
            $this->section->label(),
            $this->path,
            ...$this->keywords,
        ]));

        return str_contains($haystack, strtolower($query));
    }

    private function safeAdminPath(string $path): bool
    {
        if (
            $path === ''
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            return false;
        }

        return $path === '/admin'
            || str_starts_with($path, '/admin/')
            || $path === '/moderation'
            || str_starts_with($path, '/moderation/')
            || $path === '/support/staff'
            || str_starts_with($path, '/support/staff/')
            || $path === '/bugs/staff'
            || str_starts_with($path, '/bugs/staff/');
    }
}
