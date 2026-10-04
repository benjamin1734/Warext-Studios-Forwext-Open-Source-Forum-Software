<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\Operations\SystemOperationsService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Breadcrumb\BreadcrumbTrail;
use InvalidArgumentException;
use RuntimeException;

final readonly class AdminCronHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemOperationsService $operations,
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
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                if (($body['action'] ?? null) !== 'run') {
                    throw new InvalidArgumentException('Cron action is invalid.');
                }
                $taskName = $body['task_name'] ?? null;
                if (!is_string($taskName) || strlen($taskName) > 191) {
                    throw new InvalidArgumentException('Cron task is invalid.');
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $this->operations->runScheduledTask(
                    $actor,
                    $taskName,
                    HttpAuditRequestId::fromRequest($request),
                    $now,
                );

                return Response::redirect(
                    $this->basePath->prepend('/admin/system/cron?updated=run'),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                throw new RuntimeException('Cron management CSRF render token is unavailable.');
            }
            $query = $request->query()['q'] ?? '';
            if (!is_string($query) || strlen($query) > 120 || preg_match('//u', $query) !== 1) {
                throw new InvalidArgumentException('Cron filter is invalid.');
            }
            $updated = ($request->query()['updated'] ?? null) === 'run' ? 'run' : null;
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

            return Response::html(ProfileHtml::page(
                'Cron Entries',
                AdminCronHtml::page(
                    $this->operations->scheduledTasks($actor),
                    $this->basePath,
                    $csrf,
                    trim($query),
                    $now,
                    $updated,
                ),
                $this->basePath,
                breadcrumbs: new BreadcrumbTrail([]),
                authenticated: true,
                viewerId: $actor->value(),
                headAssets: AdminAssetsHtml::headAssets($this->basePath),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }
}
