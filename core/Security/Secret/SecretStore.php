<?php

declare(strict_types=1);

namespace Forwext\Core\Security\Secret;

use SensitiveParameter;

interface SecretStore
{
    public function has(string $name): bool;

    public function get(string $name): ?string;

    public function set(string $name, #[SensitiveParameter] string $value): void;

    public function delete(string $name): bool;

    /** @return array<string, string> */
    public function all(): array;
}
