<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Forum\Post\PostId;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use Forwext\Core\Social\Interaction\ReactionSummary;
use Forwext\Core\Social\Interaction\SocialInteractionException;
use Forwext\Core\Social\Interaction\SocialInteractionService;
use InvalidArgumentException;

final readonly class PostReactionHandler implements RequestHandlerInterface
{
    public function __construct(
        private SocialInteractionService $service,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return $this->json(['error' => 'authentication_required'], 401);
        }
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $rawPostId = is_array($parameters) ? ($parameters['postId'] ?? null) : null;
        if (!is_string($rawPostId)) {
            return $this->json(['error' => 'invalid_post'], 400);
        }

        try {
            $postId = PostId::fromStored($rawPostId);
            $summary = match ($request->method()) {
                HttpMethod::Get => $this->service->reactionSummary($actor, $postId),
                HttpMethod::Put => $this->service->react($actor, $postId, $this->reactionKey($request)),
                HttpMethod::Delete => $this->service->removeReaction($actor, $postId),
                HttpMethod::Post => $this->postAction($actor, $postId, $request),
                default => throw new InvalidArgumentException('Unsupported reaction method.'),
            };
        } catch (PermissionDeniedException) {
            return $this->json(['error' => 'forbidden'], 403);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_reaction_request'], 400);
        } catch (SocialInteractionException) {
            return $this->json(['error' => 'interaction_rejected'], 409);
        }

        return $this->json($this->summary($summary));
    }

    private function postAction(
        \Forwext\Core\Domain\Entity\EntityId $actor,
        \Forwext\Core\Domain\Entity\EntityId $postId,
        Request $request,
    ): ReactionSummary {
        $body = $this->body($request);
        return match ($body['action'] ?? null) {
            'react' => $this->service->react($actor, $postId, $this->reactionKey($request)),
            'remove_reaction' => $this->service->removeReaction($actor, $postId),
            default => throw new InvalidArgumentException('Reaction action is invalid.'),
        };
    }

    private function reactionKey(Request $request): string
    {
        $value = $this->body($request)['reaction_key'] ?? null;
        if (!is_string($value) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Reaction key is invalid.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = $request->parsedBody();
        if ($body !== [] || !$request->isJson()) {
            return $body;
        }

        try {
            $decoded = $request->json();
        } catch (HttpException $exception) {
            throw new InvalidArgumentException('Reaction request body is invalid.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Reaction request body is invalid.');
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function summary(ReactionSummary $summary): array
    {
        return ['total' => $summary->total, 'score' => $summary->score, 'counts' => $summary->counts];
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
