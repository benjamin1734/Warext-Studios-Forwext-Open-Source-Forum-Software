<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Webhook;

use DateTimeImmutable;
use Forwext\Core\Content\Ai\Transport\AiModerationEndpoint;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpResponse;
use Forwext\Core\Content\Ai\Transport\AiModerationHttpTransport;
use Forwext\Core\Forum\Editor\HostAddressResolver;
use Forwext\Core\Infrastructure\Clock;
use Forwext\Core\Queue\JobId;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use Forwext\Core\Queue\QueueReservation;
use Forwext\Core\Security\Secret\SecretStore;
use Forwext\Core\Webhook\ApprovedWebhookDestination;
use Forwext\Core\Webhook\PinnedHttpsWebhookTransport;
use Forwext\Core\Webhook\WebhookDelivery;
use Forwext\Core\Webhook\WebhookDeliveryJobHandler;
use Forwext\Core\Webhook\WebhookDeliveryStatus;
use Forwext\Core\Webhook\WebhookDestinationPolicy;
use Forwext\Core\Webhook\WebhookException;
use Forwext\Core\Webhook\WebhookPlatformService;
use Forwext\Core\Webhook\WebhookRepository;
use Forwext\Core\Webhook\WebhookSecretManager;
use Forwext\Core\Webhook\WebhookSigner;
use Forwext\Core\Webhook\WebhookSubscription;
use Forwext\Core\Webhook\WebhookTransport;
use Forwext\Core\Webhook\WebhookTransportResult;
use PHPUnit\Framework\TestCase;

final class WebhookPlatformTest extends TestCase
{
    public function testDestinationPolicyRejectsPrivateResolution():void
    {
        $policy=new WebhookDestinationPolicy(new WebhookAddressResolverFixture(['127.0.0.1']));
        $this->expectException(WebhookException::class);
        $policy->approve('https://hooks.example.com/incoming');
    }

    public function testRotationDualSignatureRetryAndSuccess():void
    {
        $clock=new WebhookClockFixture(new DateTimeImmutable('2026-09-25T18:30:00Z'));
        $repo=new WebhookRepositoryFixture();
        $queue=new WebhookQueueFixture();
        $secretStore=new WebhookSecretStoreFixture();
        $secrets=new WebhookSecretManager($secretStore);
        $policy=new WebhookDestinationPolicy(new WebhookAddressResolverFixture(['93.184.216.34']));
        $platform=new WebhookPlatformService($repo,$secrets,$policy,$queue,$clock);

        $issued=$platform->createSubscription(
            'thread.created',
            'https://hooks.example.com/incoming?source=forwext',
            str_repeat('a',32),
            3,
        );
        self::assertStringStartsWith('whsec_',$issued->secret);
        self::assertSame('https://hooks.example.com/incoming?source=forwext',$issued->subscription->destinationUrl);

        $oldSecret=$issued->secret;
        $newSecret=$platform->rotateSecret($issued->subscription->id,3600);
        self::assertNotSame($oldSecret,$newSecret);

        try{
            $platform->rotateSecret($issued->subscription->id,3600);
            self::fail('Second rotation during the active grace window must fail.');
        }catch(WebhookException $e){
            self::assertStringContainsString('grace period',$e->getMessage());
        }

        $ids=$platform->publish('thread.created',['thread_id'=>str_repeat('b',32)]);
        self::assertCount(1,$ids);
        self::assertCount(1,$queue->jobs);

        $transport=new WebhookTransportFixture([
            WebhookTransportResult::failure('http_503',true,503),
            WebhookTransportResult::success(204),
        ]);
        $handler=new WebhookDeliveryJobHandler($repo,$secrets,$policy,$transport,$queue);
        $payload=json_encode(['delivery_id'=>$ids[0]],JSON_THROW_ON_ERROR);
        $firstTimestamp=$clock->now()->getTimestamp();

        self::assertSame(1,$handler->handle($payload,$clock->now()));
        $afterFirst=$repo->delivery($ids[0]);
        self::assertNotNull($afterFirst);
        self::assertSame(WebhookDeliveryStatus::RetryScheduled,$afterFirst->status);
        self::assertSame(1,$afterFirst->attemptCount);
        self::assertSame(503,$afterFirst->lastHttpStatus);
        self::assertSame(
            '2026-09-25T18:30:30+00:00',
            $afterFirst->nextAttemptAt?->format('c'),
        );
        self::assertCount(2,$queue->jobs);

        $signature=$transport->headers[0]['X-Forwext-Webhook-Signature']??'';
        self::assertStringContainsString('v2=',$signature);
        self::assertStringContainsString('v1=',$signature);
        self::assertSame(
            WebhookSigner::signature($newSecret,2,$ids[0],$firstTimestamp,$afterFirst->bodyJson),
            explode(',',$signature)[0],
        );
        self::assertSame(
            WebhookSigner::signature($oldSecret,1,$ids[0],$firstTimestamp,$afterFirst->bodyJson),
            explode(',',$signature)[1],
        );

        $clock->time=$afterFirst->nextAttemptAt??$clock->time;
        self::assertSame(1,$handler->handle($payload,$clock->now()));
        $afterSecond=$repo->delivery($ids[0]);
        self::assertNotNull($afterSecond);
        self::assertSame(WebhookDeliveryStatus::Delivered,$afterSecond->status);
        self::assertSame(2,$afterSecond->attemptCount);
        self::assertCount(2,$repo->attempts);
    }

    public function testTestDeliveryOnlyQueuesSelectedSubscription():void
    {
        $clock=new WebhookClockFixture(new DateTimeImmutable('2026-09-25T19:00:00Z'));
        $repo=new WebhookRepositoryFixture();
        $queue=new WebhookQueueFixture();
        $platform=new WebhookPlatformService(
            $repo,
            new WebhookSecretManager(new WebhookSecretStoreFixture()),
            new WebhookDestinationPolicy(new WebhookAddressResolverFixture(['93.184.216.34'])),
            $queue,
            $clock,
        );
        $issued=$platform->createSubscription('post.created','https://hooks.example.com/',null,2);
        $id=$platform->testSubscription($issued->subscription->id);

        $delivery=$repo->delivery($id);
        self::assertNotNull($delivery);
        self::assertTrue($delivery->test);
        self::assertStringContainsString('"test":true',$delivery->bodyJson);
        self::assertCount(1,$queue->jobs);
    }

    public function testPinnedTransportMapsRetryableAndTerminalStatuses():void
    {
        $destination=new ApprovedWebhookDestination(
            'https://hooks.example.com/',
            'hooks.example.com',
            443,
            '/',
            ['93.184.216.34'],
        );

        $retryTransport=new PinnedHttpsWebhookTransport(
            new WebhookHttpFixture(new AiModerationHttpResponse(429,[],'slow down')),
        );
        $retry=$retryTransport->post($destination,'{}',['X-Test'=>'1']);
        self::assertFalse($retry->successful);
        self::assertTrue($retry->retryable);
        self::assertSame('http_429',$retry->errorCode);

        $terminalTransport=new PinnedHttpsWebhookTransport(
            new WebhookHttpFixture(new AiModerationHttpResponse(410,[],'gone')),
        );
        $terminal=$terminalTransport->post($destination,'{}',['X-Test'=>'1']);
        self::assertFalse($terminal->successful);
        self::assertFalse($terminal->retryable);
        self::assertSame('http_410',$terminal->errorCode);
    }
}

final class WebhookAddressResolverFixture implements HostAddressResolver
{
    /** @param list<string> $addresses */
    public function __construct(private array $addresses){}

    public function resolve(string $host):array{return $this->addresses;}
}

final class WebhookClockFixture implements Clock
{
    public function __construct(public DateTimeImmutable $time){}
    public function now():DateTimeImmutable{return $this->time;}
}

final class WebhookSecretStoreFixture implements SecretStore
{
    /** @var array<string,string> */
    private array $values=[];

    public function has(string $name):bool{return array_key_exists($name,$this->values);}
    public function get(string $name):?string{return $this->values[$name]??null;}
    public function set(string $name,string $value):void{$this->values[$name]=$value;}
    public function delete(string $name):bool
    {
        $exists=array_key_exists($name,$this->values);
        unset($this->values[$name]);
        return $exists;
    }
    public function all():array{return $this->values;}
}

final class WebhookQueueFixture implements QueueDriver
{
    /** @var list<array{queue:string,type:string,payload:string,max:int,available:?DateTimeImmutable}> */
    public array $jobs=[];

    public function push(
        QueueName $queue,string $type,string $payload,int $maxAttempts=3,?DateTimeImmutable $availableAt=null,
    ):JobId{
        $this->jobs[]=[
            'queue'=>$queue->value(),'type'=>$type,'payload'=>$payload,'max'=>$maxAttempts,'available'=>$availableAt,
        ];
        return JobId::generate();
    }

    public function reserve(QueueName $queue,int $visibilityTimeoutSeconds=60):?QueueReservation{return null;}
    public function acknowledge(QueueReservation $reservation):void{}
    public function retry(QueueReservation $reservation,int $delaySeconds=0):void{}
    public function fail(QueueReservation $reservation,string $failureCode):void{}
}

final class WebhookTransportFixture implements WebhookTransport
{
    /** @var list<WebhookTransportResult> */
    private array $results;
    /** @var list<array<string,string>> */
    public array $headers=[];

    /** @param list<WebhookTransportResult> $results */
    public function __construct(array $results){$this->results=$results;}

    public function post(
        ApprovedWebhookDestination $destination,string $body,array $headers,int $timeoutMilliseconds=5000,
    ):WebhookTransportResult{
        $this->headers[]=$headers;
        return array_shift($this->results)??WebhookTransportResult::success(204);
    }
}

final class WebhookHttpFixture implements AiModerationHttpTransport
{
    public function __construct(private AiModerationHttpResponse $response){}

    public function postJson(
        AiModerationEndpoint $endpoint,
        array $headers,
        string $json,
        int $timeoutMilliseconds,
        int $maxResponseBytes=524288,
    ):AiModerationHttpResponse{
        return $this->response;
    }
}

final class WebhookRepositoryFixture implements WebhookRepository
{
    /** @var array<string,WebhookSubscription> */
    private array $subscriptions=[];
    /** @var array<string,WebhookDelivery> */
    private array $deliveries=[];
    /** @var list<array<string,mixed>> */
    public array $attempts=[];

    public function saveSubscription(WebhookSubscription $subscription):void
    {
        $this->subscriptions[$subscription->id]=$subscription;
    }

    public function subscription(string $subscriptionId):?WebhookSubscription
    {
        return $this->subscriptions[$subscriptionId]??null;
    }

    public function activeSubscriptionsForEvent(string $eventName):array
    {
        return array_values(array_filter(
            $this->subscriptions,
            static fn(WebhookSubscription $s):bool=>$s->active&&$s->eventName===$eventName,
        ));
    }

    public function updateSecretRotation(
        string $subscriptionId,
        int $secretVersion,
        int $previousSecretVersion,
        DateTimeImmutable $previousSecretValidUntil,
        DateTimeImmutable $updatedAt,
    ):void{
        $s=$this->subscriptions[$subscriptionId];
        $this->subscriptions[$subscriptionId]=new WebhookSubscription(
            $s->id,$s->eventName,$s->destinationUrl,$s->active,$secretVersion,$previousSecretVersion,
            $previousSecretValidUntil,$s->maxAttempts,$s->createdByUserId,$s->createdAt,$updatedAt,
        );
    }

    public function createDelivery(WebhookDelivery $delivery):void
    {
        $this->deliveries[$delivery->id]=$delivery;
    }

    public function delivery(string $deliveryId):?WebhookDelivery
    {
        return $this->deliveries[$deliveryId]??null;
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
    ):void{
        $this->attempts[]=compact('deliveryId','attemptNumber','result','httpStatus','errorCode','retryable');
    }

    public function markDelivered(string $deliveryId,int $attemptCount,DateTimeImmutable $at):void
    {
        $d=$this->deliveries[$deliveryId];
        $this->deliveries[$deliveryId]=new WebhookDelivery(
            $d->id,$d->subscriptionId,$d->eventName,$d->bodyJson,$d->test,WebhookDeliveryStatus::Delivered,
            $attemptCount,$d->maxAttempts,null,204,null,$d->createdAt,$at,$at,
        );
    }

    public function scheduleRetry(
        string $deliveryId,
        int $attemptCount,
        DateTimeImmutable $nextAttemptAt,
        ?int $httpStatus,
        string $errorCode,
        DateTimeImmutable $updatedAt,
    ):void{
        $d=$this->deliveries[$deliveryId];
        $this->deliveries[$deliveryId]=new WebhookDelivery(
            $d->id,$d->subscriptionId,$d->eventName,$d->bodyJson,$d->test,WebhookDeliveryStatus::RetryScheduled,
            $attemptCount,$d->maxAttempts,$nextAttemptAt,$httpStatus,$errorCode,$d->createdAt,$updatedAt,
        );
    }

    public function markFailed(
        string $deliveryId,
        int $attemptCount,
        ?int $httpStatus,
        string $errorCode,
        DateTimeImmutable $updatedAt,
    ):void{
        $d=$this->deliveries[$deliveryId];
        $this->deliveries[$deliveryId]=new WebhookDelivery(
            $d->id,$d->subscriptionId,$d->eventName,$d->bodyJson,$d->test,WebhookDeliveryStatus::Failed,
            $attemptCount,$d->maxAttempts,null,$httpStatus,$errorCode,$d->createdAt,$updatedAt,
        );
    }
}
