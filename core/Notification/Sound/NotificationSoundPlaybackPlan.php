<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Sound;

final readonly class NotificationSoundPlaybackPlan
{
    private function __construct(
        public bool $enabled,
        public ?string $soundKey,
        public float $volume,
        public string $reason,
    ) {
    }

    public static function play(string $soundKey, int $volume): self
    {
        return new self(true, $soundKey, max(0.0, min(1.0, $volume / 100)), 'enabled');
    }

    public static function skip(string $reason): self
    {
        return new self(false, null, 0.0, $reason);
    }
}
