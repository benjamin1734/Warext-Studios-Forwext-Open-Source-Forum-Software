<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Advertising\AdvertisingCampaign;
use Forwext\Core\Advertising\AdvertisingKind;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdvertisingCampaignTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp():void
    {
        $this->now=new DateTimeImmutable('2026-09-21 18:00:00',new DateTimeZone('UTC'));
    }

    public function testCampaignHonorsTimeWindow():void
    {
        $campaign=$this->campaign($this->now->modify('-1 hour'),$this->now->modify('+1 hour'));
        self::assertTrue($campaign->activeAt($this->now));
        self::assertFalse($campaign->activeAt($this->now->modify('+2 hours')));
    }

    public function testFrequencyCapRequiresWindow():void
    {
        $this->expectException(InvalidArgumentException::class);
        new AdvertisingCampaign(
            EntityId::fromString(str_repeat('a',32)),'promo.one',AdvertisingKind::Advertisement,
            'Promo','Başlık','Metin','https://example.com','page.top',true,0,3,null,0,0,'TRY',
            null,null,$this->now,$this->now
        );
    }

    public function testLifetimeScheduleCanHaveNoBounds():void
    {
        self::assertTrue($this->campaign(null,null)->activeAt($this->now));
    }

    private function campaign(?DateTimeImmutable $start,?DateTimeImmutable $end):AdvertisingCampaign
    {
        return new AdvertisingCampaign(
            EntityId::fromString(str_repeat('b',32)),'promo.test',AdvertisingKind::Advertisement,
            'Promo','Başlık','Metin','/marketplace','page.top',true,10,3,3600,1,5,'TRY',
            $start,$end,$this->now,$this->now
        );
    }
}
