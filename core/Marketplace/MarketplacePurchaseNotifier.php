<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class MarketplacePurchaseNotifier
{
    public const BUYER_CREATED='marketplace.order.buyer_created';
    public const SELLER_CREATED='marketplace.order.seller_created';

    public function __construct(private NotificationDispatcher $dispatcher){}

    public static function registerDefinitions(NotificationRegistry $registry):void
    {
        $registry->register(new NotificationDefinition(
            self::BUYER_CREATED,'marketplace',
            'Siparişiniz oluşturuldu',
            '{{order}} numaralı Marketplace siparişiniz oluşturuldu.'
        ));
        $registry->register(new NotificationDefinition(
            self::SELLER_CREATED,'marketplace',
            'Yeni Marketplace siparişi',
            '{{order}} numaralı yeni bir sipariş aldınız.'
        ));
    }

    public function created(MarketplaceOrder $order):void
    {
        $path='/marketplace/orders/'.$order->orderId->value();
        $payload=['order_id'=>$order->orderId->value(),'order_number'=>$order->orderNumber];
        $this->dispatcher->dispatch(new NotificationRequest(
            $order->buyerUserId,self::BUYER_CREATED,['order'=>$order->orderNumber],
            null,'marketplace-order-buyer:'.$order->orderId->value(),$path,$payload
        ));
        $this->dispatcher->dispatch(new NotificationRequest(
            $order->sellerUserId,self::SELLER_CREATED,['order'=>$order->orderNumber],
            null,'marketplace-order-seller:'.$order->orderId->value(),$path,$payload
        ));
    }
}
