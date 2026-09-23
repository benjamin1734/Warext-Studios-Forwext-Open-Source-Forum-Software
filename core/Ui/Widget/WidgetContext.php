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
    }

    public function fingerprint(): string
    {
        try {
            $payload = json_encode(
                [
                    'title' => $this->pageTitle,
                    'locale' => $this->locale,
                    'authenticated' => $this->authenticated,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Widget context cannot be fingerprinted.', previous: $exception);
        }

        return hash('sha256', $payload);
    }
}
