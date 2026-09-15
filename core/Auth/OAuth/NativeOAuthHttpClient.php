<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\OAuth;

use SensitiveParameter;

final readonly class NativeOAuthHttpClient implements OAuthHttpClient
{
    public function __construct(private int $timeoutSeconds = 10, private int $maximumResponseBytes = 1048576)
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30 || $maximumResponseBytes < 1024 || $maximumResponseBytes > 4194304) {
            throw new OAuthException('OAuth HTTP client limits are invalid.');
        }
    }

    public function postForm(string $url, array $form): array
    {
        return $this->request($url, 'POST', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query($form, '', '&', PHP_QUERY_RFC3986));
    }

    public function getBearerJson(string $url, #[SensitiveParameter] string $accessToken): array
    {
        if ($accessToken === '' || strlen($accessToken) > 8192) {
            throw new OAuthException('OAuth access token is invalid.');
        }
        return $this->request($url, 'GET', ['Accept: application/json', 'Authorization: Bearer ' . $accessToken], null);
    }

    /** @param list<string> $headers @return array<string, mixed> */
    private function request(string $url, string $method, array $headers, ?string $body): array
    {
        self::assertEndpoint($url);
        if (function_exists('curl_init')) {
            $payload = $this->curlRequest($url, $method, $headers, $body);
        } else {
            $payload = $this->streamRequest($url, $method, $headers, $body);
        }
        if (strlen($payload) > $this->maximumResponseBytes) {
            throw new OAuthException('OAuth provider response exceeded the configured size limit.');
        }
        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new OAuthException('OAuth provider returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new OAuthException('OAuth provider returned an invalid response object.');
        }
        return $decoded;
    }

    /** @param list<string> $headers */
    private function curlRequest(string $url, string $method, array $headers, ?string $body): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new OAuthException('Unable to initialize OAuth HTTP request.');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($response) || $status < 200 || $status >= 300) {
                throw new OAuthException(sprintf('OAuth provider HTTP request failed with status %d.', $status));
            }
            return $response;
        } finally {
            curl_close($handle);
        }
    }

    /** @param list<string> $headers */
    private function streamRequest(string $url, string $method, array $headers, ?string $body): string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            throw new OAuthException('OAuth requires cURL or allow_url_fopen for outbound HTTPS requests.');
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ]]);
        $response = @file_get_contents($url, false, $context, 0, $this->maximumResponseBytes + 1);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1) {
            $status = (int) $matches[1];
        }
        if (!is_string($response) || $status < 200 || $status >= 300) {
            throw new OAuthException(sprintf('OAuth provider HTTP request failed with status %d.', $status));
        }
        return $response;
    }

    private static function assertEndpoint(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])) {
            throw new OAuthException('OAuth provider endpoint must use HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new OAuthException('OAuth provider endpoint is malformed.');
        }
    }
}
