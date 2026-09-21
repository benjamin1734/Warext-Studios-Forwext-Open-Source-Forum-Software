<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\AnalyticsEventRecorder;
use Forwext\Core\Analytics\AnalyticsEventRegistry;
use Forwext\Core\Analytics\AnalyticsMaintenanceTasks;
use Forwext\Core\Analytics\AnalyticsPrivacyHasher;
use Forwext\Core\Analytics\AnalyticsRepository;
use Forwext\Core\Analytics\AnalyticsRetentionJobHandler;
use Forwext\Core\Analytics\AnalyticsStoredEvent;
use Forwext\Core\Scheduler\SchedulerRegistry;
use Forwext\Core\Security\Secret\SecretKey;
use PHPUnit\Framework\TestCase;

final class AnalyticsRetentionTest extends TestCase
{
    public function testRetentionTaskIsRegisteredAsDailyMaintenance():void
    {
        $registry=new SchedulerRegistry();
        AnalyticsMaintenanceTasks::register($registry);
        $tasks=$registry->all();

        self::assertCount(1,$tasks);
        self::assertSame('analytics.retention.prune',$tasks[0]->name);
        self::assertSame(AnalyticsMaintenanceTasks::RETENTION_JOB_TYPE,$tasks[0]->jobType);
        self::assertSame('maintenance',$tasks[0]->queue->value());
        self::assertSame('{"limit_per_event":1000}',$tasks[0]->payload);
    }

    public function testRetentionJobAppliesPerDefinitionRetentionWithBoundedDeletes():void
    {
        $repository=new class implements AnalyticsRepository {
            /** @var list<array{key:string,before:string,limit:int}> */
            public array $prunes=[];

            public function append(AnalyticsStoredEvent $event):void
            {
            }

            public function prune(string $eventKey,DateTimeImmutable $before,int $limit=5000):int
            {
                $this->prunes[]=[
                    'key'=>$eventKey,
                    'before'=>$before->format('Y-m-d H:i:s'),
                    'limit'=>$limit,
                ];
                return 1;
            }
        };

        $registry=AnalyticsEventRegistry::withCoreDefaults();
        $recorder=new AnalyticsEventRecorder(
            $registry,
            $repository,
            new AnalyticsPrivacyHasher(SecretKey::generate())
        );
        $handler=new AnalyticsRetentionJobHandler($recorder);
        $now=new DateTimeImmutable('2026-09-21 03:37:00',new DateTimeZone('UTC'));

        self::assertSame(count($registry->all()),$handler->handle('{"limit_per_event":7}',$now));
        self::assertCount(count($registry->all()),$repository->prunes);
        foreach($repository->prunes as $prune)self::assertSame(7,$prune['limit']);

        $active=array_values(array_filter(
            $repository->prunes,
            static fn(array $row):bool=>$row['key']==='user.active'
        ));
        self::assertCount(1,$active);
        self::assertSame('2026-06-23 03:37:00',$active[0]['before']);
    }
}
