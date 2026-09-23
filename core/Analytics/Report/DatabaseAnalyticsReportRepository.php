<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class DatabaseAnalyticsReportRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    /** @return list<AnalyticsSavedReport> */
    public function savedReports(?EntityId $ownerUserId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Analytics saved report limit is invalid.');
        }
        $where = '';
        $parameters = [];
        if ($ownerUserId !== null) {
            UserId::assert($ownerUserId);
            $where = ' WHERE owner_user_id=:owner';
            $parameters['owner'] = $ownerUserId->value();
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT report_id,owner_user_id,name,dataset,start_date,end_date,filters_json,privacy_min_count,created_at_utc,updated_at_utc '
            . 'FROM forwext_analytics_saved_reports'.$where
            . ' ORDER BY updated_at_utc DESC,report_id DESC LIMIT '.$limit,
            $parameters,
        ));
        return array_map($this->hydrateSaved(...), $rows);
    }

    public function savedReport(EntityId $reportId): ?AnalyticsSavedReport
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT report_id,owner_user_id,name,dataset,start_date,end_date,filters_json,privacy_min_count,created_at_utc,updated_at_utc '
            . 'FROM forwext_analytics_saved_reports WHERE report_id=:id LIMIT 1',
            ['id'=>$reportId->value()],
        ));
        return $row === null ? null : $this->hydrateSaved($row);
    }

    public function save(AnalyticsSavedReport $report): void
    {
        try {
            $filters = json_encode($report->definition->filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Analytics report filters cannot be encoded.', previous:$exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_analytics_saved_reports '
            . '(report_id,owner_user_id,name,dataset,start_date,end_date,filters_json,privacy_min_count,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:owner,:name,:dataset,:start_date,:end_date,:filters,:privacy,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE owner_user_id=VALUES(owner_user_id),name=VALUES(name),dataset=VALUES(dataset),'
            . 'start_date=VALUES(start_date),end_date=VALUES(end_date),filters_json=VALUES(filters_json),'
            . 'privacy_min_count=VALUES(privacy_min_count),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$report->reportId->value(),
                'owner'=>$report->ownerUserId->value(),
                'name'=>$report->name,
                'dataset'=>$report->definition->dataset->value,
                'start_date'=>$report->definition->from->format('Y-m-d'),
                'end_date'=>$report->definition->to->format('Y-m-d'),
                'filters'=>$filters,
                'privacy'=>$report->definition->privacyMinCount,
                'created'=>self::format($report->createdAt),
                'updated'=>self::format($report->updatedAt),
            ],
            true,
        ));
        if ($affected < 1) {
            throw new RuntimeException('Analytics saved report was not persisted.');
        }
    }

    public function delete(EntityId $reportId): bool
    {
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_analytics_saved_reports WHERE report_id=:id',
            ['id'=>$reportId->value()],
            true,
        )) === 1;
    }

    public function run(AnalyticsReportDefinition $definition): AnalyticsReportResult
    {
        [$columns, $rows] = match ($definition->dataset) {
            AnalyticsReportDataset::ForumActivity => $this->forumActivity($definition),
            AnalyticsReportDataset::ContentActivity => $this->contentActivity($definition),
            AnalyticsReportDataset::OperationsActivity => $this->operationsActivity($definition),
            AnalyticsReportDataset::CommerceOrders => $this->commerceOrders($definition),
            AnalyticsReportDataset::ReferralFunnel => $this->referralFunnel($definition),
            AnalyticsReportDataset::GiveawayParticipation => $this->giveawayParticipation($definition),
        };

        $visible = [];
        $suppressed = 0;
        foreach ($rows as $row) {
            $count = (int) ($row['count'] ?? 0);
            if ($count < $definition->privacyMinCount) {
                ++$suppressed;
                continue;
            }
            $visible[] = $row;
        }

        return new AnalyticsReportResult(
            $definition,
            $columns,
            $visible,
            $suppressed,
            $definition->privacyMinCount,
        );
    }

    /** @return array{0:list<string>,1:list<array<string,int|float|string|null>>} */
    private function forumActivity(AnalyticsReportDefinition $definition): array
    {
        $params = $this->window($definition);
        $where = ["category IN ('forum','user')", 'occurred_at_utc>=:from', 'occurred_at_utc<:to'];
        $this->filter($definition, 'event_key', 'event_key', $where, $params);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT DATE(occurred_at_utc) AS day,event_key,COUNT(*) AS count '
            . 'FROM forwext_analytics_events WHERE '.implode(' AND ', $where)
            . ' GROUP BY DATE(occurred_at_utc),event_key ORDER BY day,event_key LIMIT 5000',
            $params,
        ));
        return [['day','event_key','count'], array_map(static fn(array $row):array=>[
            'day'=>(string)$row['day'],
            'event_key'=>(string)$row['event_key'],
            'count'=>(int)$row['count'],
        ], $rows)];
    }

    /** @return array{0:list<string>,1:list<array<string,int|float|string|null>>} */
    private function contentActivity(AnalyticsReportDefinition $definition): array
    {
        $params = $this->window($definition);
        $where = ["category='content'", 'occurred_at_utc>=:from', 'occurred_at_utc<:to'];
        $this->filter($definition, 'event_key', 'event_key', $where, $params);
        $this->filter($definition, 'content_type', 'content_type', $where, $params);
        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT DATE(occurred_at_utc) AS day,event_key,COALESCE(content_type,'') AS content_type,COUNT(*) AS count "
            . 'FROM forwext_analytics_events WHERE '.implode(' AND ', $where)
            . " GROUP BY DATE(occurred_at_utc),event_key,COALESCE(content_type,'') "
            . 'ORDER BY day,event_key,content_type LIMIT 5000',
            $params,
        ));
        return [['day','event_key','content_type','count'], array_map(static fn(array $row):array=>[
            'day'=>(string)$row['day'],
            'event_key'=>(string)$row['event_key'],
            'content_type'=>(string)$row['content_type'],
            'count'=>(int)$row['count'],
        ], $rows)];
    }

    /** @return array{0:list<string>,1:list<array<string,int|float|string|null>>} */
    private function operationsActivity(AnalyticsReportDefinition $definition): array
    {
        $params = $this->window($definition);
        $where = ["scope IN ('moderation','support','bug')", 'occurred_at_utc>=:from', 'occurred_at_utc<:to'];
        $this->filter($definition, 'scope', 'scope', $where, $params);
        $this->filter($definition, 'action', 'action', $where, $params);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT DATE(occurred_at_utc) AS day,scope,action,COUNT(*) AS count '
            . 'FROM forwext_core_audit_events WHERE '.implode(' AND ', $where)
            . ' GROUP BY DATE(occurred_at_utc),scope,action ORDER BY day,scope,action LIMIT 5000',
            $params,
        ));
        return [['day','scope','action','count'], array_map(static fn(array $row):array=>[
            'day'=>(string)$row['day'],
            'scope'=>(string)$row['scope'],
            'action'=>(string)$row['action'],
            'count'=>(int)$row['count'],
        ], $rows)];
    }

    /** @return array{0:list<string>,1:list<array<string,int|float|string|null>>} */
    private function commerceOrders(AnalyticsReportDefinition $definition): array
    {
        $params = $this->window($definition);
        $where = ['created_at_utc>=:from', 'created_at_utc<:to'];
        $this->filter($definition, 'currency', 'currency', $where, $params);
        $this->filter($definition, 'order_state', 'order_state', $where, $params);
        $this->filter($definition, 'payment_state', 'payment_state', $where, $params);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT DATE(created_at_utc) AS day,currency,order_state,payment_state,COUNT(*) AS count,'
            . 'COALESCE(SUM(total_minor),0) AS value_minor FROM forwext_marketplace_orders '
            . 'WHERE '.implode(' AND ', $where)
            . ' GROUP BY DATE(created_at_utc),currency,order_state,payment_state '
            . 'ORDER BY day,currency,order_state,payment_state LIMIT 5000',
            $params,
        ));
        return [['day','currency','order_state','payment_state','count','value_minor'], array_map(static fn(array $row):array=>[
            'day'=>(string)$row['day'],
            'currency'=>(string)$row['currency'],
            'order_state'=>(string)$row['order_state'],
            'payment_state'=>(string)$row['payment_state'],
            'count'=>(int)$row['count'],
            'value_minor'=>(int)$row['value_minor'],
        ], $rows)];
    }

    /** @return array{0:list<string>,1:list<array<string,int|float|string|null>>} */
    private function referralFunnel(AnalyticsReportDefinition $definition): array
    {
        $params = $this->window($definition);
        $where = ['a.attributed_at_utc>=:from', 'a.attributed_at_utc<:to'];
        $this->filter($definition, 'campaign', 'c.campaign_key', $where, $params);
        $this->filter($definition, 'state', 'a.state', $where, $params);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT DATE(a.attributed_at_utc) AS day,c.campaign_key,a.state,COUNT(*) AS count '
            . 'FROM forwext_referral_attributions a INNER JOIN forwext_referral_campaigns c ON c.campaign_id=a.campaign_id '
            . 'WHERE '.implode(' AND ', $where)
            . ' GROUP BY DATE(a.attributed_at_utc),c.campaign_key,a.state '
            . 'ORDER BY day,c.campaign_key,a.state LIMIT 5000',
            $params,
        ));
        return [['day','campaign_key','state','count'], array_map(static fn(array $row):array=>[
            'day'=>(string)$row['day'],
            'campaign_key'=>(string)$row['campaign_key'],
            'state'=>(string)$row['state'],
            'count'=>(int)$row['count'],
        ], $rows)];
    }

    /** @return array{0:list<string>,1:list<array<string,int|float|string|null>>} */
    private function giveawayParticipation(AnalyticsReportDefinition $definition): array
    {
        $params = $this->window($definition);
        $where = ['e.entered_at_utc>=:from', 'e.entered_at_utc<:to'];
        $this->filter($definition, 'state', 'g.state', $where, $params);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT DATE(e.entered_at_utc) AS day,g.title,g.state,COUNT(DISTINCT e.user_id) AS count,'
            . 'COALESCE(SUM(e.entry_count),0) AS entries FROM forwext_giveaway_entries e '
            . 'INNER JOIN forwext_giveaways g ON g.giveaway_id=e.giveaway_id '
            . 'WHERE '.implode(' AND ', $where)
            . ' GROUP BY DATE(e.entered_at_utc),g.giveaway_id,g.title,g.state '
            . 'ORDER BY day,g.title,g.giveaway_id LIMIT 5000',
            $params,
        ));
        return [['day','title','state','count','entries'], array_map(static fn(array $row):array=>[
            'day'=>(string)$row['day'],
            'title'=>(string)$row['title'],
            'state'=>(string)$row['state'],
            'count'=>(int)$row['count'],
            'entries'=>(int)$row['entries'],
        ], $rows)];
    }

    /**
     * @param list<string> $where
     * @param array<string,mixed> $params
     */
    private function filter(
        AnalyticsReportDefinition $definition,
        string $key,
        string $column,
        array &$where,
        array &$params,
    ): void {
        $value = $definition->filters[$key] ?? null;
        if ($value === null) {
            return;
        }
        $allowedColumns = [
            'event_key','content_type','scope','action','currency','order_state','payment_state',
            'c.campaign_key','a.state','g.state',
        ];
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Analytics report filter column is invalid.');
        }
        $parameter = 'filter_'.$key;
        $where[] = $column.'=:'.$parameter;
        $params[$parameter] = $value;
    }

    /** @return array{from:string,to:string} */
    private function window(AnalyticsReportDefinition $definition): array
    {
        return [
            'from'=>self::format($definition->from),
            'to'=>self::format($definition->exclusiveEnd()),
        ];
    }

    /** @param array<string,mixed> $row */
    private function hydrateSaved(array $row): AnalyticsSavedReport
    {
        try {
            $filters = json_decode((string)$row['filters_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored analytics report filters are invalid.', previous:$exception);
        }
        if (!is_array($filters)) {
            throw new RuntimeException('Stored analytics report filters are invalid.');
        }
        $stringFilters = [];
        foreach ($filters as $key=>$value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Stored analytics report filter value is invalid.');
            }
            $stringFilters[$key] = $value;
        }

        return new AnalyticsSavedReport(
            EntityId::fromString((string)$row['report_id']),
            EntityId::fromString((string)$row['owner_user_id']),
            (string)$row['name'],
            new AnalyticsReportDefinition(
                AnalyticsReportDataset::from((string)$row['dataset']),
                self::date((string)$row['start_date']),
                self::date((string)$row['end_date']),
                $stringFilters,
                (int)$row['privacy_min_count'],
            ),
            self::time((string)$row['created_at_utc']),
            self::time((string)$row['updated_at_utc']),
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored analytics report date is invalid.');
        }
        return $date;
    }

    private static function time(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }
        throw new RuntimeException('Stored analytics saved report timestamp is invalid.');
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
