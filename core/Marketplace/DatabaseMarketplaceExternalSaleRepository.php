<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseMarketplaceExternalSaleRepository implements MarketplaceExternalSaleRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function link(EntityId $listingId):?MarketplaceExternalSaleLink
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_external_sale_links WHERE listing_id=:listing LIMIT 1',
            ['listing'=>$listingId->value()]
        ));
        if($row===null)return null;
        return new MarketplaceExternalSaleLink(
            EntityId::fromString((string)$row['listing_id']),
            (string)$row['target_url'],
            (string)$row['target_host'],
            (bool)$row['enabled'],
            EntityId::fromString((string)$row['updated_by_user_id']),
            self::at((string)$row['created_at_utc']),
            self::at((string)$row['updated_at_utc'])
        );
    }

    public function save(MarketplaceExternalSaleLink $link):void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_external_sale_links '
            . '(listing_id,target_url,target_host,enabled,updated_by_user_id,created_at_utc,updated_at_utc) '
            . 'VALUES (:listing,:url,:host,:enabled,:actor,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE target_url=VALUES(target_url),target_host=VALUES(target_host),enabled=VALUES(enabled),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            [
                'listing'=>$link->listingId->value(),'url'=>$link->targetUrl,'host'=>$link->targetHost,
                'enabled'=>$link->enabled,'actor'=>$link->updatedByUserId->value(),
                'created'=>self::format($link->createdAt),'updated'=>self::format($link->updatedAt)
            ]
        ));
    }

    public function delete(EntityId $listingId):void
    {
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_marketplace_external_sale_links WHERE listing_id=:listing',
            ['listing'=>$listingId->value()]
        ));
    }

    public function recordClick(EntityId $listingId,?EntityId $viewerUserId,string $targetHost,DateTimeImmutable $at):void
    {
        if($viewerUserId!==null)UserId::assert($viewerUserId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_external_sale_clicks '
            . '(click_id,listing_id,viewer_user_id,target_host,clicked_at_utc) VALUES (:id,:listing,:viewer,:host,:clicked)',
            [
                'id'=>bin2hex(random_bytes(16)),'listing'=>$listingId->value(),
                'viewer'=>$viewerUserId?->value(),'host'=>$targetHost,'clicked'=>self::format($at)
            ]
        ));
    }

    public function clickCount(EntityId $listingId):int
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_external_sale_clicks WHERE listing_id=:listing',
            ['listing'=>$listingId->value()]
        ));
    }

    private static function at(string $value):DateTimeImmutable
    {
        return new DateTimeImmutable($value,new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $value):string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
