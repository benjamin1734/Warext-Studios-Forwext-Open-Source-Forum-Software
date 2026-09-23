<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use InvalidArgumentException;

final readonly class AnalyticsEventDefinition
{
    /** @var array<string,true> */
    private array $allowedDimensions;

    /** @param list<string> $allowedDimensions */
    public function __construct(
        public string $key,
        public AnalyticsEventCategory $category,
        public int $retentionDays,
        public bool $collectActor,
        public bool $collectSession,
        public bool $collectSubject,
        public bool $collectForum,
        array $allowedDimensions=[],
        public bool $collectContent=false,
    ){
        if(preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D',$this->key)!==1){
            throw new InvalidArgumentException('Analytics event key is invalid.');
        }
        if($this->retentionDays<1||$this->retentionDays>3650){
            throw new InvalidArgumentException('Analytics retention period is invalid.');
        }
        $normalized=[];
        foreach($allowedDimensions as $dimension){
            if(preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$dimension)!==1){
                throw new InvalidArgumentException('Analytics dimension key is invalid.');
            }
            $normalized[$dimension]=true;
        }
        $this->allowedDimensions=$normalized;
    }

    public function allowsDimension(string $key):bool
    {
        return isset($this->allowedDimensions[$key]);
    }

    /** @return list<string> */
    public function dimensions():array
    {
        return array_keys($this->allowedDimensions);
    }
}
