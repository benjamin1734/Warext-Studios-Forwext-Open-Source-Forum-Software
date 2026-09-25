<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use Forwext\Core\Security\Secret\SecretStore;
use SensitiveParameter;

final readonly class WebhookSecretManager
{
    public function __construct(private SecretStore $secrets)
    {
    }

    public function issue(string $subscriptionId, int $version): string
    {
        $this->assertVersion($subscriptionId, $version);
        $secret = 'whsec_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->secrets->set($this->name($subscriptionId, $version), $secret);

        return $secret;
    }

    public function get(string $subscriptionId, int $version): string
    {
        $this->assertVersion($subscriptionId, $version);
        $secret = $this->secrets->get($this->name($subscriptionId, $version));
        if ($secret === null || !$this->validSecret($secret)) {
            throw new WebhookException('Webhook signing secret is unavailable.');
        }

        return $secret;
    }

    public function delete(string $subscriptionId, int $version): bool
    {
        $this->assertVersion($subscriptionId, $version);
        return $this->secrets->delete($this->name($subscriptionId, $version));
    }

    public function validSecret(#[SensitiveParameter] string $secret): bool
    {
        return preg_match('/^whsec_[A-Za-z0-9_-]{43}$/D', $secret) === 1;
    }

    private function name(string $subscriptionId, int $version): string
    {
        return 'webhook.subscription.' . $subscriptionId . '.v' . $version;
    }

    private function assertVersion(string $subscriptionId, int $version): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $subscriptionId) !== 1 || $version < 1 || $version > 65535) {
            throw new WebhookException('Webhook signing-secret reference is invalid.');
        }
    }
}
