<?php

declare(strict_types=1);

namespace Forwext\Core\Bug\Conversation;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Bug\Report\BugReportService;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class BugReportConversationService
{
    public const REPLY_OWN_PERMISSION='bug.report.reply_own';
    public const REPLY_ALL_PERMISSION='bug.report.reply_all';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private BugReportService $reports,
        private BugReportConversationRepository $conversation,
        private PermissionGate $gate,
        private BugReportNotifier $notifier=new NullBugReportNotifier(),
        private ?AuditRecorder $audit=null,
        private ?AuditRequestId $auditRequestId=null,
    ) {
    }

    public function view(EntityId $reportId): BugReportConversationView
    {
        $report=$this->reports->report($reportId);
        $staff=$this->gate->allows(PermissionKey::fromString(BugReportService::VIEW_ALL_PERMISSION));

        return new BugReportConversationView(
            $report,
            $this->conversation->messages($reportId),
            $this->reports->history($reportId),
            $staff,
        );
    }

    public function addReporterInfo(
        EntityId $reportId,
        string $body,
        ?DateTimeImmutable $now=null,
    ): BugReportMessage {
        $report=$this->reports->report($reportId);
        if(!$report->isReporter($this->gate->actorId())){
            throw new InvalidArgumentException('Only the bug reporter may add reporter information.');
        }
        $this->gate->require(PermissionKey::fromString(self::REPLY_OWN_PERMISSION));

        $message=new BugReportMessage(
            BugReportMessage::generateId(),
            $report->reportId,
            $this->gate->actorId(),
            BugReportMessageRole::Reporter,
            trim($body),
            self::utc($now),
        );

        $this->atomic(function () use ($message): void {
            $this->conversation->append($message);
            $this->appendAudit(
                'bug.report.reporter_reply',
                $message->reportId,
                [],
                ['message_id'=>$message->messageId->value(),'role'=>$message->authorRole->value],
                $message->createdAt,
            );
        });
        $this->safeNotify(fn()=> $this->notifier->reporterReply($report,$message));
        return $message;
    }

    public function staffReply(
        EntityId $reportId,
        string $body,
        ?DateTimeImmutable $now=null,
    ): BugReportMessage {
        $this->gate->require(PermissionKey::fromString(BugReportService::VIEW_ALL_PERMISSION));
        $this->gate->require(PermissionKey::fromString(self::REPLY_ALL_PERMISSION));
        $report=$this->reports->report($reportId);

        $message=new BugReportMessage(
            BugReportMessage::generateId(),
            $report->reportId,
            $this->gate->actorId(),
            BugReportMessageRole::Staff,
            trim($body),
            self::utc($now),
        );

        $this->atomic(function () use ($message): void {
            $this->conversation->append($message);
            $this->appendAudit(
                'bug.report.staff_reply',
                $message->reportId,
                [],
                ['message_id'=>$message->messageId->value(),'role'=>$message->authorRole->value],
                $message->createdAt,
            );
        });
        $this->safeNotify(fn()=> $this->notifier->staffReply($report,$message));
        return $message;
    }

    public function changeStatus(
        EntityId $reportId,
        BugReportStatus $status,
        ?DateTimeImmutable $now=null,
    ): BugReport {
        $before=$this->reports->report($reportId);
        $after=$this->reports->changeStatus($reportId,$status,$now);
        if($before->status!==$after->status){
            $this->safeNotify(fn()=> $this->notifier->statusChanged($after));
        }
        return $after;
    }

    /**
     * @param array<string,scalar|null> $before
     * @param array<string,scalar|null> $after
     */
    private function appendAudit(
        string $action,
        EntityId $reportId,
        array $before,
        array $after,
        DateTimeImmutable $at,
    ): void {
        if ($this->audit === null) {
            return;
        }
        $this->audit->append(new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Bug,
            $this->gate->actorId(),
            AuditAction::fromString($action),
            'bug.report',
            $reportId->value(),
            null,
            null,
            $this->auditRequestId ?? AuditRequestId::generate(),
            $before,
            $after,
            $at,
        ));
    }

    private function atomic(Closure $callback): mixed
    {
        return $this->database->inTransaction()
            ? $callback()
            : $this->database->transaction(static fn()=> $callback());
    }

    private function safeNotify(Closure $notify): void
    {
        try{
            $notify();
        }catch(Throwable){
            // Durable bug state is authoritative; notification delivery is an acceleration path.
        }
    }

    private static function utc(?DateTimeImmutable $now): DateTimeImmutable
    {
        return ($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
