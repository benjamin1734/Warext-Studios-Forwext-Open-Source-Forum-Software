<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseBugReportConversationRepository implements BugReportConversationRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function append(BugReportMessage $message): void
    {
        $affected=$this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_bug_report_messages '
            . '(message_id,report_id,author_user_id,author_role,body,created_at_utc) '
            . 'VALUES (:message_id,:report_id,:author_user_id,:author_role,:body,:created_at)',
            [
                'message_id'=>$message->messageId->value(),
                'report_id'=>$message->reportId->value(),
                'author_user_id'=>$message->authorUserId?->value(),
                'author_role'=>$message->authorRole->value,
                'body'=>$message->body,
                'created_at'=>$message->createdAt->format('Y-m-d H:i:s.u'),
            ],
            true,
        ));
        if($affected!==1){
            throw new RuntimeException('Bug report message was not persisted.');
        }
    }

    public function messages(EntityId $reportId,int $limit=200): array
    {
        if($limit<1||$limit>500){
            throw new RuntimeException('Bug report message limit is invalid.');
        }
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT message_id,report_id,author_user_id,author_role,body,created_at_utc '
            . 'FROM forwext_bug_report_messages WHERE report_id=:report_id '
            . 'ORDER BY created_at_utc,message_id LIMIT '.$limit,
            ['report_id'=>$reportId->value()],
        ));
        return array_map(static function(array $row): BugReportMessage {
            try{
                $role=BugReportMessageRole::from((string)$row['author_role']);
            }catch(ValueError $exception){
                throw new RuntimeException('Stored bug report message role is invalid.',previous:$exception);
            }
            $time=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',(string)$row['created_at_utc'],new DateTimeZone('UTC'));
            if(!$time instanceof DateTimeImmutable){
                throw new RuntimeException('Stored bug report message timestamp is invalid.');
            }
            return new BugReportMessage(
                EntityId::fromString((string)$row['message_id']),
                EntityId::fromString((string)$row['report_id']),
                $row['author_user_id']===null?null:UserId::fromStored((string)$row['author_user_id']),
                $role,
                (string)$row['body'],
                $time,
            );
        },$rows);
    }
}
