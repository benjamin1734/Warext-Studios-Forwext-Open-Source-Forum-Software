<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Engagement;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\AnalyticsEvent;
use Forwext\Core\Analytics\AnalyticsEventRecorder;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretKey;
use Throwable;

final readonly class SearchAnalyticsService
{
    public function __construct(
        private AnalyticsEventRecorder $analytics,
        private DatabaseSearchTermAnalyticsRepository $terms,
        private SearchTermPolicy $policy,
        private SecretKey $termKey,
    ){}

    public function recordBestEffort(
        EntityId $actor,
        string $query,
        int $resultCount,
        ?DateTimeImmutable $at=null,
    ):bool{
        try{
            if($resultCount<0||$resultCount>1000000)return false;
            $at=($at??new DateTimeImmutable('now',new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));

            $class=$this->policy->queryClass($query);
            $this->analytics->recordBestEffort(new AnalyticsEvent(
                'forum.search',
                actorUserId:$actor,
                dimensions:[
                    'query_class'=>$class,
                    'result_bucket'=>$this->policy->resultBucket($resultCount),
                ],
                occurredAt:$at,
            ));

            $display=$this->policy->safeDisplay($query);
            if($display!==null){
                $key=hash_hmac(
                    'sha256',
                    "search-term\0".$display,
                    $this->termKey->bytesForCrypto(),
                );
                $this->terms->record($key,$display,$resultCount,$at);
            }
            return true;
        }catch(Throwable){
            return false;
        }
    }
}
