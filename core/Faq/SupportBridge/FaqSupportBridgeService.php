<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\SupportBridge;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqArticle;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\FaqRepository;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Support\Conversation\SupportConversationMessage;
use Forwext\Core\Support\Conversation\SupportMessageRole;
use Forwext\Core\Support\Conversation\SupportMessageVisibility;
use Forwext\Core\Support\Ticket\SupportTicket;
use InvalidArgumentException;

final readonly class FaqSupportBridgeService
{
    public const SUGGEST_DRAFT_PERMISSION='support.faq_draft.suggest';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private FaqService $faq,
        private FaqRepository $faqRepository,
        private FaqSupportBridgeRepository $drafts,
        private PermissionAuthorizer $authorizer,
    ) {}

    /** @return list<FaqSupportRecommendation> */
    public function recommend(?EntityId $actor,string $supportCategoryKey,string $query,int $limit=5):array
    {
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$supportCategoryKey)!==1) throw new InvalidArgumentException('Support category key is invalid for FAQ recommendation.');
        if($limit<1||$limit>10) throw new InvalidArgumentException('FAQ recommendation limit is invalid.');
        $tokens=self::tokens($query);
        $recommendations=[];
        foreach($this->drafts->candidateArticleIds($supportCategoryKey,$tokens,100) as $articleId){
            try {
                $view=$this->faq->article($articleId,$actor);
            } catch (\Forwext\Core\Faq\FaqOperationException) {
                continue;
            }
            $article=$view->article;
            $score=0;$reasons=[];
            if($article->categoryKey===$supportCategoryKey){$score+=60;$reasons[]='category';}
            if(in_array($supportCategoryKey,$article->tags,true)){$score+=40;$reasons[]='tag';}
            $question=self::normalize($article->question);
            $answer=self::normalize($article->answer);
            foreach($tokens as $token){
                if(str_contains($question,$token)){$score+=8;$reasons[]='question:'.$token;}
                elseif(in_array($token,$article->tags,true)){$score+=6;$reasons[]='tag:'.$token;}
                elseif(str_contains($answer,$token)){$score+=2;$reasons[]='answer:'.$token;}
            }
            if($score>0){
                $ratio=$view->helpful->ratio();
                if($ratio!==null) $score+=(int)round($ratio*5);
                $recommendations[]=new FaqSupportRecommendation($view,$score,array_values(array_unique($reasons)));
            }
        }
        usort($recommendations,static function(FaqSupportRecommendation $a,FaqSupportRecommendation $b):int{
            if($a->score!==$b->score) return $b->score<=>$a->score;
            return [$a->view->article->sortOrder,$a->view->article->articleId->value()]
                <=>[$b->view->article->sortOrder,$b->view->article->articleId->value()];
        });
        return array_slice($recommendations,0,$limit);
    }

    public function suggestDraft(
        EntityId $actor,
        SupportTicket $ticket,
        SupportConversationMessage $message,
        ?string $suggestedCategoryKey=null,
        ?DateTimeImmutable $now=null,
    ):FaqSupportDraftSuggestion {
        $this->requirePermission($actor,self::SUGGEST_DRAFT_PERMISSION);
        if(!$message->ticketId->equals($ticket->ticketId)
            || $message->authorRole!==SupportMessageRole::Staff
            || $message->visibility!==SupportMessageVisibility::Public
        ) throw new InvalidArgumentException('Only a public staff reply from this ticket may become an FAQ draft.');
        if($suggestedCategoryKey!==null){
            $category=$this->faqRepository->category($suggestedCategoryKey);
            if($category===null) throw new InvalidArgumentException('Suggested FAQ category does not exist.');
        } elseif($this->faqRepository->category($ticket->categoryKey)!==null) {
            $suggestedCategoryKey=$ticket->categoryKey;
        }

        $draft=new FaqSupportDraftSuggestion(
            FaqSupportDraftSuggestion::generateId(),
            $ticket->ticketId,
            $message->messageId,
            $actor,
            $suggestedCategoryKey,
            $ticket->subject,
            $message->body,
            FaqSupportDraftStatus::Pending,
            self::utc($now),
        );
        $this->drafts->create($draft);
        return $draft;
    }

    /** @return list<FaqSupportDraftSuggestion> */
    public function pendingDrafts(EntityId $actor,int $limit=100):array
    {
        $this->requirePermission($actor,FaqService::MANAGE_PERMISSION);
        return $this->drafts->pending($limit);
    }

    public function applyDraft(
        EntityId $actor,
        EntityId $draftId,
        string $categoryKey,
        string $slug,
        ?DateTimeImmutable $now=null,
    ):FaqArticle {
        $this->requirePermission($actor,FaqService::MANAGE_PERMISSION);
        $draft=$this->drafts->find($draftId)??throw new InvalidArgumentException('FAQ support draft was not found.');
        if($draft->status!==FaqSupportDraftStatus::Pending) throw new InvalidArgumentException('FAQ support draft is no longer pending.');
        $category=$this->faqRepository->category($categoryKey)??throw new InvalidArgumentException('FAQ draft target category does not exist.');
        $now=self::utc($now);
        $tags=['support-draft'];
        if(preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$categoryKey)===1) $tags[]=$categoryKey;
        $article=new FaqArticle(
            FaqArticle::generateId(),
            $category->key,
            strtolower(trim($slug)),
            $draft->question,
            $draft->answer,
            $tags,
            FaqVisibility::Staff,
            $category->language,
            100,
            null,
            null,
            false,
            $now,
            $now,
        );
        $this->database->transaction(function()use($actor,$article,$draftId):void{
            $this->faq->saveArticle($actor,$article);
            $this->drafts->updateStatus($draftId,FaqSupportDraftStatus::Applied);
        });
        return $article;
    }

    public function rejectDraft(EntityId $actor,EntityId $draftId):void
    {
        $this->requirePermission($actor,FaqService::MANAGE_PERMISSION);
        $this->drafts->updateStatus($draftId,FaqSupportDraftStatus::Rejected);
    }

    private function requirePermission(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed()) throw new PermissionDeniedException($decision);
    }

    /** @return list<string> */
    private static function tokens(string $text):array
    {
        $parts=preg_split('/[^\p{L}\p{N}]+/u',self::normalize($text))?:[];
        $tokens=[];
        foreach($parts as $part){
            if(strlen($part)<3) continue;
            $tokens[$part]=true;
            if(count($tokens)>=24) break;
        }
        return array_keys($tokens);
    }
    private static function normalize(string $text):string
    { return strtolower(trim((string)preg_replace('/\s+/u',' ',strip_tags($text)))); }
    private static function utc(?DateTimeImmutable $now):DateTimeImmutable
    { return ($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC')); }
}
