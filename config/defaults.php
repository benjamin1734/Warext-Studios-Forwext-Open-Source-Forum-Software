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
    'security' => [
        'secret_store_path' => 'storage/secrets/forwext.secrets',
        'master_key_environment' => 'FORWEXT_MASTER_KEY',
        'master_key_file' => 'config/secret.key',
    ],
];
