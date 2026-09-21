<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Advertising;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Advertising\AdvertisingCampaign;
use Forwext\Core\Advertising\AdvertisingDevice;
use Forwext\Core\Advertising\AdvertisingEventType;
use Forwext\Core\Advertising\AdvertisingKind;
use Forwext\Core\Advertising\AdvertisingRepository;
use Forwext\Core\Advertising\AdvertisingRuntimeContext;
use Forwext\Core\Advertising\AdvertisingService;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretKey;
use PHPUnit\Framework\TestCase;

final class AdvertisingServiceSelectionTest extends TestCase
{
    public function testMatchingCampaignIsSelectedAndImpressionRecorded():void
    {
        $now=new DateTimeImmutable('2026-09-21 18:00:00',new DateTimeZone('UTC'));
        $campaign=$this->campaign($now);
        $forum=$this->id('f');
        $group=$this->id('1');

        $repository=$this->createMock(AdvertisingRepository::class);
        $repository->method('activeCampaigns')->willReturn([$campaign]);
        $repository->method('routeTargets')->willReturn(['forum.*']);
        $repository->method('forumTargets')->willReturn([$forum]);
        $repository->method('groupTargets')->willReturn([$group]);
        $repository->method('deviceTargets')->willReturn([AdvertisingDevice::Mobile]);
        $repository->method('impressionCount')->willReturn(1);
        $repository->expects(self::once())->method('recordEvent')->with(
            $campaign->campaignId,
            AdvertisingEventType::Impression,
            str_repeat('a',64),
            'forum.thread.view',
            $forum,
            AdvertisingDevice::Mobile,
            $now
        );

        $service=$this->service($repository);
        $selected=$service->select(new AdvertisingRuntimeContext(
            'forum.thread.view',$forum,[$group],AdvertisingDevice::Mobile,str_repeat('a',64),$now
        ),['page.top']);

        self::assertSame($campaign,$selected['page.top']??null);
    }

    public function testFrequencyCapSuppressesOtherwiseMatchingCampaign():void
    {
        $now=new DateTimeImmutable('2026-09-21 18:00:00',new DateTimeZone('UTC'));
        $campaign=$this->campaign($now);

        $repository=$this->createMock(AdvertisingRepository::class);
        $repository->method('activeCampaigns')->willReturn([$campaign]);
        $repository->method('routeTargets')->willReturn([]);
        $repository->method('forumTargets')->willReturn([]);
        $repository->method('groupTargets')->willReturn([]);
        $repository->method('deviceTargets')->willReturn([]);
        $repository->method('impressionCount')->willReturn(2);
        $repository->expects(self::never())->method('recordEvent');

        $service=$this->service($repository);
        $selected=$service->select(new AdvertisingRuntimeContext(
            'home',null,[],AdvertisingDevice::Desktop,str_repeat('b',64),$now
        ),['page.top']);

        self::assertSame([],$selected);
    }

    private function service(AdvertisingRepository $repository):AdvertisingService
    {
        $permissionRules=$this->createMock(PermissionRuleRepository::class);
        $assignments=$this->createMock(UserAccessAssignmentProvider::class);
        return new AdvertisingService(
            $this->createMock(TransactionalQueryExecutor::class),
            $repository,
            new PermissionAuthorizer(new PermissionEngine($permissionRules),$assignments),
            $this->createMock(AuditRecorder::class),
            SecretKey::generate()
        );
    }

    private function campaign(DateTimeImmutable $now):AdvertisingCampaign
    {
        return new AdvertisingCampaign(
            $this->id('c'),'campaign.test',AdvertisingKind::Advertisement,'Campaign','Headline','Body',
            '/marketplace','page.top',true,100,2,3600,1,5,'TRY',
            $now->modify('-1 day'),$now->modify('+1 day'),$now,$now
        );
    }

    private function id(string $seed):EntityId
    {
        return EntityId::fromString(str_repeat($seed,32));
    }
}
