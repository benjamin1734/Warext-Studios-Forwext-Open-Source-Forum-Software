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
use InvalidArgumentException;

final readonly class BugStaffExportHandler implements RequestHandlerInterface
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
    ) {
    }

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null){
            return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        }

        try{
            $gate=new PermissionGate($this->authorizer,$actor);
            $input=(new BugStaffFilterReader($this->users))->read($request->query(),1000);
            $service=new BugStaffService(
                $this->database,
                new BugReportService($this->database,$this->reports,$gate,$this->authorizer),
                $this->staff,
                $gate,
                $this->audit,
                $this->requestId($request),
                $this->notifier,
            );
            $csv=$service->export($input->filter);

            return Response::text($csv)
                ->withHeader('Content-Type','text/csv; charset=utf-8')
                ->withHeader('Content-Disposition','attachment; filename="forwext-bug-reports.csv"')
                ->withHeader('X-Content-Type-Options','nosniff')
                ->withHeader('Cache-Control','private, no-store')
                ->withHeader('X-Robots-Tag','noindex, nofollow');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function requestId(Request $request):AuditRequestId
    {
        $value=$request->attribute(RequestIdMiddleware::ATTRIBUTE);
        return is_string($value)&&$value!=='' ? AuditRequestId::fromString($value) : AuditRequestId::generate();
    }
}
