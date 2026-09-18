<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Reporting;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;

final readonly class DatabaseSupportReportingRepository implements SupportReportingRepository
{
    public function __construct(private TransactionalQueryExecutor $database) {}

    public function summary(DateTimeImmutable $now):SupportDashboardSummary
    {
        $at=self::format($now);
        $row=$this->database->fetchOne(new CompiledQuery(
            "SELECT COUNT(*) AS total,"
            . "COALESCE(SUM(status IN ('open','in_progress','waiting_requester')),0) AS active,"
            . "COALESCE(SUM(status='resolved'),0) AS resolved,"
            . "COALESCE(SUM(status='closed'),0) AS closed,"
            . "COALESCE(SUM(first_response_due_at_utc IS NOT NULL AND "
            . "((first_responded_at_utc IS NULL AND first_response_due_at_utc<:now1) OR first_responded_at_utc>first_response_due_at_utc)),0) AS first_breach,"
            . "COALESCE(SUM(resolution_due_at_utc IS NOT NULL AND "
            . "((resolved_at_utc IS NULL AND resolution_due_at_utc<:now2) OR resolved_at_utc>resolution_due_at_utc)),0) AS resolution_breach,"
            . "AVG(CASE WHEN first_responded_at_utc IS NOT NULL THEN TIMESTAMPDIFF(SECOND,created_at_utc,first_responded_at_utc) END) AS avg_first,"
            . "AVG(CASE WHEN resolved_at_utc IS NOT NULL THEN TIMESTAMPDIFF(SECOND,created_at_utc,resolved_at_utc) END) AS avg_resolution "
            . "FROM forwext_support_tickets",
            ['now1'=>$at,'now2'=>$at],
        ))??[];
        return new SupportDashboardSummary(
            (int)($row['total']??0),
            (int)($row['active']??0),
            (int)($row['resolved']??0),
            (int)($row['closed']??0),
            (int)($row['first_breach']??0),
            (int)($row['resolution_breach']??0),
            self::nullableFloat($row['avg_first']??null),
            self::nullableFloat($row['avg_resolution']??null),
        );
    }

    public function categoryMetrics(DateTimeImmutable $now,int $limit=100):array
    {
        if($limit<1||$limit>500) throw new \InvalidArgumentException('Support category metric limit is invalid.');
        $at=self::format($now);
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT c.category_key,c.label,COUNT(t.ticket_id) AS total,"
            . "COALESCE(SUM(t.status IN ('open','in_progress','waiting_requester')),0) AS active,"
            . "COALESCE(SUM(t.status IN ('resolved','closed')),0) AS resolved_closed,"
            . "COALESCE(SUM(t.first_response_due_at_utc IS NOT NULL AND "
            . "((t.first_responded_at_utc IS NULL AND t.first_response_due_at_utc<:now1) OR t.first_responded_at_utc>t.first_response_due_at_utc)),0) AS first_breach,"
            . "COALESCE(SUM(t.resolution_due_at_utc IS NOT NULL AND "
            . "((t.resolved_at_utc IS NULL AND t.resolution_due_at_utc<:now2) OR t.resolved_at_utc>t.resolution_due_at_utc)),0) AS resolution_breach,"
            . "AVG(CASE WHEN t.first_responded_at_utc IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.created_at_utc,t.first_responded_at_utc) END) AS avg_first,"
            . "AVG(CASE WHEN t.resolved_at_utc IS NOT NULL THEN TIMESTAMPDIFF(SECOND,t.created_at_utc,t.resolved_at_utc) END) AS avg_resolution "
            . "FROM forwext_support_categories c LEFT JOIN forwext_support_tickets t ON t.category_key=c.category_key "
            . "GROUP BY c.category_key,c.label,c.sort_order ORDER BY c.sort_order,c.category_key LIMIT ".$limit,
            ['now1'=>$at,'now2'=>$at],
        ));
        return array_map(static fn(array $row):SupportCategoryMetric=>new SupportCategoryMetric(
            (string)$row['category_key'],
            (string)$row['label'],
            (int)$row['total'],
            (int)$row['active'],
            (int)$row['resolved_closed'],
            (int)$row['first_breach'],
            (int)$row['resolution_breach'],
            self::nullableFloat($row['avg_first']??null),
            self::nullableFloat($row['avg_resolution']??null),
        ),$rows);
    }

    public function recentAudit(int $limit=50):array
    {
        if($limit<1||$limit>200) throw new \InvalidArgumentException('Support audit limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            "SELECT audit_id,actor_user_id,action,target_type,target_id,request_id,occurred_at_utc "
            . "FROM forwext_core_audit_events WHERE scope='support' "
            . "ORDER BY occurred_at_utc DESC,audit_id DESC LIMIT ".$limit,
        ));
        return array_map(static function(array $row):SupportAuditEntry{
            foreach(['audit_id','actor_user_id','action','target_type','target_id','request_id','occurred_at_utc'] as $key){
                if(!is_string($row[$key]??null)) throw new RuntimeException('Stored support audit row is invalid.');
            }
            return new SupportAuditEntry(
                EntityId::fromString((string)$row['audit_id']),
                UserId::fromStored((string)$row['actor_user_id']),
                (string)$row['action'],
                (string)$row['target_type'],
                (string)$row['target_id'],
                (string)$row['request_id'],
                self::parse((string)$row['occurred_at_utc']),
            );
        },$rows);
    }

    private static function nullableFloat(mixed $value):?float
    { return $value===null?null:(float)$value; }

    private static function format(DateTimeImmutable $value):string
    { return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }

    private static function parse(string $value):DateTimeImmutable
    {
        foreach(['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format){
            $time=DateTimeImmutable::createFromFormat($format,$value,new DateTimeZone('UTC'));
            if($time instanceof DateTimeImmutable) return $time;
        }
        throw new RuntimeException('Stored support reporting timestamp is invalid.');
    }
}
