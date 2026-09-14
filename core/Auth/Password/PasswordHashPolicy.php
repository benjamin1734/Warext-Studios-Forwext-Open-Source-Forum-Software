<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Password;

use Forwext\Core\Auth\AuthException;
use SensitiveParameter;

final readonly class PasswordHashPolicy
{
    public function __construct(
        public int $minimumCharacters = 12,
        public int $maximumBytes = 1024,
        public int $argonMemoryCost = 32768,
        public int $argonTimeCost = 3,
        public int $argonThreads = 1,
        public int $bcryptCost = 12,
    ) {
        if ($minimumCharacters < 8 || $minimumCharacters > 128 || $maximumBytes < 64 || $maximumBytes > 4096) {
            throw new AuthException('Password length policy is outside safe bounds.');
        }
        if ($argonMemoryCost < 8192 || $argonMemoryCost > 262144 || $argonTimeCost < 1 || $argonTimeCost > 10 || $argonThreads < 1 || $argonThreads > 8) {
            throw new AuthException('Argon2id password cost policy is outside safe bounds.');
        }
        if ($bcryptCost < 10 || $bcryptCost > 15) {
            throw new AuthException('Bcrypt password cost policy is outside safe bounds.');
        }
    }

    public function assertPassword(#[SensitiveParameter] string $password): void
    {
        if ($password === ''
            || strlen($password) > $this->maximumBytes
            || str_contains($password, "\0")
            || preg_match('//u', $password) !== 1
            || trim($password) === ''
        ) {
            throw new AuthException('Password does not satisfy the configured length/encoding policy.');
        }
        $characters = preg_match_all('/./us', $password, $matches);
        if ($characters === false || $characters < $this->minimumCharacters) {
            throw new AuthException('Password does not satisfy the configured length/encoding policy.');
        }
    }

    public function algorithm(): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $algorithm = constant('PASSWORD_ARGON2ID');
            if (is_string($algorithm)) {
                return $algorithm;
            }
        }
        $bcrypt = constant('PASSWORD_BCRYPT');
        if (!is_string($bcrypt)) {
            throw new AuthException('No supported password hashing algorithm is available.');
        }
        return $bcrypt;
    }

    /** @return array<string, int> */
    public function options(): array
    {
        $algorithm = $this->algorithm();
        if (defined('PASSWORD_ARGON2ID') && $algorithm === constant('PASSWORD_ARGON2ID')) {
            return [
                'memory_cost' => $this->argonMemoryCost,
                'time_cost' => $this->argonTimeCost,
                'threads' => $this->argonThreads,
            ];
        }
        return ['cost' => $this->bcryptCost];
    }
}
