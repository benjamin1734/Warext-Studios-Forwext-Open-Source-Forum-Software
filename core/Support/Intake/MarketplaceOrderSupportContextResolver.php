<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplacePurchaseRepository;

final readonly class MarketplaceOrderSupportContextResolver implements SupportContextResolver
{
    public function __construct(
        private MarketplacePurchaseRepository $orders,
        private PermissionAuthorizer $authorizer,
    ){}

    public function type():SupportContextType{return SupportContextType::MarketplaceOrder;}

    public function resolve(EntityId $actorUserId,EntityId $targetId):SupportContextLink
    {
        $order=$this->orders->order($targetId)
            ??throw new SupportContextUnavailableException('Marketplace order context is unavailable.');
        $manager=$this->authorizer->allows($actorUserId,PermissionKey::fromString('marketplace.order.manage'))
            ||$this->authorizer->allows($actorUserId,PermissionKey::fromString('marketplace.delivery.manage_all'));
        if(!$order->buyerUserId->equals($actorUserId)&&!$order->sellerUserId->equals($actorUserId)&&!$manager){
            throw new SupportContextUnavailableException('Marketplace order context is unavailable.');
        }

        return new SupportContextLink(
            SupportContextType::MarketplaceOrder,
            $targetId,
            'Marketplace order '.$order->orderNumber,
        );
    }
}
