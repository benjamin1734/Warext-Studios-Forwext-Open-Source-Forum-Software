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
        'message_retention_seconds' => 86400,
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
