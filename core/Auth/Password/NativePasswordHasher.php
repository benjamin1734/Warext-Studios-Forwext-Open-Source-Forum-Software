<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Password;

use Forwext\Core\Auth\AuthException;
use SensitiveParameter;

final class NativePasswordHasher implements PasswordHasher
{
    private string $dummyHash;

    public function __construct(private readonly PasswordHashPolicy $policy = new PasswordHashPolicy())
    {
        $hash = password_hash('Forwext-Dummy-Authentication-Password-Only', $policy->algorithm(), $policy->options());
        if (!is_string($hash)) {
            throw new AuthException('Unable to initialize password verification policy.');
        }
        $this->dummyHash = $hash;
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        $this->policy->assertPassword($password);
        $hash = password_hash($password, $this->policy->algorithm(), $this->policy->options());
        if (!is_string($hash) || $hash === '') {
            throw new AuthException('Password hashing failed.');
        }
        return $hash;
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if ($hash === '' || strlen($hash) > 255) {
            return false;
        }
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->policy->algorithm(), $this->policy->options());
    }

    public function dummyVerify(#[SensitiveParameter] string $password): void
    {
        password_verify($password, $this->dummyHash);
    }
}
