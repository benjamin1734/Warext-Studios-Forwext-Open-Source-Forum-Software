<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Bug\Intake\BugReportIntakeRepository;
use Forwext\Core\Bug\Report\BugReportNotFoundException;
use Forwext\Core\Bug\Report\BugReportNotifier;
use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class BugReportDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private BugReportIntakeRepository $intake,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BugReportNotifier $notifier,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['reportId'] ?? null) : null;
        if (!is_string($value)) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $reportId = EntityId::fromString($value);
            $gate = new PermissionGate($this->authorizer, $actor);
            $service = new BugReportService(
                $this->database,
                $this->reports,
                $gate,
                $this->authorizer,
                $this->notifier,
            );

            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($request, $reportId, $service);
            }

            $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($token) || $token === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            $report = $service->report($reportId);
            $canAddInfo = $report->isReporter($actor)
                && !$report->status->isTerminal()
                && $gate->allows(PermissionKey::fromString(BugReportService::VIEW_OWN_PERMISSION));
            $canStaffRespond = $gate->allows(PermissionKey::fromString(BugReportService::VIEW_ALL_PERMISSION))
                && $gate->allows(PermissionKey::fromString(BugReportService::MANAGE_PERMISSION));

            return Response::html(BugReportDetailHtml::page(
                $report,
                $this->intake->intake($reportId),
                $this->intake->attachments($reportId),
                $service->history($reportId),
                $token,
                $this->basePath,
                $canAddInfo,
                $canStaffRespond,
                ($request->query()['updated'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (BugReportNotFoundException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        } catch (BugReportOperationException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(
        Request $request,
        EntityId $reportId,
        BugReportService $service,
    ): Response {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Bug report action is missing.');
        }
        $message = $body['body'] ?? null;
        if (!is_string($message)) {
            throw new InvalidArgumentException('Bug report response body is missing.');
        }

        match ($action) {
            'additional_info' => $service->addReporterInfo($reportId, $message),
            'staff_response' => $service->staffRespond($reportId, $message),
            default => throw new InvalidArgumentException('Unknown bug report action.'),
        };

        return Response::text('', 303)
            ->withHeader(
                'Location',
                $this->basePath->prepend('/bugs/' . rawurlencode($reportId->value()) . '?updated=1'),
            )
            ->withHeader('Cache-Control', 'no-store');
    }
}
