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
use Forwext\Core\Faq\FaqOperationException;
use Forwext\Core\Faq\FaqRepository;
use Forwext\Core\Faq\FaqService;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FaqSystemTest extends TestCase
{
    public function testEffectiveVisibilityUsesMostRestrictiveLevel(): void
    {
        $article = $this->article(FaqVisibility::Public);
        $category = new FaqCategory(
            'general',
            'General',
            '',
            'tr',
            FaqVisibility::Staff,
            10,
            true,
        );

        self::assertSame(FaqVisibility::Staff, $article->effectiveVisibility($category));
    }

    public function testGuestCannotReadMemberFaqButMemberCanAndStaffCanReadStaffFaq(): void
    {
        $member = $this->id('1');
        $staff = $this->id('2');
        $repo = new FaqMemoryRepository();
        $repo->categories['general'] = new FaqCategory(
            'general','General','','tr',FaqVisibility::Members,10,true,
        );
        $article = $this->article(FaqVisibility::Public);
        $repo->articles[$article->articleId->value()] = $article;

        $service = $this->service(
            [
                $member->value()=>['faq.view'],
                $staff->value()=>['faq.view','faq.manage'],
            ],
            $repo,
            new FaqChangeStore(),
        );

        try {
            $service->article($article->articleId, null);
            self::fail('Guest must not see members-only FAQ.');
        } catch (FaqOperationException) {
            self::assertTrue(true);
        }

        self::assertSame($article->articleId->value(), $service->article($article->articleId,$member)->article->articleId->value());

        $repo->categories['general'] = new FaqCategory(
            'general','General','','tr',FaqVisibility::Staff,10,true,
        );
        try {
            $service->article($article->articleId,$member);
            self::fail('Member must not see staff-only FAQ.');
        } catch (FaqOperationException) {
            self::assertTrue(true);
        }
        self::assertSame($article->articleId->value(), $service->article($article->articleId,$staff)->article->articleId->value());
    }

    public function testSaveArticleRequiresMatchingCategoryLanguageAndQueuesSearchChange(): void
    {
        $staff = $this->id('2');
        $repo = new FaqMemoryRepository();
        $repo->categories['general'] = new FaqCategory(
            'general','General','','tr',FaqVisibility::Public,10,true,
        );
        $changes = new FaqChangeStore();
        $service = $this->service([$staff->value()=>['faq.manage']],$repo,$changes);

        $service->saveArticle($staff,$this->article(FaqVisibility::Public));
        self::assertSame([['faq.article',str_repeat('a',32)]],$changes->records);

        $invalid = new FaqArticle(
            $this->id('b'),
            'general',
            'english-article',
            'English?',
            'Answer',
            [],
            FaqVisibility::Public,
            'en',
            10,
            null,
            null,
            true,
            $this->now(),
            $this->now(),
        );

        $this->expectException(InvalidArgumentException::class);
        $service->saveArticle($staff,$invalid);
    }

    public function testHelpfulVoteIsPerUserAndExportDoesNotContainAnalyticsIdentity(): void
    {
        $member = $this->id('1');
        $staff = $this->id('2');
        $repo = new FaqMemoryRepository();
        $repo->categories['general'] = new FaqCategory(
            'general','General','','tr',FaqVisibility::Public,10,true,
        );
        $article = $this->article(FaqVisibility::Public);
        $repo->articles[$article->articleId->value()] = $article;
        $service = $this->service(
            [
                $member->value()=>['faq.view'],
                $staff->value()=>['faq.manage'],
            ],
            $repo,
            new FaqChangeStore(),
        );

        $summary = $service->voteHelpful($member,$article->articleId,true);
        self::assertSame(1,$summary->helpful);
        self::assertSame(0,$summary->notHelpful);

        $summary = $service->voteHelpful($member,$article->articleId,false);
        self::assertSame(0,$summary->helpful);
        self::assertSame(1,$summary->notHelpful);

        $json = $service->export($staff);
        self::assertStringContainsString('"format": "forwext-faq"',$json);
        self::assertStringNotContainsString($member->value(),$json);
        self::assertStringNotContainsString('helpful_votes',$json);
    }

    private function service(array $permissions,FaqMemoryRepository $repo,FaqChangeStore $changes): FaqService
    {
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine(new FaqPermissionRepository($permissions)),
            new FaqAssignmentProvider(array_keys($permissions)),
        );
        return new FaqService(new FaqTransactionDatabase(),$repo,$authorizer,$changes);
    }

    private function article(FaqVisibility $visibility): FaqArticle
    {
        return new FaqArticle(
            $this->id('a'),
            'general',
            'nasil-calisir',
            'Nasıl çalışır?',
            'Açıklama',
            ['yardim','genel'],
            $visibility,
            'tr',
            10,
            'SEO başlık',
            'SEO açıklama',
            true,
            $this->now(),
            $this->now(),
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed,32));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-18 18:00:00',new DateTimeZone('UTC'));
    }
}

final class FaqMemoryRepository implements FaqRepository
{
    /** @var array<string,FaqCategory> */
    public array $categories=[];
    /** @var array<string,FaqArticle> */
    public array $articles=[];
    /** @var array<string,array<string,bool>> */
    public array $votes=[];

    public function categories(): array { return array_values($this->categories); }
    public function category(string $key): ?FaqCategory { return $this->categories[$key]??null; }
    public function saveCategory(FaqCategory $category): void { $this->categories[$category->key]=$category; }
    public function articles(): array { return array_values($this->articles); }
    public function articlesByCategory(string $categoryKey): array
    {
        return array_values(array_filter(
            $this->articles,
            static fn(FaqArticle $article): bool=>$article->categoryKey===$categoryKey,
        ));
    }
    public function article(EntityId $articleId): ?FaqArticle { return $this->articles[$articleId->value()]??null; }
    public function articleBySlug(string $language,string $slug): ?FaqArticle
    {
        foreach($this->articles as $article){
            if($article->language===$language&&$article->slug===$slug) return $article;
        }
        return null;
    }
    public function saveArticle(FaqArticle $article): void { $this->articles[$article->articleId->value()]=$article; }
    public function recordHelpful(EntityId $articleId,EntityId $userId,bool $helpful): void
    {
        $this->votes[$articleId->value()][$userId->value()]=$helpful;
    }
    public function helpfulSummary(EntityId $articleId): FaqHelpfulSummary
    {
        $helpful=0;$not=0;
        foreach($this->votes[$articleId->value()]??[] as $value){
            $value?$helpful++:$not++;
        }
        return new FaqHelpfulSummary($helpful,$not);
    }
}

final class FaqChangeStore implements SearchIndexChangeStore
{
    /** @var list<array{string,string}> */
    public array $records=[];
    public function record(string $documentType,string $documentId): void { $this->records[]=[$documentType,$documentId]; }
    public function claimDue(DateTimeImmutable $now,int $limit,int $leaseSeconds=120): array { return []; }
    public function acknowledge(SearchIndexChange $change): bool { return true; }
    public function retry(SearchIndexChange $change,int $attempts,DateTimeImmutable $availableAt,string $errorCode): bool { return true; }
}

final class FaqTransactionDatabase implements TransactionalQueryExecutor
{
    private bool $inside=false;
    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->inside; }
    public function transaction(Closure $callback): mixed
    {
        $before=$this->inside;$this->inside=true;
        try{return $callback($this);}finally{$this->inside=$before;}
    }
}

final readonly class FaqAssignmentProvider implements UserAccessAssignmentProvider
{
    /** @param list<string> $ids */
    public function __construct(private array $ids){}
    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return in_array($userId->value(),$this->ids,true)
            ? new UserAccessAssignment($userId,EntityId::fromString(str_repeat('f',32)))
            : null;
    }
}

final readonly class FaqPermissionRepository implements PermissionRuleRepository
{
    /** @param array<string,list<string>> $permissions */
    public function __construct(private array $permissions){}
    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key,PermissionValueType::Flag);
    }
    public function rules(PermissionKey $key,UserAccessAssignment $assignment,?EntityId $nodeId): array
    {
        if($nodeId!==null||!in_array($key->value(),$this->permissions[$assignment->userId()->value()]??[],true)) return [];
        return [new PermissionRule(PermissionSubjectType::User,$assignment->userId(),PermissionEffect::Allow)];
    }
}
