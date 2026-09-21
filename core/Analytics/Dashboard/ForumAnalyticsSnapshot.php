<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ForumAnalyticsSnapshot
{
    public DateTimeImmutable $generatedAt;

    /** @param list<ForumAnalyticsDailyRow> $daily */
    public function __construct(
        public int $windowDays,
        public int $dau,
        public int $mau,
        public int $activeAccounts,
        public int $registrationsToday,
        public int $registrationsWindow,
        public int $registrationsPreviousWindow,
        public int $threadsTotal,
        public int $threadsWindow,
        public int $threadsPreviousWindow,
        public int $postsTotal,
        public int $postsWindow,
        public int $postsPreviousWindow,
        public int $onlineNow,
        public int $onlinePeak24h,
        public int $onlinePeak7d,
        public ?float $retention7,
        public ?float $retention30,
        public array $daily,
        DateTimeImmutable $generatedAt,
    ){
        if($this->windowDays<7||$this->windowDays>90){
            throw new InvalidArgumentException('Forum analytics window must be 7..90 days.');
        }
        foreach([
            $this->dau,$this->mau,$this->activeAccounts,$this->registrationsToday,
            $this->registrationsWindow,$this->registrationsPreviousWindow,
            $this->threadsTotal,$this->threadsWindow,$this->threadsPreviousWindow,
            $this->postsTotal,$this->postsWindow,$this->postsPreviousWindow,
            $this->onlineNow,$this->onlinePeak24h,$this->onlinePeak7d,
        ] as $value){
            if($value<0)throw new InvalidArgumentException('Forum analytics counts cannot be negative.');
        }
        foreach([$this->retention7,$this->retention30] as $rate){
            if($rate!==null&&($rate<0.0||$rate>100.0)){
                throw new InvalidArgumentException('Forum analytics retention must be 0..100.');
            }
        }
        foreach($this->daily as $row){
            if(!$row instanceof ForumAnalyticsDailyRow){
                throw new InvalidArgumentException('Forum analytics daily row is invalid.');
            }
        }
        $this->generatedAt=$generatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function growthPercent(int $current,int $previous):?float
    {
        if($previous===0)return $current===0?0.0:null;
        return (($current-$previous)/$previous)*100.0;
    }
}
