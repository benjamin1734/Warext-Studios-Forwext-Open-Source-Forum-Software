<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Task\ModerationTaskPriority;
use Forwext\Core\Moderation\Task\ModerationTaskService;
use Forwext\Core\Moderation\Task\ModerationTaskStatus;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ModerationWorkspaceHandler
{
    public function __construct(
        private ModerationWorkspaceService $workspace,
        private ModerationTaskService $tasks,
        private ModerationRequestGuard $guard,
        private BasePath $basePath,
        private bool $canManage,
        private bool $canViewAudit = false,
    ) {
    }

    public function view(): Response
    {
        return $this->secure(Response::html(
            ModerationWorkspaceHtml::page(
                $this->workspace->snapshot(),
                $this->basePath,
                $this->canManage,
                $this->canViewAudit,
            ),
        ));
    }

    public function createTask(Request $request): Response
    {
        if (!$this->guard->allows($request)) {
            return $this->secure(Response::text('Forbidden', 403));
        }
        $body = $request->parsedBody();
        $title = self::string($body, 'title');
        $description = self::string($body, 'description', '');
        $priority = ModerationTaskPriority::from(self::string($body, 'priority', 'normal'));
        $dueAt = self::dueAt(self::string($body, 'due_at', ''));
        $this->tasks->create($title, $description, $priority, dueAt: $dueAt);

        return $this->secure(Response::redirect($this->basePath->prepend('/moderation'), 303));
    }

    public function updateTaskStatus(Request $request, string $taskId): Response
    {
        if (!$this->guard->allows($request)) {
            return $this->secure(Response::text('Forbidden', 403));
        }
        $body = $request->parsedBody();
        $status = ModerationTaskStatus::from(self::string($body, 'status'));
        $this->tasks->updateStatus(EntityId::fromString($taskId), $status);

        return $this->secure(Response::redirect($this->basePath->prepend('/moderation'), 303));
    }

    /** @param array<string, mixed> $body */
    private static function string(array $body, string $key, ?string $default = null): string
    {
        $value = $body[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Moderation form field is invalid.');
        }
        return $value;
    }

    private static function dueAt(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable || $time->format('Y-m-d\\TH:i') !== $value) {
            throw new InvalidArgumentException('Moderation task due date is invalid.');
        }
        return $time;
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
