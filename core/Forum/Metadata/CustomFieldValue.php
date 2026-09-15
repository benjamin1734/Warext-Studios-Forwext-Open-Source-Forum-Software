<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use JsonException;
use RuntimeException;

final readonly class CustomFieldValue
{
    public function __construct(
        public CustomFieldType $type,
        public string|int|bool $value,
    ) {
    }

    public function encoded(): string
    {
        return json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fromStored(CustomFieldType $type, string $json): self
    {
        try {
            $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored custom field value JSON is invalid.', 0, $exception);
        }

        $valid = match ($type) {
            CustomFieldType::Text, CustomFieldType::Choice => is_string($value),
            CustomFieldType::Integer => is_int($value),
            CustomFieldType::Boolean => is_bool($value),
        };
        if (!$valid) {
            throw new RuntimeException('Stored custom field value does not match its declared type.');
        }

        return new self($type, $value);
    }
}
