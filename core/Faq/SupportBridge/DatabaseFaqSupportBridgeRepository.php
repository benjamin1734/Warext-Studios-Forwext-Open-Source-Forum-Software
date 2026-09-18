<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\SupportBridge;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseFaqSupportBridgeRepository implements FaqSupportBridgeRepository
{
    public function __construct(private TransactionalQueryExecutor $database) {}

    public function candidateArticleIds(string $supportCategoryKey,array $tokens,int $limit=100):array
    {
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$supportCategoryKey)!==1||$limit<1||$limit>200){
            throw new \InvalidArgumentException('FAQ support candidate query is invalid.');
        }
        $where=['a.category_key=:support_category','EXISTS (SELECT 1 FROM forwext_faq_article_tags st WHERE st.article_id=a.article_id AND st.tag_key=:support_tag)'];
        $params=['support_category'=>$supportCategoryKey,'support_tag'=>$supportCategoryKey];
        $index=0;
        foreach($tokens as $token){
            if(!is_string($token)||$token===''||strlen($token)>64) continue;
            $key='token_'.$index++;
            $where[]='(a.question LIKE :'.$key." ESCAPE '\\\\' OR a.answer LIKE :".$key." ESCAPE '\\\\' OR EXISTS (SELECT 1 FROM forwext_faq_article_tags tt WHERE tt.article_id=a.article_id AND tt.tag_key=:".$key.'_exact))';
            $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$token);
            $params[$key]='%'.$escaped.'%';
            $params[$key.'_exact']=$token;
            if($index>=24) break;
        }
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT DISTINCT a.article_id FROM forwext_faq_articles a '
            . 'INNER JOIN forwext_faq_categories c ON c.category_key=a.category_key '
            . 'WHERE a.active=1 AND c.active=1 AND ('.implode(' OR ',$where).') '
            . 'ORDER BY a.updated_at_utc DESC,a.article_id LIMIT '.$limit,
            $params,
        ));
        $ids=[];
        foreach($rows as $row){
            $id=$row['article_id']??null;
            if(!is_string($id)) throw new RuntimeException('Stored FAQ support candidate id is invalid.');
            $ids[]=EntityId::fromString($id);
        }
        return $ids;
    }

    public function create(FaqSupportDraftSuggestion $draft): void
    {
        $affected=$this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_faq_support_drafts '
            . '(draft_id,ticket_id,source_message_id,suggested_by_user_id,suggested_category_key,question,answer,status,created_at_utc,updated_at_utc) '
            . 'VALUES (:draft_id,:ticket_id,:source_message_id,:suggested_by_user_id,:suggested_category_key,:question,:answer,:status,:created_at,:updated_at)',
            [
                'draft_id'=>$draft->draftId->value(),
                'ticket_id'=>$draft->ticketId->value(),
                'source_message_id'=>$draft->sourceMessageId->value(),
                'suggested_by_user_id'=>$draft->suggestedByUserId?->value(),
                'suggested_category_key'=>$draft->suggestedCategoryKey,
                'question'=>$draft->question,
                'answer'=>$draft->answer,
                'status'=>$draft->status->value,
                'created_at'=>self::format($draft->createdAt),
                'updated_at'=>self::format($draft->createdAt),
            ],
            true,
        ));
        if($affected!==1) throw new RuntimeException('FAQ support draft was not persisted.');
    }

    public function find(EntityId $draftId): ?FaqSupportDraftSuggestion
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT draft_id,ticket_id,source_message_id,suggested_by_user_id,suggested_category_key,question,answer,status,created_at_utc '
            . 'FROM forwext_faq_support_drafts WHERE draft_id=:draft_id LIMIT 1',
            ['draft_id'=>$draftId->value()],
        ));
        return $row===null?null:$this->hydrate($row);
    }

    public function pending(int $limit=100): array
    {
        if($limit<1||$limit>500) throw new \InvalidArgumentException('FAQ support draft limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT draft_id,ticket_id,source_message_id,suggested_by_user_id,suggested_category_key,question,answer,status,created_at_utc "
            . "FROM forwext_faq_support_drafts WHERE status='pending' ORDER BY created_at_utc,draft_id LIMIT ".$limit,
        ));
        return array_map($this->hydrate(...),$rows);
    }

    public function updateStatus(EntityId $draftId,FaqSupportDraftStatus $status): void
    {
        $affected=$this->database->execute(new CompiledQuery(
            "UPDATE forwext_faq_support_drafts SET status=:status,updated_at_utc=UTC_TIMESTAMP(6) "
            . "WHERE draft_id=:draft_id AND status='pending'",
            ['status'=>$status->value,'draft_id'=>$draftId->value()],
            true,
        ));
        if($affected!==1) throw new RuntimeException('FAQ support draft is stale or unavailable.');
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): FaqSupportDraftSuggestion
    {
        try{$status=FaqSupportDraftStatus::from((string)$row['status']);}
        catch(ValueError $e){throw new RuntimeException('Stored FAQ support draft status is invalid.',previous:$e);}
        return new FaqSupportDraftSuggestion(
            EntityId::fromString((string)$row['draft_id']),
            EntityId::fromString((string)$row['ticket_id']),
            EntityId::fromString((string)$row['source_message_id']),
            $row['suggested_by_user_id']===null?null:UserId::fromStored((string)$row['suggested_by_user_id']),
            $row['suggested_category_key']===null?null:(string)$row['suggested_category_key'],
            (string)$row['question'],
            (string)$row['answer'],
            $status,
            self::parse((string)$row['created_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $v):string
    { return $v->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }

    private static function parse(string $v):DateTimeImmutable
    {
        foreach(['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $f){
            $d=DateTimeImmutable::createFromFormat($f,$v,new DateTimeZone('UTC'));
            if($d instanceof DateTimeImmutable) return $d;
        }
        throw new RuntimeException('Stored FAQ support draft timestamp is invalid.');
    }
}
