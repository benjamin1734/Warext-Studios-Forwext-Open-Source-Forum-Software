<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

enum IntegrationSection: string
{
    case Mail = 'mail';
    case OAuth = 'oauth';
    case Turnstile = 'turnstile';
    case Ai = 'ai';
    case Storage = 'storage';
    case Cache = 'cache';
    case Queue = 'queue';
    case Search = 'search';
    case Realtime = 'realtime';
    case ApiWebhook = 'api-webhook';

    public function label(): string
    {
        return match ($this) {
            self::Mail => 'Mail',
            self::OAuth => 'OAuth',
            self::Turnstile => 'Turnstile',
            self::Ai => 'AI Providers',
            self::Storage => 'Storage',
            self::Cache => 'Cache',
            self::Queue => 'Queue',
            self::Search => 'Search',
            self::Realtime => 'Realtime',
            self::ApiWebhook => 'API / Webhooks',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mail => 'Outgoing mail configuration contract. Transport delivery is composed by the owning runtime/installer.',
            self::OAuth => 'Google/Discord provider enablement, client ids and HTTPS redirect allowlists.',
            self::Turnstile => 'Registration CAPTCHA behavior and Cloudflare Turnstile identity settings.',
            self::Ai => 'AI moderation provider/model/endpoint policy. Credentials remain in encrypted secret storage.',
            self::Storage => 'Native local storage and preconfiguration for advanced S3-compatible deployments.',
            self::Cache => 'Cache backend selection and TTL policy for native/advanced runtime compositions.',
            self::Queue => 'Database/Redis queue selection and bounded worker reservation policy.',
            self::Search => 'Native search baseline and external adapter preconfiguration.',
            self::Realtime => 'Polling/SSE/WebSocket client delivery policy and bounded timing configuration.',
            self::ApiWebhook => 'Disabled-by-default API/webhook integration configuration ahead of the versioned platform in roadmap step 19.',
        };
    }
}
