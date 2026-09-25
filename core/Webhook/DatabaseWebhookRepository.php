<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use RuntimeException;

final readonly class DatabaseWebhookRepository implements WebhookRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function saveSubscription(WebhookSubscription $subscription): void
    {
        $affected=$this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_webhook_subscriptions '
            . '(subscription_id,event_name,destination_url,active,secret_version,previous_secret_version,'
            . 'previous_secret_valid_until_utc,max_attempts,created_by_user_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:event,:url,:active,:secret_version,NULL,NULL,:max_attempts,:actor,:created_at,:updated_at)',
            [
                'id'=>$subscription->id,'event'=>$subscription->eventName,'url'=>$subscription->destinationUrl,
                'active'=>$subscription->active?1:0,'secret_version'=>$subscription->secretVersion,
                'max_attempts'=>$subscription->maxAttempts,'actor'=>$subscription->createdByUserId,
                'created_at'=>self::format($subscription->createdAt),'updated_at'=>self::format($subscription->updatedAt),
            ],
        ));
        if($affected!==1)throw new RuntimeException('Webhook subscription was not persisted.');
    }

    public function subscription(string $subscriptionId): ?WebhookSubscription
    {
        self::assertId($subscriptionId);
        $row=$this->database->fetchOne(new CompiledQuery(
            self::subscriptionSelect().' WHERE subscription_id=:id LIMIT 1',['id'=>$subscriptionId],
        ));
        return $row===null?null:self::subscriptionFromRow($row);
    }

    public function activeSubscriptionsForEvent(string $eventName): array
    {
        self::assertEvent($eventName);
        $rows=$this->database->fetchAll(new CompiledQuery(
            self::subscriptionSelect()
            .' WHERE event_name=:event AND active=1 ORDER BY created_at_utc,subscription_id',
            ['event'=>$eventName],
        ));
        return array_map(self::subscriptionFromRow(...),$rows);
    }

    public function updateSecretRotation(
        string $subscriptionId,
        int $secretVersion,
        int $previousSecretVersion,
        DateTimeImmutable $previousSecretValidUntil,
        DateTimeImmutable $updatedAt,
    ): void {
        self::assertId($subscriptionId);
        $affected=$this->database->execute(new CompiledQuery(
            'UPDATE forwext_webhook_subscriptions SET secret_version=:secret_version,'
            .'previous_secret_version=:previous_secret_version,previous_secret_valid_until_utc=:valid_until,'
            .'updated_at_utc=:updated_at WHERE subscription_id=:id AND active=1',
            [
                'secret_version'=>$secretVersion,'previous_secret_version'=>$previousSecretVersion,
                'valid_until'=>self::format($previousSecretValidUntil),'updated_at'=>self::format($updatedAt),
                'id'=>$subscriptionId,
            ],
        ));
        if($affected!==1)throw new RuntimeException('Webhook secret rotation did not update exactly one subscription.');
    }

    public function createDelivery(WebhookDelivery $delivery): void
    {
        $affected=$this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_webhook_deliveries '
            .'(delivery_id,subscription_id,event_name,body_json,is_test,status,attempt_count,max_attempts,'
            .'next_attempt_at_utc,last_http_status,last_error_code,created_at_utc,updated_at_utc,delivered_at_utc) '
            .'VALUES (:id,:subscription_id,:event,:body,:is_test,:status,:attempt_count,:max_attempts,'
            .':next_attempt,NULL,NULL,:created_at,:updated_at,NULL)',
            [
                'id'=>$delivery->id,'subscription_id'=>$delivery->subscriptionId,'event'=>$delivery->eventName,
                'body'=>$delivery->bodyJson,'is_test'=>$delivery->test?1:0,'status'=>$delivery->status->value,
                'attempt_count'=>$delivery->attemptCount,'max_attempts'=>$delivery->maxAttempts,
                'next_attempt'=>self::formatNullable($delivery->nextAttemptAt),
                'created_at'=>self::format($delivery->createdAt),'updated_at'=>self::format($delivery->updatedAt),
            ],
        ));
        if($affected!==1)throw new RuntimeException('Webhook delivery was not persisted.');
    }

    public function delivery(string $deliveryId): ?WebhookDelivery
    {
        self::assertId($deliveryId);
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT delivery_id,subscription_id,event_name,body_json,is_test,status,attempt_count,max_attempts,'
            .'next_attempt_at_utc,last_http_status,last_error_code,created_at_utc,updated_at_utc,delivered_at_utc '
            .'FROM forwext_webhook_deliveries WHERE delivery_id=:id LIMIT 1',['id'=>$deliveryId],
        ));
        return $row===null?null:self::deliveryFromRow($row);
    }

    public function recordAttempt(
        string $deliveryId,
        int $attemptNumber,
        string $result,
        ?int $httpStatus,
        ?string $errorCode,
        bool $retryable,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $finishedAt,
    ): void {
        self::assertId($deliveryId);
        if($attemptNumber<1||$attemptNumber>20
            ||preg_match('/^[a-z0-9._-]{1,24}$/D',$result)!==1
            ||($errorCode!==null&&preg_match('/^[a-z0-9._-]{1,64}$/D',$errorCode)!==1)
        )throw new RuntimeException('Webhook attempt log metadata is invalid.');

        $durationMs=max(0,min(4294967295,(int)round(
            ((float)$finishedAt->format('U.u')-(float)$startedAt->format('U.u'))*1000,
        )));
        $affected=$this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_webhook_delivery_attempts '
            .'(delivery_id,attempt_number,result,http_status,error_code,retryable,started_at_utc,finished_at_utc,duration_ms) '
            .'VALUES (:delivery_id,:attempt_number,:result,:http_status,:error_code,:retryable,:started_at,:finished_at,:duration_ms)',
            [
                'delivery_id'=>$deliveryId,'attempt_number'=>$attemptNumber,'result'=>$result,'http_status'=>$httpStatus,
                'error_code'=>$errorCode,'retryable'=>$retryable?1:0,'started_at'=>self::format($startedAt),
                'finished_at'=>self::format($finishedAt),'duration_ms'=>$durationMs,
            ],
        ));
        if($affected!==1)throw new RuntimeException('Webhook delivery attempt was not persisted.');
    }

    public function markDelivered(string $deliveryId,int $attemptCount,DateTimeImmutable $at):void
    {
        $this->transition($deliveryId,$attemptCount,WebhookDeliveryStatus::Delivered,null,null,null,$at,$at);
    }

    public function scheduleRetry(
        string $deliveryId,int $attemptCount,DateTimeImmutable $nextAttemptAt,?int $httpStatus,
        string $errorCode,DateTimeImmutable $updatedAt,
    ):void {
        $this->transition(
            $deliveryId,$attemptCount,WebhookDeliveryStatus::RetryScheduled,$nextAttemptAt,
            $httpStatus,$errorCode,$updatedAt,null,
        );
    }

    public function markFailed(
        string $deliveryId,int $attemptCount,?int $httpStatus,string $errorCode,DateTimeImmutable $updatedAt,
    ):void {
        $this->transition(
            $deliveryId,$attemptCount,WebhookDeliveryStatus::Failed,null,$httpStatus,$errorCode,$updatedAt,null,
        );
    }

    private function transition(
        string $deliveryId,int $attemptCount,WebhookDeliveryStatus $status,?DateTimeImmutable $nextAttemptAt,
        ?int $httpStatus,?string $errorCode,DateTimeImmutable $updatedAt,?DateTimeImmutable $deliveredAt,
    ):void {
        self::assertId($deliveryId);
        $affected=$this->database->execute(new CompiledQuery(
            'UPDATE forwext_webhook_deliveries SET status=:status,attempt_count=:attempt_count,'
            .'next_attempt_at_utc=:next_attempt,last_http_status=:http_status,last_error_code=:error_code,'
            .'updated_at_utc=:updated_at,delivered_at_utc=:delivered_at WHERE delivery_id=:id',
            [
                'status'=>$status->value,'attempt_count'=>$attemptCount,'next_attempt'=>self::formatNullable($nextAttemptAt),
                'http_status'=>$httpStatus,'error_code'=>$errorCode,'updated_at'=>self::format($updatedAt),
                'delivered_at'=>self::formatNullable($deliveredAt),'id'=>$deliveryId,
            ],
        ));
        if($affected!==1)throw new RuntimeException('Webhook delivery transition did not update exactly one row.');
    }

    private static function subscriptionSelect():string
    {
        return 'SELECT subscription_id,event_name,destination_url,active,secret_version,previous_secret_version,'
            .'previous_secret_valid_until_utc,max_attempts,created_by_user_id,created_at_utc,updated_at_utc '
            .'FROM forwext_webhook_subscriptions';
    }

    /** @param array<string,mixed> $row */
    private static function subscriptionFromRow(array $row):WebhookSubscription
    {
        return new WebhookSubscription(
            (string)($row['subscription_id']??''),(string)($row['event_name']??''),
            (string)($row['destination_url']??''),(int)($row['active']??0)===1,(int)($row['secret_version']??0),
            isset($row['previous_secret_version'])?(int)$row['previous_secret_version']:null,
            self::parseNullable($row['previous_secret_valid_until_utc']??null),(int)($row['max_attempts']??0),
            is_string($row['created_by_user_id']??null)?$row['created_by_user_id']:null,
            self::parse((string)($row['created_at_utc']??'')),self::parse((string)($row['updated_at_utc']??'')),
        );
    }

    /** @param array<string,mixed> $row */
    private static function deliveryFromRow(array $row):WebhookDelivery
    {
        return new WebhookDelivery(
            (string)($row['delivery_id']??''),(string)($row['subscription_id']??''),
            (string)($row['event_name']??''),(string)($row['body_json']??''),(int)($row['is_test']??0)===1,
            WebhookDeliveryStatus::from((string)($row['status']??'')),(int)($row['attempt_count']??0),
            (int)($row['max_attempts']??0),self::parseNullable($row['next_attempt_at_utc']??null),
            isset($row['last_http_status'])?(int)$row['last_http_status']:null,
            is_string($row['last_error_code']??null)?$row['last_error_code']:null,
            self::parse((string)($row['created_at_utc']??'')),self::parse((string)($row['updated_at_utc']??'')),
            self::parseNullable($row['delivered_at_utc']??null),
        );
    }

    private static function assertId(string $id):void
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$id)!==1)throw new RuntimeException('Webhook id is invalid.');
    }

    private static function assertEvent(string $event):void
    {
        if(preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D',$event)!==1){
            throw new RuntimeException('Webhook event name is invalid.');
        }
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullable(?DateTimeImmutable $value):?string
    {
        return $value===null?null:self::format($value);
    }

    private static function parse(string $value):DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC'));
        if(!$date instanceof DateTimeImmutable)throw new RuntimeException('Stored webhook timestamp is invalid.');
        return $date;
    }

    private static function parseNullable(mixed $value):?DateTimeImmutable
    {
        return is_string($value)&&$value!==''?self::parse($value):null;
    }
}
