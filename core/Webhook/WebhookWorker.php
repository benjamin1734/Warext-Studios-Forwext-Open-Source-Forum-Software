<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use Throwable;

final readonly class WebhookWorker
{
    public function __construct(
        private QueueDriver $queue,
        private WebhookDeliveryJobHandler $handler,
    ){}

    public function run(int $limit=25,int $visibilityTimeoutSeconds=30):int
    {
        if($limit<1||$limit>500||$visibilityTimeoutSeconds<5||$visibilityTimeoutSeconds>300){
            throw new WebhookException('Webhook worker limits are invalid.');
        }

        $processed=0;
        $queue=QueueName::fromString(WebhookPlatformService::QUEUE);
        while($processed<$limit){
            $reservation=$this->queue->reserve($queue,$visibilityTimeoutSeconds);
            if($reservation===null)break;

            if($reservation->job->type!==$this->handler->jobType()){
                $this->queue->fail($reservation,'unexpected_job_type');
                ++$processed;
                continue;
            }

            try{
                $this->handler->handle($reservation->job->payload,$reservation->job->availableAt);
                $this->queue->acknowledge($reservation);
            }catch(Throwable){
                $this->queue->fail($reservation,'webhook_handler_exception');
            }
            ++$processed;
        }

        return $processed;
    }
}
