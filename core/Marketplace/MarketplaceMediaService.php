<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use InvalidArgumentException;
use Throwable;

final readonly class MarketplaceMediaService
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private MarketplaceService $marketplace,
        private MarketplaceRepository $repository,
        private StorageDriver $storage,
        private AttachmentInspector $inspector,
    ){}

    public function upload(
        EntityId $actor,
        EntityId $listingId,
        string $contents,
        string $alt,
        DateTimeImmutable $at,
    ):MarketplaceMedia{
        $listing=$this->marketplace->managementListing($actor,$listingId);
        if(preg_match('//u',$alt)!==1||strlen($alt)>500){
            throw new InvalidArgumentException('Marketplace media alternative text is invalid.');
        }
        $inspection=$this->inspector->inspect($contents);
        if(!in_array($inspection->mediaType,['image/jpeg','image/png','image/webp'],true)
            ||!in_array($inspection->extension,['jpg','png','webp'],true)
        ){
            throw new AttachmentOperationException('Marketplace media must be JPEG, PNG or WebP.');
        }

        $count=(int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_listing_media WHERE listing_id=:listing',
            ['listing'=>$listingId->value()]
        ));
        if($count>=20)throw new AttachmentOperationException('Marketplace media limit has been reached.');

        $storagePath=sprintf('marketplace/%s/%s.%s',$listingId->value(),$inspection->sha256,$inspection->extension);
        $duplicate=(int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_listing_media WHERE listing_id=:listing AND storage_path=:path',
            ['listing'=>$listingId->value(),'path'=>$storagePath]
        ));
        if($duplicate>0)throw new AttachmentOperationException('This image is already attached to the marketplace listing.');

        $path=StoragePath::fromString($storagePath);
        $this->storage->put($path,$inspection->contents,StorageVisibility::Private,$inspection->mediaType);
        $media=new MarketplaceMedia(
            MarketplaceMedia::generateId(),$storagePath,$inspection->mediaType,trim($alt),min(1000,($count+1)*10)
        );

        try{
            $this->database->transaction(function()use($media,$listing,$actor):void{
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_marketplace_listing_media(media_id,listing_id,storage_path,media_type,alt_text,sort_order) '
                    . 'VALUES (:media,:listing,:path,:type,:alt,:sort)',
                    [
                        'media'=>$media->mediaId->value(),'listing'=>$listing->listingId->value(),
                        'path'=>$media->storagePath,'type'=>$media->mediaType,'alt'=>$media->altText,'sort'=>$media->sortOrder
                    ]
                ));
                $this->repository->recordHistory($listing->listingId,$actor,'media.upload',$listing->state->value,$listing->state->value);
            });
        }catch(Throwable $exception){
            try{$this->storage->delete($path,StorageVisibility::Private);}catch(Throwable){}
            throw $exception;
        }
        return $media;
    }

    public function delete(EntityId $actor,EntityId $listingId,EntityId $mediaId):void
    {
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT storage_path FROM forwext_marketplace_listing_media WHERE media_id=:media AND listing_id=:listing LIMIT 1',
            ['media'=>$mediaId->value(),'listing'=>$listingId->value()]
        ));
        if($row===null||!is_string($row['storage_path']??null))throw new InvalidArgumentException('Marketplace media was not found.');
        $path=StoragePath::fromString((string)$row['storage_path']);
        $this->database->transaction(function()use($mediaId,$listing,$actor):void{
            $changed=$this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_marketplace_listing_media WHERE media_id=:media AND listing_id=:listing',
                ['media'=>$mediaId->value(),'listing'=>$listing->listingId->value()]
            ));
            if($changed!==1)throw new InvalidArgumentException('Marketplace media was not found.');
            $this->repository->recordHistory($listing->listingId,$actor,'media.delete',$listing->state->value,$listing->state->value);
        });
        try{$this->storage->delete($path,StorageVisibility::Private);}catch(Throwable){}
    }

    public function download(EntityId $mediaId,?EntityId $actor):MarketplaceMediaDownload
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT media_id,listing_id,storage_path,media_type FROM forwext_marketplace_listing_media WHERE media_id=:media LIMIT 1',
            ['media'=>$mediaId->value()]
        ));
        if($row===null)throw new InvalidArgumentException('Marketplace media was not found.');
        $listingId=EntityId::fromString((string)$row['listing_id']);
        $this->marketplace->listing($listingId,$actor);
        $pathValue=$row['storage_path']??null;$type=$row['media_type']??null;
        if(!is_string($pathValue)||!is_string($type)
            ||preg_match('#^marketplace/[a-f0-9]{32}/([a-f0-9]{64})\.(jpg|png|webp)$#D',$pathValue,$match)!==1
            ||!in_array($type,['image/jpeg','image/png','image/webp'],true)
        ){
            throw new InvalidArgumentException('Marketplace media record is invalid.');
        }
        $contents=$this->storage->read(StoragePath::fromString($pathValue),StorageVisibility::Private);
        if(!hash_equals($match[1],hash('sha256',$contents))){
            throw new AttachmentOperationException('Marketplace media integrity verification failed.');
        }
        return new MarketplaceMediaDownload($contents,$type,'marketplace-'.$mediaId->value().'.'.$match[2]);
    }
}
