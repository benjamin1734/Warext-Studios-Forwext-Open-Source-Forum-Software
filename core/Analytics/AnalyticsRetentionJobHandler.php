<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class AnalyticsRetentionJobHandler
{
    public function __construct(private AnalyticsEventRecorder $analytics){}

    public function jobType():string
    {
        return AnalyticsMaintenanceTasks::RETENTION_JOB_TYPE;
    }

    public function handle(string $payload,DateTimeImmutable $now):int
    {
        try{
            $decoded=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
        }catch(JsonException $exception){
            throw new RuntimeException('Analytics retention payload is invalid JSON.',previous:$exception);
        }

        $limit=is_array($decoded)?($decoded['limit_per_event']??1000):1000;
        if(!is_int($limit)||$limit<1||$limit>10000){
            throw new RuntimeException('Analytics retention per-event limit is invalid.');
        }

        return array_sum($this->analytics->pruneDue($now,$limit));
    }
}
