<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfileCommentId;
use Forwext\Core\Profile\Activity\ProfilePostId;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ProfileActivityDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private ProfileActivityService $service,
        private ProfileViewerResolver $viewers,
        private bool $commentMode,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $key = $this->commentMode ? 'commentId' : 'profilePostId';
        $rawId = is_array($params) ? ($params[$key] ?? null) : null;
        if (!is_string($rawId)) return $this->json(['error' => 'invalid_profile_activity_target'], 400);
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($this->commentMode) $this->service->deleteComment($actor, ProfileCommentId::fromStored($rawId), $now);
            else $this->service->deletePost($actor, ProfilePostId::fromStored($rawId), $now);
            return $this->json(['deleted' => true]);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_profile_activity_target'], 400);
        } catch (ProfileActivityException|\Forwext\Core\Domain\Access\Permission\PermissionDeniedException) {
            return $this->json(['error' => 'profile_activity_unavailable'], 409);
        }
    }

    /** @param array<string,mixed> $p */ private function json(array $p, int $s = 200): Response { return Response::json($p, $s)->withHeader('Cache-Control', 'private, no-store'); }
}
