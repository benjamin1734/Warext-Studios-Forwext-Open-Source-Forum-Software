<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Profile\Activity\ProfileActivityException;
use Forwext\Core\Profile\Activity\ProfileActivityService;
use Forwext\Core\Profile\Activity\ProfilePostId;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ProfilePostReactionHandler implements RequestHandlerInterface
{
    public function __construct(private ProfileActivityService $service, private ProfileViewerResolver $viewers) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        $params = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $rawPost = is_array($params) ? ($params['profilePostId'] ?? null) : null;
        if (!is_string($rawPost)) return $this->json(['error' => 'invalid_profile_post'], 400);
        try {
            $postId = ProfilePostId::fromStored($rawPost);
            $summary = match ($request->method()) {
                HttpMethod::Get => $this->service->reactionSummary($actor, $postId),
                HttpMethod::Put => $this->service->react($actor, $postId, $this->reactionKey($request)),
                HttpMethod::Delete => $this->service->removeReaction($actor, $postId),
                default => throw new InvalidArgumentException('Unsupported profile reaction method.'),
            };
            return $this->json(['total' => $summary->total, 'score' => $summary->score, 'counts' => $summary->counts]);
        } catch (PermissionDeniedException) {
            return $this->json(['error' => 'forbidden'], 403);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_profile_reaction_request'], 400);
        } catch (ProfileActivityException) {
            return $this->json(['error' => 'profile_activity_unavailable'], 409);
        }
    }

    private function reactionKey(Request $request): string
    {
        $key = $request->parsedBody()['reaction_key'] ?? null;
        if (!is_string($key) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $key) !== 1) throw new InvalidArgumentException('Reaction key is invalid.');
        return $key;
    }
    /** @param array<string,mixed> $p */ private function json(array $p, int $s = 200): Response { return Response::json($p, $s)->withHeader('Cache-Control', 'private, no-store'); }
}
