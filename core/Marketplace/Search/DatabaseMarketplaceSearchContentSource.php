<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Search;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\AbstractDatabaseSearchContentSource;
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;

final readonly class DatabaseMarketplaceSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType():string{return 'marketplace.listing';}

    public function document(string $documentId):?SearchDocument
    {
        $this->validateId($documentId);
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT l.listing_id,l.seller_user_id,l.title,l.description,l.state,l.updated_at_utc,c.name AS category_name '
            . 'FROM forwext_marketplace_listings l INNER JOIN forwext_marketplace_categories c ON c.category_id=l.category_id '
            . 'WHERE l.listing_id=:id LIMIT 1',['id'=>$documentId]
        ));
        if($row===null||!in_array((string)$row['state'],['active','sold'],true))return null;
        $tags=array_map(
            static fn(array $r):string=>(string)$r['tag_key'],
            $this->database->fetchAll(new CompiledQuery(
                'SELECT tag_key FROM forwext_marketplace_listing_tags WHERE listing_id=:id ORDER BY tag_key',['id'=>$documentId]
            ))
        );
        return new SearchDocument(
            $this->documentType(),$documentId,(string)$row['title'],
            trim((string)$row['description']."\n".(string)$row['category_name']."\n".implode(' ',$tags)),
            [MarketplaceSearchAccessScopeProvider::MEMBERS],$this->date((string)$row['updated_at_utc']),null,
            [
                SearchAttribute::USER=>[(string)$row['seller_user_id']],
                SearchAttribute::STATE=>[(string)$row['state']],
                SearchAttribute::TAG=>$tags,
            ]
        );
    }

    public function scan(?string $afterId,int $limit):SearchContentPage
    {
        return $this->scanIds('forwext_marketplace_listings','listing_id',$afterId,$limit);
    }
}
