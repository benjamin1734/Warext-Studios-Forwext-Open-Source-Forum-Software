<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseAnalyticsRepository implements AnalyticsRepository
{
    public function __construct(private QueryExecutor $database){}

    public function append(AnalyticsStoredEvent $event):void
    {
        try{
            $dimensions=json_encode(
                $event->dimensions,
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
            );
        }catch(JsonException $exception){
            throw new InvalidArgumentException('Analytics dimensions cannot be encoded.',previous:$exception);
        }

        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_analytics_events '
            . '(event_id,event_key,category,actor_hash,session_hash,subject_type,subject_hash,forum_id,content_type,content_id,dimensions_json,'
            . 'occurred_at_utc,event_day_utc,recorded_at_utc) '
            . 'VALUES (:event,:key,:category,:actor,:session,:subject_type,:subject_hash,:forum,:content_type,:content_id,:dimensions,'
            . ':occurred,:event_day,UTC_TIMESTAMP(6))',
            [
                'event'=>$event->eventId->value(),
                'key'=>$event->definition->key,
                'category'=>$event->definition->category->value,
                'actor'=>$event->actorHash,
                'session'=>$event->sessionHash,
                'subject_type'=>$event->subjectType,
                'subject_hash'=>$event->subjectHash,
                'forum'=>$event->forumId?->value(),
                'content_type'=>$event->contentType,
                'content_id'=>$event->contentId?->value(),
                'dimensions'=>$dimensions,
                'occurred'=>self::format($event->occurredAt),
                'event_day'=>$event->occurredAt->format('Y-m-d'),
            ]
        ));
    }

    public function prune(string $eventKey,DateTimeImmutable $before,int $limit=5000):int
    {
        if(preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D',$eventKey)!==1||$limit<1||$limit>50000){
            throw new InvalidArgumentException('Analytics prune request is invalid.');
        }
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_analytics_events WHERE event_key=:event_key '
            . 'AND occurred_at_utc<:before ORDER BY occurred_at_utc,event_id LIMIT '.$limit,
            ['event_key'=>$eventKey,'before'=>self::format($before)]
        ));
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
