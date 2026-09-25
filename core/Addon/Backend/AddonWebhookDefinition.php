<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

use InvalidArgumentException;

final readonly class AddonWebhookDefinition
{
    public function __construct(
        public string $key,
        public AddonWebhookDirection $direction,
        public string $eventName,
        public string $description,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $this->key) !== 1
            || preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $this->eventName) !== 1
        ) {
            throw new InvalidArgumentException('Add-on webhook key or event name is invalid.');
        }
        if ($this->description === '' || strlen($this->description) > 500 || preg_match('//u', $this->description) !== 1) {
            throw new InvalidArgumentException('Add-on webhook description is invalid.');
        }
    }
}
