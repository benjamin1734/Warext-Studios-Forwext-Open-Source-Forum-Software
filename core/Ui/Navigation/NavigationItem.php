<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Navigation;

use InvalidArgumentException;

final readonly class NavigationItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $path,
        public int $order = 100,
        public NavigationAudience $audience = NavigationAudience::Public,
        public ?string $moduleKey = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Navigation key is invalid.');
        }
        if ($this->label === '' || strlen($this->label) > 80) {
            throw new InvalidArgumentException('Navigation label must contain 1..80 bytes.');
        }
        if (
            $this->path === ''
            || $this->path[0] !== '/'
            || str_starts_with($this->path, '//')
            || str_contains($this->path, '?')
            || str_contains($this->path, '#')
            || preg_match('/[\x00-\x1F\x7F]/', $this->path) === 1
        ) {
            throw new InvalidArgumentException('Navigation path must be a safe same-origin absolute path.');
        }
        if ($this->order < -10000 || $this->order > 10000) {
            throw new InvalidArgumentException('Navigation order is outside supported bounds.');
        }
        if ($this->moduleKey !== null && preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D', $this->moduleKey) !== 1) {
            throw new InvalidArgumentException('Navigation module key is invalid.');
        }
    }
}
