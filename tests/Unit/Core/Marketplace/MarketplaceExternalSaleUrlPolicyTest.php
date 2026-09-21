<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Marketplace;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Marketplace\MarketplaceExternalSaleLink;
use Forwext\Core\Marketplace\MarketplaceExternalSaleUrlPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MarketplaceExternalSaleUrlPolicyTest extends TestCase
{
    public function testAllowlistIsFailClosedAndRejectsUnsafeRedirectShapes():void
    {
        $policy=new MarketplaceExternalSaleUrlPolicy([]);
        foreach([
            'https://store.example.com/product',
            'http://store.example.com/product',
            'https://user:pass@store.example.com/product',
            'https://store.example.com:8443/product',
            'https://127.0.0.1/product',
        ] as $url){
            try{
                $policy->validate($url);
                self::fail('Unsafe or non-allowlisted marketplace URL must be rejected: '.$url);
            }catch(InvalidArgumentException){}
        }
    }

    public function testExactHostAndOptionalSubdomainsUseLabelBoundary():void
    {
        $exact=new MarketplaceExternalSaleUrlPolicy(['shop.example.com']);
        self::assertSame('shop.example.com',$exact->validate('https://shop.example.com/product')['host']);

        foreach(['https://evilshop.example.com/product','https://sub.shop.example.com/product'] as $url){
            try{
                $exact->validate($url);
                self::fail('Exact-host policy must reject: '.$url);
            }catch(InvalidArgumentException){}
        }

        $subdomains=new MarketplaceExternalSaleUrlPolicy(['shop.example.com'],true);
        self::assertSame('sub.shop.example.com',$subdomains->validate('https://sub.shop.example.com/product')['host']);
        try{
            $subdomains->validate('https://evilshop.example.com/product');
            self::fail('Suffix confusion must not bypass the external-sale allowlist.');
        }catch(InvalidArgumentException){}
    }

    public function testOutboundUrlAddsMissingUtmWithoutOverwritingExistingValues():void
    {
        $policy=new MarketplaceExternalSaleUrlPolicy(['shop.example.com'],false,'forwext','marketplace');
        $listingId=EntityId::fromString(str_repeat('a',32));
        $link=new MarketplaceExternalSaleLink(
            $listingId,
            'https://shop.example.com/product?utm_source=seller&x=1',
            'shop.example.com',
            true,
            UserId::generate(),
            new DateTimeImmutable('2026-09-21T09:00:00+00:00'),
            new DateTimeImmutable('2026-09-21T09:00:00+00:00'),
        );

        $url=$policy->outboundUrl($link,$listingId);
        self::assertStringContainsString('utm_source=seller',$url);
        self::assertStringNotContainsString('utm_source=forwext',$url);
        self::assertStringContainsString('utm_medium=marketplace',$url);
        self::assertStringContainsString('utm_campaign=listing-'.str_repeat('a',32),$url);
    }

    public function testStoredHostBindingMustStillMatchCurrentValidatedTarget():void
    {
        $policy=new MarketplaceExternalSaleUrlPolicy(['shop.example.com']);
        $listingId=EntityId::fromString(str_repeat('b',32));
        $link=new MarketplaceExternalSaleLink(
            $listingId,
            'https://shop.example.com/product',
            'other.example.com',
            true,
            UserId::generate(),
            new DateTimeImmutable('2026-09-21T09:00:00+00:00'),
            new DateTimeImmutable('2026-09-21T09:00:00+00:00'),
        );
        $this->expectException(InvalidArgumentException::class);
        $policy->outboundUrl($link,$listingId);
    }
}
