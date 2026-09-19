<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class TrophyEvaluationJobHandler
{
    public function __construct(private TrophyService $trophies)
    {
    }

    public function jobType():string
    {
        return TrophyMaintenanceTasks::EVALUATE_JOB_TYPE;
    }

    public function handle(string $payload,DateTimeImmutable $now):int
    {
        try{$decoded=json_decode($payload,true,8,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new RuntimeException('Trophy evaluation payload is invalid JSON.',previous:$e);}
        if(!is_array($decoded)) throw new RuntimeException('Trophy evaluation payload must be an object.');
        $limit=$decoded['limit']??200;
        if(!is_int($limit)||$limit<1||$limit>500) throw new RuntimeException('Trophy evaluation limit is invalid.');
        return $this->trophies->evaluateBatch($limit,$now);
    }
}
