<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Analytics\Operations\OperationsAnalyticsService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class OperationsAnalyticsHandler implements RequestHandlerInterface
{
    public function __construct(
        private OperationsAnalyticsService $analytics,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $raw = $request->query()['days'] ?? '30';
            if (!is_string($raw) || !in_array($raw, ['7', '30', '90'], true)) {
                throw new InvalidArgumentException('Operations analytics range is invalid.');
            }
            $snapshot = $this->analytics->dashboard(
                $actor,
                (int) $raw,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

            return Response::html(OperationsAnalyticsHtml::page($snapshot, $this->basePath))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }
}
