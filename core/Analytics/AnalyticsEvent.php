<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AnalyticsEvent
{
    public DateTimeImmutable $occurredAt;

    /**
     * @param array<string,string|int|float|bool|null> $dimensions
     */
    public function __construct(
        public string $key,
        public ?EntityId $actorUserId=null,
        public ?string $sessionId=null,
        public ?string $subjectType=null,
        public ?EntityId $subjectId=null,
        public ?EntityId $forumId=null,
        public array $dimensions=[],
        ?DateTimeImmutable $occurredAt=null,
    ){
        if(preg_match('/^[a-z][a-z0-9_.-]{2,95}$/D',$this->key)!==1){
            throw new InvalidArgumentException('Analytics event key is invalid.');
        }
        if(($this->subjectType===null)!==($this->subjectId===null)){
            throw new InvalidArgumentException('Analytics subject type and id must be provided together.');
        }
        if($this->subjectType!==null&&preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$this->subjectType)!==1){
            throw new InvalidArgumentException('Analytics subject type is invalid.');
        }
        if($this->sessionId!==null&&($this->sessionId===''||strlen($this->sessionId)>512)){
            throw new InvalidArgumentException('Analytics session id is invalid.');
        }
        if(count($this->dimensions)>32)throw new InvalidArgumentException('Too many analytics dimensions.');
        foreach($this->dimensions as $key=>$value){
            if(!is_string($key)||preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$key)!==1){
                throw new InvalidArgumentException('Analytics dimension key is invalid.');
            }
            if(is_string($value)){
                if(strlen($value)>96||preg_match('/^[A-Za-z0-9._:-]*$/D',$value)!==1){
                    throw new InvalidArgumentException('Analytics string dimension must be a bounded token.');
                }
                if(filter_var($value,FILTER_VALIDATE_IP)!==false){
                    throw new InvalidArgumentException('Analytics dimensions may not contain raw IP addresses.');
                }
            }
            if(is_float($value)&&!is_finite($value)){
                throw new InvalidArgumentException('Analytics numeric dimension is invalid.');
            }
        }
        $this->occurredAt=($occurredAt??new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
