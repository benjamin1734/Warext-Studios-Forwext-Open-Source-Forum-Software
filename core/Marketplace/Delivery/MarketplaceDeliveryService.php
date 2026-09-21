<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use Forwext\Core\Marketplace\MarketplaceListing;
use Forwext\Core\Marketplace\MarketplaceOrder;
use Forwext\Core\Marketplace\MarketplaceOrderItem;
use Forwext\Core\Marketplace\MarketplaceOrderState;
use Forwext\Core\Marketplace\MarketplacePaymentState;
use Forwext\Core\Marketplace\MarketplacePurchaseRepository;
use Forwext\Core\Marketplace\MarketplaceService;
use Forwext\Core\Payment\PaymentOrderPaidListener;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use InvalidArgumentException;
use Throwable;

final readonly class MarketplaceDeliveryService implements MarketplaceDeliveryCoordinator,PaymentOrderPaidListener
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private MarketplaceDeliveryRepository $deliveries,
        private MarketplacePurchaseRepository $orders,
        private MarketplaceService $marketplace,
        private PermissionAuthorizer $authorizer,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
        private MarketplaceDeliverySecretProtector $secrets,
        private AuditRecorder $audit,
    ){}

    public function listingSetting(EntityId $actor,EntityId $listingId):?MarketplaceDeliveryListingSetting
    {
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->requireListingManager($actor,$listing);
        return $this->deliveries->listingSetting($listingId);
    }

    public function configureListing(
        EntityId $actor,
        EntityId $listingId,
        MarketplaceDeliveryType $type,
        ?EntityId $assetId,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):MarketplaceDeliveryListingSetting{
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->requireListingManager($actor,$listing);
        $at=self::utc($now);

        if($type===MarketplaceDeliveryType::Download){
            if($assetId===null)throw new InvalidArgumentException('Download delivery requires an uploaded asset.');
            $asset=$this->deliveries->asset($assetId)
                ??throw new InvalidArgumentException('Marketplace delivery asset was not found.');
            if(!$asset->listingId->equals($listingId)){
                throw new InvalidArgumentException('Marketplace delivery asset does not belong to the listing.');
            }
        }elseif($assetId!==null){
            throw new InvalidArgumentException('Only download delivery may reference an asset.');
        }

        $before=$this->deliveries->listingSetting($listingId);
        $setting=new MarketplaceDeliveryListingSetting($listingId,$type,$assetId,$actor,$at);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('marketplace.delivery.configure'),
            'marketplace.delivery_setting',$listingId->value(),null,'marketplace.delivery.configure',
            $requestId??AuditRequestId::generate(),
            $before===null?[]:['type'=>$before->type->value,'asset_id'=>$before->assetId?->value()],
            ['type'=>$setting->type->value,'asset_id'=>$setting->assetId?->value()],
            $at
        );
        $this->audit->mutate($event,fn():mixed=>$this->deliveries->saveListingSetting($setting));
        return $setting;
    }

    public function uploadAsset(
        EntityId $actor,
        EntityId $listingId,
        string $contents,
        string $filename,
        DateTimeImmutable $now,
    ):MarketplaceDeliveryAsset{
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->requireListingManager($actor,$listing);
        $filename=self::filename($filename);

        $inspection=$this->inspector->inspect($contents);
        if(!in_array($inspection->mediaType,[
            'application/zip','application/pdf','text/plain',
            'image/jpeg','image/png','image/gif','image/webp',
        ],true)){
            throw new InvalidArgumentException('Marketplace delivery file type is not allowed.');
        }

        $assetId=MarketplaceDeliveryAsset::generateId();
        $digest=hash('sha256',$inspection->contents.'|'.$assetId->value());
        $path=StoragePath::fromString(sprintf(
            'marketplace-delivery/%s/%s.%s',$listingId->value(),$digest,$inspection->extension
        ));
        $this->storage->put($path,$inspection->contents,StorageVisibility::Private,$inspection->mediaType);
        $asset=new MarketplaceDeliveryAsset(
            $assetId,$listingId,$path->value(),$filename,$inspection->mediaType,
            $inspection->sizeBytes,$inspection->sha256,$actor,self::utc($now)
        );

        try{
            $this->deliveries->saveAsset($asset);
        }catch(Throwable $exception){
            try{$this->storage->delete($path,StorageVisibility::Private);}catch(Throwable){}
            throw $exception;
        }
        return $asset;
    }

    /** @param list<string> $values */
    public function addKeys(
        EntityId $actor,
        EntityId $listingId,
        array $values,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):int{
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->requireListingManager($actor,$listing);
        if($values===[]||count($values)>500)throw new InvalidArgumentException('Marketplace delivery key batch is invalid.');
        $at=self::utc($now);

        $created=$this->database->transaction(function()use($actor,$listingId,$values,$at):int{
            $count=0;$seen=[];
            foreach($values as $value){
                if(!is_string($value))throw new InvalidArgumentException('Marketplace delivery key must be text.');
                $fingerprint=$this->secrets->fingerprint($value);
                if(isset($seen[$fingerprint])||$this->deliveries->keyFingerprintExists($listingId,$fingerprint))continue;
                $seen[$fingerprint]=true;
                $this->deliveries->insertKey(new MarketplaceDeliveryKey(
                    MarketplaceDeliveryKey::generateId(),$listingId,$this->secrets->protect($value),$fingerprint,
                    MarketplaceDeliveryKeyState::Available,null,$actor,$at
                ));
                ++$count;
            }
            return $count;
        });

        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('marketplace.delivery.keys.add'),
            'marketplace.delivery_key_pool',$listingId->value(),null,'marketplace.delivery.keys.add',
            $requestId??AuditRequestId::generate(),[],['created_count'=>$created],$at
        );
        $this->audit->append($event);
        return $created;
    }

    public function availableKeyCount(EntityId $actor,EntityId $listingId):int
    {
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->requireListingManager($actor,$listing);
        return $this->deliveries->availableKeyCount($listingId);
    }

    public function snapshotForListing(EntityId $listingId):MarketplaceDeliverySnapshot
    {
        $setting=$this->deliveries->listingSetting($listingId);
        if($setting===null)return MarketplaceDeliverySnapshot::manual();
        if($setting->type===MarketplaceDeliveryType::Download){
            $asset=$setting->assetId===null?null:$this->deliveries->asset($setting->assetId);
            if($asset===null||!$asset->listingId->equals($listingId)){
                throw new InvalidArgumentException('Marketplace download delivery asset is unavailable.');
            }
        }
        return new MarketplaceDeliverySnapshot($setting->type,$setting->assetId);
    }

    public function initializeOrderItem(MarketplaceOrderItem $item,DateTimeImmutable $at):void
    {
        $at=self::utc($at);
        $this->deliveries->initializeOrderItem($item,$at);
        if(!in_array($item->deliveryType,[MarketplaceDeliveryType::License,MarketplaceDeliveryType::Key],true))return;

        $key=$this->deliveries->reserveAvailableKey($item->listingId,$item->itemId,$at);
        if($key===null){
            throw new InvalidArgumentException('Marketplace delivery key stock is unavailable.');
        }
        $record=$this->deliveries->delivery($item->itemId,true)
            ??throw new InvalidArgumentException('Marketplace delivery record was not initialized.');
        $this->deliveries->saveDelivery(new MarketplaceDeliveryRecord(
            $record->orderItemId,$record->orderId,$record->type,$record->state,$record->assetId,$key->keyId,
            $record->encryptedManualValue,$record->fulfilledByUserId,$record->downloadCount,
            $record->createdAt,$at,$record->readyAt,$record->deliveredAt,$record->lastDownloadAt
        ));
    }

    public function cancelOrderReservations(EntityId $orderId,DateTimeImmutable $at):void
    {
        $at=self::utc($at);
        foreach($this->deliveries->deliveriesForOrder($orderId,true) as $record){
            if($record->state===MarketplaceDeliveryState::Cancelled)continue;
            if(in_array($record->type,[MarketplaceDeliveryType::License,MarketplaceDeliveryType::Key],true)){
                $this->deliveries->releaseReservedKey($record->orderItemId);
            }
            $this->deliveries->saveDelivery(new MarketplaceDeliveryRecord(
                $record->orderItemId,$record->orderId,$record->type,MarketplaceDeliveryState::Cancelled,
                $record->assetId,null,null,$record->fulfilledByUserId,$record->downloadCount,
                $record->createdAt,$at,$record->readyAt,null,$record->lastDownloadAt
            ));
        }
    }

    public function onOrderPaid(EntityId $orderId,DateTimeImmutable $at):void
    {
        $at=self::utc($at);
        $this->database->transaction(function()use($orderId,$at):void{
            $order=$this->orders->order($orderId,true)
                ??throw new InvalidArgumentException('Marketplace order was not found.');
            if($order->paymentState!==MarketplacePaymentState::Paid
                ||$order->state===MarketplaceOrderState::Cancelled
            )return;

            foreach($this->deliveries->deliveriesForOrder($orderId,true) as $record){
                if($record->state!==MarketplaceDeliveryState::Pending)continue;
                if($record->type===MarketplaceDeliveryType::Manual)continue;

                $keyId=$record->keyId;
                if(in_array($record->type,[MarketplaceDeliveryType::License,MarketplaceDeliveryType::Key],true)){
                    $key=$this->deliveries->activateReservedKey($record->orderItemId,$at);
                    if($key===null)throw new InvalidArgumentException('Reserved delivery key is unavailable.');
                    $keyId=$key->keyId;
                }
                $this->deliveries->saveDelivery(new MarketplaceDeliveryRecord(
                    $record->orderItemId,$record->orderId,$record->type,MarketplaceDeliveryState::Ready,
                    $record->assetId,$keyId,$record->encryptedManualValue,null,$record->downloadCount,
                    $record->createdAt,$at,$at,null,$record->lastDownloadAt
                ));
            }
            $this->syncOrderDelivery($order,null,'delivery.payment_settlement',$at);
        });
    }

    public function fulfillManual(
        EntityId $actor,
        EntityId $orderItemId,
        string $value,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):MarketplaceDeliveryRecord{
        $at=self::utc($now);
        $before=$this->deliveries->delivery($orderItemId)
            ??throw new InvalidArgumentException('Marketplace delivery record was not found.');
        $order=$this->orders->order($before->orderId)
            ??throw new InvalidArgumentException('Marketplace order was not found.');
        $this->requireOrderManager($actor,$order);
        if($before->type!==MarketplaceDeliveryType::Manual){
            throw new InvalidArgumentException('Only manual deliveries accept seller fulfillment text.');
        }
        if($order->paymentState!==MarketplacePaymentState::Paid
            ||!in_array($order->state,[MarketplaceOrderState::Confirmed,MarketplaceOrderState::Completed],true)
        ){
            throw new InvalidArgumentException('Marketplace order is not paid and eligible for fulfillment.');
        }

        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('marketplace.delivery.fulfill'),
            'marketplace.order_item_delivery',$orderItemId->value(),null,'marketplace.delivery.fulfill',
            $requestId??AuditRequestId::generate(),
            ['state'=>$before->state->value],['state'=>MarketplaceDeliveryState::Ready->value],$at
        );

        return $this->audit->mutate($event,function()use($actor,$orderItemId,$value,$at):MarketplaceDeliveryRecord{
            $record=$this->deliveries->delivery($orderItemId,true)
                ??throw new InvalidArgumentException('Marketplace delivery record was not found.');
            $order=$this->orders->order($record->orderId,true)
                ??throw new InvalidArgumentException('Marketplace order was not found.');
            if($record->type!==MarketplaceDeliveryType::Manual){
                throw new InvalidArgumentException('Marketplace delivery type changed unexpectedly.');
            }
            $updated=new MarketplaceDeliveryRecord(
                $record->orderItemId,$record->orderId,$record->type,MarketplaceDeliveryState::Ready,
                null,null,$this->secrets->protect($value),$actor,$record->downloadCount,
                $record->createdAt,$at,$record->readyAt??$at,null,$record->lastDownloadAt
            );
            $this->deliveries->saveDelivery($updated);
            $this->syncOrderDelivery($order,$actor,'delivery.manual_ready',$at);
            return $updated;
        });
    }

    public function reveal(EntityId $actor,EntityId $orderItemId,DateTimeImmutable $now):string
    {
        $record=$this->deliveries->delivery($orderItemId)
            ??throw new InvalidArgumentException('Marketplace delivery record was not found.');
        if($record->type===MarketplaceDeliveryType::Download){
            throw new InvalidArgumentException('Download deliveries must use the controlled download route.');
        }
        $order=$this->orders->order($record->orderId)
            ??throw new InvalidArgumentException('Marketplace order was not found.');
        $this->requireBuyerAccess($actor,$order);
        self::requirePaidDeliverable($order);
        if(!in_array($record->state,[MarketplaceDeliveryState::Ready,MarketplaceDeliveryState::Delivered],true)){
            throw new InvalidArgumentException('Marketplace delivery is not ready.');
        }

        $value=match($record->type){
            MarketplaceDeliveryType::License,MarketplaceDeliveryType::Key=>$this->keyValue($record),
            MarketplaceDeliveryType::Manual=>$record->encryptedManualValue===null
                ?throw new InvalidArgumentException('Marketplace manual delivery payload is unavailable.')
                :$this->secrets->reveal($record->encryptedManualValue),
            default=>throw new InvalidArgumentException('Marketplace delivery cannot be revealed.'),
        };
        $this->markDeliveredForBuyer($actor,$record,self::utc($now),'delivery.revealed');
        return $value;
    }

    public function download(EntityId $actor,EntityId $orderItemId,DateTimeImmutable $now):MarketplaceDeliveryDownload
    {
        $record=$this->deliveries->delivery($orderItemId)
            ??throw new InvalidArgumentException('Marketplace delivery record was not found.');
        if($record->type!==MarketplaceDeliveryType::Download||$record->assetId===null){
            throw new InvalidArgumentException('Marketplace delivery is not a download.');
        }
        $order=$this->orders->order($record->orderId)
            ??throw new InvalidArgumentException('Marketplace order was not found.');
        $this->requireBuyerAccess($actor,$order);
        self::requirePaidDeliverable($order);
        if(!in_array($record->state,[MarketplaceDeliveryState::Ready,MarketplaceDeliveryState::Delivered],true)){
            throw new InvalidArgumentException('Marketplace download is not ready.');
        }

        $asset=$this->deliveries->asset($record->assetId)
            ??throw new InvalidArgumentException('Marketplace delivery asset was not found.');
        $contents=$this->storage->read(StoragePath::fromString($asset->storagePath),StorageVisibility::Private);
        if(strlen($contents)!==$asset->sizeBytes||!hash_equals($asset->sha256,hash('sha256',$contents))){
            throw new InvalidArgumentException('Marketplace delivery asset integrity verification failed.');
        }

        $at=self::utc($now);
        $this->database->transaction(function()use($actor,$orderItemId,$at):void{
            $record=$this->deliveries->delivery($orderItemId,true)
                ??throw new InvalidArgumentException('Marketplace delivery record was not found.');
            $order=$this->orders->order($record->orderId,true)
                ??throw new InvalidArgumentException('Marketplace order was not found.');
            $this->requireBuyerAccess($actor,$order);
            self::requirePaidDeliverable($order);
            $this->deliveries->recordDownload($orderItemId,$at);
            if($order->buyerUserId->equals($actor)&&$record->state===MarketplaceDeliveryState::Ready){
                $delivered=new MarketplaceDeliveryRecord(
                    $record->orderItemId,$record->orderId,$record->type,MarketplaceDeliveryState::Delivered,
                    $record->assetId,$record->keyId,$record->encryptedManualValue,$record->fulfilledByUserId,
                    $record->downloadCount+1,$record->createdAt,$at,$record->readyAt,$at,$at
                );
                $this->deliveries->saveDelivery($delivered);
                $this->syncOrderDelivery($order,$actor,'delivery.downloaded',$at);
            }
        });

        return new MarketplaceDeliveryDownload($contents,$asset->mediaType,$asset->filename);
    }

    /**
     * @return array{order:MarketplaceOrder,items:list<MarketplaceOrderItem>,deliveries:list<MarketplaceDeliveryRecord>}
     */
    public function orderSnapshot(EntityId $actor,EntityId $orderId):array
    {
        $order=$this->orders->order($orderId)
            ??throw new InvalidArgumentException('Marketplace order was not found.');
        if(!$order->buyerUserId->equals($actor)&&!$order->sellerUserId->equals($actor)
            &&!$this->allows($actor,'marketplace.delivery.manage_all')
        ){
            throw new InvalidArgumentException('Marketplace order was not found.');
        }
        return [
            'order'=>$order,
            'items'=>$this->orders->orderItems($orderId),
            'deliveries'=>$this->deliveries->deliveriesForOrder($orderId),
        ];
    }

    private function markDeliveredForBuyer(
        EntityId $actor,
        MarketplaceDeliveryRecord $initial,
        DateTimeImmutable $at,
        string $action,
    ):void{
        if(($this->orders->order($initial->orderId)?->buyerUserId->equals($actor))!==true)return;
        $this->database->transaction(function()use($actor,$initial,$at,$action):void{
            $record=$this->deliveries->delivery($initial->orderItemId,true)
                ??throw new InvalidArgumentException('Marketplace delivery record was not found.');
            if($record->state===MarketplaceDeliveryState::Delivered)return;
            $order=$this->orders->order($record->orderId,true)
                ??throw new InvalidArgumentException('Marketplace order was not found.');
            self::requirePaidDeliverable($order);
            $updated=new MarketplaceDeliveryRecord(
                $record->orderItemId,$record->orderId,$record->type,MarketplaceDeliveryState::Delivered,
                $record->assetId,$record->keyId,$record->encryptedManualValue,$record->fulfilledByUserId,
                $record->downloadCount,$record->createdAt,$at,$record->readyAt,$at,$record->lastDownloadAt
            );
            $this->deliveries->saveDelivery($updated);
            $this->syncOrderDelivery($order,$actor,$action,$at);
        });
    }

    private function keyValue(MarketplaceDeliveryRecord $record):string
    {
        if($record->keyId===null)throw new InvalidArgumentException('Marketplace delivery key is unavailable.');
        $key=$this->deliveries->key($record->keyId)
            ??throw new InvalidArgumentException('Marketplace delivery key was not found.');
        if($key->state!==MarketplaceDeliveryKeyState::Assigned
            ||$key->assignedOrderItemId===null
            ||!$key->assignedOrderItemId->equals($record->orderItemId)
        ){
            throw new InvalidArgumentException('Marketplace delivery key assignment is invalid.');
        }
        return $this->secrets->reveal($key->encryptedValue);
    }

    private function syncOrderDelivery(
        MarketplaceOrder $order,
        ?EntityId $actor,
        string $action,
        DateTimeImmutable $at,
    ):void{
        $records=$this->deliveries->deliveriesForOrder($order->orderId);
        if($records===[])return;

        $allDelivered=true;$allReady=true;
        foreach($records as $record){
            if($record->state!==MarketplaceDeliveryState::Delivered)$allDelivered=false;
            if(!in_array($record->state,[MarketplaceDeliveryState::Ready,MarketplaceDeliveryState::Delivered],true)){
                $allReady=false;
            }
        }
        $delivery=$allDelivered
            ?MarketplaceDeliveryState::Delivered
            :($allReady?MarketplaceDeliveryState::Ready:MarketplaceDeliveryState::Pending);
        if($order->state===MarketplaceOrderState::Cancelled)$delivery=MarketplaceDeliveryState::Cancelled;

        $orderState=$order->state;
        if($delivery===MarketplaceDeliveryState::Delivered&&$orderState===MarketplaceOrderState::Confirmed){
            $orderState=MarketplaceOrderState::Completed;
        }
        if($delivery===$order->deliveryState&&$orderState===$order->state)return;

        $updated=new MarketplaceOrder(
            $order->orderId,$order->orderNumber,$order->checkoutKey,$order->buyerUserId,$order->sellerUserId,
            $order->currency,$order->subtotalMinor,$order->totalMinor,$orderState,$order->paymentState,$delivery,
            $order->billing,$order->receiptMetadata,$order->createdAt,$at
        );
        $this->orders->saveOrderStates($updated);
        $this->orders->recordOrderHistory(
            $order->orderId,$actor,$action,$order->state,$updated->state,
            $order->paymentState,$updated->paymentState,$order->deliveryState,$updated->deliveryState,$at
        );
    }

    private function requireListingManager(EntityId $actor,MarketplaceListing $listing):void
    {
        if($listing->sellerUserId->equals($actor)){
            $this->require($actor,'marketplace.delivery.manage_own');
            return;
        }
        $this->require($actor,'marketplace.delivery.manage_all');
    }

    private function requireOrderManager(EntityId $actor,MarketplaceOrder $order):void
    {
        if($order->sellerUserId->equals($actor)){
            $this->require($actor,'marketplace.delivery.manage_own');
            return;
        }
        $this->require($actor,'marketplace.delivery.manage_all');
    }

    private function requireBuyerAccess(EntityId $actor,MarketplaceOrder $order):void
    {
        if($order->buyerUserId->equals($actor))return;
        $this->require($actor,'marketplace.delivery.manage_all');
    }

    private function require(EntityId $actor,string $permission):void
    {
        $decision=$this->authorizer->resolve($actor,PermissionKey::fromString($permission));
        if(!$decision->isAllowed())throw new PermissionDeniedException($decision);
    }

    private function allows(EntityId $actor,string $permission):bool
    {
        return $this->authorizer->allows($actor,PermissionKey::fromString($permission));
    }

    private static function requirePaidDeliverable(MarketplaceOrder $order):void
    {
        if($order->paymentState!==MarketplacePaymentState::Paid
            ||!in_array($order->state,[MarketplaceOrderState::Confirmed,MarketplaceOrderState::Completed],true)
        ){
            throw new InvalidArgumentException('Marketplace order is not eligible for delivery access.');
        }
    }

    private static function filename(string $value):string
    {
        $value=trim($value);
        if($value===''||strlen($value)>191||preg_match('//u',$value)!==1
            ||preg_match('/[\\\/\x00-\x1F\x7F]/',$value)===1
        ){
            throw new InvalidArgumentException('Marketplace delivery filename is invalid.');
        }
        return $value;
    }

    private static function utc(DateTimeImmutable $at):DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
