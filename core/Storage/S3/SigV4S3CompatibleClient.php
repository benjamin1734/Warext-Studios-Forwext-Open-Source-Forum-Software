<?php

declare(strict_types=1);

namespace Forwext\Core\Storage\S3;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageException;
use Forwext\Core\Storage\StorageVisibility;
use SensitiveParameter;

final readonly class SigV4S3CompatibleClient implements S3CompatibleClient
{
    private const SERVICE = 's3';
    private const MAX_RESPONSE_BYTES = 134_217_728;

    /** @var array{scheme:string,host:string,port:?int,path:string} */
    private array $endpoint;

    public function __construct(
        string $endpoint,
        private string $region,
        #[SensitiveParameter] private string $accessKey,
        #[SensitiveParameter] private string $secretKey,
        private ?string $publicBaseUrl = null,
        private int $timeoutMs = 15000,
    ) {
        $this->endpoint = self::parseEndpoint($endpoint);
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $region) !== 1) {
            throw new StorageException('S3 region is invalid.');
        }
        foreach ([$accessKey, $secretKey] as $credential) {
            if ($credential === '' || strlen($credential) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $credential) === 1) {
                throw new StorageException('S3 credential is invalid.');
            }
        }
        if ($publicBaseUrl !== null) {
            self::assertPublicBaseUrl($publicBaseUrl);
        }
        if ($timeoutMs < 500 || $timeoutMs > 120000) {
            throw new StorageException('S3 timeout must be between 500 and 120000 milliseconds.');
        }
        if (!function_exists('curl_init')) {
            throw new StorageException('The optional cURL extension is required for S3-compatible storage.');
        }
    }

    public function putObject(
        string $bucket,
        string $key,
        ReadableStream $stream,
        StorageVisibility $visibility,
        ?string $contentType = null,
    ): S3ObjectMetadata {
        self::assertBucket($bucket);
        self::assertKey($key);
        $body = $stream->contents();
        $sha256 = hash('sha256', $body);
        $headers = [
            'x-amz-meta-forwext-sha256' => $sha256,
            'x-amz-meta-forwext-visibility' => $visibility->value,
        ];
        if ($contentType !== null) {
            $headers['content-type'] = $contentType;
        }

        $this->request('PUT', $bucket, $key, $body, $headers, [200, 201, 204]);

        return new S3ObjectMetadata(strlen($body), $sha256, $visibility, $contentType);
    }

    public function getObjectStream(string $bucket, string $key): ReadableStream
    {
        self::assertBucket($bucket);
        self::assertKey($key);
        $response = $this->request('GET', $bucket, $key, null, [], [200]);

        return ReadableStream::fromString($response['body']);
    }

    public function headObject(string $bucket, string $key): ?S3ObjectMetadata
    {
        self::assertBucket($bucket);
        self::assertKey($key);
        $response = $this->request('HEAD', $bucket, $key, null, [], [200, 404]);
        if ($response['status'] === 404) {
            return null;
        }

        $size = $response['headers']['content-length'] ?? null;
        $sha256 = $response['headers']['x-amz-meta-forwext-sha256'] ?? null;
        $visibility = $response['headers']['x-amz-meta-forwext-visibility'] ?? null;
        $contentType = $response['headers']['content-type'] ?? null;

        if (
            !is_string($size)
            || preg_match('/^[0-9]+$/D', $size) !== 1
            || !is_string($sha256)
            || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
            || !is_string($visibility)
        ) {
            throw new StorageException('S3 object metadata is incomplete or invalid.');
        }

        try {
            $visibilityValue = StorageVisibility::from($visibility);
        } catch (\ValueError $exception) {
            throw new StorageException('S3 object visibility metadata is invalid.', previous: $exception);
        }

        return new S3ObjectMetadata(
            (int) $size,
            $sha256,
            $visibilityValue,
            is_string($contentType) && $contentType !== '' ? $contentType : null,
        );
    }

    public function deleteObject(string $bucket, string $key): bool
    {
        self::assertBucket($bucket);
        self::assertKey($key);
        $response = $this->request('DELETE', $bucket, $key, null, [], [200, 202, 204, 404]);

        return $response['status'] !== 404;
    }

    public function publicObjectUrl(string $bucket, string $key): string
    {
        self::assertBucket($bucket);
        self::assertKey($key);
        if ($this->publicBaseUrl !== null) {
            return rtrim($this->publicBaseUrl, '/') . '/' . self::encodedPath($bucket, $key);
        }

        return $this->baseUrl() . '/' . self::encodedPath($bucket, $key);
    }

    public function temporaryPrivateUrl(string $bucket, string $key, int $ttlSeconds): string
    {
        self::assertBucket($bucket);
        self::assertKey($key);
        if ($ttlSeconds < 1 || $ttlSeconds > 86400) {
            throw new StorageException('S3 presigned URL TTL must be between 1 and 86400 seconds.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $scope = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
        $canonicalUri = $this->canonicalUri($bucket, $key);
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->accessKey . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $ttlSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = self::canonicalQuery($query);
        $canonicalRequest = "GET\n{$canonicalUri}\n{$canonicalQuery}\nhost:" . $this->hostHeader()
            . "\n\nhost\nUNSIGNED-PAYLOAD";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $query['X-Amz-Signature'] = hash_hmac('sha256', $stringToSign, $this->signingKey($date));

        return $this->baseUrl() . $canonicalUri . '?' . self::canonicalQuery($query);
    }

    /**
     * @param array<string,string> $headers
     * @param list<int> $acceptedStatuses
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    private function request(
        string $method,
        string $bucket,
        string $key,
        ?string $body,
        array $headers,
        array $acceptedStatuses,
    ): array {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $payloadHash = hash('sha256', $body ?? '');
        $headers['host'] = $this->hostHeader();
        $headers['x-amz-content-sha256'] = $payloadHash;
        $headers['x-amz-date'] = $amzDate;
        $normalized = self::normalizedHeaders($headers);
        $canonicalUri = $this->canonicalUri($bucket, $key);
        $canonicalRequest = $method . "\n" . $canonicalUri . "\n\n"
            . $normalized['canonical'] . "\n" . $normalized['signed'] . "\n" . $payloadHash;
        $scope = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);
        $signature = hash_hmac('sha256', $stringToSign, $this->signingKey($date));
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope
            . ', SignedHeaders=' . $normalized['signed'] . ', Signature=' . $signature;

        $curlHeaders = [];
        foreach ($normalized['values'] as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }
        $curlHeaders[] = 'Authorization: ' . $authorization;

        $handle = curl_init($this->baseUrl() . $canonicalUri);
        if ($handle === false) {
            throw new StorageException('Unable to initialize S3 request.');
        }

        $responseBody = '';
        $responseBytes = 0;
        $responseHeaders = [];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);
                if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                    return $length;
                }
                $separator = strpos($trimmed, ':');
                if ($separator !== false) {
                    $name = strtolower(trim(substr($trimmed, 0, $separator)));
                    $value = trim(substr($trimmed, $separator + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$responseBody, &$responseBytes): int {
                $responseBytes += strlen($chunk);
                if ($responseBytes > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false || $responseBytes > self::MAX_RESPONSE_BYTES) {
            throw new StorageException($responseBytes > self::MAX_RESPONSE_BYTES
                ? 'S3 response exceeded the configured safety limit.'
                : 'S3 transport request failed: ' . ($error !== '' ? $error : 'transport_error'));
        }
        if (!in_array($status, $acceptedStatuses, true)) {
            throw new StorageException(sprintf('S3 request failed with HTTP %d.', $status));
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    /** @return array{canonical:string,signed:string,values:array<string,string>} */
    private static function normalizedHeaders(array $headers): array
    {
        $values = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(trim((string) $name));
            if (preg_match('/^[a-z0-9-]+$/D', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new StorageException('S3 signing header is invalid.');
            }
            $values[$name] = preg_replace('/[ \t]+/', ' ', trim($value)) ?? trim($value);
        }
        ksort($values, SORT_STRING);
        $canonical = '';
        foreach ($values as $name => $value) {
            $canonical .= $name . ':' . $value . "\n";
        }

        return [
            'canonical' => rtrim($canonical, "\n"),
            'signed' => implode(';', array_keys($values)),
            'values' => $values,
        ];
    }

    private function signingKey(string $date): string
    {
        $dateKey = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', self::SERVICE, $regionKey, true);

        return hash_hmac('sha256', 'aws4_request', $serviceKey, true);
    }

    private function canonicalUri(string $bucket, string $key): string
    {
        $base = rtrim($this->endpoint['path'], '/');

        return ($base === '' ? '' : $base) . '/' . self::encodedPath($bucket, $key);
    }

    private function baseUrl(): string
    {
        return $this->endpoint['scheme'] . '://' . $this->hostHeader();
    }

    private function hostHeader(): string
    {
        $port = $this->endpoint['port'];
        $default = ($this->endpoint['scheme'] === 'https' && ($port === null || $port === 443))
            || ($this->endpoint['scheme'] === 'http' && ($port === null || $port === 80));

        return $this->endpoint['host'] . ($default || $port === null ? '' : ':' . $port);
    }

    private static function encodedPath(string $bucket, string $key): string
    {
        $segments = [$bucket, ...explode('/', $key)];

        return implode('/', array_map(static fn (string $part): string => rawurlencode($part), $segments));
    }

    /** @param array<string,string> $query */
    private static function canonicalQuery(array $query): string
    {
        ksort($query, SORT_STRING);
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    /** @return array{scheme:string,host:string,port:?int,path:string} */
    private static function parseEndpoint(string $endpoint): array
    {
        $parts = parse_url($endpoint);
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new StorageException('S3 endpoint is invalid.');
        }
        $path = isset($parts['path']) && is_string($parts['path']) ? rtrim($parts['path'], '/') : '';
        if (preg_match('~(?:^|/)\.\.?(/|$)~', $path) === 1 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new StorageException('S3 endpoint path is invalid.');
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw new StorageException('S3 endpoint port is invalid.');
        }

        return ['scheme' => $parts['scheme'], 'host' => strtolower($parts['host']), 'port' => $port, 'path' => $path];
    }

    private static function assertPublicBaseUrl(string $url): void
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new StorageException('S3 public base URL is invalid.');
        }
    }

    private static function assertBucket(string $bucket): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) !== 1) {
            throw new StorageException('S3 bucket name is invalid.');
        }
    }

    private static function assertKey(string $key): void
    {
        if (
            $key === ''
            || strlen($key) > 2048
            || str_starts_with($key, '/')
            || str_contains($key, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $key) === 1
            || in_array('..', explode('/', $key), true)
        ) {
            throw new StorageException('S3 object key is invalid.');
        }
    }
}
