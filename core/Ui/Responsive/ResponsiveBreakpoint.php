<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Responsive;

use InvalidArgumentException;

final readonly class ResponsiveBreakpoint
{
    public function __construct(
        public string $key,
        public int $minWidth,
        public ?int $maxWidth,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Responsive breakpoint key is invalid.');
        }

        if ($this->minWidth < 0 || $this->minWidth > 10000) {
            throw new InvalidArgumentException('Responsive breakpoint minimum width is invalid.');
        }

        if ($this->maxWidth !== null && ($this->maxWidth < $this->minWidth || $this->maxWidth > 10000)) {
            throw new InvalidArgumentException('Responsive breakpoint maximum width is invalid.');
        }
    }

    public function mediaQuery(): string
    {
        $query = '(min-width:' . $this->minWidth . 'px)';
        if ($this->maxWidth !== null) {
            $query .= ' and (max-width:' . $this->maxWidth . 'px)';
        }

        return $query;
    }
}
