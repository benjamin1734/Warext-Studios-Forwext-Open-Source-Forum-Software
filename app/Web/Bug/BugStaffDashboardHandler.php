<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Bug\Conversation\BugReportNotifier;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Staff\BugStaffRepository;
use Forwext\Core\Bug\Staff\BugStaffService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class BugStaffDashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private BugStaffRepository $staff,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private UserRepository $users,
        private BugReportNotifier $notifier,
        private AuditRecorder $audit,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        }

        try {
            $gate = new PermissionGate($this->authorizer,$actor);
            $reader = new BugStaffFilterReader($this->users);
            $input = $reader->read($request->query());
            $reportService = new BugReportService($this->database,$this->reports,$gate,$this->authorizer);
            $service = new BugStaffService(
                $this->database,
                $reportService,
                $this->staff,
                $gate,
                $this->audit,
                $this->requestId($request),
                $this->notifier,
            );
            $dashboard = $service->dashboard($input->filter);

            $usernames = [];
            foreach ($dashboard->reports as $report) {
                foreach ([$report->reporterUserId,$report->assignedUserId] as $userId) {
                    if ($userId === null || isset($usernames[$userId->value()])) {
                        continue;
                    }
                    $user = $this->users->find($userId);
                    $usernames[$userId->value()] = $user?->username()->display() ?? $userId->value();
                }
            }
            foreach ($dashboard->audit as $entry) {
                $id = $entry->actorUserId;
                if (!isset($usernames[$id->value()])) {
                    $usernames[$id->value()] = $this->users->find($id)?->username()->display() ?? $id->value();
                }
            }

            return Response::html(BugStaffDashboardHtml::page(
                $dashboard,
                $this->basePath,
                $usernames,
                $input->assigneeQuery,
            ))
                ->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex, nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function requestId(Request $request):AuditRequestId
    {
        $value=$request->attribute(RequestIdMiddleware::ATTRIBUTE);
        return is_string($value)&&$value!=='' ? AuditRequestId::fromString($value) : AuditRequestId::generate();
    }
}
