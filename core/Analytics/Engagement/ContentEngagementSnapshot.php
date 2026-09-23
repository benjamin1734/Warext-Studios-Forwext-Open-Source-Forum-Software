<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Engagement;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ContentEngagementSnapshot
{
    public DateTimeImmutable $generatedAt;

    /**
     * @param list<array{id:string,title:string,threads:int,posts:int,reactions:int,views:int,watches:int}> $forums
     * @param list<array{id:string,title:string,forums:int,threads:int,posts:int,reactions:int,views:int,watches:int}> $categories
     * @param list<array{id:string,title:string,forum_title:string,replies:int,views:int,reactions:int,bookmarks:int,watches:int,watch_rate:?float,bookmark_rate:?float,reaction_rate:?float}> $threads
     * @param list<array{term:string,searches:int,zero_results:int,avg_results:float}> $searchTerms
     * @param list<array{id:string,username:string,follows:int}> $followLeaders
     */
    public function __construct(
        public int $windowDays,
        public int $reactionCount,
        public int $bookmarkCount,
        public int $watchedThreadCount,
        public int $watchedForumCount,
        public int $followCount,
        public int $searchCount,
        public int $redactedSearchCount,
        public int $zeroResultSearchCount,
        public array $forums,
        public array $categories,
        public array $threads,
        public array $searchTerms,
        DateTimeImmutable $generatedAt,
        public array $followLeaders=[],
    ){
        if(!in_array($this->windowDays,[7,30,90],true)){
            throw new InvalidArgumentException('Content engagement range must be 7, 30 or 90 days.');
        }
        foreach([
            $this->reactionCount,$this->bookmarkCount,$this->watchedThreadCount,
            $this->watchedForumCount,$this->followCount,$this->searchCount,
            $this->redactedSearchCount,$this->zeroResultSearchCount,
        ] as $value){
            if($value<0)throw new InvalidArgumentException('Content engagement count cannot be negative.');
        }
        $this->generatedAt=$generatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function rate(int $actions,int $views):?float
    {
        if($views<=0)return null;
        return ($actions/$views)*100.0;
    }
}
