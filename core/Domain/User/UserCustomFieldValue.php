<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use InvalidArgumentException;
use JsonException;

final readonly class UserCustomFieldValue
{
    private const MAX_ENCODED_BYTES = 65535;

    private function __construct(
        public UserCustomFieldType $type,
        private string $encoded,
    ) {
        if ($encoded === '' || strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new InvalidArgumentException('User custom field value is empty or too large.');
        }
    }

    public static function string(string $value): self
    {
        if (strlen($value) > 20000 || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('User custom field string is invalid or too large.');
        }
        return new self(UserCustomFieldType::String, self::encode($value));
    }

    public static function integer(int $value): self
    {
        return new self(UserCustomFieldType::Integer, self::encode($value));
    }

    public static function boolean(bool $value): self
    {
        return new self(UserCustomFieldType::Boolean, self::encode($value));
    }

    /** @param array<array-key, mixed> $value */
    public static function json(array $value): self
    {
        return new self(UserCustomFieldType::Json, self::encode($value));
    }

    public static function fromStored(UserCustomFieldType $type, string $encoded): self
    {
        $instance = new self($type, $encoded);
        $instance->decoded();
        return $instance;
    }

    public function encoded(): string
    {
        return $this->encoded;
    }

    public function decoded(): string|int|bool|array
    {
        try {
            $value = json_decode($this->encoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Stored user custom field JSON is invalid.', previous: $exception);
        }

        return match ($this->type) {
            UserCustomFieldType::String => is_string($value)
                ? $value
                : throw new InvalidArgumentException('Stored string custom field has the wrong JSON type.'),
            UserCustomFieldType::Integer => is_int($value)
                ? $value
                : throw new InvalidArgumentException('Stored integer custom field has the wrong JSON type.'),
            UserCustomFieldType::Boolean => is_bool($value)
                ? $value
                : throw new InvalidArgumentException('Stored boolean custom field has the wrong JSON type.'),
            UserCustomFieldType::Json => is_array($value)
                ? $value
                : throw new InvalidArgumentException('Stored JSON custom field must decode to an array.'),
        };
    }

    private static function encode(mixed $value): string
    {
        try {
            $encoded = json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('User custom field cannot be encoded as JSON.', previous: $exception);
        }

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new InvalidArgumentException('User custom field JSON exceeds the storage limit.');
        }
        return $encoded;
    }
}
