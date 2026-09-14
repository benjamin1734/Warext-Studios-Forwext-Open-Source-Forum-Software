<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Redis\RedisScriptClient;
use JsonException;

final readonly class RedisQueueDriver implements QueueDriver
{
    public function __construct(
        private RedisScriptClient $redis,
        private string $prefix = 'forwext:queue:',
        private Clock $clock = new SystemClock(),
    ) {
        if ($prefix === '' || str_contains($prefix, "\0")) {
            throw new QueueException('Redis queue prefix is invalid.');
        }
    }

    public function push(
        QueueName $queue,
        string $type,
        string $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
    ): JobId {
        $createdAt = $this->clock->now();
        $job = new QueueJob(
            JobId::generate(),
            $queue,
            $type,
            $payload,
            0,
            $maxAttempts,
            $availableAt ?? $createdAt,
            $createdAt,
        );

        $result = $this->redis->evaluate(
            "redis.call('HSET', KEYS[1], ARGV[1], ARGV[2]); "
            . "redis.call('ZADD', KEYS[2], ARGV[3], ARGV[1]); return 1",
            [$this->jobsKey($queue), $this->readyKey($queue)],
            [$job->id->value(), $this->encodeJob($job), $this->milliseconds($job->availableAt)],
        );

        if ((int) $result !== 1) {
            throw new QueueException('Redis queue enqueue failed.');
        }

        return $job->id;
    }

    public function reserve(QueueName $queue, int $visibilityTimeoutSeconds = 60): ?QueueReservation
    {
        if ($visibilityTimeoutSeconds < 1 || $visibilityTimeoutSeconds > 86400) {
            throw new QueueException('Queue visibility timeout must be between 1 and 86400 seconds.');
        }

        $now = $this->clock->now();
        $token = bin2hex(random_bytes(16));
        $reservedUntil = $now->add(new DateInterval('PT' . $visibilityTimeoutSeconds . 'S'));
        $script = <<<'LUA'
local expired = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', ARGV[1], 'LIMIT', 0, 100)
for _, id in ipairs(expired) do
    local raw = redis.call('HGET', KEYS[1], id)
    if raw then
        local job = cjson.decode(raw)
        if tonumber(job.attempts) >= tonumber(job.max_attempts) then
            job.failure_code = 'reservation_timeout_max_attempts'
            job.failed_at_ms = tonumber(ARGV[1])
            job.reservation_token = cjson.null
            job.reserved_until_ms = cjson.null
            redis.call('RPUSH', KEYS[4], cjson.encode(job))
            redis.call('HDEL', KEYS[1], id)
        else
            job.reservation_token = cjson.null
            job.reserved_until_ms = cjson.null
            job.available_at_ms = tonumber(ARGV[1])
            redis.call('HSET', KEYS[1], id, cjson.encode(job))
            redis.call('ZADD', KEYS[2], ARGV[1], id)
        end
    end
    redis.call('ZREM', KEYS[3], id)
end
local ids = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1], 'LIMIT', 0, 1)
if #ids == 0 then return nil end
local id = ids[1]
local raw = redis.call('HGET', KEYS[1], id)
if not raw then
    redis.call('ZREM', KEYS[2], id)
    return nil
end
local job = cjson.decode(raw)
job.attempts = tonumber(job.attempts) + 1
job.reservation_token = ARGV[2]
job.reserved_until_ms = tonumber(ARGV[3])
redis.call('HSET', KEYS[1], id, cjson.encode(job))
redis.call('ZREM', KEYS[2], id)
redis.call('ZADD', KEYS[3], ARGV[3], id)
return cjson.encode(job)
LUA;

        $raw = $this->redis->evaluate(
            $script,
            [
                $this->jobsKey($queue),
                $this->readyKey($queue),
                $this->reservedKey($queue),
                $this->failedKey($queue),
            ],
            [$this->milliseconds($now), $token, $this->milliseconds($reservedUntil)],
        );

        if ($raw === null || $raw === false) {
            return null;
        }
        if (!is_string($raw)) {
            throw new QueueException('Redis queue reserve returned invalid data.');
        }

        return new QueueReservation($this->decodeJob($raw), $token, $reservedUntil);
    }

    public function acknowledge(QueueReservation $reservation): void
    {
        $script = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local job = cjson.decode(raw)
if job.reservation_token ~= ARGV[2] then return 0 end
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
return 1
LUA;

        $result = $this->redis->evaluate(
            $script,
            [
                $this->jobsKey($reservation->job->queue),
                $this->readyKey($reservation->job->queue),
                $this->reservedKey($reservation->job->queue),
            ],
            [$reservation->job->id->value(), $reservation->token],
        );

        if ((int) $result !== 1) {
            throw new QueueException('Redis queue acknowledgement lost reservation ownership.');
        }
    }

    public function retry(QueueReservation $reservation, int $delaySeconds = 0): void
    {
        if ($delaySeconds < 0 || $delaySeconds > 604800) {
            throw new QueueException('Queue retry delay must be between 0 and 604800 seconds.');
        }
        if ($reservation->job->attempts >= $reservation->job->maxAttempts) {
            $this->fail($reservation, 'max_attempts_exhausted');
            return;
        }

        $availableAt = $this->clock->now()->add(new DateInterval('PT' . $delaySeconds . 'S'));
        $script = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local job = cjson.decode(raw)
if job.reservation_token ~= ARGV[2] then return 0 end
job.reservation_token = cjson.null
job.reserved_until_ms = cjson.null
job.available_at_ms = tonumber(ARGV[3])
redis.call('HSET', KEYS[1], ARGV[1], cjson.encode(job))
redis.call('ZREM', KEYS[3], ARGV[1])
redis.call('ZADD', KEYS[2], ARGV[3], ARGV[1])
return 1
LUA;

        $result = $this->redis->evaluate(
            $script,
            [
                $this->jobsKey($reservation->job->queue),
                $this->readyKey($reservation->job->queue),
                $this->reservedKey($reservation->job->queue),
            ],
            [$reservation->job->id->value(), $reservation->token, $this->milliseconds($availableAt)],
        );

        if ((int) $result !== 1) {
            throw new QueueException('Redis queue retry lost reservation ownership.');
        }
    }

    public function fail(QueueReservation $reservation, string $failureCode): void
    {
        if (preg_match('/^[a-z0-9._-]{1,64}$/D', $failureCode) !== 1) {
            throw new QueueException('Queue failure code is invalid.');
        }

        $script = <<<'LUA'
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local job = cjson.decode(raw)
if job.reservation_token ~= ARGV[2] then return 0 end
job.failure_code = ARGV[3]
job.failed_at_ms = tonumber(ARGV[4])
job.reservation_token = cjson.null
job.reserved_until_ms = cjson.null
redis.call('RPUSH', KEYS[4], cjson.encode(job))
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('ZREM', KEYS[3], ARGV[1])
return 1
LUA;

        $result = $this->redis->evaluate(
            $script,
            [
                $this->jobsKey($reservation->job->queue),
                $this->readyKey($reservation->job->queue),
                $this->reservedKey($reservation->job->queue),
                $this->failedKey($reservation->job->queue),
            ],
            [
                $reservation->job->id->value(),
                $reservation->token,
                $failureCode,
                $this->milliseconds($this->clock->now()),
            ],
        );

        if ((int) $result !== 1) {
            throw new QueueException('Redis queue failure handling lost reservation ownership.');
        }
    }

    private function encodeJob(QueueJob $job): string
    {
        return json_encode([
            'id' => $job->id->value(),
            'queue' => $job->queue->value(),
            'type' => $job->type,
            'payload_b64' => base64_encode($job->payload),
            'attempts' => $job->attempts,
            'max_attempts' => $job->maxAttempts,
            'available_at_ms' => $this->milliseconds($job->availableAt),
            'created_at_ms' => $this->milliseconds($job->createdAt),
            'reservation_token' => null,
            'reserved_until_ms' => null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJob(string $json): QueueJob
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Redis queue job payload is invalid.', previous: $exception);
        }

        if (!is_array($data)) {
            throw new QueueException('Redis queue job payload has an invalid shape.');
        }

        $payload = isset($data['payload_b64']) && is_string($data['payload_b64'])
            ? base64_decode($data['payload_b64'], true)
            : false;
        if (
            $payload === false
            || !is_string($data['id'] ?? null)
            || !is_string($data['queue'] ?? null)
            || !is_string($data['type'] ?? null)
        ) {
            throw new QueueException('Redis queue job payload has invalid fields.');
        }

        return new QueueJob(
            JobId::fromString($data['id']),
            QueueName::fromString($data['queue']),
            $data['type'],
            $payload,
            self::boundedInteger($data['attempts'] ?? null, 'attempts'),
            self::boundedInteger($data['max_attempts'] ?? null, 'max_attempts'),
            $this->fromMilliseconds($data['available_at_ms'] ?? null),
            $this->fromMilliseconds($data['created_at_ms'] ?? null),
        );
    }

    private static function boundedInteger(mixed $value, string $field): int
    {
        if (!is_int($value) && !is_float($value)) {
            throw new QueueException(sprintf('Redis queue field "%s" is invalid.', $field));
        }
        $number = (int) $value;
        if ((float) $number !== (float) $value || $number < 0 || $number > 100) {
            throw new QueueException(sprintf('Redis queue field "%s" is outside the allowed range.', $field));
        }
        return $number;
    }

    private function milliseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U')) * 1000 + (int) floor(((int) $date->format('u')) / 1000);
    }

    private function fromMilliseconds(mixed $value): DateTimeImmutable
    {
        if (!is_int($value) && !is_float($value)) {
            throw new QueueException('Redis queue timestamp is invalid.');
        }
        $milliseconds = (int) $value;
        if ($milliseconds < 0 || (float) $milliseconds !== (float) $value) {
            throw new QueueException('Redis queue timestamp is invalid.');
        }

        $seconds = intdiv($milliseconds, 1000);
        $microseconds = ($milliseconds % 1000) * 1000;
        $date = DateTimeImmutable::createFromFormat(
            '!U.u',
            sprintf('%d.%06d', $seconds, $microseconds),
            new DateTimeZone('UTC'),
        );
        if (!$date instanceof DateTimeImmutable) {
            throw new QueueException('Redis queue timestamp is invalid.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private function keyBase(QueueName $queue): string
    {
        return $this->prefix . '{' . $queue->value() . '}:';
    }

    private function jobsKey(QueueName $queue): string
    {
        return $this->keyBase($queue) . 'jobs';
    }

    private function readyKey(QueueName $queue): string
    {
        return $this->keyBase($queue) . 'ready';
    }

    private function reservedKey(QueueName $queue): string
    {
        return $this->keyBase($queue) . 'reserved';
    }

    private function failedKey(QueueName $queue): string
    {
        return $this->keyBase($queue) . 'failed';
    }
}
