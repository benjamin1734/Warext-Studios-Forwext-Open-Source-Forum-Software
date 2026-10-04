<?php

declare(strict_types=1);

namespace Forwext\App\Web\Search;

use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Search\Discovery\DiscoveryMode;
use Forwext\Core\Search\Discovery\ThreadDiscoveryService;
use Forwext\Core\Search\SearchException;
use InvalidArgumentException;
use ValueError;

final readonly class ThreadDiscoveryHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 30;

    public function __construct(
        private ThreadDiscoveryService $discovery,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private DateTimeZone $timezone,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::html(ThreadDiscoveryHtml::authenticationRequired($this->basePath), 401)
                ->withHeader('Cache-Control', 'private, no-store');
        }

        try {
            $mode = $this->mode($request);
            $page = $this->page($request);
            $rows = $this->discovery->discover(
                $actor,
                $mode,
                self::PER_PAGE + 1,
                ($page - 1) * self::PER_PAGE,
            );
            $hasMore = count($rows) > self::PER_PAGE;
            if ($hasMore) {
                array_pop($rows);
            }

            return Response::html(ThreadDiscoveryHtml::page(
                $rows,
                $mode,
                $page,
                $hasMore,
                $this->basePath,
                $this->timezone,
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::html(ThreadDiscoveryHtml::permissionDenied($this->basePath), 403)
                ->withHeader('Cache-Control', 'private, no-store');
        } catch (SearchException|InvalidArgumentException|ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mode(Request $request): DiscoveryMode
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $raw = is_array($parameters) ? ($parameters['mode'] ?? null) : null;
        if ($raw === null) {
            return DiscoveryMode::RecentActivity;
        }
        if (!is_string($raw)) {
            throw new InvalidArgumentException('Discovery mode is invalid.');
        }

        return match ($raw) {
            'new' => DiscoveryMode::New,
            'unread' => DiscoveryMode::Unread,
            'trending' => DiscoveryMode::Trending,
            'featured' => DiscoveryMode::Featured,
            'no-replies' => DiscoveryMode::NoReplies,
            'mine' => DiscoveryMode::StartedByViewer,
            'participated' => DiscoveryMode::ParticipatedByViewer,
            'recent' => DiscoveryMode::RecentActivity,
            default => throw new InvalidArgumentException('Discovery mode is invalid.'),
        };
    }

    private function page(Request $request): int
    {
        $raw = $request->query()['page'] ?? null;
        if ($raw === null || $raw === '') {
            return 1;
        }
        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,2}$/D', $raw) === 1) {
            $page = (int) $raw;
            if ($page <= 34) {
                return $page;
            }
        }

        throw new InvalidArgumentException('Discovery page is invalid.');
    }
}
