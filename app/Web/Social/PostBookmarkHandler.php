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
use Forwext\Core\Social\Interaction\SocialInteractionException;
use Forwext\Core\Social\Interaction\SocialInteractionService;
use InvalidArgumentException;

final readonly class PostBookmarkHandler implements RequestHandlerInterface
{
    public function __construct(
        private SocialInteractionService $service,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $rawPostId = is_array($parameters) ? ($parameters['postId'] ?? null) : null;
        if (!is_string($rawPostId)) return $this->json(['error' => 'invalid_post'], 400);

        try {
            $postId = PostId::fromStored($rawPostId);
            if ($request->method() === HttpMethod::Delete) {
                $this->service->removeBookmark($actor, $postId);
            } elseif ($request->method() === HttpMethod::Put) {
                $this->save($actor, $postId, $request);
            } elseif ($request->method() === HttpMethod::Post) {
                $action = $this->body($request)['action'] ?? null;
                if ($action === 'save_bookmark') {
                    $this->save($actor, $postId, $request);
                } elseif ($action === 'remove_bookmark') {
                    $this->service->removeBookmark($actor, $postId);
                } else {
                    throw new InvalidArgumentException('Bookmark action is invalid.');
                }
            } else {
                throw new InvalidArgumentException('Unsupported bookmark method.');
            }
        } catch (PermissionDeniedException) {
            return $this->json(['error' => 'forbidden'], 403);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_bookmark_request'], 400);
        } catch (SocialInteractionException) {
            return $this->json(['error' => 'interaction_rejected'], 409);
        }

        $saved = $request->method() === HttpMethod::Put
            || ($request->method() === HttpMethod::Post && (($this->body($request)['action'] ?? null) === 'save_bookmark'));
        return $this->json(['saved' => $saved]);
    }

    private function save(
        \Forwext\Core\Domain\Entity\EntityId $actor,
        \Forwext\Core\Domain\Entity\EntityId $postId,
        Request $request,
    ): void {
        $note = $this->body($request)['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            throw new InvalidArgumentException('Bookmark note is invalid.');
        }
        $this->service->bookmark($actor, $postId, $note);
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
            throw new InvalidArgumentException('Bookmark request body is invalid.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Bookmark request body is invalid.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
