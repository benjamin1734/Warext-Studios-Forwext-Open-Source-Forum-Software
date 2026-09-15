<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Music;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileVisibility;

final readonly class ProfileMusicSettings
{
    public function __construct(
        public EntityId $userId,
        public bool $enabled,
        public ?ProfileMusicSourceType $sourceType,
        public ?string $uploadPath,
        public ?string $externalUrl,
        public string $title,
        public ProfileVisibility $visibility,
        public int $volume,
        public bool $muted,
        public bool $autoplay,
        public bool $loop,
        public ProfileMusicModerationStatus $moderationStatus,
        public ?string $moderationReasonCode,
        public ?EntityId $moderatedByUserId,
        public ?DateTimeImmutable $moderatedAt,
        public DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($userId);
        if ($moderatedByUserId !== null) {
            UserId::assert($moderatedByUserId);
        }

        self::assertUtf8Length($title, 191, 'Profile music title');
        if ($volume < 0 || $volume > 100) {
            throw new ProfileException('Profile music volume must be between 0 and 100.');
        }
        $this->assertSource();
        $this->assertModeration();

        if ($updatedAt->getTimezone()->getName() !== 'UTC') {
            throw new ProfileException('Profile music timestamps must use UTC.');
        }
    }

    public static function defaults(EntityId $userId, DateTimeImmutable $now, int $defaultVolume = 70): self
    {
        return new self(
            $userId,
            false,
            null,
            null,
            null,
            '',
            ProfileVisibility::Public,
            $defaultVolume,
            false,
            false,
            true,
            ProfileMusicModerationStatus::Active,
            null,
            null,
            null,
            self::utc($now),
        );
    }

    public static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    private function assertSource(): void
    {
        if ($this->sourceType === null) {
            if ($this->uploadPath !== null || $this->externalUrl !== null) {
                throw new ProfileException('Profile music without a source type cannot retain source data.');
            }
            return;
        }

        if ($this->sourceType === ProfileMusicSourceType::Upload) {
            if ($this->uploadPath === null || $this->externalUrl !== null) {
                throw new ProfileException('Uploaded profile music must contain only a private upload path.');
            }
            $pattern = '/^profiles\/' . preg_quote($this->userId->value(), '/')
                . '\/music\/[a-f0-9]{64}\.(?:mp3|ogg|wav|m4a)$/D';
            if (strlen($this->uploadPath) > 1024 || preg_match($pattern, $this->uploadPath) !== 1) {
                throw new ProfileException('Stored profile music upload path is invalid.');
            }
            return;
        }

        if ($this->externalUrl === null || $this->uploadPath !== null) {
            throw new ProfileException('External profile music must contain only an external URL.');
        }
        if (
            strlen($this->externalUrl) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $this->externalUrl) === 1
            || filter_var($this->externalUrl, FILTER_VALIDATE_URL) === false
        ) {
            throw new ProfileException('Stored external profile music URL is invalid.');
        }
    }

    private function assertModeration(): void
    {
        if (($this->moderatedByUserId === null) !== ($this->moderatedAt === null)) {
            throw new ProfileException('Profile music moderation actor and timestamp must be stored together.');
        }
        if ($this->moderatedAt !== null && $this->moderatedAt->getTimezone()->getName() !== 'UTC') {
            throw new ProfileException('Profile music moderation timestamps must use UTC.');
        }

        if ($this->moderationStatus === ProfileMusicModerationStatus::Blocked) {
            if (
                $this->moderationReasonCode === null
                || $this->moderatedByUserId === null
                || $this->moderatedAt === null
            ) {
                throw new ProfileException('Blocked profile music requires moderation metadata.');
            }
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $this->moderationReasonCode) !== 1) {
                throw new ProfileException('Profile music moderation reason code is invalid.');
            }
            return;
        }

        if ($this->moderationReasonCode !== null) {
            throw new ProfileException('Active profile music may not retain a block reason.');
        }
    }

    private static function assertUtf8Length(string $value, int $maxCharacters, string $label): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new ProfileException($label . ' must contain valid UTF-8.');
        }
        $count = preg_match_all('/./us', $value);
        if ($count === false || $count > $maxCharacters) {
            throw new ProfileException($label . ' exceeds the allowed length.');
        }
    }
}
