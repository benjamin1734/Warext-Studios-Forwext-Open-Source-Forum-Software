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
        $this->occurredAt=$occurredAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
