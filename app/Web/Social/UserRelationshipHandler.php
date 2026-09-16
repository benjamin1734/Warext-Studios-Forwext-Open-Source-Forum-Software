<?php

declare(strict_types=1);

namespace Forwext\App\Web\Social;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use Forwext\Core\Social\Interaction\SocialInteractionException;
use Forwext\Core\Social\Interaction\SocialInteractionService;
use InvalidArgumentException;

final readonly class UserRelationshipHandler implements RequestHandlerInterface
{
    public function __construct(
        private SocialInteractionService $service,
        private ProfileViewerResolver $viewers,
        private bool $ignoreMode,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return $this->json(['error' => 'authentication_required'], 401);
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $rawTarget = is_array($parameters) ? ($parameters['userId'] ?? null) : null;
        if (!is_string($rawTarget)) return $this->json(['error' => 'invalid_user'], 400);

        try {
            $target = UserId::fromStored($rawTarget);
            $enabled = match ($request->method()) {
                HttpMethod::Put => $this->enable($actor, $target),
                HttpMethod::Delete => $this->disable($actor, $target),
                default => throw new InvalidArgumentException('Unsupported relationship method.'),
            };
        } catch (PermissionDeniedException) {
            return $this->json(['error' => 'forbidden'], 403);
        } catch (InvalidArgumentException) {
            return $this->json(['error' => 'invalid_relationship_request'], 400);
        } catch (SocialInteractionException) {
            return $this->json(['error' => 'interaction_rejected'], 409);
        }

        return $this->json([$this->ignoreMode ? 'ignoring' : 'following' => $enabled]);
    }

    private function enable(\Forwext\Core\Domain\Entity\EntityId $actor, \Forwext\Core\Domain\Entity\EntityId $target): bool
    {
        if ($this->ignoreMode) $this->service->ignore($actor, $target);
        else $this->service->follow($actor, $target);
        return true;
    }

    private function disable(\Forwext\Core\Domain\Entity\EntityId $actor, \Forwext\Core\Domain\Entity\EntityId $target): bool
    {
        if ($this->ignoreMode) $this->service->unignore($actor, $target);
        else $this->service->unfollow($actor, $target);
        return false;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)->withHeader('Cache-Control', 'private, no-store');
    }
}
