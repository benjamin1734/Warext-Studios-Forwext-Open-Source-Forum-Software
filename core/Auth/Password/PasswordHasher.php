<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Password;

use SensitiveParameter;

interface PasswordHasher
{
    public function hash(#[SensitiveParameter] string $password): string;

    public function verify(#[SensitiveParameter] string $password, string $hash): bool;

    public function needsRehash(string $hash): bool;

    public function dummyVerify(#[SensitiveParameter] string $password): void;
}
