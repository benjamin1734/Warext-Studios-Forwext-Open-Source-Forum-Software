<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceOrderHistoryEntry
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $historyId,
        public EntityId $orderId,
        public ?EntityId $actorUserId,
        public string $action,
        public MarketplaceOrderState $fromOrderState,
        public MarketplaceOrderState $toOrderState,
        public MarketplacePaymentState $fromPaymentState,
        public MarketplacePaymentState $toPaymentState,
        public MarketplaceDeliveryState $fromDeliveryState,
        public MarketplaceDeliveryState $toDeliveryState,
        DateTimeImmutable $createdAt,
    ){
        if($this->actorUserId!==null)UserId::assert($this->actorUserId);
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$this->action)!==1){
            throw new InvalidArgumentException('Marketplace order history action is invalid.');
        }
        $this->createdAt=$createdAt->setTimezone(new DateTimeZone('UTC'));
    }
}
