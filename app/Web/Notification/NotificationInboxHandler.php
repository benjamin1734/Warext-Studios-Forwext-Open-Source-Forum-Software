<?php

declare(strict_types=1);

namespace Forwext\App\Web\Notification;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Notification\NotificationException;
use Forwext\Core\Notification\NotificationInboxService;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class NotificationInboxHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 30;

    public function __construct(
        private NotificationInboxService $inbox,
        private ProfileViewerResolver $viewers,
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

        try {
            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($actor, $request);
            }

            if (self::previewRequested($request)) {
                return $this->preview($actor);
            }

            $page = $this->page($request->query()['page'] ?? null);
            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            $rows = $this->inbox->inbox(
                $actor,
                self::PER_PAGE + 1,
                ($page - 1) * self::PER_PAGE,
            );
            $hasMore = count($rows) > self::PER_PAGE;
            if ($hasMore) {
                array_pop($rows);
            }

            return Response::html(NotificationInboxHtml::page(
                $rows,
                $this->inbox->unreadCount($actor),
                $page,
                $hasMore,
                $csrf,
                $this->basePath,
                $this->timezone,
            ))->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (NotificationException|InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function preview(EntityId $actor): Response
    {
        $items = [];
        foreach ($this->inbox->inbox($actor, 4, 0) as $notification) {
            $items[] = [
                'title' => $notification->title,
                'preview' => self::compactText($notification->body),
                'category' => $notification->categoryKey,
                'href' => $this->basePath->prepend('/account/notifications'),
                'updated_label' => $notification->updatedAt->setTimezone($this->timezone)->format('d.m.Y H:i'),
                'unread' => $notification->readAt === null,
                'occurrences' => $notification->occurrences,
            ];
        }

        return Response::json([
            'unread_count' => $this->inbox->unreadCount($actor),
            'items' => $items,
        ])->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow');
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        $page = $this->page($body['page'] ?? null);

        if ($action === 'mark_all_read') {
            $this->inbox->markAllRead($actor);

            return Response::redirect(
                $this->basePath->prepend('/account/notifications?page=' . $page),
                303,
            )->withHeader('Cache-Control', 'no-store');
        }

        if ($action !== 'mark_read') {
            throw new InvalidArgumentException('Notification inbox action is invalid.');
        }

        $rawId = $body['notification_id'] ?? null;
        if (!is_string($rawId) || preg_match('/^[a-f0-9]{32}$/D', $rawId) !== 1) {
            throw new InvalidArgumentException('Notification id is invalid.');
        }

        $this->inbox->markRead($actor, EntityId::fromString($rawId));

        return Response::redirect(
            $this->basePath->prepend('/account/notifications?page=' . $page),
            303,
        )->withHeader('Cache-Control', 'no-store');
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

    private function page(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (is_int($raw) && $raw >= 1 && $raw <= 10000) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,3}$/D', $raw) === 1) {
            return (int) $raw;
        }

        throw new InvalidArgumentException('Notification page is invalid.');
    }
}
