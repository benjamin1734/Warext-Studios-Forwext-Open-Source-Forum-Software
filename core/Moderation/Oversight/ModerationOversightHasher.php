<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Oversight;

use Forwext\Core\Audit\AuditRedactor;
use Forwext\Core\Audit\SensitiveAuditRedactor;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use JsonException;
use RuntimeException;

final readonly class ModerationOversightHasher
{
    public function __construct(private AuditRedactor $redactor = new SensitiveAuditRedactor())
    {
    }

    public function payloadJson(ModerationAuditEvent $event): string
    {
        $payload = [
            'action' => $event->action->value,
            'actor_user_id' => $event->actorUserId->value(),
            'after' => $this->redactor->redact($event->after),
            'before' => $this->redactor->redact($event->before),
            'forum_node_id' => $event->forumNodeId?->value(),
            'occurred_at_utc' => $event->occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            'reason_code' => $event->reasonCode->value(),
            'request_id' => $event->requestId->value(),
            'source_audit_id' => $event->auditId->value(),
            'target_id' => $event->targetId,
            'target_type' => $event->targetType,
        ];
        $payload = self::canonicalize($payload);

        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Oversight payload cannot be encoded.', previous: $exception);
        }
    }

    public function payloadHash(string $payloadJson): string
    {
        return hash('sha256', $payloadJson);
    }

    public function chainHash(int $sequence, string $previousHash, string $payloadHash): string
    {
        if ($sequence < 1
            || preg_match('/^[a-f0-9]{64}$/D', $previousHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payloadHash) !== 1
        ) {
            throw new RuntimeException('Oversight hash input is invalid.');
        }

        return hash('sha256', $sequence . ':' . $previousHash . ':' . $payloadHash);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
