<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Analytics\AnalyticsEvent;
use Forwext\Core\Analytics\AnalyticsEventRecorder;
use Forwext\Core\Bug\Diagnostic\BugBrowserDeviceClassifier;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\ThreadRepository;
use Forwext\Core\Http\Middleware\MiddlewareInterface;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;

final readonly class AnalyticsRequestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AnalyticsEventRecorder $analytics,
        private ProfileViewerResolver $viewers,
        private ThreadRepository $threads,
        private BugBrowserDeviceClassifier $devices,
        private string $sessionCookieName,
    ){
        if($this->sessionCookieName===''){
            throw new \InvalidArgumentException('Analytics session cookie name cannot be empty.');
        }
    }

    public function process(Request $request,RequestHandlerInterface $next):Response
    {
        $response=$next->handle($request);

        if(!$this->eligible($request,$response))return $response;

        $route=$request->attribute(Router::ATTRIBUTE_ROUTE_NAME);
        if(!is_string($route)||$route==='')return $response;

        $viewer=$this->viewers->resolve($request);
        $session=$request->cookie($this->sessionCookieName);
        if(!is_string($session)||$session==='')$session=null;

        $device=$this->devices->classify($request->headers()->first('user-agent'))->deviceClass;

        if($viewer!==null){
            $this->analytics->recordBestEffort(new AnalyticsEvent(
                'user.active',
                actorUserId:$viewer,
                sessionId:$session,
                dimensions:['device'=>$device],
            ));
        }

        $forumId=$this->forumId($request);
        if($forumId!==null){
            $this->analytics->recordBestEffort(new AnalyticsEvent(
                'forum.view',
                actorUserId:$viewer,
                sessionId:$session,
                forumId:$forumId,
                dimensions:['route'=>$route,'device'=>$device],
            ));
        }

        return $response;
    }

    private function eligible(Request $request,Response $response):bool
    {
        if($request->method()->value!=='GET'||$response->status()!==200)return false;
        $type=strtolower($response->headers()->first('content-type')??'');
        return str_starts_with($type,'text/html');
    }

    private function forumId(Request $request):?EntityId
    {
        $params=$request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS,[]);
        if(!is_array($params))return null;

        foreach(['forumId','forumNodeId','nodeId'] as $key){
            $raw=$params[$key]??null;
            if(is_string($raw)&&$raw!=='')return EntityId::fromString($raw);
        }

        $threadId=$params['threadId']??null;
        if(is_string($threadId)&&$threadId!==''){
            return $this->threads->find(EntityId::fromString($threadId))?->forumNodeId();
        }

        return null;
    }
}
