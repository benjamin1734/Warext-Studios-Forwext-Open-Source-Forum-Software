<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor\Extension;

use Forwext\Core\Forum\Editor\EditorSurface;
use InvalidArgumentException;

final readonly class EditorToolbarExtension
{
    /** @var list<EditorSurface> */
    public array $surfaces;

    /** @param list<EditorSurface> $surfaces */
    public function __construct(
        public string $key,
        public string $label,
        public string $open,
        public string $close = '',
        public int $order = 500,
        array $surfaces = [EditorSurface::Thread, EditorSurface::Post],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,190}$/D', $this->key) !== 1) {
            throw new InvalidArgumentException('Editor extension key is invalid.');
        }
        if (
            $this->label === ''
            || strlen($this->label) > 80
            || preg_match('//u', $this->label) !== 1
        ) {
            throw new InvalidArgumentException('Editor extension label is invalid.');
        }
        if ($this->order < -10000 || $this->order > 10000) {
            throw new InvalidArgumentException('Editor extension order is outside supported bounds.');
        }
        if (
            ($this->open === '' && $this->close === '')
            || strlen($this->open) > 256
            || strlen($this->close) > 256
            || preg_match('//u', $this->open . $this->close) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $this->open . $this->close) === 1
        ) {
            throw new InvalidArgumentException('Editor extension insertion text is invalid.');
        }

        $normalized = [];
        foreach ($surfaces as $surface) {
            if (!$surface instanceof EditorSurface) {
                throw new InvalidArgumentException('Editor extension surface is invalid.');
            }
            $normalized[$surface->value] = $surface;
        }
        if ($normalized === []) {
            throw new InvalidArgumentException('Editor extension must target at least one editor surface.');
        }
        ksort($normalized, SORT_STRING);
        $this->surfaces = array_values($normalized);
    }

    public function supports(EditorSurface $surface): bool
    {
        foreach ($this->surfaces as $candidate) {
            if ($candidate === $surface) {
                return true;
            }
        }

        return false;
    }
}
