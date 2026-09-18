<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentInspection;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Support\Conversation\SupportConversationRepository;
use Forwext\Core\Support\Conversation\SupportHistoryEventType;
use Forwext\Core\Support\Conversation\SupportHistoryVisibility;
use Forwext\Core\Support\Conversation\SupportTicketHistoryEntry;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Support\Ticket\SupportTicketService;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class SupportTicketSubmissionService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private SupportTicketService $tickets,
        private SupportTicketIntakeRepository $intake,
        private SupportContextRegistry $contexts,
        private SupportSubmissionRateLimiter $rateLimiter,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private PermissionGate $gate,
        private SupportSubmissionPolicy $policy = new SupportSubmissionPolicy(),
        private ?SupportConversationRepository $conversation = null,
    ) {
    }

    /** @return list<SupportFieldDefinition> */
    public function fields(string $categoryKey): array
    {
        $category = $this->activeCategory($categoryKey);
        return $this->intake->activeFields($category->key);
    }

    public function saveFieldDefinition(SupportFieldDefinition $definition): void
    {
        $this->gate->require(PermissionKey::fromString(SupportTicketService::MANAGE_PERMISSION));
        $this->intake->saveFieldDefinition($definition);
    }

    /**
     * @param array<string,mixed> $rawFields
     * @param list<SupportUpload> $uploads
     */
    public function submit(
        string $categoryKey,
        string $subject,
        string $description,
        array $rawFields = [],
        ?SupportContextType $contextType = null,
        ?EntityId $contextId = null,
        array $uploads = [],
        ?DateTimeImmutable $now = null,
    ): SupportTicketSubmissionReceipt {
        $this->gate->require(PermissionKey::fromString(SupportTicketService::CREATE_PERMISSION));
        $category = $this->activeCategory($categoryKey);
        $subject = trim($subject);
        if ($subject === '' || strlen($subject) > 200) {
            throw new InvalidArgumentException('Support ticket subject must contain 1-200 UTF-8 bytes.');
        }
        $description = trim($description);
        if ($description === '' || strlen($description) > $this->policy->descriptionMaxBytes) {
            throw new InvalidArgumentException('Support description is outside the supported length.');
        }
        if (count($uploads) > $this->policy->maxAttachments) {
            throw new InvalidArgumentException('Support attachment count exceeds the configured limit.');
        }
        foreach ($uploads as $upload) {
            if (!$upload instanceof SupportUpload) {
                throw new InvalidArgumentException('Support uploads must be typed.');
            }
        }

        $definitions = $this->intake->activeFields($category->key);
        $definitionMap = [];
        foreach ($definitions as $definition) {
            $definitionMap[$definition->fieldKey] = $definition;
        }
        foreach ($rawFields as $fieldKey => $_value) {
            if (!is_string($fieldKey) || !isset($definitionMap[$fieldKey])) {
                throw new InvalidArgumentException('Submitted support field is unknown or unavailable.');
            }
        }

        $values = [];
        foreach ($definitions as $definition) {
            $value = $definition->validate($rawFields[$definition->fieldKey] ?? null);
            if ($definition->type !== SupportFieldType::Checkbox && $value->value === '' && !$definition->required) {
                continue;
            }
            $values[$definition->fieldKey] = $value;
        }
        ksort($values, SORT_STRING);

        if (($contextType === null) !== ($contextId === null)) {
            throw new InvalidArgumentException('Support context type and id must be provided together.');
        }
        $context = $contextType === null
            ? null
            : $this->contexts->resolve($contextType, $this->gate->actorId(), $contextId);

        $now = self::utc($now);
        $this->consumeSubmissionLimits(
            $category->key,
            $subject,
            $description,
            $values,
            $context,
            $now,
        );

        /** @var list<array{upload:SupportUpload,inspection:AttachmentInspection}> $prepared */
        $prepared = [];
        foreach ($uploads as $upload) {
            $prepared[] = [
                'upload'=>$upload,
                'inspection'=>$this->inspector->inspect($upload->contents),
            ];
        }

        /** @var list<StoragePath> $written */
        $written = [];
        try {
            return $this->database->transaction(function () use (
                $category,
                $subject,
                $description,
                $values,
                $context,
                $prepared,
                $now,
                &$written,
            ): SupportTicketSubmissionReceipt {
                $ticket = $this->tickets->create($category->key, $subject, $now);
                $this->intake->saveIntake($ticket->ticketId, $description);
                if ($this->conversation !== null) {
                    $this->conversation->appendHistory(new SupportTicketHistoryEntry(
                        SupportTicketHistoryEntry::generateId(),
                        $ticket->ticketId,
                        $this->gate->actorId(),
                        SupportHistoryEventType::Created,
                        SupportHistoryVisibility::Public,
                        ['status'=>$ticket->status->value],
                        $now,
                    ));
                }
                $this->intake->saveFieldValues($ticket->ticketId, $values);
                if ($context !== null) {
                    $this->intake->saveContext($ticket->ticketId, $context);
                }

                $attachments = [];
                foreach ($prepared as $item) {
                    $attachmentId = SupportAttachmentRecord::generateId();
                    $inspection = $item['inspection'];
                    $path = StoragePath::fromString(sprintf(
                        'support/tickets/%s/%s/%s.%s',
                        $ticket->ticketId->value(),
                        $attachmentId->value(),
                        $inspection->sha256,
                        $inspection->extension,
                    ));
                    $this->storage->put(
                        $path,
                        $inspection->contents,
                        StorageVisibility::Private,
                        $inspection->mediaType,
                    );
                    $written[] = $path;

                    $record = new SupportAttachmentRecord(
                        $attachmentId,
                        $ticket->ticketId,
                        $this->gate->actorId(),
                        $item['upload']->filename,
                        $inspection->mediaType,
                        $inspection->extension,
                        $inspection->sizeBytes,
                        $inspection->sha256,
                        $path->value(),
                        $inspection->imageWidth,
                        $inspection->imageHeight,
                        $inspection->metadataStripped,
                        $now,
                    );
                    $this->intake->saveAttachment($record);
                    $attachments[] = $record;
                }

                return new SupportTicketSubmissionReceipt($ticket, $values, $context, $attachments);
            });
        } catch (Throwable $exception) {
            foreach (array_reverse($written) as $path) {
                try {
                    $this->storage->delete($path, StorageVisibility::Private);
                } catch (Throwable) {
                }
            }
            throw $exception;
        }
    }

    private function activeCategory(string $categoryKey): SupportCategory
    {
        $categoryKey = strtolower(trim($categoryKey));
        SupportCategory::assertKey($categoryKey);
        foreach ($this->tickets->categories() as $category) {
            if ($category->key === $categoryKey && $category->active) {
                return $category;
            }
        }
        throw new InvalidArgumentException('Support category is unavailable.');
    }

    /**
     * @param array<string,SupportFieldValue> $values
     */
    private function consumeSubmissionLimits(
        string $categoryKey,
        string $subject,
        string $description,
        array $values,
        ?SupportContextLink $context,
        DateTimeImmutable $now,
    ): void {
        $userFingerprint = hash('sha256', 'support-user:' . $this->gate->actorId()->value());
        $this->requireLimit('user_hour', $userFingerprint, $this->policy->hourlyLimit, 3600, $now);
        $this->requireLimit('user_day', $userFingerprint, $this->policy->dailyLimit, 86400, $now);

        $fieldSnapshot = [];
        foreach ($values as $key => $value) {
            $fieldSnapshot[$key] = [$value->type->value, $value->value];
        }
        try {
            $payload = json_encode([
                'category'=>$categoryKey,
                'subject'=>self::normalizeText($subject),
                'description'=>self::normalizeText($description),
                'fields'=>$fieldSnapshot,
                'context_type'=>$context?->type->value,
                'context_id'=>$context?->targetId->value(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Support duplicate fingerprint payload is invalid.', previous: $exception);
        }

        $duplicateFingerprint = hash('sha256', $payload);
        $this->requireLimit(
            'duplicate',
            $duplicateFingerprint,
            $this->policy->duplicateLimit,
            $this->policy->duplicateWindowSeconds,
            $now,
        );
    }

    private function requireLimit(
        string $scope,
        string $fingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): void {
        if (!$this->rateLimiter->consume($scope, $fingerprint, $limit, $windowSeconds, $now)) {
            throw new SupportSubmissionRateLimitException('Support submission rate limit exceeded.');
        }
    }

    private static function normalizeText(string $value): string
    {
        return strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
    }

    private static function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
