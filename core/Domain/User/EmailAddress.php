<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use InvalidArgumentException;
use Stringable;

final readonly class EmailAddress implements Stringable
{
    private function __construct(private string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 254 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(
                'Email address is empty, too long or contains unsupported whitespace/control bytes.',
            );
        }

        $at = strrpos($value, '@');
        if ($at === false) {
            throw new InvalidArgumentException('Email address must contain a domain.');
        }
        $local = substr($value, 0, $at);
        $domain = substr($value, $at + 1);
        if ($local === '' || strlen($local) > 64 || $domain === '') {
            throw new InvalidArgumentException('Email local/domain lengths are invalid.');
        }
        if (preg_match("/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~.-]+$/D", $local) !== 1) {
            throw new InvalidArgumentException('Email local part contains unsupported characters.');
        }
        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            throw new InvalidArgumentException('Email local part dot placement is invalid.');
        }

        $canonical = strtolower($local) . '@' . strtolower(self::asciiDomain($domain));
        if (strlen($canonical) > 254 || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email address is invalid.');
        }

        return new self($canonical);
    }

    public static function fromStored(string $value, string $key): self
    {
        $email = self::fromString($value);
        if (!hash_equals($email->value, $key)) {
            throw new InvalidArgumentException('Stored email canonical key does not match its value.');
        }
        return $email;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function key(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function asciiDomain(string $domain): string
    {
        if (preg_match('/[^\x00-\x7F]/', $domain) !== 1) {
            return $domain;
        }
        if (!function_exists('idn_to_ascii')) {
            throw new InvalidArgumentException('Internationalized email domains require the Intl extension.');
        }

        $variant = defined('INTL_IDNA_VARIANT_UTS46') ? constant('INTL_IDNA_VARIANT_UTS46') : 1;
        $ascii = idn_to_ascii($domain, 0, $variant);
        if (!is_string($ascii) || $ascii === '') {
            throw new InvalidArgumentException('Internationalized email domain normalization failed.');
        }

        return $ascii;
    }
}
