<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class AnalyticsEventRecorder
{
    public function __construct(
        private AnalyticsEventRegistry $registry,
        private AnalyticsRepository $repository,
        private AnalyticsPrivacyHasher $privacy,
    ){}

    public function record(AnalyticsEvent $event):AnalyticsStoredEvent
    {
        $definition=$this->registry->require($event->key);

        if(!$definition->collectActor&&$event->actorUserId!==null){
            throw new AnalyticsPrivacyException('This analytics event does not permit actor identity collection.');
        }
        if(!$definition->collectSession&&$event->sessionId!==null){
            throw new AnalyticsPrivacyException('This analytics event does not permit session identity collection.');
        }
        if(!$definition->collectSubject&&$event->subjectId!==null){
            throw new AnalyticsPrivacyException('This analytics event does not permit subject identity collection.');
        }
        if(!$definition->collectForum&&$event->forumId!==null){
            throw new AnalyticsPrivacyException('This analytics event does not permit forum identity collection.');
        }

        $dimensions=[];
        foreach($event->dimensions as $key=>$value){
            if(!$definition->allowsDimension($key)){
                throw new AnalyticsPrivacyException('Analytics dimension is not allowlisted for event: '.$key);
            }
            $dimensions[$key]=$value;
        }
        ksort($dimensions,SORT_STRING);

        $stored=new AnalyticsStoredEvent(
            AnalyticsStoredEvent::generateId(),
            $definition,
            $event->actorUserId===null?null:$this->privacy->actor($event->actorUserId),
            $event->sessionId===null?null:$this->privacy->session($event->sessionId),
            $event->subjectType,
            $event->subjectId===null?null:$this->privacy->subject((string)$event->subjectType,$event->subjectId),
            $event->forumId,
            $dimensions,
            $event->occurredAt,
        );

        $this->repository->append($stored);
        return $stored;
    }

    public function recordBestEffort(AnalyticsEvent $event):bool
    {
        try{
            $this->record($event);
            return true;
        }catch(Throwable){
            return false;
        }
    }

    /** @return array<string,int> deleted rows by event key */
    public function pruneDue(?DateTimeImmutable $now=null,int $limitPerEvent=5000):array
    {
        $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $deleted=[];
        foreach($this->registry->all() as $definition){
            $before=$now->modify('-'.$definition->retentionDays.' days');
            $deleted[$definition->key]=$this->repository->prune($definition->key,$before,$limitPerEvent);
        }
        return $deleted;
    }
}
