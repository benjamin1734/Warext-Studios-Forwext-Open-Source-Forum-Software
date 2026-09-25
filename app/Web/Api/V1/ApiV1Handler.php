<?php

declare(strict_types=1);

namespace Forwext\App\Web\Api\V1;

use Forwext\Core\Api\V1\ApiV1EndpointDefinition;
use Forwext\Core\Api\V1\ApiV1Operation;
use Forwext\Core\Api\V1\ApiV1Page;
use Forwext\Core\Api\V1\PrivateApiV1ReadRepository;
use Forwext\Core\Api\V1\PublicApiV1Service;
use Forwext\Core\Api\V1\Security\ApiV1Principal;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class ApiV1Handler implements RequestHandlerInterface
{
    public function __construct(
        private PublicApiV1Service $service,
        private PrivateApiV1ReadRepository $privateReads,
        private ApiV1EndpointDefinition $endpoint,
    ) {
    }

    public function handle(Request $request): Response
    {
        $principal = $request->attribute(ApiV1SecurityMiddleware::ATTRIBUTE_PRINCIPAL);
        if (!$this->endpoint->public && !$principal instanceof ApiV1Principal) {
            return ApiV1ErrorResponder::error(
                'authentication_required',
                'This API resource requires an authenticated API context.',
                401,
            );
        }

        try {
            $payload = match ($this->endpoint->operation) {
                ApiV1Operation::ServiceDocument => $this->service->serviceDocument(),
                ApiV1Operation::UserShow => $this->service->user($this->parameter($request, 'userId')),
                ApiV1Operation::ForumIndex => $this->service->forums(...$this->pagination($request)),
                ApiV1Operation::ForumShow => $this->service->forum($this->parameter($request, 'forumId')),
                ApiV1Operation::ForumThreads => $this->service->threads($this->parameter($request, 'forumId'), ...$this->pagination($request)),
                ApiV1Operation::ThreadShow => $this->service->thread($this->parameter($request, 'threadId')),
                ApiV1Operation::ThreadPosts => $this->service->posts($this->parameter($request, 'threadId'), ...$this->pagination($request)),
                ApiV1Operation::PostShow => $this->service->post($this->parameter($request, 'postId')),
                ApiV1Operation::ModuleIndex => $this->service->modules(...$this->pagination($request)),
                ApiV1Operation::MarketplaceIndex => $this->service->marketplace(...$this->pagination($request)),
                ApiV1Operation::MarketplaceShow => $this->service->marketplaceListing($this->parameter($request, 'listingId')),
                ApiV1Operation::SupportCategoryIndex => $this->service->supportCategories(...$this->pagination($request)),
                ApiV1Operation::ConversationIndex => $this->privateReads->conversations($principal->userId, ...$this->pagination($request)),
                ApiV1Operation::NotificationIndex => $this->privateReads->notifications($principal->userId, ...$this->pagination($request)),
                ApiV1Operation::SupportTicketIndex => $this->privateReads->supportTickets($principal->userId, ...$this->pagination($request)),
            };
        } catch (InvalidArgumentException $exception) {
            return ApiV1ErrorResponder::error('invalid_request', $exception->getMessage(), 400);
        }

        if ($payload === null) {
            return ApiV1ErrorResponder::error('not_found', 'Resource not found.', 404);
        }
        if ($payload instanceof ApiV1Page) {
            $payload = $payload->toArray();
        }

        $cache = $this->endpoint->public ? 'public, max-age=30' : 'private, no-store';

        return Response::json(['data'=>$payload], 200)
            ->withHeader('Cache-Control', $cache)
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function parameter(Request $request, string $key): string
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters[$key] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Route entity id is invalid.');
        }

        return $value;
    }

    /** @return array{int,int} */
    private function pagination(Request $request): array
    {
        $query = $request->query();

        return [
            $this->positiveInt($query['page'] ?? 1, 'page', 100000),
            $this->positiveInt($query['per_page'] ?? 20, 'per_page', 100),
        ];
    }

    private function positiveInt(mixed $value, string $name, int $maximum): int
    {
        if (is_int($value)) {
            $parsed = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $parsed = (int) $value;
        } else {
            throw new InvalidArgumentException('Query parameter ' . $name . ' must be a positive integer.');
        }
        if ($parsed < 1 || $parsed > $maximum) {
            throw new InvalidArgumentException('Query parameter ' . $name . ' is outside supported bounds.');
        }

        return $parsed;
    }
}
