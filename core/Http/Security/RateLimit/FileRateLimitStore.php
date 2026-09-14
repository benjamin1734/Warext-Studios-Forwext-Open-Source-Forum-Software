<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

use JsonException;

final readonly class FileRateLimitStore implements RateLimitStore
{
    public function __construct(private string $directory)
    {
    }

    public function consume(string $key, RateLimitPolicy $policy, int $now): RateLimitResult
    {
        if ($key === '' || strlen($key) > 2048) {
            throw new RateLimitException('Rate-limit identity key is invalid.');
        }

        $hash = hash('sha256', $policy->name . "\0" . $key);
        $directory = rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . substr($hash, 0, 2);
        $this->ensureDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . $hash . '.json';

        if (is_link($path)) {
            throw new RateLimitException('Rate-limit state file may not be a symbolic link.');
        }

        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RateLimitException('Unable to open rate-limit state file.');
        }

        try {
            if (!@chmod($path, 0600)) {
                throw new RateLimitException('Unable to restrict permissions on rate-limit state file.');
            }
            if (!flock($handle, LOCK_EX)) {
                throw new RateLimitException('Unable to lock rate-limit state file.');
            }

            try {
                $bucket = $this->readBucket($handle);
                if ($bucket === null || $now >= $bucket['start'] + $policy->windowSeconds) {
                    $bucket = ['start' => $now, 'count' => 0];
                }

                if ($bucket['count'] >= $policy->limit) {
                    return new RateLimitResult(false, $policy->limit, 0, $bucket['start'] + $policy->windowSeconds);
                }

                ++$bucket['count'];
                $this->writeBucket($handle, $bucket);

                return new RateLimitResult(
                    true,
                    $policy->limit,
                    max(0, $policy->limit - $bucket['count']),
                    $bucket['start'] + $policy->windowSeconds,
                );
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     * @return array{start: int, count: int}|null
     */
    private function readBucket($handle): ?array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RateLimitException('Rate-limit state file is corrupted.', previous: $exception);
        }

        if (
            !is_array($decoded)
            || !isset($decoded['start'], $decoded['count'])
            || !is_int($decoded['start'])
            || !is_int($decoded['count'])
            || $decoded['start'] < 0
            || $decoded['count'] < 0
        ) {
            throw new RateLimitException('Rate-limit state file contains invalid data.');
        }

        return ['start' => $decoded['start'], 'count' => $decoded['count']];
    }

    /**
     * @param resource $handle
     * @param array{start: int, count: int} $bucket
     */
    private function writeBucket($handle, array $bucket): void
    {
        $payload = json_encode($bucket, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
            throw new RateLimitException('Unable to persist rate-limit state.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RateLimitException('Unable to create rate-limit state directory.');
        }
    }
}
