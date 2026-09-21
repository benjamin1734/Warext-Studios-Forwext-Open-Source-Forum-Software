<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Support\Intake;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplaceBillingSnapshot;
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderState;
use Forwext\Core\Marketplace\MarketplacePaymentState;
use Forwext\Core\Marketplace\MarketplacePurchaseRepository;
use Forwext\Core\Support\Intake\MarketplaceOrderSupportContextResolver;
use Forwext\Core\Support\Intake\SupportContextType;
use Forwext\Core\Support\Intake\SupportContextUnavailableException;
use PHPUnit\Framework\TestCase;

final class MarketplaceOrderSupportContextResolverTest extends TestCase
{
    public function testBuyerCanLinkOwnOrderWithoutManagementPermission(): void
    {
        $buyer = $this->id('1');
        $order = $this->order($buyer, $this->id('2'));
        $orders = $this->createMock(MarketplacePurchaseRepository::class);
        $orders->method('order')->with($order->orderId)->willReturn($order);

        $resolver = new MarketplaceOrderSupportContextResolver(
            $orders,
            $this->authorizer($buyer, []),
        );
        $context = $resolver->resolve($buyer, $order->orderId);

        self::assertSame(SupportContextType::MarketplaceOrder, $context->type);
        self::assertTrue($context->targetId->equals($order->orderId));
        self::assertStringContainsString($order->orderNumber, $context->label);
    }

    public function testUnrelatedUserCannotProbeOrderExistence(): void
    {
        $buyer = $this->id('1');
        $stranger = $this->id('3');
        $order = $this->order($buyer, $this->id('2'));
        $orders = $this->createMock(MarketplacePurchaseRepository::class);
        $orders->method('order')->with($order->orderId)->willReturn($order);

        $resolver = new MarketplaceOrderSupportContextResolver(
            $orders,
            $this->authorizer($stranger, []),
        );

        $this->expectException(SupportContextUnavailableException::class);
        $resolver->resolve($stranger, $order->orderId);
    }

    private function authorizer(EntityId $actor, array $allowed): PermissionAuthorizer
    {
        return new PermissionAuthorizer(
            new PermissionEngine(new OrderContextPermissionRepository($actor, $allowed)),
            new OrderContextAssignmentProvider($actor),
        );
    }

    private function order(EntityId $buyer, EntityId $seller): MarketplaceOrder
    {
        $at = new DateTimeImmutable('2026-09-21 10:00:00', new DateTimeZone('UTC'));

        return new MarketplaceOrder(
            $this->id('a'),
            'FWX-20260921-ABCDEF123456',
            str_repeat('b', 32),
            $buyer,
            $seller,
            'TRY',
            10000,
            10000,
            MarketplaceOrderState::Confirmed,
            MarketplacePaymentState::Paid,
            MarketplaceDeliveryState::Ready,
            new MarketplaceBillingSnapshot('Test Buyer', 'buyer@example.test', 'TR'),
            [],
            $at,
            $at,
        );
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }
}

final readonly class OrderContextAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private EntityId $actor) {}

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $userId->equals($this->actor)
            ? new UserAccessAssignment($userId, EntityId::fromString(str_repeat('f', 32)))
            : null;
    }
}

final readonly class OrderContextPermissionRepository implements PermissionRuleRepository
{
    /** @param list<string> $allowed */
    public function __construct(private EntityId $actor, private array $allowed) {}

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        if (
            $nodeId !== null
            || !$assignment->userId()->equals($this->actor)
            || !in_array($key->value(), $this->allowed, true)
        ) {
            return [];
        }

        return [
            new PermissionRule(
                PermissionSubjectType::User,
                $this->actor,
                PermissionEffect::Allow,
            ),
        ];
    }
}
