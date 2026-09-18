<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Bug\Conversation\BugReportConversationRepository;
use Forwext\Core\Bug\Conversation\BugReportConversationService;
use Forwext\Core\Bug\Conversation\BugReportNotifier;
use Forwext\Core\Bug\Intake\BugReportIntakeRepository;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportNotFoundException;
use Forwext\Core\Bug\Report\BugReportOperationException;
use Forwext\Core\Bug\Report\BugReportRepository;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugStaffRepository;
use Forwext\Core\Bug\Staff\BugStaffService;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Middleware\RequestIdMiddleware;
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
        private BugStaffRepository $staff,
        private ProfileViewerResolver $viewers,
        private PermissionAuthorizer $authorizer,
        private UserRepository $users,
        private BugReportNotifier $notifier,
        private AuditRecorder $audit,
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
            $requestId=$this->requestId($request);
            $reportService=new BugReportService($this->database,$this->reports,$gate,$this->authorizer);
            $conversationService=new BugReportConversationService(
                $this->database,
                $reportService,
                $this->conversation,
                $gate,
                $this->notifier,
                $this->audit,
                $requestId,
            );
            $staffService=new BugStaffService(
                $this->database,
                $reportService,
                $this->staff,
                $gate,
                $this->audit,
                $requestId,
                $this->notifier,
            );

            if($request->method()===HttpMethod::Post){
                $report=$reportService->report($reportId);
                return $this->mutate($request,$actor,$report,$conversationService,$staffService);
            }

            return $this->view($request,$reportId,$conversationService,$staffService,$reportService,$gate);
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
        BugReportConversationService $conversationService,
        BugStaffService $staffService,
        BugReportService $reportService,
        PermissionGate $gate,
    ):Response {
        $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if(!is_string($token)||$token===''){
            return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
        }

        $view=$conversationService->view($reportId);
        $report=$view->report;
        $isReporter=$report->isReporter($gate->actorId());
        $canReply=$isReporter
            ? $gate->allows(PermissionKey::fromString(BugReportConversationService::REPLY_OWN_PERMISSION))
            : $gate->allows(PermissionKey::fromString(BugReportConversationService::REPLY_ALL_PERMISSION));
        $canManage=$view->staffView&&$gate->allows(PermissionKey::fromString(BugReportService::MANAGE_PERMISSION));
        $canAssign=$view->staffView&&$gate->allows(PermissionKey::fromString(BugReportService::ASSIGN_PERMISSION));
        $category=$this->reports->category($report->categoryKey);

        $staffContext=null;
        if($view->staffView){
            $link=$staffService->duplicateLink($reportId);
            $suggestions=$canManage&&$link===null
                ? $staffService->duplicateSuggestions($reportId)
                : [];
            $assigneeName=$report->assignedUserId===null
                ? null
                : $this->users->find($report->assignedUserId)?->username()->display();

            $staffContext=new BugReportStaffContext(
                $reportService->categories(),
                $suggestions,
                $link,
                $assigneeName,
            );
        }

        return Response::html(BugReportDetailHtml::page(
            $view,
            $this->intake->intake($reportId),
            $this->intake->attachments($reportId),
            $token,
            $this->basePath,
            new BugReportDetailCapabilities(
                $canReply,
                $canManage,
                $canAssign,
                $canManage,
                $canManage,
                $view->staffView,
            ),
            $category?->label??$report->categoryKey,
            ($request->query()['updated']??null)==='1',
            $staffContext,
        ))
            ->withHeader('Cache-Control','private, no-store')
            ->withHeader('X-Robots-Tag','noindex, nofollow');
    }

    private function mutate(
        Request $request,
        EntityId $actor,
        BugReport $report,
        BugReportConversationService $conversationService,
        BugStaffService $staffService,
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
                    $conversationService->addReporterInfo($reportId,$message);
                }else{
                    $conversationService->staffReply($reportId,$message);
                }
                break;
            case 'status':
                $staffService->changeStatus(
                    $reportId,
                    BugReportStatus::from($this->requiredString($body,'status',16)),
                );
                break;
            case 'assign':
                $username=$this->optionalString($body,'assignee_username',80);
                $assignee=null;
                if($username!==null){
                    $user=$this->users->findByUsername(Username::fromString($username));
                    if($user===null){
                        throw new InvalidArgumentException('Bug report assignee was not found.');
                    }
                    $assignee=$user->id();
                }
                $staffService->assign($reportId,$assignee);
                break;
            case 'severity':
                $staffService->changeSeverity(
                    $reportId,
                    BugReportSeverity::from($this->requiredString($body,'severity',16)),
                );
                break;
            case 'category':
                $staffService->changeCategory(
                    $reportId,
                    strtolower($this->requiredString($body,'category',64)),
                );
                break;
            case 'duplicate':
                $staffService->linkDuplicate(
                    $reportId,
                    EntityId::fromString($this->requiredHexId($body,'canonical_report_id')),
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

    /** @param array<string,mixed> $body */
    private function optionalString(array $body,string $key,int $maxBytes):?string
    {
        $value=$body[$key]??null;
        if($value===null||$value===''){
            return null;
        }
        if(!is_string($value)||strlen($value)>$maxBytes){
            throw new InvalidArgumentException('Bug report optional action field is invalid: '.$key);
        }
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param array<string,mixed> $body */
    private function requiredHexId(array $body,string $key):string
    {
        $value=$this->requiredString($body,$key,32);
        if(preg_match('/^[a-f0-9]{32}$/D',$value)!==1){
            throw new InvalidArgumentException('Bug report entity id is invalid: '.$key);
        }
        return $value;
    }

    private function requestId(Request $request):AuditRequestId
    {
        $value=$request->attribute(RequestIdMiddleware::ATTRIBUTE);
        return is_string($value)&&$value!=='' ? AuditRequestId::fromString($value) : AuditRequestId::generate();
    }
}
