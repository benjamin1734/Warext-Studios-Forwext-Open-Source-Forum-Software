<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Bug\Conversation\BugReportConversationRepository;
use Forwext\Core\Bug\Conversation\BugReportConversationService;
use Forwext\Core\Bug\Conversation\BugReportNotifier;
use Forwext\Core\Bug\Intake\BugReportIntakeRepository;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportNotFoundException;
use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportStatus;
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
use ValueError;

final readonly class BugReportDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportRepository $reports,
        private BugReportIntakeRepository $intake,
        private BugReportConversationRepository $conversation,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private BugReportNotifier $notifier,
        private BasePath $basePath,
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
        if(!is_string($reportValue)){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }

        try{
            $reportId=EntityId::fromString($reportValue);
            $gate=new PermissionGate($this->authorizer,$actor);
            $reportService=new BugReportService($this->database,$this->reports,$gate,$this->authorizer);
            $service=new BugReportConversationService(
                $this->database,
                $reportService,
                $this->conversation,
                $gate,
                $this->notifier,
            );

            if($request->method()===HttpMethod::Post){
                $report=$reportService->report($reportId);
                return $this->mutate($request,$actor,$report,$service);
            }

            return $this->view($request,$reportId,$service,$gate);
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(BugReportNotFoundException){
            return Response::text('Not Found',404)->withHeader('Cache-Control','no-store');
        }catch(BugReportOperationException){
            return Response::text('Conflict',409)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|ValueError){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }

    private function view(
        Request $request,
        EntityId $reportId,
        BugReportConversationService $service,
        PermissionGate $gate,
    ):Response {
        $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if(!is_string($token)||$token===''){
            return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
        }

        $view=$service->view($reportId);
        $report=$view->report;
        $isReporter=$report->isReporter($gate->actorId());
        $canReply=$isReporter
            ? $gate->allows(PermissionKey::fromString(BugReportConversationService::REPLY_OWN_PERMISSION))
            : $gate->allows(PermissionKey::fromString(BugReportConversationService::REPLY_ALL_PERMISSION));
        $category=$this->reports->category($report->categoryKey);

        return Response::html(BugReportDetailHtml::page(
            $view,
            $this->intake->intake($reportId),
            $this->intake->attachments($reportId),
            $token,
            $this->basePath,
            new BugReportDetailCapabilities(
                $canReply,
                $view->staffView && $gate->allows(PermissionKey::fromString(BugReportService::MANAGE_PERMISSION)),
            ),
            $category?->label??$report->categoryKey,
            ($request->query()['updated']??null)==='1',
        ))->withHeader('Cache-Control','private, no-store');
    }

    private function mutate(
        Request $request,
        EntityId $actor,
        BugReport $report,
        BugReportConversationService $service,
    ):Response {
        $reportId=$report->reportId;
        $body=$request->parsedBody();
        $action=$body['action']??null;
        if(!is_string($action)){
            throw new InvalidArgumentException('Bug report action is missing.');
        }

        switch($action){
            case 'reply':
                $message=$this->requiredString($body,'body',10000);
                if($report->isReporter($actor)){
                    $service->addReporterInfo($reportId,$message);
                }else{
                    $service->staffReply($reportId,$message);
                }
                break;
            case 'status':
                $service->changeStatus(
                    $reportId,
                    BugReportStatus::from($this->requiredString($body,'status',16)),
                );
                break;
            default:
                throw new InvalidArgumentException('Unknown bug report action.');
        }

        return Response::text('',303)
            ->withHeader('Location',$this->basePath->prepend('/bugs/'.rawurlencode($reportId->value()).'?updated=1'))
            ->withHeader('Cache-Control','no-store');
    }

    /** @param array<string,mixed> $body */
    private function requiredString(array $body,string $key,int $maxBytes):string
    {
        $value=$body[$key]??null;
        if(!is_string($value)){
            throw new InvalidArgumentException('Bug report action field is missing: '.$key);
        }
        $value=trim($value);
        if($value===''||strlen($value)>$maxBytes){
            throw new InvalidArgumentException('Bug report action field is invalid: '.$key);
        }
        return $value;
    }
}
