<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface PaymentOrderPaidListener
{
    public function onOrderPaid(EntityId $orderId,DateTimeImmutable $at):void;
}
