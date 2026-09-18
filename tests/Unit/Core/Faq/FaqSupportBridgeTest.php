<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Faq;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqArticle;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\FaqHelpfulSummary;
use Forwext\Core\Faq\FaqRepository;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Faq\SupportBridge\FaqSupportBridgeRepository;
use Forwext\Core\Faq\SupportBridge\FaqSupportBridgeService;
use Forwext\Core\Faq\SupportBridge\FaqSupportDraftStatus;
use Forwext\Core\Faq\SupportBridge\FaqSupportDraftSuggestion;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use Forwext\Core\Support\Conversation\SupportConversationMessage;
use Forwext\Core\Support\Conversation\SupportMessageRole;
use Forwext\Core\Support\Conversation\SupportMessageVisibility;
use Forwext\Core\Support\Ticket\SupportSlaMetadata;
use Forwext\Core\Support\Ticket\SupportTicket;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use Forwext\Core\Support\Ticket\SupportTicketStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FaqSupportBridgeTest extends TestCase
{
    public function testRecommendationUsesFaqVisibilityAndCategoryQuestionSignals(): void
    {
        $member=$this->id('1');
        $faqRepo=new BridgeFaqRepository();
        $faqRepo->categories['general']=new FaqCategory('general','General','','tr',FaqVisibility::Public,10,true);
        $faqRepo->categories['staff']=new FaqCategory('staff','Staff','','tr',FaqVisibility::Staff,20,true);
        $public=$this->article('a','general','giris-sorunu','Giriş yapamıyorum','Hesabınızı kontrol edin',['general','giris'],FaqVisibility::Public);
        $hidden=$this->article('b','staff','gizli-prosedur','Giriş prosedürü','Sadece yetkililer',['general','giris'],FaqVisibility::Public);
        $faqRepo->articles[$public->articleId->value()]=$public;
        $faqRepo->articles[$hidden->articleId->value()]=$hidden;
        $draftRepo=new BridgeDraftRepository([$public->articleId,$hidden->articleId]);
        $authorizer=$this->authorizer([$member->value()=>['faq.view']]);
        $faq=new FaqService(new BridgeDatabase(),$faqRepo,$authorizer,new BridgeChangeStore());
        $bridge=new FaqSupportBridgeService(new BridgeDatabase(),$faq,$faqRepo,$draftRepo,$authorizer);

        $results=$bridge->recommend($member,'general','giriş yapamıyorum');

        self::assertCount(1,$results);
        self::assertSame($public->articleId->value(),$results[0]->view->article->articleId->value());
        self::assertGreaterThan(0,$results[0]->score);
    }

    public function testInternalNoteCannotBecomeFaqDraftButPublicStaffReplyCan(): void
    {
        $staff=$this->id('2');
        $faqRepo=new BridgeFaqRepository();
        $draftRepo=new BridgeDraftRepository([]);
        $authorizer=$this->authorizer([$staff->value()=>['support.faq_draft.suggest']]);
        $faq=new FaqService(new BridgeDatabase(),$faqRepo,$authorizer,new BridgeChangeStore());
        $bridge=new FaqSupportBridgeService(new BridgeDatabase(),$faq,$faqRepo,$draftRepo,$authorizer);
        $ticket=$this->ticket($this->id('1'));
        $internal=$this->message($ticket,$staff,'internal',SupportMessageVisibility::Internal);

        try {
            $bridge->suggestDraft($staff,$ticket,$internal);
            self::fail('Internal staff note must not become an FAQ draft.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $public=$this->message($ticket,$staff,'public answer',SupportMessageVisibility::Public,'d');
        $draft=$bridge->suggestDraft($staff,$ticket,$public);
        self::assertSame(FaqSupportDraftStatus::Pending,$draft->status);
        self::assertSame('public answer',$draft->answer);
        self::assertCount(1,$draftRepo->drafts);
    }

    public function testFaqManagerCanApplySuggestedDraftAsInactiveStaffArticle(): void
    {
        $staff=$this->id('2');
        $manager=$this->id('3');
        $faqRepo=new BridgeFaqRepository();
        $faqRepo->categories['general']=new FaqCategory('general','General','','tr',FaqVisibility::Public,10,true);
        $draftRepo=new BridgeDraftRepository([]);
        $authorizer=$this->authorizer([
            $staff->value()=>['support.faq_draft.suggest'],
            $manager->value()=>['faq.manage'],
        ]);
        $db=new BridgeDatabase();
        $faq=new FaqService($db,$faqRepo,$authorizer,new BridgeChangeStore());
        $bridge=new FaqSupportBridgeService($db,$faq,$faqRepo,$draftRepo,$authorizer);
        $ticket=$this->ticket($this->id('1'));
        $draft=$bridge->suggestDraft(
            $staff,
            $ticket,
            $this->message($ticket,$staff,'Reusable solution',SupportMessageVisibility::Public,'e'),
            'general',
            $this->now(),
        );

        $article=$bridge->applyDraft($manager,$draft->draftId,'general','cozum-rehberi',$this->now());

        self::assertFalse($article->active);
        self::assertSame(FaqVisibility::Staff,$article->visibility);
        self::assertContains('support-draft',$article->tags);
        self::assertSame(FaqSupportDraftStatus::Applied,$draftRepo->drafts[$draft->draftId->value()]->status);
        self::assertSame('Reusable solution',$article->answer);
    }

    /** @param array<string,list<string>> $permissions */
    private function authorizer(array $permissions):PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new BridgePermissionRepository($permissions)),
            new BridgeAssignmentProvider(array_keys($permissions)),
        );
    }

    private function article(string $seed,string $category,string $slug,string $question,string $answer,array $tags,FaqVisibility $visibility):FaqArticle
    {
        return new FaqArticle($this->id($seed),$category,$slug,$question,$answer,$tags,$visibility,'tr',10,null,null,true,$this->now(),$this->now());
    }

    private function ticket(EntityId $requester):SupportTicket
    {
        return new SupportTicket($this->id('c'),'general',$requester,null,'Giriş sorunu',SupportTicketPriority::Normal,SupportTicketStatus::Open,new SupportSlaMetadata(null,null),null,$this->now(),$this->now(),1);
    }

    private function message(SupportTicket $ticket,EntityId $staff,string $body,SupportMessageVisibility $visibility,string $seed='f'):SupportConversationMessage
    {
        return new SupportConversationMessage($this->id($seed),$ticket->ticketId,$staff,SupportMessageRole::Staff,$visibility,$body,null,null,null,$this->now());
    }

    private function id(string $seed):EntityId { return EntityId::fromString(str_repeat($seed,32)); }
    private function now():DateTimeImmutable { return new DateTimeImmutable('2026-09-18 18:30:00',new DateTimeZone('UTC')); }
}

final class BridgeFaqRepository implements FaqRepository
{
    /** @var array<string,FaqCategory> */ public array $categories=[];
    /** @var array<string,FaqArticle> */ public array $articles=[];
    public function categories():array{return array_values($this->categories);}
    public function category(string $key):?FaqCategory{return $this->categories[$key]??null;}
    public function saveCategory(FaqCategory $category):void{$this->categories[$category->key]=$category;}
    public function articles():array{return array_values($this->articles);}
    public function articlesByCategory(string $categoryKey):array{return array_values(array_filter($this->articles,static fn(FaqArticle $a):bool=>$a->categoryKey===$categoryKey));}
    public function article(EntityId $articleId):?FaqArticle{return $this->articles[$articleId->value()]??null;}
    public function articleBySlug(string $language,string $slug):?FaqArticle{foreach($this->articles as $a)if($a->language===$language&&$a->slug===$slug)return $a;return null;}
    public function saveArticle(FaqArticle $article):void{$this->articles[$article->articleId->value()]=$article;}
    public function recordHelpful(EntityId $articleId,EntityId $userId,bool $helpful):void{}
    public function helpfulSummary(EntityId $articleId):FaqHelpfulSummary{return new FaqHelpfulSummary(0,0);}
}

final class BridgeDraftRepository implements FaqSupportBridgeRepository
{
    /** @var list<EntityId> */ public array $candidates;
    /** @var array<string,FaqSupportDraftSuggestion> */ public array $drafts=[];
    /** @param list<EntityId> $candidates */
    public function __construct(array $candidates){$this->candidates=$candidates;}
    public function candidateArticleIds(string $supportCategoryKey,array $tokens,int $limit=100):array{return array_slice($this->candidates,0,$limit);}
    public function create(FaqSupportDraftSuggestion $draft):void{$this->drafts[$draft->draftId->value()]=$draft;}
    public function find(EntityId $draftId):?FaqSupportDraftSuggestion{return $this->drafts[$draftId->value()]??null;}
    public function pending(int $limit=100):array{return array_slice(array_values(array_filter($this->drafts,static fn(FaqSupportDraftSuggestion $d):bool=>$d->status===FaqSupportDraftStatus::Pending)),0,$limit);}
    public function updateStatus(EntityId $draftId,FaqSupportDraftStatus $status):void
    {
        $d=$this->drafts[$draftId->value()]??throw new \RuntimeException('missing');
        $this->drafts[$draftId->value()]=new FaqSupportDraftSuggestion($d->draftId,$d->ticketId,$d->sourceMessageId,$d->suggestedByUserId,$d->suggestedCategoryKey,$d->question,$d->answer,$status,$d->createdAt);
    }
}

final class BridgeDatabase implements TransactionalQueryExecutor
{
    private bool $inside=false;
    public function execute(CompiledQuery $query):int{return 1;}
    public function fetchOne(CompiledQuery $query):?array{return null;}
    public function fetchAll(CompiledQuery $query):array{return [];}
    public function fetchValue(CompiledQuery $query):mixed{return null;}
    public function inTransaction():bool{return $this->inside;}
    public function transaction(Closure $callback):mixed{$before=$this->inside;$this->inside=true;try{return $callback($this);}finally{$this->inside=$before;}}
}

final class BridgeChangeStore implements SearchIndexChangeStore
{
    public function record(string $documentType,string $documentId):void{}
    public function claimDue(DateTimeImmutable $now,int $limit,int $leaseSeconds=120):array{return [];}
    public function acknowledge(SearchIndexChange $change):bool{return true;}
    public function retry(SearchIndexChange $change,int $attempts,DateTimeImmutable $availableAt,string $errorCode):bool{return true;}
}

final readonly class BridgeAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param list<string> $ids */ public function __construct(private array $ids){}
    public function find(EntityId $userId):?UserAccessAssignment{return in_array($userId->value(),$this->ids,true)?new UserAccessAssignment($userId,EntityId::fromString(str_repeat('9',32))):null;}
}

final readonly class BridgePermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */ public function __construct(private array $permissions){}
    public function definition(PermissionKey $key):?PermissionDefinition{return new PermissionDefinition($key,PermissionValueType::Flag);}
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId):array
    {
        if($nodeId!==null||!in_array($key->value(),$this->permissions[$assignment->userId()->value()]??[],true))return [];
        return [new PermissionRule(PermissionSubjectType::User,$assignment->userId(),PermissionEffect::Allow)];
    }
}
