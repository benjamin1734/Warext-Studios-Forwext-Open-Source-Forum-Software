<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Approval\ApprovalQueueAction;
use Forwext\Core\Moderation\Approval\ApprovalQueueSelection;
use Forwext\Core\Moderation\Approval\ApprovalQueueService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class ApprovalQueueHandler
{
    public function __construct(
        private ApprovalQueueService $queue,
        private ModerationRequestGuard $guard,
        private BasePath $basePath,
        private bool $canManage,
    ) {
    }

    public function view(): Response
    {
        return $this->secure(Response::html(
            ApprovalQueueHtml::page($this->queue->snapshot(), $this->basePath, $this->canManage),
        ));
    }

    public function moderate(Request $request): Response
    {
        if (!$this->guard->allows($request)) {
            return $this->secure(Response::text('Forbidden', 403));
        }
        $body = $request->parsedBody();
        $actionValue = $body['action'] ?? null;
        $reasonValue = $body['reason'] ?? null;
        $itemsValue = $body['items'] ?? null;
        if (!is_string($actionValue) || !is_string($reasonValue) || !is_array($itemsValue)) {
            throw new InvalidArgumentException('Approval queue bulk form is invalid.');
        }

        $selections = [];
        foreach ($itemsValue as $item) {
            if (!is_string($item)) {
                throw new InvalidArgumentException('Approval queue bulk selection is invalid.');
            }
            $selections[] = ApprovalQueueSelection::fromToken($item);
        }

        $requestId = $request->attribute(RequestIdMiddleware::ATTRIBUTE);
        $auditRequestId = is_string($requestId)
            ? ModerationRequestId::fromString($requestId)
            : ModerationRequestId::generate();
        $this->queue->moderate(
            ApprovalQueueAction::from($actionValue),
            $selections,
            ModerationReasonCode::fromString($reasonValue),
            $auditRequestId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        return $this->secure(Response::redirect($this->basePath->prepend('/moderation/approval'), 303));
    }

    private function secure(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
