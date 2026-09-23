<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Widget;

use InvalidArgumentException;
use JsonException;

final readonly class WidgetContext
{
    public function __construct(
        public string $pageTitle,
        public string $locale,
        public bool $authenticated,
        public ?string $viewerId = null,
    ) {
        if (
            $this->pageTitle === ''
            || strlen($this->pageTitle) > 200
            || preg_match('//u', $this->pageTitle) !== 1
        ) {
            throw new InvalidArgumentException('Widget page title context is invalid.');
        }

        if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/D', $this->locale) !== 1) {
            throw new InvalidArgumentException('Widget locale context is invalid.');
        }

        if (
            $this->viewerId !== null
            && preg_match('/^[a-f0-9]{32}$/D', $this->viewerId) !== 1
        ) {
            throw new InvalidArgumentException('Widget viewer id must be an opaque 128-bit identifier.');
        }

        if (!$this->authenticated && $this->viewerId !== null) {
            throw new InvalidArgumentException('Guest widget context cannot carry a viewer id.');
        }
    }

    public function cacheSafe(): bool
    {
        return !$this->authenticated || $this->viewerId !== null;
    }

    public function fingerprint(): string
    {
        try {
            $payload = json_encode(
                [
                    'title' => $this->pageTitle,
                    'locale' => $this->locale,
                    'authenticated' => $this->authenticated,
                    'viewer' => $this->viewerId ?? 'guest',
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Widget context cannot be fingerprinted.', previous: $exception);
        }

        return hash('sha256', $payload);
    }
}
