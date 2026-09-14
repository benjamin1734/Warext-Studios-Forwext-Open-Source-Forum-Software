<?php

declare(strict_types=1);

return [
    'app' => [
        'environment' => 'production',
    ],
    'routing' => [
        'canonical_url' => 'https://forum.example.com/community',
        'trusted_proxies' => ['10.0.0.0/8'],
        'cloudflare_proxies' => [],
    ],
    'http_security' => [
        'trusted_hosts' => ['forum.example.com'],
        'cors' => [
            'allowed_origins' => [],
            'allow_credentials' => false,
        ],
    ],
    'health' => [
        'public_details' => false,
    ],
];
