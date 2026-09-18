<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;

final readonly class AiModerationOverrideService
{
    public const PERMISSION = 'ai.moderation.override';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private AiModerationOverrideRepository $overrides,
        private PermissionGate $gate,
        private AuditRecorder $audit,
        private AuditRequestId $requestId,
    ) {
    }

    public function set(
        string $contentType,
        string $text,
        AiModerationAction $action,
        string $reason,
        ?DateTimeImmutable $expiresAt = null,
        ?DateTimeImmutable $now = null,
    ): AiModerationHumanOverride {
        $this->gate->require(PermissionKey::fromString(self::PERMISSION));
        $at = self::utc($now);
        $fingerprint = AiModerationFingerprint::forContent($contentType, $text);
        $override = new AiModerationHumanOverride(
            $fingerprint,
            $action,
            $this->gate->actorId(),
            $reason,
            $at,
            $expiresAt,
        );

        return $this->database->transaction(function () use ($override, $at): AiModerationHumanOverride {
            $previous = $this->overrides->active($override->contentFingerprint, $at);
            $this->overrides->save($override);
            $this->audit->append(new AuditEvent(
                AuditEvent::generateId(),
                AuditScope::Moderation,
                $this->gate->actorId(),
                AuditAction::fromString('content.ai_moderation.override.set'),
                'content.ai_override',
                $override->contentFingerprint,
                null,
                null,
                $this->requestId,
                $previous === null ? [] : [
                    'action'=>$previous->action->value,
                    'reason'=>$previous->reason,
                    'expires_at_utc'=>$previous->expiresAt?->format('Y-m-d H:i:s.u'),
                ],
                [
                    'action'=>$override->action->value,
                    'reason'=>$override->reason,
                    'expires_at_utc'=>$override->expiresAt?->format('Y-m-d H:i:s.u'),
                ],
                $at,
            ));
            return $override;
        });
    }

    public function clear(
        string $contentType,
        string $text,
        ?DateTimeImmutable $now = null,
    ): bool {
        $this->gate->require(PermissionKey::fromString(self::PERMISSION));
        $at = self::utc($now);
        $fingerprint = AiModerationFingerprint::forContent($contentType, $text);

        return $this->database->transaction(function () use ($fingerprint, $at): bool {
            $previous = $this->overrides->active($fingerprint, $at);
            if ($previous === null || !$this->overrides->delete($fingerprint)) {
                return false;
            }
            $this->audit->append(new AuditEvent(
                AuditEvent::generateId(),
                AuditScope::Moderation,
                $this->gate->actorId(),
                AuditAction::fromString('content.ai_moderation.override.clear'),
                'content.ai_override',
                $fingerprint,
                null,
                null,
                $this->requestId,
                [
                    'action'=>$previous->action->value,
                    'reason'=>$previous->reason,
                    'expires_at_utc'=>$previous->expiresAt?->format('Y-m-d H:i:s.u'),
                ],
                [],
                $at,
            ));
            return true;
        });
    }

    private static function utc(?DateTimeImmutable $value): DateTimeImmutable
    {
        return ($value ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
