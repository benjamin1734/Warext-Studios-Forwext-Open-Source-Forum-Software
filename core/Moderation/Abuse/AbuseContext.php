<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class AbuseContext
{
    public function __construct(
        public AbuseEventType $eventType,
        public ?EntityId $actorUserId = null,
        public ?string $identityFingerprint = null,
        public ?string $ipFingerprint = null,
        public ?string $deviceFingerprint = null,
        public ?string $contentFingerprint = null,
    ) {
        if ($this->actorUserId !== null) {
            UserId::assert($this->actorUserId);
        }
        foreach ([
            $this->identityFingerprint,
            $this->ipFingerprint,
            $this->deviceFingerprint,
            $this->contentFingerprint,
        ] as $fingerprint) {
            if ($fingerprint !== null && preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new InvalidArgumentException('Abuse context fingerprint is invalid.');
            }
        }
        if ($this->actorUserId === null
            && $this->identityFingerprint === null
            && $this->ipFingerprint === null
            && $this->deviceFingerprint === null
            && $this->contentFingerprint === null
        ) {
            throw new InvalidArgumentException('Abuse context requires at least one abuse signal.');
        }
    }

    public function fingerprint(AbuseSignal $signal): ?string
    {
        return match ($signal) {
            AbuseSignal::User => $this->actorUserId === null
                ? null
                : hash('sha256', 'user:' . $this->actorUserId->value()),
            AbuseSignal::Identity => $this->identityFingerprint,
            AbuseSignal::Ip => $this->ipFingerprint,
            AbuseSignal::Device => $this->deviceFingerprint,
            AbuseSignal::Content => $this->contentFingerprint,
        };
    }
}
