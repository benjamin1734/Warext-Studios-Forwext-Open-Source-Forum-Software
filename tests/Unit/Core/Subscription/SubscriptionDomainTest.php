<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Subscription;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Payment\PaymentAttemptState;
use Forwext\Core\Subscription\SubscriptionPlan;
use Forwext\Core\Subscription\SubscriptionPurchase;
use Forwext\Core\Subscription\SubscriptionState;
use Forwext\Core\Subscription\UserSubscription;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SubscriptionDomainTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp():void
    {
        $this->now=new DateTimeImmutable('2026-09-21 12:00:00',new DateTimeZone('UTC'));
    }

    public function testTimedAndLifetimePlansAreExplicit():void
    {
        $timed=new SubscriptionPlan(
            $this->id('a'),'premium.monthly','Premium','Timed',true,9900,'TRY',30,10,$this->now,$this->now
        );
        $lifetime=new SubscriptionPlan(
            $this->id('b'),'premium.lifetime','Lifetime','Forever',true,49900,'TRY',null,20,$this->now,$this->now
        );

        self::assertFalse($timed->lifetime());
        self::assertTrue($lifetime->lifetime());
    }

    public function testSubscriptionEffectiveStateHonorsExpiryWithoutCleanupJob():void
    {
        $subscription=new UserSubscription(
            $this->id('c'),$this->id('d'),$this->id('e'),SubscriptionState::Active,
            $this->now,$this->now->modify('+1 day'),$this->now,$this->now
        );

        self::assertTrue($subscription->activeAt($this->now->modify('+12 hours')));
        self::assertFalse($subscription->activeAt($this->now->modify('+2 days')));
    }

    public function testActionRequiredPurchaseMustHaveProviderReferenceAndCheckoutUrl():void
    {
        $this->expectException(InvalidArgumentException::class);

        new SubscriptionPurchase(
            $this->id('1'),$this->id('2'),$this->id('3'),'provider','abcdabcdabcdabcdabcdabcdabcdabcd',
            1000,'TRY',30,PaymentAttemptState::RequiresAction,null,null,null,$this->now,$this->now
        );
    }

    private function id(string $seed):EntityId
    {
        return EntityId::fromString(str_repeat($seed,32));
    }
}
