<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class RewardRetryJobHandler
{
    public function __construct(private RewardService $rewards){}

    public function jobType():string{return RewardMaintenanceTasks::RETRY_JOB_TYPE;}

    public function handle(string $payload,DateTimeImmutable $now):int
    {
        try{$decoded=json_decode($payload,true,8,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new RuntimeException('Reward retry payload is invalid JSON.',previous:$e);}
        if(!is_array($decoded))throw new RuntimeException('Reward retry payload must be an object.');
        $limit=$decoded['limit']??100;
        if(!is_int($limit)||$limit<1||$limit>500)throw new RuntimeException('Reward retry limit is invalid.');
        return $this->rewards->retryDue($limit,$now);
    }
}
