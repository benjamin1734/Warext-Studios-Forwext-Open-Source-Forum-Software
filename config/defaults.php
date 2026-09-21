<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'production',
        'debug' => false,
        'maintenance' => false,
    ],
    'routing' => [
        'canonical_url' => null,
        'trusted_proxies' => [],
        'cloudflare_proxies' => [],
    ],
    'database' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => null,
        'username' => null,
        'charset' => 'utf8mb4',
        'connect_timeout_seconds' => 5,
        'unix_socket' => null,
        'password_secret' => 'database.password',
    ],
    'cache' => [
        'driver' => 'file',
        'path' => 'storage/cache/data',
        'default_ttl_seconds' => 300,
    ],
    'session' => [
        'driver' => 'file',
        'path' => 'storage/sessions',
        'ttl_seconds' => 7200,
    ],
    'lock' => [
        'driver' => 'file',
        'path' => 'storage/locks',
        'default_ttl_seconds' => 30,
    ],
    'storage' => [
        'driver' => 'local',
        'local' => [
            'private_root' => 'storage/files/private',
            'public_root' => 'public/storage',
            'public_base_url' => null,
        ],
        's3' => [
            'bucket' => null,
            'prefix' => '',
            'endpoint' => null,
            'region' => null,
            'access_key_secret' => 'storage.s3.access_key',
            'secret_key_secret' => 'storage.s3.secret_key',
        ],
    ],
    'queue' => [
        'driver' => 'database',
        'default_queue' => 'default',
        'visibility_timeout_seconds' => 60,
        'default_max_attempts' => 3,
    ],
    'scheduler' => [
        'claim_driver' => 'database',
        'timezone' => 'UTC',
        'claim_retention_days' => 14,
    ],
    'realtime' => [
        'mode' => 'polling',
        'poll_limit' => 100,
        'poll_interval_ms' => 3000,
        'hidden_poll_interval_ms' => 15000,
        'sse_retry_ms' => 4000,
        'websocket_path' => null,
        'message_retention_seconds' => 86400,
    ],
    'search' => [
        'driver' => 'native',
    ],
    'registration' => [
        'mode' => 'closed',
        'email_verification_required' => true,
        'email_verification_ttl_seconds' => 86400,
        'captcha' => [
            'required' => true,
            'provider' => 'turnstile',
            'site_key' => null,
            'secret_name' => 'turnstile.secret',
            'expected_hostname' => null,
            'expected_action' => 'register',
        ],
        'rate_limit' => [
            'ip_attempts' => 10,
            'email_attempts' => 5,
            'window_seconds' => 3600,
            'fingerprint_secret_name' => 'registration.fingerprint_key',
        ],
        'disposable_email_domains' => [],
        'legal_documents' => [],
    ],
    'authentication' => [
        'password' => [
            'minimum_characters' => 12,
            'maximum_bytes' => 1024,
            'preferred_algorithm' => 'argon2id',
            'argon_memory_cost' => 32768,
            'argon_time_cost' => 3,
            'argon_threads' => 1,
            'bcrypt_fallback_cost' => 12,
        ],
        'session' => [
            'ttl_seconds' => 7200,
            'cookie_name' => '__Host-forwext_session',
        ],
        'remember' => [
            'ttl_seconds' => 2592000,
        ],
        'login_rate_limit' => [
            'identity_attempts' => 10,
            'network_attempts' => 50,
            'window_seconds' => 900,
        ],
        'password_reset' => [
            'ttl_seconds' => 3600,
        ],
        'password_confirmation' => [
            'ttl_seconds' => 900,
        ],
        'fingerprint_secret_name' => 'authentication.fingerprint_key',
    ],
    'oauth' => [
        'transaction_ttl_seconds' => 600,
        'http_timeout_seconds' => 10,
        'maximum_response_bytes' => 1048576,
        'providers' => [
            'google' => [
                'enabled' => false,
                'client_id' => null,
                'client_secret_name' => 'oauth.google.client_secret',
                'redirect_uris' => [],
            ],
            'discord' => [
                'enabled' => false,
                'client_id' => null,
                'client_secret_name' => 'oauth.discord.client_secret',
                'redirect_uris' => [],
            ],
        ],
    ],
    'profile_music' => [
        'upload_max_bytes' => 20971520,
        'default_volume' => 70,
        'external_allowed_hosts' => [],
        'permissions' => [
            'use' => true,
            'upload' => true,
            'external' => false,
            'autoplay' => true,
            'moderate' => false,
        ],
    ],
    'profile_url' => [
        'minimum_change_interval_seconds' => 86400,
        'change_window_seconds' => 2592000,
        'maximum_changes_per_window' => 3,
        'reserved_names' => [
            'admin',
            'administrator',
            'api',
            'assets',
            'auth',
            'login',
            'logout',
            'register',
            'account',
            'members',
            'staff',
            'moderator',
            'moderation',
            'support',
            'help',
            'official',
            'system',
            'security',
            'forwext',
            'warext',
            'warext-studios',
        ],
        'permissions' => [
            'use' => true,
        ],
    ],
    'mfa' => [
        'challenge_ttl_seconds' => 300,
        'trusted_device_ttl_seconds' => 2592000,
        'recovery_code_count' => 10,
        'totp' => [
            'issuer' => 'Forwext',
            'period_seconds' => 30,
            'window' => 1,
        ],
        'webauthn' => [
            'rp_name' => 'Forwext',
            'rp_id' => null,
            'host' => null,
            'ceremony_ttl_seconds' => 300,
            'user_verification' => 'required',
            'attestation' => 'none',
        ],
    ],
    'marketplace' => [
        'external_sale' => [
            'allowed_hosts' => [],
            'allow_subdomains' => false,
            'utm_source' => 'forwext',
            'utm_medium' => 'marketplace',
        ],
    ],
    'http_security' => [
        'trusted_hosts' => [],
        'cors' => [
            'allowed_origins' => [],
            'allow_credentials' => false,
            'max_age' => 600,
        ],
        'csrf' => [
            'cookie_name' => '__Host-forwext_csrf',
            'token_ttl_seconds' => 7200,
            'cookie_max_age' => 7200,
        ],
        'rate_limit' => [
            'storage_path' => 'storage/rate-limit',
            'default_limit' => 120,
            'default_window_seconds' => 60,
        ],
        'security_headers' => [
            'hsts_max_age' => 31536000,
        ],
    ],
    'logging' => [
        'path' => 'storage/logs/forwext.jsonl',
    ],
    'health' => [
        'public_details' => false,
    ],
    'security' => [
        'secret_store_path' => 'storage/secrets/forwext.secrets',
        'master_key_environment' => 'FORWEXT_MASTER_KEY',
        'master_key_file' => 'config/secret.key',
    ],
];