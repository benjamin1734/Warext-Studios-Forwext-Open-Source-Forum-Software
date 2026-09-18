<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Report\ReportService;
use Forwext\Core\Moderation\Report\ReportStatus;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ReportModerationHandler
{
    public function __construct(
        private ReportService $reports,
        private ModerationRequestGuard $guard,
        private BasePath $basePath,
        private bool $canManage,
    ) {
    }

    public function view(string $groupId): Response
    {
        $id = EntityId::fromString($groupId);
        return $this->secure(Response::html(ReportModerationHtml::page(
            $this->reports->group($id),
            $this->reports->submissions($id, 200),
            $this->reports->comments($id, 200),
            $this->basePath,
            $this->canManage,
        )));
    }

    public function assign(Request $request, string $groupId): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $value = self::string($body, 'assignee_user_id', '');
        $assignee = trim($value) === '' ? null : EntityId::fromString(trim($value));
        $this->reports->assign(EntityId::fromString($groupId), $assignee);
        return $this->redirect($groupId);
    }

    public function status(Request $request, string $groupId): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $status = ReportStatus::from(self::string($body, 'status'));
        $this->reports->setStatus(EntityId::fromString($groupId), $status);
        return $this->redirect($groupId);
    }

    public function comment(Request $request, string $groupId): Response
    {
        $this->requireMutation($request);
        $body = $request->parsedBody();
        $this->reports->addModeratorComment(EntityId::fromString($groupId), self::string($body, 'body'));
        return $this->redirect($groupId);
    }

    private function requireMutation(Request $request): void
    {
        if (!$this->guard->allows($request)) {
            throw new ReportMutationGuardException('Report moderation mutation failed same-origin validation.');
        }
        if (!$this->canManage) {
            throw new ReportMutationGuardException('Report moderation mutation is not permitted.');
        }
    }

    /** @param array<string, mixed> $body */
    private static function string(array $body, string $key, ?string $default = null): string
    {
        $value = $body[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Report moderation form field is invalid.');
        }
        return $value;
    }

    private function redirect(string $groupId): Response
    {
        return $this->secure(Response::redirect(
            $this->basePath->prepend('/moderation/reports/' . rawurlencode($groupId)),
            303,
        ));
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
