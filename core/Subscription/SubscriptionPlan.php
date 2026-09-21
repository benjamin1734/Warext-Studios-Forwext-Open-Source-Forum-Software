<?php

declare(strict_types=1);

namespace Forwext\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class SubscriptionPlan
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $planId,
        public string $key,
        public string $name,
        public string $description,
        public bool $active,
        public int $priceMinor,
        public string $currency,
        public ?int $durationDays,
        public int $sortOrder,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->planId->value())!==1){
            throw new InvalidArgumentException('Subscription plan id is invalid.');
        }
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$this->key)!==1){
            throw new InvalidArgumentException('Subscription plan key is invalid.');
        }
        if(trim($this->name)===''||strlen(trim($this->name))>120||preg_match('//u',$this->name)!==1){
            throw new InvalidArgumentException('Subscription plan name is invalid.');
        }
        if(strlen($this->description)>20000||preg_match('//u',$this->description)!==1){
            throw new InvalidArgumentException('Subscription plan description is invalid.');
        }
        if($this->priceMinor<0||preg_match('/^[A-Z]{3}$/D',$this->currency)!==1){
            throw new InvalidArgumentException('Subscription plan price is invalid.');
        }
        if($this->durationDays!==null&&($this->durationDays<1||$this->durationDays>3650)){
            throw new InvalidArgumentException('Subscription plan duration is invalid.');
        }
        if($this->sortOrder<0||$this->sortOrder>65535){
            throw new InvalidArgumentException('Subscription plan sort order is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt){
            throw new InvalidArgumentException('Subscription plan timestamps are invalid.');
        }
    }

    public static function generateId():EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }

    public function lifetime():bool
    {
        return $this->durationDays===null;
    }
}
