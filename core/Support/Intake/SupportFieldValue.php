<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use JsonException;
use RuntimeException;

final readonly class SupportFieldValue
{
    public function __construct(
        public SupportFieldType $type,
        public string|bool $value,
    ) {
    }

    public function encoded(): string
    {
        return json_encode(
            $this->value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function fromStored(SupportFieldType $type, string $json): self
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored support field JSON is invalid.', previous: $exception);
        }

        if ($type === SupportFieldType::Checkbox) {
            if (!is_bool($value)) {
                throw new RuntimeException('Stored support checkbox value is invalid.');
            }
            return new self($type, $value);
        }

        if (!is_string($value)) {
            throw new RuntimeException('Stored support field value is invalid.');
        }
        return new self($type, $value);
    }
}
