<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use SensitiveParameter;

interface AiModerationCredentialStore
{
    public function get(string $providerKey): ?string;

    public function set(string $providerKey, #[SensitiveParameter] string $credential): void;

    public function delete(string $providerKey): bool;
}
