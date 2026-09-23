<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\AnalyticsEvent;
use Forwext\Core\Analytics\AnalyticsEventCategory;
use Forwext\Core\Analytics\AnalyticsEventRecorder;
use Forwext\Core\Analytics\AnalyticsEventRegistry;
use Forwext\Core\Analytics\AnalyticsPrivacyException;
use Forwext\Core\Analytics\AnalyticsPrivacyHasher;
use Forwext\Core\Analytics\AnalyticsRepository;
use Forwext\Core\Analytics\AnalyticsStoredEvent;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnalyticsEventModelTest extends TestCase
{
    public function testCoreRegistryCoversEveryRoadmapDomain():void
    {
        $registry=AnalyticsEventRegistry::withCoreDefaults();
        $categories=[];
        foreach($registry->all() as $definition)$categories[$definition->category->value]=true;

        foreach(AnalyticsEventCategory::cases() as $category){
            self::assertArrayHasKey($category->value,$categories);
        }

        self::assertSame(
            AnalyticsEventCategory::Marketplace,
            $registry->require('marketplace.purchase.completed')->category
        );
        self::assertTrue($registry->require('forum.view')->collectForum);
        self::assertFalse($registry->require('user.active')->collectForum);
    }

    public function testRecorderPersistsOnlyPseudonymousIdentitiesAndAllowlistedDimensions():void
    {
        $captured=null;
        $repository=$this->createMock(AnalyticsRepository::class);
        $repository->expects(self::once())->method('append')
            ->willReturnCallback(static function(AnalyticsStoredEvent $event)use(&$captured):void{$captured=$event;});

        $recorder=new AnalyticsEventRecorder(
            AnalyticsEventRegistry::withCoreDefaults(),
            $repository,
            new AnalyticsPrivacyHasher(SecretKey::fromBase64(base64_encode(str_repeat('k',32))))
        );

        $user=EntityId::fromString(str_repeat('a',32));
        $forum=EntityId::fromString(str_repeat('b',32));
        $stored=$recorder->record(new AnalyticsEvent(
            'forum.view',
            actorUserId:$user,
            sessionId:'session-secret-value',
            forumId:$forum,
            dimensions:['route'=>'forum.thread.view','device'=>'desktop'],
            occurredAt:new DateTimeImmutable('2026-09-21 18:00:00',new DateTimeZone('UTC')),
        ));

        self::assertSame($stored,$captured);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',(string)$stored->actorHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',(string)$stored->sessionHash);
        self::assertNotSame($user->value(),$stored->actorHash);
        self::assertNotSame('session-secret-value',$stored->sessionHash);
        self::assertSame($forum->value(),$stored->forumId?->value());
        self::assertSame(['device'=>'desktop','route'=>'forum.thread.view'],$stored->dimensions);
    }

    public function testRecorderRejectsDimensionOutsideRegistryAllowlist():void
    {
        $repository=$this->createMock(AnalyticsRepository::class);
        $repository->expects(self::never())->method('append');
        $recorder=new AnalyticsEventRecorder(
            AnalyticsEventRegistry::withCoreDefaults(),
            $repository,
            new AnalyticsPrivacyHasher(SecretKey::generate())
        );

        $this->expectException(AnalyticsPrivacyException::class);
        $recorder->record(new AnalyticsEvent('user.active',dimensions:['email'=>'user.example']));
    }

    public function testDimensionPayloadRejectsRawIpAndFreeText():void
    {
        $this->expectException(InvalidArgumentException::class);
        new AnalyticsEvent('user.active',dimensions:['device'=>'192.168.1.1']);
    }


    public function testStructuralContentIdIsAllowedOnlyByExplicitEventDefinition():void
    {
        $captured=null;
        $repository=$this->createMock(AnalyticsRepository::class);
        $repository->expects(self::once())->method('append')
            ->willReturnCallback(static function(AnalyticsStoredEvent $event)use(&$captured):void{$captured=$event;});

        $recorder=new AnalyticsEventRecorder(
            AnalyticsEventRegistry::withCoreDefaults(),
            $repository,
            new AnalyticsPrivacyHasher(SecretKey::generate())
        );
        $thread=EntityId::fromString(str_repeat('c',32));
        $forum=EntityId::fromString(str_repeat('d',32));

        $stored=$recorder->record(new AnalyticsEvent(
            'content.thread.view',
            forumId:$forum,
            contentType:'thread',
            contentId:$thread,
            dimensions:['route'=>'forum.thread.view','device'=>'desktop'],
        ));

        self::assertSame($stored,$captured);
        self::assertSame('thread',$stored->contentType);
        self::assertSame($thread->value(),$stored->contentId?->value());
        self::assertTrue($stored->definition->collectContent);
    }

    public function testEventWithoutContentPolicyRejectsStructuralContentId():void
    {
        $repository=$this->createMock(AnalyticsRepository::class);
        $repository->expects(self::never())->method('append');
        $recorder=new AnalyticsEventRecorder(
            AnalyticsEventRegistry::withCoreDefaults(),
            $repository,
            new AnalyticsPrivacyHasher(SecretKey::generate())
        );

        $this->expectException(AnalyticsPrivacyException::class);
        $recorder->record(new AnalyticsEvent(
            'user.active',
            contentType:'thread',
            contentId:EntityId::fromString(str_repeat('e',32)),
            dimensions:['device'=>'desktop'],
        ));
    }
}
