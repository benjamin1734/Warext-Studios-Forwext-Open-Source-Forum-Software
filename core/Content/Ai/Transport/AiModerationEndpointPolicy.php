<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Transport;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Forum\Editor\HostAddressResolver;

final readonly class AiModerationEndpointPolicy
{
    public function __construct(private HostAddressResolver $resolver)
    {
    }

    public function approve(string $value): AiModerationEndpoint
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new AiModerationProviderException('AI provider endpoint URL is invalid.');
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
        ) {
            throw new AiModerationProviderException(
                'AI provider endpoint requires a credential-free HTTPS URL without fragments.',
            );
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new AiModerationProviderException('AI provider endpoint host is invalid.');
        }
        if (!str_contains($host, '.')
            || $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.invalid')
        ) {
            throw new AiModerationProviderException('AI provider endpoint host is not public.');
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                throw new AiModerationProviderException('Internationalized AI provider hosts require IDN support.');
            }
            $ascii = idn_to_ascii($host, defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0);
            if (!is_string($ascii) || $ascii === '') {
                throw new AiModerationProviderException('AI provider host normalization failed.');
            }
            $host = strtolower($ascii);
        }
        if (preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host) !== 1) {
            throw new AiModerationProviderException('AI provider endpoint host syntax is invalid.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port !== 443) {
            throw new AiModerationProviderException('AI provider endpoints only permit HTTPS on port 443.');
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new AiModerationProviderException('AI provider endpoint host could not be resolved.');
        }
        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new AiModerationProviderException('AI provider endpoint resolves to a non-public address.');
            }
        }

        $path = isset($parts['path']) && $parts['path'] !== '' ? (string) $parts['path'] : '/';
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
        return new AiModerationEndpoint(
            'https://' . $host . $path . $query,
            $host,
            443,
            $path . $query,
            array_values(array_unique($addresses)),
        );
    }
}
