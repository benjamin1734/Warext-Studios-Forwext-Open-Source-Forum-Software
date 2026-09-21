<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use Forwext\Core\Marketplace\MarketplaceOrderItem;
use InvalidArgumentException;

final readonly class DatabaseMarketplaceDeliveryRepository implements MarketplaceDeliveryRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function listingSetting(EntityId $listingId):?MarketplaceDeliveryListingSetting
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_delivery_settings WHERE listing_id=:listing LIMIT 1',
            ['listing'=>$listingId->value()]
        ));
        return $row===null?null:$this->hydrateSetting($row);
    }

    public function saveListingSetting(MarketplaceDeliveryListingSetting $setting):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_delivery_settings '
            . '(listing_id,delivery_type,asset_id,updated_by_user_id,updated_at_utc) '
            . 'VALUES (:listing,:type,:asset,:actor,:updated) '
            . 'ON DUPLICATE KEY UPDATE delivery_type=VALUES(delivery_type),asset_id=VALUES(asset_id),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'listing'=>$setting->listingId->value(),'type'=>$setting->type->value,
                'asset'=>$setting->assetId?->value(),'actor'=>$setting->updatedByUserId->value(),
                'updated'=>self::format($setting->updatedAt),
            ]
        ));
    }

    public function asset(EntityId $assetId):?MarketplaceDeliveryAsset
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_delivery_assets WHERE asset_id=:asset LIMIT 1',
            ['asset'=>$assetId->value()]
        ));
        return $row===null?null:$this->hydrateAsset($row);
    }

    public function saveAsset(MarketplaceDeliveryAsset $asset):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_delivery_assets '
            . '(asset_id,listing_id,storage_path,filename,media_type,size_bytes,sha256,created_by_user_id,created_at_utc) '
            . 'VALUES (:asset,:listing,:path,:filename,:media_type,:size,:sha256,:actor,:created)',
            [
                'asset'=>$asset->assetId->value(),'listing'=>$asset->listingId->value(),
                'path'=>$asset->storagePath,'filename'=>$asset->filename,'media_type'=>$asset->mediaType,
                'size'=>$asset->sizeBytes,'sha256'=>$asset->sha256,'actor'=>$asset->createdByUserId->value(),
                'created'=>self::format($asset->createdAt),
            ]
        ));
    }

    public function insertKey(MarketplaceDeliveryKey $key):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_delivery_keys '
            . '(key_id,listing_id,encrypted_value,fingerprint,key_state,assigned_order_item_id,'
            . 'created_by_user_id,created_at_utc,assigned_at_utc) '
            . 'VALUES (:key_id,:listing,:encrypted,:fingerprint,:state,:item,:actor,:created,:assigned)',
            [
                'key_id'=>$key->keyId->value(),'listing'=>$key->listingId->value(),
                'encrypted'=>$key->encryptedValue,'fingerprint'=>$key->fingerprint,'state'=>$key->state->value,
                'item'=>$key->assignedOrderItemId?->value(),'actor'=>$key->createdByUserId->value(),
                'created'=>self::format($key->createdAt),'assigned'=>$key->assignedAt===null?null:self::format($key->assignedAt),
            ]
        ));
    }

    public function key(EntityId $keyId):?MarketplaceDeliveryKey
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_delivery_keys WHERE key_id=:key_id LIMIT 1',
            ['key_id'=>$keyId->value()]
        ));
        return $row===null?null:$this->hydrateKey($row);
    }

    public function keyFingerprintExists(EntityId $listingId,string $fingerprint):bool
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1){
            throw new InvalidArgumentException('Marketplace delivery key fingerprint is invalid.');
        }
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_delivery_keys WHERE listing_id=:listing AND fingerprint=:fingerprint',
            ['listing'=>$listingId->value(),'fingerprint'=>$fingerprint]
        ))>0;
    }

    public function availableKeyCount(EntityId $listingId):int
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_marketplace_delivery_keys WHERE listing_id=:listing AND key_state='available'",
            ['listing'=>$listingId->value()]
        ));
    }

    public function reserveAvailableKey(
        EntityId $listingId,
        EntityId $orderItemId,
        DateTimeImmutable $at,
    ):?MarketplaceDeliveryKey{
        $row=$this->database->fetchOne(new CompiledQuery(
            "SELECT * FROM forwext_marketplace_delivery_keys WHERE listing_id=:listing "
            . "AND key_state='available' ORDER BY created_at_utc,key_id LIMIT 1 FOR UPDATE",
            ['listing'=>$listingId->value()]
        ));
        if($row===null)return null;
        $key=$this->hydrateKey($row);
        $changed=$this->database->execute(new CompiledQuery(
            "UPDATE forwext_marketplace_delivery_keys SET key_state='reserved',assigned_order_item_id=:item,"
            . "assigned_at_utc=:assigned WHERE key_id=:key_id AND key_state='available'",
            ['item'=>$orderItemId->value(),'assigned'=>self::format($at),'key_id'=>$key->keyId->value()]
        ));
        if($changed!==1)throw new InvalidArgumentException('Marketplace delivery key reservation race detected.');
        return new MarketplaceDeliveryKey(
            $key->keyId,$key->listingId,$key->encryptedValue,$key->fingerprint,
            MarketplaceDeliveryKeyState::Reserved,$orderItemId,$key->createdByUserId,$key->createdAt,$at
        );
    }

    public function activateReservedKey(EntityId $orderItemId,DateTimeImmutable $at):?MarketplaceDeliveryKey
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            "SELECT * FROM forwext_marketplace_delivery_keys WHERE assigned_order_item_id=:item "
            . "AND key_state IN ('reserved','assigned') LIMIT 1 FOR UPDATE",
            ['item'=>$orderItemId->value()]
        ));
        if($row===null)return null;
        $key=$this->hydrateKey($row);
        if($key->state===MarketplaceDeliveryKeyState::Assigned)return $key;
        $changed=$this->database->execute(new CompiledQuery(
            "UPDATE forwext_marketplace_delivery_keys SET key_state='assigned',assigned_at_utc=:assigned "
            . "WHERE key_id=:key_id AND key_state='reserved'",
            ['assigned'=>self::format($at),'key_id'=>$key->keyId->value()]
        ));
        if($changed!==1)throw new InvalidArgumentException('Marketplace delivery key activation race detected.');
        return new MarketplaceDeliveryKey(
            $key->keyId,$key->listingId,$key->encryptedValue,$key->fingerprint,
            MarketplaceDeliveryKeyState::Assigned,$orderItemId,$key->createdByUserId,$key->createdAt,$at
        );
    }

    public function releaseReservedKey(EntityId $orderItemId):void
    {
        $this->database->execute(new CompiledQuery(
            "UPDATE forwext_marketplace_delivery_keys SET key_state='available',assigned_order_item_id=NULL,"
            . "assigned_at_utc=NULL WHERE assigned_order_item_id=:item AND key_state='reserved'",
            ['item'=>$orderItemId->value()]
        ));
    }

    public function initializeOrderItem(MarketplaceOrderItem $item,DateTimeImmutable $at):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_order_item_deliveries '
            . '(order_item_id,order_id,delivery_type,delivery_state,asset_id,key_id,encrypted_manual_value,'
            . 'fulfilled_by_user_id,download_count,ready_at_utc,delivered_at_utc,last_download_at_utc,created_at_utc,updated_at_utc) '
            . "VALUES (:item,:order_id,:type,'pending',:asset,NULL,NULL,NULL,0,NULL,NULL,NULL,:created,:updated) "
            . 'ON DUPLICATE KEY UPDATE order_item_id=order_item_id',
            [
                'item'=>$item->itemId->value(),'order_id'=>$item->orderId->value(),
                'type'=>$item->deliveryType->value,'asset'=>$item->deliveryAssetId?->value(),
                'created'=>self::format($at),'updated'=>self::format($at),
            ]
        ));
    }

    public function delivery(EntityId $orderItemId,bool $forUpdate=false):?MarketplaceDeliveryRecord
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_order_item_deliveries WHERE order_item_id=:item LIMIT 1'
            .($forUpdate?' FOR UPDATE':''),
            ['item'=>$orderItemId->value()]
        ));
        return $row===null?null:$this->hydrateDelivery($row);
    }

    public function deliveriesForOrder(EntityId $orderId,bool $forUpdate=false):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_order_item_deliveries WHERE order_id=:order_id '
            . 'ORDER BY order_item_id'.($forUpdate?' FOR UPDATE':''),
            ['order_id'=>$orderId->value()]
        ));
        return array_map($this->hydrateDelivery(...),$rows);
    }

    public function saveDelivery(MarketplaceDeliveryRecord $record):void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_marketplace_order_item_deliveries SET delivery_state=:state,asset_id=:asset,key_id=:key_id,'
            . 'encrypted_manual_value=:manual,fulfilled_by_user_id=:actor,download_count=:download_count,'
            . 'ready_at_utc=:ready,delivered_at_utc=:delivered,last_download_at_utc=:last_download,updated_at_utc=:updated '
            . 'WHERE order_item_id=:item',
            [
                'state'=>$record->state->value,'asset'=>$record->assetId?->value(),'key_id'=>$record->keyId?->value(),
                'manual'=>$record->encryptedManualValue,'actor'=>$record->fulfilledByUserId?->value(),
                'download_count'=>$record->downloadCount,
                'ready'=>$record->readyAt===null?null:self::format($record->readyAt),
                'delivered'=>$record->deliveredAt===null?null:self::format($record->deliveredAt),
                'last_download'=>$record->lastDownloadAt===null?null:self::format($record->lastDownloadAt),
                'updated'=>self::format($record->updatedAt),'item'=>$record->orderItemId->value(),
            ]
        ));
    }

    public function recordDownload(EntityId $orderItemId,DateTimeImmutable $at):void
    {
        $changed=$this->database->execute(new CompiledQuery(
            'UPDATE forwext_marketplace_order_item_deliveries SET download_count=download_count+1,'
            . 'last_download_at_utc=:download,updated_at_utc=:updated WHERE order_item_id=:item',
            ['download'=>self::format($at),'updated'=>self::format($at),'item'=>$orderItemId->value()]
        ));
        if($changed!==1)throw new InvalidArgumentException('Marketplace delivery record was not found.');
    }

    /** @param array<string,mixed> $row */
    private function hydrateSetting(array $row):MarketplaceDeliveryListingSetting
    {
        return new MarketplaceDeliveryListingSetting(
            EntityId::fromString((string)$row['listing_id']),
            MarketplaceDeliveryType::from((string)$row['delivery_type']),
            self::id($row['asset_id']??null),
            EntityId::fromString((string)$row['updated_by_user_id']),
            self::at((string)$row['updated_at_utc'])
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateAsset(array $row):MarketplaceDeliveryAsset
    {
        return new MarketplaceDeliveryAsset(
            EntityId::fromString((string)$row['asset_id']),EntityId::fromString((string)$row['listing_id']),
            (string)$row['storage_path'],(string)$row['filename'],(string)$row['media_type'],(int)$row['size_bytes'],
            (string)$row['sha256'],EntityId::fromString((string)$row['created_by_user_id']),
            self::at((string)$row['created_at_utc'])
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateKey(array $row):MarketplaceDeliveryKey
    {
        return new MarketplaceDeliveryKey(
            EntityId::fromString((string)$row['key_id']),EntityId::fromString((string)$row['listing_id']),
            (string)$row['encrypted_value'],(string)$row['fingerprint'],
            MarketplaceDeliveryKeyState::from((string)$row['key_state']),
            self::id($row['assigned_order_item_id']??null),
            EntityId::fromString((string)$row['created_by_user_id']),
            self::at((string)$row['created_at_utc']),self::date($row['assigned_at_utc']??null)
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateDelivery(array $row):MarketplaceDeliveryRecord
    {
        return new MarketplaceDeliveryRecord(
            EntityId::fromString((string)$row['order_item_id']),EntityId::fromString((string)$row['order_id']),
            MarketplaceDeliveryType::from((string)$row['delivery_type']),
            MarketplaceDeliveryState::from((string)$row['delivery_state']),
            self::id($row['asset_id']??null),self::id($row['key_id']??null),
            self::nullable($row['encrypted_manual_value']??null),self::id($row['fulfilled_by_user_id']??null),
            (int)$row['download_count'],self::at((string)$row['created_at_utc']),self::at((string)$row['updated_at_utc']),
            self::date($row['ready_at_utc']??null),self::date($row['delivered_at_utc']??null),
            self::date($row['last_download_at_utc']??null)
        );
    }

    private static function id(mixed $value):?EntityId
    {
        return $value===null?null:EntityId::fromString((string)$value);
    }
    private static function nullable(mixed $value):?string{return $value===null?null:(string)$value;}
    private static function date(mixed $value):?DateTimeImmutable{return $value===null?null:self::at((string)$value);}
    private static function at(string $value):DateTimeImmutable{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
    private static function format(DateTimeImmutable $value):string{return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
}
