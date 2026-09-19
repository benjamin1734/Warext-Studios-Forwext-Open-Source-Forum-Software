<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use JsonException;
use RuntimeException;

final readonly class ContentManagerJobHandler
{
    public function __construct(
        private ContentManagerOperationProcessor $processor,
        private QueueDriver $queue,
    ) {
    }

    public function jobType(): string
    {
        return ContentManagerService::JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Content manager job payload is invalid JSON.', previous:$exception);
        }
        $operationId = is_array($decoded) ? ($decoded['operation_id'] ?? null) : null;
        if (!is_string($operationId) || preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new RuntimeException('Content manager job operation id is invalid.');
        }

        $operation = $this->processor->process(EntityId::fromString($operationId), $now, 50);
        if (!$operation->status->terminal()) {
            $this->queue->push(
                QueueName::fromString('content-manager'),
                $this->jobType(),
                json_encode(['operation_id'=>$operationId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                5,
                $now,
            );
        }
        return $operation->processedCount;
    }
}
