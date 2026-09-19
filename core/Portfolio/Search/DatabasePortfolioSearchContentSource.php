<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio\Search;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\Source\AbstractDatabaseSearchContentSource;
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabasePortfolioSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'portfolio.item';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT project_id,owner_user_id,title,summary,description,state,updated_at_utc '
            . 'FROM forwext_portfolio_projects WHERE project_id=:project_id LIMIT 1',
            ['project_id' => $documentId],
        ));
        if ($row === null || (string) $row['state'] !== 'published') {
            return null;
        }
        $tags = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT tag_key FROM forwext_portfolio_project_tags WHERE project_id=:project_id ORDER BY tag_key',
            ['project_id' => $documentId],
        )) as $tagRow) {
            $tag = $tagRow['tag_key'] ?? null;
            if (!is_string($tag)) {
                throw new SearchException('Stored portfolio tag is invalid.');
            }
            $tags[] = $tag;
        }
        return new SearchDocument(
            $this->documentType(),
            $documentId,
            (string) $row['title'],
            trim((string) $row['summary'] . "\n" . (string) $row['description']),
            [PortfolioSearchAccessScopeProvider::MEMBERS],
            $this->date((string) $row['updated_at_utc']),
            null,
            [
                SearchAttribute::USER => [(string) $row['owner_user_id']],
                SearchAttribute::TAG => $tags,
                SearchAttribute::STATE => ['published'],
            ],
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_portfolio_projects', 'project_id', $afterId, $limit);
    }
}
