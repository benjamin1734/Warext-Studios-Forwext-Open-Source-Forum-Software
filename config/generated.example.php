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
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'forwext_forum',
        'username' => 'forwext_user',
        'charset' => 'utf8mb4',
        'password_secret' => 'database.password',
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
