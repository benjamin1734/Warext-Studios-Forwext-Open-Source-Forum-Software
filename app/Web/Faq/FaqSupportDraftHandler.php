<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Faq\SupportBridge\FaqSupportBridgeService;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class FaqSupportDraftHandler implements RequestHandlerInterface
{
    public function __construct(
        private FaqService $faq,
        private FaqSupportBridgeService $bridge,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {}

    public function handle(Request $request):Response
    {
        $actor=$this->viewers->resolve($request);
        if($actor===null) return Response::text('Authentication required.',401)->withHeader('Cache-Control','no-store');
        try{
            if($request->method()===HttpMethod::Post){
                $body=$request->parsedBody();
                $action=$body['action']??null;
                $draftId=$body['draft_id']??null;
                if(!is_string($action)||!is_string($draftId)||preg_match('/^[a-f0-9]{32}$/D',$draftId)!==1){
                    throw new InvalidArgumentException('FAQ support draft action is invalid.');
                }
                if($action==='reject'){
                    $this->bridge->rejectDraft($actor,EntityId::fromString($draftId));
                }elseif($action==='apply'){
                    $category=$body['category']??null;
                    $slug=$body['slug']??null;
                    if(!is_string($category)||!is_string($slug)) throw new InvalidArgumentException('FAQ support draft apply input is invalid.');
                    $this->bridge->applyDraft($actor,EntityId::fromString($draftId),strtolower(trim($category)),strtolower(trim($slug)));
                }else{
                    throw new InvalidArgumentException('Unknown FAQ support draft action.');
                }
                return Response::text('',303)
                    ->withHeader('Location',$this->basePath->prepend('/faq/manage/support-drafts?updated=1'))
                    ->withHeader('Cache-Control','no-store');
            }

            $token=$request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if(!is_string($token)||$token==='') return Response::text('Internal Server Error',500)->withHeader('Cache-Control','no-store');
            $snapshot=$this->faq->managementSnapshot($actor);
            return Response::html(FaqSupportDraftHtml::page(
                $this->bridge->pendingDrafts($actor),
                $snapshot['categories'],
                $this->basePath,
                $token,
                ($request->query()['updated']??null)==='1',
            ))->withHeader('Cache-Control','private, no-store');
        }catch(PermissionDeniedException){
            return Response::text('Forbidden',403)->withHeader('Cache-Control','no-store');
        }catch(InvalidArgumentException|\RuntimeException){
            return Response::text('Bad Request',400)->withHeader('Cache-Control','no-store');
        }
    }
}
