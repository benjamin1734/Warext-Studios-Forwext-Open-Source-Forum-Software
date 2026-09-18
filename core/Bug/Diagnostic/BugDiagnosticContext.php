<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Diagnostic;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class BugDiagnosticContext
{
    public DateTimeImmutable $capturedAt;

    public function __construct(
        public EntityId $reportId,
        public ?EntityId $actorUserId,
        public string $urlPath,
        public ?string $routeName,
        public ?EntityId $forumId,
        public ?EntityId $threadId,
        public ?EntityId $postId,
        public string $themeKey,
        public ?string $moduleKey,
        public BugBrowserDeviceSummary $client,
        public ?string $requestId,
        DateTimeImmutable $capturedAt,
    ) {
        if ($this->actorUserId !== null) {
            UserId::assert($this->actorUserId);
        }
        if ($this->urlPath === '' || !str_starts_with($this->urlPath, '/') || strlen($this->urlPath) > 2048) {
            throw new InvalidArgumentException('Bug diagnostic URL path is invalid.');
        }
        if ($this->routeName !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $this->routeName) !== 1
        ) {
            throw new InvalidArgumentException('Bug diagnostic route name is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $this->themeKey) !== 1) {
            throw new InvalidArgumentException('Bug diagnostic theme key is invalid.');
        }
        if ($this->moduleKey !== null
            && preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $this->moduleKey) !== 1
        ) {
            throw new InvalidArgumentException('Bug diagnostic module key is invalid.');
        }
        if ($this->requestId !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $this->requestId) !== 1
        ) {
            throw new InvalidArgumentException('Bug diagnostic request id is invalid.');
        }

        $this->capturedAt = $capturedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
