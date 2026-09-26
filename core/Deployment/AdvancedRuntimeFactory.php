<?php

declare(strict_types=1);

namespace Forwext\Core\Deployment;

use Forwext\Core\Cache\CacheStore;
use Forwext\Core\Cache\DatabaseCacheStore;
use Forwext\Core\Cache\FileCacheStore;
use Forwext\Core\Cache\RedisCacheStore;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Http\Security\RateLimit\FileRateLimitStore;
use Forwext\Core\Http\Security\RateLimit\RateLimitStore;
use Forwext\Core\Http\Security\RateLimit\RedisRateLimitStore;
use Forwext\Core\Lock\DatabaseLockManager;
use Forwext\Core\Lock\FileLockManager;
use Forwext\Core\Lock\LockManager;
use Forwext\Core\Lock\RedisLockManager;
use Forwext\Core\Queue\DatabaseQueueDriver;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\RedisQueueDriver;
use Forwext\Core\Realtime\DatabaseRealtimeMessageStore;
use Forwext\Core\Realtime\PollingRealtimeTransport;
use Forwext\Core\Realtime\RealtimeMode;
use Forwext\Core\Realtime\RealtimeTransport;
use Forwext\Core\Realtime\RedisWebSocketGateway;
use Forwext\Core\Realtime\WebSocketRealtimeTransport;
use Forwext\Core\Redis\PhpRedisClient;
use Forwext\Core\Redis\RedisScriptClient;
use Forwext\Core\Scheduler\DatabaseSchedulerClaimStore;
use Forwext\Core\Scheduler\RedisSchedulerClaimStore;
use Forwext\Core\Scheduler\SchedulerClaimStore;
use Forwext\Core\Search\External\ExternalSearchDriver;
use Forwext\Core\Search\External\MeilisearchExternalSearchClient;
use Forwext\Core\Search\NativeDatabaseSearchDriver;
use Forwext\Core\Search\ResilientSearchDriver;
use Forwext\Core\Search\SearchDriver;
use Forwext\Core\Security\Secret\SecretStore;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Core\Session\FileSessionStore;
use Forwext\Core\Session\RedisSessionStore;
use Forwext\Core\Session\SessionStore;
use RuntimeException;

final class AdvancedRuntimeFactory
{
    private ?RedisScriptClient $redis = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly DatabaseConnection $database,
        private readonly SecretStore $secrets,
        private readonly string $projectRoot,
    ) {
        if ($projectRoot === '' || str_contains($projectRoot, "\0")) {
            throw new RuntimeException('Project root is invalid.');
        }
    }

    public function sessionStore(): SessionStore
    {
        return match ($this->config->requireString('session.driver')) {
            'file' => new FileSessionStore($this->path($this->config->requireString('session.path'))),
            'database' => new DatabaseSessionStore($this->database),
            'redis' => new RedisSessionStore($this->redis()),
            default => throw new RuntimeException('Unsupported session driver.'),
        };
    }

    public function cacheStore(): CacheStore
    {
        return match ($this->config->requireString('cache.driver')) {
            'file' => new FileCacheStore($this->path($this->config->requireString('cache.path'))),
            'database' => new DatabaseCacheStore($this->database),
            'redis' => new RedisCacheStore($this->redis()),
            default => throw new RuntimeException('Unsupported cache driver.'),
        };
    }

    public function queueDriver(): QueueDriver
    {
        return match ($this->config->requireString('queue.driver')) {
            'database' => new DatabaseQueueDriver($this->database),
            'redis' => new RedisQueueDriver($this->redis()),
            default => throw new RuntimeException('Unsupported queue driver.'),
        };
    }

    public function lockManager(): LockManager
    {
        return match ($this->config->requireString('lock.driver')) {
            'file' => new FileLockManager($this->path($this->config->requireString('lock.path'))),
            'database' => new DatabaseLockManager($this->database),
            'redis' => new RedisLockManager($this->redis()),
            default => throw new RuntimeException('Unsupported lock driver.'),
        };
    }

    public function schedulerClaimStore(): SchedulerClaimStore
    {
        return match ($this->config->requireString('scheduler.claim_driver')) {
            'database' => new DatabaseSchedulerClaimStore($this->database),
            'redis' => new RedisSchedulerClaimStore($this->redis()),
            default => throw new RuntimeException('Unsupported scheduler claim driver.'),
        };
    }

    public function rateLimitStore(): RateLimitStore
    {
        $driver = $this->config->get('http_security.rate_limit.driver', 'file');
        if (!is_string($driver)) {
            throw new RuntimeException('Rate-limit driver configuration is invalid.');
        }

        return match ($driver) {
            'file' => new FileRateLimitStore(
                $this->path($this->config->requireString('http_security.rate_limit.storage_path')),
            ),
            'redis' => new RedisRateLimitStore($this->redis()),
            default => throw new RuntimeException('Unsupported rate-limit driver.'),
        };
    }

    public function searchDriver(): SearchDriver
    {
        $fallback = new NativeDatabaseSearchDriver($this->database);
        $driver = $this->config->requireString('search.driver');

        if ($driver === 'native') {
            return new ResilientSearchDriver($fallback);
        }
        if ($driver !== 'meilisearch') {
            throw new RuntimeException('Unsupported search driver.');
        }

        $endpoint = $this->config->get('search.external.endpoint');
        if (!is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('External search endpoint is required.');
        }

        $index = $this->config->get('search.external.index', 'forwext');
        $timeout = $this->config->get('search.external.timeout_ms', 5000);
        if (!is_string($index) || !is_int($timeout)) {
            throw new RuntimeException('External search configuration is invalid.');
        }

        $secretName = $this->config->requireString('search.external.api_key_secret');
        $apiKey = $this->secrets->get($secretName);

        return new ResilientSearchDriver(
            $fallback,
            new ExternalSearchDriver(new MeilisearchExternalSearchClient(
                $endpoint,
                $index,
                $apiKey,
                $timeout,
            )),
        );
    }

    public function realtimeTransport(): RealtimeTransport
    {
        $store = new DatabaseRealtimeMessageStore($this->database);
        $mode = RealtimeMode::from($this->config->requireString('realtime.mode'));

        if ($mode !== RealtimeMode::WebSocket) {
            return new PollingRealtimeTransport($store);
        }

        $channel = $this->config->get('realtime.redis_broadcast_channel', 'forwext:realtime:broadcast');
        if (!is_string($channel)) {
            throw new RuntimeException('Realtime Redis broadcast channel is invalid.');
        }

        return new WebSocketRealtimeTransport(
            $store,
            new RedisWebSocketGateway($this->redis(), $channel),
        );
    }

    public function redis(): RedisScriptClient
    {
        if ($this->redis instanceof RedisScriptClient) {
            return $this->redis;
        }

        $host = $this->config->get('redis.host');
        $database = $this->config->get('redis.database');
        if (!is_string($host) || $host === '' || ($database !== null && !is_int($database))) {
            throw new RuntimeException('Redis host/database configuration is invalid.');
        }

        $passwordName = $this->config->requireString('redis.password_secret');
        $password = $this->secrets->get($passwordName);
        $timeoutMs = $this->config->requireInt('redis.timeout_ms');

        $this->redis = PhpRedisClient::connect(
            $host,
            $this->config->requireInt('redis.port'),
            $timeoutMs / 1000,
            $password,
            $database,
        );

        return $this->redis;
    }

    private function path(string $relative): string
    {
        if (
            $relative === ''
            || str_contains($relative, "\0")
            || str_starts_with($relative, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1
            || in_array('..', preg_split('~[\\\\/]+~', $relative) ?: [], true)
        ) {
            throw new RuntimeException('Configured runtime path must stay inside the project root.');
        }

        return rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    }
}
