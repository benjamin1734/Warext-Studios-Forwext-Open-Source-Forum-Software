<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateInterval;
use DateTimeImmutable;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Infrastructure\SystemClock;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueJobHandler;
use Forwext\Core\Queue\QueueName;
use JsonException;
use Throwable;

final readonly class WebhookDeliveryJobHandler implements QueueJobHandler
{
    public function __construct(
        private WebhookRepository $repository,
        private WebhookSecretManager $secrets,
        private WebhookDestinationPolicy $destinations,
        private WebhookTransport $transport,
        private QueueDriver $queue,
        private Clock $clock=new SystemClock(),
    ){}

    public function jobType():string{return WebhookPlatformService::JOB_TYPE;}

    public function handle(string $payload,DateTimeImmutable $now):int
    {
        try{$decoded=json_decode($payload,true,8,JSON_THROW_ON_ERROR);}
        catch(JsonException $e){throw new WebhookException('Webhook job payload is invalid.',previous:$e);}
        $deliveryId=is_array($decoded)?($decoded['delivery_id']??null):null;
        if(!is_string($deliveryId)||preg_match('/^[a-f0-9]{32}$/D',$deliveryId)!==1){
            throw new WebhookException('Webhook delivery job id is invalid.');
        }

        $delivery=$this->repository->delivery($deliveryId);
        if($delivery===null||in_array($delivery->status,[WebhookDeliveryStatus::Delivered,WebhookDeliveryStatus::Failed],true)){
            return 0;
        }
        if($delivery->nextAttemptAt!==null&&$delivery->nextAttemptAt>$now){
            $this->queue->push(
                QueueName::fromString(WebhookPlatformService::QUEUE),$this->jobType(),$payload,1,$delivery->nextAttemptAt,
            );
            return 0;
        }

        $subscription=$this->repository->subscription($delivery->subscriptionId);
        if($subscription===null||!$subscription->active){
            $this->repository->markFailed($delivery->id,$delivery->attemptCount,null,'subscription_inactive',$now);
            return 1;
        }

        $attempt=$delivery->attemptCount+1;
        $started=$now;
        try{
            $approved=$this->destinations->approve($subscription->destinationUrl);
            $timestamp=$now->getTimestamp();
            $signatures=[WebhookSigner::signature(
                $this->secrets->get($subscription->id,$subscription->secretVersion),
                $subscription->secretVersion,$delivery->id,$timestamp,$delivery->bodyJson,
            )];
            if($subscription->previousSecretVersion!==null
                &&$subscription->previousSecretValidUntil!==null
                &&$subscription->previousSecretValidUntil>=$now
            ){
                $signatures[]=WebhookSigner::signature(
                    $this->secrets->get($subscription->id,$subscription->previousSecretVersion),
                    $subscription->previousSecretVersion,$delivery->id,$timestamp,$delivery->bodyJson,
                );
            }
            $result=$this->transport->post($approved,$delivery->bodyJson,[
                'X-Forwext-Webhook-Id'=>$delivery->id,
                'X-Forwext-Webhook-Event'=>$delivery->eventName,
                'X-Forwext-Webhook-Timestamp'=>(string)$timestamp,
                'X-Forwext-Webhook-Signature'=>implode(',',$signatures),
                'X-Forwext-Webhook-Test'=>$delivery->test?'1':'0',
            ]);
        }catch(Throwable){
            $result=WebhookTransportResult::failure('delivery_exception',true);
        }

        $finished=$this->clock->now();
        $this->repository->recordAttempt(
            $delivery->id,$attempt,$result->successful?'delivered':'failed',$result->httpStatus,
            $result->errorCode,$result->retryable,$started,$finished,
        );

        if($result->successful){
            $this->repository->markDelivered($delivery->id,$attempt,$finished);
            return 1;
        }

        $error=$result->errorCode??'delivery_failed';
        if($result->retryable&&$attempt<$delivery->maxAttempts){
            $next=$finished->add(new DateInterval('PT'.$this->backoffSeconds($attempt).'S'));
            $this->repository->scheduleRetry(
                $delivery->id,$attempt,$next,$result->httpStatus,$error,$finished,
            );
            try{
                $this->queue->push(
                    QueueName::fromString(WebhookPlatformService::QUEUE),$this->jobType(),
                    json_encode(['delivery_id'=>$delivery->id],JSON_THROW_ON_ERROR),1,$next,
                );
            }catch(Throwable){
                $this->repository->markFailed(
                    $delivery->id,$attempt,$result->httpStatus,'retry_queue_failed',$finished,
                );
            }
            return 1;
        }

        $this->repository->markFailed($delivery->id,$attempt,$result->httpStatus,$error,$finished);
        return 1;
    }

    private function backoffSeconds(int $attempt):int
    {
        return min(86400,30*(2**max(0,$attempt-1)));
    }
}
