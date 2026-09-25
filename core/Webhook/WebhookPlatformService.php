<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateInterval;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use JsonException;
use Throwable;

final readonly class WebhookPlatformService
{
    public const QUEUE='webhooks';
    public const JOB_TYPE='core.webhook.deliver';

    public function __construct(
        private WebhookRepository $repository,
        private WebhookSecretManager $secrets,
        private WebhookDestinationPolicy $destinations,
        private QueueDriver $queue,
        private Clock $clock=new SystemClock(),
    ){}

    public function createSubscription(
        string $eventName,string $destinationUrl,?string $actorUserId=null,int $maxAttempts=8,
    ):WebhookIssuedSubscription{
        $this->assertEvent($eventName);
        if($actorUserId!==null&&preg_match('/^[a-f0-9]{32}$/D',$actorUserId)!==1){
            throw new WebhookException('Webhook subscription actor id is invalid.');
        }
        if($maxAttempts<1||$maxAttempts>20)throw new WebhookException('Webhook retry count is invalid.');

        $approved=$this->destinations->approve($destinationUrl);
        $id=bin2hex(random_bytes(16));
        $now=$this->clock->now();
        $secret=$this->secrets->issue($id,1);
        $subscription=new WebhookSubscription(
            $id,$eventName,$approved->url,true,1,null,null,$maxAttempts,$actorUserId,$now,$now,
        );
        try{$this->repository->saveSubscription($subscription);}
        catch(Throwable $e){
            $this->secrets->delete($id,1);
            throw $e;
        }
        return new WebhookIssuedSubscription($subscription,$secret);
    }

    public function rotateSecret(string $subscriptionId,int $graceSeconds=86400):string
    {
        if($graceSeconds<60||$graceSeconds>604800)throw new WebhookException('Webhook rotation grace is invalid.');
        $subscription=$this->repository->subscription($subscriptionId)
            ??throw new WebhookException('Webhook subscription does not exist.');
        if(!$subscription->active)throw new WebhookException('Inactive webhook cannot rotate secrets.');
        if($subscription->secretVersion>=65535)throw new WebhookException('Webhook secret version is exhausted.');

        $next=$subscription->secretVersion+1;
        $secret=$this->secrets->issue($subscriptionId,$next);
        $now=$this->clock->now();
        $validUntil=$now->add(new DateInterval('PT'.$graceSeconds.'S'));
        try{
            $this->repository->updateSecretRotation(
                $subscriptionId,$next,$subscription->secretVersion,$validUntil,$now,
            );
        }catch(Throwable $e){
            $this->secrets->delete($subscriptionId,$next);
            throw $e;
        }
        return $secret;
    }

    /** @param array<string,mixed> $data @return list<string> */
    public function publish(string $eventName,array $data):array
    {
        $this->assertEvent($eventName);
        $ids=[];
        foreach($this->repository->activeSubscriptionsForEvent($eventName) as $subscription){
            $ids[]=$this->enqueue($subscription,$eventName,$data,false);
        }
        return $ids;
    }

    public function testSubscription(string $subscriptionId):string
    {
        $subscription=$this->repository->subscription($subscriptionId)
            ??throw new WebhookException('Webhook subscription does not exist.');
        if(!$subscription->active)throw new WebhookException('Inactive webhook cannot receive a test delivery.');
        return $this->enqueue(
            $subscription,$subscription->eventName,
            ['test'=>true,'message'=>'Forwext webhook test delivery'],true,
        );
    }

    /** @param array<string,mixed> $data */
    private function enqueue(WebhookSubscription $subscription,string $eventName,array $data,bool $test):string
    {
        $id=bin2hex(random_bytes(16));
        $now=$this->clock->now();
        try{
            $body=json_encode([
                'id'=>$id,'event'=>$eventName,'created_at'=>$now->format('Y-m-d\TH:i:s.u\Z'),
                'test'=>$test,'data'=>$data,
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }catch(JsonException $e){
            throw new WebhookException('Webhook event payload cannot be encoded.',previous:$e);
        }
        if(strlen($body)>1_048_576)throw new WebhookException('Webhook payload exceeds the size limit.');

        $delivery=new WebhookDelivery(
            $id,$subscription->id,$eventName,$body,$test,WebhookDeliveryStatus::Pending,0,
            $subscription->maxAttempts,$now,null,null,$now,$now,
        );
        $this->repository->createDelivery($delivery);
        try{
            $this->queue->push(
                QueueName::fromString(self::QUEUE),self::JOB_TYPE,
                json_encode(['delivery_id'=>$id],JSON_THROW_ON_ERROR),1,$now,
            );
        }catch(Throwable $e){
            $this->repository->markFailed($id,0,null,'queue_unavailable',$now);
            throw new WebhookException('Webhook delivery could not be queued.',previous:$e);
        }
        return $id;
    }

    private function assertEvent(string $eventName):void
    {
        if(preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D',$eventName)!==1){
            throw new WebhookException('Webhook event name is invalid.');
        }
    }
}
