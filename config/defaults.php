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
