<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AnalyticsStoredEvent
{
    public DateTimeImmutable $occurredAt;

    /** @param array<string,string|int|float|bool|null> $dimensions */
    public function __construct(
        public EntityId $eventId,
        public AnalyticsEventDefinition $definition,
        public ?string $actorHash,
        public ?string $sessionHash,
        public ?string $subjectType,
        public ?string $subjectHash,
        public ?EntityId $forumId,
        public array $dimensions,
        DateTimeImmutable $occurredAt,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->eventId->value())!==1){
            throw new InvalidArgumentException('Analytics stored event id is invalid.');
        }
        foreach([$this->actorHash,$this->sessionHash,$this->subjectHash] as $hash){
            if($hash!==null&&preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){
                throw new InvalidArgumentException('Analytics privacy hash is invalid.');
            }
        }
        if(($this->subjectType===null)!==($this->subjectHash===null)){
            throw new InvalidArgumentException('Analytics stored subject is incomplete.');
        }
        if($this->subjectType!==null&&preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$this->subjectType)!==1){
            throw new InvalidArgumentException('Analytics stored subject type is invalid.');
        }
        if(!$this->definition->collectActor&&$this->actorHash!==null){
            throw new InvalidArgumentException('Analytics stored event may not collect actor identity.');
        }
        if(!$this->definition->collectSession&&$this->sessionHash!==null){
            throw new InvalidArgumentException('Analytics stored event may not collect session identity.');
        }
        if(!$this->definition->collectSubject&&$this->subjectHash!==null){
            throw new InvalidArgumentException('Analytics stored event may not collect subject identity.');
        }
        if(!$this->definition->collectForum&&$this->forumId!==null){
            throw new InvalidArgumentException('Analytics stored event may not collect forum identity.');
        }
        foreach($this->dimensions as $key=>$_value){
            if(!is_string($key)||!$this->definition->allowsDimension($key)){
                throw new InvalidArgumentException('Analytics stored dimension is not allowlisted.');
            }
        }
        $this->occurredAt=$occurredAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
