<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use Forwext\Core\Security\Secret\SecretStore;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class SecretStoreAiModerationCredentialStore implements AiModerationCredentialStore
{
    public function __construct(private SecretStore $secrets)
    {
    }

    public function get(string $providerKey): ?string
    {
        return $this->secrets->get($this->name($providerKey));
    }

    public function set(string $providerKey, #[SensitiveParameter] string $credential): void
    {
        if ($credential === '' || strlen($credential) > 8192) {
            throw new InvalidArgumentException('AI moderation credential is invalid.');
        }
        $this->secrets->set($this->name($providerKey), $credential);
    }

    public function delete(string $providerKey): bool
    {
        return $this->secrets->delete($this->name($providerKey));
    }

    private function name(string $providerKey): string
    {
        $providerKey = strtolower(trim($providerKey));
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $providerKey) !== 1) {
            throw new InvalidArgumentException('AI moderation provider key is invalid.');
        }
        return 'ai.provider.' . $providerKey . '.credential';
    }
}
