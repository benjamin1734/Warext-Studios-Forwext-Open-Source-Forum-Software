<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class UserSubscription
{
    public DateTimeImmutable $startsAt;
    public ?DateTimeImmutable $endsAt;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $subscriptionId,
        public EntityId $userId,
        public EntityId $planId,
        public SubscriptionState $state,
        DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->subscriptionId->value())!==1
            ||preg_match('/^[a-f0-9]{32}$/D',$this->planId->value())!==1
        ){
            throw new InvalidArgumentException('Subscription identifier is invalid.');
        }
        UserId::assert($this->userId);
        $utc=new DateTimeZone('UTC');
        $this->startsAt=$startsAt->setTimezone($utc);
        $this->endsAt=$endsAt?->setTimezone($utc);
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->endsAt!==null&&$this->endsAt<=$this->startsAt){
            throw new InvalidArgumentException('Subscription end must be after start.');
        }
        if($this->createdAt>$this->updatedAt){
            throw new InvalidArgumentException('Subscription timestamps are invalid.');
        }
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function activeAt(DateTimeImmutable $at):bool
    {
        $at=$at->setTimezone(new DateTimeZone('UTC'));
        return $this->state===SubscriptionState::Active
            &&$this->startsAt<=$at
            &&($this->endsAt===null||$this->endsAt>$at);
    }
}
