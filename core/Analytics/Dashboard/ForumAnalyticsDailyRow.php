<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ForumAnalyticsDailyRow
{
    public DateTimeImmutable $day;

    public function __construct(
        DateTimeImmutable $day,
        public int $registrations,
        public int $threads,
        public int $posts,
        public int $activeUsers,
    ){
        foreach([$this->registrations,$this->threads,$this->posts,$this->activeUsers] as $value){
            if($value<0)throw new InvalidArgumentException('Forum analytics daily counts cannot be negative.');
        }
        $this->day=$day->setTimezone(new DateTimeZone('UTC'))->setTime(0,0);
    }
}
