<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Forum\AttachmentDownloadResponseFactory;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Bug\Intake\BugAttachmentDownloadService;
use Forwext\Core\Bug\Intake\BugAttachmentOperationException;
use Forwext\Core\Bug\Intake\BugReportIntakeRepository;
use Forwext\Core\Bug\Report\BugReportNotFoundException;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use Forwext\Core\Storage\StorageDriver;
use InvalidArgumentException;

final readonly class BugAttachmentDownloadHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private BugReportIntakeRepository $intake,
        private StorageDriver $storage,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private AttachmentDownloadResponseFactory $responses,
    ) {
    }

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null){
            return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        }
        $parameters=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        $reportValue=is_array($parameters)?($parameters['reportId']??null):null;
        $attachmentValue=is_array($parameters)?($parameters['attachmentId']??null):null;
        if(!is_string($reportValue)||!is_string($attachmentValue)){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }

        try{
            $service=new BugReportService(
                $this->database,
                $this->reports,
                new PermissionGate($this->authorizer,$actor),
                $this->authorizer,
            );
            $download=(new BugAttachmentDownloadService($service,$this->intake,$this->storage))->download(
                EntityId::fromString($reportValue),
                EntityId::fromString($attachmentValue),
            );
            return $this->responses->create($download);
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|BugReportNotFoundException|BugAttachmentOperationException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }
    }
}
