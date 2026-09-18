<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

final readonly class MyBugReportsHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null){
            return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        }

        try{
            $service=new BugReportService(
                $this->database,
                $this->reports,
                new PermissionGate($this->authorizer,$actor),
                $this->authorizer,
            );
            $own=$service->own();
            $labels=[];
            foreach($own as $report){
                if(isset($labels[$report->categoryKey]))continue;
                $category=$this->reports->category($report->categoryKey);
                $labels[$report->categoryKey]=$category?->label??$report->categoryKey;
            }
            return Response::html(MyBugReportsHtml::page($own,$labels,$this->basePath))
                ->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }
    }
}
