<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Dashboard;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseForumAnalyticsRepository
{
    public function __construct(private QueryExecutor $database){}

    public function snapshot(int $days,?DateTimeImmutable $now=null):ForumAnalyticsSnapshot
    {
        if($days<7||$days>90)throw new InvalidArgumentException('Forum analytics window must be 7..90 days.');

        $utc=new DateTimeZone('UTC');
        $now=($now??new DateTimeImmutable('now',$utc))->setTimezone($utc);
        $today=$now->setTime(0,0);
        $tomorrow=$today->add(new DateInterval('P1D'));
        $windowStart=$today->sub(new DateInterval('P'.($days-1).'D'));
        $previousStart=$windowStart->sub(new DateInterval('P'.$days.'D'));
        $monthStart=$today->sub(new DateInterval('P29D'));

        $activeAccounts=$this->count("SELECT COUNT(*) FROM forwext_users WHERE status='active'");
        $registrationsToday=$this->countRange('forwext_users','created_at_utc',$today,$tomorrow);
        $registrationsWindow=$this->countRange('forwext_users','created_at_utc',$windowStart,$tomorrow);
        $registrationsPrevious=$this->countRange('forwext_users','created_at_utc',$previousStart,$windowStart);

        $threadFilter="deleted=0 AND moderation_state='visible' AND merged_into_thread_id IS NULL";
        $threadsTotal=$this->count('SELECT COUNT(*) FROM forwext_threads WHERE '.$threadFilter);
        $threadsWindow=$this->countRange('forwext_threads','created_at_utc',$windowStart,$tomorrow,$threadFilter);
        $threadsPrevious=$this->countRange('forwext_threads','created_at_utc',$previousStart,$windowStart,$threadFilter);

        $postFilter="p.deleted=0 AND p.moderation_state='visible' "
            ."AND t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL";
        $postsTotal=$this->count(
            'SELECT COUNT(*) FROM forwext_posts p INNER JOIN forwext_threads t ON t.thread_id=p.thread_id '
            .'WHERE '.$postFilter
        );
        $postsWindow=$this->postCountRange($windowStart,$tomorrow,$postFilter);
        $postsPrevious=$this->postCountRange($previousStart,$windowStart,$postFilter);

        $dau=$this->distinctActive($today,$tomorrow);
        $mau=$this->distinctActive($monthStart,$tomorrow);
        $onlineNow=$this->count(
            "SELECT COUNT(*) FROM forwext_user_presence p INNER JOIN forwext_users u ON u.user_id=p.user_id "
            ."WHERE u.status='active' AND p.last_seen_at_utc>=:since",
            ['since'=>self::format($now->sub(new DateInterval('PT300S')))]
        );

        $daily=$this->daily($windowStart,$tomorrow);
        $retention7=$this->retention($today->sub(new DateInterval('P7D')),$today,$tomorrow);
        $retention30=$this->retention($today->sub(new DateInterval('P30D')),$today,$tomorrow);

        return new ForumAnalyticsSnapshot(
            $days,
            $dau,
            $mau,
            $activeAccounts,
            $registrationsToday,
            $registrationsWindow,
            $registrationsPrevious,
            $threadsTotal,
            $threadsWindow,
            $threadsPrevious,
            $postsTotal,
            $postsWindow,
            $postsPrevious,
            $onlineNow,
            $this->onlinePeak($now->sub(new DateInterval('PT24H')),$now),
            $this->onlinePeak($now->sub(new DateInterval('P7D')),$now),
            $retention7,
            $retention30,
            $daily,
            $now,
        );
    }

    /** @return list<ForumAnalyticsDailyRow> */
    private function daily(DateTimeImmutable $start,DateTimeImmutable $end):array
    {
        $rows=[];
        for($day=$start;$day<$end;$day=$day->add(new DateInterval('P1D'))){
            $rows[$day->format('Y-m-d')]=[
                'day'=>$day,'registrations'=>0,'threads'=>0,'posts'=>0,'active_users'=>0,
            ];
        }

        foreach($this->dailyCount('forwext_users','created_at_utc',$start,$end) as $day=>$count){
            if(isset($rows[$day]))$rows[$day]['registrations']=$count;
        }

        foreach($this->database->fetchAll(new CompiledQuery(
            "SELECT DATE(created_at_utc) AS event_day,COUNT(*) AS aggregate_count FROM forwext_threads "
            ."WHERE created_at_utc>=:start AND created_at_utc<:end "
            ."AND deleted=0 AND moderation_state='visible' AND merged_into_thread_id IS NULL "
            .'GROUP BY DATE(created_at_utc) ORDER BY event_day',
            ['start'=>self::format($start),'end'=>self::format($end)]
        )) as $row){
            $day=(string)$row['event_day'];
            if(isset($rows[$day]))$rows[$day]['threads']=(int)$row['aggregate_count'];
        }

        foreach($this->database->fetchAll(new CompiledQuery(
            "SELECT DATE(p.created_at_utc) AS event_day,COUNT(*) AS aggregate_count FROM forwext_posts p "
            ."INNER JOIN forwext_threads t ON t.thread_id=p.thread_id "
            ."WHERE p.created_at_utc>=:start AND p.created_at_utc<:end "
            ."AND p.deleted=0 AND p.moderation_state='visible' "
            ."AND t.deleted=0 AND t.moderation_state='visible' AND t.merged_into_thread_id IS NULL "
            .'GROUP BY DATE(p.created_at_utc) ORDER BY event_day',
            ['start'=>self::format($start),'end'=>self::format($end)]
        )) as $row){
            $day=(string)$row['event_day'];
            if(isset($rows[$day]))$rows[$day]['posts']=(int)$row['aggregate_count'];
        }

        foreach($this->database->fetchAll(new CompiledQuery(
            "SELECT event_day_utc AS event_day,COUNT(DISTINCT actor_hash) AS aggregate_count "
            ."FROM forwext_analytics_events WHERE event_key='user.active' AND actor_hash IS NOT NULL "
            ."AND occurred_at_utc>=:start AND occurred_at_utc<:end "
            .'GROUP BY event_day_utc ORDER BY event_day_utc',
            ['start'=>self::format($start),'end'=>self::format($end)]
        )) as $row){
            $day=(string)$row['event_day'];
            if(isset($rows[$day]))$rows[$day]['active_users']=(int)$row['aggregate_count'];
        }

        return array_map(
            static fn(array $row):ForumAnalyticsDailyRow=>new ForumAnalyticsDailyRow(
                $row['day'],$row['registrations'],$row['threads'],$row['posts'],$row['active_users']
            ),
            array_values($rows)
        );
    }

    private function distinctActive(DateTimeImmutable $start,DateTimeImmutable $end):int
    {
        return $this->count(
            "SELECT COUNT(DISTINCT actor_hash) FROM forwext_analytics_events "
            ."WHERE event_key='user.active' AND actor_hash IS NOT NULL "
            .'AND occurred_at_utc>=:start AND occurred_at_utc<:end',
            ['start'=>self::format($start),'end'=>self::format($end)]
        );
    }

    private function retention(
        DateTimeImmutable $cohortDay,
        DateTimeImmutable $today,
        DateTimeImmutable $tomorrow,
    ):?float{
        $cohortEnd=$cohortDay->add(new DateInterval('P1D'));
        $cohort=$this->distinctActive($cohortDay,$cohortEnd);
        if($cohort===0)return null;

        $retained=$this->count(
            "SELECT COUNT(DISTINCT old.actor_hash) FROM forwext_analytics_events old "
            ."WHERE old.event_key='user.active' AND old.actor_hash IS NOT NULL "
            .'AND old.occurred_at_utc>=:cohort_start AND old.occurred_at_utc<:cohort_end '
            ."AND EXISTS (SELECT 1 FROM forwext_analytics_events current_event "
            ."WHERE current_event.event_key='user.active' AND current_event.actor_hash=old.actor_hash "
            .'AND current_event.occurred_at_utc>=:today_start AND current_event.occurred_at_utc<:today_end)',
            [
                'cohort_start'=>self::format($cohortDay),
                'cohort_end'=>self::format($cohortEnd),
                'today_start'=>self::format($today),
                'today_end'=>self::format($tomorrow),
            ]
        );

        return min(100.0,max(0.0,($retained/$cohort)*100.0));
    }

    private function onlinePeak(DateTimeImmutable $start,DateTimeImmutable $end):int
    {
        return $this->count(
            "SELECT COALESCE(MAX(bucket_count),0) FROM ("
            ."SELECT FLOOR(UNIX_TIMESTAMP(occurred_at_utc)/300) AS bucket_id,"
            ."COUNT(DISTINCT actor_hash) AS bucket_count FROM forwext_analytics_events "
            ."WHERE event_key='user.active' AND actor_hash IS NOT NULL "
            .'AND occurred_at_utc>=:start AND occurred_at_utc<:end GROUP BY bucket_id'
            .') AS online_buckets',
            ['start'=>self::format($start),'end'=>self::format($end)]
        );
    }

    /** @return array<string,int> */
    private function dailyCount(
        string $table,string $column,DateTimeImmutable $start,DateTimeImmutable $end
    ):array{
        $allowed=[
            'forwext_users'=>['created_at_utc'],
        ];
        if(!isset($allowed[$table])||!in_array($column,$allowed[$table],true)){
            throw new InvalidArgumentException('Forum analytics daily source is invalid.');
        }
        $result=[];
        foreach($this->database->fetchAll(new CompiledQuery(
            'SELECT DATE('.$column.') AS event_day,COUNT(*) AS aggregate_count FROM '.$table.' '
            .'WHERE '.$column.'>=:start AND '.$column.'<:end GROUP BY DATE('.$column.') ORDER BY event_day',
            ['start'=>self::format($start),'end'=>self::format($end)]
        )) as $row){
            $result[(string)$row['event_day']]=(int)$row['aggregate_count'];
        }
        return $result;
    }

    private function countRange(
        string $table,
        string $column,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?string $extraWhere=null,
    ):int{
        $allowed=[
            'forwext_users'=>['created_at_utc'],
            'forwext_threads'=>['created_at_utc'],
        ];
        if(!isset($allowed[$table])||!in_array($column,$allowed[$table],true)){
            throw new InvalidArgumentException('Forum analytics range source is invalid.');
        }
        return $this->count(
            'SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'>=:start AND '.$column.'<:end'
            .($extraWhere===null?'':' AND '.$extraWhere),
            ['start'=>self::format($start),'end'=>self::format($end)]
        );
    }

    private function postCountRange(DateTimeImmutable $start,DateTimeImmutable $end,string $filter):int
    {
        return $this->count(
            'SELECT COUNT(*) FROM forwext_posts p INNER JOIN forwext_threads t ON t.thread_id=p.thread_id '
            .'WHERE p.created_at_utc>=:start AND p.created_at_utc<:end AND '.$filter,
            ['start'=>self::format($start),'end'=>self::format($end)]
        );
    }

    /** @param array<string,mixed> $parameters */
    private function count(string $sql,array $parameters=[]):int
    {
        return max(0,(int)$this->database->fetchValue(new CompiledQuery($sql,$parameters)));
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
