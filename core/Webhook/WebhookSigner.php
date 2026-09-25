<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use SensitiveParameter;

final class WebhookSigner
{
    public static function signature(
        #[SensitiveParameter] string $secret,
        int $secretVersion,
        string $deliveryId,
        int $timestamp,
        string $body,
    ): string {
        if (preg_match('/^whsec_[A-Za-z0-9_-]{43}$/D', $secret) !== 1
            || $secretVersion < 1 || $secretVersion > 65535
            || preg_match('/^[a-f0-9]{32}$/D', $deliveryId) !== 1
            || $timestamp < 1
        ) {
            throw new WebhookException('Webhook signature input is invalid.');
        }

        return 'v' . $secretVersion . '=' . hash_hmac(
            'sha256',
            'v1.' . $timestamp . '.' . $deliveryId . '.' . $body,
            $secret,
        );
    }
}
