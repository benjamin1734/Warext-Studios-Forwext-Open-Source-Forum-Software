<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

use InvalidArgumentException;

final readonly class SystemIntegrationCatalog
{
    /** @var array<string,IntegrationSettingDefinition> */
    private array $settings;

    /** @var array<string,IntegrationSecretDefinition> */
    private array $secrets;

    /**
     * @param list<IntegrationSettingDefinition> $settings
     * @param list<IntegrationSecretDefinition> $secrets
     */
    public function __construct(array $settings, array $secrets)
    {
        $settingMap = [];
        $paths = [];
        foreach ($settings as $setting) {
            if (isset($settingMap[$setting->key]) || isset($paths[$setting->configPath])) {
                throw new InvalidArgumentException('Integration catalog contains a duplicate setting.');
            }
            $settingMap[$setting->key] = $setting;
            $paths[$setting->configPath] = true;
        }
        $secretMap = [];
        $names = [];
        foreach ($secrets as $secret) {
            if (isset($secretMap[$secret->key]) || isset($names[$secret->secretName])) {
                throw new InvalidArgumentException('Integration catalog contains a duplicate secret.');
            }
            $secretMap[$secret->key] = $secret;
            $names[$secret->secretName] = true;
        }
        $this->settings = $settingMap;
        $this->secrets = $secretMap;
    }

    public static function coreDefaults(): self
    {
        $s = [];
        $add = static function (
            string $key,
            IntegrationSection $section,
            string $path,
            string $label,
            string $description,
            IntegrationSettingType $type,
            bool $nullable = false,
            array $allowed = [],
            ?int $min = null,
            ?int $max = null,
            int $maxLength = 500,
            bool $editable = true,
            ?string $note = null,
        ) use (&$s): void {
            $s[] = new IntegrationSettingDefinition(
                $key, $section, $path, $label, $description, $type, $nullable,
                $allowed, $min, $max, $maxLength, $editable, $note,
            );
        };

        $add('integration.mail.driver', IntegrationSection::Mail, 'mail.driver', 'Mail driver', 'Outgoing mail driver contract.', IntegrationSettingType::Enum, false, ['disabled','php_mail','smtp']);
        $add('integration.mail.from_address', IntegrationSection::Mail, 'mail.from_address', 'From address', 'Default outgoing sender address.', IntegrationSettingType::Email, true);
        $add('integration.mail.from_name', IntegrationSection::Mail, 'mail.from_name', 'From name', 'Default outgoing sender name.', IntegrationSettingType::String, false, [], null, null, 120);
        $add('integration.mail.smtp_host', IntegrationSection::Mail, 'mail.smtp.host', 'SMTP host', 'SMTP hostname for advanced mail delivery.', IntegrationSettingType::String, true, [], null, null, 253);
        $add('integration.mail.smtp_port', IntegrationSection::Mail, 'mail.smtp.port', 'SMTP port', 'SMTP TCP port.', IntegrationSettingType::Integer, false, [], 1, 65535);
        $add('integration.mail.smtp_encryption', IntegrationSection::Mail, 'mail.smtp.encryption', 'SMTP encryption', 'SMTP transport security mode.', IntegrationSettingType::Enum, false, ['none','starttls','tls']);
        $add('integration.mail.smtp_username', IntegrationSection::Mail, 'mail.smtp.username', 'SMTP username', 'Optional SMTP authentication username.', IntegrationSettingType::String, true, [], null, null, 254);

        foreach (['google','discord'] as $provider) {
            $label = ucfirst($provider);
            $add("integration.oauth.$provider.enabled", IntegrationSection::OAuth, "oauth.providers.$provider.enabled", "$label enabled", "Enable the $label OAuth provider.", IntegrationSettingType::Flag);
            $add("integration.oauth.$provider.client_id", IntegrationSection::OAuth, "oauth.providers.$provider.client_id", "$label client id", "Public OAuth client identifier.", IntegrationSettingType::String, true, [], null, null, 255);
            $add("integration.oauth.$provider.redirect_uris", IntegrationSection::OAuth, "oauth.providers.$provider.redirect_uris", "$label redirect URIs", "HTTPS redirect allowlist, one URI per line.", IntegrationSettingType::HttpsUrlList, false, [], null, null, 2048);
        }

        $add('integration.turnstile.required', IntegrationSection::Turnstile, 'registration.captcha.required', 'CAPTCHA required', 'Require CAPTCHA during registration.', IntegrationSettingType::Flag);
        $add('integration.turnstile.site_key', IntegrationSection::Turnstile, 'registration.captcha.site_key', 'Site key', 'Public Cloudflare Turnstile site key.', IntegrationSettingType::String, true, [], null, null, 255);
        $add('integration.turnstile.hostname', IntegrationSection::Turnstile, 'registration.captcha.expected_hostname', 'Expected hostname', 'Optional hostname binding validated on Turnstile responses.', IntegrationSettingType::String, true, [], null, null, 253);
        $add('integration.turnstile.action', IntegrationSection::Turnstile, 'registration.captcha.expected_action', 'Expected action', 'Expected Turnstile action claim.', IntegrationSettingType::String, false, [], null, null, 64);

        $add('integration.ai.provider', IntegrationSection::Ai, 'ai.moderation.provider', 'Provider', 'AI moderation provider selector.', IntegrationSettingType::Enum, false, ['disabled','openai','gemini','anthropic','openrouter','custom']);
        $add('integration.ai.model', IntegrationSection::Ai, 'ai.moderation.model', 'Model', 'Provider model identifier.', IntegrationSettingType::String, true, [], null, null, 120);
        $add('integration.ai.custom_endpoint', IntegrationSection::Ai, 'ai.moderation.custom_endpoint', 'Custom endpoint', 'Custom provider HTTPS endpoint. Provider endpoint policy still applies.', IntegrationSettingType::HttpsUrl, true, [], null, null, 2048);
        $add('integration.ai.timeout_ms', IntegrationSection::Ai, 'ai.moderation.timeout_ms', 'Timeout (ms)', 'Bounded provider request timeout.', IntegrationSettingType::Integer, false, [], 100, 30000);

        $add('integration.storage.driver', IntegrationSection::Storage, 'storage.driver', 'Storage driver', 'Native PHP runtime currently composes local storage; S3 credentials may be staged for advanced composition.', IntegrationSettingType::Enum, false, ['local'], null, null, 500, true, 'S3-compatible contract exists but native WebApplicationFactory currently composes local storage only.');
        $add('integration.storage.public_base_url', IntegrationSection::Storage, 'storage.local.public_base_url', 'Public storage base URL', 'Optional public URL prefix for local public objects.', IntegrationSettingType::HttpsUrl, true, [], null, null, 2048);
        $add('integration.storage.s3_bucket', IntegrationSection::Storage, 'storage.s3.bucket', 'S3 bucket', 'Preconfigure an S3-compatible bucket for advanced runtime composition.', IntegrationSettingType::String, true, [], null, null, 255);
        $add('integration.storage.s3_prefix', IntegrationSection::Storage, 'storage.s3.prefix', 'S3 prefix', 'Optional object-key prefix.', IntegrationSettingType::String, true, [], null, null, 512);
        $add('integration.storage.s3_endpoint', IntegrationSection::Storage, 'storage.s3.endpoint', 'S3 endpoint', 'S3-compatible HTTPS endpoint.', IntegrationSettingType::HttpsUrl, true, [], null, null, 2048);
        $add('integration.storage.s3_region', IntegrationSection::Storage, 'storage.s3.region', 'S3 region', 'Optional S3 region.', IntegrationSettingType::String, true, [], null, null, 100);

        $add('integration.cache.driver', IntegrationSection::Cache, 'cache.driver', 'Cache driver', 'Cache backend preference.', IntegrationSettingType::Enum, false, ['file','database','redis']);
        $add('integration.cache.ttl', IntegrationSection::Cache, 'cache.default_ttl_seconds', 'Default TTL', 'Default cache TTL in seconds.', IntegrationSettingType::Integer, false, [], 1, 86400);

        $add('integration.queue.driver', IntegrationSection::Queue, 'queue.driver', 'Queue driver', 'Queue backend preference. Redis requires ext-redis and Redis connection configuration.', IntegrationSettingType::Enum, false, ['database','redis']);
        $add('integration.queue.visibility_timeout', IntegrationSection::Queue, 'queue.visibility_timeout_seconds', 'Visibility timeout', 'Worker reservation visibility timeout in seconds.', IntegrationSettingType::Integer, false, [], 1, 86400);
        $add('integration.queue.max_attempts', IntegrationSection::Queue, 'queue.default_max_attempts', 'Default max attempts', 'Default bounded job attempt count.', IntegrationSettingType::Integer, false, [], 1, 100);
        $add('integration.queue.redis_host', IntegrationSection::Queue, 'redis.host', 'Redis host', 'Redis hostname used by advanced cache/queue/session compositions.', IntegrationSettingType::String, true, [], null, null, 253);
        $add('integration.queue.redis_port', IntegrationSection::Queue, 'redis.port', 'Redis port', 'Redis TCP port.', IntegrationSettingType::Integer, false, [], 1, 65535);
        $add('integration.queue.redis_database', IntegrationSection::Queue, 'redis.database', 'Redis database', 'Optional Redis logical database.', IntegrationSettingType::Integer, true, [], 0, 65535);
        $add('integration.queue.redis_timeout_ms', IntegrationSection::Queue, 'redis.timeout_ms', 'Redis timeout (ms)', 'Redis connect timeout.', IntegrationSettingType::Integer, false, [], 100, 30000);

        $add('integration.search.driver', IntegrationSection::Search, 'search.driver', 'Search driver', 'Native runtime currently composes the permission-aware native MySQL/MariaDB index.', IntegrationSettingType::Enum, false, ['native'], null, null, 500, true, 'ExternalSearchClient is a provider contract; concrete external provider composition is add-on/advanced-runtime work.');
        $add('integration.search.external_endpoint', IntegrationSection::Search, 'search.external.endpoint', 'External search endpoint', 'Preconfigure a future external search HTTPS endpoint.', IntegrationSettingType::HttpsUrl, true, [], null, null, 2048);

        $add('integration.realtime.mode', IntegrationSection::Realtime, 'realtime.mode', 'Realtime mode', 'Client delivery preference: polling, SSE or external WebSocket gateway.', IntegrationSettingType::Enum, false, ['polling','sse','websocket']);
        $add('integration.realtime.poll_interval', IntegrationSection::Realtime, 'realtime.poll_interval_ms', 'Polling interval (ms)', 'Visible-tab polling interval.', IntegrationSettingType::Integer, false, [], 1000, 60000);
        $add('integration.realtime.hidden_interval', IntegrationSection::Realtime, 'realtime.hidden_poll_interval_ms', 'Hidden polling interval (ms)', 'Background-tab polling interval.', IntegrationSettingType::Integer, false, [], 1000, 300000);
        $add('integration.realtime.sse_retry', IntegrationSection::Realtime, 'realtime.sse_retry_ms', 'SSE retry (ms)', 'Client retry delay for SSE.', IntegrationSettingType::Integer, false, [], 1000, 60000);
        $add('integration.realtime.websocket_path', IntegrationSection::Realtime, 'realtime.websocket_path', 'WebSocket path', 'Optional same-origin WebSocket gateway path.', IntegrationSettingType::SameOriginPath, true, [], null, null, 500);

        $add('integration.api.enabled', IntegrationSection::ApiWebhook, 'api.enabled', 'REST API enabled', 'Roadmap step 19 owns the versioned REST API. This switch remains disabled until that platform is installed.', IntegrationSettingType::Flag, false, [], null, null, 500, false, 'REST API platform is implemented in roadmap step 19.01.');
        $add('integration.webhooks.enabled', IntegrationSection::ApiWebhook, 'webhooks.enabled', 'Webhooks enabled', 'Roadmap step 19 owns signed webhooks/retries. This switch remains disabled until that platform is installed.', IntegrationSettingType::Flag, false, [], null, null, 500, false, 'Webhook platform is implemented in roadmap step 19.03.');

        $secrets = [
            new IntegrationSecretDefinition('integration.mail.smtp_password', IntegrationSection::Mail, 'mail.smtp.password', 'SMTP password', 'Encrypted SMTP credential.'),
            new IntegrationSecretDefinition('integration.oauth.google.client_secret', IntegrationSection::OAuth, 'oauth.google.client_secret', 'Google client secret', 'Encrypted Google OAuth client secret.'),
            new IntegrationSecretDefinition('integration.oauth.discord.client_secret', IntegrationSection::OAuth, 'oauth.discord.client_secret', 'Discord client secret', 'Encrypted Discord OAuth client secret.'),
            new IntegrationSecretDefinition('integration.turnstile.secret', IntegrationSection::Turnstile, 'turnstile.secret', 'Turnstile secret', 'Encrypted Cloudflare Turnstile server secret.'),
            new IntegrationSecretDefinition('integration.ai.openai', IntegrationSection::Ai, 'ai.openai.api_key', 'OpenAI API key', 'Encrypted OpenAI credential.'),
            new IntegrationSecretDefinition('integration.ai.gemini', IntegrationSection::Ai, 'ai.gemini.api_key', 'Gemini API key', 'Encrypted Gemini credential.'),
            new IntegrationSecretDefinition('integration.ai.anthropic', IntegrationSection::Ai, 'ai.anthropic.api_key', 'Anthropic API key', 'Encrypted Anthropic credential.'),
            new IntegrationSecretDefinition('integration.ai.openrouter', IntegrationSection::Ai, 'ai.openrouter.api_key', 'OpenRouter API key', 'Encrypted OpenRouter credential.'),
            new IntegrationSecretDefinition('integration.ai.custom', IntegrationSection::Ai, 'ai.custom.api_key', 'Custom AI credential', 'Encrypted custom AI bearer credential.'),
            new IntegrationSecretDefinition('integration.storage.s3_access_key', IntegrationSection::Storage, 'storage.s3.access_key', 'S3 access key', 'Encrypted S3-compatible access key.'),
            new IntegrationSecretDefinition('integration.storage.s3_secret_key', IntegrationSection::Storage, 'storage.s3.secret_key', 'S3 secret key', 'Encrypted S3-compatible secret key.'),
            new IntegrationSecretDefinition('integration.redis.password', IntegrationSection::Queue, 'redis.password', 'Redis password', 'Optional encrypted Redis password.'),
            new IntegrationSecretDefinition('integration.search.external_api_key', IntegrationSection::Search, 'search.external.api_key', 'External search API key', 'Encrypted external search credential.'),
            new IntegrationSecretDefinition('integration.api.signing_secret', IntegrationSection::ApiWebhook, 'api.signing_secret', 'API signing secret', 'Reserved encrypted signing secret for roadmap step 19.'),
            new IntegrationSecretDefinition('integration.webhooks.signing_secret', IntegrationSection::ApiWebhook, 'webhooks.signing_secret', 'Webhook signing secret', 'Reserved encrypted signing secret for roadmap step 19.'),
        ];

        return new self($s, $secrets);
    }

    /** @return list<IntegrationSettingDefinition> */
    public function settings(): array
    {
        return array_values($this->settings);
    }

    /** @return list<IntegrationSecretDefinition> */
    public function secrets(): array
    {
        return array_values($this->secrets);
    }

    public function setting(string $key): IntegrationSettingDefinition
    {
        return $this->settings[$key] ?? throw new InvalidArgumentException('Unknown integration setting.');
    }

    public function secret(string $key): IntegrationSecretDefinition
    {
        return $this->secrets[$key] ?? throw new InvalidArgumentException('Unknown integration secret.');
    }
}
