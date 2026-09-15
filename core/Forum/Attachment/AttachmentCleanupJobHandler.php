<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class AttachmentCleanupJobHandler
{
    public function __construct(private AttachmentCleanupService $cleanup)
    {
    }

    public function jobType(): string
    {
        return AttachmentMaintenanceTasks::CLEANUP_JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Attachment cleanup job payload is invalid JSON.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Attachment cleanup job payload must be an object.');
        }

        $limit = $decoded['limit'] ?? 250;
        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            throw new RuntimeException('Attachment cleanup job limit is invalid.');
        }

        return $this->cleanup->cleanupExpired($now, $limit);
    }
}
