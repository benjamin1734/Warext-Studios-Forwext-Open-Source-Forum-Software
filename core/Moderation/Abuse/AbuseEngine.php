<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class AbuseEngine
{
    public function __construct(private AbuseRepository $repository)
    {
    }

    public function evaluate(AbuseContext $context, DateTimeImmutable $at): AbuseDecision
    {
        $action = AbuseAction::Allow;
        $matched = [];

        foreach ($this->repository->rules($context->eventType) as $rule) {
            if (!$rule->active) {
                continue;
            }
            $fingerprint = $context->fingerprint($rule->signal);
            if ($fingerprint === null) {
                continue;
            }
            $hits = $this->repository->consume($rule, $fingerprint, $at);
            if ($hits <= $rule->limit) {
                continue;
            }
            $matched[$rule->key] = true;
            if ($rule->action->severity() > $action->severity()) {
                $action = $rule->action;
            }
        }

        if ($action === AbuseAction::Allow) {
            return AbuseDecision::allow();
        }
        $keys = array_keys($matched);
        sort($keys, SORT_STRING);
        return new AbuseDecision($action, $keys);
    }

    public function record(
        AbuseContext $context,
        AbuseDecision $decision,
        ?string $targetType,
        ?EntityId $targetId,
        DateTimeImmutable $at,
    ): ?AbuseEvent {
        if ($decision->action === AbuseAction::Allow) {
            return null;
        }
        $event = new AbuseEvent(
            EntityId::fromString(bin2hex(random_bytes(16))),
            $context->eventType,
            $decision->action,
            $decision->matchedKeys,
            $context->actorUserId,
            $targetType,
            $targetId,
            $context->identityFingerprint,
            $context->ipFingerprint,
            $context->deviceFingerprint,
            $context->contentFingerprint,
            $at,
        );
        $this->repository->insertEvent($event);
        return $event;
    }
}
