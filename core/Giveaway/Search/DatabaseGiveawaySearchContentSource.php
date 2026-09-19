<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway\Search;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\AbstractDatabaseSearchContentSource;
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;

final readonly class DatabaseGiveawaySearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'giveaway.item';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT giveaway_id,owner_user_id,title,description,prize_title,prize_description,state,updated_at_utc '
            . 'FROM forwext_giveaways WHERE giveaway_id=:giveaway_id LIMIT 1',
            ['giveaway_id'=>$documentId],
        ));
        if ($row === null || !in_array((string) $row['state'], ['scheduled','open','closed'], true)) {
            return null;
        }
        return new SearchDocument(
            $this->documentType(),
            $documentId,
            (string) $row['title'],
            trim(
                (string) $row['description'] . "\n"
                . (string) $row['prize_title'] . "\n"
                . (string) $row['prize_description']
            ),
            [GiveawaySearchAccessScopeProvider::MEMBERS],
            $this->date((string) $row['updated_at_utc']),
            null,
            [
                SearchAttribute::USER=>[(string) $row['owner_user_id']],
                SearchAttribute::STATE=>[(string) $row['state']],
            ],
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_giveaways', 'giveaway_id', $afterId, $limit);
    }
}
