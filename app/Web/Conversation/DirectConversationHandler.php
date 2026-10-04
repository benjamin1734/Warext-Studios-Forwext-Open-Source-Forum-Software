<?php

declare(strict_types=1);

namespace Forwext\App\Web\Conversation;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Conversation\DirectConversationRepository;
use Forwext\Core\Conversation\DirectConversationService;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Social\Interaction\SocialInteractionRepository;
use InvalidArgumentException;
use ValueError;

final readonly class DirectConversationHandler implements RequestHandlerInterface
{
    public function __construct(
        private DirectConversationRepository $conversations,
        private UserRepository $users,
        private SocialInteractionRepository $social,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
        private DateTimeZone $timezone,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $service = new DirectConversationService(
            $this->conversations,
            $this->users,
            $this->social,
            new PermissionGate($this->authorizer, $actor),
        );

        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $conversationValue = is_array($parameters) ? ($parameters['conversationId'] ?? null) : null;

        try {
            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($request, $actor, $service, is_string($conversationValue) ? $conversationValue : null);
            }

            if ($conversationValue === null && self::previewRequested($request)) {
                return $this->preview($service);
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            if (is_string($conversationValue)) {
                $conversationId = EntityId::fromString($conversationValue);
                $view = $service->view($conversationId);
                if ($view === null) {
                    return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
                }
                return Response::html(DirectConversationHtml::detail(
                    $view,
                    $actor,
                    $csrf,
                    $this->basePath,
                    $this->timezone,
                ))->withHeader('Cache-Control', 'private, no-store')
                    ->withHeader('X-Robots-Tag', 'noindex,nofollow');
            }

            $page = self::page($request);
            $filter = self::filter($request);
            $rows = $service->inbox(31, ($page - 1) * 30, $filter === 'starred');
            $hasMore = count($rows) > 30;
            if ($hasMore) {
                array_pop($rows);
            }

            return Response::html(DirectConversationHtml::inbox(
                $rows,
                $csrf,
                $this->basePath,
                $this->timezone,
                $page,
                $hasMore,
                $filter,
            ))->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function preview(DirectConversationService $service): Response
    {
        $items = [];
        foreach ($service->inbox(4, 0) as $summary) {
            $items[] = [
                'href' => $this->basePath->prepend(
                    '/account/conversations/' . rawurlencode($summary->conversationId->value()),
                ),
                'username' => $summary->otherUsername,
                'preview' => self::compactText($summary->lastMessageBody),
                'updated_label' => $summary->updatedAt->setTimezone($this->timezone)->format('d.m.Y H:i'),
                'unread_count' => $summary->unreadCount,
            ];
        }

        return Response::json(['items' => $items])
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    private function mutate(
        Request $request,
        EntityId $actor,
        DirectConversationService $service,
        ?string $conversationValue,
    ): Response {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Direct conversation action is missing.');
        }

        if ($conversationValue === null) {
            if ($action !== 'start') {
                throw new InvalidArgumentException('Direct conversation start action is invalid.');
            }
            $username = $body['recipient_username'] ?? null;
            $message = $body['body'] ?? null;
            if (!is_string($username) || !is_string($message)) {
                throw new InvalidArgumentException('Direct conversation fields are invalid.');
            }
            $conversationId = $service->start($username, $message);
            $location = '/account/conversations/' . rawurlencode($conversationId->value());
        } else {
            $conversationId = EntityId::fromString($conversationValue);
            if ($this->conversations->otherParticipant($actor, $conversationId) === null) {
                return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
            }

            if ($action === 'reply') {
                $message = $body['body'] ?? null;
                if (!is_string($message)) {
                    throw new InvalidArgumentException('Direct conversation reply is invalid.');
                }
                $service->reply($conversationId, $message);
                $location = '/account/conversations/' . rawurlencode($conversationId->value());
            } elseif ($action === 'star' || $action === 'unstar') {
                $service->setStarred($conversationId, $action === 'star');
                $location = '/account/conversations/' . rawurlencode($conversationId->value());
            } elseif ($action === 'leave') {
                $service->leave($conversationId);
                $location = '/account/conversations';
            } else {
                throw new InvalidArgumentException('Direct conversation action is invalid.');
            }
        }

        return Response::text('', 303)
            ->withHeader('Location', $this->basePath->prepend($location))
            ->withHeader('Cache-Control', 'no-store');
    }

    private static function previewRequested(Request $request): bool
    {
        return ($request->query()['preview'] ?? null) === '1';
    }

    private static function compactText(string $value): string
    {
        $value = trim((string) preg_replace('/\\s+/u', ' ', $value));
        if (preg_match('/^(.{0,160})/us', $value, $match) !== 1) {
            return $value;
        }
        $preview = $match[1];
        return $preview === $value ? $preview : $preview . '…';
    }

    private static function filter(Request $request): string
    {
        $filter = $request->query()['filter'] ?? 'all';
        if (!is_string($filter) || !in_array($filter, ['all', 'starred'], true)) {
            throw new InvalidArgumentException('Direct conversation filter is invalid.');
        }
        return $filter;
    }

    private static function page(Request $request): int
    {
        $raw = $request->query()['page'] ?? 1;
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) || $raw < 1 || $raw > 33334) {
            throw new InvalidArgumentException('Direct conversation page is invalid.');
        }
        return $raw;
    }
}
